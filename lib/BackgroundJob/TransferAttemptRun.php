<?php

/**
 * OpenRegister transfer attempt run
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

/**
 * One transfer attempt of a TransferExecutionJob run: which list, which attempt, and the list as it stands.
 *
 * Built by TransferExecutionJob::run() and handed to its helpers; it never leaves the job.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
final class TransferAttemptRun {
	/**
	 * The transfer list as it stands after loading, then after the attempt.
	 *
	 * @var array<string, mixed>
	 */
	public array $list = [];

	/**
	 * Start the run.
	 *
	 * @param string $transferListId The transfer list id.
	 * @param int    $attempt        The 1-based attempt number.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	public function __construct(
		public readonly string $transferListId,
		public readonly int $attempt,
	) {
	}//end __construct()
}//end class
