<?php

/**
 * OpenRegister Sync Conflict Resolver
 *
 * Pure conflict-resolution logic for the harvest pipeline. Given the
 * configured strategy plus the source/local modification timestamps and a
 * flag indicating whether the local copy changed since the last sync, it
 * decides which side wins. No I/O, so it is fully unit-testable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use Throwable;

/**
 * Resolves source-vs-local conflicts using a configurable strategy.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SyncConflictResolver {

	/**
	 * Source data overwrites local changes.
	 */
	public const SOURCE_WINS = 'source-wins';

	/**
	 * Local changes are kept; the source update is skipped.
	 */
	public const LOCAL_WINS = 'local-wins';

	/**
	 * The most recently modified side wins.
	 */
	public const NEWEST_WINS = 'newest-wins';

	/**
	 * Conflict is flagged for manual administrator resolution.
	 */
	public const MANUAL = 'manual';

	/**
	 * The source item is refused: the local object stays untouched and is never linked.
	 */
	public const REJECT = 'reject';

	/**
	 * Collision reason: no sync record, but a local object already holds the external id.
	 */
	public const REASON_PRE_EXISTING_CLAIM = 'pre-existing-claim';

	/**
	 * Collision reason: the linked object was edited after the harvest last wrote it.
	 */
	public const REASON_LOCAL_EDITED = 'local-edited';

	/**
	 * Collision reason: the linked object was deleted locally.
	 */
	public const REASON_LOCAL_DELETED = 'local-deleted';

	/**
	 * Resolution outcome: apply the source data.
	 */
	public const APPLY_SOURCE = 'apply_source';

	/**
	 * Resolution outcome: keep the local data (skip the update).
	 */
	public const KEEP_LOCAL = 'keep_local';

	/**
	 * Resolution outcome: defer to a human (flag as conflict).
	 */
	public const DEFER = 'defer';

	/**
	 * Resolution outcome: refuse the source item (status rejected, payload cleared).
	 */
	public const REJECT_SOURCE = 'reject_source';

	/**
	 * Build the resolver.
	 *
	 * @param DecisionTableEvaluator $evaluator Evaluates a source's conflictRules per collision.
	 */
	public function __construct(
		private readonly DecisionTableEvaluator $evaluator = new DecisionTableEvaluator(),
	) {
	}//end __construct()

	/**
	 * All recognised strategy values.
	 *
	 * @return list<string> The known strategies
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public static function strategies(): array {
		return [self::SOURCE_WINS, self::LOCAL_WINS, self::NEWEST_WINS, self::MANUAL, self::REJECT];
	}//end strategies()

	/**
	 * Whether a value is a recognised conflict strategy.
	 *
	 * @param string $strategy The strategy to check
	 *
	 * @return bool True when the strategy is recognised
	 */
	public function isValidStrategy(string $strategy): bool {
		return in_array($strategy, self::strategies(), true);
	}//end isValidStrategy()

	/**
	 * Decide the outcome for a record.
	 *
	 * A conflict only exists when BOTH sides changed since the last sync.
	 * When the local copy did not change, the source update is applied
	 * regardless of strategy (there is nothing to conflict with).
	 *
	 * @param string       $strategy Configured strategy (one of the *_WINS / MANUAL constants)
	 * @param SyncConflict $conflict Whether the local copy changed, and when each side was modified
	 *
	 * @return string One of APPLY_SOURCE, KEEP_LOCAL, DEFER
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function resolve(string $strategy, SyncConflict $conflict): string {
		// Unknown strategy falls back to the safest default: defer to a human.
		if ($this->isValidStrategy(strategy: $strategy) === false) {
			return self::DEFER;
		}

		// No competing local edit: there is no conflict, just apply the source.
		if ($conflict->localChanged === false) {
			return self::APPLY_SOURCE;
		}

		return $this->conflictOutcome(strategy: $strategy, conflict: $conflict);
	}//end resolve()

	/**
	 * The strategy one collision follows.
	 *
	 * A locally deleted object is always left to a person (manual), whatever
	 * the source says: recreating what someone removed is not the harvest's
	 * call. Otherwise the source's conflictRules decision table picks the
	 * strategy from the reason, the external id and the mapped payload's
	 * top-level fields; no table, no match, an evaluation error or an output
	 * that is not a known strategy fall back to the source's conflictStrategy.
	 *
	 * @param Source               $source     The source.
	 * @param string               $reason     The collision reason (one of the REASON_* constants).
	 * @param string               $externalId The item's external id.
	 * @param array<string, mixed> $mapped     The mapped payload.
	 *
	 * @return string One of the strategy constants.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md#requirement-a-collision-follows-the-sources-strategy-or-a-decision-table-req-haf-007
	 */
	public function strategyFor(Source $source, string $reason, string $externalId, array $mapped): string {
		if ($reason === self::REASON_LOCAL_DELETED) {
			return self::MANUAL;
		}

		$fallback = (string)($source->getConflictStrategy() ?? self::SOURCE_WINS);
		$rules    = $source->getConflictRules();
		if (is_array($rules) === false || $rules === []) {
			return $fallback;
		}

		$picked = $this->ruleStrategy(rules: $rules, inputs: ['conflictReason' => $reason, 'externalId' => $externalId] + $mapped);
		if ($picked === null || $this->isValidStrategy(strategy: $picked) === false) {
			return $fallback;
		}

		return $picked;
	}//end strategyFor()

	/**
	 * The `strategy` output of a conflictRules table for these inputs, or null.
	 *
	 * Only the inputs the table declares are handed in; a declared input the
	 * item does not carry is null. A table that matches no row or cannot be
	 * evaluated yields null, so the caller falls back to conflictStrategy.
	 *
	 * @param array<string, mixed> $rules  The decision table.
	 * @param array<string, mixed> $inputs Every available input.
	 *
	 * @return string|null
	 */
	private function ruleStrategy(array $rules, array $inputs): ?string {
		$declared = [];
		foreach (($rules['inputs'] ?? []) as $input) {
			if (is_array($input) === true && is_string($input['name'] ?? null) === true && $input['name'] !== '') {
				$name = $input['name'];
				$declared[$name] = ($inputs[$name] ?? null);
			}
		}

		try {
			$result = $this->evaluator->evaluate(decisionTable: $rules, inputs: $declared);
		} catch (Throwable $e) {
			return null;
		}

		$strategy = ($result['outputs']['strategy'] ?? null);
		if (is_string($strategy) === true) {
			// A FEEL string literal ("reject") names the same strategy as the bare word.
			return trim($strategy, '"');
		}

		return null;
	}//end ruleStrategy()

	/**
	 * The outcome of a real conflict under a known strategy.
	 *
	 * @param string       $strategy The configured strategy, known to be valid.
	 * @param SyncConflict $conflict When each side was modified.
	 *
	 * @return string One of APPLY_SOURCE, KEEP_LOCAL, DEFER
	 */
	private function conflictOutcome(string $strategy, SyncConflict $conflict): string {
		switch ($strategy) {
			case self::SOURCE_WINS:
				return self::APPLY_SOURCE;
			case self::LOCAL_WINS:
				return self::KEEP_LOCAL;
			case self::NEWEST_WINS:
				return $this->resolveNewest(conflict: $conflict);
			case self::REJECT:
				return self::REJECT_SOURCE;
			case self::MANUAL:
			default:
				return self::DEFER;
		}//end switch
	}//end conflictOutcome()

	/**
	 * Compare timestamps for the newest-wins strategy.
	 *
	 * @param SyncConflict $conflict The source-side and local-side timestamps
	 *
	 * @return string APPLY_SOURCE, KEEP_LOCAL, or DEFER when undecidable
	 */
	private function resolveNewest(SyncConflict $conflict): string {
		// Cannot compare without both timestamps; defer rather than guess.
		if ($conflict->sourceModified === null || $conflict->localModified === null) {
			return self::DEFER;
		}

		$sourceTs = $conflict->sourceModified->getTimestamp();
		$localTs = $conflict->localModified->getTimestamp();

		if ($sourceTs > $localTs) {
			return self::APPLY_SOURCE;
		}

		if ($localTs > $sourceTs) {
			return self::KEEP_LOCAL;
		}

		// Exact tie: prefer the source so sync remains convergent.
		return self::APPLY_SOURCE;
	}//end resolveNewest()
}//end class
