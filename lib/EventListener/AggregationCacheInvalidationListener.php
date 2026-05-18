<?php

/**
 * AggregationCacheInvalidationListener
 *
 * Evicts the aggregation result cache whenever an object is written (created,
 * updated, or deleted) in the associated register/schema. Uses coarse-grained
 * ICache::clear() because the underlying Memcached / Redis backend has no
 * prefix-delete; the 60-second TTL bounds maximum staleness.
 *
 * @category EventListener
 * @package  OCA\OpenRegister\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Evicts the aggregation cache on every object write event.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
 */
class AggregationCacheInvalidationListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param AggregationCache $cache  Aggregation result cache.
     * @param LoggerInterface  $logger Logger.
     */
    public function __construct(
        private readonly AggregationCache $cache,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle an object lifecycle event and evict the aggregation cache.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent
            || $event instanceof ObjectUpdatedEvent
            || $event instanceof ObjectDeletedEvent) === false
        ) {
            return;
        }

        $this->logger->debug(
            message: '[AggregationCacheInvalidationListener] Evicting aggregation cache on object write.',
            context: ['file' => __FILE__, 'line' => __LINE__, 'event' => get_class($event)]
        );

        $this->cache->evict();
    }//end handle()
}//end class
