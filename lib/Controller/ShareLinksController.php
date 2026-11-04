<?php

/**
 * ShareLinksController — Tier-2 REST controller for NC core shares
 * linked to an OpenRegister object.
 *
 * NO LINK TABLE / NO CACHE: every endpoint delegates straight to
 * `ShareLinkService`, which wraps `OCP\Share\IManager` (the single
 * source of truth). See `ShareLinkService` for the rationale.
 *
 * Endpoints:
 *   - GET    /api/objects/{register}/{schema}/{id}/file-shares     — list
 *   - POST   /api/objects/{register}/{schema}/{id}/file-shares     — create
 *   - DELETE /api/objects/{register}/{schema}/{id}/file-shares/{shareId} — revoke
 *   - GET    /api/integrations/shares/files/{register}/{schema}/{id}      — shareable files
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ShareLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 share links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class ShareLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param ShareLinkService $shareLinkService Backing service.
	 * @param ObjectService $objectService OR object resolver.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ShareLinkService $shareLinkService,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List shares linked to an object.
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
		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$results = $this->shareLinkService->getLinkedShares($object->getUuid());

				return new JSONResponse(['results' => $results, 'total' => count($results)]);
			}
		);
	}//end index()

	/**
	 * Create a share on a file inside the object's folder.
	 *
	 * Body:
	 *   `{ fileId: int, shareType: int, shareWith?: string,
	 *      permissions: int, password?: string, expiration?: ISO-8601 }`
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
	public function create(string $register, string $schema, string $id): JSONResponse {
		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$fileId = (int)$this->request->getParam('fileId', 0);
				if ($fileId === 0) {
					return new JSONResponse(['error' => 'fileId is required'], 400);
				}

				$shareType = (int)$this->request->getParam('shareType', -1);
				$permissions = (int)$this->request->getParam('permissions', 1);
				$shareWith = $this->request->getParam('shareWith');
				$password = $this->request->getParam('password');
				$expiration = $this->request->getParam('expiration');

				$shareWithStr = null;
				if ($shareWith !== null) {
					$shareWithStr = (string)$shareWith;
				}

				$passwordStr = null;
				if ($password !== null) {
					$passwordStr = (string)$password;
				}

				$expirationStr = null;
				if ($expiration !== null) {
					$expirationStr = (string)$expiration;
				}

				$share = $this->shareLinkService->createShare(
					$object->getUuid(),
					(int)$object->getRegister(),
					(int)$object->getSchema(),
					$fileId,
					$shareType,
					$shareWithStr,
					$permissions,
					$passwordStr,
					$expirationStr
				);

				return new JSONResponse(
					[
						'shareId' => (string)$share->getId(),
						'shareType' => (int)$share->getShareType(),
						'token' => (string)($share->getToken() ?? ''),
					],
					201
				);
			}
		);
	}//end create()

	/**
	 * Revoke a share by id (ownership-checked).
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $shareId Share id to revoke.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroy(string $register, string $schema, string $id, string $shareId): JSONResponse {
		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object) use ($shareId): JSONResponse {
				$this->shareLinkService->revokeShare($object->getUuid(), $shareId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end destroy()

	/**
	 * List the files inside an object's folder that can be shared.
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
	public function files(string $register, string $schema, string $id): JSONResponse {
		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$results = $this->shareLinkService->getShareableFiles($object->getUuid());

				return new JSONResponse(['results' => $results, 'total' => count($results)]);
			}
		);
	}//end files()

	/**
	 * Map a service-layer Exception to a JSONResponse.
	 *
	 * Exception codes carry HTTP intent (401/403/404/503), everything
	 * else defaults to 400 bad request.
	 *
	 * @param Exception $exception Source exception.
	 *
	 * @return JSONResponse
	 */
	protected function mapException(Exception $exception): JSONResponse {
		$code = $exception->getCode();
		if (in_array($code, [400, 401, 403, 404, 503], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end mapException()
}//end class
