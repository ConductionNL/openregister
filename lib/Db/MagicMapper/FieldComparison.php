<?php

/**
 * FieldComparison
 *
 * One filter operator with its operand.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * One comparison an object-field filter asks for: the query-builder
 * expression operator (`eq`, `gte`, `in`, `notIn`, ...) and the operand it
 * compares the column with (ADR-011 Rule 2, value object).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class FieldComparison
{

	/**
	 * Constructor.
	 *
	 * @param string $operator The expression-builder method (`eq`, `neq`, `gte`, `in`, `notIn`, ...).
	 * @param mixed  $operand  The value, or the list of values for `in` and `notIn`.
	 */
	public function __construct(
		public readonly string $operator,
		public readonly mixed $operand,
	) {
	}//end __construct()
}//end class
