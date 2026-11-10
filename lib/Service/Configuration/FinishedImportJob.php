<?php

/**
 * OpenRegister finished import job
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * An app configuration import that has ended, as AppImportJobRecorder records it.
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-job-id-of-an-app-import-that-created-objects-must-be-recorded-per-app
 */
final class FinishedImportJob {
	/**
	 * Hold the finished job.
	 *
	 * @param string $appId          The app id the import ran under (e.g. `learniq.demo`).
	 * @param string $importJobId    The job id AppImportJobRecorder::begin() returned.
	 * @param string $version        The version the app imported.
	 * @param int    $objectsWritten How many objects the import reported writing.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $importJobId,
		public readonly string $version,
		public readonly int $objectsWritten,
	) {
	}//end __construct()
}//end class
