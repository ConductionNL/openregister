<?php

/**
 * TalkLinksController — Tier-2 REST controller for Talk room links.
 *
 * Backs the bespoke CnTalkTab + picker UX (ADR-019 / ADR-022). Surfaces:
 *   - GET    /api/objects/{register}/{schema}/{id}/talk            — list linked rooms
 *   - POST   /api/objects/{register}/{schema}/{id}/talk            — link existing room
 *   - POST   /api/objects/{register}/{schema}/{id}/talk/new        — create + link
 *   - DELETE /api/objects/{register}/{schema}/{id}/talk/{roomToken}— unlink (does NOT destroy room)
 *   - GET    /api/integrations/talk/rooms?search=                  — picker source
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TalkLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Talk links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class TalkLinksController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param TalkLinkService $talkLinkService Backing service.
	 * @param ObjectService $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TalkLinkService $talkLinkService,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List linked Talk rooms for an object.
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
		if ($this->talkLinkService->isTalkAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Talk app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$results = $this->talkLinkService->getLinkedRooms($object->getUuid());

			return new JSONResponse(['results' => $results, 'total' => count($results)]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end index()

	/**
	 * Link an existing Talk room.
	 *
	 * Body: `{ roomToken: string }`.
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
		if ($this->talkLinkService->isTalkAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Talk app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$roomToken = (string)$this->request->getParam('roomToken', '');
			if ($roomToken === '') {
				return new JSONResponse(['error' => 'roomToken is required'], 400);
			}

			$link = $this->talkLinkService->linkRoom(
				$object->getUuid(),
				(int)$object->getRegister(),
				(int)$object->getSchema(),
				$roomToken
			);

			return new JSONResponse($link->jsonSerialize(), 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
	}//end link()

	/**
	 * Create a new Talk room and link it.
	 *
	 * Body: `{ name, description?, type? }`.
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function createNew(string $register, string $schema, string $id): JSONResponse {
		if ($this->talkLinkService->isTalkAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Talk app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$name = trim((string)$this->request->getParam('name', ''));
			if ($name === '') {
				return new JSONResponse(['error' => 'name is required'], 400);
			}

			$description = $this->request->getParam('description');
			$typeParam = $this->request->getParam('type');
			$type = 2;
			if ($typeParam !== null) {
				$type = (int)$typeParam;
			}

			$descriptionValue = null;
			if ($description !== null) {
				$descriptionValue = (string)$description;
			}

			$link = $this->talkLinkService->createAndLinkRoom(
				$object->getUuid(),
				(int)$object->getRegister(),
				(int)$object->getSchema(),
				$name,
				$descriptionValue,
				$type
			);

			return new JSONResponse($link->jsonSerialize(), 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
	}//end createNew()

	/**
	 * Unlink a Talk room. Does NOT destroy the room in Talk.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $roomToken Talk room token.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function destroy(string $register, string $schema, string $id, string $roomToken): JSONResponse {
		if ($this->talkLinkService->isTalkAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Talk app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$this->talkLinkService->unlinkRoom($object->getUuid(), $roomToken);

			return new JSONResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
	}//end destroy()

	/**
	 * List Talk rooms available to the current user (picker source).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: TalkLinkService::getAvailableRoomsForUser returns only rooms
	 *   the current user participates in; no unguarded id.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function rooms(): JSONResponse {
		if ($this->talkLinkService->isTalkAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Talk app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		$search = $this->request->getParam('search');
		$searchValue = null;
		if ($search !== null) {
			$searchValue = (string)$search;
		}

		$rooms = $this->talkLinkService->getAvailableRoomsForUser($searchValue);

		return new JSONResponse(['results' => $rooms, 'total' => count($rooms)]);
	}//end rooms()

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
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()

	/**
	 * Map a service-layer Exception to a JSONResponse.
	 *
	 * Exception codes carry HTTP intent:
	 *   - 409 → conflict
	 *   - 404 → not found
	 *   - 503 → service unavailable
	 *   - 400 → bad request
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
		if ($code === 409) {
			return new JSONResponse(['error' => $exception->getMessage()], 409);
		}

		if ($code === 404) {
			return new JSONResponse(['error' => $exception->getMessage()], 404);
		}

		if ($code === 503) {
			return new JSONResponse(['error' => $exception->getMessage()], 503);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end mapException()
}//end class
