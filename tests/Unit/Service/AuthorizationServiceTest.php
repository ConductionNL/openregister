<?php

namespace Unit\Service;

use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\AuthorizationService;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class AuthorizationServiceTest extends TestCase
{

    /**
     * @var IUserManager&MockObject
     */
    private IUserManager $userManager;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * @var ConsumerMapper&MockObject
     */
    private ConsumerMapper $consumerMapper;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    private AuthorizationService $service;

    protected function setUp(): void
    {
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->consumerMapper = $this->createMock(ConsumerMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->service = new AuthorizationService(
            $this->userManager,
            $this->userSession,
            $this->consumerMapper,
            $this->groupManager
        );
    }

    // --- validatePayload ---

    public function testValidatePayloadSucceedsWithValidToken(): void
    {
        $now = time();
        $payload = [
            'iat' => $now - 60,
            'exp' => $now + 3600,
        ];

        // Should not throw.
        $this->service->validatePayload($payload);
        $this->assertTrue(true);
    }

    public function testValidatePayloadThrowsWhenMissingIat(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('no time of creation');

        $this->service->validatePayload([]);
    }

    public function testValidatePayloadThrowsWhenExpired(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

        $payload = [
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ];

        $this->service->validatePayload($payload);
    }

    public function testValidatePayloadUsesDefaultExpiryWhenNoExp(): void
    {
        // Token created just now, no explicit exp — default +1 hour should be valid.
        $payload = [
            'iat' => time() - 60,
        ];

        $this->service->validatePayload($payload);
        $this->assertTrue(true);
    }

    public function testValidatePayloadDefaultExpiryExpired(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

        // Token created 2 hours ago, default exp = iat + 1 hour = expired.
        $payload = [
            'iat' => time() - 7200,
        ];

        $this->service->validatePayload($payload);
    }

    // --- authorizeBasic ---

    public function testAuthorizeBasicSucceeds(): void
    {
        $user = $this->createMock(IUser::class);

        $this->userManager
            ->expects($this->once())
            ->method('checkPassword')
            ->with('admin', 'password')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $header = 'Basic ' . base64_encode('admin:password');
        $this->service->authorizeBasic($header);
    }

    public function testAuthorizeBasicThrowsOnInvalidCredentials(): void
    {
        $this->userManager
            ->expects($this->once())
            ->method('checkPassword')
            ->willReturn(false);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid username or password');

        $header = 'Basic ' . base64_encode('wrong:creds');
        $this->service->authorizeBasic($header);
    }

    // --- authorizeOAuth ---

    public function testAuthorizeOAuthSucceeds(): void
    {
        $this->userSession
            ->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(true);

        $this->service->authorizeOAuth('Bearer sometoken');
        $this->assertTrue(true);
    }

    public function testAuthorizeOAuthThrowsWithoutBearerPrefix(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid method');

        $this->service->authorizeOAuth('Token sometoken');
    }

    public function testAuthorizeOAuthThrowsWhenNotLoggedIn(): void
    {
        $this->userSession
            ->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(false);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->authorizeOAuth('Bearer sometoken');
    }

    // --- authorizeApiKey ---

    public function testAuthorizeApiKeySucceeds(): void
    {
        $user = $this->createMock(IUser::class);
        $keys = ['valid-key-123' => 'admin'];

        $this->userManager
            ->expects($this->once())
            ->method('get')
            ->with('admin')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $this->service->authorizeApiKey('valid-key-123', $keys);
    }

    public function testAuthorizeApiKeyThrowsForInvalidKey(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $this->service->authorizeApiKey('bad-key', ['valid-key' => 'admin']);
    }

    public function testAuthorizeApiKeyThrowsWhenUserNotFound(): void
    {
        $keys = ['valid-key' => 'nonexistent-user'];

        $this->userManager
            ->expects($this->once())
            ->method('get')
            ->with('nonexistent-user')
            ->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $this->service->authorizeApiKey('valid-key', $keys);
    }

    // --- authorizeJwt ---

    public function testAuthorizeJwtThrowsWhenNoToken(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No token has been provided');

        $this->service->authorizeJwt('Bearer ');
    }

    public function testAuthorizeJwtThrowsWhenEmptyBearer(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No token has been provided');

        $this->service->authorizeJwt('Bearer');
    }

    // --- corsAfterController ---

    public function testCorsAfterControllerAddsOriginHeader(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->server = ['HTTP_ORIGIN' => 'https://example.com'];

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['Content-Type' => 'application/json']);

        $response->expects($this->once())
            ->method('addHeader')
            ->with('Access-Control-Allow-Origin', 'https://example.com');

        $result = $this->service->corsAfterController($request, $response);

        $this->assertSame($response, $result);
    }

    public function testCorsAfterControllerReturnsResponseWithoutOrigin(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->server = [];

        $response = $this->createMock(Response::class);
        $response->expects($this->never())
            ->method('addHeader');

        $result = $this->service->corsAfterController($request, $response);

        $this->assertSame($response, $result);
    }

    public function testCorsAfterControllerThrowsOnCredentialsTrue(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->server = ['HTTP_ORIGIN' => 'https://example.com'];

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['Access-Control-Allow-Credentials' => 'true']);

        $this->expectException(SecurityException::class);

        $this->service->corsAfterController($request, $response);
    }

    // --- Constants ---

    public function testHmacAlgorithmsConstant(): void
    {
        $this->assertSame(['HS256', 'HS384', 'HS512'], AuthorizationService::HMAC_ALGORITHMS);
    }

    public function testPkcs1AlgorithmsConstant(): void
    {
        $this->assertSame(['RS256', 'RS384', 'RS512'], AuthorizationService::PKCS1_ALGORITHMS);
    }

    public function testPssAlgorithmsConstant(): void
    {
        $this->assertSame(['PS256', 'PS384', 'PS512'], AuthorizationService::PSS_ALGORITHMS);
    }
}
