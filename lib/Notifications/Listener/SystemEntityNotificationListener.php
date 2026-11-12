<?php

/**
 * OpenRegister SystemEntityNotificationListener
 *
 * Bridges create/update events from OpenRegister's own system entities
 * (Register, Schema, Configuration, Source, Agent, Webhook) into the
 * AnnotationNotificationDispatcher so their declared
 * x-openregister-notifications rules fire through the same listener/dispatcher
 * path used for stored register objects.
 *
 * Populates _oldData / _newData on updated-event dispatches so the
 * field-change `condition` block is available for system schemas too.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\SystemEntityChange;
use OCA\OpenRegister\Notifications\Service\Notification\SystemEntityObjectAdapter;
use OCA\OpenRegister\Notifications\Service\Notification\SystemSchemaRules;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Routes system-entity lifecycle events through the annotation notification pipeline.
 *
 * @template-implements IEventListener<AgentCreatedEvent|AgentUpdatedEvent|ConfigurationCreatedEvent|ConfigurationUpdatedEvent|RegisterCreatedEvent|RegisterUpdatedEvent|SchemaCreatedEvent|SchemaUpdatedEvent|SourceCreatedEvent|SourceUpdatedEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Listener maps ten distinct event classes to two
 * dispatcher calls; each event class import is required by the switch-by-instanceof dispatch below
 * and cannot be reduced without losing event coverage.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
 */
class SystemEntityNotificationListener implements IEventListener {
	/**
	 * Wire the dispatcher and the system-schema rule registry.
	 *
	 * @param AnnotationNotificationDispatcher $dispatcher Dispatcher used to fire notifications.
	 * @param SystemSchemaRules $rules Registry of declared system-schema rules.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AnnotationNotificationDispatcher $dispatcher,
		private readonly SystemSchemaRules $rules,
	) {
	}//end __construct()

	/**
	 * The created events, each with the getter for its entity and its system slug.
	 *
	 * @var array<class-string<Event>, array{0: string, 1: string}>
	 */
	private const CREATED_EVENTS = [
		RegisterCreatedEvent::class => ['getRegister', SystemSchemaRules::SLUG_REGISTER],
		SchemaCreatedEvent::class => ['getSchema', SystemSchemaRules::SLUG_SCHEMA],
		ConfigurationCreatedEvent::class => ['getConfiguration', SystemSchemaRules::SLUG_CONFIGURATION],
		SourceCreatedEvent::class => ['getSource', SystemSchemaRules::SLUG_SOURCE],
		AgentCreatedEvent::class => ['getAgent', SystemSchemaRules::SLUG_AGENT],
	];

	/**
	 * The updated events, each with the getters for its new and old entity and its system slug.
	 *
	 * @var array<class-string<Event>, array{0: string, 1: string, 2: string}>
	 */
	private const UPDATED_EVENTS = [
		RegisterUpdatedEvent::class => ['getNewRegister', 'getOldRegister', SystemSchemaRules::SLUG_REGISTER],
		SchemaUpdatedEvent::class => ['getNewSchema', 'getOldSchema', SystemSchemaRules::SLUG_SCHEMA],
		ConfigurationUpdatedEvent::class => ['getNewConfiguration', 'getOldConfiguration', SystemSchemaRules::SLUG_CONFIGURATION],
		SourceUpdatedEvent::class => ['getNewSource', 'getOldSource', SystemSchemaRules::SLUG_SOURCE],
		AgentUpdatedEvent::class => ['getNewAgent', 'getOldAgent', SystemSchemaRules::SLUG_AGENT],
	];

	/**
	 * Bridge the inbound system-entity event to the notification dispatcher.
	 *
	 * Resolves the entity + slug from the concrete event type, wraps the entity as a
	 * virtual ObjectEntity, loads the synthetic schema from the rule registry, and
	 * delegates to AnnotationNotificationDispatcher::dispatchWithSchema() — the same
	 * path used for stored register objects.
	 *
	 * @param Event $event Inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function handle(Event $event): void {
		$eventData = $this->extractEventData(event: $event);
		[$entity, $slug] = $eventData;

		if ($entity === null || $slug === null) {
			return;
		}

		$schema = $this->rules->buildSchema(slug: $slug);
		if ($schema === null) {
			return;
		}

		$this->dispatchChange(eventData: $eventData, schema: $schema);

	}//end handle()

	/**
	 * Dispatch one recognised system-entity change against its synthetic schema.
	 *
	 * @param array{0: Entity, 1: string, 2: string, 3: array<string,mixed>|null} $eventData The entity, slug, trigger and old data.
	 * @param Schema                                                              $schema    The synthetic system schema.
	 *
	 * @return void
	 */
	private function dispatchChange(array $eventData, Schema $schema): void {
		[$entity, $slug, $trigger] = $eventData;

		$adapter = new SystemEntityObjectAdapter(entity: $entity, systemSlug: $slug);
		$context = $this->changeContext(eventData: $eventData);
		if ($context === null) {
			return;
		}

		$this->dispatcher->dispatchWithSchema(
			object: $adapter,
			trigger: $trigger,
			context: $context,
			schema: $schema
		);
	}//end dispatchChange()

	/**
	 * The dispatch context of a change: old and new data for a real update, empty otherwise.
	 *
	 * @param array{0: Entity, 1: string, 2: string, 3: array<string,mixed>|null} $eventData The entity, slug, trigger and old data.
	 *
	 * @return array<string, mixed>|null The context, or null when an update moved nothing worth telling.
	 */
	private function changeContext(array $eventData): ?array {
		[$entity, , $trigger, $oldData] = $eventData;
		if ($trigger !== 'updated' || $oldData === null) {
			return [];
		}

		$newData = $this->entityData(entity: $entity);
		if (is_array($newData) === false) {
			return [];
		}

		// An administrator is told about an update only when the content
		// moved. An app re-import saves every entity it ships; one that
		// differs only in its stamps, its version or the order of its
		// keys is not news (Ruben, 7 October 2026).
		if ((new SystemEntityChange())->isReal(old: $oldData, new: $newData) === false) {
			return null;
		}

		return ['_newData' => $newData, '_oldData' => $oldData];
	}//end changeContext()

	/**
	 * The entity's serialised data, or an empty array when it does not serialise.
	 *
	 * @param Entity $entity The new entity.
	 *
	 * @return mixed
	 */
	private function entityData(Entity $entity): mixed {
		$newData = [];
		if ($entity instanceof \JsonSerializable) {
			$newData = $entity->jsonSerialize();
		}

		return $newData;
	}//end entityData()

	/**
	 * Extract entity, system slug, trigger and old-data from the concrete event.
	 *
	 * Returns [entity, slug, trigger, oldData|null]. Returns [null, null, '', null] when
	 * the event type is not recognised as a system-entity event.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return array{0: Entity|null, 1: string|null, 2: string, 3: array<string,mixed>|null}
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
	 */
	private function extractEventData(Event $event): array {
		foreach (self::CREATED_EVENTS as $eventClass => [$getter, $slug]) {
			if ($event instanceof $eventClass) {
				return [$event->{$getter}(), $slug, 'created', null];
			}
		}

		return $this->updatedEventData(event: $event);
	}//end extractEventData()

	/**
	 * Extract entity, system slug, trigger and old-data from an updated event.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return array{0: Entity|null, 1: string|null, 2: string, 3: array<string,mixed>|null}
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
	 */
	private function updatedEventData(Event $event): array {
		foreach (self::UPDATED_EVENTS as $eventClass => [$newGetter, $oldGetter, $slug]) {
			if ($event instanceof $eventClass) {
				// Every Db entity extends OCP Entity, which implements
				// JsonSerializable, so the instanceof guard that used to wrap this
				// was always true and the null default unreachable.
				$oldData = $event->{$oldGetter}()->jsonSerialize();

				return [$event->{$newGetter}(), $slug, 'updated', $oldData];
			}
		}

		return [null, null, '', null];
	}//end updatedEventData()
}//end class
