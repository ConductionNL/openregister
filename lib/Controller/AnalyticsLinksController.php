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

use OCA\OpenRegister\Service\AnalyticsReportDraft;
use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\AnalyticsLinksControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AnalyticsLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Analytics links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class AnalyticsLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var AnalyticsLinkServiceInterface
	 */
	private readonly AnalyticsLinkServiceInterface $analyticsLinkService;

	/**
	 * OR object resolver.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param AnalyticsLinksControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		AnalyticsLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->analyticsLinkService = $services->analyticsLinkService;
		$this->objectService = $services->objectService;
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
		return $this->indexFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * List linked Analytics reports for an object: the body of index() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function indexFor(ObjectRef $ref): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Analytics');
		}

		return $this->listLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->analyticsLinkService->getLinkedReports($object->getUuid()))
		);
	}//end indexFor()

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
		return $this->linkFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end link()

	/**
	 * Link an existing Analytics report: the body of link() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function linkFor(ObjectRef $ref): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Analytics');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: function (ObjectEntity $object): JSONResponse {
				$reportId = (int)$this->request->getParam('reportId', 0);
				if ($reportId === 0) {
					return new JSONResponse(['error' => 'reportId is required'], 400);
				}

				$link = $this->analyticsLinkService->linkReport(
					$this->linkTargetOf(object: $object),
					$reportId
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end linkFor()

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
		return $this->createAndLinkFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end createAndLink()

	/**
	 * Create a new Analytics report and link it: the body of createAndLink() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function createAndLinkFor(ObjectRef $ref): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Analytics');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: function (ObjectEntity $object): JSONResponse {
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
					target: $this->linkTargetOf(object: $object),
					draft: new AnalyticsReportDraft(name: $name, type: $typeInt)
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end createAndLinkFor()

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
		return $this->destroyFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id), reportId: $reportId);
	}//end destroy()

	/**
	 * Unlink an Analytics report: the body of destroy() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 * @param string $reportId Report id (numeric).
	 *
	 * @return JSONResponse
	 */
	private function destroyFor(ObjectRef $ref, string $reportId): JSONResponse {
		if ($this->analyticsLinkService->isAnalyticsAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Analytics');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: function (ObjectEntity $object) use ($reportId): JSONResponse {
				$this->analyticsLinkService->unlinkReport($object->getUuid(), (int)$reportId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end destroyFor()

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
			return $this->appNotInstalled(appName: 'NC Analytics');
		}

		$search = $this->request->getParam('search');
		if ($search !== null) {
			$search = (string)$search;
		}

		$reports = $this->analyticsLinkService->getAvailableReports($search);
		return new JSONResponse(['results' => $reports, 'total' => count($reports)]);
	}//end available()

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
	protected function mapException(Exception $exception): JSONResponse {
		$code = $exception->getCode();
		if (in_array($code, [400, 401, 404, 409, 503], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end mapException()

	/**
	 * The link route of the object a route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return ObjectLinkRoute The route the link traits resolve.
	 */
	private function routeOf(ObjectRef $ref): ObjectLinkRoute {
		return new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id);
	}//end routeOf()
}//end class
