<?php

/**
 * AggregationCacheInvalidationListener — evicts aggregation cache on object lifecycle events.
 *
 * Listens for ObjectCreatedEvent, ObjectUpdatedEvent, and ObjectDeletedEvent
 * and calls AggregationCache::evictForSchema() which flushes the entire
 * 'openregister_aggregations' distributed cache namespace.
 *
 * This coarse eviction strategy covers both named-aggregation entries and
 * ad-hoc entries because they share the same cache instance.  The 60 s TTL
 * ceiling on AggregationCache::TTL bounds residual staleness in the event
 * of a missed eviction.
 *
 * No additional listener changes are needed when the ad-hoc cache is
 * introduced (D4 from design.md): the existing ICache::clear() on
 * 'openregister_aggregations' already covers ad-hoc entries.
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
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.4
 *
 * @template-implements \OCP\EventDispatcher\IEventListener<
 *     \OCA\OpenRegister\Event\ObjectCreatedEvent|
 *     \OCA\OpenRegister\Event\ObjectUpdatedEvent|
 *     \OCA\OpenRegister\Event\ObjectDeletedEvent
 * >
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Evicts aggregation cache entries on every object lifecycle event.
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.4
 */
class AggregationCacheInvalidationListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param AggregationCache $cache  Aggregation cache service.
     * @param LoggerInterface  $logger Logger.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.4
     */
    public function __construct(
        private readonly AggregationCache $cache,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle object lifecycle events by evicting the aggregation cache.
     *
     * @param Event $event Object lifecycle event.
     *
     * @return void
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.4
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false
            && ($event instanceof ObjectUpdatedEvent) === false
            && ($event instanceof ObjectDeletedEvent) === false
        ) {
            return;
        }

        $object       = $event->getObject();
        $registerSlug = $object->getRegister() ?? '';
        $schemaSlug   = $object->getSchema() ?? '';

        $this->logger->debug(
            message: '[AggregationCacheInvalidationListener] Evicting aggregation cache',
            context: [
                'register' => $registerSlug,
                'schema'   => $schemaSlug,
                'event'    => $event::class,
            ]
        );

        $this->cache->evictForSchema(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
    }//end handle()
}//end class
