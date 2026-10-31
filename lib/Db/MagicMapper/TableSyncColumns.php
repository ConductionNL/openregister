<?php

/**
 * TableSyncColumns
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The columns of one magic-table sync, and the statistics reported for it.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class TableSyncColumns {

	/**
	 * The columns every magic table carries, whatever its schema.
	 *
	 * @var array<int, string>
	 */
	private const METADATA_COLUMNS = [
		'id',
		'uuid',
		'register',
		'schema',
		'object',
		'deleted',
		'locked',
		'updated',
		'created',
		'version',
	];

	/**
	 * Constructor.
	 *
	 * @param array $required The columns the schema needs, keyed by name
	 * @param array $changes  What the structure update did: columnsAdded, columnsDeRequired, columnsReRequired, columnsDropped
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		public readonly array $required,
		public readonly array $changes = [],
	) {
	}//end __construct()

	/**
	 * The statistics of a newly created table: every column was added.
	 *
	 * @return array The statistics.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function createdStatistics(): array {
		$metadataCount = $this->metadataCount();

		return [
			'success' => true,
			'created' => true,
			'metadataProperties' => $metadataCount,
			'regularProperties' => count($this->required) - $metadataCount,
			'totalProperties' => count($this->required),
			'columnsAdded' => count($this->required),
			'columnsDeRequired' => 0,
			'columnsDropped' => 0,
			'columnsUnchanged' => 0,
			'columnsAddedList' => array_keys($this->required),
			'columnsDeRequiredList' => [],
			'columnsDroppedList' => [],
		];
	}//end createdStatistics()

	/**
	 * The statistics of an existing table whose structure was updated.
	 *
	 * @param array $current The columns the table had before the update, keyed by name
	 *
	 * @return array The statistics.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function updatedStatistics(array $current): array {
		$metadataCount = $this->metadataCount();
		$added = count($this->changes['columnsAdded']);
		$dropped = count($this->changes['columnsDropped']);

		return [
			'success' => true,
			'metadataProperties' => $metadataCount,
			'regularProperties' => count($this->required) - $metadataCount,
			'totalProperties' => count($this->required),
			'columnsAdded' => $added,
			'columnsDeRequired' => count($this->changes['columnsDeRequired']),
			'columnsReRequired' => count($this->changes['columnsReRequired']),
			'columnsDropped' => $dropped,
			'columnsUnchanged' => count($current) - $added - $dropped,
			'columnsAddedList' => $this->changes['columnsAdded'],
			'columnsDeRequiredList' => $this->changes['columnsDeRequired'],
			'columnsReRequiredList' => $this->changes['columnsReRequired'],
			'columnsDroppedList' => $this->changes['columnsDropped'],
		];
	}//end updatedStatistics()

	/**
	 * How many of the required columns are metadata columns.
	 *
	 * @return int The count.
	 */
	private function metadataCount(): int {
		return count(array_intersect(array_keys($this->required), self::METADATA_COLUMNS));
	}//end metadataCount()
}//end class
