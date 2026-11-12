<?php

/**
 * OpenRegister JoinedColumnProjection
 *
 * One join-qualified group field projected onto the parent rows: which
 * parent field carries the join key, which joined field it matches, which
 * joined field supplies the value, and the column the value is stamped into.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Stamps each parent row with the joined value its join key maps to.
 *
 * The synthetic column is named after the QUALIFIED field, so a caller
 * reading `keys` sees the name it asked for rather than an internal alias.
 *
 * A parent row whose key matches nothing gets NULL rather than being
 * dropped: dropping it would quietly shrink every total, while a null
 * bucket is visible and is the honest answer for "belongs to no dimension".
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class JoinedColumnProjection {

	/**
	 * Constructor.
	 *
	 * @param string $parentKey   Parent-side join field.
	 * @param string $joinedKey   Joined-side join field.
	 * @param string $sourceField Joined field to project.
	 * @param string $column      Synthetic column name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly string $parentKey,
		public readonly string $joinedKey,
		public readonly string $sourceField,
		public readonly string $column,
	) {
	}//end __construct()

	/**
	 * The parent rows carrying the projected column.
	 *
	 * @param array<int, array<string, mixed>> $rows       Parent rows.
	 * @param array<int, array<string, mixed>> $joinedRows Joined rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function stamp(array $rows, array $joinedRows): array {
		$lookup = $this->lookup(joinedRows: $joinedRows);

		foreach ($rows as $index => $row) {
			$parentValue = ($row[$this->parentKey] ?? null);
			$projected = null;
			if ($parentValue !== null) {
				$projected = ($lookup[(string)$parentValue] ?? null);
			}

			$rows[$index][$this->column] = $projected;
		}

		return $rows;
	}//end stamp()

	/**
	 * Joined key => projected value, the last joined row winning a duplicate key.
	 *
	 * @param array<int, array<string, mixed>> $joinedRows Joined rows.
	 *
	 * @return array<string, mixed>
	 */
	private function lookup(array $joinedRows): array {
		$lookup = [];
		foreach ($joinedRows as $joinedRow) {
			$key = ($joinedRow[$this->joinedKey] ?? null);
			if ($key === null) {
				continue;
			}

			$lookup[(string)$key] = ($joinedRow[$this->sourceField] ?? null);
		}

		return $lookup;
	}//end lookup()
}//end class
