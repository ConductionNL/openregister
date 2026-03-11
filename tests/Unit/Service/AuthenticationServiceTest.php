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

    /**
     * Helper to invoke private/protected methods via reflection.
     *
     * @param object $object     The object to invoke the method on.
     * @param string $methodName The method name.
     * @param array  $parameters The parameters to pass.
     *
     * @return mixed The method return value.
     */
    private function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    // ── fetchOAuthTokens validation ──

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

    // ── createClientCredentialConfig (private, tested via reflection) ──

    public function testCreateClientCredentialConfigThrowsWhenMissingParams(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('required parameters are not set');

        $this->invokeMethod($this->service, 'createClientCredentialConfig', [
            ['grant_type' => 'client_credentials'],
        ]);
    }

    public function testCreateClientCredentialConfigThrowsListsMissingParams(): void
    {
        try {
            $this->invokeMethod($this->service, 'createClientCredentialConfig', [
                ['grant_type' => 'client_credentials'],
            ]);
            $this->fail('Expected BadRequestException');
        } catch (BadRequestException $e) {
            $this->assertStringContainsString('scope', $e->getMessage());
            $this->assertStringContainsString('authentication', $e->getMessage());
            $this->assertStringContainsString('client_id', $e->getMessage());
            $this->assertStringContainsString('client_secret', $e->getMessage());
        }
    }

    public function testCreateClientCredentialConfigBodyAuth(): void
    {
        $result = $this->invokeMethod($this->service, 'createClientCredentialConfig', [
            [
                'grant_type'    => 'client_credentials',
                'scope'         => 'read write',
                'authentication' => 'body',
                'client_id'     => 'my-client-id',
                'client_secret' => 'my-client-secret',
            ],
        ]);

        $this->assertArrayHasKey('form_params', $result);
        $this->assertSame('client_credentials', $result['form_params']['grant_type']);
        $this->assertSame('read write', $result['form_params']['scope']);
        $this->assertSame('my-client-id', $result['form_params']['client_id']);
        $this->assertSame('my-client-secret', $result['form_params']['client_secret']);
        $this->assertArrayNotHasKey('auth', $result);
    }

    public function testCreateClientCredentialConfigBasicAuth(): void
    {
        $result = $this->invokeMethod($this->service, 'createClientCredentialConfig', [
            [
                'grant_type'    => 'client_credentials',
                'scope'         => 'api',
                'authentication' => 'basic_auth',
                'client_id'     => 'basic-client',
                'client_secret' => 'basic-secret',
            ],
        ]);

        $this->assertArrayHasKey('form_params', $result);
        $this->assertSame('client_credentials', $result['form_params']['grant_type']);
        $this->assertSame('api', $result['form_params']['scope']);
        $this->assertArrayNotHasKey('client_id', $result['form_params']);
        $this->assertArrayNotHasKey('client_secret', $result['form_params']);

        $this->assertArrayHasKey('auth', $result);
        $this->assertSame('basic-client', $result['auth']['username']);
        $this->assertSame('basic-secret', $result['auth']['password']);
    }

    public function testCreateClientCredentialConfigOtherAuthType(): void
    {
        // When authentication is neither 'body' nor 'basic_auth', no credentials are added.
        $result = $this->invokeMethod($this->service, 'createClientCredentialConfig', [
            [
                'grant_type'    => 'client_credentials',
                'scope'         => 'api',
                'authentication' => 'other',
                'client_id'     => 'some-client',
                'client_secret' => 'some-secret',
            ],
        ]);

        $this->assertArrayHasKey('form_params', $result);
        $this->assertSame('client_credentials', $result['form_params']['grant_type']);
        $this->assertSame('api', $result['form_params']['scope']);
        // Neither body params nor auth should be set.
        $this->assertArrayNotHasKey('client_id', $result['form_params']);
        $this->assertArrayNotHasKey('client_secret', $result['form_params']);
        $this->assertArrayNotHasKey('auth', $result);
    }

    // ── createPasswordConfig (private, tested via reflection) ──

    public function testCreatePasswordConfigThrowsWhenMissingParams(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('required parameters are not set');

        $this->invokeMethod($this->service, 'createPasswordConfig', [
            ['grant_type' => 'password'],
        ]);
    }

    public function testCreatePasswordConfigThrowsListsMissingParams(): void
    {
        try {
            $this->invokeMethod($this->service, 'createPasswordConfig', [
                ['grant_type' => 'password'],
            ]);
            $this->fail('Expected BadRequestException');
        } catch (BadRequestException $e) {
            $this->assertStringContainsString('scope', $e->getMessage());
            $this->assertStringContainsString('authentication', $e->getMessage());
            $this->assertStringContainsString('username', $e->getMessage());
            $this->assertStringContainsString('password', $e->getMessage());
        }
    }

    public function testCreatePasswordConfigBodyAuth(): void
    {
        $result = $this->invokeMethod($this->service, 'createPasswordConfig', [
            [
                'grant_type'    => 'password',
                'scope'         => 'openid',
                'authentication' => 'body',
                'username'      => 'testuser',
                'password'      => 'testpass',
            ],
        ]);

        $this->assertArrayHasKey('form_params', $result);
        $this->assertSame('password', $result['form_params']['grant_type']);
        $this->assertSame('openid', $result['form_params']['scope']);
        $this->assertSame('testuser', $result['form_params']['username']);
        $this->assertSame('testpass', $result['form_params']['password']);
        $this->assertArrayNotHasKey('auth', $result);
    }

    public function testCreatePasswordConfigBasicAuth(): void
    {
        $result = $this->invokeMethod($this->service, 'createPasswordConfig', [
            [
                'grant_type'    => 'password',
                'scope'         => 'openid profile',
                'authentication' => 'basic_auth',
                'username'      => 'basic-user',
                'password'      => 'basic-pass',
            ],
        ]);

        $this->assertArrayHasKey('form_params', $result);
        $this->assertSame('password', $result['form_params']['grant_type']);
        $this->assertSame('openid profile', $result['form_params']['scope']);
        $this->assertArrayNotHasKey('username', $result['form_params']);
        $this->assertArrayNotHasKey('password', $result['form_params']);

        $this->assertArrayHasKey('auth', $result);
        $this->assertSame('basic-user', $result['auth']['username']);
        $this->assertSame('basic-pass', $result['auth']['password']);
    }

    public function testCreatePasswordConfigOtherAuthType(): void
    {
        $result = $this->invokeMethod($this->service, 'createPasswordConfig', [
            [
                'grant_type'    => 'password',
                'scope'         => 'api',
                'authentication' => 'bearer',
                'username'      => 'user',
                'password'      => 'pass',
            ],
        ]);

        $this->assertArrayHasKey('form_params', $result);
        $this->assertArrayNotHasKey('username', $result['form_params']);
        $this->assertArrayNotHasKey('password', $result['form_params']);
        $this->assertArrayNotHasKey('auth', $result);
    }

    // ── fetchJWTToken validation ──

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

    public function testFetchJWTTokenThrowsWhenAllParamsMissing(): void
    {
        $this->expectException(BadRequestException::class);

        $this->service->fetchJWTToken([]);
    }

    public function testFetchJWTTokenThrowsMissingParamsList(): void
    {
        try {
            $this->service->fetchJWTToken(['algorithm' => 'HS256']);
            $this->fail('Expected BadRequestException');
        } catch (BadRequestException $e) {
            $this->assertStringContainsString('payload', $e->getMessage());
            $this->assertStringContainsString('secret', $e->getMessage());
        }
    }

    // ── fetchJWTToken generation (HS algorithms) ──

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
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

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

    // ── fetchJWTToken generation (RS algorithms) ──

    /**
     * Generate a base64-encoded RSA private key for testing.
     *
     * @return string Base64-encoded PEM key.
     */
    private function generateTestRsaKey(): string
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $key = openssl_pkey_new($config);
        openssl_pkey_export($key, $pem);

        return base64_encode($pem);
    }

    public function testFetchJWTTokenGeneratesRs256Token(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        $token = $this->service->fetchJWTToken([
            'payload'   => '{"sub": "rs256-test", "iss": "test-issuer"}',
            'secret'    => $rsaKey,
            'algorithm' => 'RS256',
        ]);

        $this->assertNotEmpty($token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $payload = json_decode(base64_decode($parts[1]), true);
        $this->assertSame('rs256-test', $payload['sub']);
        $this->assertSame('test-issuer', $payload['iss']);
    }

    public function testFetchJWTTokenGeneratesRs384Token(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        $token = $this->service->fetchJWTToken([
            'payload'   => '{"sub": "rs384-test"}',
            'secret'    => $rsaKey,
            'algorithm' => 'RS384',
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('RS384', $header['alg']);
    }

    public function testFetchJWTTokenGeneratesRs512Token(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        $token = $this->service->fetchJWTToken([
            'payload'   => '{"sub": "rs512-test"}',
            'secret'    => $rsaKey,
            'algorithm' => 'RS512',
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('RS512', $header['alg']);
    }

    public function testFetchJWTTokenGeneratesPs256Token(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        $token = $this->service->fetchJWTToken([
            'payload'   => '{"sub": "ps256-test"}',
            'secret'    => $rsaKey,
            'algorithm' => 'PS256',
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('PS256', $header['alg']);
    }

    // ── fetchJWTToken unsupported algorithm ──

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

    // ── fetchJWTToken with Twig template ──

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

        $payload = json_decode(base64_decode($parts[1]), true);
        $this->assertSame('my-client', $payload['sub']);
    }

    public function testFetchJWTTokenWithComplexTwigTemplate(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $token = $this->service->fetchJWTToken([
            'payload' => '{"sub": "{{ client_id }}", "aud": "{{ audience }}", "scope": "{{ scope }}"}',
            'secret' => 'my-secret-key-that-is-at-least-32-bytes-long!',
            'algorithm' => 'HS256',
            'client_id' => 'app-123',
            'audience'  => 'https://api.example.com',
            'scope'     => 'read:data write:data',
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $payload = json_decode(base64_decode($parts[1]), true);
        $this->assertSame('app-123', $payload['sub']);
        $this->assertSame('https://api.example.com', $payload['aud']);
        $this->assertSame('read:data write:data', $payload['scope']);
    }

    // ── fetchJWTToken with x5t header ──

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
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
    }

    public function testFetchJWTTokenWithoutX5tHasNoX5tInHeader(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $token = $this->service->fetchJWTToken([
            'payload' => '{"sub": "test"}',
            'secret' => 'my-secret-key-that-is-at-least-32-bytes-long!',
            'algorithm' => 'HS256',
        ]);

        $parts = explode('.', $token);
        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertArrayNotHasKey('x5t', $header);
    }

    public function testFetchJWTTokenWithX5tAndRsaKey(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        $token = $this->service->fetchJWTToken([
            'payload'   => '{"sub": "x5t-rsa-test"}',
            'secret'    => $rsaKey,
            'algorithm' => 'RS256',
            'x5t'       => 'rsa-cert-thumbprint-abc',
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('rsa-cert-thumbprint-abc', $header['x5t']);
    }

    // ── getJWK (private, tested via reflection) ──

    public function testGetJwkReturnsHsKeyForHs256(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $jwk = $this->invokeMethod($this->service, 'getJWK', [
            ['algorithm' => 'HS256', 'secret' => 'test-secret'],
        ]);

        $this->assertInstanceOf(\Jose\Component\Core\JWK::class, $jwk);
        $this->assertSame('oct', $jwk->get('kty'));
    }

    public function testGetJwkReturnsHsKeyForHs384(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $jwk = $this->invokeMethod($this->service, 'getJWK', [
            ['algorithm' => 'HS384', 'secret' => 'test-secret'],
        ]);

        $this->assertInstanceOf(\Jose\Component\Core\JWK::class, $jwk);
        $this->assertSame('oct', $jwk->get('kty'));
    }

    public function testGetJwkReturnsHsKeyForHs512(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $jwk = $this->invokeMethod($this->service, 'getJWK', [
            ['algorithm' => 'HS512', 'secret' => 'test-secret'],
        ]);

        $this->assertInstanceOf(\Jose\Component\Core\JWK::class, $jwk);
        $this->assertSame('oct', $jwk->get('kty'));
    }

    public function testGetJwkReturnsRsKeyForRs256(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        $jwk = $this->invokeMethod($this->service, 'getJWK', [
            ['algorithm' => 'RS256', 'secret' => $rsaKey],
        ]);

        $this->assertInstanceOf(\Jose\Component\Core\JWK::class, $jwk);
        $this->assertSame('RSA', $jwk->get('kty'));
    }

    public function testGetJwkReturnsRsKeyForPs256(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        $jwk = $this->invokeMethod($this->service, 'getJWK', [
            ['algorithm' => 'PS256', 'secret' => $rsaKey],
        ]);

        $this->assertInstanceOf(\Jose\Component\Core\JWK::class, $jwk);
        $this->assertSame('RSA', $jwk->get('kty'));
    }

    public function testGetJwkThrowsForUnsupportedAlgorithm(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Algorithm not supported by key generator');

        $this->invokeMethod($this->service, 'getJWK', [
            ['algorithm' => 'ES256', 'secret' => 'irrelevant'],
        ]);
    }

    public function testGetJwkThrowsForEdDsaAlgorithm(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Algorithm not supported by key generator');

        $this->invokeMethod($this->service, 'getJWK', [
            ['algorithm' => 'EdDSA', 'secret' => 'irrelevant'],
        ]);
    }

    // ── getHSJWK (private, tested via reflection) ──

    public function testGetHsJwkReturnsOctKey(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $jwk = $this->invokeMethod($this->service, 'getHSJWK', [
            ['secret' => 'my-test-secret'],
        ]);

        $this->assertInstanceOf(\Jose\Component\Core\JWK::class, $jwk);
        $this->assertSame('oct', $jwk->get('kty'));
        // The 'k' value should be base64-encoded and trimmed of '='.
        $k = $jwk->get('k');
        $this->assertNotEmpty($k);
        $this->assertStringNotContainsString('=', $k);
    }

    public function testGetHsJwkEncodesSecretWithSpecialChars(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $jwk = $this->invokeMethod($this->service, 'getHSJWK', [
            ['secret' => "secret'with\"special"],
        ]);

        $this->assertInstanceOf(\Jose\Component\Core\JWK::class, $jwk);
        $this->assertSame('oct', $jwk->get('kty'));
    }

    // ── getRSJWK (private, tested via reflection) ──

    public function testGetRsJwkReturnsRsaKey(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        $jwk = $this->invokeMethod($this->service, 'getRSJWK', [
            ['secret' => $rsaKey],
        ]);

        $this->assertInstanceOf(\Jose\Component\Core\JWK::class, $jwk);
        $this->assertSame('RSA', $jwk->get('kty'));
        $this->assertSame('sig', $jwk->get('use'));
    }

    public function testGetRsJwkCleansUpTempFile(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        if (function_exists('openssl_pkey_new') === false) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $rsaKey = $this->generateTestRsaKey();

        // Count temp files before.
        $before = glob('/var/tmp/privatekey-*');

        $this->invokeMethod($this->service, 'getRSJWK', [
            ['secret' => $rsaKey],
        ]);

        // Temp file should be cleaned up.
        $after = glob('/var/tmp/privatekey-*');
        $this->assertCount(count($before), $after);
    }

    // ── getJWTPayload (private, tested via reflection) ──

    public function testGetJwtPayloadParsesJsonPayload(): void
    {
        $result = $this->invokeMethod($this->service, 'getJWTPayload', [
            ['payload' => '{"sub": "user1", "iss": "issuer1"}'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('user1', $result['sub']);
        $this->assertSame('issuer1', $result['iss']);
    }

    public function testGetJwtPayloadRendersTwigVariables(): void
    {
        $result = $this->invokeMethod($this->service, 'getJWTPayload', [
            [
                'payload'   => '{"sub": "{{ client_id }}", "aud": "{{ audience }}"}',
                'client_id' => 'rendered-client',
                'audience'  => 'https://api.example.com',
            ],
        ]);

        $this->assertSame('rendered-client', $result['sub']);
        $this->assertSame('https://api.example.com', $result['aud']);
    }

    public function testGetJwtPayloadWithNumericValues(): void
    {
        $result = $this->invokeMethod($this->service, 'getJWTPayload', [
            ['payload' => '{"iat": 1234567890, "exp": 1234571490}'],
        ]);

        $this->assertSame(1234567890, $result['iat']);
        $this->assertSame(1234571490, $result['exp']);
    }

    // ── generateJWT (private, tested via reflection) ──

    public function testGenerateJwtProducesValidCompactSerialization(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $jwk = new \Jose\Component\Core\JWK([
            'kty' => 'oct',
            'k'   => rtrim(base64_encode('test-secret-key-for-jwt'), '='),
        ]);

        $token = $this->invokeMethod($this->service, 'generateJWT', [
            ['sub' => 'test', 'iat' => 1234567890],
            $jwk,
            'HS256',
            null,
        ]);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
        $this->assertArrayNotHasKey('x5t', $header);
    }

    public function testGenerateJwtWithX5tAddsToHeader(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $jwk = new \Jose\Component\Core\JWK([
            'kty' => 'oct',
            'k'   => rtrim(base64_encode('test-secret-key-for-jwt'), '='),
        ]);

        $token = $this->invokeMethod($this->service, 'generateJWT', [
            ['sub' => 'test'],
            $jwk,
            'HS256',
            'my-x5t-thumbprint',
        ]);

        $parts = explode('.', $token);
        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('my-x5t-thumbprint', $header['x5t']);
    }

    public function testGenerateJwtPayloadMatchesInput(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $jwk = new \Jose\Component\Core\JWK([
            'kty' => 'oct',
            'k'   => rtrim(base64_encode('test-secret-key-for-jwt'), '='),
        ]);

        $inputPayload = [
            'sub'   => 'test-subject',
            'iss'   => 'test-issuer',
            'aud'   => 'test-audience',
            'exp'   => 9999999999,
            'iat'   => 1234567890,
        ];

        $token = $this->invokeMethod($this->service, 'generateJWT', [
            $inputPayload,
            $jwk,
            'HS256',
            null,
        ]);

        $parts = explode('.', $token);
        $decoded = json_decode(base64_decode($parts[1]), true);
        $this->assertSame($inputPayload, $decoded);
    }

    // ── createClientCredentialConfig with client_assertion_type ──

    public function testCreateClientCredentialConfigWithJwtBearerAssertion(): void
    {
        if (class_exists('Jose\Component\Core\JWK') === false) {
            $this->markTestSkipped('Jose JWT library not available');
        }

        $result = $this->invokeMethod($this->service, 'createClientCredentialConfig', [
            [
                'grant_type'              => 'client_credentials',
                'scope'                   => 'api',
                'authentication'          => 'body',
                'client_id'               => 'jwt-client',
                'client_secret'           => 'jwt-secret',
                'client_assertion_type'   => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'private_key'             => base64_encode('not-a-real-key'),
                'x5t'                     => 'thumbprint-123',
                'payload'                 => '{"sub": "{{ client_id }}"}',
                'algorithm'               => 'HS256',
                'secret'                  => 'hs256-fallback-secret-at-least-32-bytes-long!!!',
            ],
        ]);

        $this->assertArrayHasKey('form_params', $result);
        $this->assertSame(
            'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            $result['form_params']['client_assertion_type']
        );
        $this->assertNotEmpty($result['form_params']['client_assertion']);
        // The assertion should be a valid JWT (3 parts).
        $parts = explode('.', $result['form_params']['client_assertion']);
        $this->assertCount(3, $parts);
    }

    public function testCreateClientCredentialConfigWithoutJwtBearerAssertionType(): void
    {
        $result = $this->invokeMethod($this->service, 'createClientCredentialConfig', [
            [
                'grant_type'              => 'client_credentials',
                'scope'                   => 'api',
                'authentication'          => 'body',
                'client_id'               => 'normal-client',
                'client_secret'           => 'normal-secret',
                'client_assertion_type'   => 'some_other_type',
            ],
        ]);

        $this->assertArrayNotHasKey('client_assertion_type', $result['form_params']);
        $this->assertArrayNotHasKey('client_assertion', $result['form_params']);
    }

    // ── Constants ──

    public function testRequiredParametersClientCredentials(): void
    {
        $this->assertContains('grant_type', AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
        $this->assertContains('scope', AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
        $this->assertContains('authentication', AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
        $this->assertContains('client_id', AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
        $this->assertContains('client_secret', AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
        $this->assertCount(5, AuthenticationService::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS);
    }

    public function testRequiredParametersPassword(): void
    {
        $this->assertContains('grant_type', AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
        $this->assertContains('scope', AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
        $this->assertContains('authentication', AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
        $this->assertContains('username', AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
        $this->assertContains('password', AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
        $this->assertCount(5, AuthenticationService::REQUIRED_PARAMETERS_PASSWORD);
    }

    public function testRequiredParametersJwt(): void
    {
        $this->assertContains('payload', AuthenticationService::REQUIRED_PARAMETERS_JWT);
        $this->assertContains('secret', AuthenticationService::REQUIRED_PARAMETERS_JWT);
        $this->assertContains('algorithm', AuthenticationService::REQUIRED_PARAMETERS_JWT);
        $this->assertCount(3, AuthenticationService::REQUIRED_PARAMETERS_JWT);
    }
}
