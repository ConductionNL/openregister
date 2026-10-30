<?php

/**
 * Once a day, removes the drafts that outlived their schema's draft lifetime (decisions 216 and 228).
 *
 * A lifetime no job reads is a label, not a rule. This job is what makes a
 * draft lifetime (30 days, or what `draftLifetimeDays` on the schema says)
 * mean that an abandoned draft stops existing. It is declared in
 * `appinfo/info.xml`; without that line the setting would be stored,
 * exported, imported and never acted on.
 *
 * `occ openregister:drafts:expired` lists what the next run would remove.
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
use OCA\OpenRegister\Service\Object\DraftRemovalRecord;
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
	 * The name the audit-trail entry of a removed draft gives as its actor.
	 *
	 * @var string
	 */
	public const JOB_NAME = 'DraftExpiryJob';

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
	 * @SuppressWarnings(PHPMD.StaticAccess)          DraftRemovalRecord::asJob() is the ambient frame that names the job.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
	 */
	protected function run(mixed $argument): void {
		try {
			// Under the job's own name, so the audit-trail entry of each removed
			// draft says which job removed it (decision 226).
			$counts = DraftRemovalRecord::asJob(
				job: self::JOB_NAME,
				operation: fn (): array => $this->expiry->sweep(now: DateTimeImmutable::createFromMutable($this->time->getDateTime()))
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				message: '[DraftExpiryJob] The sweep failed',
				context: ['exception' => $exception->getMessage()]
			);
			throw $exception;
		}

		// One line per schema that held an expired draft, so an administrator
		// reads from the log which schema lost how many (decision 228), and
		// one line with the totals, a zero included.
		//
		// A sweep that removed or refused a draft is logged at WARNING: the
		// default loglevel (2 = warning) would hide an INFO line, and the first
		// run after the upgrade removes drafts that were kept until then, so an
		// administrator would have no record of how many. A sweep that changed
		// nothing keeps its counted zero at info.
		foreach ($counts['perSchema'] as $row) {
			$this->report(message: '[DraftExpiryJob] drafts past the lifetime of a schema', counts: $row);
		}

		unset($counts['perSchema']);
		$this->report(message: '[DraftExpiryJob] swept drafts past their schema\'s lifetime', counts: $counts);
	}//end run()

	/**
	 * Log one line of counts: at warning when drafts were removed or refused, at info for a counted zero.
	 *
	 * @param string               $message The line.
	 * @param array<string, mixed> $counts  The counts of one schema or of the whole sweep.
	 *
	 * @return void
	 */
	private function report(string $message, array $counts): void {
		if (((int)($counts['removed'] ?? 0) + (int)($counts['refused'] ?? 0)) > 0) {
			$this->logger->warning(message: $message, context: $counts);

			return;
		}

		$this->logger->info(message: $message, context: $counts);
	}//end report()
}//end class
