<?php

/**
 * OpenRegister recorded background job runtime
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What a job that records its runs runs on: the clock the job scheduler reads and the recorder that writes the run row.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class RecordedJobRuntime {
	/**
	 * Bundle the runtime.
	 *
	 * @param ITimeFactory            $time     Clock handed to the Nextcloud job base class.
	 * @param JobRunRecorderInterface $recorder The wrapper that writes the run row.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		public readonly ITimeFactory $time,
		public readonly JobRunRecorderInterface $recorder,
	) {
	}//end __construct()
}//end class
