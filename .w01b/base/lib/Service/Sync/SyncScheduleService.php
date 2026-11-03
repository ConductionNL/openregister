<?php

/**
 * OpenRegister Sync Schedule Service
 *
 * Pure scheduling logic for the harvest pipeline: decides whether a given
 * source is due for synchronisation and selects the due subset from a
 * collection. Mirrors SyncConfigurationsJob::isDueForSync() but operates
 * on Source entities and is injectable/testable without a live clock or DB.
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

use DateTimeInterface;
use OCA\OpenRegister\Db\Source;

/**
 * Determines which sources are due for sync at a given moment.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SyncScheduleService {

	/**
	 * Default interval (hours) used when a source has sync enabled but no
	 * explicit interval configured.
	 */
	public const DEFAULT_INTERVAL_HOURS = 24;

	/**
	 * Whether a single source is due for synchronisation at $now.
	 *
	 * A source is due when:
	 *  - sync is enabled, AND
	 *  - it is not already running (last status != 'running'), AND
	 *  - it has never synced, OR the configured interval has elapsed.
	 *
	 * @param Source $source The source to evaluate
	 * @param DateTimeInterface $now The reference moment
	 *
	 * @return bool True when the source should be queued for sync
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function isDueForSync(Source $source, DateTimeInterface $now): bool {
		if ($source->getSyncEnabled() !== true) {
			return false;
		}

		// Skip sources whose previous execution is still in progress
		// (overlap protection).
		if ($source->getLastSyncStatus() === 'running') {
			return false;
		}

		$lastSync = $source->getLastSyncDate();
		if ($lastSync === null) {
			// Never synced: due immediately.
			return true;
		}

		$intervalHours = $source->getSyncInterval();
		if ($intervalHours === null || $intervalHours <= 0) {
			$intervalHours = self::DEFAULT_INTERVAL_HOURS;
		}

		$hoursPassed = ($now->getTimestamp() - $lastSync->getTimestamp()) / 3600;

		return $hoursPassed >= $intervalHours;
	}//end isDueForSync()

	/**
	 * Select the subset of sources that are due for sync at $now.
	 *
	 * @param array<Source> $sources The candidate sources
	 * @param DateTimeInterface $now The reference moment
	 *
	 * @return list<Source> The sources that are due
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function selectDueSources(array $sources, DateTimeInterface $now): array {
		$due = [];
		foreach ($sources as $source) {
			if ($this->isDueForSync(source: $source, now: $now) === true) {
				$due[] = $source;
			}
		}

		return $due;
	}//end selectDueSources()
}//end class
