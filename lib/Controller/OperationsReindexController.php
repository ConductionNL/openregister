<?php

/**
 * OperationsReindexController: an administrator reindexes a selection from the operations console.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\Operations\ReindexRefusedException;
use OCA\OpenRegister\Service\Operations\ReindexSelectionServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Administrator-only: the method declares no `#[NoAdminRequired]`, so
 * Nextcloud refuses a non-administrator with 403 before this controller is
 * built, and CSRF stays required on the write.
 *
 * Not reachable in maintenance mode, unlike the console's reads: a reindex
 * writes the search index, and maintenance mode holds writes.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
 */
class OperationsReindexController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request     The request.
	 * @param ReindexSelectionServiceInterface $reindex     Resolves, refuses or queues the selection.
	 * @param IUserSession                     $userSession The administrator starting it.
	 */
	public function __construct(
		IRequest $request,
		private readonly ReindexSelectionServiceInterface $reindex,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/operations/reindex: queue a reindex of exactly one selection.
	 *
	 * @return JSONResponse 202 with the run key, the selection kind and its total; 400 naming why the selection is refused.
	 *
	 * @auth admin-only declares no NoAdminRequired attribute, so the middleware
	 *       refuses a non-administrator before this controller is built, and
	 *       CSRF stays required on the write.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	public function reindex(): JSONResponse {
		$params = [];
		foreach (['register', 'schema', 'filters', 'ids'] as $name) {
			$params[$name] = $this->request->getParam($name);
		}

		try {
			$queued = $this->reindex->start(params: $params, actor: (string)$this->userSession->getUser()?->getUID());
		} catch (ReindexRefusedException $refusal) {
			return new JSONResponse(data: ['error' => 'refused', 'message' => $refusal->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $queued, statusCode: Http::STATUS_ACCEPTED);
	}//end reindex()
}//end class
