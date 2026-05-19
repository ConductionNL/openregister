<?php

/**
 * NotificationHistoryController
 *
 * Read-only REST endpoint for querying the notification dispatch audit trail.
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Provides read-only access to the notification history audit trail.
 *
 * @psalm-suppress UnusedClass
 */
class NotificationHistoryController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                    $appName       Application name.
     * @param IRequest                  $request       HTTP request.
     * @param NotificationHistoryMapper $historyMapper Notification history mapper.
     * @param LoggerInterface           $logger        Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly NotificationHistoryMapper $historyMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List notification history entries with optional filters and pagination.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-9
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $ruleId     = $this->request->getParam(key: 'ruleId');
        $channel    = $this->request->getParam(key: 'channel');
        $recipient  = $this->request->getParam(key: 'recipient');
        $objectUuid = $this->request->getParam(key: 'objectUuid');
        $schemaId   = $this->request->getParam(key: 'schemaId');
        $registerId = $this->request->getParam(key: 'registerId');
        $status     = $this->request->getParam(key: 'status');
        $limit      = (int) ($this->request->getParam(key: 'limit') ?? 50);
        $offset     = (int) ($this->request->getParam(key: 'offset') ?? 0);

        try {
            $entries = $this->historyMapper->findFiltered(
                ruleId: $ruleId !== null && $ruleId !== '' ? $ruleId : null,
                channel: $channel !== null && $channel !== '' ? $channel : null,
                recipient: $recipient !== null && $recipient !== '' ? $recipient : null,
                objectUuid: $objectUuid !== null && $objectUuid !== '' ? $objectUuid : null,
                schemaId: $schemaId !== null && $schemaId !== '' ? $schemaId : null,
                registerId: $registerId !== null && $registerId !== '' ? $registerId : null,
                status: $status !== null && $status !== '' ? $status : null,
                limit: $limit,
                offset: $offset
            );

            $total = $this->historyMapper->countFiltered(
                ruleId: $ruleId !== null && $ruleId !== '' ? $ruleId : null,
                channel: $channel !== null && $channel !== '' ? $channel : null,
                recipient: $recipient !== null && $recipient !== '' ? $recipient : null,
                objectUuid: $objectUuid !== null && $objectUuid !== '' ? $objectUuid : null,
                schemaId: $schemaId !== null && $schemaId !== '' ? $schemaId : null,
                registerId: $registerId !== null && $registerId !== '' ? $registerId : null,
                status: $status !== null && $status !== '' ? $status : null
            );

            return new JSONResponse(
                    data: [
                        'results' => array_map(
                    callback: static fn($e) => $e->jsonSerialize(),
                    array: $entries
                ),
                        'total'   => $total,
                        'limit'   => $limit,
                        'offset'  => $offset,
                    ]
                    );
        } catch (\Throwable $e) {
            $this->logger->error(
                message: 'NotificationHistoryController::index failed',
                context: ['error' => $e->getMessage()]
            );
            return new JSONResponse(data: ['message' => 'Internal server error'], statusCode: 500);
        }//end try
    }//end index()
}//end class
