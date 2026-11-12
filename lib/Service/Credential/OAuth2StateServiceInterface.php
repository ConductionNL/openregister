<?php

/**
 * OAuth2StateServiceInterface: what callers outside the component use of OAuth2StateService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * The OAuth2 state the OAuth2 endpoints issue, read, consume and withdraw.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface OAuth2StateServiceInterface {

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
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
	 */
	public function consume(string $state): ?array;

	/**
	 * Start a flow: generate a nonce and a verifier, store them, and sign a state.
	 *
	 * @param array<string, mixed> $claims The flow's claims (user, provider, scope, callback, return URL, credential id).
	 *
	 * @return array{state: string, nonce: string, verifier: string, challenge: string} The signed state and its PKCE pair.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
	 */
	public function issue(array $claims): array;

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
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	public function parseUnverified(string $state): ?array;

	/**
	 * Withdraw a flow that will never be redeemed: delete its pending record.
	 *
	 * For a start that failed after issue(): its state never reached the person,
	 * so no callback will consume the record, and nothing else would remove it.
	 *
	 * @param string $nonce The nonce issue() returned.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
	 */
	public function withdraw(string $nonce): void;
}//end interface
