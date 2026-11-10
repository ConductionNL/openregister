<?php

/**
 * DeckLinksController — Tier-2 REST controller for Deck card links.
 *
 * Augments the Tier-1 {@see DeckController} with explicit link/create
 * endpoints and a board/stack discovery surface so the picker UX can
 * drive the multi-step modal without leaking Deck internals.
 *
 * Endpoints:
 *   - GET  /api/objects/{register}/{schema}/{id}/deck            — list
 *   - POST /api/objects/{register}/{schema}/{id}/deck            — link existing card
 *   - POST /api/objects/{register}/{schema}/{id}/deck/new        — create + link
 *   - DELETE /api/objects/{register}/{schema}/{id}/deck/{cardId} — unlink
 *   - GET  /api/integrations/deck/boards                         — list boards
 *   - GET  /api/integrations/deck/boards/{boardId}/stacks        — list stacks
 *   - GET  /api/integrations/deck/schemas/{schema}/default       — get sticky default
 *   - PUT  /api/integrations/deck/schemas/{schema}/default       — set sticky default
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

use OCA\OpenRegister\AppInfo\Application;
use Exception;
use OCA\OpenRegister\Controller\Support\DeckLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DeckLinkServiceInterface;
use OCA\OpenRegister\Service\CardDraft;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Deck links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class DeckLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var DeckLinkServiceInterface
	 */
	private readonly DeckLinkServiceInterface $deckLinkService;

	/**
	 * OR object resolver.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Settings service for sticky default.
	 *
	 * @var AppSettingsInterface
	 */
	private readonly AppSettingsInterface $settingsService;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param DeckLinksControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		DeckLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->deckLinkService = $services->deckLinkService;
		$this->objectService = $services->objectService;
		$this->settingsService = $services->settingsService;
	}//end __construct()

	/**
	 * List linked Deck cards for an object.
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
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->indexFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * List linked Deck cards for an object: the body of index() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function indexFor(ObjectRef $ref): JSONResponse {
		if ($this->deckLinkService->isDeckAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Deck');
		}

		return $this->listLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->deckLinkService->getLinkedCards($object->getUuid()))
		);
	}//end indexFor()

	/**
	 * Link an existing Deck card.
	 *
	 * Body: `{ cardId: int }`.
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
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function link(string $register, string $schema, string $id): JSONResponse {
		return $this->linkFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end link()

	/**
	 * Link an existing Deck card: the body of link() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function linkFor(ObjectRef $ref): JSONResponse {
		if ($this->deckLinkService->isDeckAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Deck');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: function (ObjectEntity $object): JSONResponse {
				$cardId = (int)$this->request->getParam('cardId', 0);
				if ($cardId === 0) {
					return new JSONResponse(['error' => 'cardId is required'], 400);
				}

				$link = $this->deckLinkService->linkCard(
					$this->linkTargetOf(object: $object),
					$cardId
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end linkFor()

	/**
	 * Create a new Deck card and link it.
	 *
	 * Body: `{ boardId, stackId, title, description?, duedate? }`.
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Composed of independent
	 *     guard clauses (Deck-availability, object resolve, three required-
	 *     field checks, two optional-field reads, two HTTP error mappings).
	 *     Each branch carries a distinct surface contract — splitting would
	 *     scatter the request-handling intent across helper methods.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function createNew(string $register, string $schema, string $id): JSONResponse {
		return $this->createNewFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end createNew()

	/**
	 * Create a new Deck card and link it: the body of createNew() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function createNewFor(ObjectRef $ref): JSONResponse {
		if ($this->deckLinkService->isDeckAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Deck');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: function (ObjectEntity $object) use ($ref): JSONResponse {
				$boardId = (int)$this->request->getParam('boardId', 0);
				$stackId = (int)$this->request->getParam('stackId', 0);
				$title = (string)$this->request->getParam('title', '');

				if ($boardId === 0 || $stackId === 0 || $title === '') {
					return new JSONResponse(
						['error' => 'boardId, stackId and title are required'],
						400
					);
				}

				$description = $this->request->getParam('description');
				$duedate = $this->request->getParam('duedate');

				$descriptionStr = null;
				if ($description !== null) {
					$descriptionStr = (string)$description;
				}

				$duedateStr = null;
				if ($duedate !== null) {
					$duedateStr = (string)$duedate;
				}

				$link = $this->deckLinkService->createAndLinkCard(
					target: $this->linkTargetOf(object: $object),
					card: (new CardDraft(title: $title, stackId: $stackId))
						->onBoard(boardId: $boardId)
						->withDetails(description: $descriptionStr, duedate: $duedateStr)
				);

				// AD-1: persist sticky schema-level default after each successful create.
				$this->settingsService->setDeckDefault(
					schemaSlug: (string)$ref->schema,
					boardId: $boardId,
					stackId: $stackId,
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end createNewFor()

	/**
	 * Unlink a card.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $cardId Deck card id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroy(string $register, string $schema, string $id, string $cardId): JSONResponse {
		return $this->destroyFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id), cardId: $cardId);
	}//end destroy()

	/**
	 * Unlink a card: the body of destroy() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 * @param string $cardId Deck card id (numeric).
	 *
	 * @return JSONResponse
	 */
	private function destroyFor(ObjectRef $ref, string $cardId): JSONResponse {
		if ($this->deckLinkService->isDeckAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Deck');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: function (ObjectEntity $object) use ($cardId): JSONResponse {
				$this->deckLinkService->unlinkCard($object->getUuid(), (int)$cardId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end destroyFor()

	/**
	 * List Deck boards visible to the current user.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: Deck BoardService returns only boards the current user may access; no unguarded caller-supplied id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function boards(): JSONResponse {
		if ($this->deckLinkService->isDeckAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Deck');
		}

		$boards = $this->deckLinkService->getAvailableBoards();
		return new JSONResponse(['results' => $boards, 'total' => count($boards)]);
	}//end boards()

	/**
	 * List stacks for a board.
	 *
	 * @param string $boardId Deck board id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded downstream: DeckLinkService::getStacksForBoard delegates to Deck StackService::findAll,
	 *   which calls PermissionService::checkPermission(READ) on the board and throws NoPermissionException for non-members.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function stacks(string $boardId): JSONResponse {
		if ($this->deckLinkService->isDeckAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Deck');
		}

		$stacks = $this->deckLinkService->getStacksForBoard((int)$boardId);
		return new JSONResponse(['results' => $stacks, 'total' => count($stacks)]);
	}//end stacks()

	/**
	 * Get the schema-level sticky default board+stack.
	 *
	 * Returns `{boardId: null, stackId: null}` when no default has been saved.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Operates on schema-level Deck configuration (the
	 *   sticky default board+stack for a schema), not on a user-owned object.
	 *   There is no per-object resource to scope; the value is shared config
	 *   for everyone authoring objects of this schema.
	 *
	 * @spec openspec/changes/nextcloud-entity-relations/tasks.md#deck-card-relations
	 */
	public function getDefault(string $schema): JSONResponse {
		$default = $this->settingsService->getDeckDefault(schemaSlug: $schema);
		if ($default === null) {
			return new JSONResponse(['boardId' => null, 'stackId' => null]);
		}

		return new JSONResponse($default);
	}//end getDefault()

	/**
	 * Set (or update) the schema-level sticky default board+stack.
	 *
	 * Body: `{ boardId: int, stackId: int }`.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Operates on schema-level Deck configuration (the
	 *   sticky default board+stack for a schema), not on a user-owned object.
	 *   There is no per-object resource to scope; the value is shared config
	 *   for everyone authoring objects of this schema.
	 *
	 * @spec openspec/changes/nextcloud-entity-relations/tasks.md#deck-card-relations
	 */
	public function setDefault(string $schema): JSONResponse {
		$boardId = (int)$this->request->getParam('boardId', 0);
		$stackId = (int)$this->request->getParam('stackId', 0);

		if ($boardId === 0 || $stackId === 0) {
			return new JSONResponse(['error' => 'boardId and stackId are required'], 400);
		}

		$this->settingsService->setDeckDefault(
			schemaSlug: $schema,
			boardId: $boardId,
			stackId: $stackId,
		);

		return new JSONResponse(['boardId' => $boardId, 'stackId' => $stackId]);
	}//end setDefault()

	/**
	 * Map a service-layer Exception to a JSONResponse.
	 *
	 * Exception codes carry HTTP intent:
	 *   - 409 → conflict
	 *   - 404 → not found
	 *   - 503 → service unavailable
	 *   - everything else → 400 bad request
	 *
	 * @param Exception $exception Source exception.
	 *
	 * @return JSONResponse
	 */
	protected function mapException(Exception $exception): JSONResponse {
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
