<?php

/**
 * The dependency breakers in the operations console: list them and reset one.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\Configuration\MaintenanceReachableInterface;
use OCA\OpenRegister\Kernel\Contracts\DependencyConsoleInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Administrator-only: neither method declares `#[NoAdminRequired]`, so
 * Nextcloud refuses a non-administrator with 403 before this controller is
 * built (design D-7). The reset keeps CSRF required.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class OperationsDependenciesController extends Controller implements MaintenanceReachableInterface {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The request.
	 * @param DependencyConsoleInterface $console The breakers' list and reset.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly DependencyConsoleInterface $console,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/operations/dependencies: every breaker with state, and the database pressure.
	 *
	 * @return JSONResponse The overview.
	 *
	 * @auth admin-only declares no NoAdminRequired attribute, so the middleware
	 *       refuses a non-administrator before this controller is built.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(data: $this->console->overview());
	}//end index()

	/**
	 * POST /api/operations/dependencies/{key}/reset: close a breaker, audited as `dependency.reset`.
	 *
	 * @param string $key The breaker key, for example `llm` or `source:12`.
	 *
	 * @return JSONResponse The breaker's state after the reset, or 404 for a key without a breaker.
	 *
	 * @auth admin-only declares no NoAdminRequired attribute, so the middleware
	 *       refuses a non-administrator before this controller is built, and
	 *       CSRF stays required on the write.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function reset(string $key): JSONResponse {
		$state = $this->console->reset(key: $key);
		if ($state === null) {
			return new JSONResponse(data: ['error' => 'No breaker with that key.'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $state);
	}//end reset()
}//end class
