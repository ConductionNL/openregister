<?php

/**
 * SearchIndexMaintenanceInterface: what callers outside the Search component use of SearchIndexMaintenance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Search;

/**
 * The state of the search index, as the settings status page reads it.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
interface SearchIndexMaintenanceInterface {

	/**
	 * Whether this platform can rebuild an index without taking it away first.
	 *
	 * @return bool True on PostgreSQL.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function supportsConcurrentRebuild(): bool;

	/**
	 * Every magic table in scope, as bare table names.
	 *
	 * @param int|null $registerId Limit to one register, or null for all of them.
	 *
	 * @return array<int, string> The bare table names.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function tablesInScope(?int $registerId = null): array;

	/**
	 * How many rows the tables in scope hold, in all and per schema slug.
	 *
	 * @return array{rowsInTables?: int, indexed: array<string, int>} The counts that could be made.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function rowCounts(): array;

	/**
	 * The index names of many tables.
	 *
	 * @param array<int, string> $tableNames The tables, without the prefix.
	 *
	 * @return array<string, array<int, string>> Each table's index names, sorted, keyed as given.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function indexNamesFor(array $tableNames): array;

	/**
	 * The report of the last rebuild, for the surface an administrator reads.
	 *
	 * @return array<string, mixed> The stored report, or an empty array before the first run.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function lastRun(): array;
}//end interface
