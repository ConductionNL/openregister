<?php

namespace Unit\Service;

use OCA\OpenRegister\Service\AuthenticationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Loader\ArrayLoader;

class AuthenticationServiceTest extends TestCase
{

    private AuthenticationService $service;

    protected function setUp(): void
    {
        $loader = new ArrayLoader();
        $this->service = new AuthenticationService($loader);
    }

    // --- fetchOAuthTokens validation ---

    public function testFetchOAuthTokensThrowsWhenGrantTypeMissing(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Grant type not set');

        $this->service->fetchOAuthTokens([]);
    }

    public function testFetchOAuthTokensThrowsWhenTokenUrlMissing(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Token URL not set');

        $this->service->fetchOAuthTokens(['grant_type' => 'client_credentials']);
    }

    public function testFetchOAuthTokensThrowsForUnsupportedGrantType(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Grant type not supported');

        $this->service->fetchOAuthTokens([
            'grant_type' => 'authorization_code',
            'tokenUrl' => 'https://example.com/token',
        ]);
    }

    // --- fetchJWTToken validation ---

    public function testFetchJWTTokenThrowsWhenPayloadMissing(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('required parameters are not set');

        $this->service->fetchJWTToken([
            'secret' => 'mysecret',
            'algorithm' => 'HS256',
        ]);
    }

    public function testFetchJWTTokenThrowsWhenSecretMissing(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('required parameters are not set');

        $this->service->fetchJWTToken([
            'payload' => '{"sub": "test"}',
            'algorithm' => 'HS256',
        ]);
    }

    public function testFetchJWTTokenThrowsWhenAlgorithmMissing(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('required parameters are not set');

        $this->service->fetchJWTToken([
            'payload' => '{"sub": "test"}',
            'secret' => 'mysecret',
        ]);
    }

    public function testFetchJWTTokenGeneratesHs256Token(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }
        $token = $this->service->fetchJWTToken([
            'payload' => '{"sub": "test", "iat": 1234567890}',
            'secret' => 'my-secret-key-for-testing-purposes',
            'algorithm' => 'HS256',
        ]);

        $this->assertNotEmpty($token);
        // JWT has 3 parts separated by dots.
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        // Verify header contains HS256 algorithm.
        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
    }

    public function testFetchJWTTokenGeneratesHs384Token(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }
        $token = $this->service->fetchJWTToken([
            'payload' => '{"sub": "test384"}',
            'secret' => 'my-secret-key-that-is-at-least-48-bytes-long-for-hs384-algorithm!!',
            'algorithm' => 'HS384',
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('HS384', $header['alg']);
    }

    public function testFetchJWTTokenGeneratesHs512Token(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }
        $token = $this->service->fetchJWTToken([
            'payload' => '{"sub": "test512"}',
            'secret' => 'my-secret-key-that-is-at-least-64-bytes-long-for-hs512-algorithm-requirement-satisfied!!',
            'algorithm' => 'HS512',
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('HS512', $header['alg']);
    }

    public function testFetchJWTTokenThrowsForUnsupportedAlgorithm(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Algorithm not supported');

        $this->service->fetchJWTToken([
            'payload' => '{"sub": "test"}',
            'secret' => 'mysecret',
            'algorithm' => 'ES256',
        ]);
    }

    public function testFetchJWTTokenWithTwigPayloadTemplate(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }
        $token = $this->service->fetchJWTToken([
            'payload' => '{"sub": "{{ client_id }}", "iss": "test"}',
            'secret' => 'my-secret-key-that-is-at-least-32-bytes-long!',
            'algorithm' => 'HS256',
            'client_id' => 'my-client',
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        // Decode payload and verify client_id was rendered.
        $payload = json_decode(base64_decode($parts[1]), true);
        $this->assertSame('my-client', $payload['sub']);
    }

    public function testFetchJWTTokenWithX5tHeader(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }
        $token = $this->service->fetchJWTToken([
            'payload' => '{"sub": "test"}',
            'secret' => 'my-secret-key-that-is-at-least-32-bytes-long!',
            'algorithm' => 'HS256',
            'x5t' => 'abc123thumbprint',
        ]);

        $parts = explode('.', $token);
        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('abc123thumbprint', $header['x5t']);
    }

    // --- Constants ---

    public function testRequiredParametersClientCredentials(): void
    {
        $this->assertContains('grant_type', AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
        $this->assertContains('client_id', AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
        $this->assertContains('client_secret', AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
    }

    public function testRequiredParametersPassword(): void
    {
        $this->assertContains('grant_type', AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
        $this->assertContains('username', AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
        $this->assertContains('password', AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
    }

    public function testRequiredParametersJwt(): void
    {
        $this->assertContains('payload', AuthenticationService::REQUIRED_PARAMETERS_JWT);
        $this->assertContains('secret', AuthenticationService::REQUIRED_PARAMETERS_JWT);
        $this->assertContains('algorithm', AuthenticationService::REQUIRED_PARAMETERS_JWT);
    }
}
