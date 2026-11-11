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
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-9
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller;

use OCA\OpenRegister\AppInfo\Application;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use OCA\OpenRegister\Search\Controller\Support\ReportRenderRequest;
use OCA\OpenRegister\Search\Controller\Support\ReportsControllerServices;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST endpoint for on-demand dashboard rendering.
 */
class ReportsController extends Controller {
	/**
	 * Composer.
	 *
	 * @var ReportRenderService
	 */
	private readonly ReportRenderService $renderService;

	/**
	 * Dashboard loader.
	 *
	 * @var MagicObjectFinderInterface
	 */
	private readonly MagicObjectFinderInterface $objectMapper;

	/**
	 * Logger for render diagnostics.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request  The current request.
	 * @param ReportsControllerServices $services The reports API services.
	 */
	public function __construct(IRequest $request, ReportsControllerServices $services) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->renderService = $services->renderService;
		$this->objectMapper  = $services->objectMapper;
		$this->logger        = $services->logger;
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-9
	 */
	public function render(string $id) {
		$format = (string)($this->request->getParam(key: 'format', default: 'xlsx'));
		$request = new ReportRenderRequest(identifier: $id, format: strtolower($format));

		try {
			$dashboard = $this->loadDashboard(identifier: $id);
		} catch (\Throwable $e) {
			return $this->loadFailureResponse(identifier: $id, error: $e);
		}

		return $this->renderedDownload(dashboard: $dashboard, request: $request);
	}//end render()

	/**
	 * The response to a dashboard that could not be loaded.
	 *
	 * @param string     $identifier The dashboard identifier.
	 * @param \Throwable $error      Why loading failed.
	 *
	 * @return JSONResponse 404 for an unknown dashboard, a generic 500 otherwise.
	 */
	private function loadFailureResponse(string $identifier, \Throwable $error): JSONResponse {
		if ($error instanceof DoesNotExistException) {
			return $this->notFoundResponse(identifier: $identifier);
		}

		// SECURITY: log internal exception detail (file paths, SQL
		// fragments, library state) and return a generic message.
		$this->logger->error(
			message: '[ReportsController] Failed to load dashboard',
			context: ['identifier' => $identifier, 'error' => $error->getMessage()]
		);
		return new JSONResponse(
			data: ['error' => 'Failed to load dashboard'],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end loadFailureResponse()

	/**
	 * The 404 for an unknown dashboard.
	 *
	 * @param string $identifier The dashboard identifier.
	 *
	 * @return JSONResponse
	 */
	private function notFoundResponse(string $identifier): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Dashboard not found', 'identifier' => $identifier],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFoundResponse()

	/**
	 * Render the dashboard and hand it back as a download.
	 *
	 * @param ObjectEntity        $dashboard The dashboard.
	 * @param ReportRenderRequest $request   The identifier and format.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 */
	private function renderedDownload(ObjectEntity $dashboard, ReportRenderRequest $request) {
		try {
			$rendered = $this->renderService->render(
				dashboard: $dashboard,
				format: $request->format
			);
		} catch (\Throwable $e) {
			return $this->renderFailureResponse(request: $request, error: $e);
		}

		return new DataDownloadResponse(
			data: $rendered['bytes'],
			filename: $rendered['filename'],
			contentType: $rendered['mime']
		);
	}//end renderedDownload()

	/**
	 * The response to a render that failed.
	 *
	 * @param ReportRenderRequest $request The identifier and format.
	 * @param \Throwable          $error   Why rendering failed.
	 *
	 * @return JSONResponse 422 for a validation error, 501 without an office server, a generic 500 otherwise.
	 */
	private function renderFailureResponse(ReportRenderRequest $request, \Throwable $error): JSONResponse {
		if ($error instanceof InvalidArgumentException) {
			// Validation errors are safe to return verbatim (the caller
			// controls the input that triggered them).
			return new JSONResponse(
				data: ['error' => $error->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		if ($error instanceof OfficeServerRequiredException) {
			return new JSONResponse(data: $error->toResponseData(), statusCode: OfficeServerRequiredException::HTTP_STATUS);
		}

		$this->logger->error(
			message: '[ReportsController] Render failed',
			context: ['identifier' => $request->identifier, 'format' => $request->format, 'error' => $error->getMessage()]
		);
		return new JSONResponse(
			data: ['error' => 'Render failed; see server logs for details'],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end renderFailureResponse()

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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-10
	 */
	public function preview(string $id) {
		try {
			$dashboard = $this->loadDashboard(identifier: $id);
		} catch (DoesNotExistException $e) {
			return $this->notFoundResponse(identifier: $id);
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-10
	 */
	private function loadDashboard(string $identifier): ObjectEntity {
		$identifier = trim($identifier);
		$arg = $identifier;
		if (ctype_digit($identifier) === true) {
			$arg = (int)$identifier;
		}

		// SECURITY: standard RBAC + multitenancy filtering must apply on every
		// dashboard load — render/preview surface user-controlled HTML/XLSX/PDF
		// bytes that embed widget data resolved at render time. Bypassing the
		// mapper's filters lets any authenticated caller render any dashboard
		// in any tenant by guessing identifiers (cross-tenant exfiltration).
		return $this->objectMapper->find($arg);
	}//end loadDashboard()
}//end class
