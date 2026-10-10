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

use DateTime;
use Exception;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
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
	 * @param ExportRightService $rights      Decides whether the caller sees every run.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ExportRunRecorder $runs,
		private readonly IUserSession $userSession,
		private readonly ExportRightService $rights,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/exports - the runs this caller made.
	 *
	 * Filters on register, schema, profile, source, status and actor, and on
	 * the period `from` / `until` (ISO 8601, inclusive) over when the export
	 * was produced. An unknown filter key is dropped rather than widening the
	 * result; a date that does not parse is refused with 400, because
	 * dropping it would widen the result too. Whether the caller sees every
	 * run or only their own is answered by ExportRightService.
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
		foreach (['register', 'schema', 'profile', 'source', 'status', 'actor'] as $key) {
			$value = $this->request->getParam(key: $key);
			if ($value !== null && $value !== '') {
				$filters[$key] = (string)$value;
			}
		}

		$period = [];
		foreach (['from', 'until'] as $key) {
			$value = $this->request->getParam(key: $key);
			if ($value === null || $value === '') {
				continue;
			}

			try {
				$period[$key] = new DateTime((string)$value);
			} catch (Exception) {
				return new JSONResponse(
					data: ['error' => sprintf('The %s date "%s" is not a date.', $key, (string)$value)],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			$filters[$key] = (string)$value;
		}

		$results = $this->runs->listFor(
			actor: $user->getUID(),
			isAdmin: $this->rights->seesEveryExportRun(userId: $user->getUID()),
			filters: array_merge($filters, $period),
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
