<?php

/**
 * OpenRegister Sync Conflict
 *
 * The two sides of one harvested record, as the conflict resolver weighs them.
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

/**
 * Whether the local copy changed since the last sync, and when each side was
 * last modified (only the newest-wins strategy reads the timestamps).
 *
 * The operation object of SyncConflictResolver::resolve() (ADR-011 Rule 2).
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SyncConflict {
	/**
	 * Constructor.
	 *
	 * @param bool                   $localChanged   Whether the local object changed since the last sync
	 * @param DateTimeInterface|null $sourceModified Source-side last-modified timestamp (for newest-wins)
	 * @param DateTimeInterface|null $localModified  Local-side last-modified timestamp (for newest-wins)
	 */
	public function __construct(
		public readonly bool $localChanged,
		public readonly ?DateTimeInterface $sourceModified = null,
		public readonly ?DateTimeInterface $localModified = null,
	) {
	}//end __construct()
}//end class
