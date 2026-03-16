<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\AuthenticationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Loader\ArrayLoader;

/**
 * Gap tests for AuthenticationService covering uncovered branches.
 */
class AuthenticationServiceGapTest extends TestCase
{
    private AuthenticationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $loader = new ArrayLoader();
        $this->service = new AuthenticationService($loader);
    }

    /**
     * Test fetchJWTToken with missing required parameters.
     */
    public function testFetchJWTTokenMissingParams(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Some required parameters are not set');

        $this->service->fetchJWTToken([
            'payload' => '{}',
            // missing secret and algorithm
        ]);
    }

    /**
     * Test fetchOAuthTokens with missing grant_type.
     */
    public function testFetchOAuthTokensMissingGrantType(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Grant type not set');

        $this->service->fetchOAuthTokens([
            'tokenUrl' => 'https://example.com/token',
        ]);
    }

    /**
     * Test fetchOAuthTokens with missing tokenUrl.
     */
    public function testFetchOAuthTokensMissingTokenUrl(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Token URL not set');

        $this->service->fetchOAuthTokens([
            'grant_type' => 'client_credentials',
        ]);
    }

    /**
     * Test fetchOAuthTokens with unsupported grant type.
     */
    public function testFetchOAuthTokensUnsupportedGrantType(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Grant type not supported');

        $this->service->fetchOAuthTokens([
            'grant_type' => 'implicit',
            'tokenUrl' => 'https://example.com/token',
        ]);
    }

    /**
     * Test fetchJWTToken with HS256 algorithm (covers HS key generation).
     * HS256 requires a key of at least 32 bytes.
     */
    public function testFetchJWTTokenWithHS256(): void
    {
        $payload = '{"sub": "test", "iat": 1234567890}';

        $result = $this->service->fetchJWTToken([
            'payload' => $payload,
            'secret' => 'this-is-a-32-byte-secret-key!!!-',
            'algorithm' => 'HS256',
        ]);

        // JWT is a string with 3 parts separated by dots
        $this->assertIsString($result);
        $parts = explode('.', $result);
        $this->assertCount(3, $parts);

        // Verify the header contains HS256
        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertEquals('HS256', $header['alg']);
        $this->assertEquals('JWT', $header['typ']);
    }

    /**
     * Test fetchJWTToken with HS384 algorithm.
     * HS384 requires a key of at least 48 bytes.
     */
    public function testFetchJWTTokenWithHS384(): void
    {
        $payload = '{"sub": "test"}';

        $result = $this->service->fetchJWTToken([
            'payload' => $payload,
            'secret' => 'this-is-a-48-byte-secret-key-for-hs384-testing!!',
            'algorithm' => 'HS384',
        ]);

        $this->assertIsString($result);
        $parts = explode('.', $result);
        $this->assertCount(3, $parts);
    }

    /**
     * Test fetchJWTToken with HS512 algorithm.
     * HS512 requires a key of at least 64 bytes.
     */
    public function testFetchJWTTokenWithHS512(): void
    {
        $payload = '{"sub": "test"}';

        $result = $this->service->fetchJWTToken([
            'payload' => $payload,
            'secret' => 'this-is-a-64-byte-secret-key-for-hs512-testing-need-more-chars!!',
            'algorithm' => 'HS512',
        ]);

        $this->assertIsString($result);
        $parts = explode('.', $result);
        $this->assertCount(3, $parts);
    }

    /**
     * Test fetchJWTToken with x5t header (covers x5t branch).
     * HS256 requires a key of at least 32 bytes.
     */
    public function testFetchJWTTokenWithX5tHeader(): void
    {
        $payload = '{"sub": "test"}';

        $result = $this->service->fetchJWTToken([
            'payload' => $payload,
            'secret' => 'this-is-a-32-byte-secret-key!!!-',
            'algorithm' => 'HS256',
            'x5t' => 'thumbprint123',
        ]);

        $this->assertIsString($result);
        $parts = explode('.', $result);
        $this->assertCount(3, $parts);

        // Verify x5t is in the header
        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertEquals('thumbprint123', $header['x5t']);
    }

    /**
     * Test fetchJWTToken with unsupported algorithm (covers getJWK exception).
     */
    public function testFetchJWTTokenUnsupportedAlgorithm(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Algorithm not supported');

        $this->service->fetchJWTToken([
            'payload' => '{"sub": "test"}',
            'secret' => 'this-is-a-32-byte-secret-key!!!-',
            'algorithm' => 'ES256', // Not supported
        ]);
    }

    /**
     * Test fetchJWTToken with Twig template in payload.
     */
    public function testFetchJWTTokenWithTwigPayload(): void
    {
        // Twig template that uses a configuration variable
        $payload = '{"sub": "{{ client_id }}", "iss": "{{ client_id }}"}';

        $result = $this->service->fetchJWTToken([
            'payload' => $payload,
            'secret' => 'this-is-a-32-byte-secret-key!!!-',
            'algorithm' => 'HS256',
            'client_id' => 'my-app-id',
        ]);

        $this->assertIsString($result);
        $parts = explode('.', $result);
        $this->assertCount(3, $parts);

        // Verify the payload contains the rendered values
        $decodedPayload = json_decode(base64_decode($parts[1]), true);
        $this->assertEquals('my-app-id', $decodedPayload['sub']);
        $this->assertEquals('my-app-id', $decodedPayload['iss']);
    }
}
