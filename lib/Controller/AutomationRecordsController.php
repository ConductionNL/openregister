<?php

/**
 * OpenRegister AutomationRecordsController
 *
 * The read route over automation run records.
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
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\AutomationRecordFilter;
use OCA\OpenRegister\Service\AutomationRecordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * `GET /api/automation-records`: notification and lifecycle-action run
 * records, filtered by kind, key prefix, schema, object and time range.
 *
 * Signed-in callers only; what each caller sees is decided by
 * AutomationRecordService (D-4): an admin everything, anyone else only records
 * of objects they may read.
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
class AutomationRecordsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request     The request.
	 * @param AutomationRecordService $records     Reads the records under the caller's rules.
	 * @param IUserSession            $userSession The signed-in caller.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function __construct(
		IRequest $request,
		private readonly AutomationRecordService $records,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The records the caller may see.
	 *
	 * Query: `kind` (notification|lifecycle-action), `key` (prefix), `schema`,
	 * `object`, `since`, `until`, `limit` (max 500), `offset`.
	 *
	 * @return JSONResponse `results`, `limit` and `offset`; 401 when nobody is signed in; 500 when the store fails.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md#requirement-every-dispatch-of-a-declared-notification-leaves-a-readable-record
	 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md#requirement-every-lifecycle-action-reached-leaves-an-outcome-record
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$filter = AutomationRecordFilter::fromParams(params: $this->request->getParams());
		try {
			$results = $this->records->find($filter);
		} catch (Throwable $e) {
			unset($e);
			return new JSONResponse(data: ['error' => 'Automation records could not be read'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			data: [
				'results' => $results,
				'limit'   => $filter->limit,
				'offset'  => $filter->offset,
			]
		);
	}//end index()
}//end class
