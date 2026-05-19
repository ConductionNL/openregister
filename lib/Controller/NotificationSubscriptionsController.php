<?php

/**
 * NotificationSubscriptionsController
 *
 * REST endpoint for managing per-user (register, schema) notification subscriptions.
 *
 * GET    /api/notification-subscriptions           — list current user's subscriptions
 * POST   /api/notification-subscriptions           — subscribe { registerId?, schemaId? }
 * DELETE /api/notification-subscriptions           — unsubscribe ?registerId=&schemaId=
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\NotificationSubscriptionMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Manages user notification subscriptions per (register, schema).
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class NotificationSubscriptionsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                         $appName            Application name.
     * @param IRequest                       $request            HTTP request.
     * @param NotificationSubscriptionMapper $subscriptionMapper Subscription mapper.
     * @param IUserSession                   $userSession        User session.
     * @param LoggerInterface                $logger             Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly NotificationSubscriptionMapper $subscriptionMapper,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List subscriptions for the current user.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Not authenticated'], statusCode: 401);
        }

        try {
            $subs = $this->subscriptionMapper->findByUser(userId: $userId);
            return new JSONResponse(
                data: array_map(
                    callback: static fn($s) => $s->jsonSerialize(),
                    array: $subs
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                message: 'NotificationSubscriptionsController::index failed',
                context: ['error' => $e->getMessage()]
            );
            return new JSONResponse(data: ['message' => 'Internal server error'], statusCode: 500);
        }
    }//end index()

    /**
     * Subscribe the current user to a (register, schema).
     *
     * Body: { "registerId"?: string, "schemaId"?: string }
     * At least one of registerId or schemaId must be provided.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Not authenticated'], statusCode: 401);
        }

        $registerId = $this->request->getParam(key: 'registerId');
        $registerId = $registerId !== null && $registerId !== '' ? $registerId : null;
        $schemaId   = $this->request->getParam(key: 'schemaId');
        $schemaId   = $schemaId !== null && $schemaId !== '' ? $schemaId : null;

        if ($registerId === null && $schemaId === null) {
            return new JSONResponse(
                data: ['message' => 'At least one of registerId or schemaId is required'],
                statusCode: 422
            );
        }

        try {
            $sub = $this->subscriptionMapper->subscribe(
                userId: $userId,
                registerId: $registerId,
                schemaId: $schemaId
            );
            return new JSONResponse(data: $sub->jsonSerialize(), statusCode: 201);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: 'NotificationSubscriptionsController::create failed',
                context: ['error' => $e->getMessage()]
            );
            return new JSONResponse(data: ['message' => 'Internal server error'], statusCode: 500);
        }
    }//end create()

    /**
     * Unsubscribe the current user.
     *
     * Query params: ?registerId=&schemaId=
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(): JSONResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Not authenticated'], statusCode: 401);
        }

        $registerId = $this->request->getParam(key: 'registerId');
        $registerId = $registerId !== null && $registerId !== '' ? $registerId : null;
        $schemaId   = $this->request->getParam(key: 'schemaId');
        $schemaId   = $schemaId !== null && $schemaId !== '' ? $schemaId : null;

        try {
            $deleted = $this->subscriptionMapper->unsubscribe(
                userId: $userId,
                registerId: $registerId,
                schemaId: $schemaId
            );
            return new JSONResponse(data: ['deleted' => $deleted]);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: 'NotificationSubscriptionsController::destroy failed',
                context: ['error' => $e->getMessage()]
            );
            return new JSONResponse(data: ['message' => 'Internal server error'], statusCode: 500);
        }
    }//end destroy()

    /**
     * Get the current user's ID from the session.
     *
     * @return string|null
     */
    private function currentUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end currentUserId()
}//end class
