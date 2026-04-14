<?php

namespace Unit\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
// Consumer entity is used directly in JWT algorithm tests.
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\AuthorizationService;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;

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

    private AuthorizationService $service;

    protected function setUp(): void
    {
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->consumerMapper = $this->createMock(ConsumerMapper::class);

        $this->service = new AuthorizationService(
            $this->userManager,
            $this->userSession,
            $this->consumerMapper
        );
    }

    /**
     * Helper to invoke a private method via reflection.
     *
     * @param object $object     The object instance
     * @param string $methodName The method name
     * @param array  $args       The arguments to pass
     *
     * @return mixed The method return value
     */
    private function invokePrivateMethod(object $object, string $methodName, array $args = []): mixed
    {
        $method = new ReflectionMethod($object, $methodName);
        $method->setAccessible(true);
        return $method->invoke($object, ...$args);
    }

    /**
     * Helper: build a signed HMAC JWT token string.
     *
     * @param array  $payload   The JWT payload claims
     * @param string $secret    The shared secret
     * @param string $algorithm The HMAC algorithm (HS256, HS384, HS512)
     *
     * @return string The compact-serialized JWT
     */
    private function buildHmacJwt(array $payload, string $secret, string $algorithm = 'HS256'): string
    {
        $algMap = [
            'HS256' => new HS256(),
            'HS384' => new HS384(),
            'HS512' => new HS512(),
        ];

        // Ensure minimum key length: HS256=32, HS384=48, HS512=64 bytes.
        $minLengths = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64];
        $minLen = $minLengths[$algorithm] ?? 32;
        $paddedSecret = str_pad($secret, $minLen, $secret);

        $algorithmManager = new AlgorithmManager([$algMap[$algorithm]]);
        $jwk = new JWK([
            'kty' => 'oct',
            'k'   => rtrim(strtr(base64_encode($paddedSecret), '+/', '-_'), '='),
            'alg' => $algorithm,
            'use' => 'sig',
        ]);

        $jwsBuilder = new JWSBuilder($algorithmManager);
        $jws = $jwsBuilder
            ->create()
            ->withPayload(json_encode($payload))
            ->addSignature($jwk, ['alg' => $algorithm])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /**
     * Helper: create a Consumer entity configured for HMAC JWT.
     *
     * @param string $name      The consumer name / issuer
     * @param string $secret    The shared secret
     * @param string $algorithm The algorithm
     * @param string $userId    The Nextcloud user ID
     *
     * @return Consumer
     */
    private function createHmacConsumer(
        string $name,
        string $secret,
        string $algorithm = 'HS256',
        string $userId = 'admin'
    ): Consumer {
        // Pad secret to match what buildHmacJwt uses.
        $minLengths = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64];
        $minLen = $minLengths[$algorithm] ?? 32;
        $paddedSecret = str_pad($secret, $minLen, $secret);

        $consumer = new Consumer();
        $consumer->setName($name);
        $consumer->setUserId($userId);
        $consumer->setAuthorizationConfiguration([
            'publicKey'  => $paddedSecret,
            'algorithm'  => $algorithm,
        ]);
        return $consumer;
    }

    // ==========================================
    // validatePayload
    // ==========================================

    public function testValidatePayloadSucceedsWithValidToken(): void
    {
        $now = time();
        $payload = [
            'iat' => $now - 60,
            'exp' => $now + 3600,
        ];

        $this->service->validatePayload($payload);
        $this->assertTrue(true);
    }

    public function testValidatePayloadThrowsWhenMissingIat(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('no time of creation');

        $this->service->validatePayload([]);
    }

    public function testValidatePayloadThrowsWhenMissingIatWithDetails(): void
    {
        try {
            $this->service->validatePayload([]);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertArrayHasKey('iat', $e->getDetails());
            $this->assertNull($e->getDetails()['iat']);
        }
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

    public function testValidatePayloadExpiredDetailsContainTimestamps(): void
    {
        $now = time();
        $iat = $now - 7200;
        $exp = $now - 3600;

        try {
            $this->service->validatePayload(['iat' => $iat, 'exp' => $exp]);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $details = $e->getDetails();
            $this->assertArrayHasKey('iat', $details);
            $this->assertArrayHasKey('exp', $details);
            $this->assertArrayHasKey('time checked', $details);
            $this->assertSame($iat, $details['iat']);
            $this->assertSame($exp, $details['exp']);
        }
    }

    public function testValidatePayloadUsesDefaultExpiryWhenNoExp(): void
    {
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

        $payload = [
            'iat' => time() - 7200,
        ];

        $this->service->validatePayload($payload);
    }

    public function testValidatePayloadWithFutureIat(): void
    {
        $now = time();
        $payload = [
            'iat' => $now + 60,
            'exp' => $now + 3600,
        ];

        $this->service->validatePayload($payload);
        $this->assertTrue(true);
    }

    public function testValidatePayloadWithExactlyNowIat(): void
    {
        $now = time();
        $payload = [
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $this->service->validatePayload($payload);
        $this->assertTrue(true);
    }

    public function testValidatePayloadWithZeroIat(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

        $this->service->validatePayload(['iat' => 0]);
    }

    public function testValidatePayloadWithExtraClaimsDoesNotThrow(): void
    {
        $now = time();
        $payload = [
            'iat'  => $now - 60,
            'exp'  => $now + 3600,
            'sub'  => 'user123',
            'aud'  => 'my-api',
            'nbf'  => $now - 30,
            'jti'  => 'unique-id',
            'data' => ['foo' => 'bar'],
        ];

        $this->service->validatePayload($payload);
        $this->assertTrue(true);
    }

    // ==========================================
    // authorizeBasic
    // ==========================================

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

    public function testAuthorizeBasicWithUsersAndGroupsParams(): void
    {
        $user = $this->createMock(IUser::class);

        $this->userManager
            ->expects($this->once())
            ->method('checkPassword')
            ->with('testuser', 'testpass')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $header = 'Basic ' . base64_encode('testuser:testpass');
        $this->service->authorizeBasic($header, ['testuser'], ['testgroup']);
    }

    public function testAuthorizeBasicWithPasswordContainingColon(): void
    {
        $user = $this->createMock(IUser::class);

        $this->userManager
            ->expects($this->once())
            ->method('checkPassword')
            ->with('admin', 'pass')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $header = 'Basic ' . base64_encode('admin:pass:extra');
        $this->service->authorizeBasic($header);
    }

    public function testAuthorizeBasicEmptyDetailsOnFailure(): void
    {
        $this->userManager
            ->method('checkPassword')
            ->willReturn(false);

        try {
            $header = 'Basic ' . base64_encode('bad:creds');
            $this->service->authorizeBasic($header);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame([], $e->getDetails());
        }
    }

    // ==========================================
    // authorizeOAuth
    // ==========================================

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

    public function testAuthorizeOAuthThrowsWithBasicPrefix(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid method');

        $this->service->authorizeOAuth('Basic dXNlcjpwYXNz');
    }

    public function testAuthorizeOAuthThrowsWithEmptyString(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid method');

        $this->service->authorizeOAuth('');
    }

    public function testAuthorizeOAuthInvalidMethodDetailsContainReason(): void
    {
        try {
            $this->service->authorizeOAuth('Token abc');
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertArrayHasKey('reason', $e->getDetails());
            $this->assertStringContainsString('not allowed', $e->getDetails()['reason']);
        }
    }

    public function testAuthorizeOAuthNotAuthorizedDetailsContainReason(): void
    {
        $this->userSession
            ->method('isLoggedIn')
            ->willReturn(false);

        try {
            $this->service->authorizeOAuth('Bearer sometoken');
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertArrayHasKey('reason', $e->getDetails());
            $this->assertStringContainsString('expired', $e->getDetails()['reason']);
        }
    }

    public function testAuthorizeOAuthSucceedsWithBearerNoSpace(): void
    {
        $this->userSession
            ->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(true);

        $this->service->authorizeOAuth('Bearertoken');
        $this->assertTrue(true);
    }

    // ==========================================
    // authorizeApiKey
    // ==========================================

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

    public function testAuthorizeApiKeyWithMultipleKeys(): void
    {
        $user = $this->createMock(IUser::class);
        $keys = [
            'key-one'   => 'user1',
            'key-two'   => 'user2',
            'key-three' => 'user3',
        ];

        $this->userManager
            ->expects($this->once())
            ->method('get')
            ->with('user2')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $this->service->authorizeApiKey('key-two', $keys);
    }

    public function testAuthorizeApiKeyWithEmptyKeysMap(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $this->service->authorizeApiKey('any-key', []);
    }

    public function testAuthorizeApiKeyEmptyDetailsOnInvalidKey(): void
    {
        try {
            $this->service->authorizeApiKey('bad', ['good' => 'admin']);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame([], $e->getDetails());
        }
    }

    public function testAuthorizeApiKeyEmptyDetailsOnNullUser(): void
    {
        $this->userManager
            ->method('get')
            ->willReturn(null);

        try {
            $this->service->authorizeApiKey('key', ['key' => 'ghost']);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame([], $e->getDetails());
        }
    }

    // ==========================================
    // authorizeJwt
    // ==========================================

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

    public function testAuthorizeJwtEmptyTokenDetails(): void
    {
        try {
            $this->service->authorizeJwt('Bearer ');
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame([], $e->getDetails());
        }
    }

    public function testAuthorizeJwtThrowsWhenNoIssuer(): void
    {
        $secret = 'my-test-secret-key-for-jwt-testing';
        $now = time();
        $payload = [
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('could not be validated');

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtThrowsWhenEmptyIssuer(): void
    {
        $secret = 'my-test-secret-key-for-jwt-testing';
        $now = time();
        $payload = [
            'iss' => '',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('could not be validated');

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtNoIssuerDetailsContainReason(): void
    {
        $secret = 'my-test-secret-key-for-details';
        $now = time();
        $payload = ['iat' => $now, 'exp' => $now + 3600];

        $token = $this->buildHmacJwt($payload, $secret);

        try {
            $this->service->authorizeJwt('Bearer ' . $token);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertArrayHasKey('reason', $e->getDetails());
            $this->assertStringContainsString('issuer', $e->getDetails()['reason']);
        }
    }

    public function testAuthorizeJwtThrowsWhenInvalidAlgorithmHeader(): void
    {
        // Craft a JWT with an unsupported algorithm ('none') to trigger
        // the 'algorithm is not supported' branch after issuer lookup.
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['iss' => 'test', 'iat' => time()])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('fake-signature'), '+/', '-_'), '=');
        $token = "$header.$payload.$signature";

        // Consumer must exist so findIssuer succeeds; algorithm check happens after.
        $consumer = new Consumer();
        $consumer->setName('test');
        $consumer->setAuthorizationConfiguration(['publicKey' => 'secret', 'algorithm' => 'none']);
        $this->consumerMapper->method('findAll')->willReturn([$consumer]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('not supported');

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtInvalidAlgorithmDetailsContainReason(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['iss' => 'test', 'iat' => time()])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('fake'), '+/', '-_'), '=');
        $token = "$header.$payload.$signature";

        $consumer = new Consumer();
        $consumer->setName('test');
        $consumer->setAuthorizationConfiguration(['publicKey' => 'secret', 'algorithm' => 'none']);
        $this->consumerMapper->method('findAll')->willReturn([$consumer]);

        try {
            $this->service->authorizeJwt('Bearer ' . $token);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertArrayHasKey('algorithm', $e->getDetails());
        }
    }

    public function testAuthorizeJwtThrowsWhenIssuerNotFound(): void
    {
        $secret = 'my-test-secret-key-for-jwt-testing';
        $now = time();
        $payload = [
            'iss' => 'unknown-issuer',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret);

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('issuer was not found');

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtSucceedsWithHs256(): void
    {
        $secret = 'my-test-secret-key-for-jwt-hs256';
        $now = time();
        $payload = [
            'iss' => 'test-app',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret, 'HS256');
        $consumer = $this->createHmacConsumer('test-app', $secret, 'HS256', 'admin');
        $user = $this->createMock(IUser::class);

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $this->userManager
            ->expects($this->once())
            ->method('get')
            ->with('admin')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtSucceedsWithHs384(): void
    {
        $secret = 'my-test-secret-key-for-jwt-hs384';
        $now = time();
        $payload = [
            'iss' => 'test-384',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret, 'HS384');
        $consumer = $this->createHmacConsumer('test-384', $secret, 'HS384', 'admin');
        $user = $this->createMock(IUser::class);

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $this->userManager
            ->expects($this->once())
            ->method('get')
            ->with('admin')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtSucceedsWithHs512(): void
    {
        $secret = 'my-test-secret-key-for-jwt-hs512';
        $now = time();
        $payload = [
            'iss' => 'test-512',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret, 'HS512');
        $consumer = $this->createHmacConsumer('test-512', $secret, 'HS512', 'admin');
        $user = $this->createMock(IUser::class);

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $this->userManager
            ->expects($this->once())
            ->method('get')
            ->with('admin')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtThrowsWhenSignatureInvalid(): void
    {
        $secret = 'correct-secret-key-for-test';
        $wrongSecret = 'wrong-secret-key-different';
        $now = time();
        $payload = [
            'iss' => 'test-app',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $wrongSecret, 'HS256');
        $consumer = $this->createHmacConsumer('test-app', $secret, 'HS256');

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('could not be validated');

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtSignatureInvalidDetailsContainReason(): void
    {
        $secret = 'correct-secret-for-detail-test';
        $wrongSecret = 'wrong-secret-for-detail-test';
        $now = time();
        $payload = [
            'iss' => 'test-detail',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $wrongSecret, 'HS256');
        $consumer = $this->createHmacConsumer('test-detail', $secret, 'HS256');

        $this->consumerMapper
            ->method('findAll')
            ->willReturn([$consumer]);

        try {
            $this->service->authorizeJwt('Bearer ' . $token);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertArrayHasKey('reason', $e->getDetails());
            $this->assertStringContainsString('public key', $e->getDetails()['reason']);
        }
    }

    public function testAuthorizeJwtThrowsWhenTokenExpired(): void
    {
        $secret = 'my-test-secret-key-for-jwt-expired';
        $now = time();
        $payload = [
            'iss' => 'test-expired',
            'iat' => $now - 7200,
            'exp' => $now - 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret, 'HS256');
        $consumer = $this->createHmacConsumer('test-expired', $secret, 'HS256');

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtThrowsWhenTokenMissingIat(): void
    {
        $secret = 'my-test-secret-key-for-no-iat';
        $payload = [
            'iss' => 'test-no-iat',
            'exp' => time() + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret, 'HS256');
        $consumer = $this->createHmacConsumer('test-no-iat', $secret, 'HS256');

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('no time of creation');

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtSucceedsWithDefaultExpiry(): void
    {
        $secret = 'my-test-secret-key-for-default-exp';
        $now = time();
        $payload = [
            'iss' => 'test-default-exp',
            'iat' => $now - 60,
        ];

        $token = $this->buildHmacJwt($payload, $secret, 'HS256');
        $consumer = $this->createHmacConsumer('test-default-exp', $secret, 'HS256', 'admin');
        $user = $this->createMock(IUser::class);

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $this->userManager
            ->expects($this->once())
            ->method('get')
            ->with('admin')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtThrowsWhenUnsupportedAlgorithm(): void
    {
        $secret = 'my-test-secret-key-for-unsupported';
        $now = time();
        $payload = [
            'iss' => 'test-unsupported',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret, 'HS256');
        $consumer = $this->createHmacConsumer('test-unsupported', $secret, 'EdDSA');

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('not supported');

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    public function testAuthorizeJwtWithMultipleConsumersReturnsFirst(): void
    {
        $secret = 'shared-secret-key-multi';
        $now = time();
        $payload = [
            'iss' => 'multi-consumer',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $token = $this->buildHmacJwt($payload, $secret, 'HS256');

        $consumer1 = $this->createHmacConsumer('multi-consumer', $secret, 'HS256', 'user1');
        $consumer2 = $this->createHmacConsumer('multi-consumer', $secret, 'HS256', 'user2');
        $user = $this->createMock(IUser::class);

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer1, $consumer2]);

        $this->userManager
            ->expects($this->once())
            ->method('get')
            ->with('user1')
            ->willReturn($user);

        $this->userSession
            ->expects($this->once())
            ->method('setUser')
            ->with($user);

        $this->service->authorizeJwt('Bearer ' . $token);
    }

    // ==========================================
    // findIssuer (private, via reflection)
    // ==========================================

    public function testFindIssuerReturnsConsumer(): void
    {
        $consumer = new Consumer();
        $consumer->setName('test-issuer');

        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$consumer]);

        $result = $this->invokePrivateMethod($this->service, 'findIssuer', ['test-issuer']);
        $this->assertInstanceOf(Consumer::class, $result);
        $this->assertSame('test-issuer', $result->getName());
    }

    public function testFindIssuerThrowsWhenNotFound(): void
    {
        $this->consumerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('issuer was not found');

        $this->invokePrivateMethod($this->service, 'findIssuer', ['nonexistent']);
    }

    public function testFindIssuerDetailsContainIss(): void
    {
        $this->consumerMapper
            ->method('findAll')
            ->willReturn([]);

        try {
            $this->invokePrivateMethod($this->service, 'findIssuer', ['my-issuer']);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertArrayHasKey('iss', $e->getDetails());
            $this->assertSame('my-issuer', $e->getDetails()['iss']);
        }
    }

    public function testFindIssuerReturnsFirstConsumerWhenMultiple(): void
    {
        $consumer1 = new Consumer();
        $consumer1->setName('issuer');
        $consumer1->setUserId('user1');

        $consumer2 = new Consumer();
        $consumer2->setName('issuer');
        $consumer2->setUserId('user2');

        $this->consumerMapper
            ->method('findAll')
            ->willReturn([$consumer1, $consumer2]);

        $result = $this->invokePrivateMethod($this->service, 'findIssuer', ['issuer']);
        $this->assertSame('user1', $result->getUserId());
    }

    // ==========================================
    // getJWK — removed: method no longer exists in AuthorizationService
    // ==========================================

    // ==========================================
    // checkHeaders (private, via reflection)
    // ==========================================

    // checkHeaders — removed: method no longer exists in AuthorizationService

    // ==========================================
    // corsAfterController
    // ==========================================

    public function testCorsAfterControllerAddsOriginHeader(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Origin')->willReturn('https://example.com');

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
        $request->method('getHeader')->with('Origin')->willReturn('');

        $response = $this->createMock(Response::class);
        $response->expects($this->never())
            ->method('addHeader');

        $result = $this->service->corsAfterController($request, $response);

        $this->assertSame($response, $result);
    }

    public function testCorsAfterControllerThrowsOnCredentialsTrue(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Origin')->willReturn('https://example.com');

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['Access-Control-Allow-Credentials' => 'true']);

        $this->expectException(SecurityException::class);

        $this->service->corsAfterController($request, $response);
    }

    public function testCorsAfterControllerAllowsCredentialsFalse(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Origin')->willReturn('https://example.com');

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['Access-Control-Allow-Credentials' => 'false']);

        $response->expects($this->once())
            ->method('addHeader')
            ->with('Access-Control-Allow-Origin', 'https://example.com');

        $result = $this->service->corsAfterController($request, $response);
        $this->assertSame($response, $result);
    }

    public function testCorsAfterControllerHandlesMultipleHeaders(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Origin')->willReturn('https://test.org');

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getHeaders')
            ->willReturn([
                'Content-Type'  => 'application/json',
                'X-Custom'      => 'value',
                'Cache-Control' => 'no-cache',
            ]);

        $response->expects($this->once())
            ->method('addHeader')
            ->with('Access-Control-Allow-Origin', 'https://test.org');

        $result = $this->service->corsAfterController($request, $response);
        $this->assertSame($response, $result);
    }

    public function testCorsAfterControllerCredentialsCaseInsensitive(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Origin')->willReturn('https://example.com');

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['ACCESS-CONTROL-ALLOW-CREDENTIALS' => ' True ']);

        $this->expectException(SecurityException::class);

        $this->service->corsAfterController($request, $response);
    }

    public function testCorsAfterControllerWithEmptyHeaders(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Origin')->willReturn('https://example.com');

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getHeaders')
            ->willReturn([]);

        $response->expects($this->once())
            ->method('addHeader')
            ->with('Access-Control-Allow-Origin', 'https://example.com');

        $result = $this->service->corsAfterController($request, $response);
        $this->assertSame($response, $result);
    }

    public function testCorsAfterControllerWithNullServerKey(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Origin')->willReturn('');

        $response = $this->createMock(Response::class);
        $response->expects($this->never())
            ->method('getHeaders');
        $response->expects($this->never())
            ->method('addHeader');

        $result = $this->service->corsAfterController($request, $response);
        $this->assertSame($response, $result);
    }

    // ==========================================
    // Constants
    // ==========================================

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

    public function testAllAlgorithmsAreCovered(): void
    {
        $all = array_merge(
            AuthorizationService::HMAC_ALGORITHMS,
            AuthorizationService::PKCS1_ALGORITHMS,
            AuthorizationService::PSS_ALGORITHMS
        );

        $this->assertCount(9, $all);
        $this->assertContains('HS256', $all);
        $this->assertContains('RS512', $all);
        $this->assertContains('PS256', $all);
    }
}
