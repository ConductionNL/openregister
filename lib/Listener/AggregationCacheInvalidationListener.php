<?php

/**
 * OpenRegister AggregationCacheInvalidationListener
 *
 * Subscribes to ObjectCreatedEvent / ObjectUpdatedEvent /
 * ObjectDeletedEvent / ObjectTransitionedEvent and evicts the
 * AggregationCache so the next aggregation read recomputes against
 * fresh data. The 60s TTL bounds staleness even when an evict is missed.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;

/**
 * Listener that evicts aggregation caches on object lifecycle changes.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 */
class AggregationCacheInvalidationListener implements IEventListener {
	/**
	 * Wire the aggregation cache used for evictions.
	 *
	 * The mappers are resolved from the container when an event arrives, not
	 * injected, because RegisterMapper and MagicMapper reach each other at
	 * construction time.
	 *
	 * @param AggregationCache $cache Cache holding aggregation read-models.
	 * @param ContainerInterface|null $container Resolves the register and schema mappers for slug lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
	 */
	public function __construct(
		private readonly AggregationCache $cache,
		private readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()

	/**
	 * Evict the aggregation cache for the schema referenced by the event.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
	 * @spec openspec/specs/aggregations-backend-native/spec.md#requirement-an-object-write-must-evict-the-aggregations-of-its-schema
	 */
	public function handle(Event $event): void {
		$object = $this->extractObject(event: $event);
		if ($object === null) {
			return;
		}

		// An object carries its register and schema as ids ("20", "34"), while
		// AggregationCache keys its entries and version counters by slug
		// ("pipelinq", "leadProduct"). Evicting by id bumped a counter nothing
		// reads, so a write never evicted and counts stayed stale for the TTL.
		$this->cache->evictForSchema(
			registerSlug: $this->slugOf(reference: (string)$object->getRegister(), mapperClass: RegisterMapper::class),
			schemaSlug: $this->slugOf(reference: (string)$object->getSchema(), mapperClass: SchemaMapper::class)
		);
	}//end handle()

	/**
	 * The slug for a register or schema reference, as AggregationCache keys it.
	 *
	 * A reference that is not numeric is taken to be a slug already. When the
	 * lookup fails the reference is returned unchanged.
	 *
	 * @param string $reference The id or slug stored on the object.
	 * @param string $mapperClass RegisterMapper::class or SchemaMapper::class.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md#requirement-an-object-write-must-evict-the-aggregations-of-its-schema
	 */
	private function slugOf(string $reference, string $mapperClass): string {
		if ($this->container === null || is_numeric($reference) === false) {
			return $reference;
		}

		try {
			// Both mappers take named rbac and multitenancy flags on find().
			$entity = $this->container->get($mapperClass)->find(
				id: (int)$reference,
				_rbac: false,
				_multitenancy: false
			);
			$slug = $entity->getSlug();
		} catch (\Throwable $e) {
			return $reference;
		}

		if (is_string($slug) === false || $slug === '') {
			return $reference;
		}

		return $slug;
	}//end slugOf()

	/**
	 * Resolve the underlying object for any of the supported event types.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return ObjectEntity|null Object instance, or null when not resolvable.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-20
	 */
	private function extractObject(Event $event): ?ObjectEntity {
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
