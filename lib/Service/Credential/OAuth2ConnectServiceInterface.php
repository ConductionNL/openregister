<?php

/**
 * OAuth2ConnectServiceInterface: what callers outside the component use of OAuth2ConnectService.
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

use InvalidArgumentException;
use RuntimeException;

/**
 * The OAuth2 connect flow the OAuth2 endpoints drive.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface OAuth2ConnectServiceInterface {

	/**
	 * The per-request values an authorization URL is built from.
	 *
	 * @param string $redirectUri The callback the provider must redirect to.
	 * @param string $state The signed state.
	 * @param string $challenge The PKCE code challenge.
	 *
	 * @return OAuth2AuthorizationRequest The request, to hand to {@see authorizationUrl()}.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function authorizationRequest(string $redirectUri, string $state, string $challenge): OAuth2AuthorizationRequest;

	/**
	 * Build the authorization URL a person is sent to.
	 *
	 * The OAuth2 client is resolved HERE rather than by the caller, so a controller
	 * never handles a client secret it has no use for.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param array<string, mixed> $claims The flow's claims (provider, scopes, host, client).
	 * @param OAuth2AuthorizationRequest $request The callback, signed state and PKCE challenge, from {@see authorizationRequest()}.
	 *
	 * @return string The absolute authorization URL.
	 *
	 * @throws InvalidArgumentException When the entry names no usable authorization endpoint.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function authorizationUrl(array $provider, array $claims, OAuth2AuthorizationRequest $request): string;

	/**
	 * Exchange an authorization code and mint or overwrite the credential.
	 *
	 * @param array<string, mixed> $claims The verified state claims.
	 * @param string $code The authorization code the provider returned.
	 * @param string $verifier The PKCE code verifier held since the start.
	 * @param string $redirectUri The callback the code was issued against.
	 *
	 * @return string The credential UUID, which is the credentialRef apps hold.
	 *
	 * @throws InvalidArgumentException When the claims name a provider that cannot be connected.
	 * @throws RuntimeException When the exchange fails or a re-authorisation would re-point a credential.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	public function complete(array $claims, string $code, string $verifier, string $redirectUri): string;

	/**
	 * Make sure a per-instance provider has a client before the person is sent onward.
	 *
	 * Delegated to {@see OAuth2InstanceClient}, which owns the register-at-most-once
	 * rule; it stays on this service's surface so the controller talks to one connect
	 * seam rather than to every collaborator behind it.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param array<string, mixed> $claims The claims assembled so far.
	 * @param string $redirectUri The callback to register.
	 *
	 * @return array<string, mixed> The claims, carrying a client id and its credentialRef, and
	 *                              OAuth2InstanceClient::MINTED_KEY when a new client was registered.
	 *
	 * @throws OAuth2RegistrationFailedException When the account's server refuses the registration.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function ensureInstanceClient(array $provider, array $claims, string $redirectUri): array;

	/**
	 * Resolve a provider entry, refusing anything that is not an OAuth2 token set.
	 *
	 * @param string $providerId The catalogue provider identifier.
	 *
	 * @return array<string, mixed> The provider entry.
	 *
	 * @throws InvalidArgumentException When the provider is unknown or is not an OAuth2 token set.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function oauth2Provider(string $providerId): array;

	/**
	 * Revoke a connection upstream where the provider has a revoke endpoint.
	 *
	 * Best effort by contract: the local disable must happen whether or not the
	 * provider is reachable, so a network failure here is reported, not fatal.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The credential scope.
	 *
	 * @return string An empty string on success, or a secret-free reason the revoke failed.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
	 */
	public function revokeUpstream(array $provider, array $credential, string $credentialId, string $scope): string;
}//end interface
