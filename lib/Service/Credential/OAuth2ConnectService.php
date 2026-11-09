<?php

/**
 * OAuth2ConnectService — builds an authorization URL and redeems the code it returns.
 *
 * The half of the connect flow that talks to a provider. The controller owns the
 * HTTP shape and the authorization decisions; this owns what a provider is asked
 * for, what comes back, and how the answer becomes a credential.
 *
 * A FAILED EXCHANGE LEAVES NOTHING BEHIND. Nothing is written until the token
 * response is in hand, so a provider refusal produces no half-made connection for
 * somebody to find later and wonder about. The re-authorisation refusals run
 * before the exchange, so a repair that would re-point a credential costs the
 * provider nothing either.
 *
 * The jobs behind this seam live BESIDE it, and are reached through here so a
 * caller has one connect seam rather than several:
 * {@see OAuth2InstanceClient} registers the OAuth2 application that a provider with
 * no central registry has no other way to get, {@see OAuth2CodeExchange} redeems the
 * code, {@see OAuth2ConnectionWriter} repairs in place or mints (and owns the rule
 * that a re-authorisation may not re-point a credential), and
 * {@see OAuth2UpstreamRevoker} revokes at the provider on disconnect. Each is a
 * whole step with its own failure posture.
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Builds authorization URLs and turns authorization codes into credentials.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) One call, to a named constructor
 * ({@see OAuth2TokenSet}); a value object has no instance to call it on.
 */
class OAuth2ConnectService implements OAuth2ConnectServiceInterface {
	/**
	 * The credential kind this service mints.
	 *
	 * @var string
	 */
	public const KIND = 'oauth2-token-set';

	/**
	 * Declares every provider's endpoints and grants.
	 *
	 * @var ProviderCatalogue
	 */
	private readonly ProviderCatalogue $catalogue;

	/**
	 * Resolves the client id and secret for the exchange.
	 *
	 * @var OAuth2ClientResolver
	 */
	private readonly OAuth2ClientResolver $clients;

	/**
	 * Registers or reuses a per-instance OAuth2 application.
	 *
	 * @var OAuth2InstanceClient
	 */
	private readonly OAuth2InstanceClient $instanceClients;

	/**
	 * Redeems the authorization code at the token endpoint.
	 *
	 * @var OAuth2CodeExchange
	 */
	private readonly OAuth2CodeExchange $exchange;

	/**
	 * Repairs the targeted credential in place or mints a new one.
	 *
	 * @var OAuth2ConnectionWriter
	 */
	private readonly OAuth2ConnectionWriter $writer;

	/**
	 * Revokes a connection at the provider, best effort.
	 *
	 * @var OAuth2UpstreamRevoker
	 */
	private readonly OAuth2UpstreamRevoker $revoker;

	/**
	 * Constructor.
	 *
	 * @param OAuth2ConnectClients $sources The provider catalogue, client resolver and per-instance client registration.
	 * @param OAuth2ConnectSteps   $steps   The code exchange, connection writer and upstream revoker.
	 *
	 * @return void
	 */
	public function __construct(
		OAuth2ConnectClients $sources,
		OAuth2ConnectSteps $steps,
	) {
		$this->catalogue = $sources->catalogue;
		$this->clients = $sources->clients;
		$this->instanceClients = $sources->instanceClients;
		$this->exchange = $steps->exchange;
		$this->writer = $steps->writer;
		$this->revoker = $steps->revoker;
	}//end __construct()

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
	public function ensureInstanceClient(array $provider, array $claims, string $redirectUri): array {
		return $this->instanceClients->ensure(provider: $provider, claims: $claims, redirectUri: $redirectUri);
	}//end ensureInstanceClient()

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
	public function oauth2Provider(string $providerId): array {
		$provider = $this->catalogue->get($providerId);
		if ($provider === null || (string)($provider['kind'] ?? '') !== self::KIND) {
			throw new InvalidArgumentException(message: 'provider "' . $providerId . '" is not an OAuth2 connection');
		}

		if (is_array(($provider['oauth2'] ?? null)) === false) {
			throw new InvalidArgumentException(message: 'provider "' . $providerId . '" declares no oauth2 block');
		}

		return $provider;
	}//end oauth2Provider()

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
	public function authorizationRequest(string $redirectUri, string $state, string $challenge): OAuth2AuthorizationRequest {
		return new OAuth2AuthorizationRequest(redirectUri: $redirectUri, state: $state, challenge: $challenge);
	}//end authorizationRequest()

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
	public function authorizationUrl(array $provider, array $claims, OAuth2AuthorizationRequest $request): string {
		$flow = new OAuth2FlowClaims(claims: $claims);
		$config = new OAuth2ProviderConfig(provider: $provider);
		$instanceBaseUrl = $flow->pinnedHost();
		$query = array_merge(
			$this->authorizationQuery(flow: $flow, provider: $provider, request: $request),
			$this->pkceChallenge(config: $config, challenge: $request->challenge),
			$config->authorizationParameters()
		);

		return $config->endpoint(key: 'authorizationEndpoint', instanceBaseUrl: $instanceBaseUrl) . '?' . http_build_query($query);
	}//end authorizationUrl()

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
	public function complete(array $claims, string $code, string $verifier, string $redirectUri): string {
		$flow = new OAuth2FlowClaims(claims: $claims);
		$provider = $this->oauth2Provider(providerId: $flow->string(key: 'p'));
		$instanceBaseUrl = $flow->pinnedHost();
		$existing = $this->writer->reauthorisationTarget(flow: $flow, instanceBaseUrl: $instanceBaseUrl);

		$response = $this->exchange->redeem(
			provider: $provider,
			grant: ['code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirectUri],
			client: $this->clientFor(flow: $flow, existing: $existing),
			instanceBaseUrl: $instanceBaseUrl
		);

		$tokenSet = OAuth2TokenSet::fromTokenResponse(response: $response, requestedScopes: $flow->scopes(provider: $provider));

		return $this->writer->store(provider: $provider, flow: $flow, existing: $existing, tokenSet: $tokenSet);
	}//end complete()

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
	public function revokeUpstream(array $provider, array $credential, string $credentialId, string $scope): string {
		return $this->revoker->revoke(provider: $provider, credential: $credential, credentialId: $credentialId, scope: $scope);
	}//end revokeUpstream()

	/**
	 * Resolve the client the exchange authenticates as.
	 *
	 * The claims win only where they SAY something. A re-authorisation whose
	 * claims carry no client is reusing the credential's own, and letting an
	 * empty claim overwrite it would strand a per-instance connection: the
	 * stored client id would be gone, and its secret would still be sitting on
	 * the account's own server with nothing left pointing at it.
	 *
	 * @param OAuth2FlowClaims $flow The verified state claims.
	 * @param array<string, mixed>|null $existing The credential a repair targets, or null.
	 *
	 * @return array{clientId: string, clientSecret: string|null} The resolved client.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
	 */
	private function clientFor(OAuth2FlowClaims $flow, ?array $existing): array {
		$providerId = $flow->string(key: 'p');
		$credentialData = array_merge(
			($existing ?? []),
			array_filter(
				['provider' => $providerId, 'clientId' => $flow->trimmed(key: 'cl'), 'clientCredentialRef' => $flow->trimmed(key: 'cr')],
				static fn (string $value): bool => $value !== ''
			)
		);

		return $this->clients->resolve(credential: $credentialData, provider: $providerId, actingUserId: $flow->string(key: 'u'));
	}//end clientFor()

	/**
	 * The core authorization request parameters, with the client resolved for them.
	 *
	 * @param OAuth2FlowClaims $flow The flow's claims.
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param OAuth2AuthorizationRequest $request The callback and signed state.
	 *
	 * @return array<string, string> The query parameters.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	private function authorizationQuery(OAuth2FlowClaims $flow, array $provider, OAuth2AuthorizationRequest $request): array {
		$scopes = $flow->scopes(provider: $provider);
		$client = $this->clients->resolve(
			credential: ['clientId' => $flow->string(key: 'cl'), 'clientCredentialRef' => $flow->string(key: 'cr')],
			provider: $flow->string(key: 'p'),
			actingUserId: $flow->string(key: 'u')
		);

		return [
			'response_type' => 'code',
			'client_id' => $client['clientId'],
			'redirect_uri' => $request->redirectUri,
			'state' => $request->state,
			'scope' => implode((new OAuth2ProviderConfig(provider: $provider))->scopeSeparator(), $scopes),
		];
	}//end authorizationQuery()

	/**
	 * The PKCE challenge parameters, for a provider that requires them.
	 *
	 * @param OAuth2ProviderConfig $config The provider's oauth2 block.
	 * @param string $challenge The PKCE code challenge.
	 *
	 * @return array<string, string> The challenge parameters, or an empty array.
	 *
	 * @spec exclude private request builder with no behaviour of its own
	 */
	private function pkceChallenge(OAuth2ProviderConfig $config, string $challenge): array {
		if ($config->usesPkce() === false) {
			return [];
		}

		return ['code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
	}//end pkceChallenge()
}//end class
