<?php

/**
 * OpenRegister ReferrerPage
 *
 * One page of the objects that reference a given object, filled table by table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The page an incoming-relation lookup fills, and how far into the referrers it has read.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class ReferrerPage {

	/**
	 * The requested page size, as given (default 30).
	 *
	 * @var mixed
	 */
	public readonly mixed $limit;

	/**
	 * The requested offset, as given (default 0).
	 *
	 * @var mixed
	 */
	public readonly mixed $offset;

	/**
	 * The rows collected so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * How many referrers the table reads have returned so far.
	 *
	 * @var integer
	 */
	private int $read = 0;

	/**
	 * Constructor.
	 *
	 * @param string|null          $targetUuid The uuid the referrers point at.
	 * @param array<string, mixed> $query      The request's paging parameters.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly ?string $targetUuid,
		array $query,
	) {
		$this->limit = $query['_limit'] ?? 30;
		$this->offset = $query['_offset'] ?? 0;
	}//end __construct()

	/**
	 * Whether the page holds as many rows as were asked for.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function isFull(): bool {
		return count($this->rows) >= $this->limit;
	}//end isFull()

	/**
	 * How many rows the next table read may return.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function remaining(): mixed {
		return $this->limit - count($this->rows);
	}//end remaining()

	/**
	 * Where the next table read starts, given what earlier reads returned.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function nextOffset(): mixed {
		return max(0, $this->offset - $this->read);
	}//end nextOffset()

	/**
	 * Add one referrer row.
	 *
	 * @param array<string, mixed> $row The serialised referrer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function add(array $row): void {
		$this->rows[] = $row;
	}//end add()

	/**
	 * Count what one table read returned, the referenced object itself included.
	 *
	 * @param int $count The number of rows the read returned.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function countRead(int $count): void {
		$this->read += $count;
	}//end countRead()

	/**
	 * The collected rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function rows(): array {
		return $this->rows;
	}//end rows()
}//end class
