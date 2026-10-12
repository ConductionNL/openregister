<?php

/**
 * Once a day, removes the drafts that outlived their schema's draft lifetime (decision 216).
 *
 * A lifetime no job reads is a label, not a rule. This job is what makes
 * `draftLifetimeDays` on a schema mean that an abandoned draft stops
 * existing. It is declared in `appinfo/info.xml`; without that line the
 * setting would be stored, exported, imported and never acted on.
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
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sweeps expired drafts and says how many, a zero included.
 *
 * A zero in the log is a counted zero: it tells a sweep that found nothing
 * apart from a sweep that never ran. A failure is logged and rethrown, so the
 * job list does not record a run that did not happen.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */
class DraftExpiryJob extends TimedJob {

	/**
	 * Once per 24 hours.
	 *
	 * @var integer
	 */
	public const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time   The clock.
	 * @param DraftExpiry     $expiry Removes the expired drafts.
	 * @param LoggerInterface $logger Where the counts go.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DraftExpiry $expiry,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Sweep and log the counts.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @throws Throwable When the sweep fails, after logging it.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob's signature.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
	 */
	protected function run(mixed $argument): void {
		try {
			$counts = $this->expiry->sweep(now: DateTimeImmutable::createFromMutable($this->time->getDateTime()));
		} catch (Throwable $exception) {
			$this->logger->error(
				message: '[DraftExpiryJob] The sweep failed',
				context: ['exception' => $exception->getMessage()]
			);
			throw $exception;
		}

		$this->logger->info(
			message: '[DraftExpiryJob] swept drafts past their schema\'s lifetime',
			context: $counts
		);
	}//end run()
}//end class
