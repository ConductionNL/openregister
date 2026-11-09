<?php

/**
 * DSAR case retention-sweep TimedJob.
 *
 * Daily job that drives {@see RetentionSweepInterface::runSweep()}: hard-deletes
 * data-subject-request case dossiers past their `retainUntil`, scrubs their
 * evidence PII via the erase pseudonymise primitive, and is legal-hold aware.
 * Mirrors {@see AvgRetentionJob}'s IAppConfig enabled + dry-run toggle contract.
 *
 * Operator overrides:
 *   - `dsar_retention_sweep_enabled` (bool, default: true) — set `false` to
 *     disable the sweep (e.g. during a freeze).
 *   - `dsar_retention_sweep_dry_run` (bool, default: false) — set `true` to log
 *     which expired cases WOULD be purged without destroying anything; useful
 *     for the first deployment to verify the retention windows before letting
 *     the job destroy data.
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

use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily DSAR-case retention sweep.
 */
class DsarRetentionSweepJob extends AbstractRetentionJob {

	/**
	 * App-config key for the enable/disable toggle.
	 *
	 * @var string
	 */
	protected const CONFIG_KEY_ENABLED = 'dsar_retention_sweep_enabled';

	/**
	 * App-config key for the dry-run toggle.
	 *
	 * @var string
	 */
	protected const CONFIG_KEY_DRY_RUN = 'dsar_retention_sweep_dry_run';

	/**
	 * Log line of a job that is switched off.
	 *
	 * @var string
	 */
	protected const LOG_DISABLED = '[DsarRetentionSweepJob] sweep disabled (dsar_retention_sweep_enabled=false), skipping';

	/**
	 * Log line of a finished pass.
	 *
	 * @var string
	 */
	protected const LOG_COMPLETE = '[DsarRetentionSweepJob] sweep complete';

	/**
	 * Log line of a failed pass, followed by the error.
	 *
	 * @var string
	 */
	protected const LOG_FAILED = '[DsarRetentionSweepJob] sweep failed: ';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory required by parent.
	 * @param IAppConfig $appConfig App-config reader.
	 * @param RetentionSweepInterface $sweepService Domain sweep service.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/dsar-retention-sweep/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		IAppConfig $appConfig,
		private readonly RetentionSweepInterface $sweepService,
		LoggerInterface $logger,
	) {
		parent::__construct(time: $time, appConfig: $appConfig, logger: $logger);
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
	 * @spec openspec/specs/dsar-retention-sweep/spec.md
	 */
	protected function runPass(bool $dryRun): array {
		$summary = $this->sweepService->runSweep(dryRun: $dryRun);

		return [
			'evaluated' => $summary['evaluated'],
			'purgedCount' => count($summary['purged']),
			'skippedHeld' => count($summary['skippedHeld']),
			'withinWindow' => $summary['withinWindow'],
			// Cases the archival obligation kept. Without this the sweep log reads
			// as if they simply were not due.
			'withheldCount' => $summary['withheldCount'],
		];
	}//end runPass()
}//end class
