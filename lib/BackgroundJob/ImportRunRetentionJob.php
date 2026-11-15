<?php

/**
 * Prunes the per-row detail of old import runs once a day, keeping each run's header.
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
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Import\ImportRunRetention;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The daily retention pass over import run detail (REQ-RCN-007).
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */
class ImportRunRetentionJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time      The clock.
	 * @param ImportRunRetention $retention The prune.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ImportRunRetention $retention,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: 86400);

	}//end __construct()

	/**
	 * Run the pass; a failure is logged, never thrown into cron.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md#requirement-import-detail-is-pruned-while-the-run-survives-req-rcn-007
	 */
	protected function run($argument): void {
		try {
			$pruned = $this->retention->prune(now: new DateTimeImmutable('@'.$this->time->getTime()));
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ImportRunRetention] Pass failed: '.$e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		if ($pruned > 0) {
			$this->logger->info(
				'[ImportRunRetention] Pruned the row detail of '.$pruned.' import runs.',
				['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end run()
}//end class
