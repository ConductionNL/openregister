<?php

/**
 * CredentialAppTokenServiceInterface: what callers outside lib/Service use of CredentialAppTokenService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The part of CredentialAppTokenService that code outside lib/Service uses.
 */
interface CredentialAppTokenServiceInterface {

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
	public function registerApp(string $appId): string;

	/**
	 * Whether an app currently has a registered signing secret.
	 *
	 * @param string $appId The consuming app's id.
	 *
	 * @return bool True when a signing secret is registered for the app.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function isRegistered(string $appId): bool;

	/**
	 * Verify a token and return its authenticated `{appId, credentialId}` claims.
	 *
	 * The signature is recomputed against the registered secret for the appId named
	 * IN the payload, so a token cannot be forged without that secret. Expiry is
	 * enforced. Any structural, signature, or expiry failure throws (fail-closed);
	 * the caller maps every failure to a single static 403.
	 *
	 * @param string             $token   The `payload.signature` token to verify.
	 * @param BrokerTokenBinding $request The actual request's HTTP method and path, matched against a request-bound token.
	 *
	 * @return array{appId: string, credentialId: string} The authenticated claims.
	 *
	 * @throws CredentialAccessDeniedException On any malformed / forged / expired token.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function verify(string $token, BrokerTokenBinding $request = new BrokerTokenBinding()): array;
}//end interface
