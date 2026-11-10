<?php

/**
 * PhotoLinksController — Tier-2 REST controller for NC Photos album
 * links.
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/photos             — list linked albums
 *   - POST   /api/objects/{r}/{s}/{id}/photos             — link existing album `{albumId}`
 *   - POST   /api/objects/{r}/{s}/{id}/photos/new         — create + link album `{name}`
 *   - DELETE /api/objects/{r}/{s}/{id}/photos/{albumId}   — unlink album
 *   - GET    /api/integrations/photos/available?search=   — picker source
 *
 * NC Photos albums are user-scoped, so (unlike Flow) there is no admin
 * gate — the active session's user owns the albums it can link/create.
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

use Closure;
use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\PhotoLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\LinkedSourceActions;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\PhotoLinkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Photos links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class PhotoLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var PhotoLinkServiceInterface
	 */
	private readonly PhotoLinkServiceInterface $photoLinkService;

	/**
	 * OR object resolver.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request  HTTP request.
	 * @param PhotoLinksControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		PhotoLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->photoLinkService = $services->photoLinkService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List linked Photos albums for an object.
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
		return $this->indexForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * index() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function indexForObject(ObjectRef $ref): JSONResponse {
		return $this->whenPhotosInstalled(
			answer: fn (): JSONResponse => $this->listLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->photoLinkService->getLinkedAlbums($object->getUuid()))
			)
		);
	}//end indexForObject()

	/**
	 * Link an existing Photos album.
	 *
	 * Body: `{ albumId: int }`.
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
		return $this->linkForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end link()

	/**
	 * link() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function linkForObject(ObjectRef $ref): JSONResponse {
		return $this->whenPhotosInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: (new LinkedSourceActions(request: $this->request))->linkById(
					param: 'albumId',
					link: fn (ObjectEntity $object, int $albumId) => $this->photoLinkService->linkAlbum($this->linkTargetOf(object: $object), $albumId)
				)
			)
		);
	}//end linkForObject()

	/**
	 * Create a new Photos album and link it.
	 *
	 * Body: `{ name: string }`.
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
	public function createAndLink(string $register, string $schema, string $id): JSONResponse {
		return $this->createAndLinkForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end createAndLink()

	/**
	 * createAndLink() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function createAndLinkForObject(ObjectRef $ref): JSONResponse {
		return $this->whenPhotosInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: $this->namedLinkOf(create: $this->photoLinkService->createAndLinkAlbum(...))
			)
		);
	}//end createAndLinkForObject()

	/**
	 * Unlink a Photos album.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $albumId Album id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroy(string $register, string $schema, string $id, string $albumId): JSONResponse {
		return $this->destroyForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id), albumId: $albumId);
	}//end destroy()

	/**
	 * destroy() for the object the route names.
	 *
	 * @param ObjectRef $ref     The object the route names.
	 * @param string    $albumId Album id (numeric).
	 *
	 * @return JSONResponse
	 */
	private function destroyForObject(ObjectRef $ref, string $albumId): JSONResponse {
		return $this->whenPhotosInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: LinkedSourceActions::unlink(unlink: fn (string $uuid) => $this->photoLinkService->unlinkAlbum($uuid, (int)$albumId))
			)
		);
	}//end destroyForObject()

	/**
	 * List the current user's Photos albums (picker source).
	 *
	 * Query param: `search` — optional name substring.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: returns the current user's own Photos albums; no caller-supplied object id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function available(): JSONResponse {
		return $this->whenPhotosInstalled(
			answer: fn (): JSONResponse => LinkedSourceResponses::listed(
				items: $this->photoLinkService->getAvailableAlbums(LinkedSourceActions::search(request: $this->request))
			)
		);
	}//end available()

	/**
	 * Run the answer when the Photos app is installed; 501 otherwise.
	 *
	 * @param Closure $answer Returns the response.
	 *
	 * @return JSONResponse The answer, or 501.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function whenPhotosInstalled(Closure $answer): JSONResponse {
		if ($this->photoLinkService->isPhotosAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Photos');
		}

		return $answer();
	}//end whenPhotosInstalled()

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
	 */
	protected function mapException(Exception $exception): JSONResponse {
		return LinkedSourceResponses::failure(exception: $exception, passThrough: [400, 401, 404, 409, 503]);
	}//end mapException()
}//end class
