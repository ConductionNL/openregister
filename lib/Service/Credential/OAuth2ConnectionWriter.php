<?php

/**
 * OAuth2ConnectionWriter — turns a redeemed token set into a credential.
 *
 * The write half of the connect callback, split out of {@see OAuth2ConnectService}.
 * Two decisions live here.
 *
 * A RE-AUTHORISATION OVERWRITES IN PLACE. Every `socialAccount` and `searchProperty`
 * in a consuming app points at a credential id, so minting a second credential for
 * the same account would leave all of them pointing at the dead one. The provider
 * and, where one is pinned, the instance host are refused if they would change,
 * because that would be re-pointing a credential rather than repairing it. That
 * refusal ({@see reauthorisationTarget()}) runs before any exchange.
 *
 * A NEW CONNECTION IS MINTED WITH ITS NON-SECRET METADATA, and either way the
 * account it speaks for is recorded right after.
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
use RuntimeException;

/**
 * Loads the credential a re-authorisation targets, and writes the connect result.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-re-authorised-credential-returns-to-active-in-place
 */
class OAuth2ConnectionWriter {
	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService $broker Mints the credential and its metadata object.
	 * @param OAuth2RefreshService $refresh Persists a token set and mirrors its non-secret metadata.
	 * @param OAuth2AccountIdentity $identities Reads and records whose account was connected.
	 * @param ObjectOperationsInterface $objectService Loads the credential being re-authorised.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CredentialBrokerService $broker,
		private readonly OAuth2RefreshService $refresh,
		private readonly OAuth2AccountIdentity $identities,
		private readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Load the credential a re-authorisation targets, refusing one it would re-point.
	 *
	 * @param OAuth2FlowClaims $flow The verified state claims.
	 * @param string|null $instanceBaseUrl The host named in the claims.
	 *
	 * @return array<string, mixed>|null The existing credential's data, or null for a new connection.
	 *
	 * @throws RuntimeException When the credential is gone, or the re-authorisation would re-point it.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-re-authorised-credential-returns-to-active-in-place
	 */
	public function reauthorisationTarget(OAuth2FlowClaims $flow, ?string $instanceBaseUrl): ?array {
		$credentialId = $flow->trimmed(key: 'cid');
		if ($credentialId === '') {
			return null;
		}

		$data = $this->storedData(credentialId: $credentialId);
		if ((string)($data['provider'] ?? '') !== $flow->string(key: 'p')) {
			throw new RuntimeException(message: 'a re-authorisation may not change a credential\'s provider');
		}

		$this->assertSameHost(data: $data, instanceBaseUrl: $instanceBaseUrl);

		return $data;
	}//end reauthorisationTarget()

	/**
	 * The stored data of the credential a re-authorisation names.
	 *
	 * @param string $credentialId The credential UUID.
	 *
	 * @return array<string, mixed> The property bag, without `@self`.
	 *
	 * @throws RuntimeException When the credential no longer exists.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-re-authorised-credential-returns-to-active-in-place
	 */
	private function storedData(string $credentialId): array {
		$entity = (new OAuth2CredentialObjects(objectService: $this->objectService))->find(credentialId: $credentialId);
		if ($entity === null) {
			throw new RuntimeException(message: 'the credential being re-authorised no longer exists');
		}

		$data = $entity->jsonSerialize();
		unset($data['@self']);

		return $data;
	}//end storedData()

	/**
	 * Refuse a re-authorisation that would move a credential's pinned host.
	 *
	 * @param array<string, mixed> $data The stored property bag.
	 * @param string|null $instanceBaseUrl The host named in the claims.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a pinned host would change.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	private function assertSameHost(array $data, ?string $instanceBaseUrl): void {
		$pinned = trim((string)($data['instanceBaseUrl'] ?? ''));
		if ($pinned !== '' && $pinned !== $instanceBaseUrl) {
			throw new RuntimeException(message: 'a re-authorisation may not change a credential\'s pinned host');
		}
	}//end assertSameHost()

	/**
	 * Store a redeemed token set: in place for a repair, as a new credential otherwise.
	 *
	 * @param RedeemedConnection $connection The provider, flow, repaired credential (or null) and token set.
	 *
	 * @return string The credential UUID.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	public function store(RedeemedConnection $connection): string {
		$flow = $connection->flow;
		$scope = $flow->string(key: 's', default: 'personal');
		$credentialId = $flow->string(key: 'cid');

		if ($connection->existing === null) {
			$credentialId = $this->mintNew(flow: $flow, tokenSet: $connection->tokenSet);
		}

		$address = new TokenSetAddress(credentialId: $credentialId, scope: $scope);
		if ($connection->existing !== null) {
			$this->refresh->persist(address: $address, set: $connection->tokenSet);
		}

		$this->identities->record(connection: $connection, address: $address);

		return $credentialId;
	}//end store()

	/**
	 * Mint a brand-new connection, in the scope the flow names.
	 *
	 * @param OAuth2FlowClaims $flow     The verified state claims.
	 * @param OAuth2TokenSet   $tokenSet The token set to store.
	 *
	 * @return string The new credential UUID.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	private function mintNew(OAuth2FlowClaims $flow, OAuth2TokenSet $tokenSet): string {
		$providerId = $flow->string(key: 'p');
		$minted = $this->broker->mint(
			name: $flow->string(key: 'nm', default: $providerId),
			provider: $providerId,
			owner: $flow->string(key: 'u'),
			allowedApps: $flow->allowedApps(),
			secret: $tokenSet->toStoredJson(),
			scope: $flow->string(key: 's', default: 'personal'),
			organisation: $flow->organisation(),
			metadata: $this->metadata(flow: $flow, tokenSet: $tokenSet)
		);

		return (string)$minted->getUuid();
	}//end mintNew()

	/**
	 * The non-secret metadata a new connection is minted with.
	 *
	 * @param OAuth2FlowClaims $flow The verified state claims.
	 * @param OAuth2TokenSet $tokenSet The token set to store.
	 *
	 * @return array<string, mixed> The metadata.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	private function metadata(OAuth2FlowClaims $flow, OAuth2TokenSet $tokenSet): array {
		$metadata = ['kind' => OAuth2ConnectService::KIND, 'status' => 'active', 'scopes' => $tokenSet->getScopes(), 'account' => $tokenSet->getAccount()];
		$metadata['expiresAt'] = $tokenSet->getExpiresAt()?->format(DATE_ATOM);
		$metadata['clientId'] = $flow->string(key: 'cl');
		$metadata['clientCredentialRef'] = $flow->string(key: 'cr');

		$instanceBaseUrl = $flow->pinnedHost();
		if ($instanceBaseUrl !== null) {
			$metadata['instanceBaseUrl'] = $instanceBaseUrl;
		}

		return $metadata;
	}//end metadata()
}//end class
