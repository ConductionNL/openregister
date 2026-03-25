<?php

/**
 * FileSidebarController
 *
 * Provides API endpoints for the Nextcloud Files sidebar tabs.
 * Returns OpenRegister object references and extraction metadata
 * for a given Nextcloud file ID.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\FileSidebarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for Files sidebar tab API endpoints.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @psalm-suppress UnusedClass
 */
class FileSidebarController extends Controller
{
    /**
     * Constructor.
     *
     * @param string             $appName            Application name.
     * @param IRequest           $request            HTTP request.
     * @param FileSidebarService $fileSidebarService File sidebar service.
     * @param LoggerInterface    $logger             Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FileSidebarService $fileSidebarService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get all OpenRegister objects that reference the given file.
     *
     * @param int $fileId The Nextcloud file ID.
     *
     * @return JSONResponse JSON response with objects array.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getObjectsForFile(int $fileId): JSONResponse
    {
        try {
            $objects = $this->fileSidebarService->getObjectsForFile($fileId);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => $objects,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[FileSidebarController] Error fetching objects for file '.$fileId.': '.$e->getMessage()
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => 'Failed to retrieve objects for file.',
                ],
                statusCode: 500
            );
        }//end try
    }//end getObjectsForFile()

    /**
     * Get the extraction status and metadata for the given file.
     *
     * @param int $fileId The Nextcloud file ID.
     *
     * @return JSONResponse JSON response with extraction data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getExtractionStatus(int $fileId): JSONResponse
    {
        try {
            $status = $this->fileSidebarService->getExtractionStatus($fileId);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => $status,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[FileSidebarController] Error fetching extraction status for file '.$fileId.': '.$e->getMessage()
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => 'Failed to retrieve extraction status.',
                ],
                statusCode: 500
            );
        }//end try
    }//end getExtractionStatus()
}//end class
