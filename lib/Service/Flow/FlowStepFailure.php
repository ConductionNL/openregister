<?php

/**
 * What a failed step does next: retry after a delay, branch its items, or follow its terminal policy.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use DateTimeImmutable;
use Throwable;

/**
 * The decision both walks take on a failed step, made in one place.
 *
 * A step that declares `retry` and has tries left is RETRYING: the run
 * suspends until the delay has passed (design D-1), the failed attempt is
 * counted on the resume state, and the walk resumes on the same transition
 * with the same items. Only the last try's failure reaches the `onError`
 * policy: under `branch` the step is BRANCHED and hands on the items
 * {@see FlowErrorBranch} builds; otherwise it is FAILED and the walk applies
 * its policy as it always did.
 *
 * The log entry is the step's failed entry; a retrying step adds its
 * `attempt` and `outcome`, a branched one its `outcome` and the count of
 * items it branched. A step without `retry` or `branch` keeps today's entry
 * byte for byte.
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */
final class FlowStepFailure {
	public const RETRYING = 'retrying';
	public const BRANCHED = 'branched';
	public const FAILED   = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string               $outcome    RETRYING, BRANCHED or FAILED.
	 * @param array<string, mixed> $entry      The log entry of this failure.
	 * @param FlowSuspension|null  $suspension The wait before the next try, when retrying.
	 * @param list<mixed>          $items      The items the step hands on, when branched.
	 */
	private function __construct(
		public readonly string $outcome,
		public readonly array $entry,
		public readonly ?FlowSuspension $suspension=null,
		public readonly array $items=[],
	) {
	}//end __construct()

	/**
	 * Decide what one failed hop does.
	 *
	 * @param FlowHop                $hop   The failed hop.
	 * @param Throwable              $error The failure.
	 * @param FlowResumeState|null   $state The run's resume state; without one a retry cannot be remembered, so none is made.
	 * @param DateTimeImmutable|null $now   The moment of the failure (now when null).
	 *
	 * @return self The decision.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
	 */
	public static function of(FlowHop $hop, Throwable $error, ?FlowResumeState $state, ?DateTimeImmutable $now=null): self {
		$now     = ($now ?? new DateTimeImmutable());
		$step    = $hop->name();
		$before  = 0;
		if ($state !== null) {
			$before = FlowStepAttempts::failed(state: $state, step: $step);
		}

		$attempt = ($before + 1);
		$entry   = $hop->failedEntry(error: $error);
		$retry   = FlowStepRetry::for(step: $hop->step);
		if ($retry !== null) {
			$entry['attempt'] = $attempt;
		}

		if ($retry !== null && $state !== null && $retry->allowsAnother(made: $before) === true) {
			FlowStepAttempts::record(state: $state, step: $step, failed: $attempt);
			$resumeAt = DateTime::createFromImmutable($now->modify('+'.$retry->delayBefore(attempt: $attempt).' seconds'));

			return new self(
				outcome: self::RETRYING,
				entry: $entry + ['outcome' => self::RETRYING, 'retryAt' => $resumeAt->format(DATE_ATOM)],
				suspension: new FlowSuspension(
					resumeAt: $resumeAt,
					reason: sprintf('retrying after failed attempt %d of %d: %s', $attempt, ($retry->attempts + 1), $error->getMessage())
				)
			);
		}

		if ($state !== null) {
			FlowStepAttempts::clear(state: $state, step: $step);
		}

		if (FlowErrorPolicy::branches(step: $hop->step) === true) {
			$branch = (new FlowErrorBranch(step: $step, attempt: $attempt, at: $now))->itemsAfter(itemsIn: $hop->itemsIn, error: $error);

			return new self(
				outcome: self::BRANCHED,
				entry: $entry + ['outcome' => self::BRANCHED, 'itemsBranched' => $branch['failed']],
				items: $branch['items']
			);
		}

		return new self(outcome: self::FAILED, entry: $entry);
	}//end of()
}//end class
