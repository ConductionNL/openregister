<?php

/**
 * OpenRegister Handoff Queue Drain Job
 *
 * Fallback TimedJob sweep for `whenUnavailable: queue` handoffs (ADR-051):
 * every 5 minutes (the same cadence class as
 * {@see \OCA\OpenRegister\BackgroundJob\WebhookRetryJob}) it drains parked queue
 * entries whose target kind now resolves to an installed provider — catching
 * provider arrivals the schema-save / app-enable listeners missed (e.g.
 * register import paths that bypass the schema-save event).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Cron
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
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodic fallback drain of the handoff queue.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC background-job framework (appinfo/info.xml).
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Scenario: No provider installed, queue mode)
 */
class HandoffQueueDrainJob extends TimedJob {

	/**
	 * Default interval: 5 minutes (WebhookRetryJob cadence class).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param HandoffService $handoffService The handoff engine (drain surface).
	 * @param LoggerInterface $logger Structured logging.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly HandoffService $handoffService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);

	}//end __construct()

	/**
	 * Sweep parked entries whose kind now resolves.
	 *
	 * @param mixed $argument Job arguments (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	protected function run($argument): void {
		try {
			$summary = $this->handoffService->drainParked();
			if ($summary['drained'] > 0 || $summary['failed'] > 0) {
				$this->logger->info(
					message: '[HandoffQueueDrainJob] Fallback drain sweep completed',
					context: ['file' => __FILE__, 'line' => __LINE__, 'summary' => $summary]
				);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[HandoffQueueDrainJob] Fallback drain sweep failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

	}//end run()
}//end class
