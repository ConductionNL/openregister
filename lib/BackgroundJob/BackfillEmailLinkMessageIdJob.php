<?php

/**
 * One-shot job: record the RFC `Message-ID` on email links made before it was read.
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

use OCA\OpenRegister\Notifications\Service\EmailLinkMessageIdBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queued once by the migration that adds `direction`; runs the backfill once.
 *
 * The kill switch is the app config key `backfill_email_link_message_id`:
 * `no` skips the run. A failure is logged and the job ends; links left
 * without an id thread as `unthreaded`, never onto a guess.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class BackfillEmailLinkMessageIdJob extends QueuedJob {

	/**
	 * The kill switch.
	 */
	public const CONFIG_FLAG = 'backfill_email_link_message_id';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory               $time      The clock.
	 * @param EmailLinkMessageIdBackfill $backfill  The backfill.
	 * @param IAppConfig                 $appConfig The app config, for the kill switch.
	 * @param LoggerInterface            $logger    The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EmailLinkMessageIdBackfill $backfill,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the backfill unless switched off.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	protected function run($argument): void {
		if ($this->appConfig->getValueString('openregister', self::CONFIG_FLAG, 'yes') === 'no') {
			$this->logger->info('BackfillEmailLinkMessageIdJob skipped (flag '.self::CONFIG_FLAG.' = no)');
			return;
		}

		try {
			$tally = $this->backfill->run();
		} catch (Throwable $e) {
			$this->logger->warning('BackfillEmailLinkMessageIdJob failed: '.$e->getMessage(), ['exception' => $e]);
			return;
		}

		$this->logger->info('BackfillEmailLinkMessageIdJob finished. Read='.$tally['read'].', recorded='.$tally['recorded']);
	}//end run()
}//end class
