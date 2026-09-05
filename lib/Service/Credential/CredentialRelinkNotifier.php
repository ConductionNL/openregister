<?php

/**
 * CredentialRelinkNotifier — tells the instance and the owner that a grant is gone.
 *
 * Split out of {@see OAuth2RefreshService} rather than inlined there, because the
 * refresh service's job is to produce a valid access token and this is the entirely
 * separate job of telling people it cannot. Keeping them apart also keeps the
 * refresh service's dependency list to the stores and the HTTP client, so a change
 * to how the fleet announces things does not touch the code that spends refresh
 * tokens.
 *
 * Everything here is BEST EFFORT. The caller is already raising a typed exception
 * and has already written the credential's status, which is the part that actually
 * makes the connection stop being used. A notification backend that is down must
 * not turn a recoverable relink into an unhandled error.
 *
 * Nothing announced from here carries a token. The event holds the credential id,
 * the provider, the owner and an OAuth2 error code; the notification holds the
 * provider. A listener is arbitrary code in another app, and an event payload is
 * exactly the kind of second read path ADR-064 exists to close.
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

use DateTime;
use OCA\OpenRegister\Event\CredentialRelinkRequiredEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Announces that a brokered OAuth2 connection must be re-authorised.
 */
class CredentialRelinkNotifier {
	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Announces the lost grant to the rest of the instance.
	 * @param INotificationManager $notifications Tells the owner their connection needs repairing.
	 * @param LoggerInterface $logger Records an announcement that could not be made.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Announce a lost grant, once.
	 *
	 * @param string $credentialId The credential UUID, which is the credentialRef apps hold.
	 * @param string $provider The catalogue provider identifier.
	 * @param string $owner The owning user id, or an empty string when there is none to tell.
	 * @param string $reason The provider's OAuth2 error code (secret-free by construction).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	public function announce(string $credentialId, string $provider, string $owner, string $reason): void {
		try {
			$this->eventDispatcher->dispatchTyped(
				new CredentialRelinkRequiredEvent(
					credentialId: $credentialId,
					provider: $provider,
					owner: $owner,
					reason: $reason
				)
			);
		} catch (Throwable $dispatchFailure) {
			$this->logger->warning('[CredentialRelinkNotifier] event dispatch failed: ' . $dispatchFailure->getMessage());
		}

		if ($owner === '') {
			return;
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp('openregister')
				->setUser($owner)
				->setDateTime(new DateTime())
				->setObject('brokered_credential', $credentialId)
				->setSubject('credential_relink_needed', ['provider' => $provider]);
			$this->notifications->notify($notification);
		} catch (Throwable $notifyFailure) {
			$this->logger->warning('[CredentialRelinkNotifier] notification failed: ' . $notifyFailure->getMessage());
		}
	}//end announce()
}//end class
