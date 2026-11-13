<?php

/**
 * One group of a `_where` condition tree: an AND or OR over its children.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * A validated group. Its children are {@see ConditionGroup} or
 * {@see ConditionLeaf} nodes, so the condition builder wraps the child
 * expressions in `andX()` for an AND and `orX()` for an OR.
 *
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */
final class ConditionGroup {

	/**
	 * An AND group: every child must hold.
	 */
	public const TYPE_AND = 'AND';

	/**
	 * An OR group: at least one child must hold.
	 */
	public const TYPE_OR = 'OR';

	/**
	 * Constructor.
	 *
	 * @param string                                 $type     One of TYPE_AND or TYPE_OR.
	 * @param array<int, ConditionGroup|ConditionLeaf> $children The group's children, at least one.
	 * @param string                                 $path     The node path of this group, `$` for the root.
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $children,
		public readonly string $path,
	) {
	}//end __construct()

	/**
	 * Every leaf under this group, in document order, for a limit or a sweep.
	 *
	 * @return array<int, ConditionLeaf> The leaves.
	 *
	 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
	 */
	public function leaves(): array {
		$leaves = [];
		foreach ($this->children as $child) {
			if ($child instanceof ConditionLeaf) {
				$leaves[] = $child;
				continue;
			}

			$leaves = array_merge($leaves, $child->leaves());
		}

		return $leaves;
	}//end leaves()
}//end class
