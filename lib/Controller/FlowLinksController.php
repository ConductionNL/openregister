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
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param FlowLinkServiceInterface $flowLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FlowLinkServiceInterface $flowLinkService,
		private readonly ObjectOperationsInterface $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		if ($this->flowLinkService->isFlowAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Flow (workflowengine)');
		}

		return $this->listLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$results = $this->flowLinkService->getLinkedOperations($object->getUuid());

				return new JSONResponse(
					[
						'results' => $results,
						'total' => count($results),
						'isAdmin' => $this->flowLinkService->isCurrentUserAdmin(),
					]
				);
			}
		);
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
		if ($this->flowLinkService->isFlowAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Flow (workflowengine)');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$operationId = (int)$this->request->getParam('operationId', 0);
				if ($operationId === 0) {
					return new JSONResponse(['error' => 'operationId is required'], 400);
				}

				$link = $this->flowLinkService->linkOperation(
					$object->getUuid(),
					(int)$object->getRegister(),
					(int)$object->getSchema(),
					$operationId
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
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
		if ($this->flowLinkService->isFlowAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Flow (workflowengine)');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object) use ($operationId): JSONResponse {
				$this->flowLinkService->unlinkOperation($object->getUuid(), (int)$operationId);

				return new JSONResponse(['success' => true]);
			}
		);
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
