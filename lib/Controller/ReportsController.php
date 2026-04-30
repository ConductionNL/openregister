<?php

/**
 * Reports controller — on-demand render endpoint.
 *
 * Operators trigger a render of a dashboard via:
 *
 *   POST /api/reports/{id}/render?format=xlsx
 *
 * The response is a download (DataDownloadResponse) so a browser can
 * save it directly. Format defaults to `xlsx`; supports `csv`, `xlsx`,
 * `ods`, `html` (for browser print-to-PDF).
 *
 * Phase 2 keeps this as a synchronous endpoint — Phase 2b's
 * ReportRenderJob handles scheduled renders + Files-folder delivery.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Reporting\ReportRenderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST endpoint for on-demand dashboard rendering.
 */
class ReportsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName       App identifier.
     * @param IRequest            $request       Active request.
     * @param ReportRenderService $renderService Composer.
     * @param MagicMapper         $objectMapper  Dashboard loader.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ReportRenderService $renderService,
        private readonly MagicMapper $objectMapper,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * POST /api/reports/{id}/render — render a dashboard to the chosen format.
     *
     * @param string $id Dashboard identifier (numeric id, uuid, or slug).
     *
     * @return DataDownloadResponse|JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function render(string $id)
    {
        $format = (string) ($this->request->getParam(key: 'format', default: 'xlsx'));
        $format = strtolower($format);

        try {
            $dashboard = $this->loadDashboard(identifier: $id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found', 'identifier' => $id],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to load dashboard: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $rendered = $this->renderService->render(
                dashboard: $dashboard,
                format: $format
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => 'Render failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new DataDownloadResponse(
            data: $rendered['bytes'],
            filename: $rendered['filename'],
            contentType: $rendered['mime']
        );

    }//end render()

    /**
     * GET /api/reports/{id}/preview — render to HTML and return as
     * `text/html` so an iframe can preview the dashboard before
     * downloading the spreadsheet form.
     *
     * @param string $id Dashboard identifier.
     *
     * @return DataDownloadResponse|JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function preview(string $id)
    {
        try {
            $dashboard = $this->loadDashboard(identifier: $id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found', 'identifier' => $id],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $rendered = $this->renderService->render(
            dashboard: $dashboard,
            format: 'html'
        );

        $response = new DataDownloadResponse(
            data: $rendered['bytes'],
            filename: $rendered['filename'],
            contentType: $rendered['mime']
        );

        // Inline preview rather than triggering a browser download.
        $response->addHeader(
            'Content-Disposition',
            sprintf('inline; filename="%s"', $rendered['filename'])
        );
        return $response;

    }//end preview()

    /**
     * Load the dashboard ObjectEntity by id|uuid|slug.
     *
     * @param string $identifier Path identifier.
     *
     * @return ObjectEntity
     *
     * @throws DoesNotExistException
     */
    private function loadDashboard(string $identifier): ObjectEntity
    {
        $identifier = trim($identifier);
        $arg        = ctype_digit($identifier) === true ? (int) $identifier : $identifier;
        return $this->objectMapper->find(
            $arg,
            _rbac: false,
            _multitenancy: false
        );

    }//end loadDashboard()
}//end class
