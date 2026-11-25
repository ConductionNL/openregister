<?php

/**
 * Disable a person's owned webhooks when they are removed or disabled.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Disables, never deletes, the webhooks a person owns once they are about to
 * be removed or are disabled, so an administrator can review them and hand
 * them to someone else. Delivery checks the owner on every attempt as well;
 * this makes the stop visible as `enabled: false`.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-read
 */
class OwnedWebhooksEndWithOwnerListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param WebhookMapperInterface $webhooks Disables the owner's webhooks.
	 * @param LoggerInterface        $logger   Where a failure is reported.
	 */
	public function __construct(
		private readonly WebhookMapperInterface $webhooks,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Disable the owner's webhooks on a removal or a disabling.
	 *
	 * @param Event $event The user event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-read
	 */
	public function handle(Event $event): void {
		$user = $this->leavingUser(event: $event);
		if ($user === null) {
			return;
		}

		try {
			$disabled = $this->webhooks->disableOwnedBy(uid: $user->getUID());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[OwnedWebhooksEndWithOwnerListener] Could not disable the webhooks of a departing owner',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return;
		}

		if ($disabled > 0) {
			$this->logger->info(
				message: '[OwnedWebhooksEndWithOwnerListener] Disabled the webhooks of a departing owner',
				context: ['count' => $disabled]
			);
		}
	}//end handle()

	/**
	 * The user who is being removed or disabled, or null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return IUser|null
	 */
	private function leavingUser(Event $event): ?IUser {
		if ($event instanceof BeforeUserDeletedEvent) {
			return $event->getUser();
		}

		if ($event instanceof UserChangedEvent
			&& $event->getFeature() === 'enabled'
			&& in_array($event->getValue(), [false, 'false', 0, '0'], true) === true
		) {
			return $event->getUser();
		}

		return null;
	}//end leavingUser()
}//end class
