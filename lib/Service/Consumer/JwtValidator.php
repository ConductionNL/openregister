<?php

/**
 * Signature and claim checks for a JWT presented by an API consumer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

use DateTime;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Verifies a compact JWS against a pinned algorithm and key, and its claims.
 *
 * The algorithm always comes from the consumer's stored configuration, never
 * from the token: only that algorithm is loaded into the verifier.
 */
class JwtValidator {

	/**
	 * Map of JWT HMAC algorithm names to hash_hmac algorithm strings.
	 *
	 * @var array<string, string>
	 */
	public const HMAC_MAP = [
		'HS256' => 'sha256',
		'HS384' => 'sha384',
		'HS512' => 'sha512',
	];

	/**
	 * Allowed clock skew in seconds for the iat and nbf checks.
	 *
	 * @var integer
	 */
	public const CLOCK_SKEW_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory|null $cacheFactory Distributed cache that remembers used token ids.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		private readonly ?ICacheFactory $cacheFactory = null,
	) {
	}//end __construct()

	/**
	 * Whether the token's signature verifies with the key under the pinned algorithm.
	 *
	 * HS* uses the key as the shared secret; RS* and PS* use it as an RSA
	 * public key: a PEM, or a base64-encoded PEM (the form integriq stores).
	 *
	 * @param string $token     The compact JWS.
	 * @param string $algorithm The pinned algorithm.
	 * @param string $key       The secret or public key.
	 *
	 * @return bool True when the signature is valid.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function verifySignature(string $token, string $algorithm, string $key): bool {
		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			return false;
		}

		if (isset(self::HMAC_MAP[$algorithm]) === true) {
			$expected = hash_hmac(self::HMAC_MAP[$algorithm], $parts[0] . '.' . $parts[1], $key, true);
			return hash_equals($expected, (string)base64_decode(strtr($parts[2], '-_', '+/')));
		}

		return (new RsaJwsVerifier())->verify(token: $token, algorithm: $algorithm, key: $key);
	}//end verifySignature()

	/**
	 * Validate the claims: iat, exp, nbf and a jti used once.
	 *
	 * The iat must be present and not in the future beyond the clock skew; exp
	 * (default iat + 1 hour) must not have passed; nbf, when present, must
	 * have been reached; a jti, when present, is accepted once and remembered
	 * until the token expires. A caller-supplied exp is not capped: whether
	 * OpenRegister caps a token's lifetime is an open question (Q4).
	 *
	 * @param array $payload The token payload.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When a claim refuses the token.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function validateClaims(array $payload): void {
		$now = new DateTime();

		if (isset($payload['iat']) === false) {
			throw new AuthenticationException(
				message: 'The token has no time of creation',
				details: ['iat' => null]
			);
		}

		$iat = new DateTime('@' . $payload['iat']);

		// A token issued in the future (beyond the skew) is pre-generated:
		// accepting it would stretch its usable window past its stated life.
		if ($iat->getTimestamp() > ($now->getTimestamp() + self::CLOCK_SKEW_SECONDS)) {
			throw new AuthenticationException(
				message: 'The token has an invalid issue time',
				details: ['iat' => $iat->getTimestamp(), 'time checked' => $now->getTimestamp()]
			);
		}

		$exp = clone $iat;
		$exp->modify('+1 Hour');
		if (isset($payload['exp']) === true) {
			$exp = new DateTime('@' . $payload['exp']);
		}

		if ($exp->diff($now)->format('%R') === '+') {
			throw new AuthenticationException(
				message: 'The token has expired',
				details: [
					'iat' => $iat->getTimestamp(),
					'exp' => $exp->getTimestamp(),
					'time checked' => $now->getTimestamp(),
				]
			);
		}

		if (isset($payload['nbf']) === true
			&& $now->getTimestamp() < ((int)$payload['nbf'] - self::CLOCK_SKEW_SECONDS)
		) {
			throw new AuthenticationException(
				message: 'The token is not yet valid',
				details: ['nbf' => (int)$payload['nbf'], 'time checked' => $now->getTimestamp()]
			);
		}

		$this->refuseReplay(payload: $payload, expiresAt: $exp->getTimestamp(), now: $now->getTimestamp());
	}//end validateClaims()

	/**
	 * Accept a token's jti once: remember it until the token expires.
	 *
	 * Fails closed: a token that carries a jti on an instance without a
	 * distributed cache cannot be checked for replay and is refused.
	 *
	 * @param array $payload   The token payload.
	 * @param int   $expiresAt When the token expires (unix time).
	 * @param int   $now       Now (unix time).
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the jti was used before, or cannot be checked.
	 */
	private function refuseReplay(array $payload, int $expiresAt, int $now): void {
		if (isset($payload['jti']) === false || $payload['jti'] === '') {
			return;
		}

		$cache = $this->jtiCache();
		if ($cache === null) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'A token id (jti) cannot be checked for replay on this instance']
			);
		}

		$cacheKey = 'jti:' . hash('sha256', (string)$payload['jti']);
		if ($cache->get($cacheKey) !== null) {
			throw new AuthenticationException(
				message: 'The token has already been used (jti replay)',
				details: ['jti' => $payload['jti']]
			);
		}

		$cache->set($cacheKey, 1, max(1, ($expiresAt - $now) + self::CLOCK_SKEW_SECONDS));
	}//end refuseReplay()

	/**
	 * The distributed cache that remembers used token ids, or null without one.
	 *
	 * @return ICache|null
	 */
	private function jtiCache(): ?ICache {
		return $this->cacheFactory?->createDistributed('openregister.jti');
	}//end jtiCache()
}//end class
