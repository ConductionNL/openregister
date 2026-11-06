<?php

/**
 * HS256/384/512 verification through web-token.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Consumer;

use OCA\OpenRegister\Service\Consumer\HmacJwsVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The tokens are signed with hash_hmac(), not with web-token, so a
 * consumer that signs without the library still verifies, and a short
 * secret can be signed at all (web-token's JWSBuilder refuses one).
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
class HmacJwsVerifierTest extends TestCase {

	/**
	 * The hash each HS algorithm uses, with a secret of exactly the hash output size.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function algorithms(): array {
		return [
			'HS256' => ['HS256', 'sha256', str_repeat('a', 32)],
			'HS384' => ['HS384', 'sha384', str_repeat('b', 48)],
			'HS512' => ['HS512', 'sha512', str_repeat('c', 64)],
		];
	}//end algorithms()

	/**
	 * A token signed with the pinned algorithm and a long-enough secret verifies.
	 *
	 * @param string $algorithm The JWT algorithm.
	 * @param string $hash      The hash_hmac algorithm.
	 * @param string $secret    The shared secret.
	 *
	 * @dataProvider algorithms
	 *
	 * @return void
	 */
	public function testATokenSignedWithThePinnedAlgorithmVerifies(string $algorithm, string $hash, string $secret): void {
		$token = $this->sign(algorithm: $algorithm, hash: $hash, secret: $secret);

		$this->assertTrue(condition: (new HmacJwsVerifier())->verify(token: $token, algorithm: $algorithm, key: $secret));
	}//end testATokenSignedWithThePinnedAlgorithmVerifies()

	/**
	 * A token signed with another secret is refused.
	 *
	 * @return void
	 */
	public function testATokenSignedWithAnotherSecretIsRefused(): void {
		$token = $this->sign(algorithm: 'HS256', hash: 'sha256', secret: str_repeat('a', 32));

		$this->assertFalse(condition: (new HmacJwsVerifier())->verify(token: $token, algorithm: 'HS256', key: str_repeat('z', 32)));
	}//end testATokenSignedWithAnotherSecretIsRefused()

	/**
	 * A token whose payload changed after signing is refused.
	 *
	 * @return void
	 */
	public function testATamperedPayloadIsRefused(): void {
		$secret = str_repeat('a', 32);
		$parts = explode('.', $this->sign(algorithm: 'HS256', hash: 'sha256', secret: $secret));
		$parts[1] = $this->encode(raw: (string)json_encode(['iss' => 'someone-else', 'iat' => time()]));

		$this->assertFalse(condition: (new HmacJwsVerifier())->verify(token: implode('.', $parts), algorithm: 'HS256', key: $secret));
	}//end testATamperedPayloadIsRefused()

	/**
	 * A token signed as HS512 does not verify against a consumer pinned to HS256.
	 *
	 * @return void
	 */
	public function testATokenOfAnotherHmacAlgorithmIsRefused(): void {
		$secret = str_repeat('c', 64);
		$token = $this->sign(algorithm: 'HS512', hash: 'sha512', secret: $secret);

		$this->assertFalse(condition: (new HmacJwsVerifier())->verify(token: $token, algorithm: 'HS256', key: $secret));
	}//end testATokenOfAnotherHmacAlgorithmIsRefused()

	/**
	 * A secret shorter than the hash output is refused by the library too, even with a correct signature.
	 *
	 * @return void
	 */
	public function testASecretShorterThanTheHashOutputIsRefused(): void {
		$secret = str_repeat('a', 31);
		$token = $this->sign(algorithm: 'HS256', hash: 'sha256', secret: $secret);

		$this->assertFalse(condition: (new HmacJwsVerifier())->verify(token: $token, algorithm: 'HS256', key: $secret));
	}//end testASecretShorterThanTheHashOutputIsRefused()

	/**
	 * A malformed token is refused without an exception.
	 *
	 * @return void
	 */
	public function testAMalformedTokenIsRefusedWithoutAnException(): void {
		$this->assertFalse(condition: (new HmacJwsVerifier())->verify(token: 'not-a-token', algorithm: 'HS256', key: str_repeat('a', 32)));
	}//end testAMalformedTokenIsRefusedWithoutAnException()

	/**
	 * A non-HMAC algorithm is not handled here.
	 *
	 * @return void
	 */
	public function testANonHmacAlgorithmIsRefused(): void {
		$secret = str_repeat('a', 32);
		$token = $this->sign(algorithm: 'HS256', hash: 'sha256', secret: $secret);

		$this->assertFalse(condition: (new HmacJwsVerifier())->verify(token: $token, algorithm: 'RS256', key: $secret));
	}//end testANonHmacAlgorithmIsRefused()

	/**
	 * A compact JWS signed with hash_hmac().
	 *
	 * @param string $algorithm The JWT algorithm in the header.
	 * @param string $hash      The hash_hmac algorithm.
	 * @param string $secret    The shared secret.
	 *
	 * @return string
	 */
	private function sign(string $algorithm, string $hash, string $secret): string {
		$header = $this->encode(raw: (string)json_encode(['alg' => $algorithm, 'typ' => 'JWT']));
		$payload = $this->encode(raw: (string)json_encode(['iss' => 'zaaksysteem', 'iat' => time()]));
		$signature = $this->encode(raw: hash_hmac($hash, $header . '.' . $payload, $secret, true));
		return $header . '.' . $payload . '.' . $signature;
	}//end sign()

	/**
	 * Base64url without padding.
	 *
	 * @param string $raw The bytes.
	 *
	 * @return string
	 */
	private function encode(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}//end encode()
}//end class
