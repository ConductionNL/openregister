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
 * checks expiry, so a forged or expired token is rejected. The same mechanism
 * authenticates in-process AND cross-runtime callers identically — there is no
 * HTTP-only gap. Signing secrets are never logged or returned by verify().
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
class CredentialAppTokenService
{
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
     * @param ISecureRandom       $secureRandom       CSPRNG for generating signing secrets.
     * @param ITimeFactory        $timeFactory        Clock source for iat/exp (injectable for testability).
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
     * @param string $appId The consuming app's id.
     *
     * @return string The newly generated signing secret (shown once).
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#app-manifest-declares-provider-usage
     */
    public function registerApp(string $appId): string
    {
        $secret = $this->secureRandom->generate(
            self::SECRET_LENGTH,
            (ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS)
        );

        $this->credentialsManager->store('', self::KEY_PREFIX.$appId, $secret);

        return $secret;
    }//end registerApp()

    /**
     * Whether an app currently has a registered signing secret.
     *
     * @param string $appId The consuming app's id.
     *
     * @return bool True when a signing secret is registered for the app.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#app-manifest-declares-provider-usage
     */
    public function isRegistered(string $appId): bool
    {
        return ($this->lookupSecret(appId: $appId) !== null);
    }//end isRegistered()

    /**
     * Issue a short-lived signed token binding an app id to a credential id.
     *
     * @param string $appId        The consuming app's id (must be registered).
     * @param string $credentialId The credential UUID the app intends to use.
     *
     * @return string A `payload.signature` token (base64url parts).
     *
     * @throws CredentialAccessDeniedException When the app has no registered signing secret.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#app-manifest-declares-provider-usage
     */
    public function issueToken(string $appId, string $credentialId): string
    {
        $secret = $this->lookupSecret(appId: $appId);
        if ($secret === null) {
            throw new CredentialAccessDeniedException(
                message: 'App "'.$appId.'" has no registered signing secret'
            );
        }

        $now     = $this->timeFactory->getTime();
        $payload = [
            'appId'        => $appId,
            'credentialId' => $credentialId,
            'iat'          => $now,
            'exp'          => ($now + self::TOKEN_TTL),
        ];

        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            throw new CredentialAccessDeniedException(message: 'Failed to encode token payload');
        }

        $payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $signature  = hash_hmac('sha256', $payloadB64, $secret, true);
        $signB64    = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $payloadB64.'.'.$signB64;
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
     *
     * @return array{appId: string, credentialId: string} The authenticated claims.
     *
     * @throws CredentialAccessDeniedException On any malformed / forged / expired token.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#app-manifest-declares-provider-usage
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new CredentialAccessDeniedException(message: 'Malformed broker token');
        }

        [$payloadB64, $signB64] = $parts;
        $payload = $this->decodeClaims(payloadB64: $payloadB64);

        $appId  = (string) $payload['appId'];
        $secret = $this->lookupSecret(appId: $appId);
        if ($secret === null) {
            throw new CredentialAccessDeniedException(message: 'Unknown app "'.$appId.'" in broker token');
        }

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');
        if (hash_equals($expected, $signB64) === false) {
            throw new CredentialAccessDeniedException(message: 'Bad broker token signature');
        }

        if ((int) $payload['exp'] <= $this->timeFactory->getTime()) {
            throw new CredentialAccessDeniedException(message: 'Expired broker token');
        }

        return [
            'appId'        => $appId,
            'credentialId' => (string) $payload['credentialId'],
        ];
    }//end verify()

    /**
     * Decode and structurally validate a token's base64url payload segment.
     *
     * @param string $payloadB64 The base64url-encoded payload segment.
     *
     * @return array{appId: string, credentialId: string, exp: int} The validated claims.
     *
     * @throws CredentialAccessDeniedException When the payload is unreadable or malformed.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#app-manifest-declares-provider-usage
     */
    private function decodeClaims(string $payloadB64): array
    {
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
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#app-manifest-declares-provider-usage
     */
    private function lookupSecret(string $appId): ?string
    {
        $value = $this->credentialsManager->retrieve('', self::KEY_PREFIX.$appId);
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end lookupSecret()
}//end class
