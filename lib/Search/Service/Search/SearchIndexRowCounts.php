<?php

/**
 * OpenRegister Search Index Row Counts
 *
 * The rows the search index covers, gathered table by table: a total, and a
 * count per schema slug. A count that could not be made leaves its slug out,
 * and the total too, so nobody reads a number that is too low.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-the-search-index-status-names-how-many-rows-it-covers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Search;

/**
 * Adds up row counts per schema slug.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-the-search-index-status-names-how-many-rows-it-covers
 */
final class SearchIndexRowCounts {

	/**
	 * The rows of every table counted so far.
	 *
	 * @var integer
	 */
	private int $total = 0;

	/**
	 * Rows by schema slug.
	 *
	 * @var array<string, int>
	 */
	private array $bySlug = [];

	/**
	 * The slugs with a table that could not be counted.
	 *
	 * @var array<string, true>
	 */
	private array $uncounted = [];

	/**
	 * Add one table's rows.
	 *
	 * @param string   $slug The slug of the schema the table holds.
	 * @param int|null $rows Its rows, or null when they could not be counted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-the-search-index-status-names-how-many-rows-it-covers
	 */
	public function add(string $slug, ?int $rows): void {
		if ($rows === null) {
			$this->uncounted[$slug] = true;
			return;
		}

		$this->total += $rows;
		$this->bySlug[$slug] = (($this->bySlug[$slug] ?? 0) + $rows);
	}//end add()

	/**
	 * The counts, as the status endpoint answers them.
	 *
	 * @return array{rowsInTables?: int, indexed: array<string, int>}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-the-search-index-status-names-how-many-rows-it-covers
	 */
	public function toArray(): array {
		$indexed = array_diff_key($this->bySlug, $this->uncounted);
		if ($this->uncounted !== []) {
			return ['indexed' => $indexed];
		}

		return ['rowsInTables' => $this->total, 'indexed' => $indexed];
	}//end toArray()
}//end class
