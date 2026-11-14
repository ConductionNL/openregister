<?php

/**
 * One leaf of a `_where` condition tree: a field, an operator and a value.
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
 * A validated leaf. The field names a schema property or an `@self.<column>`
 * metadata column — the same names a flat filter accepts — so the condition
 * builder can hand it to the per-property code. The node path lets a refusal
 * later in the build (a property the schema does not declare, an encrypted
 * one) name the row that failed.
 *
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */
final class ConditionLeaf {

	/**
	 * Constructor.
	 *
	 * @param string $field    The schema property or `@self.<column>` the leaf names.
	 * @param string $operator One of the accepted operators (`eq` plus COMPARISON_OPERATORS).
	 * @param mixed  $value    The compared value.
	 * @param string $path     The node path of this leaf, such as `$.children[0]`.
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $operator,
		public readonly mixed $value,
		public readonly string $path,
	) {
	}//end __construct()

	/**
	 * The leaf as the flat filter map it is equivalent to, so a leaf and the
	 * flat filter produce the same SQL. `eq` is the bare value; every other
	 * operator is wrapped under its own key, as `applyObjectFilters()` reads it.
	 *
	 * @return array<string, mixed> The one-key filter map `{field => value}`.
	 *
	 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
	 */
	public function asFlatFilter(): array {
		if ($this->operator === ConditionGroupParser::OPERATOR_EQ) {
			return [$this->field => $this->value];
		}

		return [$this->field => [$this->operator => $this->value]];
	}//end asFlatFilter()
}//end class
