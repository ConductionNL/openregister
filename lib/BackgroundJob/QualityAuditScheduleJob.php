<?php

/**
 * Runs the scheduled data quality audits that are due, once an hour.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Quality\QualityAuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The schedule of the quality audits: an audit declaring `daily`, `weekly` or
 * `monthly` runs when its period has passed since its last run.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAuditScheduleJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time    The clock.
	 * @param QualityAuditService $audits  The audits.
	 * @param LoggerInterface     $logger  The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly QualityAuditService $audits,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: 3600);

	}//end __construct()

	/**
	 * Run the due audits; a failure is logged, never thrown into cron.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	protected function run($argument): void {
		try {
			$ran = $this->audits->runDue(now: new DateTimeImmutable('@'.$this->time->getTime()));
		} catch (Throwable $e) {
			$this->logger->warning(
				'[QualityAuditSchedule] Pass failed: '.$e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		if ($ran > 0) {
			$this->logger->info(
				'[QualityAuditSchedule] Ran '.$ran.' scheduled quality audits.',
				['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end run()
}//end class
