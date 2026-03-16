<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\AuthenticationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Loader\ArrayLoader;
use Jose\Component\Core\JWK;

class AuthenticationServiceDeepTest extends TestCase
{
    private AuthenticationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $loader = new ArrayLoader();
        $this->service = new AuthenticationService($loader);
    }

    public function testFetchOAuthTokensMissingGrantType(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Grant type not set');

        $this->service->fetchOAuthTokens([]);
    }

    public function testFetchOAuthTokensMissingTokenUrl(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Token URL not set');

        $this->service->fetchOAuthTokens(['grant_type' => 'client_credentials']);
    }

    public function testFetchOAuthTokensUnsupportedGrantType(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Grant type not supported');

        $this->service->fetchOAuthTokens([
            'grant_type' => 'authorization_code',
            'tokenUrl' => 'https://example.com/token',
        ]);
    }

    public function testCreateClientCredentialConfigMissingParams(): void
    {
        $ref = new ReflectionClass(AuthenticationService::class);
        $method = $ref->getMethod('createClientCredentialConfig');
        $method->setAccessible(true);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('required parameters');

        $method->invoke($this->service, ['grant_type' => 'client_credentials']);
    }

    public function testCreateClientCredentialConfigBody(): void
    {
        $ref = new ReflectionClass(AuthenticationService::class);
        $method = $ref->getMethod('createClientCredentialConfig');
        $method->setAccessible(true);

        $config = [
            'grant_type' => 'client_credentials',
            'scope' => 'openid',
            'authentication' => 'body',
            'client_id' => 'myid',
            'client_secret' => 'mysecret',
        ];

        $result = $method->invoke($this->service, $config);

        $this->assertEquals('myid', $result['form_params']['client_id']);
        $this->assertEquals('mysecret', $result['form_params']['client_secret']);
    }

    public function testCreateClientCredentialConfigBasicAuth(): void
    {
        $ref = new ReflectionClass(AuthenticationService::class);
        $method = $ref->getMethod('createClientCredentialConfig');
        $method->setAccessible(true);

        $config = [
            'grant_type' => 'client_credentials',
            'scope' => 'openid',
            'authentication' => 'basic_auth',
            'client_id' => 'myid',
            'client_secret' => 'mysecret',
        ];

        $result = $method->invoke($this->service, $config);

        $this->assertEquals('myid', $result['auth']['username']);
        $this->assertEquals('mysecret', $result['auth']['password']);
    }

    public function testCreatePasswordConfigMissingParams(): void
    {
        $ref = new ReflectionClass(AuthenticationService::class);
        $method = $ref->getMethod('createPasswordConfig');
        $method->setAccessible(true);

        $this->expectException(BadRequestException::class);

        $method->invoke($this->service, ['grant_type' => 'password']);
    }

    public function testCreatePasswordConfigBody(): void
    {
        $ref = new ReflectionClass(AuthenticationService::class);
        $method = $ref->getMethod('createPasswordConfig');
        $method->setAccessible(true);

        $config = [
            'grant_type' => 'password',
            'scope' => 'openid',
            'authentication' => 'body',
            'username' => 'user',
            'password' => 'pass',
        ];

        $result = $method->invoke($this->service, $config);

        $this->assertEquals('user', $result['form_params']['username']);
    }

    public function testCreatePasswordConfigBasicAuth(): void
    {
        $ref = new ReflectionClass(AuthenticationService::class);
        $method = $ref->getMethod('createPasswordConfig');
        $method->setAccessible(true);

        $config = [
            'grant_type' => 'password',
            'scope' => 'openid',
            'authentication' => 'basic_auth',
            'username' => 'user',
            'password' => 'pass',
        ];

        $result = $method->invoke($this->service, $config);

        $this->assertEquals('user', $result['auth']['username']);
    }

    public function testFetchJWTTokenMissingParams(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('required parameters');

        $this->service->fetchJWTToken(['payload' => '{}']);
    }

    public function testGetHSJWK(): void
    {
        $ref = new ReflectionClass(AuthenticationService::class);
        $method = $ref->getMethod('getHSJWK');
        $method->setAccessible(true);

        $jwk = $method->invoke($this->service, ['secret' => 'testsecret']);

        $this->assertInstanceOf(JWK::class, $jwk);
        $this->assertEquals('oct', $jwk->get('kty'));
    }

    public function testGetJWKUnsupportedAlgorithm(): void
    {
        $ref = new ReflectionClass(AuthenticationService::class);
        $method = $ref->getMethod('getJWK');
        $method->setAccessible(true);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Algorithm not supported');

        $method->invoke($this->service, [
            'algorithm' => 'ES256',
            'secret' => 'test',
        ]);
    }

    public function testFetchJWTTokenWithHS256(): void
    {
        $token = $this->service->fetchJWTToken([
            'payload' => '{"sub": "test", "iss": "test"}',
            'secret' => 'my-secret-key-that-is-long-enough',
            'algorithm' => 'HS256',
        ]);

        $this->assertNotEmpty($token);
        // JWT has 3 parts separated by dots
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }
}
