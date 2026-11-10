<?php

/**
 * The `_authorization` column of a magic table, and the platform that reads it.
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
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The column name, qualified by the caller, and whether the platform is PostgreSQL.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class AuthorizationColumn {

	/**
	 * Constructor.
	 *
	 * @param string $name       The `_authorization` column, qualified by the caller.
	 * @param bool   $isPostgres Whether the connected platform is PostgreSQL.
	 */
	public function __construct(
		public readonly string $name,
		public readonly bool $isPostgres,
	) {
	}//end __construct()
}//end class
