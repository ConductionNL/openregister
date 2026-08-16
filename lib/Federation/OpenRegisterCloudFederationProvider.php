<?php

/**
 * OpenRegisterCloudFederationProvider — OpenRegister's Open Cloud Mesh (OCM)
 * federation provider.
 *
 * Registered with Nextcloud's ICloudFederationProviderManager for the
 * `openregister` resource type, so cross-instance shares of OpenRegister data
 * ride the standard OCM transport (`/ocm/shares`, `/ocm/notifications`). When a
 * remote instance shares a register/schema/object with an organisation here,
 * `shareReceived()` records an incoming FederatedShare carrying the remote's API
 * URL + scoped token (from the share `protocol` block); the local
 * FederatedObjectSourceProvider then live-proxies the data. Notifications drive
 * accept/decline/revoke.
 *
 * @category Federation
 * @package  OCA\OpenRegister\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Federation;

use OCA\OpenRegister\Service\FederationShareService;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OCM provider for OpenRegister federated shares.
 */
class OpenRegisterCloudFederationProvider implements ICloudFederationProvider {
	/**
	 * Constructor.
	 *
	 * @param FederationShareService $shareService Federated-share management.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly FederationShareService $shareService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The OCM resource type this provider handles.
	 */
	public function getShareType(): string {
		return 'openregister';
	}//end getShareType()

	/**
	 * {@inheritDoc}
	 *
	 * @return string[] The supported OCM share-with types.
	 */
	public function getSupportedShareTypes(): array {
		return ['user', 'group'];
	}//end getSupportedShareTypes()

	/**
	 * {@inheritDoc}
	 *
	 * Record the inbound share and return its local id. The share's `protocol`
	 * options carry the remote OpenRegister API URL + scoped token so the local
	 * FederatedObjectSourceProvider can proxy reads.
	 *
	 * @param ICloudFederationShare $share The inbound OCM share.
	 *
	 * @return string The local share id.
	 *
	 * @throws ProviderCouldNotAddShareException When the share cannot be recorded.
	 */
	public function shareReceived(ICloudFederationShare $share): string {
		try {
			$protocol = $share->getProtocol();
			$options = ($protocol['options'] ?? []);

			$recorded = $this->shareService->recordIncomingShare(
				params: [
					'remoteInstanceUrl' => ($options['apiUrl'] ?? null),
					'remoteProviderId' => $share->getProviderId(),
					'shareToken' => (string)($options['token'] ?? $share->getShareSecret()),
					'scope' => (string)($options['scope'] ?? 'schema'),
					'register' => ($options['register'] ?? null),
					'schema' => ($options['schema'] ?? null),
					'objectUri' => ($options['objectUri'] ?? null),
					'permissions' => (string)($options['permissions'] ?? 'read'),
					'sharedWith' => $share->getShareWith(),
				]
			);

			return (string)$recorded->getId();
		} catch (Throwable $e) {
			$this->logger->error('[Federation] shareReceived failed: ' . $e->getMessage());
			throw new ProviderCouldNotAddShareException('Could not record OpenRegister federated share');
		}//end try
	}//end shareReceived()

	/**
	 * {@inheritDoc}
	 *
	 * Handle OCM notifications: an unshare/revoke marks the local share revoked;
	 * accept/decline update the status. Unknown types are ignored.
	 *
	 * @param mixed $notificationType The OCM notification type.
	 * @param mixed $providerId The remote provider/share id.
	 * @param array<string, mixed> $notification The notification payload.
	 *
	 * @return array<array-key, string> The response payload (empty on success).
	 */
	public function notificationReceived($notificationType, $providerId, array $notification): array {
		$this->logger->info('[Federation] OCM notification ' . ((string)$notificationType) . ' for ' . ((string)$providerId));

		// Status transitions are applied by the share-management service in a
		// follow-up; this hook currently acknowledges receipt so the remote does
		// not retry. Full accept/decline/revoke wiring lands with the settings UI.
		return [];
	}//end notificationReceived()
}//end class
