<?php

/**
 * OcmShareSender — the outbound half of OpenRegister's Open Cloud Mesh
 * provider: send a share to a principal at another instance, and tell that
 * instance when the share's state changes here.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends federated shares and their state changes over OCM.
 *
 * The share travels as the `openregister` resource type, and its protocol
 * options carry exactly what the receiving provider's `shareReceived()` reads:
 * this instance's address, the share token, the scope and target, the
 * permissions and what crosses. The share secret is the share token, which is
 * also what authenticates a notification about it in either direction.
 *
 * A share whose recipient is not a federated cloud id (`user@host`) is a
 * token share handed over by other means, and nothing is sent for it.
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */
class OcmShareSender {

	/**
	 * The OCM resource type, as `OpenRegisterCloudFederationProvider::getShareType()` names it.
	 *
	 * @var string
	 */
	public const RESOURCE_TYPE = 'openregister';

	/**
	 * The share was delivered to the other instance.
	 *
	 * @var string
	 */
	public const SENT = 'sent';

	/**
	 * The recipient is not at another instance, so nothing was sent.
	 *
	 * @var string
	 */
	public const NOT_FEDERATED = 'not-federated';

	/**
	 * The other instance could not be reached or refused the share.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * The OCM notification each local status change sends, by share direction.
	 *
	 * An outgoing share revoked here is withdrawn there; an incoming share
	 * accepted or declined here is answered to its sender, and one revoked
	 * here is a decline.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const NOTIFICATIONS = [
		'outgoing' => ['revoked' => 'SHARE_UNSHARED'],
		'incoming' => ['accepted' => 'SHARE_ACCEPTED', 'declined' => 'SHARE_DECLINED', 'revoked' => 'SHARE_DECLINED'],
	];

	/**
	 * Constructor.
	 *
	 * @param ICloudFederationProviderManager $providerManager Sends shares and notifications.
	 * @param ICloudFederationFactory         $factory         Builds the OCM share and notification.
	 * @param ICloudIdManager                 $cloudIdManager  Tells a federated cloud id from a local name.
	 * @param IURLGenerator                   $urlGenerator    This instance's address.
	 * @param LoggerInterface                 $logger          Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ICloudFederationProviderManager $providerManager,
		private readonly ICloudFederationFactory $factory,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send an outgoing share to its recipient's instance.
	 *
	 * @param FederatedShare $share The stored outgoing share.
	 *
	 * @return string {@see self::SENT}, {@see self::NOT_FEDERATED} or {@see self::FAILED}.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
	 */
	public function send(FederatedShare $share): string {
		$shareWith = trim((string)$share->getSharedWith());
		if ($share->getDirection() !== 'outgoing' || $this->isCloudId(shareWith: $shareWith) === false) {
			return self::NOT_FEDERATED;
		}

		$owner = (string)$share->getOwner();
		$token = (string)$share->getShareToken();
		try {
			$ocmShare = $this->factory->getCloudFederationShare(
				$shareWith,
				$this->resourceName(share: $share),
				'',
				(string)$share->getId(),
				$this->localCloudId(user: $owner),
				$owner,
				$this->localCloudId(user: $owner),
				$owner,
				$token,
				'user',
				self::RESOURCE_TYPE
			);
			$ocmShare->setProtocol(['name' => 'webdav', 'options' => $this->protocolOptions(share: $share)]);

			$status = $this->providerManager->sendCloudShare($ocmShare)->getStatusCode();
		} catch (Throwable $e) {
			$this->logger->warning('[Federation] sending share ' . (string)$share->getId() . ' failed: ' . $e->getMessage());
			return self::FAILED;
		}

		if ($status < 200 || $status >= 300) {
			$this->logger->warning('[Federation] the other instance refused share ' . (string)$share->getId() . ' with HTTP ' . $status);
			return self::FAILED;
		}

		return self::SENT;
	}//end send()

	/**
	 * Tell the other instance a share's status changed here.
	 *
	 * Only the changes in {@see self::NOTIFICATIONS} are sent, and only for a
	 * share that has another instance on its other side. A failure is logged
	 * and does not undo the change here: the local status is what governs
	 * serving.
	 *
	 * @param FederatedShare $share  The share as it now stands.
	 * @param string         $status The status it was given.
	 *
	 * @return bool Whether a notification was delivered.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
	 */
	public function notify(FederatedShare $share, string $status): bool {
		$direction = (string)$share->getDirection();
		$type = (self::NOTIFICATIONS[$direction][$status] ?? null);
		$target = $this->notificationTarget(share: $share);
		if ($type === null || $target === null) {
			return false;
		}

		try {
			$notification = $this->factory->getCloudFederationNotification();
			$notification->setMessage(
				$type,
				self::RESOURCE_TYPE,
				$this->notifiedProviderId(share: $share),
				['sharedSecret' => (string)$share->getShareToken(), 'message' => 'The share was ' . $status . '.']
			);

			$code = $this->providerManager->sendCloudNotification($target, $notification)->getStatusCode();
		} catch (Throwable $e) {
			$this->logger->warning('[Federation] notifying share ' . (string)$share->getId() . ' ' . $status . ' failed: ' . $e->getMessage());
			return false;
		}

		return $code >= 200 && $code < 300;
	}//end notify()

	/**
	 * Whether a recipient names a principal at another instance.
	 *
	 * @param string $shareWith The recipient.
	 *
	 * @return bool
	 */
	private function isCloudId(string $shareWith): bool {
		if ($shareWith === '' || str_contains($shareWith, '@') === false) {
			return false;
		}

		try {
			return $this->cloudIdManager->isValidCloudId($shareWith);
		} catch (Throwable $e) {
			return false;
		}
	}//end isCloudId()

	/**
	 * The instance to notify about a share: the recipient's for an outgoing
	 * share, the sender's for an incoming one; null when there is none.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return string|null The remote address.
	 */
	private function notificationTarget(FederatedShare $share): ?string {
		if ($share->getDirection() === 'outgoing') {
			$shareWith = trim((string)$share->getSharedWith());
			if ($this->isCloudId(shareWith: $shareWith) === false) {
				return null;
			}

			try {
				return $this->cloudIdManager->resolveCloudId($shareWith)->getRemote();
			} catch (Throwable $e) {
				return null;
			}
		}

		$remote = trim((string)$share->getRemoteInstanceUrl());
		if ($remote === '' || trim((string)$share->getRemoteProviderId()) === '') {
			return null;
		}

		return $remote;
	}//end notificationTarget()

	/**
	 * The id the other instance knows the share by: ours for a share sent from
	 * here, the sender's for a share received here.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return string
	 */
	private function notifiedProviderId(FederatedShare $share): string {
		if ($share->getDirection() === 'outgoing') {
			return (string)$share->getId();
		}

		return (string)$share->getRemoteProviderId();
	}//end notifiedProviderId()

	/**
	 * The protocol options the receiving provider reads (`incomingShareParams()`).
	 *
	 * @param FederatedShare $share The outgoing share.
	 *
	 * @return array<string, mixed>
	 */
	private function protocolOptions(FederatedShare $share): array {
		return [
			'apiUrl' => rtrim($this->urlGenerator->getAbsoluteURL('/'), '/'),
			'token' => (string)$share->getShareToken(),
			'sharedSecret' => (string)$share->getShareToken(),
			'scope' => (string)$share->getScope(),
			'register' => $share->getRegister(),
			'schema' => $share->getSchema(),
			'objectUri' => $share->getObjectUri(),
			'permissions' => (string)$share->getPermissions(),
			'crosses' => ShareCrosses::of(share: $share),
		];
	}//end protocolOptions()

	/**
	 * The name the recipient sees for the share: what it points at.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return string
	 */
	private function resourceName(FederatedShare $share): string {
		$target = array_filter(
			[(string)$share->getRegister(), (string)$share->getSchema(), (string)$share->getObjectUri()],
			static fn (string $part): bool => $part !== ''
		);
		if ($target === []) {
			return (string)$share->getScope();
		}

		return implode(' / ', $target);
	}//end resourceName()

	/**
	 * A local user's federated cloud id, or the bare name when it cannot be made.
	 *
	 * @param string $user The local user id.
	 *
	 * @return string
	 */
	private function localCloudId(string $user): string {
		try {
			return $this->cloudIdManager->getCloudId($user, null)->getId();
		} catch (Throwable $e) {
			return $user;
		}
	}//end localCloudId()
}//end class
