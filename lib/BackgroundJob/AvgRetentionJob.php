<?php

/**
 * AVG / GDPR retention enforcement TimedJob.
 *
 * Daily job that drives `AvgRetentionService::runRetentionPass()`.
 * Walks every published verwerkingsactiviteit, computes the
 * bewaartermijn cut-off, and soft-deletes any object whose latest
 * audit-trail row predates the cut-off.
 *
 * Operator overrides:
 *   - `avg_retention_enabled` (bool, default: true) — set to `false`
 *     to disable the job (e.g. during a freeze).
 *   - `avg_retention_dry_run` (bool, default: false) — set to `true`
 *     to log what *would* be erased without acting; useful for the
 *     first deployment to verify the catalog's bewaartermijn values
 *     before letting the job destroy data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\AvgRetentionPassInterface;

/**
 * Daily AVG retention enforcement.
 */
class AvgRetentionJob extends AbstractRetentionJob {

	/**
	 * App-config key for the enable/disable toggle.
	 *
	 * @var string
	 */
	protected const CONFIG_KEY_ENABLED = 'avg_retention_enabled';

	/**
	 * App-config key for the dry-run toggle.
	 *
	 * @var string
	 */
	protected const CONFIG_KEY_DRY_RUN = 'avg_retention_dry_run';

	/**
	 * Log line of a job that is switched off.
	 *
	 * @var string
	 */
	protected const LOG_DISABLED = '[AvgRetentionJob] Retention enforcement disabled (avg_retention_enabled=false), skipping';

	/**
	 * Log line of a finished pass.
	 *
	 * @var string
	 */
	protected const LOG_COMPLETE = '[AvgRetentionJob] Retention pass complete';

	/**
	 * Log line of a failed pass, followed by the error.
	 *
	 * @var string
	 */
	protected const LOG_FAILED = '[AvgRetentionJob] Retention pass failed: ';

	/**
	 * Constructor.
	 *
	 * @param ConfiguredJobRuntime $runtime The job runtime: scheduler clock, app settings and run log.
	 * @param AvgRetentionPassInterface $retentionService Domain service.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		ConfiguredJobRuntime $runtime,
		private readonly AvgRetentionPassInterface $retentionService,
	) {
		parent::__construct(runtime: $runtime);
	}//end __construct()

	/**
	 * Run the pass and report its counts.
	 *
	 * @param bool $dryRun Whether to report without erasing anything.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Dry run is the admin's own switch, passed on as it is.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	protected function runPass(bool $dryRun): array {
		$summary = $this->retentionService->runRetentionPass(dryRun: $dryRun);

		return [
			'evaluatedActivities' => $summary['evaluatedActivities'],
			'skippedActivities' => $summary['skippedActivities'],
			'objectsErased' => $summary['objectsErased'],
			// A record the archival obligation kept must be visible from the cron
			// log. Counting it as erased, or not counting it at all, is how a
			// refusal turns back into a silent skip.
			'objectsWithheld' => $summary['objectsWithheld'],
		];
	}//end runPass()
}//end class
