<?php

/**
 * MigrationServiceInterface: what callers outside the Service component ask of MigrationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Moves a schema's objects between blob storage and its magic table.
 */
interface MigrationServiceInterface {

	/**
	 * Resolve register and schema from IDs or slugs, with the register as the boundary.
	 *
	 * The two used to be resolved INDEPENDENTLY — the schema by a global
	 * `SchemaMapper::find()` that matches `LOWER(slug)` across every register and
	 * every app on the instance. `GET /api/migration/status/{register}/{schema}`
	 * then reported the storage status of whichever same-slug schema the tie-break
	 * ordered first, and `MigrateStorageCommand` would have migrated it. A status
	 * or a migration aimed at the wrong table is worse than an error.
	 *
	 * @param string|int $registerId Register ID, uuid, or slug — the boundary.
	 * @param string|int $schemaId   Schema ID, uuid, or slug, resolved within that register.
	 *
	 * @return array{register: Register, schema: Schema}
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException If the register does not resolve.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotInRegisterException If the register does not carry the schema.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function resolveRegisterAndSchema(string|int $registerId, string|int $schemaId): array;

	/**
	 * Get storage status for a register/schema combination.
	 *
	 * Returns only magic table information since blob storage has been removed.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 *
	 * @return array Storage status with magic table counts.
	 *
	 * @spec exclude Reporting shim: assembles register/schema/magic-table-count read into a response array; no business rule.
	 */
	public function getStorageStatus(Register $register, Schema $schema): array;

	/**
	 * Migrate objects from blob storage to a magic table.
	 *
	 * NOTE: Blob storage (ObjectEntityMapper) has been removed. Use the
	 * BlobMigrationJob background job instead, which reads directly from the
	 * raw objects table.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 * @param int $batchSize Number of objects per batch.
	 * @param bool $dryRun If true, report what would happen without changes.
	 *
	 * @return array Migration report indicating blob storage is no longer available.
	 *
	 * @spec exclude Deprecated no-op stub; blob storage retired, returns a static report pointing to BlobMigrationJob.
	 */
	public function migrateToMagicTable(
		Register $register,
		Schema $schema,
		int $batchSize = 100,
		bool $dryRun = false,
	): array;

	/**
	 * Migrate objects from a magic table to blob storage.
	 *
	 * NOTE: Blob storage (ObjectEntityMapper) has been removed. Reverse migration
	 * to blob storage is no longer supported.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 * @param int $batchSize Number of objects per batch.
	 * @param bool $dryRun If true, report what would happen without changes.
	 *
	 * @return array Migration report indicating blob storage is no longer available.
	 *
	 * @spec exclude Deprecated no-op stub; reverse migration to retired blob storage is no longer supported.
	 */
	public function migrateToBlobStorage(
		Register $register,
		Schema $schema,
		int $batchSize = 100,
		bool $dryRun = false,
	): array;
}//end interface
