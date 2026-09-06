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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use RuntimeException;
use Throwable;

/**
 * Registers and reuses the OAuth2 application of a per-instance provider.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) One call, to {@see OAuth2InstanceHost}, a
 * stateless security predicate; injecting it would make the host-lock substitutable.
 */
class OAuth2InstanceClient {
	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService $broker Mints the client-secret credential.
	 * @param ObjectService $objectService Reads the client already pinned to a credential.
	 * @param IClientService $clientService NC HTTP client factory.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CredentialBrokerService $broker,
		private readonly ObjectService $objectService,
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
	 * @return array<string, mixed> The claims, carrying a client id and its credentialRef.
	 *
	 * @throws RuntimeException When the account's server refuses the registration.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function ensure(array $provider, array $claims, string $redirectUri): array {
		$oauth2 = ($provider['oauth2'] ?? []);
		if (is_array($oauth2) === false || trim((string)($oauth2['registrationEndpoint'] ?? '')) === '') {
			return $claims;
		}

		if (trim((string)($claims['cl'] ?? '')) !== '' || trim((string)($claims['cr'] ?? '')) !== '') {
			return $claims;
		}

		$stored = $this->pinnedClient(credentialId: trim((string)($claims['cid'] ?? '')));
		if ($stored !== null) {
			return array_merge($claims, $stored);
		}

		$registered = $this->register(
			provider: $provider,
			instanceBaseUrl: OAuth2InstanceHost::normalise(candidate: (string)($claims['h'] ?? '')),
			redirectUri: $redirectUri,
			scopes: $this->scopesOf(claims: $claims, provider: $provider),
			owner: (string)($claims['u'] ?? ''),
			scope: (string)($claims['s'] ?? 'personal'),
			organisation: ($claims['o'] ?? null)
		);

		return array_merge($claims, ['cl' => $registered['clientId'], 'cr' => $registered['clientCredentialRef']]);
	}//end ensure()

	/**
	 * Register an application at an account's own server.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $instanceBaseUrl The pinned, already-validated host.
	 * @param string $redirectUri The callback to register.
	 * @param array<int, string> $scopes The scopes to register for.
	 * @param string $owner The user the client-secret credential is minted for.
	 * @param string $scope The credential scope for the client-secret credential.
	 * @param string|null $organisation The owning organisation, for an organisation-scoped connection.
	 *
	 * @return array{clientId: string, clientCredentialRef: string} The client id and the secret's credentialRef.
	 *
	 * @throws RuntimeException When the server refuses the registration.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function register(
		array $provider,
		string $instanceBaseUrl,
		string $redirectUri,
		array $scopes,
		string $owner,
		string $scope,
		?string $organisation = null,
	): array {
		$endpoint = $instanceBaseUrl . '/' . ltrim((string)$provider['oauth2']['registrationEndpoint'], '/');

		try {
			$response = $this->clientService->newClient()->post(
				$endpoint,
				[
					'body' => [
						'client_name' => 'Nextcloud OpenRegister',
						'redirect_uris' => $redirectUri,
						'scopes' => implode((string)($provider['oauth2']['scopeSeparator'] ?? ' '), $scopes),
					],
					'headers' => ['Accept' => 'application/json'],
					'timeout' => 20,
				]
			);
			$decoded = json_decode((string)$response->getBody(), true);
		} catch (Throwable $failure) {
			// The class name and nothing else: a registration failure can quote the
			// server's own words, and those words can contain the request that was made.
			throw new RuntimeException(message: 'application registration failed: ' . $failure::class, previous: $failure);
		}

		if (is_array($decoded) === false || is_string(($decoded['client_id'] ?? null)) === false) {
			throw new RuntimeException(message: 'application registration returned no client id');
		}

		$minted = $this->broker->mint(
			name: 'OAuth2 client for ' . $instanceBaseUrl,
			provider: 'generic-oauth2',
			owner: $owner,
			allowedApps: ['openregister'],
			secret: (string)($decoded['client_secret'] ?? ''),
			scope: $scope,
			organisation: $organisation
		);

		return ['clientId' => (string)$decoded['client_id'], 'clientCredentialRef' => (string)$minted->getUuid()];
	}//end register()

	/**
	 * The client a credential already carries, when a re-authorisation names one.
	 *
	 * @param string $credentialId The credential being re-authorised, or an empty string.
	 *
	 * @return array{cl: string, cr: string}|null The stored client, or null when there is none.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	private function pinnedClient(string $credentialId): ?array {
		if ($credentialId === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(
				id: $credentialId,
				register: CredentialBrokerService::REGISTER,
				schema: CredentialBrokerService::SCHEMA,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Throwable $missing) {
			return null;
		}

		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		$data = $entity->jsonSerialize();
		$clientId = trim((string)($data['clientId'] ?? ''));
		if ($clientId === '') {
			return null;
		}

		return ['cl' => $clientId, 'cr' => trim((string)($data['clientCredentialRef'] ?? ''))];
	}//end pinnedClient()

	/**
	 * The scopes a flow asked for, falling back to the provider's declared defaults.
	 *
	 * @param array<string, mixed> $claims The flow's claims.
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
}//end class
