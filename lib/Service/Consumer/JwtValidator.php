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
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
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
	 * The shortest HMAC secret each algorithm accepts, in bytes.
	 *
	 * RFC 7518 §3.2: a key of the same size as the hash output or larger MUST
	 * be used. hash_hmac() itself takes any key, an empty one included, and
	 * an empty or guessable secret lets anyone mint a token for the consumer.
	 * The web-token library integriq verified with before gate 23 refused
	 * these as "Invalid key length."; this keeps that refusal.
	 */
	public const HMAC_MIN_KEY_BYTES = [
		'HS256' => 32,
		'HS384' => 48,
		'HS512' => 64,
	];

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
	 * The algorithms a consumer may pin.
	 *
	 * @var string[]
	 */
	public const SUPPORTED_ALGORITHMS = ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512'];

	/**
	 * Split a compact JWS into its decoded header and payload.
	 *
	 * @param string $token The compact JWS.
	 *
	 * @return array{0: array, 1: array} The header and the payload; the payload names an issuer.
	 *
	 * @throws AuthenticationException When the token is empty or malformed, or names no issuer.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function decode(string $token): array {
		if ($token === '') {
			throw new AuthenticationException(message: 'No token has been provided', details: []);
		}

		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			throw $this->invalid(reason: 'Invalid JWT format');
		}

		$header = $this->decodedSegment(segment: $parts[0]);
		if (is_array($header) === false || isset($header['alg']) === false) {
			throw $this->invalid(reason: 'Invalid header');
		}

		return [$header, $this->payloadOf(segment: $parts[1])];
	}//end decode()

	/**
	 * A token's payload segment, decoded; it must be an object that names its issuer.
	 *
	 * @param string $segment The base64url payload segment.
	 *
	 * @return array The payload.
	 *
	 * @throws AuthenticationException When the payload is unreadable or names no issuer.
	 */
	private function payloadOf(string $segment): array {
		$payload = $this->decodedSegment(segment: $segment);
		if (is_array($payload) === false) {
			throw $this->invalid(reason: 'Invalid payload');
		}

		if (empty($payload['iss']) === true) {
			throw $this->invalid(reason: 'No issuer mentioned');
		}

		return $payload;
	}//end payloadOf()

	/**
	 * One base64url token segment, JSON-decoded.
	 *
	 * @param string $segment The segment.
	 *
	 * @return mixed The decoded value, or null when it is not JSON.
	 */
	private function decodedSegment(string $segment): mixed {
		return json_decode((string)base64_decode(strtr($segment, '-_', '+/')), true);
	}//end decodedSegment()

	/**
	 * Verify the token under the algorithm the consumer's configuration pins.
	 *
	 * The algorithm MUST come from the stored configuration, never from the
	 * token header: taking it from the header lets an RS/PS consumer's public
	 * key be used as an HMAC secret (algorithm confusion). The header's alg
	 * must equal the pinned one.
	 *
	 * @param DecodedJwt $jwt           The compact JWS and its decoded header.
	 * @param array      $configuration The consumer's authorization configuration (algorithm, publicKey).
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When no algorithm is pinned, the header differs, the algorithm is unsupported or the signature fails.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function verifyPinned(DecodedJwt $jwt, array $configuration): void {
		$key = new JwsKey(
			algorithm: $this->pinnedAlgorithm(header: $jwt->header, configuration: $configuration),
			material: (string)($configuration['publicKey'] ?? '')
		);
		$this->verifyWithKey(token: $jwt->token, key: $key);
	}//end verifyPinned()

	/**
	 * The algorithm the configuration pins, refused unless the header names the same supported one.
	 *
	 * @param array $header        The decoded header.
	 * @param array $configuration The consumer's authorization configuration.
	 *
	 * @return string The pinned algorithm.
	 *
	 * @throws AuthenticationException When no algorithm is pinned, the header differs or the algorithm is unsupported.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	private function pinnedAlgorithm(array $header, array $configuration): string {
		$algorithm = ($configuration['algorithm'] ?? null);
		if (is_string($algorithm) === false || $algorithm === '') {
			throw $this->invalid(reason: 'No verification algorithm configured for issuer');
		}

		if ($header['alg'] !== $algorithm) {
			throw $this->invalid(reason: 'Token algorithm does not match issuer configuration');
		}

		if (in_array($algorithm, self::SUPPORTED_ALGORITHMS, true) === false) {
			throw new AuthenticationException(
				message: 'The token algorithm is not supported',
				details: ['algorithm' => $algorithm]
			);
		}

		return $algorithm;
	}//end pinnedAlgorithm()

	/**
	 * Refuse a token whose signature does not verify with the pinned key.
	 *
	 * @param string $token The compact JWS.
	 * @param JwsKey $key   The pinned algorithm and its key material.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the HMAC secret is too short or the signature fails.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	private function verifyWithKey(string $token, JwsKey $key): void {
		if ($this->hmacKeyTooShort(algorithm: $key->algorithm, key: $key->material) === true) {
			throw $this->invalid(reason: 'The issuer\'s HMAC secret is shorter than the algorithm\'s hash output');
		}

		if ($this->verifySignature(token: $token, key: $key) === false) {
			throw $this->invalid(reason: 'The token does not match the public key');
		}
	}//end verifyWithKey()


	/**
	 * Whether an HMAC secret is shorter than the algorithm's hash output.
	 *
	 * @param string $algorithm The pinned algorithm.
	 * @param string $key       The issuer's stored secret.
	 *
	 * @return bool True for an HMAC algorithm with a secret below HMAC_MIN_KEY_BYTES.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function hmacKeyTooShort(string $algorithm, string $key): bool {
		if (isset(self::HMAC_MIN_KEY_BYTES[$algorithm]) === false) {
			return false;
		}

		return strlen($key) < self::HMAC_MIN_KEY_BYTES[$algorithm];
	}//end hmacKeyTooShort()

	/**
	 * The refusal for a token that could not be validated.
	 *
	 * @param string $reason Why.
	 *
	 * @return AuthenticationException
	 */
	private function invalid(string $reason): AuthenticationException {
		return new AuthenticationException(message: 'The token could not be validated', details: ['reason' => $reason]);
	}//end invalid()

	/**
	 * Whether the token's signature verifies with the key under the pinned algorithm.
	 *
	 * HS* uses the key as the shared secret; RS* and PS* use it as an RSA
	 * public key: a PEM, or a base64-encoded PEM (the form integriq stores).
	 *
	 * @param string $token The compact JWS.
	 * @param JwsKey $key   The pinned algorithm and the secret or public key.
	 *
	 * @return bool True when the signature is valid.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function verifySignature(string $token, JwsKey $key): bool {
		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			return false;
		}

		if (isset(self::HMAC_MAP[$key->algorithm]) === true) {
			if ($this->hmacKeyTooShort(algorithm: $key->algorithm, key: $key->material) === true) {
				return false;
			}

			$expected = hash_hmac(self::HMAC_MAP[$key->algorithm], $parts[0] . '.' . $parts[1], $key->material, true);
			return hash_equals($expected, (string)base64_decode(strtr($parts[2], '-_', '+/')));
		}

		return (new RsaJwsVerifier())->verify(token: $token, key: $key);
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
		$run = new ClaimValidationRun(payload: $payload, now: new DateTime());

		$this->refuseMissingIssueTime(payload: $payload);
		$run->iat = new DateTime('@' . $payload['iat']);

		$this->refuseFutureIssueTime(run: $run);
		$run->exp = $this->expiryOf(run: $run);

		$this->refuseExpired(run: $run);
		$this->refuseNotYetValid(run: $run);

		$this->refuseReplay(payload: $payload, lifetime: ($run->exp->getTimestamp() - $run->now->getTimestamp()));
	}//end validateClaims()

	/**
	 * Refuse a token without an iat.
	 *
	 * @param array $payload The token payload.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the token has no time of creation.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	private function refuseMissingIssueTime(array $payload): void {
		if (isset($payload['iat']) === false) {
			throw new AuthenticationException(
				message: 'The token has no time of creation',
				details: ['iat' => null]
			);
		}
	}//end refuseMissingIssueTime()

	/**
	 * Refuse a token issued in the future beyond the skew: it is pre-generated,
	 * and accepting it would stretch its usable window past its stated life.
	 *
	 * @param ClaimValidationRun $run The pass, carrying the iat and the moment checked.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the issue time lies beyond the skew.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	private function refuseFutureIssueTime(ClaimValidationRun $run): void {
		if ($run->iat->getTimestamp() > ($run->now->getTimestamp() + self::CLOCK_SKEW_SECONDS)) {
			throw new AuthenticationException(
				message: 'The token has an invalid issue time',
				details: ['iat' => $run->iat->getTimestamp(), 'time checked' => $run->now->getTimestamp()]
			);
		}
	}//end refuseFutureIssueTime()

	/**
	 * The token's expiry: its exp claim, else one hour after its iat.
	 *
	 * @param ClaimValidationRun $run The pass, carrying the payload and the iat.
	 *
	 * @return DateTime The expiry.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	private function expiryOf(ClaimValidationRun $run): DateTime {
		$exp = clone $run->iat;
		$exp->modify('+1 Hour');
		if (isset($run->payload['exp']) === true) {
			$exp = new DateTime('@' . $run->payload['exp']);
		}

		return $exp;
	}//end expiryOf()

	/**
	 * Refuse a token whose expiry has passed.
	 *
	 * @param ClaimValidationRun $run The pass, carrying the iat, the expiry and the moment checked.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the token has expired.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	private function refuseExpired(ClaimValidationRun $run): void {
		if ($run->exp->diff($run->now)->format('%R') === '+') {
			throw new AuthenticationException(
				message: 'The token has expired',
				details: [
					'iat' => $run->iat->getTimestamp(),
					'exp' => $run->exp->getTimestamp(),
					'time checked' => $run->now->getTimestamp(),
				]
			);
		}
	}//end refuseExpired()

	/**
	 * Refuse a token whose nbf, when present, has not been reached within the skew.
	 *
	 * @param ClaimValidationRun $run The pass, carrying the payload and the moment checked.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the token is not yet valid.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	private function refuseNotYetValid(ClaimValidationRun $run): void {
		if (isset($run->payload['nbf']) === true
			&& $run->now->getTimestamp() < ((int)$run->payload['nbf'] - self::CLOCK_SKEW_SECONDS)
		) {
			throw new AuthenticationException(
				message: 'The token is not yet valid',
				details: ['nbf' => (int)$run->payload['nbf'], 'time checked' => $run->now->getTimestamp()]
			);
		}
	}//end refuseNotYetValid()

	/**
	 * Accept a token's jti once: remember it until the token expires.
	 *
	 * Fails closed: a token that carries a jti on an instance without a
	 * distributed cache cannot be checked for replay and is refused.
	 *
	 * @param array $payload  The token payload.
	 * @param int   $lifetime Seconds until the token expires.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the jti was used before, or cannot be checked.
	 */
	private function refuseReplay(array $payload, int $lifetime): void {
		if (isset($payload['jti']) === false || $payload['jti'] === '') {
			return;
		}

		$cache = $this->jtiCache();
		if ($cache === null) {
			throw $this->replayUncheckable();
		}

		$cacheKey = 'jti:' . hash('sha256', (string)$payload['jti']);
		if ($cache->get($cacheKey) !== null) {
			throw $this->replayed(jti: $payload['jti']);
		}

		$cache->set($cacheKey, 1, max(1, $lifetime + self::CLOCK_SKEW_SECONDS));
	}//end refuseReplay()

	/**
	 * The refusal of a token whose jti was already used.
	 *
	 * @param mixed $jti The token id.
	 *
	 * @return AuthenticationException The refusal.
	 */
	private function replayed(mixed $jti): AuthenticationException {
		return new AuthenticationException(
			message: 'The token has already been used (jti replay)',
			details: ['jti' => $jti]
		);
	}//end replayed()

	/**
	 * The refusal of a token with a jti on an instance that cannot check it for replay (fail closed).
	 *
	 * @return AuthenticationException The refusal.
	 */
	private function replayUncheckable(): AuthenticationException {
		return new AuthenticationException(
			message: 'The token could not be validated',
			details: ['reason' => 'A token id (jti) cannot be checked for replay on this instance']
		);
	}//end replayUncheckable()

	/**
	 * The distributed cache that remembers used token ids, or null without one.
	 *
	 * @return ICache|null
	 */
	private function jtiCache(): ?ICache {
		return $this->cacheFactory?->createDistributed('openregister.jti');
	}//end jtiCache()
}//end class
