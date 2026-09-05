<?php

/**
 * OAuth2ConnectService — builds an authorization URL and redeems the code it returns.
 *
 * The half of the connect flow that talks to a provider. The controller owns the
 * HTTP shape and the authorization decisions; this owns what a provider is asked
 * for, what comes back, and how the answer becomes a credential.
 *
 * Three things here are decisions rather than plumbing.
 *
 * A RE-AUTHORISATION OVERWRITES IN PLACE. Every `socialAccount` and `searchProperty`
 * in a consuming app points at a credential id, so minting a second credential for
 * the same account would leave all of them pointing at the dead one. The provider
 * and, where one is pinned, the instance host are refused if they would change,
 * because that would be re-pointing a credential rather than repairing it.
 *
 * A FAILED EXCHANGE LEAVES NOTHING BEHIND. Nothing is written until the token
 * response is in hand, so a provider refusal produces no half-made connection for
 * somebody to find later and wonder about.
 *
 * Two provider-specific jobs live BESIDE this rather than in it, and both are
 * reached through here so a caller has one connect seam rather than three:
 * {@see OAuth2InstanceClient} registers the OAuth2 application that a provider with
 * no central registry has no other way to get, and {@see OAuth2AccountIdentity} asks
 * the provider whose account was just connected. Each is a whole external exchange
 * with its own failure posture, and folding them in here made one class that did
 * three different things to three different servers.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Builds authorization URLs and turns authorization codes into credentials.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The connect flow is by nature the
 * seam between the catalogue, the broker, the refresh service, an HTTP client and the
 * flow's own state; the count is the number of things a connect genuinely touches,
 * and four of them are the exception types that distinguish a refused grant from an
 * unreachable provider.
 * @SuppressWarnings(PHPMD.StaticAccess) Two calls, to a named constructor
 * ({@see OAuth2TokenSet}) and to a stateless security predicate
 * ({@see OAuth2InstanceHost}); neither has an instance, and injecting the host guard
 * would make the host-lock substitutable.
 */
class OAuth2ConnectService {
	/**
	 * The credential kind this service mints.
	 *
	 * @var string
	 */
	public const KIND = 'oauth2-token-set';

	/**
	 * Constructor.
	 *
	 * @param ProviderCatalogue $catalogue Declares every provider's endpoints and grants.
	 * @param CredentialBrokerService $broker Mints the credential and its metadata object.
	 * @param OAuth2ClientResolver $clients Resolves the client id and secret for the exchange.
	 * @param OAuth2RefreshService $refresh Persists a token set and mirrors its non-secret metadata.
	 * @param OAuth2InstanceClient $instanceClients Registers or reuses a per-instance OAuth2 application.
	 * @param OAuth2AccountIdentity $identities Reads and records whose account was connected.
	 * @param ObjectService $objectService Loads the credential being re-authorised.
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ProviderCatalogue $catalogue,
		private readonly CredentialBrokerService $broker,
		private readonly OAuth2ClientResolver $clients,
		private readonly OAuth2RefreshService $refresh,
		private readonly OAuth2InstanceClient $instanceClients,
		private readonly OAuth2AccountIdentity $identities,
		private readonly ObjectService $objectService,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
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
	 * @return array<string, mixed> The claims, carrying a client id and its credentialRef.
	 *
	 * @throws RuntimeException When the account's server refuses the registration.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
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
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
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
	 * Build the authorization URL a person is sent to.
	 *
	 * The OAuth2 client is resolved HERE rather than by the caller, so a controller
	 * never handles a client secret it has no use for.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param array<string, mixed> $claims The flow's claims (provider, scopes, host, client).
	 * @param string $redirectUri The callback the provider must redirect to.
	 * @param string $state The signed state.
	 * @param string $challenge The PKCE code challenge.
	 *
	 * @return string The absolute authorization URL.
	 *
	 * @throws InvalidArgumentException When the entry names no usable authorization endpoint.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function authorizationUrl(
		array $provider,
		array $claims,
		string $redirectUri,
		string $state,
		string $challenge,
	): string {
		$oauth2 = $provider['oauth2'];
		$instanceBaseUrl = $this->pinnedHost(claims: $claims);
		$scopes = $this->scopesOf(claims: $claims, provider: $provider);
		$client = $this->clients->resolve(
			credential: [
				'clientId' => (string)($claims['cl'] ?? ''),
				'clientCredentialRef' => (string)($claims['cr'] ?? ''),
			],
			provider: (string)($claims['p'] ?? ''),
			actingUserId: (string)($claims['u'] ?? '')
		);
		$clientId = $client['clientId'];
		$separator = (string)($oauth2['scopeSeparator'] ?? ' ');

		$query = [
			'response_type' => 'code',
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'state' => $state,
			'scope' => implode($separator, $scopes),
		];

		if ((string)($oauth2['pkce'] ?? 'none') === 'S256') {
			$query['code_challenge'] = $challenge;
			$query['code_challenge_method'] = 'S256';
		}

		$extra = ($oauth2['authorizationParameters'] ?? []);
		if (is_array($extra) === true) {
			$query = array_merge($query, $extra);
		}

		return $this->endpoint(
			oauth2: $oauth2,
			key: 'authorizationEndpoint',
			instanceBaseUrl: $instanceBaseUrl
		) . '?' . http_build_query($query);
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
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	public function complete(array $claims, string $code, string $verifier, string $redirectUri): string {
		$providerId = (string)($claims['p'] ?? '');
		$provider = $this->oauth2Provider(providerId: $providerId);
		$instanceBaseUrl = $this->pinnedHost(claims: $claims);

		$existing = $this->existingCredential(claims: $claims, providerId: $providerId, instanceBaseUrl: $instanceBaseUrl);

		// The claims win only where they SAY something. A re-authorisation whose
		// claims carry no client is reusing the credential's own, and letting an
		// empty claim overwrite it would strand a per-instance connection: the
		// stored client id would be gone, and its secret would still be sitting on
		// the account's own server with nothing left pointing at it.
		$credentialData = array_merge(
			($existing ?? []),
			array_filter(
				[
					'provider' => $providerId,
					'clientId' => trim((string)($claims['cl'] ?? '')),
					'clientCredentialRef' => trim((string)($claims['cr'] ?? '')),
				],
				static fn (string $value): bool => $value !== ''
			)
		);

		$client = $this->clients->resolve(
			credential: $credentialData,
			provider: $providerId,
			actingUserId: (string)($claims['u'] ?? '')
		);

		$response = $this->exchangeCode(
			provider: $provider,
			code: $code,
			verifier: $verifier,
			redirectUri: $redirectUri,
			client: $client,
			instanceBaseUrl: $instanceBaseUrl
		);

		$tokenSet = OAuth2TokenSet::fromTokenResponse(
			response: $response,
			requestedScopes: $this->scopesOf(claims: $claims, provider: $provider)
		);

		$scope = (string)($claims['s'] ?? 'personal');

		if ($existing !== null) {
			$credentialId = (string)$claims['cid'];
			$this->refresh->persist(credentialId: $credentialId, scope: $scope, set: $tokenSet, extraMetadata: ['status' => 'active']);
			$this->identities->record(
				provider: $provider,
				credentialId: $credentialId,
				scope: $scope,
				set: $tokenSet,
				owner: (string)($existing['owner'] ?? $claims['u'] ?? '')
			);

			return $credentialId;
		}

		$credentialId = $this->mintNew(
			claims: $claims,
			providerId: $providerId,
			scope: $scope,
			tokenSet: $tokenSet,
			instanceBaseUrl: $instanceBaseUrl
		);

		$this->identities->record(
			provider: $provider,
			credentialId: $credentialId,
			scope: $scope,
			set: $tokenSet,
			owner: (string)($claims['u'] ?? '')
		);

		return $credentialId;
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
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
	 */
	public function revokeUpstream(array $provider, array $credential, string $credentialId, string $scope): string {
		$oauth2 = ($provider['oauth2'] ?? []);
		if (is_array($oauth2) === false || ($oauth2['revokeEndpoint'] ?? null) === null) {
			return '';
		}

		try {
			$set = $this->refresh->storedSet(credentialId: $credentialId, scope: $scope);
			$client = $this->clients->resolve(
				credential: $credential,
				provider: (string)($credential['provider'] ?? ''),
				actingUserId: (string)($credential['owner'] ?? '')
			);

			$body = ['token' => $set->getAccessToken(), 'client_id' => $client['clientId']];
			if ($client['clientSecret'] !== null) {
				$body['client_secret'] = $client['clientSecret'];
			}

			$this->clientService->newClient()->post(
				$this->endpoint(
					oauth2: $oauth2,
					key: 'revokeEndpoint',
					instanceBaseUrl: (string)($credential['instanceBaseUrl'] ?? '')
				),
				['body' => $body, 'timeout' => 20]
			);
		} catch (Throwable $failure) {
			$this->logger->warning('[OAuth2ConnectService] upstream revoke failed for ' . $credentialId . ': ' . $failure->getMessage());

			return 'revoke_failed';
		}//end try

		return '';
	}//end revokeUpstream()

	/**
	 * Mint a brand-new connection.
	 *
	 * @param array<string, mixed> $claims The verified state claims.
	 * @param string $providerId The catalogue provider identifier.
	 * @param string $scope The credential scope.
	 * @param OAuth2TokenSet $tokenSet The token set to store.
	 * @param string|null $instanceBaseUrl The pinned host, when the provider has one.
	 *
	 * @return string The new credential UUID.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	private function mintNew(
		array $claims,
		string $providerId,
		string $scope,
		OAuth2TokenSet $tokenSet,
		?string $instanceBaseUrl,
	): string {
		$metadata = [
			'kind' => self::KIND,
			'status' => 'active',
			'scopes' => $tokenSet->getScopes(),
			'account' => $tokenSet->getAccount(),
			'expiresAt' => $tokenSet->getExpiresAt()?->format(DATE_ATOM),
			'clientId' => (string)($claims['cl'] ?? ''),
			'clientCredentialRef' => (string)($claims['cr'] ?? ''),
		];
		if ($instanceBaseUrl !== null) {
			$metadata['instanceBaseUrl'] = $instanceBaseUrl;
		}

		$minted = $this->broker->mint(
			name: (string)($claims['nm'] ?? $providerId),
			provider: $providerId,
			owner: (string)($claims['u'] ?? ''),
			allowedApps: $this->allowedAppsOf(claims: $claims),
			secret: $tokenSet->toStoredJson(),
			scope: $scope,
			organisation: ($claims['o'] ?? null),
			metadata: $metadata
		);

		return (string)$minted->getUuid();
	}//end mintNew()

	/**
	 * Load the credential a re-authorisation targets, refusing one it would re-point.
	 *
	 * @param array<string, mixed> $claims The verified state claims.
	 * @param string $providerId The provider named in the claims.
	 * @param string|null $instanceBaseUrl The host named in the claims.
	 *
	 * @return array<string, mixed>|null The existing credential's data, or null for a new connection.
	 *
	 * @throws RuntimeException When the credential is gone, or the re-authorisation would re-point it.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-token-set/spec.md#requirement-a-re-authorised-credential-returns-to-active-in-place
	 */
	private function existingCredential(array $claims, string $providerId, ?string $instanceBaseUrl): ?array {
		$credentialId = trim((string)($claims['cid'] ?? ''));
		if ($credentialId === '') {
			return null;
		}

		$entity = $this->objectService->find(
			id: $credentialId,
			register: CredentialBrokerService::REGISTER,
			schema: CredentialBrokerService::SCHEMA,
			_rbac: false,
			_multitenancy: false,
			_render: false
		);

		if ($entity instanceof ObjectEntity === false) {
			throw new RuntimeException(message: 'the credential being re-authorised no longer exists');
		}

		$data = $entity->jsonSerialize();
		unset($data['@self']);

		if ((string)($data['provider'] ?? '') !== $providerId) {
			throw new RuntimeException(message: 'a re-authorisation may not change a credential\'s provider');
		}

		$pinned = trim((string)($data['instanceBaseUrl'] ?? ''));
		if ($pinned !== '' && $pinned !== $instanceBaseUrl) {
			throw new RuntimeException(message: 'a re-authorisation may not change a credential\'s pinned host');
		}

		return $data;
	}//end existingCredential()

	/**
	 * Perform the authorization-code exchange.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $code The authorization code.
	 * @param string $verifier The PKCE code verifier.
	 * @param string $redirectUri The callback the code was issued against.
	 * @param array{clientId: string, clientSecret: string|null} $client The resolved client.
	 * @param string|null $instanceBaseUrl The pinned host, when the provider has one.
	 *
	 * @return array<string, mixed> The decoded token response.
	 *
	 * @throws RuntimeException When the exchange fails or the provider returns an error.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	private function exchangeCode(
		array $provider,
		string $code,
		string $verifier,
		string $redirectUri,
		array $client,
		?string $instanceBaseUrl,
	): array {
		$oauth2 = $provider['oauth2'];
		$form = [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $redirectUri,
			'client_id' => $client['clientId'],
		];

		if ((string)($oauth2['pkce'] ?? 'none') === 'S256') {
			$form['code_verifier'] = $verifier;
		}

		$options = ['body' => $form, 'headers' => ['Accept' => 'application/json'], 'timeout' => 20];
		if ($client['clientSecret'] !== null && (string)($oauth2['clientAuth'] ?? '') === 'client_secret_basic') {
			$options['auth'] = [$client['clientId'], $client['clientSecret']];
		}

		if ($client['clientSecret'] !== null && (string)($oauth2['clientAuth'] ?? '') !== 'client_secret_basic') {
			$form['client_secret'] = $client['clientSecret'];
			$options['body'] = $form;
		}

		try {
			$response = $this->clientService->newClient()->post(
				$this->endpoint(oauth2: $oauth2, key: 'tokenEndpoint', instanceBaseUrl: $instanceBaseUrl),
				$options
			);
			$decoded = json_decode((string)$response->getBody(), true);
		} catch (Throwable $failure) {
			throw new RuntimeException(message: 'token exchange failed: ' . $failure::class, previous: $failure);
		}

		if (is_array($decoded) === false) {
			throw new RuntimeException(message: 'token endpoint returned a body that is not JSON');
		}

		$error = (string)($decoded['error'] ?? '');
		if ($error !== '') {
			throw new RuntimeException(message: 'token endpoint returned error ' . $error);
		}

		return $decoded;
	}//end exchangeCode()

	/**
	 * Resolve one of the provider's endpoints to an absolute URL.
	 *
	 * @param array<string, mixed> $oauth2 The provider entry's `oauth2` block.
	 * @param string $key The endpoint key.
	 * @param string|null $instanceBaseUrl The pinned host, for a per-instance provider.
	 *
	 * @return string The absolute endpoint URL.
	 *
	 * @throws InvalidArgumentException When the endpoint is absent or its host cannot be resolved.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-broker/spec.md#requirement-the-catalogue-may-describe-an-oauth2-provider
	 */
	private function endpoint(array $oauth2, string $key, ?string $instanceBaseUrl): string {
		$endpoint = trim((string)($oauth2[$key] ?? ''));
		if ($endpoint === '') {
			throw new InvalidArgumentException(message: 'the catalogue names no ' . $key . ' for this provider');
		}

		if (($oauth2['endpointsRelativeToInstance'] ?? false) !== true) {
			return $endpoint;
		}

		return OAuth2InstanceHost::normalise(candidate: (string)$instanceBaseUrl) . '/' . ltrim($endpoint, '/');
	}//end endpoint()

	/**
	 * The already-validated pinned host a flow carries, or null.
	 *
	 * @param array<string, mixed> $claims The verified state claims.
	 *
	 * @return string|null The normalised origin, or null.
	 *
	 * @spec exclude private claims accessor; the host rules live in OAuth2InstanceHost
	 */
	private function pinnedHost(array $claims): ?string {
		$host = trim((string)($claims['h'] ?? ''));
		if ($host === '') {
			return null;
		}

		return OAuth2InstanceHost::normalise(candidate: $host);
	}//end pinnedHost()

	/**
	 * The scopes a flow asked for, falling back to the provider's declared defaults.
	 *
	 * @param array<string, mixed> $claims The verified state claims.
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 *
	 * @return array<int, string> The scopes.
	 *
	 * @spec exclude private claims accessor with no behaviour of its own
	 */
	private function scopesOf(array $claims, array $provider): array {
		$scopes = ($claims['sc'] ?? null);
		if (is_array($scopes) === true && $scopes !== []) {
			return array_values(array_map('strval', $scopes));
		}

		$defaults = ($provider['oauth2']['defaultScopes'] ?? []);
		if (is_array($defaults) === false) {
			return [];
		}

		return array_values(array_map('strval', $defaults));
	}//end scopesOf()

	/**
	 * The app ids a new connection is granted to.
	 *
	 * @param array<string, mixed> $claims The verified state claims.
	 *
	 * @return array<int, string> The app ids.
	 *
	 * @spec exclude private claims accessor with no behaviour of its own
	 */
	private function allowedAppsOf(array $claims): array {
		$apps = ($claims['a'] ?? []);
		if (is_array($apps) === false) {
			$apps = [];
		}

		$apps = array_map('strval', $apps);

		// OPENREGISTER IS ALWAYS ON THE LIST, and this is not a courtesy to itself.
		// It is the app that reads the connected account's identity right after the
		// mint, and that call goes through request(), whose allowedApps guard would
		// otherwise deny it. Leaving it off would not produce an error a person sees:
		// it would produce a connection that never learns whose account it holds, and
		// a panel that says "not connected yet" beside a live token forever. It buys
		// the app no reach the entry's own allow-rules do not already permit. The
		// daily sweep is unaffected either way — it renews through the custody leaf
		// and the token endpoint directly, never through the proxy.
		if (in_array('openregister', $apps, true) === false) {
			$apps[] = 'openregister';
		}

		return array_values($apps);
	}//end allowedAppsOf()
}//end class
