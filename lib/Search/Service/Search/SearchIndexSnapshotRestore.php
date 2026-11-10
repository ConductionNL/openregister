<?php

/**
 * OpenRegister Search Index Snapshot Restore
 *
 * Recreates, from a snapshot file, the search indexes the database is missing.
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
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Search;

use OCP\IDBConnection;
use RuntimeException;
use Throwable;

/**
 * The restore half of SearchIndexMaintenance.
 *
 * An index that is already there is left alone rather than dropped and
 * remade: restoring is about closing a gap, not about rebuilding what already
 * answers.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final class SearchIndexSnapshotRestore {

	/**
	 * Constructor.
	 *
	 * @param SearchIndexMaintenance $maintenance Reads the indexes a table has now.
	 * @param IDBConnection          $db          Runs the CREATE statements.
	 */
	public function __construct(
		private readonly SearchIndexMaintenance $maintenance,
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Recreate from a snapshot every index the database is missing.
	 *
	 * @param string          $path The snapshot file to read.
	 * @param SearchIndexPass $pass The dry-run switch.
	 *
	 * @throws RuntimeException When the file cannot be read or is not a snapshot.
	 *
	 * @return array<string, mixed> The report: what was missing, created and refused.
	 */
	public function restore(string $path, SearchIndexPass $pass): array {
		[$missing, $failures] = $this->restoreTables(tables: $this->readSnapshot(path: $path)['tables'], pass: $pass);

		return [
			'path' => $path,
			'missing' => count($missing),
			'created' => $pass->changed(succeeded: count($missing) - count($failures)),
			'failed' => count($failures),
			'failures' => $failures,
			'indexes' => $missing,
		];
	}//end restore()

	/**
	 * Find, and unless this is a dry run create, the snapshot indexes the database is missing.
	 *
	 * @param array<string, mixed> $tables The snapshot's index definitions by bare table name.
	 * @param SearchIndexPass      $pass   The dry-run switch.
	 *
	 * @return array{0: array<int, array{table: string, index: string}>, 1: array<int, array<string, string>>} Missing, failed.
	 */
	private function restoreTables(array $tables, SearchIndexPass $pass): array {
		$missing  = [];
		$failures = [];
		foreach ($tables as $tableName => $indexes) {
			$gaps    = $this->gapsIn(tableName: (string)$tableName, indexes: (array)$indexes);
			$missing = array_merge($missing, $gaps);
			if ($pass->apply === true) {
				$failures = array_merge($failures, $this->createMissing(gaps: $gaps, indexes: (array)$indexes));
			}
		}

		return [$missing, $failures];
	}//end restoreTables()

	/**
	 * Read and check an index snapshot file.
	 *
	 * @param string $path The snapshot file to read.
	 *
	 * @throws RuntimeException When the file cannot be read or is not a snapshot.
	 *
	 * @return array<string, mixed> The snapshot, with an array under `tables`.
	 */
	private function readSnapshot(string $path): array {
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException("Could not read the index snapshot at '{$path}'.");
		}

		$snapshot = json_decode($raw, true);
		if (is_array($snapshot) === false || is_array($snapshot['tables'] ?? null) === false) {
			throw new RuntimeException("'{$path}' is not an index snapshot this version can read.");
		}

		return $snapshot;
	}//end readSnapshot()

	/**
	 * The indexes of a snapshot table the database does not have.
	 *
	 * @param string               $tableName The bare table name.
	 * @param array<string, mixed> $indexes   The snapshot's definitions by index name.
	 *
	 * @return array<int, array{table: string, index: string}> The missing indexes, in snapshot order.
	 */
	private function gapsIn(string $tableName, array $indexes): array {
		$present = $this->maintenance->indexesFor(tableName: $tableName);
		$gaps    = [];
		foreach (array_keys($indexes) as $indexName) {
			if (isset($present[$indexName]) === false) {
				$gaps[] = ['table' => $tableName, 'index' => (string)$indexName];
			}
		}

		return $gaps;
	}//end gapsIn()

	/**
	 * Create the missing indexes from their snapshot definitions.
	 *
	 * @param array<int, array{table: string, index: string}> $gaps    The missing indexes.
	 * @param array<string, mixed>                            $indexes The snapshot's definitions by index name.
	 *
	 * @return array<int, array<string, string>> The indexes that could not be created, with the error.
	 */
	private function createMissing(array $gaps, array $indexes): array {
		$failures = [];
		foreach ($gaps as $gap) {
			try {
				$this->db->executeStatement((string)$indexes[$gap['index']]);
			} catch (Throwable $exception) {
				$failures[] = $gap + ['error' => $exception->getMessage()];
			}
		}

		return $failures;
	}//end createMissing()
}//end class
