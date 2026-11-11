<?php

/**
 * CollectiveLinksController — Tier-2 REST controller for NC Collectives
 * (Knowledge) page links.
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/collectives             — list linked pages
 *   - POST   /api/objects/{r}/{s}/{id}/collectives             — link existing page `{pageId}`
 *   - POST   /api/objects/{r}/{s}/{id}/collectives/new         — create + link page `{collectiveId,title}`
 *   - DELETE /api/objects/{r}/{s}/{id}/collectives/{pageId}    — unlink page
 *   - GET    /api/integrations/collectives/available?search=   — page picker source
 *   - GET    /api/integrations/collectives/list                — collectives for the create cascade
 *
 * NC Collectives pages are user-scoped (via the collective's circle
 * membership), so (unlike Flow) there is no admin gate — the active
 * session's user owns the pages it can link/create.
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
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\CollectiveLinksControllerServices;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CollectiveLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Collectives links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class CollectiveLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var CollectiveLinkServiceInterface
	 */
	private readonly CollectiveLinkServiceInterface $linkService;

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
	 * @param CollectiveLinksControllerServices $services The collective links controller services.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function __construct(
		IRequest $request,
		CollectiveLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->linkService = $services->linkService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List linked Collectives pages for an object.
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
		return $this->linkedPages(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * Link an existing Collectives page.
	 *
	 * Body: `{ pageId: int }`.
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
		return $this->pageLinked(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
	}//end link()

	/**
	 * Create a new Collectives page and link it.
	 *
	 * Body: `{ collectiveId: int, title: string }`.
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
		return $this->pageCreated(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
	}//end createAndLink()

	/**
	 * Unlink a Collectives page.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $pageId Page id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function destroy(string $register, string $schema, string $id, string $pageId): JSONResponse {
		return $this->pageUnlinked(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), pageId: $pageId);
	}//end destroy()

	/**
	 * List the current user's Collectives pages (picker source).
	 *
	 * Query param: `search` — optional title substring.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped capability probe: reports Collectives availability for the current user; no caller-supplied object id.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function available(): JSONResponse {
		if ($this->linkService->isCollectivesAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Collectives');
		}

		$search = $this->request->getParam('search');
		if ($search !== null) {
			$search = (string)$search;
		}

		$pages = $this->linkService->getAvailablePages($search);
		return new JSONResponse(['results' => $pages, 'total' => count($pages)]);
	}//end available()

	/**
	 * List the current user's collectives (create-cascade source).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: returns the current user's own Collectives; no caller-supplied object id.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function collectives(): JSONResponse {
		if ($this->linkService->isCollectivesAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Collectives');
		}

		$collectives = $this->linkService->getAvailableCollectives();
		return new JSONResponse(['results' => $collectives, 'total' => count($collectives)]);
	}//end collectives()

	/**
	 * The Collectives pages linked to the routed object.
	 *
	 * @param ObjectLinkRoute $route The object the route names.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function linkedPages(ObjectLinkRoute $route): JSONResponse {
		if ($this->linkService->isCollectivesAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Collectives');
		}

		return $this->listLinkedObject(
			route: $route,
			action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->linkService->getLinkedPages($object->getUuid()))
		);
	}//end linkedPages()

	/**
	 * Link the page the request names to the routed object.
	 *
	 * @param ObjectLinkRoute $route The object the route names.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function pageLinked(ObjectLinkRoute $route): JSONResponse {
		if ($this->linkService->isCollectivesAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Collectives');
		}

		return $this->onLinkedObject(route: $route, action: fn (ObjectEntity $object): JSONResponse => $this->linkPageTo(object: $object));
	}//end pageLinked()

	/**
	 * Link the page the request names to a resolved object.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function linkPageTo(ObjectEntity $object): JSONResponse {
		$pageId = (int)$this->request->getParam('pageId', 0);
		if ($pageId === 0) {
			return new JSONResponse(['error' => 'pageId is required'], 400);
		}

		$link = $this->linkService->linkPage(
			$this->linkTargetOf(object: $object),
			$pageId
		);

		return new JSONResponse($link->jsonSerialize(), 201);
	}//end linkPageTo()

	/**
	 * Create the page the request describes and link it to the routed object.
	 *
	 * @param ObjectLinkRoute $route The object the route names.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function pageCreated(ObjectLinkRoute $route): JSONResponse {
		if ($this->linkService->isCollectivesAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Collectives');
		}

		return $this->onLinkedObject(route: $route, action: fn (ObjectEntity $object): JSONResponse => $this->createPageFor(object: $object));
	}//end pageCreated()

	/**
	 * Create the page the request describes and link it to a resolved object.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function createPageFor(ObjectEntity $object): JSONResponse {
		$collectiveId = (int)$this->request->getParam('collectiveId', 0);
		if ($collectiveId === 0) {
			return new JSONResponse(['error' => 'collectiveId is required'], 400);
		}

		$title = (string)$this->request->getParam('title', '');
		if (trim($title) === '') {
			return new JSONResponse(['error' => 'title is required'], 400);
		}

		$link = $this->linkService->createAndLinkPage($this->linkTargetOf(object: $object), $collectiveId, $title);

		return new JSONResponse($link->jsonSerialize(), 201);
	}//end createPageFor()

	/**
	 * Unlink a page from the routed object.
	 *
	 * @param ObjectLinkRoute $route  The object the route names.
	 * @param string          $pageId Page id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function pageUnlinked(ObjectLinkRoute $route, string $pageId): JSONResponse {
		if ($this->linkService->isCollectivesAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Collectives');
		}

		return $this->onLinkedObject(
			route: $route,
			action: function (ObjectEntity $object) use ($pageId): JSONResponse {
				$this->linkService->unlinkPage($object->getUuid(), (int)$pageId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end pageUnlinked()

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
	 * @spec exclude Private helper: maps a service exception code to an HTTP status; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	protected function mapException(Exception $exception): JSONResponse {
		return LinkedSourceResponses::failure(exception: $exception, passThrough: [400, 401, 404, 409, 503]);
	}//end mapException()
}//end class
