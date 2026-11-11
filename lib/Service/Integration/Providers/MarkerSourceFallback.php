<?php

/**
 * MarkerSourceFallback: the legacy marker scan of a link-table provider.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * The rows of an object without links: the rows of the app's own table that
 * carry the legacy `[or:{uuid}]` marker.
 *
 * The using provider provides `$this->db` (IDBConnection), uses
 * MarkerLookupTrait, and declares `MARKER_PREFIX` and `MARKER_SOURCE`.
 */
trait MarkerSourceFallback {

	/**
	 * Backwards-compatible fallback for an object without links: the rows that
	 * carry the legacy `[or:{uuid}]` marker in the app's own table.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function unlinkedRows(string $objectId): array {
		$source = self::MARKER_SOURCE;
		$scan = new MarkerScan(
			table: $source['table'],
			markerColumn: $source['column'],
			marker: self::MARKER_PREFIX . $objectId . ']',
			columns: array_merge([$source['id']], $source['extra'])
		);
		$rows = $this->scanMarker(db: $this->db, scan: $scan);

		return array_map(fn (array $row): array => $this->rowFromMarker(row: $row), $rows);
	}//end unlinkedRows()

	/**
	 * One marker-scan row in the registry leaf-row shape.
	 *
	 * @param array<string,mixed> $row The scanned row.
	 *
	 * @return array<string,mixed>
	 */
	protected function rowFromMarker(array $row): array {
		$source = self::MARKER_SOURCE;
		$id = (string)($row[$source['id']] ?? '');

		return ['id' => $id, 'title' => (string)($row[$source['column']] ?? ''), 'url' => $source['url'] . $id, 'data' => $row];
	}//end rowFromMarker()
}//end trait
