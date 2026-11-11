<?php

/**
 * CospendLinksController — Tier-2 REST controller for NC Cospend (Costs)
 * project + bill links.
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/cospend             — list linked entries
 *   - POST   /api/objects/{r}/{s}/{id}/cospend             — link existing project/bill
 *   - POST   /api/objects/{r}/{s}/{id}/cospend/new         — create + link project
 *   - DELETE /api/objects/{r}/{s}/{id}/cospend/{entryId}   — unlink an entry
 *   - GET    /api/integrations/cospend/available?search=   — project picker source
 *
 * The link POST handles BOTH project and bill rows: a `billId` in the
 * body (or `entryType: bill`) links the specific bill, otherwise the
 * whole project is linked. NC Cospend entities are user-scoped, so there
 * is no admin gate — the active session's user owns the projects it can
 * link/create.
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

use OCA\OpenRegister\Service\CospendProjectDraft;
use Closure;
use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\CospendLinksControllerServices;
use OCA\OpenRegister\Controller\Support\LinkedSourceActions;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CospendLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Cospend links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class CospendLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var CospendLinkServiceInterface
	 */
	private readonly CospendLinkServiceInterface $cospendLinkService;

	/**
	 * OR object resolver.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param CospendLinksControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		CospendLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->cospendLinkService = $services->cospendLinkService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List linked Cospend entries for an object.
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
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->indexFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * List linked Cospend entries for an object: the body of index() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function indexFor(ObjectRef $ref): JSONResponse {
		return $this->whenCospendInstalled(
			answer: fn (): JSONResponse => $this->listLinkedObject(
				route: $this->routeOf(ref: $ref),
				action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->cospendLinkService->getLinkedEntries($object->getUuid()))
			)
		);
	}//end indexFor()

	/**
	 * Link an existing Cospend project or bill.
	 *
	 * Body: `{ projectId: string, billId?: int, entryType?: string }`.
	 * A non-empty `billId` (or `entryType: 'bill'`) links the bill;
	 * otherwise the whole project is linked.
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
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function link(string $register, string $schema, string $id): JSONResponse {
		return $this->linkFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end link()

	/**
	 * Link an existing Cospend project or bill: the body of link() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function linkFor(ObjectRef $ref): JSONResponse {
		return $this->whenCospendInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: $this->routeOf(ref: $ref),
				action: fn (ObjectEntity $object): JSONResponse => $this->linkEntryFor(object: $object)
			)
		);
	}//end linkFor()

	/**
	 * Link the requested project, or one of its bills, to the object.
	 *
	 * A bill is linked when the request names a non-zero `billId` or says
	 * `entryType: bill`; otherwise the project itself.
	 *
	 * @param ObjectEntity $object The object the entry is linked to.
	 *
	 * @return JSONResponse 201 with the link, or 400 when the project is missing.
	 */
	private function linkEntryFor(ObjectEntity $object): JSONResponse {
		$projectId = (string)$this->request->getParam('projectId', '');
		if (trim($projectId) === '') {
			return LinkedSourceResponses::required(param: 'projectId');
		}

		$billIdParam = $this->request->getParam('billId');
		$entryType = (string)$this->request->getParam('entryType', '');

		$target = $this->linkTargetOf(object: $object);

		if (($billIdParam !== null && (int)$billIdParam !== 0) || $entryType === 'bill') {
			return LinkedSourceResponses::created(link: $this->cospendLinkService->linkBill($target, $projectId, (int)$billIdParam));
		}

		return LinkedSourceResponses::created(link: $this->cospendLinkService->linkProject($target, $projectId));
	}//end linkEntryFor()

	/**
	 * Create a new Cospend project and link it.
	 *
	 * Body: `{ name: string, currency?: string }`.
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
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function createAndLink(string $register, string $schema, string $id): JSONResponse {
		return $this->createAndLinkFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end createAndLink()

	/**
	 * Create a new Cospend project and link it: the body of createAndLink() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function createAndLinkFor(ObjectRef $ref): JSONResponse {
		return $this->whenCospendInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: $this->routeOf(ref: $ref),
				action: fn (ObjectEntity $object): JSONResponse => $this->createProjectFor(object: $object)
			)
		);
	}//end createAndLinkFor()

	/**
	 * Create the requested project and link it to the object.
	 *
	 * @param ObjectEntity $object The object the project is linked to.
	 *
	 * @return JSONResponse 201 with the link, or 400 when the name is missing.
	 */
	private function createProjectFor(ObjectEntity $object): JSONResponse {
		$name = (string)$this->request->getParam('name', '');
		if (trim($name) === '') {
			return LinkedSourceResponses::required(param: 'name');
		}

		$currency = (string)$this->request->getParam('currency', '');

		$link = $this->cospendLinkService->createAndLinkProject(
			target: $this->linkTargetOf(object: $object),
			draft: new CospendProjectDraft(name: $name, currency: $currency)
		);

		return LinkedSourceResponses::created(link: $link);
	}//end createProjectFor()

	/**
	 * Unlink a Cospend entry.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $entryId Link row id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroy(string $register, string $schema, string $id, string $entryId): JSONResponse {
		return $this->destroyFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id), entryId: $entryId);
	}//end destroy()

	/**
	 * Unlink a Cospend entry: the body of destroy() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 * @param string $entryId Link row id (numeric).
	 *
	 * @return JSONResponse
	 */
	private function destroyFor(ObjectRef $ref, string $entryId): JSONResponse {
		return $this->whenCospendInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: $this->routeOf(ref: $ref),
				action: LinkedSourceActions::unlink(unlink: fn (string $uuid) => $this->cospendLinkService->unlink($uuid, (int)$entryId))
			)
		);
	}//end destroyFor()

	/**
	 * List the current user's Cospend projects (picker source).
	 *
	 * Query param: `search` — optional name substring.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: returns the current user's own Cospend projects; no caller-supplied object id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function available(): JSONResponse {
		return $this->whenCospendInstalled(
			answer: fn (): JSONResponse => LinkedSourceResponses::listed(
				items: $this->cospendLinkService->getAvailableProjects(LinkedSourceActions::search(request: $this->request))
			)
		);
	}//end available()

	/**
	 * Answer only when NC Cospend is installed; 501 otherwise.
	 *
	 * @param Closure $answer Produces the answer when the app is installed.
	 *
	 * @return JSONResponse The answer, or the 501.
	 */
	private function whenCospendInstalled(Closure $answer): JSONResponse {
		if ($this->cospendLinkService->isCospendAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Cospend');
		}

		return $answer();
	}//end whenCospendInstalled()

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
