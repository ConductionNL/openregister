<?php

/**
 * DSAR case retention-sweep TimedJob.
 *
 * Daily job that drives {@see RetentionSweepService::runSweep()}: hard-deletes
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

use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily DSAR-case retention sweep.
 */
class DsarRetentionSweepJob extends TimedJob {

	/**
	 * Default interval — once per 24 hours.
	 *
	 * @var int
	 */
	private const RUN_INTERVAL_SECONDS = 86400;

	/**
	 * App-config key for the enable/disable toggle.
	 *
	 * @var string
	 */
	private const CONFIG_KEY_ENABLED = 'dsar_retention_sweep_enabled';

	/**
	 * App-config key for the dry-run toggle.
	 *
	 * @var string
	 */
	private const CONFIG_KEY_DRY_RUN = 'dsar_retention_sweep_dry_run';

	/**
	 * App identifier for app-config lookups.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory required by parent.
	 * @param IAppConfig $appConfig App-config reader.
	 * @param RetentionSweepService $sweepService Domain sweep service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly RetentionSweepService $sweepService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::RUN_INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Drive the retention sweep.
	 *
	 * @param mixed $argument Job arguments (unused for recurring jobs).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-retention-sweep/spec.md
	 */
	protected function run($argument): void {
		$enabled = filter_var(
			$this->appConfig->getValueString(
				app: self::APP_ID,
				key: self::CONFIG_KEY_ENABLED,
				default: 'true'
			),
			FILTER_VALIDATE_BOOLEAN
		);

		if ($enabled === false) {
			$this->logger->info(
				message: '[DsarRetentionSweepJob] sweep disabled (dsar_retention_sweep_enabled=false), skipping',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		$dryRun = filter_var(
			$this->appConfig->getValueString(
				app: self::APP_ID,
				key: self::CONFIG_KEY_DRY_RUN,
				default: 'false'
			),
			FILTER_VALIDATE_BOOLEAN
		);

		try {
			$summary = $this->sweepService->runSweep(dryRun: $dryRun);
			$this->logger->info(
				message: '[DsarRetentionSweepJob] sweep complete',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'dryRun' => $dryRun,
					'evaluated' => $summary['evaluated'],
					'purgedCount' => count($summary['purged']),
					'skippedHeld' => count($summary['skippedHeld']),
					'withinWindow' => $summary['withinWindow'],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[DsarRetentionSweepJob] sweep failed: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}//end try

	}//end run()
}//end class
