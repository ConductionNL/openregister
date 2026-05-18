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
 *  - PermissionHandler::getReadableByUsers(ObjectEntity) → array<string>
 *    Returns user IDs that can read the object (empty array = all users / broadcast).
 *  - IQueue is resolved lazily from the container (OCA\NotifyPush\Queue\IQueue).
 *    If notify_push is not installed the container throws — soft-fail at most once.
 *  - Registration site: registerEventListeners() in Application.php, next to
 *    the GraphQLSubscriptionListener registrations.
 *  - IAppConfig key `openregister.push_available` is set to '1' on the first
 *    successful IQueue::push() call (consumed by OpenRegisterAdmin::getPushStatus()).
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Push\PushEvents;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
 */
class NotifyPushListener implements IEventListener
{

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
     * @param IAppManager        $appManager        Nextcloud app manager (notify_push install check).
     * @param LoggerInterface    $logger            PSR-3 logger.
     * @param ContainerInterface $container         DI container for lazy IQueue resolution.
     * @param PermissionHandler  $permissionHandler RBAC handler for reader-list resolution.
     * @param IAppConfig         $appConfig         App config for push_available flag.
     * @param RegisterMapper     $registerMapper    Mapper for resolving register slugs.
     * @param SchemaMapper       $schemaMapper      Mapper for resolving schema slugs.
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-4
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly PermissionHandler $permissionHandler,
        private readonly IAppConfig $appConfig,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
    ) {
    }//end __construct()

    /**
     * Handle an object lifecycle event.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-4
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent) {
            $action = 'create';
            $object = $event->getObject();
        } else if ($event instanceof ObjectUpdatedEvent) {
            $action = 'update';
            $object = $event->getObject();
        } else if ($event instanceof ObjectDeletedEvent) {
            $action = 'delete';
            $object = $event->getObject();
        } else {
            return;
        }

        // Lazy-resolve IQueue; soft-fail if notify_push is not installed.
        $queue = $this->resolveQueue();
        if ($queue === null) {
            return;
        }

        $uuid = $object->getUuid();
        if ($uuid === null || $uuid === '') {
            return;
        }

        // Per-request deduplication — same (uuid, action) within one request fires once.
        $dedupKey = $uuid.'|'.$action;
        if (isset(self::$seen[$dedupKey]) === true) {
            return;
        }

        self::$seen[$dedupKey] = true;

        if (self::$batchMode === true) {
            // Accumulate (register-slug, schema-slug) pairs; suppress per-object push.
            $registerSlug = $this->resolveRegisterSlug(registerUuid: $object->getRegister());
            $schemaSlug   = $this->resolveSchemaSlug(schemaUuid: $object->getSchema());

            if ($registerSlug !== null && $schemaSlug !== null) {
                $collectionKey = $registerSlug.'|'.$schemaSlug;
                self::$batchedCollections[$collectionKey] = [
                    'register' => $registerSlug,
                    'schema'   => $schemaSlug,
                ];
            }

            return;
        }

        $this->dispatchPushes(action: $action, object: $object, queue: $queue);

    }//end handle()

    /**
     * Dispatch notify_push events for a single object lifecycle action.
     *
     * Emits:
     *   - `or-object-{uuid}` per authorised user (all actions)
     *   - `or-collection-{register-slug}-{schema-slug}` per authorised user (create/delete only)
     *
     * @param string       $action Action verb (`create`, `update`, `delete`).
     * @param ObjectEntity $object The affected object entity.
     * @param object       $queue  Resolved IQueue instance.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-4
     */
    private function dispatchPushes(string $action, ObjectEntity $object, object $queue): void
    {
        $uuid         = $object->getUuid();
        $registerSlug = $this->resolveRegisterSlug(registerUuid: $object->getRegister());
        $schemaSlug   = $this->resolveSchemaSlug(schemaUuid: $object->getSchema());
        $version      = $object->getVersion();

        $payload = [
            'action'   => $action,
            'register' => $registerSlug,
            'schema'   => $schemaSlug,
            'uuid'     => $uuid,
            'version'  => $version,
        ];

        $userIds = $this->permissionHandler->getReadableByUsers(object: $object);

        $pushed        = false;
        $objectChannel = PushEvents::OR_OBJECT.'-'.$uuid;

        foreach ($userIds as $userId) {
            $queue->push(
                    'notify_custom',
                    [
                        'user'    => $userId,
                        'message' => $objectChannel,
                        'body'    => $payload,
                    ]
                    );
            $pushed = true;
        }

        // Emit collection event on create and delete (not on update to avoid noise).
        if (($action === 'create' || $action === 'delete')
            && $registerSlug !== null
            && $schemaSlug !== null
        ) {
            $collectionChannel = PushEvents::OR_COLLECTION.'-'.$registerSlug.'-'.$schemaSlug;
            foreach ($userIds as $userId) {
                $queue->push(
                        'notify_custom',
                        [
                            'user'    => $userId,
                            'message' => $collectionChannel,
                            'body'    => $payload,
                        ]
                        );
                $pushed = true;
            }
        }

        // On first successful push, flag the app config so admin UI shows "active".
        if ($pushed === true && $this->appConfig->getValueString('openregister', 'push_available', '') !== '1') {
            $this->appConfig->setValueString('openregister', 'push_available', '1');
        }

    }//end dispatchPushes()

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
    public static function setBatchMode(bool $enabled): void
    {
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
     */
    public static function resetStaticState(): void
    {
        self::$batchMode          = false;
        self::$batchedCollections = [];
        self::$seen = [];
        self::$queueUnavailable = false;
    }//end resetStaticState()

    /**
     * Emit one collection event per accumulated (register, schema) pair and clear state.
     *
     * Should be called in a `finally` block after a bulk-import loop:
     * ```php
     * NotifyPushListener::setBatchMode(true);
     * try {
     *     // ... import loop
     * } finally {
     *     NotifyPushListener::flushBatch($queue, $permissionHandler);
     *     NotifyPushListener::setBatchMode(false);
     * }
     * ```
     *
     * @param object            $queue       Resolved IQueue instance.
     * @param PermissionHandler $permHandler Permission handler for user resolution.
     *
     * @return void
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-4
     */
    public static function flushBatch(object $queue, PermissionHandler $permHandler): void
    {
        foreach (self::$batchedCollections as $entry) {
            $registerSlug = $entry['register'];
            $schemaSlug   = $entry['schema'];

            $payload = [
                'action'   => 'batch',
                'register' => $registerSlug,
                'schema'   => $schemaSlug,
            ];

            $collectionChannel = PushEvents::OR_COLLECTION.'-'.$registerSlug.'-'.$schemaSlug;

            // Batch flush is a collection-wide signal that does not target a specific
            // reader set. We omit the `user` field; notify_push treats this as a
            // broadcast to every connected client subscribed to the collection
            // channel. Clients that don't have access to the underlying objects
            // simply ignore the event when they refetch and the API returns an
            // empty page.
            $queue->push(
                    'notify_custom',
                    [
                        'message' => $collectionChannel,
                        'body'    => $payload,
                    ]
                    );
        }//end foreach

        self::$batchedCollections = [];
        self::$seen = [];

    }//end flushBatch()

    /**
     * Lazily resolve the IQueue from the container.
     *
     * Returns null and logs one DEBUG message per request when notify_push is
     * not installed or not reachable. Never logs WARNING/ERROR.
     *
     * @return object|null The IQueue instance, or null when unavailable.
     */
    private function resolveQueue(): ?object
    {
        if (self::$queueUnavailable === true) {
            return null;
        }

        try {
            return $this->container->get('OCA\NotifyPush\Queue\IQueue');
        } catch (\Throwable $e) {
            self::$queueUnavailable = true;
            $this->logger->debug(
                message: '[NotifyPushListener] notify_push IQueue not available; push disabled for this request',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end resolveQueue()

    /**
     * Resolve a register's slug from its UUID.
     *
     * @param string|null $registerUuid The register UUID from ObjectEntity::getRegister().
     *
     * @return string|null The register slug, or null when not resolvable.
     */
    private function resolveRegisterSlug(?string $registerUuid): ?string
    {
        if ($registerUuid === null || $registerUuid === '') {
            return null;
        }

        try {
            // System-internal lookup: bypass RBAC + multitenancy. The listener
            // is system-level, not user-scoped — without the bypass the lookup
            // throws "Register not found" whenever the request user's tenant
            // doesn't own the register, leaving the push payload's slug fields
            // null (issue #1454).
            $register = $this->registerMapper->find(
                id: $registerUuid,
                _rbac: false,
                _multitenancy: false
            );
            return $register->getSlug();
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveRegisterSlug()

    /**
     * Resolve a schema's slug from its UUID.
     *
     * @param string|null $schemaUuid The schema UUID from ObjectEntity::getSchema().
     *
     * @return string|null The schema slug, or null when not resolvable.
     */
    private function resolveSchemaSlug(?string $schemaUuid): ?string
    {
        if ($schemaUuid === null || $schemaUuid === '') {
            return null;
        }

        try {
            // System-internal lookup: bypass RBAC + multitenancy. Same reason
            // as resolveRegisterSlug above — without the bypass cross-tenant
            // events leave the schema slug null in the push body (issue #1454).
            $schema = $this->schemaMapper->find(
                id: $schemaUuid,
                _rbac: false,
                _multitenancy: false
            );
            return $schema->getSlug();
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveSchemaSlug()
}//end class
