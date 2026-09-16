<?php

/**
 * The operations console's HTTP surface.
 *
 * Three reads over what the instance is doing: the panes with their counts,
 * the job pane's rows with the verbs each row allows, and the rules engine's
 * recent runs with the rules holding an error.
 *
 * ADMINISTRATORS ONLY, AND BY THE MIDDLEWARE. No method here carries
 * `#[NoAdminRequired]`, so Nextcloud rejects a non-administrator before the
 * controller is constructed. An in-body check behind `#[NoAdminRequired]`
 * would put the only barrier in a line a refactor can delete without the
 * pipeline noticing, which is the posture `AdminOnlyPostureTest` exists to
 * refuse.
 *
 * The acting verbs are deliberately absent. Pausing, resuming, retrying and
 * cancelling a job already have one home on `BulkJobsController`, with one
 * ownership rule; a second copy on this controller would be a second place to
 * get that rule wrong. The console calls the endpoints that exist.
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
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\OperationsConsoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * OperationsConsoleController.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */
class OperationsConsoleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName Application name.
	 * @param IRequest                 $request HTTP request.
	 * @param OperationsConsoleService $console The read model.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OperationsConsoleService $console,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The console's panes over a window.
	 *
	 * @return JSONResponse The window and the panes.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(
			data: $this->console->panes(windowHours: $this->intParam(name: 'hours', fallback: OperationsConsoleService::DEFAULT_WINDOW_HOURS))
		);
	}//end index()

	/**
	 * The job pane: the bulk jobs, and the inventory they sit in.
	 *
	 * @return JSONResponse The jobs, the registered inventory and the unobserved ones.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function jobs(): JSONResponse {
		$state = $this->request->getParam('state');

		return new JSONResponse(
			data: $this->console->jobs(
				state: (is_string($state) === true && $state !== '') ? $state : null,
				limit: $this->intParam(name: 'limit', fallback: 50)
			)
		);
	}//end jobs()

	/**
	 * The rules engine's recent runs, across every rule.
	 *
	 * @return JSONResponse The runs and the rules holding an error.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function ruleRuns(): JSONResponse {
		return new JSONResponse(
			data: $this->console->ruleRuns(
				windowHours: $this->intParam(name: 'hours', fallback: OperationsConsoleService::DEFAULT_WINDOW_HOURS),
				limit: $this->intParam(name: 'limit', fallback: 50)
			)
		);
	}//end ruleRuns()

	/**
	 * Read a whole-number request parameter, or fall back.
	 *
	 * A parameter that is not a number falls back rather than reading as
	 * zero, because zero is a window this service would then bound to one
	 * hour and report as if it had been asked for.
	 *
	 * @param string $name     The parameter name.
	 * @param int    $fallback The value to use when it is absent or malformed.
	 *
	 * @return int The value.
	 */
	private function intParam(string $name, int $fallback): int {
		$value = $this->request->getParam($name);

		if (is_numeric($value) === false) {
			return $fallback;
		}

		return (int)$value;
	}//end intParam()
}//end class
