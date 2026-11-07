<?php

/**
 * MigrationService for OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCP\IDBConnection;

/**
 * Service for migrating objects between blob storage and magic tables.
 *
 * NOTE: Blob storage (ObjectEntityMapper) has been removed. This service
 * is retained for the status endpoint but migration is no longer possible.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class MigrationService implements MigrationServiceInterface {

	/**
	 * The shared register-scoped schema resolver.
	 *
	 * Built here rather than injected: it is a stateless collaborator over the
	 * `RegisterMapper` + `SchemaMapper` this class already holds, so constructing
	 * it directly keeps every existing unit test — all of which mock those two
	 * mappers — exercising the REAL resolution path instead of a mock of the very
	 * thing under test.
	 *
	 * @var RegisterScopedSchemaResolver
	 */
	private readonly RegisterScopedSchemaResolver $scopedSchemaResolver;

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $magicMapper The magic mapper.
	 * @param RegisterMapperInterface $registerMapper The register mapper.
	 * @param SchemaMapperInterface $schemaMapper The schema mapper.
	 */
	public function __construct(
		private readonly MagicMapperInterface $magicMapper,
		RegisterMapperInterface $registerMapper,
		SchemaMapperInterface $schemaMapper,
	) {
		$this->scopedSchemaResolver = new RegisterScopedSchemaResolver(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper
		);
	}//end __construct()

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
	public function resolveRegisterAndSchema(string|int $registerId, string|int $schemaId): array {
		return $this->scopedSchemaResolver->resolvePair(registerRef: $registerId, schemaRef: $schemaId);
	}//end resolveRegisterAndSchema()


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
	public function getStorageStatus(Register $register, Schema $schema): array {
		$magicTableExists = $this->magicMapper->existsTableForRegisterSchema(
			register: $register,
			schema: $schema
		);

		return [
			'register' => $this->identity(entity: $register),
			'schema' => $this->identity(entity: $schema),
			'magicTable' => [
				'exists' => $magicTableExists,
				'count' => $this->magicCount(exists: $magicTableExists, register: $register, schema: $schema),
			],
		];
	}//end getStorageStatus()

	/**
	 * Migrate objects from blob storage to a magic table.
	 *
	 * NOTE: Blob storage (ObjectEntityMapper) has been removed. Use the
	 * BlobMigrationJob background job instead, which reads directly from the
	 * raw oc_openregister_objects table via IDBConnection.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 * @param int $batchSize Number of objects per batch.
	 * @param bool $dryRun If true, report what would happen without changes.
	 *
	 * @return array Migration report indicating blob storage is no longer available.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec exclude Deprecated no-op stub; blob storage retired, returns a static report pointing to BlobMigrationJob.
	 */
	public function migrateToMagicTable(
		Register $register,
		Schema $schema,
		int $batchSize = 100,
		bool $dryRun = false,
	): array {
		$message = 'Blob storage mapper has been removed. Use BlobMigrationJob background job instead.';
		return $this->retiredReport(direction: 'to-magic', dryRun: $dryRun, message: $message);
	}//end migrateToMagicTable()

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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec exclude Deprecated no-op stub; reverse migration to retired blob storage is no longer supported.
	 */
	public function migrateToBlobStorage(
		Register $register,
		Schema $schema,
		int $batchSize = 100,
		bool $dryRun = false,
	): array {
		$message = 'Blob storage mapper has been removed. Reverse migration is no longer supported.';
		return $this->retiredReport(direction: 'to-blob', dryRun: $dryRun, message: $message);
	}//end migrateToBlobStorage()

	/**
	 * The id, name and slug a status report gives a register or a schema.
	 *
	 * @param Register|Schema $entity The register or schema.
	 *
	 * @return array{id: int|null, name: string|null, slug: string|null}
	 */
	private function identity(Register|Schema $entity): array {
		return [
			'id' => $entity->getId(),
			'name' => $entity->getTitle(),
			'slug' => $entity->getSlug(),
		];
	}//end identity()

	/**
	 * The number of objects in the magic table, or 0 when it does not exist.
	 *
	 * @param bool     $exists   Whether the magic table exists.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return int The object count.
	 */
	private function magicCount(bool $exists, Register $register, Schema $schema): int {
		if ($exists === false) {
			return 0;
		}

		return $this->magicMapper->countObjectsInRegisterSchemaTable(
			query: [],
			register: $register,
			schema: $schema
		);
	}//end magicCount()

	/**
	 * The report of a migration that the removal of blob storage made impossible.
	 *
	 * @param string $direction The migration direction.
	 * @param bool   $dryRun    Whether the caller asked for a dry run.
	 * @param string $message   Why the migration did not run.
	 *
	 * @return array The migration report.
	 */
	private function retiredReport(string $direction, bool $dryRun, string $message): array {
		return [
			'direction' => $direction,
			'dryRun' => $dryRun,
			'total' => 0,
			'migrated' => 0,
			'skipped' => 0,
			'failed' => 0,
			'errors' => [],
			'message' => $message,
		];
	}//end retiredReport()
}//end class
