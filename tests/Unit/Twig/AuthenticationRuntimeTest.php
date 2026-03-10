<?php

namespace Unit\Twig;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\AuthenticationService;
use OCA\OpenRegister\Twig\AuthenticationRuntime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Twig\Extension\RuntimeExtensionInterface;

class AuthenticationRuntimeTest extends TestCase
{
    private AuthenticationService&MockObject $authService;
    private AuthenticationRuntime $runtime;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthenticationService::class);
        $this->runtime = new AuthenticationRuntime($this->authService);
    }

    /**
     * Create a Source mock with getConfiguration available via addMethods.
     * Source extends Entity which uses __call for getters/setters, so
     * getConfiguration() is not a real method and must be added explicitly.
     *
     * @param array|null $configuration The configuration to return
     * @return Source&MockObject
     */
    private function createSourceWithConfig(?array $configuration): Source&MockObject
    {
        $source = $this->getMockBuilder(Source::class)
            ->addMethods(['getConfiguration'])
            ->getMock();
        $source->method('getConfiguration')->willReturn($configuration);
        return $source;
    }

    public function testImplementsRuntimeExtensionInterface(): void
    {
        $this->assertInstanceOf(RuntimeExtensionInterface::class, $this->runtime);
    }

    // --- oauthToken() ---

    public function testOauthTokenCallsAuthService(): void
    {
        $source = $this->createSourceWithConfig([
            'authentication' => ['grant_type' => 'client_credentials', 'client_id' => 'abc'],
        ]);

        $this->authService->expects($this->once())
            ->method('fetchOAuthTokens')
            ->with(['grant_type' => 'client_credentials', 'client_id' => 'abc'])
            ->willReturn('oauth-token-123');

        $result = $this->runtime->oauthToken($source);
        $this->assertSame('oauth-token-123', $result);
    }

    public function testOauthTokenWithNestedConfig(): void
    {
        $source = $this->createSourceWithConfig([
            'authentication' => ['token_url' => 'https://auth.example.com/token'],
        ]);

        $this->authService->method('fetchOAuthTokens')->willReturn('token');

        $this->assertSame('token', $this->runtime->oauthToken($source));
    }

    // --- decosToken() ---

    public function testDecosTokenCallsAuthService(): void
    {
        $source = $this->createSourceWithConfig([
            'authentication' => ['api_key' => 'decos-key'],
        ]);

        $this->authService->expects($this->once())
            ->method('fetchDecosToken')
            ->with(['api_key' => 'decos-key'])
            ->willReturn('decos-token-456');

        $result = $this->runtime->decosToken($source);
        $this->assertSame('decos-token-456', $result);
    }

    // --- jwtToken() ---

    public function testJwtTokenCallsAuthService(): void
    {
        $source = $this->createSourceWithConfig([
            'authentication' => ['secret' => 'jwt-secret', 'issuer' => 'test'],
        ]);

        $this->authService->expects($this->once())
            ->method('fetchJWTToken')
            ->with(['secret' => 'jwt-secret', 'issuer' => 'test'])
            ->willReturn('jwt-token-789');

        $result = $this->runtime->jwtToken($source);
        $this->assertSame('jwt-token-789', $result);
    }

    // --- Edge cases: missing authentication key causes TypeError ---

    public function testOauthTokenWithMissingAuthenticationThrowsTypeError(): void
    {
        $source = $this->createSourceWithConfig([]);

        $this->expectException(\TypeError::class);
        $this->runtime->oauthToken($source);
    }

    public function testDecosTokenWithMissingAuthenticationThrowsTypeError(): void
    {
        $source = $this->createSourceWithConfig([]);

        $this->expectException(\TypeError::class);
        $this->runtime->decosToken($source);
    }

    public function testJwtTokenWithMissingAuthenticationThrowsTypeError(): void
    {
        $source = $this->createSourceWithConfig([]);

        $this->expectException(\TypeError::class);
        $this->runtime->jwtToken($source);
    }

    // --- Edge cases: empty authentication array ---

    public function testOauthTokenWithEmptyAuthentication(): void
    {
        $source = $this->createSourceWithConfig([
            'authentication' => [],
        ]);

        $this->authService->expects($this->once())
            ->method('fetchOAuthTokens')
            ->with([])
            ->willReturn('');

        $this->assertSame('', $this->runtime->oauthToken($source));
    }

    public function testDecosTokenWithEmptyAuthentication(): void
    {
        $source = $this->createSourceWithConfig([
            'authentication' => [],
        ]);

        $this->authService->expects($this->once())
            ->method('fetchDecosToken')
            ->with([])
            ->willReturn('');

        $this->assertSame('', $this->runtime->decosToken($source));
    }

    public function testJwtTokenWithEmptyAuthentication(): void
    {
        $source = $this->createSourceWithConfig([
            'authentication' => [],
        ]);

        $this->authService->expects($this->once())
            ->method('fetchJWTToken')
            ->with([])
            ->willReturn('');

        $this->assertSame('', $this->runtime->jwtToken($source));
    }
}
