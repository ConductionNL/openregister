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

use Exception;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
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
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param PhotoLinkServiceInterface $photoLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PhotoLinkServiceInterface $photoLinkService,
		private readonly ObjectOperationsInterface $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		if ($this->photoLinkService->isPhotosAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Photos');
		}

		return $this->listLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->photoLinkService->getLinkedAlbums($object->getUuid()))
		);
	}//end index()

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
		if ($this->photoLinkService->isPhotosAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Photos');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$albumId = (int)$this->request->getParam('albumId', 0);
				if ($albumId === 0) {
					return new JSONResponse(['error' => 'albumId is required'], 400);
				}

				$link = $this->photoLinkService->linkAlbum(
					$this->linkTargetOf(object: $object),
					$albumId
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end link()

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
		if ($this->photoLinkService->isPhotosAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Photos');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: $this->namedLinkOf(create: $this->photoLinkService->createAndLinkAlbum(...))
		);
	}//end createAndLink()

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
		if ($this->photoLinkService->isPhotosAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Photos');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object) use ($albumId): JSONResponse {
				$this->photoLinkService->unlinkAlbum($object->getUuid(), (int)$albumId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end destroy()

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
		if ($this->photoLinkService->isPhotosAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Photos');
		}

		$search = $this->request->getParam('search');
		if ($search !== null) {
			$search = (string)$search;
		}

		$albums = $this->photoLinkService->getAvailableAlbums($search);
		return new JSONResponse(['results' => $albums, 'total' => count($albums)]);
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
	 */
	protected function mapException(Exception $exception): JSONResponse {
		$code = $exception->getCode();
		if (in_array($code, [400, 401, 404, 409, 503], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end mapException()
}//end class
