<?php

/**
 * OpenRegister QualityController
 *
 * HTTP entry point for the read-only MDM quality surface: schema-scoped
 * quality statistics ({@see stats()}) and the lowest-quality object listing
 * ({@see index()}), both delegating to
 * {@see \OCA\OpenRegister\Service\Quality\QualityStatisticsService}. Neither
 * endpoint writes, re-scores, or merges — they read the already-materialised
 * `qualityScore` / `qualityStatus` fields via `ObjectService` (RBAC + tenant
 * scoped).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Quality\QualityStatisticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

class QualityController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The current request.
	 * @param QualityStatisticsService $statistics Quality statistics/query service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly QualityStatisticsService $statistics,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Schema-scoped quality statistics: average, per-status buckets,
	 * a 10-bucket score histogram, and total.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse JSON response with the statistics envelope.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
	 */
	public function stats(string $register, string $schema): JSONResponse {
		try {
			$result = $this->statistics->statisticsFor(register: $register, schema: $schema);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end stats()

	/**
	 * Lowest-quality object listing (worst-first by default). Accepts
	 * `qualityStatus` (filter), `sort` (`qualityScore`|`qualityStatus`),
	 * `order` (`asc`|`desc`), and `limit`/`offset` pagination params.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse JSON response with the paginated listing.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
	 */
	public function index(string $register, string $schema): JSONResponse {
		$qualityStatus = $this->request->getParam('qualityStatus');
		if ($qualityStatus !== null) {
			$qualityStatus = (string)$qualityStatus;
		}

		$sort = (string)$this->request->getParam('sort', 'qualityScore');
		$order = (string)$this->request->getParam('order', 'asc');

		$limit = (int)$this->request->getParam('limit', 20);
		$offset = (int)$this->request->getParam('offset', 0);

		if ($limit <= 0) {
			$limit = 20;
		}

		if ($offset < 0) {
			$offset = 0;
		}

		try {
			$result = $this->statistics->lowestQuality(
				register: $register,
				schema: $schema,
				qualityStatus: $qualityStatus,
				sort: $sort,
				order: $order,
				limit: $limit,
				offset: $offset
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end index()
}//end class
