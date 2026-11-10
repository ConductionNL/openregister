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
	 * All recognised strategy values.
	 *
	 * @return list<string> The known strategies
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public static function strategies(): array {
		return [self::SOURCE_WINS, self::LOCAL_WINS, self::NEWEST_WINS, self::MANUAL];
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
