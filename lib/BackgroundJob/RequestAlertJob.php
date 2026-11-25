<?php

/**
 * Judges the request error rate and p95 response time thresholds every five minutes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Observability\RequestAlertEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs RequestAlertEvaluator, so each run judges the five minutes since the last one.
 *
 * The request rows it reads are written by web requests (the APCu flush), so the job
 * only reads the database and works from the CLI cron as well.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */
class RequestAlertJob extends TimedJob {

	/**
	 * The window, in seconds.
	 *
	 * @var integer
	 */
	public const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory          $time      The clock.
	 * @param RequestAlertEvaluator $evaluator Judges the window.
	 * @param LoggerInterface       $logger    Logs a failed run.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RequestAlertEvaluator $evaluator,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Judge the window; a failure is logged and the next run tries again.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
	 */
	protected function run($argument): void {
		try {
			$this->evaluator->evaluate();
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] request alert thresholds could not be judged: '.$e->getMessage());
		}
	}//end run()
}//end class
