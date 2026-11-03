<?php

/**
 * CredentialAppTokenService — signed per-app identity for the credential broker.
 *
 * The broker must trust the "calling app id" it checks against a credential's
 * `allowedApps[]`. That id is NEVER read from a request body — it is proven by a
 * short-lived, HMAC-signed token (design.md D5). Each consuming app is registered
 * with a per-app signing secret (generated here, held system-scoped in
 * `ICredentialsManager` under `openregister/credential-app-key/<appId>`; the app
 * keeps its own copy). {@see issueToken()} mints a token binding
 * `{appId, credentialId, iat, exp}` signed with the app's secret; {@see verify()}
 * recomputes the signature against the registered secret for the claimed appId and
 * checks expiry, so a forged or expired token is rejected. Signed tokens are the
 * HTTP / cross-runtime identity mechanism; trusted SAME-INSTANCE PHP callers pass
 * their appId to `CredentialBrokerService::request` directly without a token
 * (credential-doriath-leaf design D-G) — the token authenticates claims across a
 * trust boundary that an in-process call does not cross. Signing secrets are
 * never logged or returned by verify().
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

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;

/**
 * Issues and verifies short-lived signed per-app broker tokens.
 */
class CredentialAppTokenService {
	/**
	 * System-scoped vault key prefix for a per-app signing secret.
	 *
	 * @var string
	 */
	private const KEY_PREFIX = 'openregister/credential-app-key/';

	/**
	 * Token lifetime in seconds (short-lived; a fresh token is minted per broker call).
	 *
	 * @var int
	 */
	private const TOKEN_TTL = 300;

	/**
	 * Length (in characters) of a generated per-app signing secret.
	 *
	 * @var int
	 */
	private const SECRET_LENGTH = 64;

	/**
	 * Constructor.
	 *
	 * @param ICredentialsManager $credentialsManager The NC vault holding per-app signing secrets (system-scoped).
	 * @param ISecureRandom $secureRandom CSPRNG for generating signing secrets.
	 * @param ITimeFactory $timeFactory Clock source for iat/exp (injectable for testability).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ICredentialsManager $credentialsManager,
		private readonly ISecureRandom $secureRandom,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Register (or rotate) an app's signing secret and return it ONCE.
	 *
	 * Generates a fresh CSPRNG secret, stores it system-scoped keyed by appId, and
	 * returns the plaintext to the caller. The secret is returned only here; it is
	 * never retrievable through any read API and never logged.
	 *
	 * ROTATES on every call. Automated callers (the D-G manifest auto-onboarding
	 * in `GenericInitializeSettings`) MUST therefore guard with
	 * {@see isRegistered()} so an auto-run never silently invalidates an app's
	 * held copy — rotation stays an explicit admin action via
	 * `POST /api/credentials/apps/{appId}/register`.
	 *
	 * @param string $appId The consuming app's id.
	 *
	 * @return string The newly generated signing secret (shown once).
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function registerApp(string $appId): string {
		$secret = $this->secureRandom->generate(
			self::SECRET_LENGTH,
			(ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
		);

		$this->credentialsManager->store('', self::KEY_PREFIX . $appId, $secret);

		return $secret;
	}//end registerApp()

	/**
	 * Whether an app currently has a registered signing secret.
	 *
	 * @param string $appId The consuming app's id.
	 *
	 * @return bool True when a signing secret is registered for the app.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function isRegistered(string $appId): bool {
		return ($this->lookupSecret(appId: $appId) !== null);
	}//end isRegistered()

	/**
	 * Issue a short-lived signed token binding an app id to a credential id.
	 *
	 * CONSUMER SEAM — deliberately has no caller inside OpenRegister.
	 *
	 * This is the CLIENT half of the broker's token protocol. OpenRegister is the
	 * VERIFIER, never the issuer: `CredentialController::brokerRequest()` calls
	 * {@see verify()} on the `X-Credential-Token` header presented by the caller.
	 * The ISSUER is the CONSUMING APP, which holds the per-app signing secret handed
	 * to it exactly once by {@see registerApp()} (`POST /api/credentials/apps/{appId}/register`).
	 * ADR-004 Rule 2 states the contract: "Apps present signed, per-app, expiring
	 * tokens." OpenRegister minting a token for itself would be a category error — it
	 * would *assert* the appId instead of *proving* it, which is precisely the control
	 * this token exists to provide.
	 *
	 * Who is meant to call it:
	 *  - Same-instance PHP consumer apps that reach the broker over HTTP, via
	 *    `Server::get(CredentialAppTokenService::class)->issueToken(...)`. (A trusted
	 *    in-process caller does NOT need a token: it passes its appId straight to
	 *    `CredentialBrokerService::request` — the token authenticates claims across a
	 *    trust boundary that an in-process call never crosses.)
	 *  - Cross-runtime consumers (ExApps, external services) re-implement this exact
	 *    HMAC construction in their own language; this method is the reference
	 *    implementation of the token format that {@see verify()} accepts, and the
	 *    issue→verify round-trip tests are what guard that format from drifting.
	 *
	 * @param string $appId The consuming app's id (must be registered).
	 * @param string $credentialId The credential UUID the app intends to use.
	 * @param string|null $method Optional HTTP method to bind the token to a single request.
	 * @param string|null $path Optional request path to bind the token to a single request.
	 *
	 * @return string A `payload.signature` token (base64url parts).
	 *
	 * @throws CredentialAccessDeniedException When the app has no registered signing secret.
	 *
	 * @orphaned-write-capability exclude Consumer-facing seam: OpenRegister is the token VERIFIER
	 *   (CredentialController::brokerRequest -> verify()); the CONSUMING APP is the issuer, holding
	 *   the per-app secret from registerApp(). ADR-004 Rule 2. An in-repo caller would defeat the control.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function issueToken(string $appId, string $credentialId, ?string $method = null, ?string $path = null): string {
		$secret = $this->lookupSecret(appId: $appId);
		if ($secret === null) {
			throw new CredentialAccessDeniedException(
				message: 'App "' . $appId . '" has no registered signing secret'
			);
		}

		$now = $this->timeFactory->getTime();
		$payload = [
			'appId' => $appId,
			'credentialId' => $credentialId,
			'iat' => $now,
			'exp' => ($now + self::TOKEN_TTL),
		];

		// Optional request binding (harden-credential-token-binding): bind the
		// token to a specific method + path so a captured token cannot be replayed
		// against a *different* allow-rule-permitted call within its TTL. Opt-in —
		// tokens minted without method/path stay unbound (backward-compatible).
		if ($method !== null && $path !== null) {
			$payload['req'] = $this->requestDigest(method: $method, path: $path);
		}

		$payloadJson = json_encode($payload);
		if ($payloadJson === false) {
			throw new CredentialAccessDeniedException(message: 'Failed to encode token payload');
		}

		$payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
		$signature = hash_hmac('sha256', $payloadB64, $secret, true);
		$signB64 = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

		return $payloadB64 . '.' . $signB64;
	}//end issueToken()

	/**
	 * Verify a token and return its authenticated `{appId, credentialId}` claims.
	 *
	 * The signature is recomputed against the registered secret for the appId named
	 * IN the payload, so a token cannot be forged without that secret. Expiry is
	 * enforced. Any structural, signature, or expiry failure throws (fail-closed);
	 * the caller maps every failure to a single static 403.
	 *
	 * @param string $token The `payload.signature` token to verify.
	 * @param string|null $method The actual request HTTP method, matched against a request-bound token.
	 * @param string|null $path The actual request path, matched against a request-bound token.
	 *
	 * @return array{appId: string, credentialId: string} The authenticated claims.
	 *
	 * @throws CredentialAccessDeniedException On any malformed / forged / expired token.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function verify(string $token, ?string $method = null, ?string $path = null): array {
		$parts = explode('.', $token);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			throw new CredentialAccessDeniedException(message: 'Malformed broker token');
		}

		[$payloadB64, $signB64] = $parts;
		$payload = $this->decodeClaims(payloadB64: $payloadB64);

		$appId = (string)$payload['appId'];
		$secret = $this->lookupSecret(appId: $appId);
		if ($secret === null) {
			throw new CredentialAccessDeniedException(message: 'Unknown app "' . $appId . '" in broker token');
		}

		$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');
		if (hash_equals($expected, $signB64) === false) {
			throw new CredentialAccessDeniedException(message: 'Bad broker token signature');
		}

		if ((int)$payload['exp'] <= $this->timeFactory->getTime()) {
			throw new CredentialAccessDeniedException(message: 'Expired broker token');
		}

		// Request binding (harden-credential-token-binding): a token bound to a
		// specific method+path is valid ONLY for that call — the actual method and
		// path MUST match, so a captured token cannot be replayed against a
		// different allow-rule-permitted call. Fail-closed: a bound token verified
		// without a method/path is rejected.
		if (isset($payload['req']) === true) {
			$matches = ($method !== null && $path !== null
				&& hash_equals(
					(string)$payload['req'],
					$this->requestDigest(method: $method, path: $path)
				) === true);
			if ($matches === false) {
				throw new CredentialAccessDeniedException(message: 'Broker token request-binding mismatch');
			}
		}

		return [
			'appId' => $appId,
			'credentialId' => (string)$payload['credentialId'],
		];
	}//end verify()

	/**
	 * Compute the request-binding digest for a method + path.
	 *
	 * Normalises the method to upper-case and the path to a single leading slash
	 * so the digest is stable across trivially-different representations of the
	 * same call.
	 *
	 * @param string $method The HTTP method (e.g. `GET`, `PUT`).
	 * @param string $path The provider-relative path.
	 *
	 * @return string A SHA-256 hex digest of the normalised `METHOD /path`.
	 */
	private function requestDigest(string $method, string $path): string {
		return hash('sha256', strtoupper($method) . ' /' . ltrim($path, '/'));
	}//end requestDigest()

	/**
	 * Decode and structurally validate a token's base64url payload segment.
	 *
	 * @param string $payloadB64 The base64url-encoded payload segment.
	 *
	 * @return array{appId: string, credentialId: string, exp: int, req?: string} The validated claims.
	 *
	 * @throws CredentialAccessDeniedException When the payload is unreadable or malformed.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function decodeClaims(string $payloadB64): array {
		$payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'), true);
		if ($payloadJson === false) {
			throw new CredentialAccessDeniedException(message: 'Malformed broker token payload');
		}

		$payload = json_decode($payloadJson, true);
		if (is_array($payload) === false
			|| is_string(($payload['appId'] ?? null)) === false
			|| is_string(($payload['credentialId'] ?? null)) === false
			|| is_int(($payload['exp'] ?? null)) === false
		) {
			throw new CredentialAccessDeniedException(message: 'Malformed broker token claims');
		}

		return $payload;
	}//end decodeClaims()

	/**
	 * Look up an app's registered signing secret, or null when absent.
	 *
	 * @param string $appId The consuming app's id.
	 *
	 * @return string|null The signing secret, or null.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function lookupSecret(string $appId): ?string {
		$value = $this->credentialsManager->retrieve('', self::KEY_PREFIX . $appId);
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end lookupSecret()
}//end class
