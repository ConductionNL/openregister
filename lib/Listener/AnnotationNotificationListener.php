<?php

/**
 * OpenRegister AnnotationNotificationListener
 *
 * Subscribes to ObjectCreatedEvent / ObjectUpdatedEvent /
 * ObjectTransitionedEvent and asks the dispatcher to fire any matching
 * notifications declared on the schema.
 *
 * Actor-forwarded deferral (openregister#408): rule evaluation and delivery
 * (which can perform synchronous outbound HTTP and mail) run in
 * AnnotationNotificationDispatchJob under the captured actor, so the
 * dispatcher's RBAC-scoped deeplink resolution evaluates as the user who
 * made the change. Inline remains: a request-cached schema-config gate
 * (schemas without `x-openregister-notifications` enqueue nothing) and the
 * full dispatch when the `listenerDeferral` kill switch is `inline`. Update
 * entries carry the pre-update data snapshot — the old state cannot be
 * re-fetched once the job runs; new data is re-read at run time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\AnnotationNotificationDispatchJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that fires schema-declared notifications on object events.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectTransitionedEvent>
 *
 * @spec openspec/changes/actor-forwarded-listener-jobs/tasks.md#task-2.2
 */
class AnnotationNotificationListener implements IEventListener
{

    /**
     * Entries per enqueued dispatch job. Smaller than the projection chunk
     * because `updated` entries carry pre-update data snapshots.
     *
     * @var integer
     */
    private const CHUNK_SIZE = 25;

    /**
     * Wire the notification dispatcher and the deferral contract.
     *
     * @param AnnotationNotificationDispatcher $dispatcher   Dispatcher used to fire notifications.
     * @param SchemaMapper                     $schemaMapper Schema lookup (request-cached) for the enqueue gate.
     * @param ListenerDeferralService          $deferral     Actor-forwarding deferral service.
     *
     * @return void
     */
    public function __construct(
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly SchemaMapper $schemaMapper,
        private readonly ListenerDeferralService $deferral
    ) {
    }//end __construct()

    /**
     * Defer (or dispatch inline) any matching annotation notifications.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectTransitionedEvent) {
            $this->handleTransitioned(event: $event);
            return;
        }

        if ($event instanceof ObjectCreatedEvent) {
            $object = $this->extractObject(event: $event);
            if ($object !== null && $this->schemaDeclaresNotifications(object: $object) === true) {
                if ($this->deferral->isDeferralEnabled() === false) {
                    $this->dispatcher->dispatch(object: $object, trigger: 'created');
                    return;
                }

                $this->deferEntry(object: $object, extra: ['trigger' => 'created']);
            }

            return;
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $this->handleUpdated(event: $event);
        }
    }//end handle()

    /**
     * Transition events: defer with the event-only context (action/from/to).
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     */
    private function handleTransitioned(ObjectTransitionedEvent $event): void
    {
        $object = $event->getObject();
        if ($this->schemaDeclaresNotifications(object: $object) === false) {
            return;
        }

        if ($this->deferral->isDeferralEnabled() === false) {
            $this->dispatcher->dispatch(
                object: $object,
                trigger: 'transition',
                context: [
                    'action' => $event->getAction(),
                    'from'   => $event->getFrom(),
                    'to'     => $event->getTo(),
                ]
            );
            return;
        }

        $this->deferEntry(
            object: $object,
            extra: [
                'trigger' => 'transition',
                'action'  => $event->getAction(),
                'from'    => $event->getFrom(),
                'to'      => $event->getTo(),
            ]
        );
    }//end handleTransitioned()

    /**
     * Update events: defer with the pre-update snapshot so field-change and
     * calculatedChange conditions can compare without re-reading versioned
     * history — the job re-fetches current data for `_newData` and fires the
     * same `updated` + `calculatedChange` pair the inline path fired.
     *
     * @param ObjectUpdatedEvent $event The update event.
     *
     * @return void
     */
    private function handleUpdated(ObjectUpdatedEvent $event): void
    {
        $newObject = $event->getNewObject();
        $oldObject = $event->getOldObject();
        if ($this->schemaDeclaresNotifications(object: $newObject) === false) {
            return;
        }

        if ($this->deferral->isDeferralEnabled() === false) {
            $this->dispatchUpdatedInline(newObject: $newObject, oldObject: $oldObject);
            return;
        }

        $oldData = null;
        if ($oldObject !== null) {
            $oldData = ($oldObject->getObject() ?? []);
        }

        $this->deferEntry(
            object: $newObject,
            extra: [
                'trigger' => 'updated',
                'oldData' => $oldData,
            ]
        );
    }//end handleUpdated()

    /**
     * Pre-deferral inline behaviour for updates (kill-switch path).
     *
     * Forward old/new data on the plain `updated` dispatch too (when an old
     * object is available) so rules declaring a field-change `condition` can
     * compare without re-reading versioned history; condition-less `updated`
     * rules ignore this context. Also evaluate calculatedChange rules when
     * both old and new objects are available.
     *
     * @param ObjectEntity      $newObject Persisted new state.
     * @param ObjectEntity|null $oldObject Pre-update state when available.
     *
     * @return void
     */
    private function dispatchUpdatedInline(ObjectEntity $newObject, ?ObjectEntity $oldObject): void
    {
        $updatedContext = [];
        if ($oldObject !== null) {
            $updatedContext = [
                '_newData' => ($newObject->getObject() ?? []),
                '_oldData' => ($oldObject->getObject() ?? []),
            ];
        }

        $this->dispatcher->dispatch(object: $newObject, trigger: 'updated', context: $updatedContext);

        if ($oldObject !== null) {
            $this->dispatcher->dispatch(
                object: $newObject,
                trigger: 'calculatedChange',
                context: [
                    '_newData' => ($newObject->getObject() ?? []),
                    '_oldData' => ($oldObject->getObject() ?? []),
                ]
            );
        }
    }//end dispatchUpdatedInline()

    /**
     * Buffer one dispatch entry on the deferral service.
     *
     * @param ObjectEntity         $object The triggering object.
     * @param array<string, mixed> $extra  Trigger-specific entry fields.
     *
     * @return void
     */
    private function deferEntry(ObjectEntity $object, array $extra): void
    {
        $this->deferral->defer(
            jobClass: AnnotationNotificationDispatchJob::class,
            entry: array_merge(
                [
                    'uuid'     => (string) $object->getUuid(),
                    'register' => (string) $object->getRegister(),
                    'schema'   => (string) $object->getSchema(),
                    'version'  => $object->getVersion(),
                ],
                $extra
            ),
            chunkSize: self::CHUNK_SIZE
        );
    }//end deferEntry()

    /**
     * Whether the object's schema declares any x-openregister-notifications.
     *
     * Cheap request-cached lookup + config-array check; schemas without the
     * annotation enqueue nothing. Resolution failures return false — the
     * dispatcher's own schema load would bail identically.
     *
     * @param ObjectEntity $object Object whose schema to inspect.
     *
     * @return bool True when notification rules are declared.
     */
    private function schemaDeclaresNotifications(ObjectEntity $object): bool
    {
        $schemaRef = (string) $object->getSchema();
        if ($schemaRef === '') {
            return false;
        }

        try {
            $schema = $this->schemaMapper->find($schemaRef);
        } catch (\Throwable $e) {
            return false;
        }

        $config        = ($schema->getConfiguration() ?? []);
        $notifications = ($config['x-openregister-notifications'] ?? null);

        return is_array($notifications) === true && count($notifications) > 0;
    }//end schemaDeclaresNotifications()

    /**
     * Different Object*Event classes expose the entity under different
     * accessors. Normalise to one.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return ObjectEntity|null Object instance, or null when none could be derived.
     */
    private function extractObject(Event $event): ?ObjectEntity
    {
        if (method_exists($event, 'getObject') === true) {
            $obj = $event->getObject();
            if ($obj instanceof ObjectEntity) {
                return $obj;
            }
        }

        if (method_exists($event, 'getNewObject') === true) {
            $obj = $event->getNewObject();
            if ($obj instanceof ObjectEntity) {
                return $obj;
            }
        }

        return null;
    }//end extractObject()
}//end class
