<?php

/**
 * Behaviour tests for JwtValidator and RsaJwsVerifier: decoding, pinned verification, claims and replay.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Consumer;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\Consumer\JwsKey;
use OCA\OpenRegister\Service\Consumer\JwtValidator;
use OCA\OpenRegister\Service\Consumer\RsaJwsVerifier;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Consumer\DecodedJwt;

/**
 * Times are relative to the real clock with margins far beyond the 60 s skew,
 * so no test depends on when it runs. The PS* (RSA-PSS) algorithms are not
 * exercised: without gmp or bcmath they run in pure-PHP big-integer arithmetic
 * and take minutes per signature.
 *
 * @covers \OCA\OpenRegister\Service\Consumer\JwtValidator
 * @covers \OCA\OpenRegister\Service\Consumer\RsaJwsVerifier
 * @uses   \OCA\OpenRegister\Exception\AuthenticationException
 */
class JwtValidatorBehaviourTest extends TestCase
{
    private const SECRET = 'a-shared-secret-of-at-least-32-bytes!!';

    private static string $privatePem = '';

    private static string $publicPem = '';

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        self::$privatePem = (string) $private;
        self::$publicPem  = (string) openssl_pkey_get_details($key)['key'];
    }//end setUpBeforeClass()

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }//end b64()

    private static function unsigned(array $header, array $payload): string
    {
        return self::b64((string) json_encode($header)).'.'.self::b64((string) json_encode($payload));
    }//end unsigned()

    private static function hs256(array $payload, string $secret = self::SECRET): string
    {
        $input = self::unsigned(['alg' => 'HS256', 'typ' => 'JWT'], $payload);
        return $input.'.'.self::b64(hash_hmac('sha256', $input, $secret, true));
    }//end hs256()

    private static function rs256(array $payload): string
    {
        $input = self::unsigned(['alg' => 'RS256', 'typ' => 'JWT'], $payload);
        openssl_sign($input, $signature, self::$privatePem, OPENSSL_ALGO_SHA256);
        return $input.'.'.self::b64($signature);
    }//end rs256()


    private function refusal(callable $call): AuthenticationException
    {
        try {
            $call();
        } catch (AuthenticationException $e) {
            return $e;
        }

        $this->fail('An AuthenticationException was expected.');
    }//end refusal()

    /**
     * @return array<string, array{0: string, 1: string, 2: array}>
     */
    public static function malformedProvider(): array
    {
        return [
            'empty'         => ['', 'No token has been provided', []],
            'two parts'     => ['a.b', 'The token could not be validated', ['reason' => 'Invalid JWT format']],
            'no alg'        => [self::unsigned(['typ' => 'JWT'], ['iss' => 'x']).'.s', 'The token could not be validated', ['reason' => 'Invalid header']],
            'bad payload'   => [self::b64('{"alg":"HS256"}').'.'.self::b64('nope').'.s', 'The token could not be validated', ['reason' => 'Invalid payload']],
            'no issuer'     => [self::unsigned(['alg' => 'HS256'], ['sub' => 'x']).'.s', 'The token could not be validated', ['reason' => 'No issuer mentioned']],
        ];
    }//end malformedProvider()

    #[DataProvider('malformedProvider')]
    public function testDecodeRefusesMalformedTokensWithTheirReason(string $token, string $message, array $details): void
    {
        $e = $this->refusal(static fn() => (new JwtValidator())->decode($token));

        $this->assertSame([$message, $details], [$e->getMessage(), $e->getDetails()]);
    }//end testDecodeRefusesMalformedTokensWithTheirReason()

    public function testDecodeReturnsHeaderAndPayload(): void
    {
        $this->assertSame(
            [['alg' => 'HS256', 'typ' => 'JWT'], ['iss' => 'app-1', 'sub' => 'u']],
            (new JwtValidator())->decode(self::hs256(['iss' => 'app-1', 'sub' => 'u']))
        );
    }//end testDecodeReturnsHeaderAndPayload()

    /**
     * @return array<string, array{0: array, 1: array, 2: string}>
     */
    public static function pinnedRefusalProvider(): array
    {
        return [
            'no algorithm'      => [['alg' => 'HS256'], ['publicKey' => self::SECRET], 'No verification algorithm configured for issuer'],
            'empty algorithm'   => [['alg' => 'HS256'], ['algorithm' => '', 'publicKey' => self::SECRET], 'No verification algorithm configured for issuer'],
            'alg mismatch'      => [['alg' => 'none'], ['algorithm' => 'HS256', 'publicKey' => self::SECRET], 'Token algorithm does not match issuer configuration'],
            'short secret'      => [['alg' => 'HS512'], ['algorithm' => 'HS512', 'publicKey' => str_repeat('k', 63)], 'The issuer\'s HMAC secret is shorter than the algorithm\'s hash output'],
            'wrong secret'      => [['alg' => 'HS256'], ['algorithm' => 'HS256', 'publicKey' => str_repeat('z', 32)], 'The token does not match the public key'],
        ];
    }//end pinnedRefusalProvider()

    #[DataProvider('pinnedRefusalProvider')]
    public function testVerifyPinnedRefusesWithTheReason(array $header, array $configuration, string $reason): void
    {
        $token = self::hs256(['iss' => 'app-1']);

        $e = $this->refusal(static fn() => (new JwtValidator())->verifyPinned(new DecodedJwt($token, $header), $configuration));

        $this->assertSame(['reason' => $reason], $e->getDetails());
    }//end testVerifyPinnedRefusesWithTheReason()

    public function testVerifyPinnedRefusesAnUnsupportedAlgorithmByName(): void
    {
        $e = $this->refusal(
            static fn() => (new JwtValidator())->verifyPinned(new DecodedJwt('a.b.c', ['alg' => 'ES256']), ['algorithm' => 'ES256', 'publicKey' => 'k'])
        );

        $this->assertSame(['The token algorithm is not supported', ['algorithm' => 'ES256']], [$e->getMessage(), $e->getDetails()]);
    }//end testVerifyPinnedRefusesAnUnsupportedAlgorithmByName()

    public function testVerifyPinnedAcceptsMatchingHmacAndRsaSignatures(): void
    {
        $validator = new JwtValidator();

        $validator->verifyPinned(new DecodedJwt(self::hs256(['iss' => 'a']), ['alg' => 'HS256']), ['algorithm' => 'HS256', 'publicKey' => self::SECRET]);
        $validator->verifyPinned(new DecodedJwt(self::rs256(['iss' => 'a']), ['alg' => 'RS256']), ['algorithm' => 'RS256', 'publicKey' => self::$publicPem]);

        $this->assertTrue($validator->verifySignature(self::rs256(['iss' => 'a']), new JwsKey('RS256', base64_encode(self::$publicPem))));
    }//end testVerifyPinnedAcceptsMatchingHmacAndRsaSignatures()

    public function testVerifySignatureRefusesWrongShapesKeysAndAlgorithms(): void
    {
        $validator = new JwtValidator();
        $rs        = self::rs256(['iss' => 'a']);

        $this->assertFalse($validator->verifySignature('only.two', new JwsKey('HS256', self::SECRET)));
        $this->assertFalse($validator->verifySignature(self::hs256(['iss' => 'a'], 'short'), new JwsKey('HS256', 'short')));
        $this->assertFalse($validator->verifySignature($rs, new JwsKey('RS384', self::$publicPem)));
        $this->assertFalse($validator->verifySignature($rs, new JwsKey('RS256', 'not a key at all')));
        $this->assertFalse((new RsaJwsVerifier())->verify($rs, new JwsKey('ES256', self::$publicPem)));
        $this->assertFalse((new RsaJwsVerifier())->verify('###.###.###', new JwsKey('RS256', self::$publicPem)));
    }//end testVerifySignatureRefusesWrongShapesKeysAndAlgorithms()

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function rsaAlgorithmProvider(): array
    {
        return [
            'RS384' => [RS384::class, 'RS384'],
            'RS512' => [RS512::class, 'RS512'],
        ];
    }//end rsaAlgorithmProvider()

    #[DataProvider('rsaAlgorithmProvider')]
    public function testEachRsaAlgorithmVerifiesItsOwnSignatureOnly(string $class, string $alg): void
    {
        $jws   = (new JWSBuilder(new AlgorithmManager([new $class()])))
            ->create()
            ->withPayload('{"iss":"a"}')
            ->addSignature(JWKFactory::createFromKey(self::$privatePem), ['alg' => $alg])
            ->build();
        $token = (new CompactSerializer())->serialize($jws, 0);

        $this->assertTrue((new RsaJwsVerifier())->verify($token, new JwsKey($alg, self::$publicPem)));
        $this->assertFalse((new RsaJwsVerifier())->verify(self::rs256(['iss' => 'a']), new JwsKey($alg, self::$publicPem)));
    }//end testEachRsaAlgorithmVerifiesItsOwnSignatureOnly()

    public function testANonRsaPublicKeyIsRefused(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        $this->assertFalse(
            (new RsaJwsVerifier())->verify(self::rs256(['iss' => 'a']), new JwsKey('RS256', (string) openssl_pkey_get_details($ec)['key']))
        );
    }//end testANonRsaPublicKeyIsRefused()

    public function testHmacKeyLengthIsOnlyCheckedForHmacAlgorithms(): void
    {
        $validator = new JwtValidator();

        $this->assertFalse($validator->hmacKeyTooShort('RS256', ''));
        $this->assertTrue($validator->hmacKeyTooShort('HS384', str_repeat('k', 47)));
        $this->assertFalse($validator->hmacKeyTooShort('HS384', str_repeat('k', 48)));
    }//end testHmacKeyLengthIsOnlyCheckedForHmacAlgorithms()

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function claimRefusalProvider(): array
    {
        $now = time();
        return [
            'no iat'          => [[], 'The token has no time of creation'],
            'iat in future'   => [['iat' => $now + 600], 'The token has an invalid issue time'],
            'default expiry'  => [['iat' => $now - 7200], 'The token has expired'],
            'explicit expiry' => [['iat' => $now - 600, 'exp' => $now - 300], 'The token has expired'],
            'not yet valid'   => [['iat' => $now, 'nbf' => $now + 600], 'The token is not yet valid'],
        ];
    }//end claimRefusalProvider()

    #[DataProvider('claimRefusalProvider')]
    public function testValidateClaimsRefusesWithTheMessage(array $payload, string $message): void
    {
        $e = $this->refusal(static fn() => (new JwtValidator())->validateClaims($payload));

        $this->assertSame($message, $e->getMessage());
    }//end testValidateClaimsRefusesWithTheMessage()

    public function testValidClaimsWithinTheSkewAndWithoutJtiPass(): void
    {
        $now     = time();
        $factory = $this->createMock(ICacheFactory::class);
        $factory->expects($this->never())->method('createDistributed');

        $validator = new JwtValidator($factory);

        $validator->validateClaims(['iat' => $now + 30, 'nbf' => $now + 30, 'exp' => $now + 600, 'jti' => '']);
        $e = $this->refusal(static fn() => $validator->validateClaims(['iat' => $now + 30, 'nbf' => $now + 120, 'exp' => $now + 600]));

        $this->assertSame('The token is not yet valid', $e->getMessage());
    }//end testValidClaimsWithinTheSkewAndWithoutJtiPass()

    public function testAJtiWithoutACacheCannotBeCheckedAndIsRefused(): void
    {
        $e = $this->refusal(static fn() => (new JwtValidator())->validateClaims(['iat' => time(), 'jti' => 'one']));

        $this->assertSame(['reason' => 'A token id (jti) cannot be checked for replay on this instance'], $e->getDetails());
    }//end testAJtiWithoutACacheCannotBeCheckedAndIsRefused()

    public function testAJtiIsRememberedUntilExpiryAndASecondUseIsRefused(): void
    {
        $store = [];
        $ttls  = [];
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(
            static function (string $k) use (&$store): mixed {
                return $store[$k] ?? null;
            }
        );
        $cache->method('set')->willReturnCallback(
            static function (string $k, mixed $v, int $ttl) use (&$store, &$ttls): bool {
                $store[$k] = $v;
                $ttls[]    = $ttl;
                return true;
            }
        );
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->with('openregister.jti')->willReturn($cache);
        $validator = new JwtValidator($factory);
        $now       = time();

        $validator->validateClaims(['iat' => $now, 'exp' => $now + 1000, 'jti' => 'abc']);
        $e = $this->refusal(static fn() => $validator->validateClaims(['iat' => $now, 'exp' => $now + 1000, 'jti' => 'abc']));

        $this->assertSame(['jti:'.hash('sha256', 'abc')], array_keys($store));
        $this->assertCount(1, $ttls);
        $this->assertGreaterThanOrEqual(1055, $ttls[0]);
        $this->assertLessThanOrEqual(1060, $ttls[0]);
        $this->assertSame(['The token has already been used (jti replay)', ['jti' => 'abc']], [$e->getMessage(), $e->getDetails()]);
    }//end testAJtiIsRememberedUntilExpiryAndASecondUseIsRefused()
}//end class
