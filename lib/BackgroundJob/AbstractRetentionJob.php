<?php

/**
 * What the daily retention jobs share: their switches, their run and their log lines.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * A daily job an admin can switch off or put in dry run.
 *
 * A subclass names its config keys and log lines in constants and runs its pass
 * in {@see self::runPass()}. A failed pass is logged, not thrown.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
abstract class AbstractRetentionJob extends TimedJob {

	/**
	 * Default interval, once per 24 hours.
	 *
	 * @var int
	 */
	private const RUN_INTERVAL_SECONDS = 86400;

	/**
	 * App identifier for app-config lookups.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * App-config key for the enable/disable toggle.
	 *
	 * @var string
	 */
	protected const CONFIG_KEY_ENABLED = '';

	/**
	 * App-config key for the dry-run toggle.
	 *
	 * @var string
	 */
	protected const CONFIG_KEY_DRY_RUN = '';

	/**
	 * Log line of a job that is switched off.
	 *
	 * @var string
	 */
	protected const LOG_DISABLED = '';

	/**
	 * Log line of a finished pass.
	 *
	 * @var string
	 */
	protected const LOG_COMPLETE = '';

	/**
	 * Log line of a failed pass, followed by the error.
	 *
	 * @var string
	 */
	protected const LOG_FAILED = '';

	/**
	 * AppConfig, taken from the ConfiguredJobRuntime.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Logger, taken from the ConfiguredJobRuntime.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ConfiguredJobRuntime $runtime The job runtime: scheduler clock and run log.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		ConfiguredJobRuntime $runtime,
	) {
		$this->logger = $runtime->logger;
		$this->appConfig = $runtime->appConfig;
		parent::__construct(time: $runtime->time);
		$this->setInterval(seconds: self::RUN_INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run the pass, and return what its completion line should report.
	 *
	 * @param bool $dryRun Whether to report without erasing anything.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Dry run is the admin's own switch, passed on as it is.
	 */
	abstract protected function runPass(bool $dryRun): array;

	/**
	 * Drive the pass.
	 *
	 * @param mixed $argument Job arguments (unused for recurring jobs).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	final protected function run($argument): void {
		if ($this->flag(key: static::CONFIG_KEY_ENABLED, default: 'true') === false) {
			$this->logger->info(message: static::LOG_DISABLED, context: ['file' => __FILE__, 'line' => __LINE__]);
			return;
		}

		$this->drive(dryRun: $this->flag(key: static::CONFIG_KEY_DRY_RUN, default: 'false'));
	}//end run()

	/**
	 * Run the pass and log how it went.
	 *
	 * @param bool $dryRun Whether to report without erasing anything.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Dry run is the admin's own switch, passed on as it is.
	 */
	private function drive(bool $dryRun): void {
		try {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'dryRun' => $dryRun];
			$this->logger->info(message: static::LOG_COMPLETE, context: $context + $this->runPass(dryRun: $dryRun));
		} catch (\Throwable $e) {
			$this->logger->error(
				message: static::LOG_FAILED . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}
	}//end drive()

	/**
	 * Read an app-config switch as a boolean.
	 *
	 * @param string $key     The app-config key.
	 * @param string $default The value of an unset switch.
	 *
	 * @return bool
	 */
	private function flag(string $key, string $default): bool {
		return filter_var(
			$this->appConfig->getValueString(app: self::APP_ID, key: $key, default: $default),
			FILTER_VALIDATE_BOOLEAN
		);
	}//end flag()
}//end class
