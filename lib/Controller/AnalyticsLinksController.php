<?php

/**
 * AnalyticsLinksController — Tier-2 REST controller for NC Analytics
 * report links.
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/analytics              — list linked reports
 *   - POST   /api/objects/{r}/{s}/{id}/analytics              — link existing report `{reportId}`
 *   - POST   /api/objects/{r}/{s}/{id}/analytics/new          — create + link report `{name,type?}`
 *   - DELETE /api/objects/{r}/{s}/{id}/analytics/{reportId}   — unlink report
 *   - GET    /api/integrations/analytics/available?search=    — picker source
 *
 * NC Analytics reports are user-scoped, so (unlike Flow) there is no
 * admin gate — the active session's user owns the reports it can
 * link/create.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AnalyticsLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Analytics links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class AnalyticsLinksController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param AnalyticsLinkService $analyticsLinkService Backing service.
	 * @param ObjectService $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AnalyticsLinkService $analyticsLinkService,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List linked Analytics reports for an object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Analytics app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$results = $this->analyticsLinkService->getLinkedReports($object->getUuid());

			return new JSONResponse(
				[
					'results' => $results,
					'total' => count($results),
				]
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end index()

	/**
	 * Link an existing Analytics report.
	 *
	 * Body: `{ reportId: int }`.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function link(string $register, string $schema, string $id): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Analytics app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$reportId = (int)$this->request->getParam('reportId', 0);
			if ($reportId === 0) {
				return new JSONResponse(['error' => 'reportId is required'], 400);
			}

			$link = $this->analyticsLinkService->linkReport(
				$object->getUuid(),
				(int)$object->getRegister(),
				(int)$object->getSchema(),
				$reportId
			);

			return new JSONResponse($link->jsonSerialize(), 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
	}//end link()

	/**
	 * Create a new Analytics report and link it.
	 *
	 * Body: `{ name: string, type?: int }`.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function createAndLink(string $register, string $schema, string $id): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Analytics app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$name = (string)$this->request->getParam('name', '');
			if (trim($name) === '') {
				return new JSONResponse(['error' => 'name is required'], 400);
			}

			$type = $this->request->getParam('type');
			$typeInt = null;
			if ($type !== null && $type !== '') {
				$typeInt = (int)$type;
			}

			$link = $this->analyticsLinkService->createAndLinkReport(
				$object->getUuid(),
				(int)$object->getRegister(),
				(int)$object->getSchema(),
				$name,
				$typeInt
			);

			return new JSONResponse($link->jsonSerialize(), 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
	}//end createAndLink()

	/**
	 * Unlink an Analytics report.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $reportId Report id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function destroy(string $register, string $schema, string $id, string $reportId): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Analytics app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$this->analyticsLinkService->unlinkReport($object->getUuid(), (int)$reportId);

			return new JSONResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
	}//end destroy()

	/**
	 * List the current user's Analytics reports (picker source).
	 *
	 * Query param: `search` — optional name substring.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Capability/availability probe: reports whether the companion Analytics integration is available
	 *   and lists report types; no caller-supplied per-object resource.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function available(): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Analytics app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		$search = $this->request->getParam('search');
		if ($search !== null) {
			$search = (string)$search;
		}

		$reports = $this->analyticsLinkService->getAvailableReports($search);
		return new JSONResponse(['results' => $reports, 'total' => count($reports)]);
	}//end available()

	/**
	 * Resolve an OR object from register/schema/id.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec exclude Private helper: resolves an object from register/schema/id;
	 *              the link REST contract is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(string $register, string $schema, string $id): ?ObjectEntity {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()

	/**
	 * Map a service-layer Exception to a JSONResponse.
	 *
	 * Exception codes carry HTTP intent:
	 *   - 400 → bad request
	 *   - 401 → unauthorized (no user)
	 *   - 404 → not found
	 *   - 409 → conflict (duplicate link)
	 *   - 503 → service unavailable
	 *   - everything else → 400 bad request
	 *
	 * @param Exception $exception Source exception.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper: maps a service exception code to an HTTP status;
	 *              the link REST contract is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function mapException(Exception $exception): JSONResponse {
		$code = $exception->getCode();
		if (in_array($code, [400, 401, 404, 409, 503], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end mapException()
}//end class
