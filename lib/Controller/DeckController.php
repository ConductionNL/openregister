<?php

/**
 * DeckController
 *
 * REST controller for Deck card relation operations on OpenRegister objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\DeckControllerServices;
use OCA\OpenRegister\Service\DeckCardServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * DeckController handles Deck card relation operations for objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class DeckController extends Controller {

	/**
	 * Deck card service.
	 *
	 * @var DeckCardServiceInterface
	 */
	private readonly DeckCardServiceInterface $deckCardService;

	/**
	 * Object service.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param DeckControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		DeckControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->deckCardService = $services->deckCardService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List all Deck cards for a specific object.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-3
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->indexFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * List all Deck cards for a specific object: the body of index() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function indexFor(ObjectRef $ref): JSONResponse {
		if ($this->deckCardService->isDeckAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(ref: $ref);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$result = $this->deckCardService->getCardsForObject($object->getUuid());

			return new JSONResponse($result);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end indexFor()

	/**
	 * Create or link a Deck card to an object.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-3
	 */
	public function create(string $register, string $schema, string $id): JSONResponse {
		return $this->createFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end create()

	/**
	 * Create or link a Deck card to an object: the body of create() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function createFor(ObjectRef $ref): JSONResponse {
		if ($this->deckCardService->isDeckAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(ref: $ref);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$data = $this->request->getParams();

			$link = $this->deckCardService->linkOrCreateCard(
				$object->getUuid(),
				(int)$object->getRegister(),
				$data
			);

			return new JSONResponse($link, 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			$code = $e->getCode();
			if ($code === 409) {
				return new JSONResponse(['error' => $e->getMessage()], 409);
			}

			if ($code === 404) {
				return new JSONResponse(['error' => $e->getMessage()], 404);
			}

			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try
	}//end createFor()

	/**
	 * Find all objects linked to cards on a board.
	 *
	 * @param string $boardId The board ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Guarded downstream: DeckCardService::getObjectsForBoard gates on
	 *   Deck's own BoardService::find (board ACL, throws for non-members) and returns [] when
	 *   the caller cannot access the board, so link metadata never leaks for boards the caller
	 *   has no Deck access to.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-3
	 */
	public function objects(string $boardId): JSONResponse {
		if ($this->deckCardService->isDeckAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$results = $this->deckCardService->getObjectsForBoard((int)$boardId);

			return new JSONResponse(['results' => $results, 'total' => count($results)]);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end objects()

	/**
	 * Validate that the object exists.
	 *
	 * @param ObjectRef $ref The register, schema and object the route names
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-3
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(ObjectRef $ref): ?\OCA\OpenRegister\Db\ObjectEntity {
		$this->objectService->setRegister($ref->register);
		$this->objectService->setSchema($ref->schema);
		$this->objectService->setObject($ref->id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
