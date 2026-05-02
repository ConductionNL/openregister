<?php

/**
 * NotificationSubscriptionsController.
 *
 * REST surface for per-user (register, schema) notification
 * subscriptions. Routes:
 *
 *   GET    /api/notification-subscriptions
 *          → list current user's subscriptions
 *
 *   POST   /api/notification-subscriptions
 *          body: { registerId?, schemaId? }
 *          → subscribe (idempotent)
 *
 *   DELETE /api/notification-subscriptions
 *          query: ?registerId=X&schemaId=Y (either may be omitted)
 *          → unsubscribe by tuple
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/notificatie-engine/tasks.md "Users MUST be able to manage their notification preferences"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\NotificationSubscriptionMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class NotificationSubscriptionsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                         $appName     App name.
     * @param IRequest                       $request     Request.
     * @param NotificationSubscriptionMapper $mapper      Subscription mapper.
     * @param IUserSession                   $userSession Current-user session.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly NotificationSubscriptionMapper $mapper,
        private readonly IUserSession $userSession
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * List the current user's subscriptions.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
        }

        $rows  = $this->mapper->findByUser(userId: $userId);
        $items = array_map(
            static fn($r) => $r->jsonSerialize(),
            $rows
        );
        return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);

    }//end index()

    /**
     * Subscribe the current user to a (register, schema) tuple.
     *
     * Body: `{ registerId?: int, schemaId?: int }`. At least one MUST
     * be present.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
        }

        $params     = $this->request->getParams();
        $registerId = $this->coerceNullableInt(value: ($params['registerId'] ?? null));
        $schemaId   = $this->coerceNullableInt(value: ($params['schemaId'] ?? null));

        try {
            $entity = $this->mapper->subscribe(
                userId: $userId,
                registerId: $registerId,
                schemaId: $schemaId
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
        }

        return new JSONResponse(data: $entity->jsonSerialize(), statusCode: 201);

    }//end create()

    /**
     * Unsubscribe the current user from a (register, schema) tuple.
     *
     * Query: `?registerId=X&schemaId=Y` (either may be omitted).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
        }

        $registerId = $this->coerceNullableInt(value: $this->request->getParam('registerId'));
        $schemaId   = $this->coerceNullableInt(value: $this->request->getParam('schemaId'));

        $deleted = $this->mapper->unsubscribe(
            userId: $userId,
            registerId: $registerId,
            schemaId: $schemaId
        );

        return new JSONResponse(data: ['deleted' => $deleted]);

    }//end destroy()

    /**
     * Resolve the current user's UID, or null when anonymous.
     *
     * @return ?string
     */
    private function resolveUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();

    }//end resolveUserId()

    /**
     * Coerce a request value to a nullable int. Empty string and null
     * both become null; anything else cast to int.
     *
     * @param mixed $value Input.
     *
     * @return ?int
     */
    private function coerceNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) === false) {
            return null;
        }

        return (int) $value;

    }//end coerceNullableInt()
}//end class
