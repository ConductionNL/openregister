<?php

/**
 * OpenRegister NotifyPushListener
 *
 * Listens for object lifecycle events (created/updated/deleted) and pushes
 * real-time notifications to connected browser clients via the Nextcloud
 * notify_push app.
 *
 * Investigation findings (Task 1):
 *  - ObjectCreatedEvent::getObject()    → ObjectEntity (the new object)
 *  - ObjectUpdatedEvent::getObject()    → ObjectEntity (new state, alias of getNewObject())
 *  - ObjectDeletedEvent::getObject()    → ObjectEntity (the deleted object)
 *  - ObjectEntity::getUuid()            → string|null
 *  - ObjectEntity::getRegister()        → string|null (UUID of register)
 *  - ObjectEntity::getSchema()          → string|null (UUID of schema)
 *  - ObjectEntity::getVersion()         → string|null
 *  - Register::getSlug() / Schema::getSlug() → string|null
 *    Slugs are resolved via RegisterMapper and SchemaMapper, injected lazily.
 *  - PermissionHandlerInterface::getReadableByUsers(ObjectEntity) → array<string>
 *    Returns user IDs that can read the object (empty array = all users / broadcast).
 *  - IQueue is resolved lazily from the container (OCA\NotifyPush\Queue\IQueue).
 *    If notify_push is not installed the container throws — soft-fail at most once.
 *  - Registration site: ListenerRegistrar::register(), next to
 *    the GraphQLSubscriptionListener registrations.
 *  - IAppConfig key `openregister.push_available` is set to '1' on the first
 *    successful IQueue::push() call (consumed by OpenRegisterAdmin::getPushStatus()).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\Support\PushSlugResolver;
use OCA\OpenRegister\Push\PushChannels;
use OCA\OpenRegister\Service\Object\ObjectReadersInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Listener\Support\NotifyPushPlatform;

/**
 * Pushes object lifecycle events to browser clients via notify_push.
 *
 * Implements soft-fail behaviour: if notify_push is not installed the listener
 * silently skips without logging warnings. Only one DEBUG entry is emitted per
 * request when the queue cannot be resolved.
 *
 * Static batch mode is provided for bulk-import paths to suppress per-object
 * pushes and instead emit one collection event per (register, schema) pair at
 * the end of the import.
 *
 * @implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Listener must reference three event types
 *   (ObjectCreated/Updated/DeletedEvent), the slug resolver, a container, logger, appConfig,
 *   and PermissionHandlerInterface; each dependency is required for the push-routing.
 */
class NotifyPushListener implements IEventListener, PresencePushInterface {

	/**
	 * Whether batch mode is active.
	 *
	 * In batch mode individual object pushes are suppressed; collection events
	 * are accumulated and flushed once via flushBatch().
	 *
	 * @var boolean
	 */
	private static bool $batchMode = false;

	/**
	 * Accumulated (register-slug, schema-slug) pairs during batch mode.
	 *
	 * Keys are composite strings `"{registerSlug}|{schemaSlug}"` to deduplicate.
	 *
	 * @var array<string, array{register: string, schema: string}>
	 */
	private static array $batchedCollections = [];

	/**
	 * Per-request deduplication accumulator.
	 *
	 * Keys are `"{uuid}|{action}"` to prevent double-emitting when the same
	 * object fires the same action more than once in a single request.
	 *
	 * @var array<string, true>
	 */
	private static array $seen = [];

	/**
	 * Whether the IQueue could not be resolved this request.
	 *
	 * Avoids logging the same DEBUG message multiple times per request.
	 *
	 * @var boolean
	 */
	private static bool $queueUnavailable = false;

	/**
	 * Constructor.
	 *
	 * @param NotifyPushPlatform $platform Logger, container and app config.
	 * @param ObjectReadersInterface $permissionHandler RBAC handler for reader-list resolution.
	 * @param PushSlugResolver $slugs Resolves register and schema slugs as the system.
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-4
	 */
	public function __construct(
		private readonly NotifyPushPlatform $platform,
		private readonly ObjectReadersInterface $permissionHandler,
		private readonly PushSlugResolver $slugs,
	) {

	}//end __construct()

	/**
	 * Handle an object lifecycle event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @listener-placement inline realtime — it is the realtime push channel, and its per-request static
	 *   state (`$seen`, `$batchMode`, `$batchedCollections`) assumes one request lifetime.
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-4
	 */
	public function handle(Event $event): void {
		$resolved = $this->resolveEventAction(event: $event);
		if ($resolved === null) {
			return;
		}

		[$action, $object] = $resolved;

		// Lazy-resolve IQueue; soft-fail if notify_push is not installed.
		$queue = $this->resolveQueue();
		if ($queue === null || $this->firstSighting(action: $action, object: $object) === false) {
			return;
		}

		$run = new NotifyPushRun();
		$run->queue = $queue;
		$run->action = $action;
		$run->object = $object;
		$this->route(run: $run);

	}//end handle()

	/**
	 * Accumulate the object's collection in batch mode, else push it now.
	 *
	 * @param NotifyPushRun $run The queue, action and object.
	 *
	 * @return void
	 */
	private function route(NotifyPushRun $run): void {
		if (self::$batchMode === true) {
			$this->accumulateBatchEntry(object: $run->object);
			return;
		}

		$this->dispatchPushes(run: $run);
	}//end route()

	/**
	 * Record an (uuid, action) pair; false when it has no uuid or was seen this request.
	 *
	 * Per-request deduplication — same (uuid, action) within one request fires once.
	 *
	 * @param string       $action Action verb.
	 * @param ObjectEntity $object The affected object entity.
	 *
	 * @return boolean True the first time the pair is seen.
	 */
	private function firstSighting(string $action, ObjectEntity $object): bool {
		$uuid = $object->getUuid();
		if ($uuid === null || $uuid === '') {
			return false;
		}

		$dedupKey = $uuid . '|' . $action;
		if (isset(self::$seen[$dedupKey]) === true) {
			return false;
		}

		self::$seen[$dedupKey] = true;
		return true;
	}//end firstSighting()

	/**
	 * Resolve the action verb and object entity from a lifecycle event.
	 *
	 * Returns null for unrecognised event types so handle() can early-return.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: string, 1: ObjectEntity}|null Tuple of [action, object], or null.
	 */
	private function resolveEventAction(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent) {
			return ['create', $event->getObject()];
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return ['update', $event->getObject()];
		}

		if ($event instanceof ObjectDeletedEvent) {
			return ['delete', $event->getObject()];
		}

		return null;
	}//end resolveEventAction()

	/**
	 * Accumulate a (register-slug, schema-slug) pair during batch mode.
	 *
	 * @param ObjectEntity $object The object whose slugs should be accumulated.
	 *
	 * @return void
	 */
	private function accumulateBatchEntry(ObjectEntity $object): void {
		$registerSlug = $this->slugs->registerSlug(registerUuid: $object->getRegister());
		$schemaSlug = $this->slugs->schemaSlug(schemaUuid: $object->getSchema());

		if ($registerSlug !== null && $schemaSlug !== null) {
			$collectionKey = $registerSlug . '|' . $schemaSlug;
			self::$batchedCollections[$collectionKey] = [
				'register' => $registerSlug,
				'schema' => $schemaSlug,
			];
		}
	}//end accumulateBatchEntry()

	/**
	 * Dispatch notify_push events for a single object lifecycle action.
	 *
	 * Emits:
	 *   - `or-object-{uuid}` per authorised user (all actions)
	 *   - `or-collection-{register-slug}-{schema-slug}` per authorised user (create/delete only)
	 *
	 * @param NotifyPushRun $run The queue, action (`create`, `update`, `delete`) and object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-4
	 */
	private function dispatchPushes(NotifyPushRun $run): void {
		$run->payload = $this->lifecyclePayload(action: $run->action, object: $run->object);
		$run->readers = $this->permissionHandler->getReadableByUsers(object: $run->object);
		$pushed = $this->pushToUsers(run: $run, channel: PushChannels::OR_OBJECT . '-' . $run->payload['uuid']);

		$collectionChannel = $this->collectionChannel(payload: $run->payload);
		if ($collectionChannel !== null) {
			$pushed = ($this->pushToUsers(run: $run, channel: $collectionChannel) || $pushed);
		}

		$this->markPushAvailable(pushed: $pushed);

	}//end dispatchPushes()

	/**
	 * The body of a lifecycle push: action, slugs, uuid and version.
	 *
	 * @param string       $action Action verb (`create`, `update`, `delete`).
	 * @param ObjectEntity $object The affected object entity.
	 *
	 * @return array{action: string, register: string|null, schema: string|null, uuid: string|null, version: mixed}
	 */
	private function lifecyclePayload(string $action, ObjectEntity $object): array {
		return [
			'action' => $action,
			'register' => $this->slugs->registerSlug(registerUuid: $object->getRegister()),
			'schema' => $this->slugs->schemaSlug(schemaUuid: $object->getSchema()),
			'uuid' => $object->getUuid(),
			'version' => $object->getVersion(),
		];
	}//end lifecyclePayload()

	/**
	 * The collection channel a lifecycle push is also sent on.
	 *
	 * Emit collection event on create and delete (not on update to avoid noise),
	 * and only when both slugs resolved.
	 *
	 * @param array{action: string, register: string|null, schema: string|null, uuid: string|null, version: mixed} $payload The push body.
	 *
	 * @return string|null Null when the push has no collection channel.
	 */
	private function collectionChannel(array $payload): ?string {
		if (in_array($payload['action'], ['create', 'delete'], true) === false
			|| $payload['register'] === null
			|| $payload['schema'] === null
		) {
			return null;
		}

		return PushChannels::OR_COLLECTION . '-' . $payload['register'] . '-' . $payload['schema'];
	}//end collectionChannel()

	/**
	 * Push one message to each of the run's readers on one channel.
	 *
	 * @param NotifyPushRun $run     The queue, the readers and the body.
	 * @param string        $channel The channel.
	 *
	 * @return boolean True when at least one push was queued.
	 */
	private function pushToUsers(NotifyPushRun $run, string $channel): bool {
		$pushed = false;
		foreach ($run->readers as $userId) {
			$run->queue->push(
				'notify_custom',
				[
					'user' => $userId,
					'message' => $channel,
					'body' => $run->payload,
				]
			);
			$pushed = true;
		}

		return $pushed;
	}//end pushToUsers()

	/**
	 * On first successful push, flag the app config so admin UI shows "active".
	 *
	 * @param boolean $pushed Whether anything was pushed.
	 *
	 * @return void
	 */
	private function markPushAvailable(bool $pushed): void {
		if ($pushed === true && $this->platform->appConfig->getValueString('openregister', 'push_available', '') !== '1') {
			$this->platform->appConfig->setValueString('openregister', 'push_available', '1');
		}
	}//end markPushAvailable()

	/**
	 * Enable or disable batch mode.
	 *
	 * When batch mode is active, handle() accumulates (register, schema) pairs
	 * instead of pushing individual object events. Call flushBatch() to emit
	 * one collection event per pair and clear the accumulator.
	 *
	 * @param bool $enabled True to enable batch mode, false to disable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-4
	 */
	public static function setBatchMode(bool $enabled): void {
		self::$batchMode = $enabled;
		if ($enabled === false) {
			self::$batchedCollections = [];
		}
	}//end setBatchMode()

	/**
	 * Reset all static state.
	 *
	 * Intended for test isolation only. Resets batch mode, accumulated
	 * collections, deduplication accumulator, and the queue-unavailable flag.
	 *
	 * @return void
	 *
	 * @internal For use in unit tests only.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public static function resetStaticState(): void {
		self::$batchMode = false;
		self::$batchedCollections = [];
		self::$seen = [];
		self::$queueUnavailable = false;
	}//end resetStaticState()

	/**
	 * Whether any (register, schema) pairs have been accumulated in batch mode.
	 *
	 * Lets import callers skip IQueue resolution entirely when nothing was
	 * accumulated (e.g. notify_push not installed, so handle() never reached
	 * the accumulator) — avoiding even the one DEBUG log of a failed resolve.
	 *
	 * @return bool True when at least one collection event is pending flush.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public static function hasBatchedCollections(): bool {
		return self::$batchedCollections !== [];
	}//end hasBatchedCollections()

	/**
	 * Accumulate a (register-slug, schema-slug) pair directly, bypassing event dispatch.
	 *
	 * Bulk saves run with lifecycle events DISABLED by default (`events=false`
	 * throughout ImportService / SaveObjects), so handle() never fires during a
	 * standard import and the accumulator would stay empty. Import callers that
	 * already know which collection changed use this entry point to queue the
	 * collection hint themselves; flushBatch() then broadcasts it. Duplicate
	 * pairs collapse onto the same accumulator key — safe to combine with
	 * event-driven accumulation when events ARE enabled.
	 *
	 * @param string $registerSlug The register slug (empty = ignored).
	 * @param string $schemaSlug The schema slug (empty = ignored).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public static function addBatchedCollection(string $registerSlug, string $schemaSlug): void {
		if ($registerSlug === '' || $schemaSlug === '') {
			return;
		}

		self::$batchedCollections[$registerSlug . '|' . $schemaSlug] = [
			'register' => $registerSlug,
			'schema' => $schemaSlug,
		];
	}//end addBatchedCollection()

	/**
	 * Emit one collection event per accumulated (register, schema) pair and clear state.
	 *
	 * Should be called in a `finally` block after a bulk-import loop, BEFORE
	 * setBatchMode(false) — disabling batch mode clears the accumulator:
	 * ```php
	 * NotifyPushListener::setBatchMode(true);
	 * try {
	 *     // ... import loop
	 * } finally {
	 *     NotifyPushListener::flushBatch($queue);
	 *     NotifyPushListener::setBatchMode(false);
	 * }
	 * ```
	 *
	 * The flush is a broadcast: collection events carry no per-user targeting
	 * (payload is slugs + action only); clients refetch through the RBAC-filtered
	 * REST API, so no data can leak to unauthorised subscribers.
	 *
	 * @param object $queue Resolved IQueue instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public static function flushBatch(object $queue): void {
		foreach (self::$batchedCollections as $entry) {
			self::broadcastCollection(queue: $queue, entry: $entry);
		}

		self::$batchedCollections = [];
		self::$seen = [];

	}//end flushBatch()

	/**
	 * Broadcast one accumulated collection change.
	 *
	 * Batch flush is a collection-wide signal that does not target a specific
	 * reader set. We omit the `user` field; notify_push treats this as a
	 * broadcast to every connected client subscribed to the collection
	 * channel. Clients that don't have access to the underlying objects
	 * simply ignore the event when they refetch and the API returns an
	 * empty page.
	 *
	 * @param object                                 $queue Resolved IQueue instance.
	 * @param array{register: string, schema: string} $entry The accumulated pair.
	 *
	 * @return void
	 */
	private static function broadcastCollection(object $queue, array $entry): void {
		$queue->push(
			'notify_custom',
			[
				'message' => PushChannels::OR_COLLECTION . '-' . $entry['register'] . '-' . $entry['schema'],
				'body' => [
					'action' => 'batch',
					'register' => $entry['register'],
					'schema' => $entry['schema'],
				],
			]
		);
	}//end broadcastCollection()

	/**
	 * Lazily resolve the IQueue from the container.
	 *
	 * Returns null and logs one DEBUG message per request when notify_push is
	 * not installed or not reachable. Never logs WARNING/ERROR.
	 *
	 * @return object|null The IQueue instance, or null when unavailable.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	private function resolveQueue(): ?object {
		if (self::$queueUnavailable === true) {
			return null;
		}

		try {
			return $this->platform->container->get('OCA\NotifyPush\Queue\IQueue');
		} catch (\Throwable $e) {
			$this->markQueueUnavailable(error: $e);
			return null;
		}
	}//end resolveQueue()

	/**
	 * Remember for the request that the queue is missing, and say so once at DEBUG.
	 *
	 * @param \Throwable $error Why the queue could not be resolved.
	 *
	 * @return void
	 */
	private function markQueueUnavailable(\Throwable $error): void {
		self::$queueUnavailable = true;
		$this->platform->logger->debug(
			message: '[NotifyPushListener] notify_push IQueue not available; push disabled for this request',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $error->getMessage(),
			]
		);
	}//end markQueueUnavailable()

	/**
	 * Push that the readers of an object changed.
	 *
	 * 🔴 ON CHANGE ONLY (D-2). This is called on an arrival, a departure and an
	 * expiry, and NEVER on a renewal that changed nothing. Twenty readers on
	 * one page are twenty writes a minute, which is nothing, and would be twenty
	 * pushes a minute to twenty clients, which is not.
	 *
	 * 🔴 THE AUDIENCE IS THE OBJECT'S (D-3). `getReadableByUsers()` is the same
	 * resolution a lifecycle push takes, so presence can never tell somebody
	 * that an object exists, or that colleagues are interested in it, when they
	 * may not read the object itself.
	 *
	 * Soft-fails, like every other push here: an instance without notify_push
	 * simply has no live presence, and the list still answers on request.
	 *
	 * @param ObjectEntity                     $object  The object whose readers changed.
	 * @param array<int, array<string, mixed>> $present Who is present now.
	 *
	 * @return boolean True when at least one push was queued.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	public function pushPresence(ObjectEntity $object, array $present): bool {
		$uuid = $object->getUuid();
		if ($uuid === null || $uuid === '') {
			return false;
		}

		$queue = $this->resolveQueue();
		if ($queue === null) {
			return false;
		}

		$run = new NotifyPushRun();
		$run->queue = $queue;
		$run->action = 'presence';
		$run->object = $object;
		$run->payload = ['action' => 'presence', 'uuid' => $uuid, 'present' => array_values($present)];
		$run->readers = $this->permissionHandler->getReadableByUsers(object: $object);

		return $this->pushToUsers(run: $run, channel: PushChannels::OR_OBJECT . '-' . $uuid);
	}//end pushPresence()
}//end class
