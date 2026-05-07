<?php

/**
 * OpenRegister Realtime Controller
 *
 * Cursor-based polling endpoint for realtime change events.
 *   GET /apps/openregister/api/realtime/events?since={cursor}&limit=100
 *     &register=...&schema=...&objectUuid=...&eventType=...
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\RealtimeEventMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RealtimeController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName             The application name.
     * @param IRequest            $request             The current request.
     * @param RealtimeEventMapper $eventMapper         The realtime event mapper.
     * @param OrganisationService $organisationService The organisation service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RealtimeEventMapper $eventMapper,
        private readonly OrganisationService $organisationService
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Cursor-based polling. Returns events with `id > since`.
     *
     * Response shape:
     *   {
     *     events: CloudEvent[] (each with ._cursor: int),
     *     cursor: int (max event id in this response, or `since` if empty),
     *     hasMore: bool (true when limit was reached, hint to poll again sooner)
     *   }
     *
     * Anonymous/unauthenticated callers get HTTP 401. Authenticated
     * callers see only events scoped to their active organisation
     * (multi-tenancy gate). Cross-org events MUST NOT leak.
     *
     * @param int|null    $since      The cursor (event id) to fetch events after.
     * @param int|null    $limit      Maximum number of events to return.
     * @param string|null $register   Optional register filter.
     * @param string|null $schema     Optional schema filter.
     * @param string|null $objectUuid Optional object UUID filter.
     * @param string|null $eventType  Optional event type filter.
     *
     * @return JSONResponse JSON response with events, cursor, and hasMore.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function events(
        ?int $since=null,
        ?int $limit=100,
        ?string $register=null,
        ?string $schema=null,
        ?string $objectUuid=null,
        ?string $eventType=null
    ): JSONResponse {
        $effectiveLimit = max(1, min(1000, (int) ($limit ?? 100)));

        // Multi-tenancy: scope to active organisation. Anonymous callers
        // get an empty stream (no leak across tenants).
        $orgUuid = null;
        try {
            $activeOrg = $this->organisationService->getActiveOrganisation();
            $orgUuid   = $activeOrg?->getUuid();
        } catch (\Throwable $e) {
            $orgUuid = null;
        }

        if ($orgUuid === null) {
            // No active org → return an empty result rather than 500.
            // Tests + CLI dev scripts would otherwise crash unless the
            // session has resolved an org.
            return new JSONResponse(
                ['events' => [], 'cursor' => $since ?? 0, 'hasMore' => false]
            );
        }

        $filters = [
            'register'     => $register,
            'schema'       => $schema,
            'objectUuid'   => $objectUuid,
            'eventType'    => $eventType,
            'organisation' => $orgUuid,
        ];

        $events     = $this->eventMapper->findSince($since, $effectiveLimit, $filters);
        $serialised = array_map(fn($event) => $event->jsonSerialize(), $events);

        $newCursor = $since ?? 0;
        if (count($events) > 0) {
            $newCursor = (int) $events[count($events) - 1]->getId();
        }

        return new JSONResponse(
                [
                    'events'  => $serialised,
                    'cursor'  => $newCursor,
                    'hasMore' => count($events) === $effectiveLimit,
                ]
                );
    }//end events()

    /**
     * Get the current head cursor (highest event id). Clients use this
     * to fast-forward past historical events on initial subscription —
     * GET /api/realtime/cursor → {cursor: 12345}, then start polling
     * with `?since=12345`.
     *
     * @return JSONResponse JSON response containing the current head cursor.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function cursor(): JSONResponse
    {
        // SECURITY: scope the cursor to the caller's active organisation.
        // Returning the global head pointer let any authed caller observe
        // the global write rate by polling — small but real cross-tenant
        // side channel. With no active org, return 0 (fail-closed) so
        // there is no head pointer to mine.
        $orgUuid = null;
        try {
            $activeOrg = $this->organisationService->getActiveOrganisation();
            $orgUuid   = $activeOrg?->getUuid();
        } catch (\Throwable $e) {
            $orgUuid = null;
        }

        if ($orgUuid === null) {
            return new JSONResponse(['cursor' => 0]);
        }

        return new JSONResponse(
                [
                    'cursor' => $this->eventMapper->getMaxIdForOrganisation($orgUuid),
                ]
                );
    }//end cursor()
}//end class
