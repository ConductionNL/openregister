<?php

/**
 * GraphQL Subscription Service
 *
 * Manages server-sent event (SSE) subscriptions for GraphQL real-time updates.
 * Bridges OpenRegister's event system to GraphQL subscription delivery.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Service\GraphQL;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
class SubscriptionService
{

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
     * @param SchemaMapper      $schemaMapper      Schema mapper
     * @param PermissionHandler $permissionHandler Permission handler
     * @param IUserSession      $userSession       User session
     * @param LoggerInterface   $logger            Logger
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly PermissionHandler $permissionHandler,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Push an object event to the subscription buffer.
     *
     * Called by the GraphQL event listener when objects are created/updated/deleted.
     *
     * @param string       $action The action (create, update, delete)
     * @param ObjectEntity $object The affected object
     *
     * @return void
     */
    public function pushEvent(string $action, ObjectEntity $object): void
    {
        if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
            return;
        }

        $event = [
            'id'        => uniqid('gql_', true),
            'action'    => $action,
            'timestamp' => time(),
            'object'    => [
                'uuid'     => $object->getUuid(),
                'register' => $object->getRegister(),
                'schema'   => $object->getSchema(),
                'owner'    => $object->getOwner(),
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
     * @param int|null    $schemaId    Optional schema ID filter
     * @param int|null    $registerId  Optional register ID filter
     *
     * @return array<array<string, mixed>> The events the user is allowed to see
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) At threshold after extracting filterEventStream + verifyEventRBAC
     */
    public function getEventsSince(
        ?string $lastEventId=null,
        ?int $schemaId=null,
        ?int $registerId=null
    ): array {
        if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
            return [];
        }

        $buffer = apcu_fetch(self::EVENT_BUFFER_KEY);
        if ($buffer === false || empty($buffer) === true) {
            return [];
        }

        $filtered = $this->filterEventStream(
            buffer: $buffer,
            lastEventId: $lastEventId,
            schemaId: $schemaId,
            registerId: $registerId
        );

        $events = [];
        foreach ($filtered as $event) {
            if ($this->verifyEventRBAC(event: $event) === true) {
                $events[] = $event;
            }
        }

        return $events;

    }//end getEventsSince()

    /**
     * Filter event buffer by last event ID and schema/register filters.
     *
     * @param array       $buffer      The full event buffer
     * @param string|null $lastEventId The last event ID the client received
     * @param int|null    $schemaId    Optional schema ID filter
     * @param int|null    $registerId  Optional register ID filter
     *
     * @return array The filtered events
     */
    private function filterEventStream(
        array $buffer,
        ?string $lastEventId,
        ?int $schemaId,
        ?int $registerId
    ): array {
        $foundLastId = ($lastEventId === null);
        $events      = [];

        foreach ($buffer as $event) {
            if ($foundLastId === false) {
                if ($event['id'] === $lastEventId) {
                    $foundLastId = true;
                }

                continue;
            }

            if ($schemaId !== null && ($event['object']['schema'] ?? null) !== $schemaId) {
                continue;
            }

            if ($registerId !== null && ($event['object']['register'] ?? null) !== $registerId) {
                continue;
            }

            $events[] = $event;
        }//end foreach

        return $events;

    }//end filterEventStream()

    /**
     * Verify RBAC permissions for a single event.
     *
     * @param array $event The event to check
     *
     * @return bool True if the current user can see this event
     */
    private function verifyEventRBAC(array $event): bool
    {
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
     */
    public function formatAsSSE(array $event): string
    {
        $id   = $event['id'];
        $type = 'graphql.'.$event['action'];
        $data = json_encode($event);

        return "id: $id\nevent: $type\ndata: $data\n\n";

    }//end formatAsSSE()
}//end class
