<?php

/**
 * OAuth2UpstreamRevoker — revokes a connection at the provider, best effort.
 *
 * Split out of {@see OAuth2ConnectService}, which still exposes it as
 * revokeUpstream() so the controller keeps one connect seam. Best effort by
 * contract: the local disable must happen whether or not the provider is
 * reachable, so a failure here is reported as a reason, never thrown.
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

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revokes a connection's access token upstream.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
 */
class OAuth2UpstreamRevoker {
	/**
	 * Constructor.
	 *
	 * @param OAuth2RefreshService $refresh Reads the stored token set.
	 * @param OAuth2ClientResolver $clients Resolves the client id and secret.
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OAuth2RefreshService $refresh,
		private readonly OAuth2ClientResolver $clients,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Revoke a connection upstream where the provider has a revoke endpoint.
	 *
	 * Best effort by contract: the local disable must happen whether or not the
	 * provider is reachable, so a network failure here is reported, not fatal.
	 *
	 * @param OAuth2Revocation $revocation The provider, the credential and where its token set is held.
	 *
	 * @return string An empty string on success, or a secret-free reason the revoke failed.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
	 */
	public function revoke(OAuth2Revocation $revocation): string {
		$config = new OAuth2ProviderConfig(provider: $revocation->provider);
		if ($config->declares(key: 'revokeEndpoint') === false) {
			return '';
		}

		try {
			$body = $this->body(revocation: $revocation);
			$endpoint = $config->endpoint(key: 'revokeEndpoint', instanceBaseUrl: (string)($revocation->credential['instanceBaseUrl'] ?? ''));
			$this->clientService->newClient()->post($endpoint, ['body' => $body, 'timeout' => 20]);
		} catch (Throwable $failure) {
			$this->logger->warning('[OAuth2ConnectService] upstream revoke failed for ' . $revocation->address->credentialId . ': ' . $failure->getMessage());
			return 'revoke_failed';
		}

		return '';
	}//end revoke()

	/**
	 * The revoke request body: the access token and the client.
	 *
	 * @param OAuth2Revocation $revocation The credential and where its token set is held.
	 *
	 * @return array<string, string> The form body.
	 *
	 * @spec exclude private request builder with no behaviour of its own
	 */
	private function body(OAuth2Revocation $revocation): array {
		$credential = $revocation->credential;
		$set = $this->refresh->storedSet(credentialId: $revocation->address->credentialId, scope: $revocation->address->scope);
		$client = $this->clients->resolve(
			credential: $credential,
			provider: (string)($credential['provider'] ?? ''),
			actingUserId: (string)($credential['owner'] ?? '')
		);

		$body = ['token' => $set->getAccessToken(), 'client_id' => $client['clientId']];
		if ($client['clientSecret'] !== null) {
			$body['client_secret'] = $client['clientSecret'];
		}

		return $body;
	}//end body()
}//end class
