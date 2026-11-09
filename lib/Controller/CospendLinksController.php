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

use Exception;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CospendLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
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
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param CospendLinkServiceInterface $cospendLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CospendLinkServiceInterface $cospendLinkService,
		private readonly ObjectOperationsInterface $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		if ($this->cospendLinkService->isCospendAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Cospend');
		}

		return $this->listLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->cospendLinkService->getLinkedEntries($object->getUuid()))
		);
	}//end index()

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
		if ($this->cospendLinkService->isCospendAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Cospend');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$projectId = (string)$this->request->getParam('projectId', '');
				if (trim($projectId) === '') {
					return new JSONResponse(['error' => 'projectId is required'], 400);
				}

				$billIdParam = $this->request->getParam('billId');
				$entryType = (string)$this->request->getParam('entryType', '');

				$target = $this->linkTargetOf(object: $object);

				if (($billIdParam !== null && (int)$billIdParam !== 0) || $entryType === 'bill') {
					$link = $this->cospendLinkService->linkBill($target, $projectId, (int)$billIdParam);
					return new JSONResponse($link->jsonSerialize(), 201);
				}

				$link = $this->cospendLinkService->linkProject($target, $projectId);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end link()

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
		if ($this->cospendLinkService->isCospendAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Cospend');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$name = (string)$this->request->getParam('name', '');
				if (trim($name) === '') {
					return new JSONResponse(['error' => 'name is required'], 400);
				}

				$currency = (string)$this->request->getParam('currency', '');

				$link = $this->cospendLinkService->createAndLinkProject(
					$this->linkTargetOf(object: $object),
					$name,
					$currency
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end createAndLink()

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
		if ($this->cospendLinkService->isCospendAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Cospend');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object) use ($entryId): JSONResponse {
				$this->cospendLinkService->unlink($object->getUuid(), (int)$entryId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end destroy()

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
		if ($this->cospendLinkService->isCospendAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC Cospend');
		}

		$search = $this->request->getParam('search');
		if ($search !== null) {
			$search = (string)$search;
		}

		$projects = $this->cospendLinkService->getAvailableProjects($search);
		return new JSONResponse(['results' => $projects, 'total' => count($projects)]);
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
