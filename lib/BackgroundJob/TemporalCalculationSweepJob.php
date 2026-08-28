<?php

/**
 * OpenRegister Temporal Calculation Sweep Job
 *
 * Hourly TimedJob driving
 * {@see \OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService}:
 * re-materialises `now`-dependent calculated fields (e.g. the DSAR
 * `escalationTier`) for objects in non-terminal lifecycle states so the
 * schema-declared `calculatedChange` notification rules (deadline reminder /
 * escalation / breach) fire without anyone editing the object.
 *
 * Operator overrides (IAppConfig, `DsarRetentionSweepJob` convention):
 *   - `temporal_calculation_sweep_enabled` (bool, default: true)
 *   - `temporal_calculation_sweep_interval` (seconds, default: 3600, min 300)
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Hourly temporal re-evaluation sweep.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC background-job framework (appinfo/info.xml).
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
 */
class TemporalCalculationSweepJob extends TimedJob {

	/**
	 * Default interval — hourly (the tier boundaries are day-granular, so
	 * hourly keeps the worst-case dispatch delay well inside a day).
	 *
	 * @var int
	 */
	private const DEFAULT_INTERVAL_SECONDS = 3600;

	/**
	 * Minimum permitted operator-configured interval.
	 *
	 * @var int
	 */
	private const MIN_INTERVAL_SECONDS = 300;

	/**
	 * App-config key for the enable/disable toggle.
	 *
	 * @var string
	 */
	private const CONFIG_KEY_ENABLED = 'temporal_calculation_sweep_enabled';

	/**
	 * App-config key for the interval override.
	 *
	 * @var string
	 */
	private const CONFIG_KEY_INTERVAL = 'temporal_calculation_sweep_interval';

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
	 * @param TemporalCalculationSweepService $sweepService Domain sweep service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly TemporalCalculationSweepService $sweepService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$interval = (int)$appConfig->getValueString(
			app: self::APP_ID,
			key: self::CONFIG_KEY_INTERVAL,
			default: (string)self::DEFAULT_INTERVAL_SECONDS
		);
		if ($interval < self::MIN_INTERVAL_SECONDS) {
			$interval = self::DEFAULT_INTERVAL_SECONDS;
		}

		$this->setInterval(seconds: $interval);

	}//end __construct()

	/**
	 * Drive the temporal re-evaluation sweep.
	 *
	 * @param mixed $argument Job arguments (unused for recurring jobs).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Untouched case crosses the reminder tier)
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
				message: '[TemporalCalculationSweepJob] sweep disabled (temporal_calculation_sweep_enabled=false), skipping',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		try {
			$summary = $this->sweepService->runSweep();
			if ($summary['objectsRewritten'] > 0 || $summary['errors'] > 0) {
				$this->logger->info(
					message: '[TemporalCalculationSweepJob] sweep complete',
					context: array_merge(['file' => __FILE__, 'line' => __LINE__], $summary)
				);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[TemporalCalculationSweepJob] sweep failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

	}//end run()
}//end class
