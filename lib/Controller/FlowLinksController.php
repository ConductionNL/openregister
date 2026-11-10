<?php

/**
 * FlowLinksController — Tier-2 REST controller for NC Flow
 * (workflowengine) operation links.
 *
 * NC Flow operations are configured by administrators in NC Workflow
 * Settings (admin-only globally). The Tier-2 OR controller exposes a
 * narrow surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/flow                  — list (read-only for non-admins)
 *   - POST   /api/objects/{r}/{s}/{id}/flow                  — link existing op (admin-only)
 *   - DELETE /api/objects/{r}/{s}/{id}/flow/{operationId}    — unlink (admin-only)
 *   - GET    /api/integrations/flow/operations               — picker source (admin-only)
 *
 * There is NO `createNew` verb: admins create operations in the
 * Workflow Settings UI (we deep-link there from the picker for
 * non-admins and from the empty state for admins). This keeps the OR
 * surface small and avoids re-implementing the NC Flow rule builder
 * (which depends on event/entity/check class resolvers).
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
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\FlowLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FlowLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Flow links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class FlowLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var FlowLinkServiceInterface
	 */
	private readonly FlowLinkServiceInterface $flowLinkService;

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
	 * @param FlowLinksControllerServices $services The flow links controller services.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function __construct(
		IRequest $request,
		FlowLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->flowLinkService = $services->flowLinkService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List linked Flow operations for an object.
	 *
	 * Available to all authenticated users (admins + non-admins);
	 * non-admins see the rows read-only.
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
		return $this->linkedOperations(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * Link an existing Flow operation. Admin-only.
	 *
	 * Body: `{ operationId: int }`.
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
		return $this->operationLinked(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
	}//end link()

	/**
	 * Unlink a Flow operation. Admin-only.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $operationId Operation id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroy(string $register, string $schema, string $id, string $operationId): JSONResponse {
		return $this->operationUnlinked(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), operationId: $operationId);
	}//end destroy()

	/**
	 * List Flow operations visible to the current user (picker source).
	 *
	 * Admin-only — non-admins receive a 403 + empty payload so the
	 * picker can degrade to a "configured by administrators" message
	 * instead of leaking the operation list.
	 *
	 * Query param: `search` — optional name substring.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function available(): JSONResponse {
		if ($this->flowLinkService->isFlowAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Flow (workflowengine)');
		}

		if ($this->flowLinkService->isCurrentUserAdmin() === false) {
			return new JSONResponse(
				[
					'error' => 'Flow operations are configured by administrators',
					'code' => 'ADMIN_ONLY',
					'results' => [],
					'total' => 0,
				],
				403
			);
		}

		$search = $this->request->getParam('search');
		if ($search !== null) {
			$search = (string)$search;
		}

		$operations = $this->flowLinkService->getAvailableOperations($search);
		return new JSONResponse(['results' => $operations, 'total' => count($operations)]);
	}//end available()

	/**
	 * The Flow operations linked to the routed object.
	 *
	 * @param ObjectLinkRoute $route The object the route names.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function linkedOperations(ObjectLinkRoute $route): JSONResponse {
		if ($this->flowLinkService->isFlowAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Flow (workflowengine)');
		}

		return $this->listLinkedObject(route: $route, action: fn (ObjectEntity $object): JSONResponse => $this->operationsOf(object: $object));
	}//end linkedOperations()

	/**
	 * The Flow operations linked to a resolved object, with the caller's admin flag.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function operationsOf(ObjectEntity $object): JSONResponse {
		$results = $this->flowLinkService->getLinkedOperations($object->getUuid());

		return new JSONResponse(
			[
				'results' => $results,
				'total' => count($results),
				'isAdmin' => $this->flowLinkService->isCurrentUserAdmin(),
			]
		);
	}//end operationsOf()

	/**
	 * Link the operation the request names to the routed object.
	 *
	 * @param ObjectLinkRoute $route The object the route names.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function operationLinked(ObjectLinkRoute $route): JSONResponse {
		if ($this->flowLinkService->isFlowAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Flow (workflowengine)');
		}

		return $this->onLinkedObject(route: $route, action: fn (ObjectEntity $object): JSONResponse => $this->linkOperationTo(object: $object));
	}//end operationLinked()

	/**
	 * Link the operation the request names to a resolved object.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function linkOperationTo(ObjectEntity $object): JSONResponse {
		$operationId = (int)$this->request->getParam('operationId', 0);
		if ($operationId === 0) {
			return new JSONResponse(['error' => 'operationId is required'], 400);
		}

		$link = $this->flowLinkService->linkOperation(target: $this->linkTargetOf(object: $object), operationId: $operationId);

		return new JSONResponse($link->jsonSerialize(), 201);
	}//end linkOperationTo()

	/**
	 * Unlink an operation from the routed object.
	 *
	 * @param ObjectLinkRoute $route       The object the route names.
	 * @param string          $operationId Operation id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper of a link route action; the link REST contract is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	private function operationUnlinked(ObjectLinkRoute $route, string $operationId): JSONResponse {
		if ($this->flowLinkService->isFlowAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Flow (workflowengine)');
		}

		return $this->onLinkedObject(
			route: $route,
			action: function (ObjectEntity $object) use ($operationId): JSONResponse {
				$this->flowLinkService->unlinkOperation($object->getUuid(), (int)$operationId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end operationUnlinked()

	/**
	 * Map a service-layer Exception to a JSONResponse.
	 *
	 * Exception codes carry HTTP intent:
	 *   - 403 → forbidden (non-admin)
	 *   - 404 → not found
	 *   - 409 → conflict (duplicate link)
	 *   - 503 → service unavailable
	 *   - 400 → bad request
	 *   - everything else → 400 bad request
	 *
	 * @param Exception $exception Source exception.
	 *
	 * @return JSONResponse
	 */
	protected function mapException(Exception $exception): JSONResponse {
		$code = $exception->getCode();
		if (in_array($code, [400, 403, 404, 409, 503], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end mapException()
}//end class
