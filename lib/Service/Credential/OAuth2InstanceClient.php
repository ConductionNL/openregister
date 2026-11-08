<?php

/**
 * OAuth2InstanceClient — the OAuth2 application a per-instance provider has no registry for.
 *
 * Most providers hand out one application per developer and every tenant shares it.
 * Mastodon does not: there is no central registry, so the client has to be created at
 * the ACCOUNT'S OWN SERVER, and it has to exist before the authorization URL is
 * built, because that URL carries its client id. This is therefore a step of the
 * start, not of the callback.
 *
 * It registers AT MOST ONCE PER CONNECTION, which is the part worth stating. A
 * tenant that brought its own application is left alone. A re-authorisation reuses
 * the client already pinned to the credential rather than creating another one:
 * registering on every reconnect would leave a trail of live applications on a
 * person's own server, each holding a client secret that nothing will ever revoke
 * and nobody will ever look at.
 *
 * The issued secret goes straight to the broker as its own credential. Only the
 * non-secret client id reaches the connection object, which is the same division
 * every other credential here observes.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Registers and reuses the OAuth2 application of a per-instance provider.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) One call, to {@see OAuth2InstanceHost}, a
 * stateless security predicate; injecting it would make the host-lock substitutable.
 */
class OAuth2InstanceClient implements OAuth2InstanceClientInterface {
	
	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService $broker Mints the client-secret credential.
	 * @param ObjectOperationsInterface $objectService Reads the client already pinned to a credential.
	 * @param IClientService $clientService NC HTTP client factory.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CredentialBrokerService $broker,
		private readonly ObjectOperationsInterface $objectService,
		private readonly IClientService $clientService,
	) {
	}//end __construct()

	/**
	 * Make sure the flow has a client, registering one only when there is none to reuse.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param array<string, mixed> $claims The claims assembled so far.
	 * @param string $redirectUri The callback to register.
	 *
	 * @return array<string, mixed> The claims, carrying a client id and its credentialRef, and
	 *                              MINTED_KEY when a new client was registered.
	 *
	 * @throws OAuth2RegistrationFailedException When the account's server refuses the registration.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function ensure(array $provider, array $claims, string $redirectUri): array {
		$flow = new OAuth2FlowClaims(claims: $claims);
		if ($this->needsClient(provider: $provider, flow: $flow) === false) {
			return $claims;
		}

		$stored = $this->pinnedClient(credentialId: $flow->trimmed(key: 'cid'));
		if ($stored !== null) {
			return array_merge($claims, $stored);
		}

		return array_merge($claims, $this->registerFor(provider: $provider, flow: $flow, redirectUri: $redirectUri));
	}//end ensure()

	/**
	 * Register an application at an account's own server.
	 *
	 * The account's host, the scopes, and the owner, credential scope and
	 * organisation the client secret is minted for all come from the flow.
	 *
	 * @param array<string, mixed> $provider    The catalogue provider entry.
	 * @param OAuth2FlowClaims     $flow        The claims assembled so far.
	 * @param string               $redirectUri The callback to register.
	 *
	 * @return array{clientId: string, clientCredentialRef: string} The client id and the secret's credentialRef.
	 *
	 * @throws OAuth2RegistrationFailedException When the server refuses the registration.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function register(array $provider, OAuth2FlowClaims $flow, string $redirectUri): array {
		$instanceBaseUrl = OAuth2InstanceHost::normalise(candidate: $flow->string(key: 'h'));
		$decoded = $this->registeredClient(
			provider: $provider, instanceBaseUrl: $instanceBaseUrl, redirectUri: $redirectUri, scopes: $flow->scopes(provider: $provider)
		);
		$clientRef = $this->mintClientSecret(instanceBaseUrl: $instanceBaseUrl, decoded: $decoded, flow: $flow);

		return ['clientId' => (string)$decoded['client_id'], 'clientCredentialRef' => $clientRef];
	}//end register()

	/**
	 * Store an issued client secret as its own brokered credential.
	 *
	 * It is minted for the flow's user, in the flow's credential scope (personal
	 * when the flow names none) and, for an organisation-scoped connection, its
	 * owning organisation.
	 *
	 * @param string               $instanceBaseUrl The pinned, already-validated host.
	 * @param array<string, mixed> $decoded         The registration answer, carrying the client secret.
	 * @param OAuth2FlowClaims     $flow            The claims assembled so far.
	 *
	 * @return string The client-secret credential's UUID.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	private function mintClientSecret(
		string $instanceBaseUrl,
		#[\SensitiveParameter] array $decoded,
		OAuth2FlowClaims $flow,
	): string {
		$minted = $this->broker->mint(
			name: 'OAuth2 client for ' . $instanceBaseUrl, provider: 'generic-oauth2', owner: $flow->string(key: 'u'), allowedApps: ['openregister'],
			secret: (string)($decoded['client_secret'] ?? ''), scope: $flow->string(key: 's', default: 'personal'), organisation: $flow->organisation()
		);

		return (string)$minted->getUuid();
	}//end mintClientSecret()

	/**
	 * Whether the flow still needs a client registered or reused.
	 *
	 * Only a provider with a registration endpoint registers, and a tenant that
	 * brought its own application is left alone.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param OAuth2FlowClaims $flow The claims assembled so far.
	 *
	 * @return boolean True when there is no client yet and the provider registers one.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	private function needsClient(array $provider, OAuth2FlowClaims $flow): bool {
		$oauth2 = ($provider['oauth2'] ?? []);
		if (is_array($oauth2) === false || trim((string)($oauth2['registrationEndpoint'] ?? '')) === '') {
			return false;
		}

		return $flow->trimmed(key: 'cl') === '' && $flow->trimmed(key: 'cr') === '';
	}//end needsClient()

	/**
	 * Register a new client for the flow and name it in the claims.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param OAuth2FlowClaims $flow The claims assembled so far.
	 * @param string $redirectUri The callback to register.
	 *
	 * @return array<string, string> The claims to merge: client id, credentialRef and MINTED_KEY.
	 *
	 * @throws OAuth2RegistrationFailedException When the account's server refuses the registration.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	private function registerFor(array $provider, OAuth2FlowClaims $flow, string $redirectUri): array {
		$registered = $this->register(provider: $provider, flow: $flow, redirectUri: $redirectUri);
		$clientRef = $registered['clientCredentialRef'];

		return ['cl' => $registered['clientId'], 'cr' => $clientRef, self::MINTED_KEY => $clientRef];
	}//end registerFor()

	/**
	 * The registration request options.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $redirectUri The callback to register.
	 * @param array<int, string> $scopes The scopes to register for.
	 *
	 * @return array<string, mixed> The HTTP client options.
	 *
	 * @spec exclude private request builder with no behaviour of its own
	 */
	private function registrationRequest(array $provider, string $redirectUri, array $scopes): array {
		return [
			'body' => [
				'client_name' => 'Nextcloud OpenRegister',
				'redirect_uris' => $redirectUri,
				'scopes' => implode((string)($provider['oauth2']['scopeSeparator'] ?? ' '), $scopes),
			],
			'headers' => ['Accept' => 'application/json'],
			'timeout' => 20,
		];
	}//end registrationRequest()

	/**
	 * Post the registration and insist on a client id in the answer.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $instanceBaseUrl The pinned, already-validated host.
	 * @param string $redirectUri The callback to register.
	 * @param array<int, string> $scopes The scopes to register for.
	 *
	 * @return array<string, mixed> The decoded answer, carrying a string `client_id`.
	 *
	 * @throws OAuth2RegistrationFailedException When the server is unreachable or issues no client id.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	private function registeredClient(array $provider, string $instanceBaseUrl, string $redirectUri, array $scopes): array {
		$endpoint = $instanceBaseUrl . '/' . ltrim((string)$provider['oauth2']['registrationEndpoint'], '/');
		$options = $this->registrationRequest(provider: $provider, redirectUri: $redirectUri, scopes: $scopes);

		try {
			$response = $this->clientService->newClient()->post($endpoint, $options);
			$decoded = json_decode((string)$response->getBody(), true);
		} catch (Throwable $failure) {
			// The class name and nothing else: a registration failure can quote the
			// server's own words, and those words can contain the request that was made.
			throw new OAuth2RegistrationFailedException(message: 'application registration failed: ' . $failure::class, previous: $failure);
		}

		if (is_array($decoded) === false || is_string(($decoded['client_id'] ?? null)) === false) {
			throw new OAuth2RegistrationFailedException(message: 'application registration returned no client id');
		}

		return $decoded;
	}//end registeredClient()

	/**
	 * The client a credential already carries, when a re-authorisation names one.
	 *
	 * @param string $credentialId The credential being re-authorised, or an empty string.
	 *
	 * @return array{cl: string, cr: string}|null The stored client, or null when there is none.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	private function pinnedClient(string $credentialId): ?array {
		$data = $this->storedCredential(credentialId: $credentialId);
		$clientId = trim((string)($data['clientId'] ?? ''));
		if ($clientId === '') {
			return null;
		}

		return ['cl' => $clientId, 'cr' => trim((string)($data['clientCredentialRef'] ?? ''))];
	}//end pinnedClient()

	/**
	 * The stored data of the credential being re-authorised, or nothing.
	 *
	 * A credential that is gone, or a lookup that fails, means there is no client
	 * to reuse; the flow registers a fresh one rather than failing the connect.
	 *
	 * @param string $credentialId The credential being re-authorised, or an empty string.
	 *
	 * @return array<string, mixed> The credential's data, or an empty array.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	private function storedCredential(string $credentialId): array {
		if ($credentialId === '') {
			return [];
		}

		try {
			$entity = (new OAuth2CredentialObjects(objectService: $this->objectService))->find(credentialId: $credentialId);
		} catch (Throwable $missing) {
			return [];
		}

		return ($entity?->jsonSerialize() ?? []);
	}//end storedCredential()
}//end class
