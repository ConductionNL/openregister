<?php

/**
 * OAuth2StateService — issues and redeems the `state` that binds one connect flow.
 *
 * A `state` value is the only thing travelling with the user through the provider
 * and back, so it has to carry enough to identify the flow and be impossible to
 * forge. It is `base64url(payload) . "." . base64url(hmac)`, signed with the
 * instance's own secret through {@see ICrypto::calculateHMAC()}. No new key is
 * created for it: an instance already has a secret, and a second one would be a
 * second thing to rotate, back up and lose.
 *
 * THE CODE VERIFIER NEVER TRAVELS. It is held here, server-side, against the
 * state's nonce, and that is what makes the relay safe to be dumb: forwarding an
 * authorization code to a host that has no verifier gets that host nothing.
 *
 * The pending record lives in Nextcloud's encrypted credential vault rather than in
 * a cache, for two reasons. It survives a cache flush, which would otherwise fail a
 * user's connect silently halfway through; and it is encrypted at rest, which a
 * short-lived single-use secret deserves even though it is worthless without the
 * matching authorization code. CONSUMING IT DELETES IT, which is both the replay
 * defence and the verifier cleanup, in one operation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCP\Security\ICredentialsManager;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * Signs, stores and redeems the state of an in-flight OAuth2 connect.
 */
class OAuth2StateService {
	/**
	 * Vault key prefix for a pending connect flow.
	 *
	 * @var string
	 */
	private const PENDING_PREFIX = 'openregister/oauth2-pending/';

	/**
	 * The reserved Nextcloud system-credential identity (empty-string user).
	 *
	 * A pending flow is not owned by the user in the way a credential is: the
	 * callback that redeems it arrives as a PUBLIC request with no session at all,
	 * so a per-user vault entry could not be read at the moment it is needed.
	 *
	 * @var string
	 */
	private const SYSTEM_IDENTITY = '';

	/**
	 * How long a start stays redeemable, in seconds.
	 *
	 * Ten minutes: long enough for a person to read a provider's consent screen and
	 * log in first, short enough that an abandoned flow is not a standing invitation.
	 *
	 * @var integer
	 */
	public const STATE_TTL_SECONDS = 600;

	/**
	 * Constructor.
	 *
	 * @param ICrypto $crypto Signs and verifies the state with the instance secret.
	 * @param ICredentialsManager $vault Holds the pending flow, encrypted at rest.
	 * @param ISecureRandom $random Generates the nonce and the PKCE verifier.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ICrypto $crypto,
		private readonly ICredentialsManager $vault,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Start a flow: generate a nonce and a verifier, store them, and sign a state.
	 *
	 * @param array<string, mixed> $claims The flow's claims (user, provider, scope, callback, return URL, credential id).
	 *
	 * @return array{state: string, nonce: string, verifier: string, challenge: string} The signed state and its PKCE pair.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
	 */
	public function issue(array $claims): array {
		$nonce = $this->random->generate(43, ISecureRandom::CHAR_ALPHANUMERIC);
		$verifier = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);

		$payload = array_merge($claims, ['v' => 1, 'n' => $nonce, 'exp' => (time() + self::STATE_TTL_SECONDS)]);
		$encoded = $this->base64UrlEncode(value: (string)json_encode($payload));
		$signature = $this->base64UrlEncode(value: $this->crypto->calculateHMAC($encoded));

		$this->vault->store(
			self::SYSTEM_IDENTITY,
			self::PENDING_PREFIX . $nonce,
			(string)json_encode(['verifier' => $verifier, 'exp' => $payload['exp']])
		);

		return [
			'state' => $encoded . '.' . $signature,
			'nonce' => $nonce,
			'verifier' => $verifier,
			'challenge' => $this->challengeFor(verifier: $verifier),
		];
	}//end issue()

	/**
	 * Read a state's claims WITHOUT verifying its signature.
	 *
	 * For the RELAY only, which cannot verify a signature it does not hold the key
	 * for. The relay uses this to learn where to forward, and forwards only to a
	 * host on its own allow-list; the receiving instance then verifies the signature
	 * properly, so a forged state cannot mint anything anywhere. Never use this on a
	 * path that acts on the claims.
	 *
	 * @param string $state The state value from the provider's redirect.
	 *
	 * @return array<string, mixed>|null The claims, or null when the value is not a state at all.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	public function parseUnverified(string $state): ?array {
		$parts = explode('.', $state);
		if (count($parts) !== 2) {
			return null;
		}

		$decoded = json_decode($this->base64UrlDecode(value: $parts[0]), true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end parseUnverified()

	/**
	 * Redeem a state: verify its signature and expiry, then consume it.
	 *
	 * Consuming DELETES the pending record before anything is exchanged, so a state
	 * presented twice fails the second time even if the first attempt is still in
	 * flight.
	 *
	 * @param string $state The state value from the provider's redirect.
	 *
	 * @return array{claims: array<string, mixed>, verifier: string}|null The claims and verifier, or null when invalid.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
	 */
	public function consume(string $state): ?array {
		$claims = $this->verifiedClaims(state: $state);
		if ($claims === null) {
			return null;
		}

		$nonce = (string)($claims['n'] ?? '');
		if ($nonce === '') {
			return null;
		}

		$pending = $this->vault->retrieve(self::SYSTEM_IDENTITY, self::PENDING_PREFIX . $nonce);
		if (is_string($pending) === false || $pending === '') {
			return null;
		}

		// Delete BEFORE returning: this is the single-use guarantee, and doing it
		// here rather than after the exchange means a slow or failing exchange still
		// cannot be retried with the same state.
		$this->vault->delete(self::SYSTEM_IDENTITY, self::PENDING_PREFIX . $nonce);

		$record = json_decode($pending, true);
		if (is_array($record) === false || is_string(($record['verifier'] ?? null)) === false) {
			return null;
		}

		return ['claims' => $claims, 'verifier' => (string)$record['verifier']];
	}//end consume()

	/**
	 * Verify a state's signature and expiry and return its claims.
	 *
	 * The signature is compared with `hash_equals`, so a near-miss takes the same time
	 * as a wild guess: a timing difference here would leak the signature one byte at a
	 * time, which is the whole attack against a naive string comparison.
	 *
	 * @param string $state The state value from the provider's redirect.
	 *
	 * @return array<string, mixed>|null The claims, or null when the state is forged or stale.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
	 */
	private function verifiedClaims(string $state): ?array {
		$parts = explode('.', $state);
		if (count($parts) !== 2) {
			return null;
		}

		$expectedSignature = $this->base64UrlEncode(value: $this->crypto->calculateHMAC($parts[0]));
		if (hash_equals($expectedSignature, $parts[1]) === false) {
			return null;
		}

		$claims = json_decode($this->base64UrlDecode(value: $parts[0]), true);
		if (is_array($claims) === false || (int)($claims['exp'] ?? 0) < time()) {
			return null;
		}

		return $claims;
	}//end verifiedClaims()

	/**
	 * Derive the S256 code challenge for a verifier.
	 *
	 * @param string $verifier The PKCE code verifier.
	 *
	 * @return string The base64url-encoded SHA-256 challenge.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
	 */
	public function challengeFor(string $verifier): string {
		return $this->base64UrlEncode(value: hash('sha256', $verifier, true));
	}//end challengeFor()

	/**
	 * Base64url-encode a value (no padding).
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The encoded value.
	 *
	 * @spec exclude private encoding helper with no behaviour of its own
	 */
	private function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}//end base64UrlEncode()

	/**
	 * Base64url-decode a value.
	 *
	 * @param string $value The encoded value.
	 *
	 * @return string The raw value, or an empty string when it does not decode.
	 *
	 * @spec exclude private encoding helper with no behaviour of its own
	 */
	private function base64UrlDecode(string $value): string {
		$decoded = base64_decode(strtr($value, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}//end base64UrlDecode()
}//end class
