<?php

/**
 * GraphQL Subscription Controller (SSE)
 *
 * Provides a Server-Sent Events endpoint for GraphQL subscriptions.
 * Clients connect via GET and receive real-time object change events.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-41
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\GraphQL\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

/**
 * SSE endpoint for GraphQL subscriptions.
 *
 * Supports:
 * - Schema filtering via ?schema={id}
 * - Register filtering via ?register={id}
 * - Reconnection via Last-Event-ID header
 *
 * @psalm-suppress UnusedClass - Registered via routes.php
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class GraphQLSubscriptionController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName             Application name
     * @param IRequest            $request             Request object
     * @param SubscriptionService $subscriptionService Subscription service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SubscriptionService $subscriptionService,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * SSE subscription endpoint.
     *
     * Streams object change events (create, update, delete) in real-time.
     * Filters by schema and register when specified.
     * Supports reconnection via Last-Event-ID header.
     *
     * @return Response SSE stream response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @CORS
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-41
     */
    public function subscribe(): Response
    {
        $schemaId   = $this->request->getParam('schema');
        $registerId = $this->request->getParam('register');
        $lastId     = $this->request->getHeader('Last-Event-ID');

        if ($schemaId !== null) {
            $schemaId = (int) $schemaId;
        }

        if ($registerId !== null) {
            $registerId = (int) $registerId;
        }

        $lastEventId = null;
        if (empty($lastId) === false) {
            $lastEventId = $lastId;
        }

        // Set SSE headers.
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Disable output buffering.
        if (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Send initial events (replay from buffer since lastEventId).
        $events = $this->subscriptionService->getEventsSince(
            $lastEventId,
            $schemaId,
            $registerId
        );

        foreach ($events as $event) {
            echo $this->subscriptionService->formatAsSSE($event);
        }

        // Send heartbeat comment to keep connection alive.
        echo ": heartbeat\n\n";
        flush();

        // Poll for new events (max 30 seconds to avoid PHP timeout).
        $startTime     = time();
        $maxDuration   = 30;
        $pollInterval  = 1;
        $currentLastId = $lastEventId;
        if (empty($events) === false) {
            $currentLastId = end($events)['id'];
        }

        while ((time() - $startTime) < $maxDuration) {
            sleep($pollInterval);

            $newEvents = $this->subscriptionService->getEventsSince(
                $currentLastId,
                $schemaId,
                $registerId
            );

            foreach ($newEvents as $event) {
                echo $this->subscriptionService->formatAsSSE($event);
                $currentLastId = $event['id'];
            }

            // Send heartbeat every poll interval.
            echo ": heartbeat\n\n";
            flush();

            // Check if client disconnected.
            if (connection_aborted() === 1) {
                break;
            }
        }//end while

        // Return empty response (output already sent via echo).
        return new Response();

    }//end subscribe()
}//end class
