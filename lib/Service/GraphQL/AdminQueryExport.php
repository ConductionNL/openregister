<?php

/**
 * OpenRegister AdminQueryExport
 *
 * Flattens the first list of a GraphQL result into a table and writes it as
 * CSV or JSON.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\GraphQL;

use OCA\OpenRegister\Exception\NoTabularResultException;
use OCA\OpenRegister\Service\Export\SpreadsheetCell;

/**
 * The download half of the console: it takes the first list in a result, reads
 * `edges[].node` for a connection or the array itself for a plain list,
 * flattens each row to columns by dot path (an array value written as its
 * JSON) and renders CSV or JSON.
 *
 * The CSV is UTF-8 with a byte order mark so a spreadsheet opens it in the
 * right encoding, and every cell goes through {@see SpreadsheetCell} so a
 * value that would read as a formula (`=`, `+`, `-`, `@`, ...) is made inert.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class AdminQueryExport {

	/**
	 * The UTF-8 byte order mark a spreadsheet reads to pick the encoding.
	 *
	 * @var string
	 */
	private const BOM = "\xEF\xBB\xBF";

	/**
	 * The first list of a result as a table of column paths and rows.
	 *
	 * @param array<string, mixed> $result The GraphQL result.
	 *
	 * @return array{columns: list<string>, rows: list<array<string, mixed>>} The table.
	 *
	 * @throws NoTabularResultException When the result holds no list.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function tableOf(array $result): array {
		$rows = $this->firstList(data: ($result['data'] ?? []));
		if ($rows === null) {
			throw new NoTabularResultException(message: 'The result has no list to download. A download needs a query that returns a list of records.');
		}

		$flatRows = [];
		$columns = [];
		foreach ($rows as $row) {
			$flat = $this->flatten(value: $row);
			$flatRows[] = $flat;
			foreach (array_keys($flat) as $column) {
				$columns[$column] = true;
			}
		}

		return ['columns' => array_keys($columns), 'rows' => $flatRows];
	}//end tableOf()

	/**
	 * Render a table as CSV: a byte order mark, a header row of the columns,
	 * then one formula-safe row per record.
	 *
	 * @param array{columns: list<string>, rows: list<array<string, mixed>>} $table The table.
	 *
	 * @return string The CSV document.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `SpreadsheetCell::csvRow()` is a stateless value helper.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function toCsv(array $table): string {
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, SpreadsheetCell::csvRow(row: $table['columns']));
		foreach ($table['rows'] as $row) {
			$ordered = [];
			foreach ($table['columns'] as $column) {
				$ordered[] = ($row[$column] ?? '');
			}

			fputcsv($handle, SpreadsheetCell::csvRow(row: $ordered));
		}

		rewind($handle);
		$csv = (string) stream_get_contents($handle);
		fclose($handle);

		return self::BOM . $csv;
	}//end toCsv()

	/**
	 * Render a table as a JSON array of row objects.
	 *
	 * @param array{columns: list<string>, rows: list<array<string, mixed>>} $table The table.
	 *
	 * @return string The JSON document.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function toJson(array $table): string {
		return (string) json_encode($table['rows'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end toJson()

	/**
	 * The first list in the result's data: the `edges[].node` of a connection,
	 * or a plain array of records; null when there is none.
	 *
	 * @param mixed $data The result's `data`.
	 *
	 * @return list<mixed>|null The records, or null.
	 */
	private function firstList(mixed $data): ?array {
		if (is_array($data) === false) {
			return null;
		}

		foreach ($data as $value) {
			if (is_array($value) === false) {
				continue;
			}

			if (array_key_exists('edges', $value) === true && is_array($value['edges']) === true) {
				return array_values(array_map(static function (mixed $edge): mixed {
					if (is_array($edge) === true) {
						return ($edge['node'] ?? $edge);
					}

					return $edge;
				}, $value['edges']));
			}

			if (array_is_list($value) === true) {
				return $value;
			}
		}

		return null;
	}//end firstList()

	/**
	 * Flatten a record to `path => scalar`, an array value written as its JSON.
	 *
	 * @param mixed  $value  The value.
	 * @param string $prefix The dotted path so far.
	 *
	 * @return array<string, mixed> The flattened record.
	 */
	private function flatten(mixed $value, string $prefix = ''): array {
		if (is_array($value) === false) {
			return [$prefix => $value];
		}

		if (array_is_list($value) === true) {
			return [$prefix => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
		}

		$flat = [];
		foreach ($value as $key => $nested) {
			$path = $prefix . '.' . $key;
			if ($prefix === '') {
				$path = (string) $key;
			}

			$flat = array_merge($flat, $this->flatten(value: $nested, prefix: $path));
		}

		return $flat;
	}//end flatten()
}//end class
