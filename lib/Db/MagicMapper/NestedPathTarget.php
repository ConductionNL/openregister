<?php

/**
 * OpenRegister NestedPathTarget.
 *
 * The expression one path filter compares, and how its values reach the SQL.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Closure;

/**
 * What every condition of one path filter is written against.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
 */
final class NestedPathTarget {

	/**
	 * The expression reading the text at the path, from NestedPathFilter::expression().
	 *
	 * @var string
	 */
	public readonly string $expression;

	/**
	 * Whether the platform is PostgreSQL.
	 *
	 * @var boolean
	 */
	public readonly bool $isPostgres;

	/**
	 * Value to SQL: a named parameter or a quoted literal.
	 *
	 * @var Closure(string): string
	 */
	private readonly Closure $binder;

	/**
	 * Constructor.
	 *
	 * @param string   $expression The expression reading the text at the path.
	 * @param callable $bind       Value to SQL: fn(string $value): string.
	 * @param bool     $isPostgres Whether the platform is PostgreSQL.
	 */
	public function __construct(string $expression, callable $bind, bool $isPostgres) {
		$this->expression = $expression;
		$this->binder     = $bind(...);
		$this->isPostgres = $isPostgres;

	}//end __construct()

	/**
	 * A value in its SQL form.
	 *
	 * @param string $value The text to bind.
	 *
	 * @return string The named parameter or quoted literal.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 */
	public function bind(string $value): string {
		return ($this->binder)($value);

	}//end bind()

	/**
	 * The expression read as a number, for a comparison with a numeric operand.
	 *
	 * @return string The numeric expression.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 */
	public function numeric(): string {
		if ($this->isPostgres === true) {
			// A non-numeric text would abort the cast, so only numeric text is compared.
			return "(CASE WHEN {$this->expression} ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST({$this->expression} AS NUMERIC) END)";
		}

		return "CAST({$this->expression} AS DECIMAL(30,10))";

	}//end numeric()
}//end class
