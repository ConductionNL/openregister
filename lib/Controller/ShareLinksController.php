<?php

/**
 * ShareLinksController — Tier-2 REST controller for NC core shares
 * linked to an OpenRegister object.
 *
 * NO LINK TABLE / NO CACHE: every endpoint delegates straight to
 * `ShareLinkServiceInterface`, which wraps `OCP\Share\IManager` (the single
 * source of truth). See `ShareLinkServiceInterface` for the rationale.
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
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Controller\Support\ShareLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\ShareDraft;
use OCA\OpenRegister\Service\ShareLinkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Share\IShare;

/**
 * Tier-2 share links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class ShareLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var ShareLinkServiceInterface
	 */
	private readonly ShareLinkServiceInterface $shareLinkService;

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
	 * @param ShareLinksControllerServices $services The controller's collaborators.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		ShareLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->shareLinkService = $services->shareLinkService;
		$this->objectService = $services->objectService;
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
		$action = $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->shareLinkService->getLinkedShares($object->getUuid()));

		return $this->onLinkedObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
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
		return $this->onLinkedObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $this->createOn(...));
	}//end create()

	/**
	 * Create the requested share on a file inside the object's folder.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return JSONResponse 400 without a file, else 201 with the share.
	 */
	private function createOn(ObjectEntity $object): JSONResponse {
		$fileId = (int)$this->request->getParam('fileId', 0);
		if ($fileId === 0) {
			return new JSONResponse(['error' => 'fileId is required'], 400);
		}

		$share = $this->shareLinkService->createShare(
			objectUuid: $object->getUuid(),
			draft: $this->requestedDraft(fileId: $fileId)
		);

		return $this->created(share: $share);
	}//end createOn()

	/**
	 * The 201 answer for a created share.
	 *
	 * @param IShare $share The created share.
	 *
	 * @return JSONResponse The answer.
	 */
	private function created(IShare $share): JSONResponse {
		return new JSONResponse(
			[
				'shareId' => (string)$share->getId(),
				'shareType' => (int)$share->getShareType(),
				'token' => (string)($share->getToken() ?? ''),
			],
			201
		);
	}//end created()

	/**
	 * The share the request body describes, on the given file.
	 *
	 * @param int $fileId The file to share.
	 *
	 * @return ShareDraft The draft.
	 */
	private function requestedDraft(int $fileId): ShareDraft {
		$shareType = (int)$this->request->getParam('shareType', -1);
		$permissions = (int)$this->request->getParam('permissions', 1);

		return (new ShareDraft(fileId: $fileId, shareType: $shareType))
			->withRecipient(shareWith: $this->optionalString(name: 'shareWith'), permissions: $permissions)
			->withProtection(
				password: $this->optionalString(name: 'password'),
				expiration: $this->optionalString(name: 'expiration')
			);
	}//end requestedDraft()

	/**
	 * A request parameter as a string, or null when it is absent.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value.
	 */
	private function optionalString(string $name): ?string {
		$value = $this->request->getParam($name);
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end optionalString()

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
		$action = fn (ObjectEntity $object): JSONResponse => $this->revokeOn(object: $object, shareId: $shareId);

		return $this->onLinkedObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
	}//end destroy()

	/**
	 * Revoke one share of the object.
	 *
	 * @param ObjectEntity $object  The object.
	 * @param string       $shareId The share.
	 *
	 * @return JSONResponse Success.
	 */
	private function revokeOn(ObjectEntity $object, string $shareId): JSONResponse {
		$this->shareLinkService->revokeShare($object->getUuid(), $shareId);

		return new JSONResponse(['success' => true]);
	}//end revokeOn()

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
		$action = $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->shareLinkService->getShareableFiles($object->getUuid()));

		return $this->onLinkedObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
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
