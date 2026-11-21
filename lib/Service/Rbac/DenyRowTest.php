<?php

/**
 * The column, verb and platform one row-level deny predicate is emitted for.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Where a row's authorization block sits, which verb is filtered and which SQL dialect speaks.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class DenyRowTest {

	/**
	 * Constructor.
	 *
	 * @param string $columnName The `_authorization` column, qualified by the caller.
	 * @param string $action     The verb being filtered, checked against the safe pattern before use.
	 * @param bool   $isPostgres Whether the connected platform is PostgreSQL.
	 */
	public function __construct(
		public readonly string $columnName,
		public readonly string $action,
		public readonly bool $isPostgres,
	) {
	}//end __construct()
}//end class
