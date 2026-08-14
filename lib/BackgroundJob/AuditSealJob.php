<?php

/**
 * Seals audit-trail rows that were left unsealed on the write path.
 *
 * `sealRow()` and `sealRows()` are fail-soft: when the seal lock cannot be
 * acquired they log, leave the row unsealed, and say "a later seal pass will
 * chain it". Until this job existed there was no later pass — nothing swept
 * unsealed rows, so every fail-soft skip was permanent. Measured on the instance
 * this was written against: 49,123 of 308,937 audit rows (15.9%) had no hash.
 *
 * A row with no hash is a row the chain cannot vouch for. The point of hash
 * chaining is that altering or deleting an entry breaks the links either side of
 * it, so an auditor can say "this history has not been rewritten" from evidence
 * rather than assertion. Gaps weaken exactly that claim, which is why this is a
 * correctness job and not only a tidy-up.
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
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\AuditHashService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Periodically seals any audit rows still missing a hash.
 */
class AuditSealJob extends TimedJob {

	/**
	 * How often the sweep runs, in seconds.
	 *
	 * Five minutes: often enough that a row left unsealed by momentary lock
	 * contention is chained well within an audit window, infrequent enough that
	 * the sweep does not itself become a source of contention with the write
	 * path it is compensating for.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 300;

	/**
	 * Passes attempted per tick.
	 *
	 * Each pass seals up to AuditHashService::SWEEP_BATCH_SIZE rows. Several
	 * passes per tick let a large backfill — tens of thousands of rows on an
	 * instance that has been running without a sweeper — drain over hours
	 * rather than days, while each individual pass stays bounded so the seal
	 * lock is never held for long.
	 *
	 * @var integer
	 */
	private const PASSES_PER_RUN = 10;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for TimedJob.
	 * @param AuditHashService $hashes Seals the rows.
	 * @param LoggerInterface $logger Reports progress and a growing backlog.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly AuditHashService $hashes,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Seal a bounded number of outstanding rows.
	 *
	 * Stops early when a pass seals nothing. That happens either because the
	 * backlog is empty — the normal steady state — or because the seal lock is
	 * held by a concurrent writer, in which case continuing would just spin.
	 * Either way the next tick picks up where this one stopped, since the sweep
	 * always selects the OLDEST unsealed rows and is therefore resumable by
	 * construction.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	protected function run($argument): void {
		try {
			$sealed = 0;
			for ($pass = 0; $pass < self::PASSES_PER_RUN; $pass++) {
				$thisPass = $this->hashes->sealUnsealed();
				if ($thisPass === 0) {
					break;
				}

				$sealed += $thisPass;
			}

			$remaining = $this->hashes->countUnsealed();

			// Steady state: nothing sealed because nothing needed sealing. The
			// only case worth no log line at all.
			if ($sealed === 0 && $remaining === 0) {
				return;
			}

			if ($sealed > 0) {
				$this->logger->info(
					message: sprintf(
						'[AuditSealJob] Sealed %d audit row(s); %d still awaiting a seal.',
						$sealed,
						$remaining
					),
					context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
				);
			}

			// A backlog that never drains means sealing is failing on the write
			// path AND here, which is the condition that went unnoticed for as
			// long as no sweeper existed. Say so at a level somebody sees.
			//
			// 🔴 This warning was DEAD until psalm said so. An earlier
			// `if ($sealed === 0) { return; }` sat above it, so the one branch
			// that could reach it had already left the method — the alarm for
			// "the chain has gaps that are not closing" could never fire, which
			// is the same silence it exists to break. Reading the backlog before
			// the early exit is what makes it reachable.
			if ($remaining > 0 && $sealed === 0) {
				$this->logger->warning(
					message: sprintf(
						'[AuditSealJob] %d audit row(s) remain unsealed and this pass sealed none. '
						. 'The hash chain has gaps that are not closing.',
						$remaining
					),
					context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
				);
			}
		} catch (Throwable $e) {
			// Never let a sweep failure break cron for every other job.
			$this->logger->error(
				message: '[AuditSealJob] Seal sweep failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
			);
		}//end try

	}//end run()
}//end class
