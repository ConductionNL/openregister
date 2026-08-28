<?php

/**
 * Parks a run that is waiting for a person to allow its delegation, and
 * releases it when they answer.
 *
 * WHY PARK RATHER THAN REFUSE
 *
 * {@see FlowDelegationCheck} refuses a run whose delegation has been withdrawn,
 * and that is right: somebody decided, and the answer was no. A run whose
 * delegation is merely UNANSWERED is a different situation with the same
 * surface. Refusing it throws away work that is about to become legal the moment
 * a person reads their notifications — and, worse, does so silently enough that
 * the requester learns nothing except that their flow did not run.
 *
 * WHY ITS OWN RUN STATE
 *
 * `awaiting_consent` is deliberately not `suspended`. The two are waiting on
 * different KINDS of thing, and every sweep in the system treats them
 * differently:
 *
 *  - `suspended` with a `resume_at` is waiting on a clock, and resumes on it.
 *  - `suspended` without one is waiting on machinery — a webhook, a child run —
 *    and the abandoned-signal reaper eventually FAILS it, on the reasoning that
 *    a signal which has not arrived in hours is not going to.
 *
 * That reasoning is wrong for a person. Somebody who has not read their
 * notifications in two hours has not declined; they are at lunch. Parking such a
 * run in `suspended` would hand it to the reaper and fail it while its prompt sat
 * unread — reporting "nobody answered" about a question nobody had yet seen.
 *
 * WHAT RELEASES IT
 *
 * The GRANT RECORD, re-resolved. Not a timer and not a signal: the answer is a
 * state change on a row, so this sweep asks the resolver again and acts on what
 * it says. That also makes one answer release every run it unblocks, which is
 * the other half of the dedup — one request, one decision, N runs freed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Holds runs whose delegation is unanswered, and frees or fails them.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class FlowConsentParking {

	/**
	 * The context key recording WHO the run is waiting on.
	 *
	 * Written onto the run so "why is this stuck" is answerable from the run
	 * itself. A parked run whose only record of the reason lived in a log would
	 * make an operator correlate two tables to learn something the row could
	 * simply have said.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'awaitingConsent';

	/**
	 * How long a parked run waits before it is failed, in hours.
	 *
	 * Generous on purpose. The failure mode being avoided is failing work while
	 * its prompt is unread, and a person who has not answered by the next working
	 * day has effectively declined — but a person who has not answered in an hour
	 * is at lunch. Bounded all the same: no run may wait forever, or "waiting for
	 * consent" becomes indistinguishable from "lost".
	 *
	 * @var integer
	 */
	public const DEFAULT_WAIT_HOURS = 72;

	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper     $runs       Reads and writes runs.
	 * @param DelegationService $delegation Re-resolves the grant.
	 * @param LoggerInterface   $logger     Records releases and failures.
	 */
	public function __construct(
		private readonly FlowRunMapper $runs,
		private readonly DelegationService $delegation,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Park a run until `$declaredBy` is allowed to act as `$runAs`.
	 *
	 * The run is WRITTEN, not discarded — that is the point. Its context records
	 * both parties and the reason, so the row answers "waiting for X to allow Y
	 * to act as them" without anybody joining it to the grant store.
	 *
	 * @param FlowRun $run        The run to park.
	 * @param string  $declaredBy The principal whose grant is missing.
	 * @param string  $runAs      The identity it would act as.
	 * @param string  $reason     The verdict reason that caused the park.
	 *
	 * @return FlowRun The parked run.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function park(FlowRun $run, string $declaredBy, string $runAs, string $reason): FlowRun {
		$context = ($run->getContext() ?? []);
		$context[self::CONTEXT_KEY] = [
			'principal' => $declaredBy,
			'actingAs' => $runAs,
			'reason' => $reason,
			'since' => (new DateTime())->format('c'),
		];

		$run->setContext($context);
		$run->setStatus(FlowRun::STATUS_AWAITING_CONSENT);
		// NO resume_at. A consent does not arrive on a clock, and giving it one
		// would hand the run to the timed-resume sweep, which would start it
		// before anybody had answered.
		$run->setResumeAt(null);
		$run->setError(
			sprintf('Waiting for "%s" to allow "%s" to act as them.', $runAs, $declaredBy)
		);

		return $this->runs->update($run);
	}//end park()

	/**
	 * Release or fail every parked run whose answer has arrived.
	 *
	 * @param DateTime $now       The moment to judge liveness and waiting at.
	 * @param integer  $waitHours How long a run may wait before it is failed.
	 * @param integer  $limit     Maximum runs to consider in one pass.
	 *
	 * @return array{released: int, failed: int} What the pass did.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function sweep(DateTime $now, int $waitHours = self::DEFAULT_WAIT_HOURS, int $limit = 25): array {
		$released = 0;
		$failed = 0;

		foreach ($this->runs->findAwaitingConsent(limit: $limit) as $run) {
			$outcome = $this->settle(run: $run, now: $now, waitHours: $waitHours);
			if ($outcome === 'released') {
				$released++;
			}

			if ($outcome === 'failed') {
				$failed++;
			}
		}

		return ['released' => $released, 'failed' => $failed];
	}//end sweep()

	/**
	 * Decide what happens to one parked run.
	 *
	 * @param FlowRun  $run       The parked run.
	 * @param DateTime $now       The moment.
	 * @param integer  $waitHours How long it may wait.
	 *
	 * @return string `released`, `failed` or `waiting`.
	 */
	private function settle(FlowRun $run, DateTime $now, int $waitHours): string {
		$parked = (($run->getContext() ?? [])[self::CONTEXT_KEY] ?? null);
		if (is_array($parked) === false) {
			// A run in this state with no record of what it waits for cannot be
			// resolved by anyone, and leaving it would make the state itself
			// untrustworthy. Fail it and say exactly that.
			return $this->fail(
				run: $run,
				message: 'This run was parked awaiting consent but records no delegation, so nothing can release it.'
			);
		}

		$principal = trim((string)($parked['principal'] ?? ''));
		$actingAs = trim((string)($parked['actingAs'] ?? ''));

		if ($principal === '' || $actingAs === '') {
			return $this->fail(
				run: $run,
				message: 'This run was parked awaiting consent but names no parties, so nothing can release it.'
			);
		}

		try {
			$verdict = $this->delegation->verdictFor(principal: $principal, actingAs: $actingAs, now: $now);
		} catch (Throwable $e) {
			// Leave it PARKED. An unreadable store is not an answer, and failing
			// on it would destroy work over an infrastructure blip — the opposite
			// trade-off from the fire-time check, where refusing costs one run
			// and permitting costs an unauthorized execution. Here nothing runs
			// either way, so waiting is free and failing is not.
			$this->logger->warning(
				message: '[FlowConsentParking] Could not resolve a parked run\'s delegation; leaving it parked: '
					. $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $run->getUuid()]
			);

			return 'waiting';
		}

		if ($verdict->permitted === true) {
			return $this->release(run: $run, principal: $principal, actingAs: $actingAs);
		}

		if ($verdict->reason === DelegationVerdict::REASON_PENDING) {
			return $this->expireIfOverdue(run: $run, now: $now, waitHours: $waitHours, parked: $parked);
		}

		// Denied, revoked, expired, out-of-scope, or the request vanished. All of
		// these are answers, and none of them is yes.
		return $this->fail(
			run: $run,
			message: sprintf(
				'Refused: "%s" may not act as "%s" (%s). %s',
				$principal,
				$actingAs,
				$verdict->reason,
				$verdict->detail
			)
		);
	}//end settle()

	/**
	 * Fail a run that has waited past the limit.
	 *
	 * @param FlowRun  $run       The parked run.
	 * @param DateTime $now       The moment.
	 * @param integer  $waitHours How long it may wait.
	 * @param array    $parked    The parked-context record.
	 *
	 * @return string `failed` or `waiting`.
	 */
	private function expireIfOverdue(FlowRun $run, DateTime $now, int $waitHours, array $parked): string {
		$since = ($parked['since'] ?? null);
		if (is_string($since) === false || $since === '') {
			return 'waiting';
		}

		$parkedAt = date_create_immutable($since);
		if ($parkedAt === false) {
			return 'waiting';
		}

		$waited = (($now->getTimestamp() - $parkedAt->getTimestamp()) / 3600);
		if ($waited < $waitHours) {
			return 'waiting';
		}

		// 🔴 FAIL CLOSED when nobody answered. The alternative — running it
		// anyway after a timeout — turns an unanswered consent prompt into an
		// approval, which is the exact substitution the whole subsystem exists to
		// prevent, and it would happen at whatever hour the timer elapsed.
		return $this->fail(
			run: $run,
			message: sprintf(
				'Nobody answered within %d hours, so this run was not performed. '
				. 'An unanswered request is not consent.',
				$waitHours
			)
		);
	}//end expireIfOverdue()

	/**
	 * Put a released run back in the queue.
	 *
	 * QUEUED, not running. It re-enters through the ordinary worker path so that
	 * fairness, batching and the fire-time delegation check all apply to it
	 * exactly as they would to any other run — including the check, which will
	 * resolve the grant once more. That is not redundant: minutes can pass
	 * between this sweep and the worker picking it up.
	 *
	 * @param FlowRun $run       The parked run.
	 * @param string  $principal The principal.
	 * @param string  $actingAs  The identity.
	 *
	 * @return string `released`.
	 */
	private function release(FlowRun $run, string $principal, string $actingAs): string {
		$context = ($run->getContext() ?? []);
		unset($context[self::CONTEXT_KEY]);

		$run->setContext($context);
		$run->setStatus(FlowRun::STATUS_QUEUED);
		$run->setError(null);
		$this->runs->update($run);

		$this->logger->info(
			message: '[FlowConsentParking] Released a parked run — the delegation was allowed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'run' => $run->getUuid(),
				'principal' => $principal,
				'actingAs' => $actingAs,
			]
		);

		return 'released';
	}//end release()

	/**
	 * Fail a parked run with a stated reason.
	 *
	 * @param FlowRun $run     The parked run.
	 * @param string  $message Why it will not run.
	 *
	 * @return string `failed`.
	 */
	private function fail(FlowRun $run, string $message): string {
		$context = ($run->getContext() ?? []);
		unset($context[self::CONTEXT_KEY]);

		$run->setContext($context);
		$run->setStatus(FlowRun::STATUS_FAILED);
		$run->setError($message);
		$this->runs->update($run);

		$this->logger->warning(
			message: '[FlowConsentParking] Failed a parked run: ' . $message,
			context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $run->getUuid()]
		);

		return 'failed';
	}//end fail()
}//end class
