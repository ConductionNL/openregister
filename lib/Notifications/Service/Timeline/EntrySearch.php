<?php

/**
 * A search over timeline entries: the term, and how the hits are narrowed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

/**
 * One entry search.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
 */
final class EntrySearch {
	/**
	 * Hold the search.
	 *
	 * @param string      $term       The search term.
	 * @param string|null $visibility The visibility the caller is entitled to, or null for both.
	 * @param string|null $kind       Narrow to one kind.
	 * @param integer     $limit      How many hits to return.
	 */
	public function __construct(
		public readonly string $term,
		public readonly ?string $visibility = null,
		public readonly ?string $kind = null,
		public readonly int $limit = 25,
	) {
	}//end __construct()
}//end class
