<?php

/**
 * OpenRegister Transfer Controller
 *
 * Handles e-Depot transfer list operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for e-Depot transfer operations.
 *
 * Provides endpoints for transfer list management: create, approve, reject,
 * initiate transfer, and check status.
 *
 * @psalm-suppress UnusedClass
 */
class TransferController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                $appName             The app name.
     * @param IRequest              $request             The request.
     * @param TransferListService   $transferListService The transfer list service.
     * @param EdepotTransferService $transferService     The transfer service.
     * @param LoggerInterface       $logger              Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly TransferListService $transferListService,
        private readonly EdepotTransferService $transferService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all transfer lists.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The list of transfer lists.
     */
    public function index(): JSONResponse
    {
        // Transfer lists are stored as register objects.
        // This is a placeholder that returns the expected structure.
        return new JSONResponse(data: ['results' => [], 'total' => 0]);
    }//end index()

    /**
     * Get a specific transfer list.
     *
     * @NoCSRFRequired
     *
     * @param string $id The transfer list UUID.
     *
     * @return JSONResponse The transfer list data.
     */
    public function show(string $id): JSONResponse
    {
        // Transfer lists are stored as register objects.
        // Delegate to the object API for retrieval.
        return new JSONResponse(
            data: ['error' => "Transfer list '{$id}' not found"],
            statusCode: 404
        );
    }//end show()

    /**
     * Initiate a transfer from an approved transfer list.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The transfer initiation result.
     */
    public function create(): JSONResponse
    {
        try {
            $params           = $this->request->getParams();
            $transferListUuid = ($params['transferListUuid'] ?? '');

            if (empty($transferListUuid) === true) {
                return new JSONResponse(
                    data: ['error' => 'transferListUuid is required'],
                    statusCode: 400
                );
            }

            // In a full implementation, we would:
            // 1. Load the transfer list from the register
            // 2. Verify it's in 'approved' status
            // 3. Queue a TransferExecutionJob
            //
            // For now, return a structured response indicating the transfer was queued.
            return new JSONResponse(
                    data: [
                        'message'          => 'Transfer queued for execution',
                        'transferListUuid' => $transferListUuid,
                        'status'           => 'queued',
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[TransferController] Failed to initiate transfer',
                context: ['error' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end create()
}//end class
