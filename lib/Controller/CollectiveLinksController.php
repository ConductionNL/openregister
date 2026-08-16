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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CollectiveLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Collectives links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class CollectiveLinksController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param CollectiveLinkService $linkService Backing service.
	 * @param ObjectService $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CollectiveLinkService $linkService,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		if ($this->linkService->isCollectivesAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$results = $this->linkService->getLinkedPages($object->getUuid());

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
		if ($this->linkService->isCollectivesAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$pageId = (int)$this->request->getParam('pageId', 0);
			if ($pageId === 0) {
				return new JSONResponse(['error' => 'pageId is required'], 400);
			}

			$link = $this->linkService->linkPage(
				$object->getUuid(),
				(int)$object->getRegister(),
				(int)$object->getSchema(),
				$pageId
			);

			return new JSONResponse($link->jsonSerialize(), 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
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
		if ($this->linkService->isCollectivesAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$collectiveId = (int)$this->request->getParam('collectiveId', 0);
			if ($collectiveId === 0) {
				return new JSONResponse(['error' => 'collectiveId is required'], 400);
			}

			$title = (string)$this->request->getParam('title', '');
			if (trim($title) === '') {
				return new JSONResponse(['error' => 'title is required'], 400);
			}

			$link = $this->linkService->createAndLinkPage(
				$object->getUuid(),
				(int)$object->getRegister(),
				(int)$object->getSchema(),
				$collectiveId,
				$title
			);

			return new JSONResponse($link->jsonSerialize(), 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
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
		if ($this->linkService->isCollectivesAvailable() === false) {
			return new JSONResponse(
				['error' => 'NC Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$this->linkService->unlinkPage($object->getUuid(), (int)$pageId);

			return new JSONResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
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
			return new JSONResponse(
				['error' => 'NC Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
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
			return new JSONResponse(
				['error' => 'NC Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		$collectives = $this->linkService->getAvailableCollectives();
		return new JSONResponse(['results' => $collectives, 'total' => count($collectives)]);
	}//end collectives()

	/**
	 * Resolve an OR object from register/schema/id.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec exclude Private helper: resolves an object from register/schema/id; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(string $register, string $schema, string $id): ?ObjectEntity {
		$this->objectService->setSchema($schema);
		$this->objectService->setRegister($register);
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
	 * @spec exclude Private helper: maps a service exception code to an HTTP status; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function mapException(Exception $exception): JSONResponse {
		$code = $exception->getCode();
		if (in_array($code, [400, 401, 404, 409, 503], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end mapException()
}//end class
