<?php

/**
 * OpenRegister ReadableTrailRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * One walk down the audit trail for a page of the rows a caller may read.
 *
 * The state ReadableAuditTrailLister's helpers share: the applied filters,
 * the page size, whether read entries are shown, and the search, plus the
 * walk's progress (the offset reached and the rows kept). It never leaves
 * ReadableAuditTrailLister.
 */
final class ReadableTrailRun {

	/**
	 * Where the walk has got to in the trail.
	 *
	 * @var int
	 */
	public int $offset = 0;

	/**
	 * The rows kept so far, as the caller is given them.
	 *
	 * @var array<int, array>
	 */
	public array $results = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $filters   The filters applied to the trail query.
	 * @param int                  $limit     The page size.
	 * @param bool                 $withReads Whether the caller may see read entries.
	 * @param string               $search    The free-text search, trimmed; empty for none.
	 */
	public function __construct(
		public readonly array $filters,
		public readonly int $limit,
		public readonly bool $withReads,
		public readonly string $search,
	) {
	}//end __construct()

	/**
	 * Room left on the page.
	 *
	 * @return int How many more rows the page takes.
	 */
	public function room(): int {
		return ($this->limit - count($this->results));
	}//end room()
}//end class
