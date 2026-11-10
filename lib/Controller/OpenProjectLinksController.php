<?php

/**
 * OpenProjectLinksController — Tier-2 REST controller for OpenProject
 * work-package links (external / OpenConnector-routed).
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/openproject            — list linked work packages
 *   - POST   /api/objects/{r}/{s}/{id}/openproject            — link existing work package `{workPackageId}`
 *   - POST   /api/objects/{r}/{s}/{id}/openproject/new        — create + link `{projectId, subject, type?}`
 *   - DELETE /api/objects/{r}/{s}/{id}/openproject/{wpId}     — unlink work package
 *   - GET    /api/integrations/openproject/available?search=  — work-package picker source
 *
 * OpenProject is external — the install dependency is OpenConnector
 * (which carries the `openproject` source + credentials, AD-4 / AD-22).
 * When the source is unconfigured / unreachable the service raises a
 * 503-with-cause Exception so the UI degrades to a "Configure" CTA
 * rather than a broken tab (wave-5.2 4-state auth UX).
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
use OCA\OpenRegister\Controller\Support\OpenProjectLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\LinkedSourceActions;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\WorkPackageDraft;
use OCA\OpenRegister\Service\OpenProjectLinkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 OpenProject links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class OpenProjectLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var OpenProjectLinkServiceInterface
	 */
	private readonly OpenProjectLinkServiceInterface $linkService;

	/**
	 * OR object resolver.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param IRequest                           $request  HTTP request.
	 * @param OpenProjectLinksControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		OpenProjectLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->linkService = $services->linkService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List linked OpenProject work packages for an object.
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
		return $this->whenOpenConnectorInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->linkService->getLinkedWorkPackages($object->getUuid()))
			)
		);
	}//end indexForObject()

	/**
	 * Link an existing OpenProject work package.
	 *
	 * Body: `{ workPackageId: int }`.
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
		return $this->whenOpenConnectorInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: (new LinkedSourceActions(request: $this->request))->linkById(
					param: 'workPackageId',
					link: fn (ObjectEntity $object, int $workPackageId) => $this->linkService->linkWorkPackage($this->linkTargetOf(object: $object), $workPackageId)
				)
			)
		);
	}//end linkForObject()

	/**
	 * Create a new OpenProject work package and link it.
	 *
	 * Body: `{ projectId: string, subject: string, type?: string }`.
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
		return $this->whenOpenConnectorInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: (new LinkedSourceActions(request: $this->request))->linkByTexts(
					params: ['projectId', 'subject'],
					link: $this->createWorkPackage(...)
				)
			)
		);
	}//end createAndLinkForObject()

	/**
	 * Unlink an OpenProject work package.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $wpId Work-package id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function destroy(string $register, string $schema, string $id, string $wpId): JSONResponse {
		return $this->destroyForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id), wpId: $wpId);
	}//end destroy()

	/**
	 * destroy() for the object the route names.
	 *
	 * @param ObjectRef $ref  The object the route names.
	 * @param string    $wpId Work-package id (numeric).
	 *
	 * @return JSONResponse
	 */
	private function destroyForObject(ObjectRef $ref, string $wpId): JSONResponse {
		return $this->whenOpenConnectorInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: LinkedSourceActions::unlink(unlink: fn (string $uuid) => $this->linkService->unlink($uuid, (int)$wpId))
			)
		);
	}//end destroyForObject()

	/**
	 * List work packages reachable through the OpenConnector
	 * `openproject` source (picker source).
	 *
	 * Query param: `search` — optional subject substring.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function available(): JSONResponse {
		return $this->whenOpenConnectorInstalled(
			answer: $this->listAvailableWorkPackages(...)
		);
	}//end available()

	/**
	 * Create a work package in the project and link it; the optional `type` param picks its type.
	 *
	 * @param ObjectEntity $object    The object the work package is linked to.
	 * @param string       $projectId The OpenProject project id.
	 * @param string       $subject   The work package subject.
	 *
	 * @return OpenProjectLink The link.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function createWorkPackage(ObjectEntity $object, string $projectId, string $subject): OpenProjectLink {
		$type = (string)$this->request->getParam('type', '');

		return $this->linkService->createAndLinkDraft($this->linkTargetOf(object: $object), new WorkPackageDraft($projectId, $subject, $type));
	}//end createWorkPackage()

	/**
	 * The picker listing of the reachable work packages; a failure is mapped by mapException().
	 *
	 * @return JSONResponse The listing, or the mapped failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function listAvailableWorkPackages(): JSONResponse {
		$search = LinkedSourceActions::search(request: $this->request);

		try {
			return LinkedSourceResponses::listed(items: $this->linkService->getAvailableWorkPackages($search));
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}
	}//end listAvailableWorkPackages()

	/**
	 * Run the answer when the OpenConnector app is installed; 501 otherwise.
	 *
	 * @param Closure $answer Returns the response.
	 *
	 * @return JSONResponse The answer, or 501.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function whenOpenConnectorInstalled(Closure $answer): JSONResponse {
		if ($this->linkService->isOpenConnectorAvailable() === false) {
			return $this->appNotInstalled(appName: 'OpenConnector');
		}

		return $answer();
	}//end whenOpenConnectorInstalled()

	/**
	 * Map a service-layer Exception to a JSONResponse.
	 *
	 * Exception codes carry HTTP intent:
	 *   - 400 → bad request
	 *   - 401 → unauthorized (no user)
	 *   - 404 → not found
	 *   - 409 → conflict (duplicate link)
	 *   - 502 → bad gateway (malformed upstream response)
	 *   - 503 → service unavailable (source unconfigured / down)
	 *   - everything else → 400 bad request
	 *
	 * @param Exception $exception Source exception.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper: maps a service exception code to an HTTP status;
	 *              the link REST contract is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	protected function mapException(Exception $exception): JSONResponse {
		return LinkedSourceResponses::failure(exception: $exception, passThrough: [400, 401, 404, 409, 502, 503]);
	}//end mapException()
}//end class
