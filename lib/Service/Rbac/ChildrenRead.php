<?php

/**
 * OpenRegister ChildrenRead.
 *
 * One read of the children of a set of parents in a magic table: the table,
 * the column naming the parent, how many rows the read may take, and the
 * children collected so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The state of one chunked children read inside {@see HierarchyDescender}.
 *
 * A run object: HierarchyDescender::childrenOf() builds it and it never leaves
 * that class. `children` is the progress field the chunks fill.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ChildrenRead {

	/**
	 * The children read so far: child uuid => parent uuid.
	 *
	 * @var array<string, string>
	 */
	public array $children = [];

	/**
	 * Constructor.
	 *
	 * @param string   $table        The magic table.
	 * @param string   $parentColumn The column naming the parent.
	 * @param int|null $limit        The most rows to read, or null for the whole level.
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $parentColumn,
		public readonly ?int $limit,
	) {
	}//end __construct()

	/**
	 * How many rows the read may still take, or null when it has no limit.
	 *
	 * @return int|null The remaining rows.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function remaining(): ?int {
		if ($this->limit === null) {
			return null;
		}

		return ($this->limit - count($this->children));
	}//end remaining()

	/**
	 * Whether the read has collected as many children as its limit allows.
	 *
	 * @return bool True when the limit is met.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function limitMet(): bool {
		return ($this->limit !== null && count($this->children) >= $this->limit);
	}//end limitMet()
}//end class
