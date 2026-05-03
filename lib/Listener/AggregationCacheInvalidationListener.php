<?php

/**
 * OpenRegister AggregationCacheInvalidationListener
 *
 * Subscribes to ObjectCreatedEvent / ObjectUpdatedEvent /
 * ObjectDeletedEvent / ObjectTransitionedEvent and evicts the
 * AggregationCache so the next aggregation read recomputes against
 * fresh data. The 60s TTL bounds staleness even when an evict is missed.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that evicts aggregation caches on object lifecycle changes.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 */
class AggregationCacheInvalidationListener implements IEventListener
{
    /**
     * Wire the aggregation cache used for evictions.
     *
     * @param AggregationCache $cache Cache holding aggregation read-models.
     *
     * @return void
     */
    public function __construct(
        private readonly AggregationCache $cache
    ) {
    }//end __construct()

    /**
     * Evict the aggregation cache for the schema referenced by the event.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        $object = $this->extractObject(event: $event);
        if ($object === null) {
            return;
        }

        $this->cache->evictForSchema(
            registerSlug: (string) $object->getRegister(),
            schemaSlug: (string) $object->getSchema()
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
}//end class
