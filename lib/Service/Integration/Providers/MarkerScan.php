<?php

/**
 * Where a leaf provider looks for the legacy `[or:{uuid}]` marker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * One marker scan of an upstream app's table: the table, the column the
 * marker is searched in, the marker itself, and the other columns to return
 * with the primary key first (ADR-011 Rule 2).
 *
 * @spec exclude Shared defensive LIKE-scan parameter object for leaf list() impls, no standalone contract.
 */
final class MarkerScan {

	/**
	 * Describe the scan.
	 *
	 * @param string            $table        Table name without the `oc_` prefix.
	 * @param string            $markerColumn Column to search the marker in.
	 * @param string            $marker       Marker substring (e.g. `[or:UUID]`).
	 * @param array<int,string> $columns      The primary-key column, then the other columns to return.
	 *
	 * @spec exclude Shared defensive LIKE-scan parameter object for leaf list() impls, no standalone contract.
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $markerColumn,
		public readonly string $marker,
		public readonly array $columns=['id'],
	) {
	}//end __construct()

	/**
	 * The columns the scan selects: the primary key, the marker column, then the rest, each once.
	 *
	 * @return array<int,string> The select list.
	 *
	 * @spec exclude Shared defensive LIKE-scan parameter object for leaf list() impls, no standalone contract.
	 */
	public function selectList(): array {
		$idColumn = ($this->columns[0] ?? 'id');
		return array_values(array_unique(array_merge([$idColumn, $this->markerColumn], array_slice($this->columns, 1))));
	}//end selectList()
}//end class
