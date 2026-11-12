<?php

/**
 * RbacOperatorTerm
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
 * One entry of an operator object in an RBAC `match` condition, such as
 * `"$in": ["open", "pending"]`: the operator as written and its operand.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacOperatorTerm {

	/**
	 * Constructor.
	 *
	 * @param int|string $operator The operator key as written; only a string can name an operator.
	 * @param mixed      $operand  The operand as written, before dynamic values are resolved.
	 */
	public function __construct(
		public readonly int|string $operator,
		public readonly mixed $operand,
	) {
	}//end __construct()

	/**
	 * Whether the operand is a list of values, as `$in` and `$nin` require.
	 *
	 * @return bool True for a list; false for a map or a scalar.
	 */
	public function operandIsList(): bool {
		return is_array($this->operand) === true && array_is_list($this->operand) === true;
	}//end operandIsList()
}//end class
