<?php

/**
 * Class ExportRunsController
 *
 * The exports area: which copies of the register have left the building, who
 * made them, how many rows they carried, when their file stops existing and
 * how often the register served it.
 *
 * Scoped in the body to the caller's own runs. An administrator sees every
 * run, which is the whole point of the area: "who holds an export of this
 * register" is a question an administrator is asked rather than one they
 * choose to ask.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Lists the produced exports.
 */
class ExportRunsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string            $appName      The app name.
	 * @param IRequest          $request      The request.
	 * @param ExportRunRecorder $runs         The export runs.
	 * @param IUserSession      $userSession  Resolves the caller.
	 * @param IGroupManager     $groupManager Decides whether the caller is an administrator.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ExportRunRecorder $runs,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/exports - the runs this caller made.
	 *
	 * Filters on register, schema, profile, source and status. An unknown
	 * filter key is dropped rather than widening the result.
	 *
	 * @return JSONResponse The runs, or 401 when anonymous.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body: the listing is scoped to the SESSION's user, and takes no
	 *     actor from the request that could name somebody else's runs.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$limit = (int)($this->request->getParam(key: 'limit') ?? 50);
		$offset = (int)($this->request->getParam(key: 'offset') ?? 0);

		$filters = [];
		foreach (['register', 'schema', 'profile', 'source', 'status'] as $key) {
			$value = $this->request->getParam(key: $key);
			if ($value !== null && $value !== '') {
				$filters[$key] = (string)$value;
			}
		}

		$results = $this->runs->listFor(
			actor: $user->getUID(),
			isAdmin: $this->groupManager->isAdmin($user->getUID()),
			filters: $filters,
			limit: max(1, min($limit, 200)),
			offset: max(0, $offset)
		);

		return new JSONResponse(
			data: [
				'results' => $results,
				'limit' => $limit,
				'offset' => $offset,
				'filters' => $filters,
			]
		);
	}//end index()
}//end class
