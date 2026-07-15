<?php

/**
 * OpenRegister AggregationThresholdListener
 *
 * Subscribes to object-write events and re-evaluates threshold-typed
 * notifications declared in `x-openregister-notifications` (evaluation logic
 * lives in ThresholdEvaluationService; rising-edge dedup in the distributed
 * cache).
 *
 * Actor-forwarded deferral (openregister#408): created/updated/transitioned
 * evaluations run in AggregationThresholdJob under the captured actor, with
 * entries deduped per (register, schema) so a bulk save of N objects of one
 * schema triggers ONE evaluation. Delete events evaluate inline — a
 * hard-deleted object cannot be re-fetched by the job, and delete-driven
 * crossings (`lt`/`lte` rules) would be silently lost. The `listenerDeferral`
 * kill switch restores full inline evaluation.
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\AggregationThresholdJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Aggregation\ThresholdEvaluationService;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that evaluates threshold-typed notifications on object writes.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 *
 * @spec openspec/changes/actor-forwarded-listener-jobs/tasks.md#task-2.3
 */
class AggregationThresholdListener implements IEventListener
{

    /**
     * Entries per enqueued evaluation job. Rarely reached thanks to the
     * (register, schema) dedupe key.
     *
     * @var integer
     */
    private const CHUNK_SIZE = 100;

    /**
     * Wire collaborators.
     *
     * @param SchemaMapper               $schemaMapper Schema lookup mapper.
     * @param ThresholdEvaluationService $evaluator    Shared threshold evaluation logic.
     * @param ListenerDeferralService    $deferral     Actor-forwarding deferral service.
     *
     * @return void
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly ThresholdEvaluationService $evaluator,
        private readonly ListenerDeferralService $deferral
    ) {
    }//end __construct()

    /**
     * Defer (or evaluate inline) the schema's threshold notifications.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    public function handle(Event $event): void
    {
        $object = $this->extractObject(event: $event);
        if ($object === null) {
            return;
        }

        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return;
        }

        if ($this->evaluator->hasThresholdNotifications($schema) === false) {
            return;
        }

        // Deletes evaluate inline: the entity is not re-fetchable once the
        // delete lands, so a deferred job could never dispatch for
        // delete-driven crossings. The kill switch also forces inline.
        if ($event instanceof ObjectDeletedEvent || $this->deferral->isDeferralEnabled() === false) {
            $this->evaluator->evaluateSchema(schema: $schema, object: $object);
            return;
        }

        $registerRef = (string) $object->getRegister();
        $schemaRef   = (string) $object->getSchema();
        $this->deferral->defer(
            jobClass: AggregationThresholdJob::class,
            entry: [
                'uuid'     => (string) $object->getUuid(),
                'register' => $registerRef,
                'schema'   => $schemaRef,
                'version'  => $object->getVersion(),
            ],
            chunkSize: self::CHUNK_SIZE,
            dedupeKey: $registerRef.'|'.$schemaRef
        );
    }//end handle()

    /**
     * Resolve the underlying object for any of the supported event types.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return ObjectEntity|null Object instance, or null when not resolvable.
     */
    private function extractObject(Event $event): ?ObjectEntity
    {
        if ($event instanceof ObjectTransitionedEvent) {
            return $event->getObject();
        }

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

    /**
     * Look up the schema referenced by an object instance.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $schemaRef = (string) $object->getSchema();
        if ($schemaRef === '') {
            return null;
        }

        // SchemaMapper resolves slug/uuid/id.
        try {
            return $this->schemaMapper->find($schemaRef);
        } catch (\Throwable $e) {
            return null;
        }
    }//end loadSchema()
}//end class
