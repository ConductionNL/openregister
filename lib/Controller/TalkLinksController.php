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

use Closure;
use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\TalkLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\LinkedSourceActions;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\TalkLink\LinkedObject;
use OCA\OpenRegister\Service\TalkLink\RoomCreation;
use OCA\OpenRegister\Service\TalkLinkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Talk links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class TalkLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var TalkLinkServiceInterface
	 */
	private readonly TalkLinkServiceInterface $talkLinkService;

	/**
	 * OR object resolver.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request  HTTP request.
	 * @param TalkLinksControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		TalkLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->talkLinkService = $services->talkLinkService;
		$this->objectService = $services->objectService;
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
		$action = $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->talkLinkService->getLinkedRooms($object->getUuid()));

		return $this->listWhenInstalled(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
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
		$action = $this->linkRoom(...);

		return $this->onWhenInstalled(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
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
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function createNew(string $register, string $schema, string $id): JSONResponse {
		$action = $this->createRoom(...);

		return $this->onWhenInstalled(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
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
		$action = LinkedSourceActions::unlink(unlink: fn (string $uuid) => $this->talkLinkService->unlinkRoom($uuid, $roomToken));

		return $this->onWhenInstalled(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
	}//end destroy()

	/**
	 * List Talk rooms available to the current user (picker source).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: TalkLinkServiceInterface::getAvailableRoomsForUser returns only rooms
	 *   the current user participates in; no unguarded id.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function rooms(): JSONResponse {
		return $this->whenTalkInstalled(
			answer: fn (): JSONResponse => LinkedSourceResponses::listed(
				items: $this->talkLinkService->getAvailableRoomsForUser(LinkedSourceActions::search(request: $this->request))
			)
		);
	}//end rooms()

	/**
	 * Link the Talk room the `roomToken` param names to the object: 400 without it, 201 with the link.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function linkRoom(ObjectEntity $object): JSONResponse {
		$roomToken = (string)$this->request->getParam('roomToken', '');
		if ($roomToken === '') {
			return LinkedSourceResponses::required(param: 'roomToken');
		}

		$link = $this->talkLinkService->linkRoom(
			new LinkedObject(objectUuid: $object->getUuid(), registerId: (int)$object->getRegister(), schemaId: (int)$object->getSchema()),
			$roomToken
		);

		return LinkedSourceResponses::created(link: $link);
	}//end linkRoom()

	/**
	 * Create a Talk room named by the `name` param (optional `description`, `type`) and link it: 400 without a name.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function createRoom(ObjectEntity $object): JSONResponse {
		$name = trim((string)$this->request->getParam('name', ''));
		if ($name === '') {
			return LinkedSourceResponses::required(param: 'name');
		}

		$link = $this->talkLinkService->createAndLinkRoom(
			new RoomCreation(
				object: new LinkedObject(objectUuid: $object->getUuid(), registerId: (int)$object->getRegister(), schemaId: (int)$object->getSchema()),
				roomName: $name,
				description: LinkedSourceActions::optionalString(request: $this->request, param: 'description'),
				roomType: $this->roomType()
			)
		);

		return LinkedSourceResponses::created(link: $link);
	}//end createRoom()

	/**
	 * The room type the `type` param asks for; 2 (group) when absent.
	 *
	 * @return int The Talk room type.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function roomType(): int {
		$type = $this->request->getParam('type');
		if ($type === null) {
			return 2;
		}

		return (int)$type;
	}//end roomType()

	/**
	 * List for the object its route names, when Talk is installed; 501 otherwise.
	 *
	 * @param ObjectLinkRoute $route  The object as the route names it.
	 * @param Closure         $action Receives the object; returns the response.
	 *
	 * @return JSONResponse The response.
	 */
	private function listWhenInstalled(ObjectLinkRoute $route, Closure $action): JSONResponse {
		return $this->whenTalkInstalled(answer: fn (): JSONResponse => $this->listLinkedObject(route: $route, action: $action));
	}//end listWhenInstalled()

	/**
	 * Run a link action on the object its route names, when Talk is installed; 501 otherwise.
	 *
	 * @param ObjectLinkRoute $route  The object as the route names it.
	 * @param Closure         $action Receives the object; returns the response.
	 *
	 * @return JSONResponse The response.
	 */
	private function onWhenInstalled(ObjectLinkRoute $route, Closure $action): JSONResponse {
		return $this->whenTalkInstalled(answer: fn (): JSONResponse => $this->onLinkedObject(route: $route, action: $action));
	}//end onWhenInstalled()

	/**
	 * Run the answer when the Talk app is installed; 501 otherwise.
	 *
	 * @param Closure $answer Returns the response.
	 *
	 * @return JSONResponse The answer, or 501.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function whenTalkInstalled(Closure $answer): JSONResponse {
		if ($this->talkLinkService->isTalkAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Talk');
		}

		return $answer();
	}//end whenTalkInstalled()

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
	protected function mapException(Exception $exception): JSONResponse {
		return LinkedSourceResponses::failure(exception: $exception, passThrough: [409, 404, 503]);
	}//end mapException()
}//end class
