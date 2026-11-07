<?php

/**
 * SchemaApplicationMigratorInterface: the part of SchemaApplicationMigrator that the migrate-schema-application command uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The methods of SchemaApplicationMigrator that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SchemaApplicationMigratorInterface {

	/**
	 * Count rows owned by an application id.
	 *
	 * @param string $table       The table to count in.
	 * @param string $application The owning application id.
	 *
	 * @return int The row count.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function countFor(string $table, string $application): int;

	/**
	 * Find slugs that exist under BOTH application ids.
	 *
	 * @param string $from The current application id.
	 * @param string $to   The new application id.
	 *
	 * @return string[] The colliding slugs.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function collidingSlugs(string $from, string $to): array;

	/**
	 * Perform the migration, refusing when it would create duplicates.
	 *
	 * Idempotent: a second run finds nothing under the old id and reports
	 * `moved` counts of zero rather than failing.
	 *
	 * @param string $from The current application id.
	 * @param string $to   The new application id.
	 *
	 * @return array{ok: bool, reason: string, collisions: string[], schemas: int, registers: int}
	 *     The outcome. `ok` false with a non-empty `collisions` means an import
	 *     already forked those schemas and the caller must resolve them first.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function migrate(string $from, string $to): array;
}//end interface
