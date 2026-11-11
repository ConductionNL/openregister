<?php

/**
 * RawObjectFieldFilter
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
 * One object field being filtered on the raw-SQL (UNION) search path.
 *
 * The string-built twin of ObjectFieldFilter: this path concatenates its SQL
 * and cannot bind, so it carries the connection that quotes every value.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class RawObjectFieldFilter {

	/**
	 * Constructor.
	 *
	 * @param string $quotedCol    The quoted column identifier
	 * @param string $propertyType The property's declared JSON-Schema type
	 * @param object $connection   The database connection, for value quoting
	 * @param bool   $isPostgres   Whether the active platform is PostgreSQL
	 */
	public function __construct(
		public readonly string $quotedCol,
		public readonly string $propertyType,
		public readonly object $connection,
		public readonly bool $isPostgres,
	) {
	}//end __construct()

	/**
	 * One value, quoted by the connection as a string literal.
	 *
	 * @param mixed $value The scalar value.
	 *
	 * @return string The quoted literal.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function quote(mixed $value): string {
		return (string) $this->connection->quote((string) $value);
	}//end quote()

	/**
	 * A list of values, each quoted, joined for an IN clause.
	 *
	 * @param array $values The scalar values.
	 *
	 * @return string The quoted literals, comma separated.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function quoteList(array $values): string {
		return implode(', ', array_map(fn ($value): string => $this->quote(value: $value), $values));
	}//end quoteList()
}//end class
