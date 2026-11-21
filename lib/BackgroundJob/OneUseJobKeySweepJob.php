<?php

/**
 * Every hour, delete the one-use job keys a dead export or load left behind.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Exchange\OneUseJobKey;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Sweeps job keys older than 24 hours (design D-3).
 *
 * A job deletes its own key when it finishes or throws; this catches the job
 * that never ran to that point.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */
class OneUseJobKeySweepJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time   The clock.
	 * @param OneUseJobKey    $keys   Deletes the stale job keys.
	 * @param LoggerInterface $logger Records how many were swept.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly OneUseJobKey $keys,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Run one sweep.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob's signature.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	protected function run(mixed $argument): void {
		$swept = $this->keys->sweep(now: $this->time->getDateTime());
		if ($swept > 0) {
			$this->logger->info(message: '[OneUseJobKeySweepJob] stale job keys deleted', context: ['count' => $swept]);
		}
	}//end run()
}//end class
