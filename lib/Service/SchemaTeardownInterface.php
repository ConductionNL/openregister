<?php

/**
 * SchemaTeardownInterface: what the schema delete endpoint and the prune command ask of SchemaDeletionService.
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

use OCA\OpenRegister\Db\Schema;

/**
 * Tearing down a schema and counting what a teardown would remove or refuse. Only the methods SchemasController and the prune-retired command call.
 */
interface SchemaTeardownInterface {

	/**
	 * How many objects `cascadeDeleteSchema()` would destroy.
	 *
	 * Counts what the delete enumerates: every magic table resolved for the
	 * schema, including ones whose register no longer references it.
	 *
	 * @param Schema $schema The schema to count for.
	 *
	 * @return int The number of objects a cascade delete would remove.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function countObjectsCascadeWouldDelete(Schema $schema): int;

	/**
	 * Cascade-delete a schema: its objects, its magic tables, and the schema itself.
	 *
	 * Kept rows and archival schemas are refused unless the override lifts them.
	 *
	 * @param Schema                 $schema   The schema to tear down.
	 * @param SchemaTeardownOverride $override What an operator lifted from the CLI; nothing by default.
	 *
	 * @throws \OCA\OpenRegister\Exception\ArchivalImmutableException If the schema is archival and no override was given.
	 * @throws \Throwable If a kept row refuses, or phase 1 fails (nothing is deleted).
	 *
	 * @return array{deletedCount: int, deletedUuids: array<int, string>, tableDropped: bool} The cascade result.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function cascadeDeleteSchema(
		Schema $schema,
		SchemaTeardownOverride $override = new SchemaTeardownOverride(),
	): array;

	/**
	 * How many rows of the schema refuse a teardown unless it is forced.
	 *
	 * @param Schema $schema The schema to count for.
	 *
	 * @return int The number of frozen, archived, locked or unreadable rows.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function countKeptRows(Schema $schema): int;

	/**
	 * Drop the schema's magic tables, but only when they hold no rows at all.
	 *
	 * Never throws: a failed reclaim of an empty table must not fail the delete that
	 * already succeeded.
	 *
	 * @param Schema $schema The schema whose tables should be reclaimed.
	 *
	 * @return bool True when no magic table for this schema remains.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function dropEmptyTablesForSchema(Schema $schema): bool;
}//end interface
