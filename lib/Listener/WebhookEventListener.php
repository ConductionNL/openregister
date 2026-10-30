<?php

/**
 * OpenRegister Webhook Event Listener
 *
 * Listener for webhook events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-event-payload-input-must-include-full-context-for-mapping-templates
 * @spec openspec/specs/event-driven-architecture/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentDeletedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\ConversationCreatedEvent;
use OCA\OpenRegister\Event\ConversationDeletedEvent;
use OCA\OpenRegister\Event\ConversationUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceDeletedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Event\ViewCreatedEvent;
use OCA\OpenRegister\Event\ViewDeletedEvent;
use OCA\OpenRegister\Event\ViewUpdatedEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * WebhookEventListener dispatches webhooks for all OpenRegister events
 *
 * @template-implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WebhookEventListener implements IEventListener {

	/**
	 * Events whose payload is one serialised entity: event class => [objectType, action, getter].
	 *
	 * The entity lands under the objectType key.
	 *
	 * @var array<class-string<Event>, array{0: string, 1: string, 2: string}>
	 */
	private const SNAPSHOT_EVENTS = [
		ObjectDeletingEvent::class => ['object', 'deleting', 'getObject'],
		ObjectLockedEvent::class => ['object', 'locked', 'getObject'],
		ObjectUnlockedEvent::class => ['object', 'unlocked', 'getObject'],
		RegisterCreatedEvent::class => ['register', 'created', 'getRegister'],
		RegisterUpdatedEvent::class => ['register', 'updated', 'getNewRegister'],
		RegisterDeletedEvent::class => ['register', 'deleted', 'getRegister'],
		SchemaCreatedEvent::class => ['schema', 'created', 'getSchema'],
		SchemaUpdatedEvent::class => ['schema', 'updated', 'getNewSchema'],
		SchemaDeletedEvent::class => ['schema', 'deleted', 'getSchema'],
		ApplicationCreatedEvent::class => ['application', 'created', 'getApplication'],
		ApplicationUpdatedEvent::class => ['application', 'updated', 'getNewApplication'],
		ApplicationDeletedEvent::class => ['application', 'deleted', 'getApplication'],
		AgentCreatedEvent::class => ['agent', 'created', 'getAgent'],
		AgentUpdatedEvent::class => ['agent', 'updated', 'getAgent'],
		AgentDeletedEvent::class => ['agent', 'deleted', 'getAgent'],
		SourceCreatedEvent::class => ['source', 'created', 'getSource'],
		SourceUpdatedEvent::class => ['source', 'updated', 'getSource'],
		SourceDeletedEvent::class => ['source', 'deleted', 'getSource'],
		ConfigurationCreatedEvent::class => ['configuration', 'created', 'getConfiguration'],
		ConfigurationUpdatedEvent::class => ['configuration', 'updated', 'getConfiguration'],
		ConfigurationDeletedEvent::class => ['configuration', 'deleted', 'getConfiguration'],
		ViewCreatedEvent::class => ['view', 'created', 'getView'],
		ViewUpdatedEvent::class => ['view', 'updated', 'getView'],
		ViewDeletedEvent::class => ['view', 'deleted', 'getView'],
		ConversationCreatedEvent::class => ['conversation', 'created', 'getConversation'],
		ConversationUpdatedEvent::class => ['conversation', 'updated', 'getConversation'],
		ConversationDeletedEvent::class => ['conversation', 'deleted', 'getConversation'],
		OrganisationCreatedEvent::class => ['organisation', 'created', 'getOrganisation'],
		OrganisationUpdatedEvent::class => ['organisation', 'updated', 'getOrganisation'],
		OrganisationDeletedEvent::class => ['organisation', 'deleted', 'getOrganisation'],
	];

	/**
	 * Object save and delete events: event class => [action, getter of the object].
	 *
	 * @var array<class-string<Event>, array{0: string, 1: string}>
	 */
	private const OBJECT_STATE_EVENTS = [
		ObjectCreatedEvent::class => ['create', 'getObject'],
		ObjectUpdatedEvent::class => ['update', 'getNewObject'],
		ObjectDeletedEvent::class => ['delete', 'getObject'],
	];

	/**
	 * Webhook service
	 *
	 * @var WebhookService
	 */
	private WebhookService $webhookService;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param WebhookService $webhookService Webhook service
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		WebhookService $webhookService,
		LoggerInterface $logger,
	) {
		$this->webhookService = $webhookService;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Handle event
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function handle(Event $event): void {
		$eventClass = get_class($event);
		$payload = $this->extractPayload(event: $event);

		if ($payload === null) {
			$this->logger->warning(
				message: '[WebhookEventListener] Could not extract payload from event',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'event' => $eventClass,
				]
			);
			return;
		}

		$this->logger->debug(
			message: '[WebhookEventListener] Processing event for webhooks',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'event' => $eventClass,
			]
		);

		// Dispatch to webhook service.
		$this->webhookService->dispatchEvent(_event: $event, eventName: $eventClass, payload: $payload);
	}//end handle()

	/**
	 * Extract payload from event
	 *
	 * Events that carry one entity map to `objectType`, `action` and the
	 * serialised entity under the objectType key (SNAPSHOT_EVENTS). Object
	 * create, update and revert events carry more context (objectPayload()).
	 *
	 * @param Event $event The event
	 *
	 * @return array<string, mixed>|null The event payload or null if not extractable
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-event-payload-input-must-include-full-context-for-mapping-templates
	 */
	private function extractPayload(Event $event): ?array {
		foreach (self::SNAPSHOT_EVENTS as $class => [$objectType, $action, $getter]) {
			if ($event instanceof $class) {
				return [
					'objectType' => $objectType,
					'action' => $action,
					$objectType => $event->{$getter}()->jsonSerialize(),
				];
			}
		}

		return $this->objectPayload(event: $event);
	}//end extractPayload()

	/**
	 * Build the payload of an object create, update or revert event.
	 *
	 * @param Event $event The event
	 *
	 * @return array<string, mixed>|null The payload, or null for any other event
	 */
	private function objectPayload(Event $event): ?array {
		if ($event instanceof ObjectCreatingEvent) {
			$object = $event->getObject();
			return [
				'objectType' => 'object',
				'action' => 'creating',
				'object' => $object->jsonSerialize(),
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
			];
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$newObject = $event->getNewObject();
			return [
				'objectType' => 'object',
				'action' => 'updating',
				'newObject' => $newObject->jsonSerialize(),
				'oldObject' => $event->getOldObject()?->jsonSerialize(),
				'register' => $newObject->getRegister(),
				'schema' => $newObject->getSchema(),
			];
		}

		if ($event instanceof ObjectRevertedEvent) {
			return [
				'objectType' => 'object',
				'action' => 'reverted',
				'object' => $event->getObject()->jsonSerialize(),
				'revertPoint' => $event->getRevertPoint(),
			];
		}

		foreach (self::OBJECT_STATE_EVENTS as $class => [$action, $getter]) {
			if ($event instanceof $class) {
				return $this->objectStatePayload(object: $event->{$getter}(), action: $action);
			}
		}

		return null;
	}//end objectPayload()

	/**
	 * Build the payload of a saved or deleted object.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object The object
	 * @param string                             $action The action: create, update or delete
	 *
	 * @return array<string, mixed> The payload, stamped with the current time
	 */
	private function objectStatePayload(\OCA\OpenRegister\Db\ObjectEntity $object, string $action): array {
		return [
			'objectType' => 'object',
			'action' => $action,
			'object' => $object->jsonSerialize(),
			'objectUuid' => $object->getUuid(),
			'register' => $object->getRegister(),
			'schema' => $object->getSchema(),
			'timestamp' => date('c'),
		];
	}//end objectStatePayload()
}//end class
