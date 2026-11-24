<?php
/**
 * AppImportJobOwnersInterface: the app an import job loaded data for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Names the app whose configuration import created an import job.
 *
 * The register API's rollback (Core) asks before it removes a job's objects,
 * because an app's example data leaves through the app; the configuration import
 * (Configuration) records the jobs and binds its recorder in its own bindings.
 */
interface AppImportJobOwnersInterface {

	/**
	 * The app id a recorded job belongs to, or null when no app recorded it.
	 *
	 * @param string $importJobId The job id to look up.
	 *
	 * @return string|null The app id.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-http-rollback-route-must-refuse-an-app-imports-job-id
	 */
	public function appForJob(string $importJobId): ?string;
}//end interface
