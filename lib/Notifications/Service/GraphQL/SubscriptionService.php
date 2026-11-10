<?php

/**
 * GraphQL Subscription Service
 *
 * Manages server-sent event (SSE) subscriptions for GraphQL real-time updates.
 * Bridges OpenRegister's event system to GraphQL subscription delivery.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */

namespace OCA\OpenRegister\Notifications\Service\GraphQL;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;

/**
 * Manages GraphQL subscriptions delivered via Server-Sent Events (SSE).
 *
 * Events are buffered in APCu with a 5-minute retention window.
 * Each event includes schema/register context for client-side filtering.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SubscriptionService {

	/**
	 * APCu key prefix for the event buffer.
	 */
	private const EVENT_BUFFER_KEY = 'openregister_graphql_events';

	/**
	 * Maximum event buffer size.
	 */
	private const MAX_BUFFER_SIZE = 1000;

	/**
	 * Event retention in seconds (5 minutes).
	 */
	private const EVENT_TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema mapper
	 * @param PermissionCheckInterface $permissionHandler Permission handler
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly PermissionCheckInterface $permissionHandler,
	) {
	}//end __construct()

	/**
	 * Push an object event to the subscription buffer.
	 *
	 * Called by the GraphQL event listener when objects are created/updated/deleted.
	 *
	 * @param string $action The action (create, update, delete)
	 * @param ObjectEntity $object The affected object
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function pushEvent(string $action, ObjectEntity $object): void {
		if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
			return;
		}

		$event = [
			'id' => uniqid('gql_', true),
			'action' => $action,
			'timestamp' => time(),
			'object' => [
				'uuid' => $object->getUuid(),
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
				'owner' => $object->getOwner(),
			],
		];

		// Include object data for create/update (not for delete).
		if ($action !== 'delete') {
			$event['object']['data'] = $object->getObject();
		}

		// Append to buffer.
		$buffer = apcu_fetch(self::EVENT_BUFFER_KEY);
		if ($buffer === false) {
			$buffer = [];
		}

		$buffer[] = $event;

		// Trim old events and cap buffer size.
		$cutoff = (time() - self::EVENT_TTL);
		$buffer = array_filter(
			$buffer,
			fn ($e) => $e['timestamp'] >= $cutoff
		);
		$buffer = array_slice($buffer, -self::MAX_BUFFER_SIZE);

		apcu_store(self::EVENT_BUFFER_KEY, $buffer, self::EVENT_TTL);

	}//end pushEvent()

	/**
	 * Get events since a given event ID, filtered by schema and RBAC.
	 *
	 * @param string|null $lastEventId The last event ID the client received
	 * @param int|null $schemaId Optional schema ID filter
	 * @param int|null $registerId Optional register ID filter
	 *
	 * @return array<array<string, mixed>> The events the user is allowed to see
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function getEventsSince(?string $lastEventId = null, ?int $schemaId = null, ?int $registerId = null): array {
		return $this->eventsMatching(filter: new EventStreamFilter(lastEventId: $lastEventId, schemaId: $schemaId, registerId: $registerId));
	}//end getEventsSince()

	/**
	 * The buffered events after the filter's last event, narrowed by it, that the user may see.
	 *
	 * @param EventStreamFilter $filter Where the client resumes, and the schema and register it narrows to.
	 *
	 * @return array<array<string, mixed>> The events the user is allowed to see
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function eventsMatching(EventStreamFilter $filter): array {
		if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
			return [];
		}

		$buffer = apcu_fetch(self::EVENT_BUFFER_KEY);
		if ($buffer === false || empty($buffer) === true) {
			return [];
		}

		$events = [];
		foreach ($this->filterEventStream(buffer: $buffer, filter: $filter) as $event) {
			if ($this->verifyEventRBAC(event: $event) === true) {
				$events[] = $event;
			}
		}

		return $events;
	}//end eventsMatching()

	/**
	 * Filter event buffer by last event ID and schema/register filters.
	 *
	 * @param array             $buffer The full event buffer
	 * @param EventStreamFilter $filter The last event ID the client received, and the schema and register filters
	 *
	 * @return array The filtered events
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	private function filterEventStream(array $buffer, EventStreamFilter $filter): array {
		$foundLastId = ($filter->lastEventId === null);
		$events = [];

		foreach ($buffer as $event) {
			if ($foundLastId === false) {
				$foundLastId = ($event['id'] === $filter->lastEventId);
				continue;
			}

			if ($this->admits(filter: $filter, object: ($event['object'] ?? [])) === true) {
				$events[] = $event;
			}
		}

		return $events;
	}//end filterEventStream()

	/**
	 * Whether an event's object falls inside the filter's schema and register.
	 *
	 * @param EventStreamFilter    $filter The filter.
	 * @param array<string, mixed> $object The event's object summary.
	 *
	 * @return bool
	 */
	private function admits(EventStreamFilter $filter, array $object): bool {
		if ($filter->schemaId !== null && ($object['schema'] ?? null) !== $filter->schemaId) {
			return false;
		}

		return ($filter->registerId === null || ($object['register'] ?? null) === $filter->registerId);
	}//end admits()

	/**
	 * Verify RBAC permissions for a single event.
	 *
	 * @param array $event The event to check
	 *
	 * @return bool True if the current user can see this event
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	private function verifyEventRBAC(array $event): bool {
		$eventSchemaId = ($event['object']['schema'] ?? null);
		if ($eventSchemaId === null) {
			return true;
		}

		try {
			$schema = $this->schemaMapper->find($eventSchemaId);
			return $this->permissionHandler->hasPermission($schema, 'read');
		} catch (\Exception $e) {
			return false;
		}

	}//end verifyEventRBAC()

	/**
	 * Format an event as an SSE message.
	 *
	 * @param array<string, mixed> $event The event data
	 *
	 * @return string The SSE-formatted message
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function formatAsSSE(array $event): string {
		$id = $event['id'];
		$type = 'graphql.' . $event['action'];
		$data = json_encode($event);

		return "id: $id\nevent: $type\ndata: $data\n\n";
	}//end formatAsSSE()
}//end class
