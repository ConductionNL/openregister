<?php

/**
 * OpenRegister OwnedWebhookView
 *
 * What a webhook owned by a person may deliver: only what that person may see
 * (webhooks-for-owners, D-5).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-see
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an event into what an owned webhook may deliver, or refuses it.
 *
 * An administrator's webhook (no owner) passes unchanged. For an owned one,
 * every delivery, first attempt, retry or manual resend alike:
 *
 * 1. the owner must exist, be enabled and hold `webhook.own`; otherwise the
 *    webhook is disabled and nothing is delivered;
 * 2. only the object created, updated and deleted events are delivered;
 * 3. a created or updated object is re-read as the owner, with RBAC and
 *    multitenancy on, and that read replaces the object in the payload; the
 *    earlier version (`oldObject`) is dropped; a record the owner cannot read
 *    is not delivered;
 * 4. a deleted object is sent as identifiers only.
 *
 * Every failure refuses: an owned webhook never falls back to the full event.
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-see
 */
class OwnedWebhookView {

	/**
	 * The right a person needs to own a webhook.
	 *
	 * @var string
	 */
	public const RIGHT = 'webhook.own';

	/**
	 * The events an owned webhook may deliver.
	 *
	 * @var array<int, string>
	 */
	public const EVENTS = [ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class];

	/**
	 * The payload keys a deleted object is sent with.
	 *
	 * @var array<int, string>
	 */
	private const IDENTIFIERS = ['objectType', 'action', 'objectUuid', 'register', 'schema', 'timestamp'];

	/**
	 * Constructor.
	 *
	 * @param IUserManager                  $users      Looks the owner up.
	 * @param OpenRegisterActionAuthService $actionAuth The action-rights matrix.
	 * @param WebhookMapperInterface        $webhooks   Disables a webhook whose owner may no longer own it.
	 * @param ContainerInterface            $container  Resolves ObjectService lazily, out of WebhookService's construction.
	 * @param LoggerInterface               $logger     Logger.
	 */
	public function __construct(
		private readonly IUserManager $users,
		private readonly OpenRegisterActionAuthService $actionAuth,
		private readonly WebhookMapperInterface $webhooks,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The payload this webhook may deliver for the event, or null when it delivers nothing.
	 *
	 * @param Webhook      $webhook The webhook.
	 * @param WebhookEvent $event   The event.
	 *
	 * @return WebhookEvent|null The event to deliver, or null.
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-see
	 */
	public function view(Webhook $webhook, WebhookEvent $event): ?WebhookEvent {
		$ownerId = trim((string)$webhook->getOwner());
		if ($ownerId === '') {
			return $event;
		}

		$owner = $this->ownerWhoMayOwn(webhook: $webhook, ownerId: $ownerId);
		if ($owner === null) {
			return null;
		}

		if (in_array($event->name, self::EVENTS, true) === false) {
			return $this->refuse(webhook: $webhook, reason: 'only object created, updated and deleted events are delivered to an owned webhook');
		}

		if ($event->name === ObjectDeletedEvent::class) {
			return new WebhookEvent(name: $event->name, payload: array_intersect_key($event->payload, array_flip(self::IDENTIFIERS)));
		}

		return $this->asOwnerSeesIt(webhook: $webhook, event: $event, owner: $owner);
	}//end view()

	/**
	 * Whether an owned webhook may be delivered to before its payload is built.
	 *
	 * Used before a delivery job is queued, so an event the webhook would refuse
	 * anyway does not cost a job.
	 *
	 * @param Webhook $webhook   The webhook.
	 * @param string  $eventName The event class.
	 *
	 * @return bool False for an owned webhook and an event it never delivers.
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-see
	 */
	public static function mayQueue(Webhook $webhook, string $eventName): bool {
		if (trim((string)$webhook->getOwner()) === '') {
			return true;
		}

		return in_array($eventName, self::EVENTS, true);
	}//end mayQueue()

	/**
	 * The owner, when they exist, are enabled and hold the right; else disable the webhook.
	 *
	 * @param Webhook $webhook The webhook.
	 * @param string  $ownerId The owner's uid.
	 *
	 * @return IUser|null The owner, or null.
	 */
	private function ownerWhoMayOwn(Webhook $webhook, string $ownerId): ?IUser {
		$owner = $this->users->get($ownerId);
		$why   = null;
		if ($owner === null) {
			$why = 'its owner no longer exists';
		} elseif ($owner->isEnabled() === false) {
			$why = 'its owner is disabled';
		} elseif ($this->mayOwn(owner: $owner) === false) {
			$why = 'its owner no longer holds ' . self::RIGHT;
		}

		if ($why === null) {
			return $owner;
		}

		$this->disable(webhook: $webhook, reason: $why);

		return null;
	}//end ownerWhoMayOwn()

	/**
	 * Whether the owner holds the right; an unreadable matrix answers no.
	 *
	 * @param IUser $owner The owner.
	 *
	 * @return bool
	 */
	private function mayOwn(IUser $owner): bool {
		try {
			return $this->actionAuth->can(user: $owner, action: self::RIGHT);
		} catch (Throwable $e) {
			$this->logger->warning(message: '[OwnedWebhookView] The action rights could not be read; not delivering', context: ['error' => $e->getMessage()]);
			return false;
		}
	}//end mayOwn()

	/**
	 * The event with the object as the owner reads it, and no earlier version.
	 *
	 * @param Webhook      $webhook The webhook.
	 * @param WebhookEvent $event   A created or updated event.
	 * @param IUser        $owner   The owner.
	 *
	 * @return WebhookEvent|null The event, or null when the owner cannot read the object.
	 */
	private function asOwnerSeesIt(Webhook $webhook, WebhookEvent $event, IUser $owner): ?WebhookEvent {
		$payload = $event->payload;
		$uuid    = $this->objectUuidOf(payload: $payload);
		if ($uuid === '') {
			return $this->refuse(webhook: $webhook, reason: 'the event names no object');
		}

		try {
			$objects = $this->container->get(ObjectService::class);
			$read    = $objects->runAs(
				$owner,
				static fn () => $objects->find(
					id: $uuid,
					options: new ObjectFindOptions(register: ($payload['register'] ?? null), schema: ($payload['schema'] ?? null), audit: false),
					_rbac: true,
					_multitenancy: true
				)
			);
		} catch (Throwable $e) {
			$read = null;
		}

		if ($read === null) {
			return $this->refuse(webhook: $webhook, reason: 'its owner may not read the object');
		}

		unset($payload['oldObject'], $payload['newObject']);
		$payload['object'] = $read->jsonSerialize();

		return new WebhookEvent(name: $event->name, payload: $payload);
	}//end asOwnerSeesIt()

	/**
	 * The uuid of the object an event is about.
	 *
	 * @param array<string, mixed> $payload The event payload.
	 *
	 * @return string The uuid, or '' when none is named.
	 */
	private function objectUuidOf(array $payload): string {
		$uuid = ($payload['objectUuid'] ?? null);
		if (is_string($uuid) === false || trim($uuid) === '') {
			$object = (array)($payload['object'] ?? ($payload['newObject'] ?? []));
			$uuid   = ($object['@self']['id'] ?? ($object['id'] ?? ($object['uuid'] ?? '')));
		}

		return trim((string)$uuid);
	}//end objectUuidOf()

	/**
	 * Log why an owned webhook delivers nothing for this event.
	 *
	 * @param Webhook $webhook The webhook.
	 * @param string  $reason  Why.
	 *
	 * @return null Always.
	 */
	private function refuse(Webhook $webhook, string $reason): mixed {
		$this->logger->info(message: '[OwnedWebhookView] Not delivered: ' . $reason, context: ['webhook_id' => $webhook->getId()]);

		return null;
	}//end refuse()

	/**
	 * Disable a webhook whose owner may no longer own it, and say why.
	 *
	 * @param Webhook $webhook The webhook.
	 * @param string  $reason  Why.
	 *
	 * @return void
	 */
	private function disable(Webhook $webhook, string $reason): void {
		$this->logger->warning(message: '[OwnedWebhookView] Webhook disabled: ' . $reason, context: ['webhook_id' => $webhook->getId()]);
		try {
			$this->webhooks->updateFromArray((int)$webhook->getId(), ['enabled' => false]);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[OwnedWebhookView] The webhook could not be disabled',
				context: ['webhook_id' => $webhook->getId(), 'error' => $e->getMessage()]
			);
		}
	}//end disable()
}//end class
