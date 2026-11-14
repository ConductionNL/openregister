<?php

/**
 * SeedReportController: the last seed report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Returns the report of the last seed run.
 *
 * Administrator only, by carrying no `NoAdminRequired`: the report names the
 * directory and every file in it, which is the operator's business.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */
class SeedReportController extends Controller {

	/**
	 * Build the controller.
	 *
	 * @param IRequest              $request  The request.
	 * @param SeedDirectoryImporter $importer Holds the last report.
	 */
	public function __construct(
		IRequest $request,
		private readonly SeedDirectoryImporter $importer,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The last seed report, or that no seed has run.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-every-seed-run-reports-what-it-did-and-a-partial-run-says-it-is-partial-req-smd-002
	 */
	public function show(): JSONResponse {
		return new JSONResponse(data: ['report' => $this->importer->lastReport()]);
	}//end show()
}//end class
