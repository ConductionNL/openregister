<?php

/**
 * The claim protocol: a firing claims the PLACES it touches, in one fixed
 * order, committing each claim on its own, and never waits.
 *
 * In a Petri net two transitions conflict exactly when their place sets
 * intersect, and Symfony hands that set over already (`getFroms()` and
 * `getTos()`). Claiming `froms ∪ tos` therefore claims precisely the conflict
 * relation the graph already has. Outputs are claimed as well as inputs: two
 * firings that consume from different places but PRODUCE onto the same one are
 * in conflict — openregister#2488 is exactly that shape.
 *
 * THE ORDER IS WHAT MAKES LIVELOCK IMPOSSIBLE. Places are sorted bytewise and
 * claimed low-to-high; on any refusal the caller's partial claims are released
 * and the candidate abandoned. Let P be the lowest place two contending
 * workers both want: whichever wins P has, by the ordering, already taken every
 * place it needs below P, and the loser cannot hold any of those. So the winner
 * of the lowest contended place always completes. Without the order, A wanting
 * `{a,c}` and B wanting `{c,a}` take one each, both refuse, both release, both
 * retry — forever, at full speed.
 *
 * EACH CLAIM COMMITS ON ITS OWN, BEFORE DISPATCH. An INSERT that collides with
 * another transaction's UNCOMMITTED insert blocks on the row lock until that
 * transaction ends; a claim taken inside the firing's transaction would make a
 * rival wait for the whole step — head-of-line blocking through the back door,
 * holding a connection while it waited. So this class refuses to run inside a
 * transaction.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use LogicException;
use OCA\OpenRegister\BackgroundJob\FlowRunWorker;
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapperInterface;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Acquires and releases place claims for one run.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */
class FlowPlaceClaims {

	/**
	 * Constructor.
	 *
	 * @param FlowClaimMapperInterface $claims The claim rows.
	 * @param IDBConnection $db To refuse running inside a transaction.
	 * @param LoggerInterface $logger Logger for repeated refusals.
	 */
	public function __construct(
		private readonly FlowClaimMapperInterface $claims,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * A per-pass owner token: instance, pid and a fresh uuid, so a reaped claim
	 * names the pass that abandoned it and two passes can never share a token.
	 *
	 * @return string The owner token.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public static function newOwner(): string {
		$host = gethostname();
		if (is_string($host) === false || $host === '') {
			$host = 'host';
		}

		$pid = getmypid();
		if (is_int($pid) === false) {
			$pid = 0;
		}

		return substr(sprintf('%s:%d:%s', $host, $pid, bin2hex(random_bytes(8))), 0, 128);
	}//end newOwner()

	/**
	 * The per-run stream cap: FlowConcurrency's numbers, referenced, not
	 * copied — the same `max(1, min(...))` as `boundedLimit()`.
	 *
	 * @param int|null $configured A flow-configured cap, or null for the default.
	 *
	 * @return int The effective cap.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-intra-run-fan-out-must-be-bounded
	 */
	public static function streamCap(?int $configured): int {
		if ($configured === null) {
			return FlowConcurrency::DEFAULT_LIMIT;
		}

		return max(1, min($configured, FlowConcurrency::MAX_LIMIT));
	}//end streamCap()

	/**
	 * The per-pass ceiling across all runs: BATCH × DEFAULT_LIMIT, so raising
	 * the per-run cap cannot turn one pass into a burst.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-intra-run-fan-out-must-be-bounded
	 */
	public static function passCap(): int {
		return (FlowRunWorker::BATCH * FlowConcurrency::DEFAULT_LIMIT);
	}//end passCap()

	/**
	 * Try to claim every place a firing touches.
	 *
	 * Returns the claimed place list on success, or null on refusal — with
	 * every partial claim already released. Never waits, never retries in
	 * place.
	 *
	 * @param FiringClaim $firing The run, stream, transition and `froms ∪ tos`.
	 * @param ClaimPass   $pass   The pass token and its per-run stream cap.
	 *
	 * @return array<int, string>|null The claimed places, sorted, or null when refused.
	 *
	 * @throws LogicException When called inside a transaction.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function claim(FiringClaim $firing, ClaimPass $pass): ?array {
		if ($this->db->inTransaction() === true) {
			throw new LogicException(
				'FlowPlaceClaims::claim() must not run inside a transaction: an uncommitted claim would block a rival for the whole step.'
			);
		}

		$places = self::normalised(places: $firing->places);
		if ($places === []) {
			return [];
		}

		// CAPS, before the first insert. A run may hold at most `runCap`
		// claims; a pass at most `passCap()` across every run it advances.
		if ($this->runFull(runUuid: $firing->runUuid, runCap: $pass->runCap) === true || $this->passFull(owner: $pass->owner) === true) {
			return null;
		}

		return $this->claimEach(template: self::template(firing: $firing, owner: $pass->owner), places: $places);
	}//end claim()

	/**
	 * The row every place of one firing shares: run, owner, stream,
	 * transition and one claim time.
	 *
	 * @param FiringClaim $firing The firing's claim.
	 * @param string      $owner  The pass token.
	 *
	 * @return FlowClaim The template row, without a place.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	private static function template(FiringClaim $firing, string $owner): FlowClaim {
		$template = new FlowClaim();
		$template->setRunUuid($firing->runUuid);
		$template->setOwner($owner);
		$template->setStreamId($firing->streamId);
		$template->setTransition($firing->transition);
		$template->setClaimedAt(new DateTime());

		return $template;
	}//end template()

	/**
	 * The places of a firing as claimed: strings, once each, in bytewise order.
	 *
	 * @param array<int, string|int> $places `froms ∪ tos`, in any order.
	 *
	 * @return array<int, string> The places, deduplicated and sorted.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	private static function normalised(array $places): array {
		$places = array_values(array_unique(array_map('strval', $places)));
		sort($places, SORT_STRING);

		return $places;
	}//end normalised()

	/**
	 * Whether a run already holds as many claims as its stream cap allows.
	 *
	 * @param string   $runUuid The run.
	 * @param int|null $runCap  The per-run stream cap; null for the default.
	 *
	 * @return bool True when the run may take no further claim.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-intra-run-fan-out-must-be-bounded
	 */
	private function runFull(string $runUuid, ?int $runCap): bool {
		return $this->claims->countHeldForRun(runUuid: $runUuid) >= self::streamCap(configured: $runCap);
	}//end runFull()

	/**
	 * Whether a pass already holds as many claims as the per-pass ceiling allows.
	 *
	 * @param string $owner The pass token.
	 *
	 * @return bool True when the pass may take no further claim.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-intra-run-fan-out-must-be-bounded
	 */
	private function passFull(string $owner): bool {
		return $this->claims->countHeldByOwner(owner: $owner) >= self::passCap();
	}//end passFull()

	/**
	 * Insert one claim per place, in order; on the first refusal release what
	 * this attempt already took and abandon the candidate. The firing stays
	 * enabled for a later attempt.
	 *
	 * @param FlowClaim          $template The run, owner, stream, transition and claim time every row shares.
	 * @param array<int, string> $places   The normalised places.
	 *
	 * @return array<int, string>|null The claimed places, or null when refused.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	private function claimEach(FlowClaim $template, array $places): ?array {
		$taken = [];
		foreach ($places as $place) {
			$claim = clone $template;
			$claim->setPlace($place);
			if ($this->claims->insertOrRefuse(claim: $claim) === false) {
				$this->claims->release(runUuid: (string)$template->getRunUuid(), places: $taken);
				$this->logRefusal(refused: $claim, places: $places);

				return null;
			}

			$taken[] = $place;
		}

		return $taken;
	}//end claimEach()

	/**
	 * Log a refused claim; the candidate is skipped, never waited on.
	 *
	 * @param FlowClaim          $refused The claim that was refused.
	 * @param array<int, string> $places  Every place the firing asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	private function logRefusal(FlowClaim $refused, array $places): void {
		$this->logger->debug(
			message: '[FlowPlaceClaims] Claim refused; candidate skipped',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'run' => $refused->getRunUuid(),
				'transition' => $refused->getTransition(),
				'place' => $refused->getPlace(),
				'places' => $places,
			]
		);
	}//end logRefusal()

	/**
	 * Release the places a firing held.
	 *
	 * @param string $runUuid The run.
	 * @param array<int, string> $places The places to release.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function release(string $runUuid, array $places): void {
		$this->claims->release(runUuid: $runUuid, places: $places);
	}//end release()
}//end class
