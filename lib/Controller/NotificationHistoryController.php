<?php

/**
 * REST controller for the notification history audit trail.
 *
 * Closes the `notificatie-engine` spec's
 * "Notification history MUST be stored and queryable for audit
 * purposes" requirement together with the
 * `Version1Date20260501100000` migration + the `NotificationHistory`
 * entity + the `NotificationHistoryMapper` query API.
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
 *
 * @spec openspec/changes/notificatie-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Class NotificationHistoryController.
 *
 * @psalm-suppress UnusedClass
 */
class NotificationHistoryController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                    $appName Application name.
     * @param IRequest                  $request HTTP request.
     * @param NotificationHistoryMapper $mapper  Mapper for the notification history table.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly NotificationHistoryMapper $mapper,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * List notification history rows with optional filters.
     *
     * Supported query string params: `ruleId`, `channel`, `recipient`,
     * `objectUuid`, `schemaId`, `registerId`, `status`, `limit`, `offset`.
     *
     * @return JSONResponse JSON response with results, total, limit, offset.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/notificatie-engine/tasks.md
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Unauthorized'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $filters = $this->extractFilters();
        $limit   = $this->resolveLimit();
        $offset  = $this->resolveOffset();

        // Per-user scoping: non-admins are silently constrained to their
        // own notification history, even if they explicitly pass a
        // `recipient` filter naming someone else. Admins keep full
        // visibility (the spec explicitly calls out audit access).
        // Without this guard, the @NoAdminRequired annotation would
        // make the endpoint a notification-history dump for every
        // authenticated user on the instance.
        $isAdmin = $this->groupManager->isAdmin($user->getUID());
        if ($isAdmin === false) {
            $filters['recipient'] = $user->getUID();
        }

        $results = $this->mapper->findFiltered(filters: $filters, limit: $limit, offset: $offset);
        $total   = $this->mapper->countFiltered(filters: $filters);

        return new JSONResponse(
            data: [
                'results' => array_map(static fn ($entity) => $entity->jsonSerialize(), $results),
                'total'   => $total,
                'limit'   => $limit,
                'offset'  => $offset,
            ]
        );

    }//end index()

    /**
     * Extract supported filter values from the request.
     *
     * @return array<string, string|null> Filter map.
     */
    private function extractFilters(): array
    {
        $supported = [
            'ruleId',
            'channel',
            'recipient',
            'objectUuid',
            'schemaId',
            'registerId',
            'status',
        ];

        $filters = [];
        foreach ($supported as $key) {
            $value = $this->request->getParam($key);
            if (is_string($value) === true && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;

    }//end extractFilters()

    /**
     * Resolve the limit parameter.
     *
     * Defaults to 50 when missing or invalid; capped at 500 to prevent
     * accidental "give me everything" queries from spiking memory.
     *
     * @return int Resolved limit.
     */
    private function resolveLimit(): int
    {
        $defaultValue = 50;
        $maxValue     = 500;

        $raw = $this->request->getParam('limit');
        if ($raw === null || $raw === '') {
            return $defaultValue;
        }

        if (is_string($raw) === true && ctype_digit($raw) === true) {
            $value = (int) $raw;
            if ($value > 0) {
                return min($value, $maxValue);
            }
        } else if (is_int($raw) === true && $raw > 0) {
            return min($raw, $maxValue);
        }

        return $defaultValue;

    }//end resolveLimit()

    /**
     * Resolve the offset parameter.
     *
     * Defaults to 0 when missing or invalid.
     *
     * @return int Resolved offset.
     */
    private function resolveOffset(): int
    {
        $raw = $this->request->getParam('offset');
        if ($raw === null || $raw === '') {
            return 0;
        }

        if (is_string($raw) === true && ctype_digit($raw) === true) {
            return (int) $raw;
        }

        if (is_int($raw) === true && $raw >= 0) {
            return $raw;
        }

        return 0;

    }//end resolveOffset()
}//end class
