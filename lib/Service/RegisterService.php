<?php

/**
 * OpenRegister RegisterService
 *
 * Service class for managing registers in the OpenRegister application.
 *
 * This service acts as a facade for register operations,
 * coordinating between RegisterMapper and FileService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\Files\Node;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * RegisterService manages registers in the OpenRegister application
 *
 * Service class for managing registers in the OpenRegister application.
 * This service acts as a facade for register operations, coordinating between
 * RegisterMapper and FileService. Handles register CRUD operations, file management,
 * and organisation-related operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $_multitenancy boolean flag is part of the public API
 * contract shared with RegisterMapper::findAll/find; the method signature must match callers that
 * explicitly pass `false` to bypass multitenancy for admin/repair contexts.
 */
class RegisterService implements RegisterServiceInterface {

	/**
	 * The count columns every per-schema stats entry carries, in order.
	 *
	 * @var array<int, string>
	 */
	private const COUNT_FIELDS = ['total', 'deleted', 'invalid', 'locked', 'size'];

	/**
	 * Register mapper
	 *
	 * Handles database operations for register entities.
	 *
	 * @var RegisterMapperInterface Register mapper instance
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper
	 *
	 * Handles database operations for schema entities.
	 *
	 * @var SchemaMapperInterface Schema mapper instance
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Database connection
	 *
	 * Direct database connection for custom queries.
	 *
	 * @var IDBConnection Database connection instance
	 */
	private readonly IDBConnection $db;

	/**
	 * Every magic table that exists, as a name => true lookup. Null until first read.
	 *
	 * Populated once per request by {@see magicTableExists()}. See that method for why
	 * this cache has to exist at all.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $magicTableNames = null;

	/**
	 * File service for file operations.
	 *
	 * @var FileFolderServiceInterface
	 */
	private readonly FileFolderServiceInterface $fileService;

	/**
	 * Applies `_extend` post-processing (`schemas`, `@self.stats`).
	 *
	 * @var RegisterSerializer
	 */
	private readonly RegisterSerializer $registerSerializer;

	/**
	 * Constructor
	 *
	 * Initializes service with required dependencies for register operations.
	 *
	 * @param RegisterServiceStorage       $storage             Register and schema mappers and the raw connection.
	 * @param RegisterServiceHelpers $helpers File service for register folders and the register serializer.
	 * @param OrganisationServiceInterface $organisationService Organisation service for permissions.
	 * @param LoggerInterface              $logger              Logger for error tracking.
	 *                                                          `@self.stats`), shared between the HTTP
	 *                                                          controller and DI consumers.
	 *
	 * @return void
	 */
	public function __construct(
		RegisterServiceStorage $storage,
		RegisterServiceHelpers $helpers,
		private readonly OrganisationServiceInterface $organisationService,
		private readonly LoggerInterface $logger,
	) {
		$this->fileService = $helpers->fileService;
		$this->registerSerializer = $helpers->registerSerializer;
		$this->logConstruction(phase: 'started');
		// Store dependencies for use in service methods.
		$this->registerMapper = $storage->registerMapper;
		$this->schemaMapper   = $storage->schemaMapper;
		$this->db             = $storage->db;
		$this->logConstruction(phase: 'completed');
	}//end __construct()

	/**
	 * Trace a construction phase.
	 *
	 * @param string $phase `started` or `completed`.
	 *
	 * @return void
	 */
	private function logConstruction(string $phase): void {
		$this->logger->debug(
			message: '[RegisterService] RegisterService constructor ' . $phase . '.',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logConstruction()

	/**
	 * Find a register by ID with optional extensions
	 *
	 * Retrieves register entity by ID with optional extended data.
	 * Extensions can include related entities like schemas, objects, etc.
	 *
	 * @param int|string $id The ID of the register to find.
	 * @param array<string> $_extend Optional array of extension names to include.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return Register The found register entity
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If register not found
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple registers found (should not happen)
	 * @throws \OCP\DB\Exception If database error occurs
	 *
	 * @spec exclude Pure pass-through to RegisterMapper::find; no business logic.
	 */
	public function find(int|string $id, array $_extend = [], bool $_multitenancy = true): Register {
		// NB: `_extend` is declared for signature compatibility with the
		// pre-spec contract; the mapper no longer accepts it. Use
		// `findSerialized()` to obtain an array payload with `_extend`
		// post-processing applied.
		unset($_extend);
		return $this->registerMapper->find(id: $id, _multitenancy: $_multitenancy);
	}//end find()

	/**
	 * Find all registers with optional filters and extensions
	 *
	 * Retrieves all registers matching optional filters and search conditions.
	 * Supports pagination via limit and offset parameters.
	 * Extensions can include related entities like schemas, objects, etc.
	 *
	 * @param int|null $limit Maximum number of results to return (null = no limit)
	 * @param int|null $offset Number of results to skip for pagination
	 * @param array<string, mixed>|null $filters Filters to apply (e.g., ['organisation_id' => 1])
	 * @param array<string, mixed>|null $searchConditions Search conditions for advanced filtering
	 * @param array<string, mixed>|null $searchParams Search parameters for query building
	 * @param array<string> $_extend Optional extensions to include in results.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return Register[] Array of found register entities
	 *
	 * @psalm-return array<Register>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Optional parameters use null defaults for flexibility
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Multiple optional filter parameters for flexibility
	 *
	 * @spec exclude Pure pass-through to RegisterMapper::findAll; no business logic.
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		?array $_extend = [],
		bool $_multitenancy = true,
	): array {
		// Find all registers with optional filtering, pagination, and extensions.
		// NB: `_extend` is declared for signature compatibility only — the
		// mapper layer ignores it; honored extensions live on findSerialized
		// / findAllSerialized which delegate to RegisterSerializer.
		return $this->registerMapper->findAll(
			limit: $limit,
			offset: $offset,
			filters: $filters,
			searchConditions: $searchConditions,
			searchParams: $searchParams,
			_multitenancy: $_multitenancy
		);
	}//end findAll()

	/**
	 * Find a register by ID and serialize it with `_extend` applied
	 *
	 * Combines `find()` with `RegisterSerializer::serialize()`. When
	 * `_extend` requests both `schemas` and `@self.stats`, this method
	 * pre-computes the per-schema counts via `getSchemaObjectCounts()`
	 * and hands them to the serializer so the serializer itself has
	 * no DI back-reference to this service.
	 *
	 * @param int|string $id The ID of the register to find.
	 * @param array $_extend Extension keys to apply (`schemas`, `@self.stats`).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return array Serialized register payload.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If register not found.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 *   (Requirement: RegisterService SHALL expose serialized query methods that honor `_extend`)
	 */
	public function findSerialized(int|string $id, array $_extend = [], bool $_multitenancy = true): array {
		$register = $this->find(id: $id, _extend: $_extend, _multitenancy: $_multitenancy);

		$stats = null;
		if ($this->wantsSchemaStats(_extend: $_extend) === true) {
			$stats = $this->statsFor(register: $register);
		}

		return $this->registerSerializer->serialize($register, $_extend, $stats);
	}//end findSerialized()

	/**
	 * Whether `_extend` asks for both `schemas` and `@self.stats`, the case that needs per-schema counts.
	 *
	 * @param array<string> $_extend Extension keys.
	 *
	 * @return bool True when both are requested.
	 */
	private function wantsSchemaStats(array $_extend): bool {
		return in_array('schemas', $_extend, true) === true
			&& in_array('@self.stats', $_extend, true) === true;
	}//end wantsSchemaStats()

	/**
	 * The per-schema object counts of one register.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<int, array{total: int}> Schema id to counts.
	 */
	private function statsFor(Register $register): array {
		return $this->getSchemaObjectCounts(
			registerId: (int)$register->getId(),
			schemas: $this->resolveSchemasForStats(schemaIds: ($register->getSchemas() ?? []))
		);
	}//end statsFor()

	/**
	 * Find all registers + serialize each with `_extend` applied
	 *
	 * Companion to {@see findAll()} that returns serialized arrays with
	 * `_extend` honored. When `schemas` + `@self.stats` are both
	 * requested, per-register stats are pre-computed and handed to the
	 * serializer via `serializeMany()`.
	 *
	 * @param int|null $limit Maximum number of results.
	 * @param int|null $offset Pagination offset.
	 * @param array|null $filters Mapper filters.
	 * @param array|null $searchConditions Search conditions.
	 * @param array|null $searchParams Search parameters.
	 * @param array<string> $_extend Extension keys to apply.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return array<int,array> Array of serialized register payloads.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors findAll() so HTTP and DI
	 *   callers can hand the same parameter set to either method without re-wrangling
	 *   query params.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 *   (Requirement: RegisterService SHALL expose serialized query methods that honor `_extend`)
	 */
	public function findAllSerialized(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		array $_extend = [],
		bool $_multitenancy = true,
	): array {
		$registers = $this->findAll(
			limit: $limit,
			offset: $offset,
			filters: $filters,
			searchConditions: $searchConditions,
			searchParams: $searchParams,
			_extend: $_extend,
			_multitenancy: $_multitenancy
		);

		return $this->registerSerializer->serializeMany($registers, $_extend, $this->statsByRegisterId(registers: $registers, _extend: $_extend));
	}//end findAllSerialized()

	/**
	 * Per-register schema counts when `_extend` asks for them, else null.
	 *
	 * @param Register[]    $registers The registers to count for.
	 * @param array<string> $_extend   Extension keys.
	 *
	 * @return array<int, array<int, array{total: int}>>|null Register id to schema counts.
	 */
	private function statsByRegisterId(array $registers, array $_extend): ?array {
		if ($this->wantsSchemaStats(_extend: $_extend) === false) {
			return null;
		}

		$statsByRegisterId = [];
		foreach ($registers as $register) {
			$statsByRegisterId[(int)$register->getId()] = $this->statsFor(register: $register);
		}

		return $statsByRegisterId;
	}//end statsByRegisterId()

	/**
	 * Resolve schema IDs to hydrated objects for stats computation.
	 *
	 * `getSchemaObjectCounts()` reads `tablePrefix` and `slug` off each
	 * schema (used to build the magic-table name). The serializer hands
	 * us bare IDs, so we resolve them here. Orphan IDs are silently
	 * dropped — they cannot produce a stats lookup anyway.
	 *
	 * @param array $schemaIds Schema ID array (int|string).
	 *
	 * @return array<int,\OCA\OpenRegister\Db\Schema> Resolved schema entities for the stats query.
	 */
	private function resolveSchemasForStats(array $schemaIds): array {
		$schemas = [];
		foreach ($schemaIds as $schemaId) {
			try {
				$schemas[] = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Orphan IDs cannot contribute to stats; skip silently.
				// The RegisterSerializer logs the orphan when it hits the
				// same ID during expansion.
				continue;
			}
		}

		return $schemas;
	}//end resolveSchemasForStats()

	/**
	 * Create a new register from array data.
	 *
	 * @param array $data The data to create the register from
	 *
	 * @return Register The created register
	 *
	 * @throws Exception If register creation fails
	 *
	 * @spec openspec/specs/file-actions/spec.md#object-and-register-folder-provisioning
	 *   (after mapper create, assigns the active/default organisation for multi-tenancy
	 *   and provisions the register's backing folder)
	 */
	public function createFromArray(array $data): Register {
		// Create the register first.
		$register = $this->registerMapper->createFromArray(object: $data);

		// Set organisation from active organisation for multi-tenancy (if not already set).
		if ($register->getOrganisation() === null || $register->getOrganisation() === '') {
			$organisationUuid = $this->organisationService->getOrganisationForNewEntity();
			$register->setOrganisation($organisationUuid);
			$register = $this->registerMapper->update($register);
		}

		// Ensure folder exists for the new register.
		$this->ensureRegisterFolderExists(entity: $register);
		$this->logCreated(register: $register);

		return $register;
	}//end createFromArray()

	/**
	 * Say, once, what was created.
	 *
	 * ONE line, at the end, saying what was created — replacing seven that
	 * narrated the steps ("Starting", "Calling ensureRegisterFolderExists",
	 * "Folder creation completed"). Creating a register IS worth info: it is
	 * rare and structural. Each intermediate step of doing so is not, and
	 * reporting them at info made the interesting line harder to find, not
	 * easier. On failure the exception carries the location anyway.
	 *
	 * @param Register $register The created register.
	 *
	 * @return void
	 */
	private function logCreated(Register $register): void {
		$this->logger->info(
			message: sprintf(
				'[RegisterService] Created register %d (%s) in organisation %s',
				$register->getId(),
				($register->getSlug() ?? $register->getTitle() ?? ''),
				($register->getOrganisation() ?? 'none')
			),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logCreated()

	/**
	 * Update an existing register from array data.
	 *
	 * @param int $id The ID of the register to update
	 * @param array $data The new data for the register
	 *
	 * @return Register The updated register
	 *
	 * @throws Exception If register update fails
	 *
	 * @spec openspec/specs/file-actions/spec.md#object-and-register-folder-provisioning
	 *   (after mapper update, ensures the register's backing folder exists,
	 *   healing legacy null/string folder properties)
	 */
	public function updateFromArray(int $id, array $data): Register {
		// Update the register first.
		$register = $this->registerMapper->updateFromArray(id: $id, object: $data);

		// Ensure folder exists for the updated register (handles legacy folder properties).
		$this->ensureRegisterFolderExists(entity: $register);

		return $register;
	}//end updateFromArray()

	/**
	 * Delete a register, then remove its folder.
	 *
	 * The folder goes only after the row is gone: the mapper refuses a register
	 * that still has objects, and that refusal must leave the folder in place.
	 * A folder that cannot be removed is logged, not raised, because the
	 * register itself is already deleted (openregister#4107).
	 *
	 * @param Register $register The register to delete
	 *
	 * @return Register The deleted register
	 *
	 * @throws Exception If register has attached objects or deletion fails
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function delete(Register $register): Register {
		$deleted = $this->registerMapper->delete($register);

		try {
			$this->fileService->deleteRegisterFolder(register: $register);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: "[RegisterService] Register {$register->getId()} was deleted but its folder was not removed: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return $deleted;
	}//end delete()

	/**
	 * Ensure folder exists for a Register.
	 *
	 * This method checks if the register has a valid folder ID and creates one if needed.
	 * It handles legacy cases where the folder property might be null, empty, or a string path.
	 *
	 * @param Register $entity The register entity to ensure folder for
	 *
	 * @return void
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple folder state checks and error handling
	 */
	private function ensureRegisterFolderExists(Register $entity): void {
		$folderProperty = $entity->getFolder();

		// Check if folder needs to be created (null, empty string, or legacy string path).
		if ($folderProperty === null || $folderProperty === '' || is_string($folderProperty) === true) {
			$this->createRegisterFolder(entity: $entity);
		}
	}//end ensureRegisterFolderExists()

	/**
	 * Create the register's folder and record its id; a failure is logged, never raised.
	 *
	 * @param Register $entity The register.
	 *
	 * @return void
	 */
	private function createRegisterFolder(Register $entity): void {
		try {
			// Create folder and get the folder node.
			$this->recordFolder(entity: $entity, folderNode: $this->fileService->createEntityFolder($entity));
		} catch (Exception $e) {
			// Log the error but don't fail the register creation/update.
			// The register can still function without a folder.
			$this->logger->error(
				message: "[RegisterService] Failed to create folder for register {$entity->getId()}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end createRegisterFolder()

	/**
	 * Store a freshly created folder's id on the register.
	 *
	 * @param Register  $entity     The register.
	 * @param Node|null $folderNode The created folder, or null when creation failed.
	 *
	 * @return void
	 */
	private function recordFolder(Register $entity, ?Node $folderNode): void {
		if ($folderNode === null) {
			$this->logger->warning(
				message: "[RegisterService] Failed to create folder for register {$entity->getId()}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		// Update the entity with the folder ID, then save it.
		$entity->setFolder((string)$folderNode->getId());
		$this->registerMapper->update($entity);
		$this->logFolderCreated(entity: $entity, folderNode: $folderNode);
	}//end recordFolder()

	/**
	 * Log the folder a register was given.
	 *
	 * @param Register $entity     The register.
	 * @param Node     $folderNode Its new folder.
	 *
	 * @return void
	 */
	private function logFolderCreated(Register $entity, Node $folderNode): void {
		$folderId   = $folderNode->getId();
		$registerId = $entity->getId();
		$this->logger->info(
			message: "[RegisterService] Created folder with ID {$folderId} for register {$registerId}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logFolderCreated()

	/**
	 * Get object counts per schema for a register using optimized SQL
	 *
	 * This method builds a single SQL query that counts objects for each schema
	 * from their magic tables.
	 *
	 * @param int $registerId The register ID to get counts for
	 * @param array $schemas Array of schema objects with their configurations
	 *
	 * @return array<int, array{total: int}> Associative array mapping schema IDs to counts
	 *
	 * @psalm-return array<int, array{total: int}>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function getSchemaObjectCounts(int $registerId, array $schemas): array {
		$result = [];
		if (empty($schemas) === true) {
			return $result;
		}

		try {
			$this->countSchemaObjects(registerId: $registerId, schemas: $schemas, result: $result);
		} catch (\Exception $e) {
			// Log the error but don't fail: the counts gathered so far are returned.
			$this->logCountFailure(e: $e);
		}

		return $result;
	}//end getSchemaObjectCounts()

	/**
	 * Fill `$result` with the counts of every schema, as far as it gets before a failure.
	 *
	 * @param int                                  $registerId The register id.
	 * @param array                                $schemas    The schemas, in any accepted shape.
	 * @param array<array-key, array<string, int>> $result     Schema id to counts, filled in place.
	 *
	 * @return void
	 */
	private function countSchemaObjects(int $registerId, array $schemas, array &$result): void {
		$schemaCount = count($schemas);
		$this->logger->debug(
			message: "[RegisterService] GetSchemaObjectCounts: Processing $schemaCount schemas for register $registerId",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$unionQueries = $this->schemaCountQueries(registerId: $registerId, schemas: $schemas, result: $result);
		if (empty($unionQueries) === true) {
			return;
		}

		$this->runCountQueries(unionQueries: $unionQueries, result: $result);
	}//end countSchemaObjects()

	/**
	 * The count query of every schema whose magic table exists; missing tables count zero.
	 *
	 * @param int                                  $registerId The register id.
	 * @param array                                $schemas    The schemas, in any accepted shape.
	 * @param array<array-key, array<string, int>> $result     Schema id to counts, filled in place for missing tables.
	 *
	 * @return array<int, string> The SELECTs to union.
	 */
	private function schemaCountQueries(int $registerId, array $schemas, array &$result): array {
		$schemaIdFormat = $this->schemaIdSqlFormat();
		$unionQueries   = [];
		foreach ($schemas as $schema) {
			$query = $this->schemaCountQuery(registerId: $registerId, schema: $schema, format: $schemaIdFormat, result: $result);
			if ($query !== null) {
				$unionQueries[] = $query;
			}
		}

		return $unionQueries;
	}//end schemaCountQueries()

	/**
	 * The count query of one schema, or null when it has no id or no magic table.
	 *
	 * @param int                                  $registerId The register id.
	 * @param mixed                                $schema     The schema in any accepted shape.
	 * @param string                               $format     The sprintf format rendering the id as SQL text.
	 * @param array<array-key, array<string, int>> $result     Schema id to counts; a missing table counts zero.
	 *
	 * @return string|null The SELECT, or null.
	 */
	private function schemaCountQuery(int $registerId, mixed $schema, string $format, array &$result): ?string {
		$schemaId = $this->schemaIdOf(schema: $schema);
		if ($schemaId === null) {
			$this->warnSchemaWithoutId();
			return null;
		}

		$tableName = 'openregister_table_' . $registerId . '_' . $schemaId;
		if ($this->magicTableExists(tableName: $tableName) !== true) {
			$result[$schemaId] = $this->getZeroCountStats();
			return null;
		}

		return $this->schemaCountSql(schemaIdExpr: sprintf($format, $schemaId), tableName: $tableName);
	}//end schemaCountQuery()

	/**
	 * Log a schema that carries no id.
	 *
	 * @return void
	 */
	private function warnSchemaWithoutId(): void {
		$this->logger->warning(
			message: '[RegisterService] Schema without ID found, skipping',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end warnSchemaWithoutId()

	/**
	 * Run the unioned count queries and store each schema's counts.
	 *
	 * Raw SQL: QueryBuilder cannot compose a UNION ALL across an arbitrary
	 * number of dynamically-named magic tables (one per schema/register pair).
	 *
	 * @param array<int, string>                   $unionQueries The SELECTs to union.
	 * @param array<array-key, array<string, int>> $result       Schema id to counts, filled in place.
	 *
	 * @return void
	 */
	private function runCountQueries(array $unionQueries, array &$result): void {
		$sql = implode(' UNION ALL ', $unionQueries);
		$this->logger->debug(
			message: '[RegisterService] Schema object counts SQL: ' . $sql,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$result[(int)$row['schema_id']] = $this->getZeroCountStats(row: $row);
		}

		$stmt->closeCursor();
	}//end runCountQueries()

	/**
	 * Log a failed count run, with its stack trace.
	 *
	 * @param \Exception $e The failure.
	 *
	 * @return void
	 */
	private function logCountFailure(\Exception $e): void {
		$this->logger->error(
			message: '[RegisterService] Error getting schema object counts: ' . $e->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$this->logger->error(
			message: '[RegisterService] Stack trace: ' . $e->getTraceAsString(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logCountFailure()

	/**
	 * The sprintf format that renders a schema id as text in SQL.
	 *
	 * PostgreSQL uses `::text`, MariaDB/MySQL need CAST AS CHAR (as lib/Db/MagicMapper.php does).
	 * get_debug_type() is null-safe: a mocked IDBConnection returns null for the platform.
	 *
	 * @return string The format, with one %s for the id.
	 */
	private function schemaIdSqlFormat(): string {
		$platform = $this->db->getDatabasePlatform();
		if (stripos(get_debug_type($platform), 'PostgreSQL') !== false) {
			return '%s::text';
		}

		return 'CAST(%s AS CHAR)';
	}//end schemaIdSqlFormat()

	/**
	 * The id of a schema given as a Schema entity, an array with an `id`, or a numeric id.
	 *
	 * Schemas arrive hydrated (the `_extend[]=schemas` + `@self.stats` path), as plain
	 * arrays, or as scalar ids.
	 *
	 * @param mixed $schema The schema in any of those shapes.
	 *
	 * @return mixed The id, or null when it has none.
	 */
	private function schemaIdOf(mixed $schema): mixed {
		if ($schema instanceof \OCA\OpenRegister\Db\Schema) {
			return $schema->getId();
		}

		if (is_array($schema) === true) {
			return $schema['id'] ?? null;
		}

		if (is_numeric($schema) === true) {
			return (int)$schema;
		}

		return null;
	}//end schemaIdOf()

	/**
	 * The count query for one schema's magic table.
	 *
	 * @param string $schemaIdExpr SQL expression for the schema id as text.
	 * @param string $tableName    The unprefixed magic table name.
	 *
	 * @return string The SELECT returning schema_id, total, deleted, invalid, locked and size.
	 */
	private function schemaCountSql(string $schemaIdExpr, string $tableName): string {
		$quotedTableName = $this->db->getQueryBuilder()->getTableName($tableName);
		return "
                    SELECT
                        {$schemaIdExpr} as schema_id,
                        COUNT(*) as total,
                        COUNT(CASE WHEN _deleted IS NOT NULL THEN 1 END) as deleted,
                        0 as invalid,
                        0 as locked,
                        0 as size
                    FROM {$quotedTableName}
                ";
	}//end schemaCountSql()

	/**
	 * Whether a magic table exists, answered from a single cached catalog read.
	 *
	 * IDBConnection::tableExists() looks cheap and is not: it goes to Doctrine's schema
	 * manager, which re-reads `information_schema.tables` on EVERY call. Called once per
	 * schema inside getSchemaObjectCounts()'s loop, that is one full catalog scan per
	 * schema — on a dev instance with 76 registers / 1,231 schemas / 1,960 magic tables
	 * it made `GET /api/registers?_extend[]=schemas&_extend[]=@self.stats` take **76
	 * seconds**, ~90% of it inside that one introspection query. The same call without
	 * the extend took 0.9s.
	 *
	 * So read the catalog ONCE and answer from memory. The cache is per-request (the
	 * service is request-scoped), which is the right lifetime: a magic table created by
	 * a concurrent request is irrelevant to a stats snapshot already in flight, and a
	 * table created by THIS request goes through MagicMapper, not here.
	 *
	 * @param string $tableName The unprefixed magic table name (`openregister_table_1_2`).
	 *
	 * @return bool True when the table exists.
	 *
	 * @spec exclude performance cache over information_schema; behaviour identical to tableExists()
	 */
	private function magicTableExists(string $tableName): bool {
		if ($this->magicTableNames === null) {
			try {
				$this->loadMagicTableNames();
			} catch (\Throwable $e) {
				return $this->tableExistsAfterCatalogFailure(tableName: $tableName, e: $e);
			}
		}

		return isset($this->magicTableNames[$tableName]);
	}//end magicTableExists()

	/**
	 * Read every magic table name from the catalog into the per-request lookup.
	 *
	 * Match on the `openregister_table_` marker itself, NOT on a computed
	 * prefix. The obvious `getQueryBuilder()->getTableName('')` returns the
	 * literal `*PREFIX*` placeholder — it is only resolved to the real prefix
	 * (`oc_`) when a query is EXECUTED through the NC DB layer, which a raw
	 * information_schema string never is. Building the LIKE from that
	 * placeholder matched zero tables, so every schema was reported as having
	 * zero objects. Anchoring on the marker sidesteps the prefix completely:
	 * the caller passes `openregister_table_R_S`, which is exactly the suffix
	 * we key on.
	 *
	 * @return void
	 */
	private function loadMagicTableNames(): void {
		$this->magicTableNames = [];

		$sql  = 'SELECT table_name FROM information_schema.tables';
		$sql .= ' WHERE table_name LIKE :pattern';

		$stmt = $this->db->prepare($sql);
		$stmt->bindValue('pattern', '%openregister\_table\_%');
		$stmt->execute();
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$this->rememberMagicTable(name: (string)($row['table_name'] ?? ''));
		}

		$stmt->closeCursor();
	}//end loadMagicTableNames()

	/**
	 * Add one catalog table name to the lookup, keyed from the marker onwards.
	 *
	 * Key on the unprefixed marker onwards, so callers need not know the
	 * prefix: `oc_openregister_table_1_2` => `openregister_table_1_2`.
	 *
	 * @param string $name The catalog table name.
	 *
	 * @return void
	 */
	private function rememberMagicTable(string $name): void {
		if ($name === '') {
			return;
		}

		$pos = strpos($name, 'openregister_table_');
		if ($pos !== false) {
			$this->magicTableNames[substr($name, $pos)] = true;
		}
	}//end rememberMagicTable()

	/**
	 * Answer one table check directly after the catalog read failed.
	 *
	 * A failed catalog read must not become "no tables exist" — that would
	 * silently report every schema as having zero objects. Fall back to the
	 * slow-but-correct per-table check for the rest of this request. Catch
	 * \Throwable, not just \Exception: a stats read is not worth a fatal if
	 * the platform's query builder is unavailable.
	 *
	 * @param string     $tableName The unprefixed magic table name.
	 * @param \Throwable $e         Why the catalog read failed.
	 *
	 * @return bool True when the table exists.
	 */
	private function tableExistsAfterCatalogFailure(string $tableName, \Throwable $e): bool {
		$this->logger->warning(
			message: '[RegisterService] Could not list magic tables, falling back to per-table checks: ' . $e->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$this->magicTableNames = null;

		return $this->db->tableExists($tableName);
	}//end tableExistsAfterCatalogFailure()

	/**
	 * Get a zero-initialized count stats array, optionally populated from a database row.
	 *
	 * @param array|null $row Optional database result row to extract counts from.
	 *
	 * @return array{total: int, deleted: int, invalid: int, locked: int, size: int}
	 */
	private function getZeroCountStats(?array $row = null): array {
		if ($row === null) {
			$row = array_fill_keys(self::COUNT_FIELDS, 0);
		}

		$stats = [];
		foreach (self::COUNT_FIELDS as $field) {
			$stats[$field] = (int)$row[$field];
		}

		return $stats;
	}//end getZeroCountStats()
}//end class
