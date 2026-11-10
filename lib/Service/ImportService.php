<?php

/**
 * OpenRegister Import Service
 *
 * This file contains the class for handling data import operations in the OpenRegister application.
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
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Closure;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Listener\NotifyPushBatchInterface;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaLookup;
use OCA\OpenRegister\Service\Configuration\ImportHandler as ConfigurationImportHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Import\DryRunBatch;
use OCA\OpenRegister\Service\Import\ImportErrorsCsv;
use OCA\OpenRegister\Service\Import\ImportMapping;
use OCA\OpenRegister\Service\Import\ImportOptions;
use OCA\OpenRegister\Service\Import\ImportRequest;
use OCA\OpenRegister\Service\Import\ImportScope;
use OCA\OpenRegister\Service\Import\ImportServiceCodecs;
use OCA\OpenRegister\Service\Import\ImportServiceRuntime;
use OCA\OpenRegister\Service\Import\ImportServiceStores;
use OCA\OpenRegister\Service\Import\ImportTarget;
use OCA\OpenRegister\Service\Import\ImportValueCaster;
use OCA\OpenRegister\Service\Import\JsonImportRows;
use OCA\OpenRegister\Service\Import\MappedSheet;
use OCA\OpenRegister\Service\Import\MappingErrorRow;
use OCA\OpenRegister\Service\Import\RowImportTarget;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCP\IGroupManager;
use OCP\IUser;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\Async\PromiseInterface;
use React\EventLoop\Loop;
use React\Promise\Promise;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\Object\ObjectDeletion;

/**
 * Service for importing data from various formats with ReactPHP optimization
 *
 * This service handles importing data from CSV and Excel files with automatic
 * array parsing for fields that contain multiple values. Arrays can be provided
 * in various formats including JSON, comma-separated, or quoted values.
 *
 * ### Performance Optimizations
 *
 * - **Chunked Processing**: Processes data in configurable chunks to prevent memory overflow
 * - **Concurrent Operations**: Uses ReactPHP promises for concurrent object creation/updates
 * - **Memory Management**: Clears processed data after each chunk to prevent memory leaks
 * - **Progress Tracking**: Provides real-time progress updates during import
 *
 * @package OCA\OpenRegister\Service
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Import service requires comprehensive data transformation methods
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required for multi-format import support
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex import logic with multiple data formats
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   Import methods require comprehensive configuration parameters
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Requires many dependencies for import operations
 * @SuppressWarnings(PHPMD.LongVariable)             Descriptive variable names improve code readability
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ImportService implements ObjectImporterInterface {

	/**
	 * Default chunk size for processing
	 *
	 * @var int
	 */
	private const DEFAULT_CHUNK_SIZE = 5;

	/**
	 * Minimum chunk size for very complex data
	 *
	 * @var int
	 */
	private const MINIMAL_CHUNK_SIZE = 2;

	/**
	 * Maximum concurrent operations
	 *
	 * @var int
	 */
	private const MAX_CONCURRENT = 5;

	/**
	 * Minimum chunk size for concurrent processing
	 *
	 * @var int
	 */
	private const MIN_CONCURRENT_CHUNK_SIZE = 5;

	/**
	 * Cache for schema properties during import operations
	 *
	 * @var array<string, array>
	 */
	private array $schemaPropertiesCache = [];

	/**
	 * Casts cell values by schema type.
	 *
	 * @var ImportValueCaster|null
	 */
	private ?ImportValueCaster $valueCaster = null;

	/**
	 * Constructor for the ImportService
	 *
	 * @param ImportServiceStores  $stores       The schema lookup, object writer, audit trail and validator.
	 * @param ImportServiceCodecs  $codecs       The translation codec and the mapping engine.
	 * @param ImportServiceRuntime $runtime      The container, the push batch and the logger.
	 * @param IGroupManager        $groupManager The group manager (the admin check).
	 */
	public function __construct(
		private readonly ImportServiceStores $stores,
		private readonly ImportServiceCodecs $codecs,
		private readonly ImportServiceRuntime $runtime,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The value caster, built on first use.
	 *
	 * @return ImportValueCaster The caster
	 */
	private function valueCaster(): ImportValueCaster {
		return $this->valueCaster ??= new ImportValueCaster();
	}//end valueCaster()

	/**
	 * Soft-delete every object whose `create` audit row carries the
	 * given import-job UUID. Implements the rollback contract added by
	 * the `data-import-export` change (decision 2026-05-02): on critical
	 * failure or explicit user request, the import-job UUID is handed
	 * back and every tagged object is soft-deleted as a unit.
	 *
	 * Out of scope (option A from the design — not shipped here):
	 * compensation pass for materialised relation rows. The objects are
	 * soft-deleted, so they remain recoverable via the standard restore
	 * path; relations on other objects pointing at the deleted ones
	 * stay live and surface as broken references on next read (the
	 * existing `referential-integrity` machinery already handles this).
	 *
	 * @param string $importJobId UUID v4 of the import job to roll back.
	 *
	 * @return array{
	 *     importJobId: string,
	 *     candidates: int,
	 *     softDeleted: list<string>,
	 *     errors: list<array{uuid: string, error: string}>
	 * }
	 *
	 * @spec openspec/specs/data-import-export/spec.md#import-must-support-rollback-on-critical-failure (rolls back an import
	 *       unit: finds every create-audited object for the import job UUID and soft-deletes them, reporting
	 *       per-object outcomes)
	 */
	public function softDeleteByImportJobId(string $importJobId): array {
		$auditRows = $this->stores->auditTrailMapper->findByImportJobId(importJobId: $importJobId, action: 'create');
		$report = [
			'importJobId' => $importJobId,
			'candidates' => count($auditRows),
			'softDeleted' => [],
			'errors' => [],
		];

		foreach ($auditRows as $row) {
			$objectUuid = $row->getObjectUuid();
			if ($objectUuid === null || $objectUuid === '') {
				continue;
			}

			try {
				$this->stores->objectService->deleteObject(deletion: new ObjectDeletion(uuid: $objectUuid));
				$report['softDeleted'][] = $objectUuid;
			} catch (\Throwable $e) {
				$report['errors'][] = [
					'uuid' => $objectUuid,
					'error' => $e->getMessage(),
				];
				$this->runtime->logger->warning(
					message: '[ImportService] Failed to soft-delete object during import rollback',
					context: [
						'importJobId' => $importJobId,
						'objectUuid' => $objectUuid,
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach

		return $report;
	}//end softDeleteByImportJobId()

	/**
	 * Check if the given user is in the admin group
	 *
	 * @param IUser|null $user The user to check (null means anonymous/no user)
	 *
	 * @return bool True if user is admin, false otherwise
	 */
	private function isUserAdmin(?IUser $user): bool {
		if ($user === null) {
			// Anonymous users are never admin.
			return false;
		}

		// Check if user is in admin group.
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			// Admin group doesn't exist.
			return false;
		}

		return $adminGroup->inGroup($user);
	}//end isUserAdmin()

	/**
	 * Import data from Excel file asynchronously.
	 *
	 * @param string $filePath The path to the Excel file.
	 * @param Register|null $register Optional register to associate with imported objects.
	 * @param Schema|null $schema Optional schema to associate with imported objects.
	 * @param int $chunkSize Number of rows to process in each chunk (default: 100).
	 *
	 * @return PromiseInterface<array<string, array>> Promise that resolves to import summary.
	 */

	/**
	 * Import data from Excel file.
	 *
	 * @param string $filePath The path to the Excel file.
	 * @param Register|null $register Optional register to associate with imported objects.
	 * @param Schema|null $schema Optional schema to associate with imported objects.
	 * @param int $chunkSize Number of rows to process in each chunk (default: 100).
	 *
	 * @return array<string, array> Summary of import with sheet-based results.
	 * @phpstan-return array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
	 * @psalm-return   array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
	 */

	/**
	 * Import data from Excel file.
	 *
	 * @param string $filePath The path to the Excel file.
	 * @param RegisterInterface|null $register Optional register to associate with imported objects.
	 * @param SchemaInterface|null $schema Optional schema to associate with imported objects.
	 * @param bool $validation Whether to validate objects against schema definitions (default: false).
	 * @param bool $events Whether to dispatch object lifecycle events (default: false).
	 * @param bool $_rbac Whether to apply RBAC checks (default: true, unused).
	 * @param bool $_multitenancy Whether to apply multitenancy checks (default: true, unused).
	 * @param bool $publish DEPRECATED: No-op. Object-level publish metadata removed; use RBAC $now rules.
	 * @param IUser|null $currentUser The current user performing the import (optional).
	 * @param bool $enrich Whether to enrich objects with metadata (default: true).
	 *
	 * @return (array|int|null|string)[][]
	 *
	 * @phpstan-return array<string, array{
	 *     found: int,
	 *     created: array<mixed>,
	 *     updated: array<mixed>,
	 *     unchanged: array<mixed>,
	 *     errors: array<mixed>,
	 *     debug?: array,
	 *     schema?: array{id: int, slug: null|string, title: null|string},
	 *     deduplication_efficiency?: string
	 * }|string>
	 *
	 * The `|string` is not slack: the result is a map of sheet title => summary,
	 * but the method also writes a scalar `importJobId` into the SAME map. A
	 * caller that iterates the result and assumes every value is a summary array
	 * will hit that key. Kept as-is because moving it would change the response
	 * shape for existing clients; the union at least makes it visible.
	 *
	 * @psalm-return array<string, array{
	 *     created: array,
	 *     errors: array,
	 *     found: int,
	 *     unchanged?: array,
	 *     updated: array,
	 *     deduplication_efficiency?: string,
	 *     schema?: array{id: int, title: null|string, slug: null|string}|null,
	 *     debug?: array{headers: array<never, never>, processableHeaders: array<never, never>,
	 *             schemaProperties: list<array-key>}
	 * }>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control import behavior options
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importFromExcel(
		string $filePath,
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		bool $validation = false,
		bool $events = false,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $publish = false,
		?IUser $currentUser = null,
		bool $enrich = true,
	): array {
		$scope   = new ImportScope(register: $register, schema: $schema, currentUser: $currentUser);
		$options = new ImportOptions(validation: $validation, events: $events, enrich: $enrich, publish: $publish);
		$request = new ImportRequest(filePath: $filePath, scope: $scope, options: $options);
		return $this->importSpreadsheet(request: $request, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end importFromExcel()

	/**
	 * Import a spreadsheet as requested; see {@see importFromExcel()}.
	 *
	 * @param ImportRequest $request       The file, scope and save options.
	 * @param bool          $_rbac         Whether to enforce RBAC checks.
	 * @param bool          $_multitenancy Whether to enable multi-tenancy.
	 *
	 * @return array The summaries by sheet title, plus `importJobId`.
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importSpreadsheet(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array {
		$import = $this->importSettings(request: $request) + ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy];

		return $this->asImportJob(run: fn (): array => $this->importExcelFile(filePath: $request->filePath, import: $import));
	}//end importSpreadsheet()

	/**
	 * The stored register and schema, and the save and mapping settings of one import.
	 *
	 * Clears the caches, so every import starts from fresh schema data.
	 *
	 * @param ImportRequest $request The import request.
	 *
	 * @return array<string, mixed> The import settings, without the guard flags.
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 */
	private function importSettings(ImportRequest $request): array {
		$register = StoredEntity::register(register: $request->scope->register);
		$schema   = StoredEntity::schema(schema: $request->scope->schema);

		// Clear caches at the start of each import to prevent stale data issues.
		$this->clearCaches();

		return [
			'register' => $register,
			'schema' => $schema,
			'validation' => $request->options->validation,
			'events' => $request->options->events,
			'publish' => $request->options->publish,
			'currentUser' => $request->scope->currentUser,
			'enrich' => $request->options->enrich,
			'pack' => $request->mapping->pack,
			'dryRun' => $request->mapping->dryRun,
			'registerSlug' => $request->scope->registerSlug,
		];
	}//end importSettings()

	/**
	 * Import a spreadsheet, one schema per sheet when only a register is given.
	 *
	 * @param string $filePath The path to the Excel file.
	 * @param array  $import   The register, schema and save flags.
	 *
	 * @return array The summaries by sheet.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importExcelFile(string $filePath, array $import): array {
		$reader = new Xlsx();
		$reader->setReadDataOnly(true);
		$spreadsheet = $reader->load($filePath);

		if ($import['register'] !== null && $import['schema'] === null) {
			return $this->processMultiSchemaSpreadsheetAsync(spreadsheet: $spreadsheet, import: $import);
		}

		$sheetTitle = $spreadsheet->getActiveSheet()->getTitle();
		$sheetSummary = $this->processSpreadsheetBatch(spreadsheet: $spreadsheet, import: $import);
		if ($import['schema'] !== null) {
			$sheetSummary['schema'] = self::schemaSummary(schema: $import['schema']);
		}

		return [$sheetTitle => $sheetSummary];
	}//end importExcelFile()

	/**
	 * Run an import as one job: every audit row it writes carries the job id,
	 * which softDeleteByImportJobId() rolls the import back by.
	 *
	 * The id is cleared on a throw too, because the mapper outlives the request
	 * on a long-lived worker.
	 *
	 * @param Closure(): array $run The import.
	 *
	 * @return array The import's result, with `importJobId`.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function asImportJob(Closure $run): array {
		$importJobId = Uuid::v4()->toRfc4122();

		try {
			$this->stores->auditTrailMapper->setRequestImportJobId(importJobId: $importJobId);

			$result = $run();
			$result['importJobId'] = $importJobId;

			return $result;
		} finally {
			$this->stores->auditTrailMapper->setRequestImportJobId(importJobId: null);
		}
	}//end asImportJob()

	/**
	 * The schema as an import summary names it.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{id: int, title: string|null, slug: string|null}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function schemaSummary(Schema $schema): array {
		return ['id' => $schema->getId(), 'title' => $schema->getTitle(), 'slug' => $schema->getSlug()];
	}//end schemaSummary()

	/**
	 * Import data from CSV file.
	 *
	 * @param string $filePath The path to the CSV file.
	 * @param RegisterInterface|null $register Optional register to associate with imported objects.
	 * @param SchemaInterface|null $schema Optional schema to associate with imported objects.
	 * @param bool $validation Whether to validate objects against schema definitions (default: false).
	 * @param bool $events Whether to dispatch object lifecycle events (default: false).
	 * @param bool $_rbac Whether to enforce RBAC checks (default: true, unused).
	 * @param bool $_multitenancy Whether to enable multi-tenancy (default: true, unused).
	 * @param bool $publish DEPRECATED: No-op. Object-level publish metadata removed; use RBAC $now rules.
	 * @param IUser|null $currentUser Current user for RBAC checks (default: null).
	 * @param bool $enrich Whether to enrich objects with metadata (default: true).
	 * @param array|null $pack Optional migration pack definition (decoded JSON). When given, each row is
	 *                         mapped through `MappingEngine::mapRow()` before the normal validate/save
	 *                         pipeline.
	 * @param bool $dryRun When true, rows are mapped and validated but nothing is saved.
	 *
	 * @return array Import results by schema
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags control import behavior options
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the existing importFrom* signatures; pack/dryRun extend it.
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function importFromCsv(
		string $filePath,
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		bool $validation = false,
		bool $events = false,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $publish = false,
		?IUser $currentUser = null,
		bool $enrich = true,
		?array $pack = null,
		bool $dryRun = false,
	): array {
		$scope   = new ImportScope(register: $register, schema: $schema, currentUser: $currentUser);
		$options = new ImportOptions(validation: $validation, events: $events, enrich: $enrich, publish: $publish);
		$request = new ImportRequest(filePath: $filePath, scope: $scope, options: $options, mapping: new ImportMapping(pack: $pack, dryRun: $dryRun));
		return $this->importCsv(request: $request, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end importFromCsv()

	/**
	 * Import a CSV file as requested; see {@see importFromCsv()}.
	 *
	 * @param ImportRequest $request       The file, scope, save options and mapping.
	 * @param bool          $_rbac         Whether to enforce RBAC checks.
	 * @param bool          $_multitenancy Whether to enable multi-tenancy.
	 *
	 * @return array The summary by sheet title, plus `importJobId`.
	 *
	 * @throws \InvalidArgumentException When no schema is given, or the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function importCsv(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array {
		$import = $this->importSettings(request: $request) + ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy];

		// CSV can only handle a single schema.
		if ($import['schema'] === null) {
			throw new InvalidArgumentException('CSV import requires a specific schema');
		}

		return $this->asImportJob(run: fn (): array => $this->importCsvFile(filePath: $request->filePath, import: $import));
	}//end importCsv()

	/**
	 * Import a CSV file into one schema.
	 *
	 * @param string $filePath The path to the CSV file.
	 * @param array  $import   The register, schema and save flags.
	 *
	 * @return array The summary by sheet title.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importCsvFile(string $filePath, array $import): array {
		$reader = new Csv();
		$reader->setReadDataOnly(true);
		$reader->setDelimiter(',');
		$reader->setEnclosure('"');
		$sheet = $reader->load($filePath)->getActiveSheet();

		$sheetSummary = $this->processCsvSheet(sheet: $sheet, import: $import);
		$sheetSummary['schema'] = self::schemaSummary(schema: $import['schema']);

		return [$sheet->getTitle() => $sheetSummary];
	}//end importCsvFile()

	/**
	 * Import objects of a single schema from a JSON document
	 *
	 * Inverse of `ExportService::exportToJson()`. Accepts either a bare JSON
	 * array of objects or a `{ "results": [...] }` envelope, then upserts every
	 * object (by uuid) through `ObjectService::saveObject()` — the same
	 * single-object path the REST create/update uses, applying RBAC and
	 * multi-tenancy. JSON carries no spreadsheet, so no PhpSpreadsheet (and
	 * therefore no ZipStream) is involved.
	 *
	 * When `$register` is null (the caller could not resolve the target
	 * register by slug), the payload is inspected for a register-bundle
	 * envelope — the same `components.registers` + `components.schemas` shape
	 * `ConfigurationService::importFromApp()` and the Repair-step register
	 * importers understand. A bundle auto-creates its register and schemas
	 * (reusing that same creation path — no duplicated logic, ADR-011) before
	 * objects import into it; idempotent — re-importing an existing register's
	 * bundle skips creation (registers/schemas are looked up by slug first).
	 * A non-bundle payload with no resolvable register fails with a clear,
	 * actionable error naming the missing slug rather than a silent no-op or
	 * an opaque exception.
	 *
	 * @param string $filePath Path to the uploaded JSON file
	 * @param RegisterInterface|null $register Register to import into. When null, the payload is checked for
	 *                                a register-bundle envelope to auto-create it; see
	 *                                `$registerSlug` and `@throws` below.
	 * @param SchemaInterface|null $schema Target schema (required — JSON import is single-schema, like
	 *                            CSV — unless resolved from a register-bundle's own schemas)
	 * @param bool $validation Whether to validate objects against the schema
	 * @param bool $events Whether to dispatch object lifecycle events
	 * @param bool $_rbac Whether to apply RBAC permissions
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering
	 * @param bool $publish DEPRECATED no-op; publication is RBAC-driven
	 * @param IUser|null $currentUser The current user performing the import
	 * @param bool $enrich Whether to enrich objects with metadata
	 * @param array|null $pack Optional migration pack definition (decoded JSON). When given, each JSON
	 *                         object is mapped through `MappingEngine::mapRow()` (source resolved via
	 *                         JSON-Pointer-style paths against the raw decoded object) before save.
	 * @param bool $dryRun When true, rows are mapped and validated but nothing is saved.
	 * @param string|null $registerSlug The register slug/id the caller originally requested, used ONLY to
	 *                                  name the missing register in the clear-failure error when `$register`
	 *                                  is null and the payload is not a creatable bundle (REQ-IMP-AC-02).
	 *
	 * @return array<string, mixed> Sheet-shaped summary (keyed 'JSON') plus importJobId
	 *
	 * @throws InvalidArgumentException When no schema is given (and none is resolvable from a bundle) or the
	 *                                  payload is not an array of objects
	 * @throws RegisterNotFoundException When `$register` is null and the payload is not a creatable
	 *                                   register-bundle (REQ-IMP-AC-02)
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags control import behavior options
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the Excel/CSV importer signatures
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)  validation/events/enrich are kept for signature parity with importFromExcel/importFromCsv
	 *
	 * @spec exclude Retrofit — JSON object import/export added alongside the existing Excel/CSV importers; no dedicated openspec change.
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-auto-create-a-missing-register-from-a-bundle-req-imp-ac-01
	 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-clear-failure-when-auto-create-is-impossible-req-imp-ac-02
	 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-idempotent-register-bundle-re-import-req-imp-ac-03
	 */
	public function importFromJson(
		string $filePath,
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		bool $validation = false,
		bool $events = false,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $publish = false,
		?IUser $currentUser = null,
		bool $enrich = true,
		?array $pack = null,
		bool $dryRun = false,
		?string $registerSlug = null,
	): array {
		$scope   = new ImportScope(register: $register, schema: $schema, currentUser: $currentUser, registerSlug: $registerSlug);
		$options = new ImportOptions(validation: $validation, events: $events, enrich: $enrich, publish: $publish);
		$request = new ImportRequest(filePath: $filePath, scope: $scope, options: $options, mapping: new ImportMapping(pack: $pack, dryRun: $dryRun));
		return $this->importJson(request: $request, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end importFromJson()

	/**
	 * Import a JSON document as requested; see {@see importFromJson()}.
	 *
	 * @param ImportRequest $request       The file, scope, save options and mapping.
	 * @param bool          $_rbac         Whether to apply RBAC permissions.
	 * @param bool          $_multitenancy Whether to apply multi-tenancy filtering.
	 *
	 * @return array<string, mixed> Sheet-shaped summary (keyed 'JSON') plus importJobId
	 *
	 * @throws InvalidArgumentException  When no schema is given (and none is resolvable from a bundle) or the
	 *                                   payload is not an array of objects
	 * @throws RegisterNotFoundException When no register is given and the payload is not a creatable register-bundle
	 *
	 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-auto-create-a-missing-register-from-a-bundle-req-imp-ac-01
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function importJson(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array {
		$job = $this->importSettings(request: $request) + ['startTime' => microtime(true), '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
		$this->warnIfPublishing(publish: $job['publish']);

		$decoded = $this->decodeJsonImportFile(filePath: $request->filePath);
		$job     = $this->withJsonImportTarget(decoded: $decoded, job: $job);

		return $this->runJsonImportJob(objects: $this->jsonImportObjects(decoded: $decoded), job: $job);
	}//end importJson()

	/**
	 * The JSON import job with its register and schema settled.
	 *
	 * A null register could not be resolved by slug: a register bundle
	 * auto-creates it with its schemas (REQ-IMP-AC-01/03), anything else
	 * fails clearly, naming the missing slug (REQ-IMP-AC-02).
	 *
	 * @param array<mixed>         $decoded The decoded document.
	 * @param array<string, mixed> $job     The import job.
	 *
	 * @return array<string, mixed> The job, with a register and a schema.
	 *
	 * @throws InvalidArgumentException  When no schema is given and none is resolvable from a bundle
	 * @throws RegisterNotFoundException When no register is given and the payload is not a creatable register-bundle
	 */
	private function withJsonImportTarget(array $decoded, array $job): array {
		if ($job['register'] === null) {
			[$job['register'], $job['schema']] = $this->registerFromBundle(
				decoded: $decoded,
				target: ['schema' => $job['schema'], 'registerSlug' => $job['registerSlug']]
			);
		}

		if ($job['schema'] === null) {
			throw new InvalidArgumentException('JSON import requires a specific schema');
		}

		return $job;
	}//end withJsonImportTarget()

	/**
	 * Log that the deprecated publish flag was set; it changes nothing.
	 *
	 * @param bool $publish The deprecated publish flag.
	 *
	 * @return void
	 */
	private function warnIfPublishing(bool $publish): void {
		if ($publish === true) {
			$this->runtime->logger->warning(
				message: '[ImportService] The $publish parameter is deprecated. Use RBAC $now rules instead.',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end warnIfPublishing()

	/**
	 * Run a JSON import as one import job, tagging its audit rows with the job id.
	 *
	 * @param array<mixed>         $objects The objects to import
	 * @param array<string, mixed> $job     The register, schema, RBAC, multitenancy, user, pack, dryRun and startTime
	 *
	 * @return array<string, mixed> Sheet-shaped summary (keyed 'JSON') plus importJobId
	 */
	private function runJsonImportJob(array $objects, array $job): array {
		return $this->asImportJob(run: fn (): array => ['JSON' => $this->importJsonObjects(objects: $objects, job: $job)]);
	}//end runJsonImportJob()

	/**
	 * Resolve every JSON import row, then validate (dry run) or save them.
	 *
	 * @param array<mixed>         $objects The objects to import
	 * @param array<string, mixed> $job     The register, schema, RBAC, multitenancy, user, pack, dryRun and startTime
	 *
	 * @return array<string, mixed> The summary
	 */
	private function importJsonObjects(array $objects, array $job): array {
		$schema = $job['schema'];
		$rows = new JsonImportRows(
			mappingEngine: $this->codecs->mappingEngine,
			pack: $job['pack'],
			propertyKeys: array_flip(array_keys($schema->getProperties()))
		);

		$summary = $this->applyJsonImportRows(resolution: $rows->resolve(objects: $objects), job: $job);
		$summary['schema'] = self::schemaSummary(schema: $schema);

		return $summary;
	}//end importJsonObjects()

	/**
	 * Save resolved rows; a dry run validates them and saves nothing.
	 *
	 * @param array{rows: list<array<string, mixed>>, summary: array<string, mixed>} $resolution The resolved rows.
	 * @param array<string, mixed>                                                   $job        The import job.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function applyJsonImportRows(array $resolution, array $job): array {
		if ($job['dryRun'] === true) {
			return $this->buildDryRunSummary(
				summary: $resolution['summary'],
				batch: new DryRunBatch(objects: array_column($resolution['rows'], 'body'), schema: $job['schema'], startTime: $job['startTime'])
			);
		}

		return $this->saveJsonImportRows(rows: $resolution['rows'], target: ['summary' => $resolution['summary']] + $job);
	}//end applyJsonImportRows()

	/**
	 * Read and decode a JSON import file.
	 *
	 * @param string $filePath Path to the JSON file
	 *
	 * @return array<mixed> The decoded document
	 *
	 * @throws InvalidArgumentException When the file does not hold a JSON array or object
	 */
	private function decodeJsonImportFile(string $filePath): array {
		$raw = file_get_contents($filePath);
		$decoded = null;
		if ($raw !== false) {
			$decoded = json_decode($raw, true);
		}

		if (is_array($decoded) === false) {
			throw new InvalidArgumentException('JSON import expects an array of objects');
		}

		return $decoded;
	}//end decodeJsonImportFile()

	/**
	 * Resolve the register (and schema) of a JSON import from a register bundle.
	 *
	 * A bundle is an object (not a list) carrying a non-empty `components.registers`,
	 * the shape the configuration import understands.
	 *
	 * @param array<mixed>         $decoded The decoded document
	 * @param array<string, mixed> $target  The schema hint and the register slug the caller asked for
	 *
	 * @return array{0: Register, 1: ?Schema} The register and the schema
	 *
	 * @throws RegisterNotFoundException When the document is not a register bundle
	 */
	private function registerFromBundle(array $decoded, array $target): array {
		if ($this->isRegisterBundle(decoded: $decoded) === false) {
			throw new RegisterNotFoundException(
				registerSlugOrId: $target['registerSlug'] ?? 'unknown',
				code: 404,
				remedies: 'Create the register first, or supply a full register bundle '
					. '(a register definition together with its schemas) to auto-create it.'
			);
		}

		return $this->autoCreateRegisterFromBundle(bundle: $decoded, schemaHint: $target['schema']);
	}//end registerFromBundle()

	/**
	 * Whether a decoded JSON document is a register bundle: an object (not a
	 * list) carrying a non-empty `components.registers` array.
	 *
	 * @param array<mixed> $decoded The decoded document
	 *
	 * @return bool True when the document is a register bundle
	 */
	private function isRegisterBundle(array $decoded): bool {
		$registers = ($decoded['components']['registers'] ?? null);
		return array_is_list($decoded) === false
			&& is_array($registers) === true
			&& count($registers) > 0;
	}//end isRegisterBundle()

	/**
	 * The object list of a JSON import document.
	 *
	 * Accepts a bare list, a `{ "results": [...] }` envelope, or a register
	 * bundle's own `components.objects` list.
	 *
	 * @param array<mixed> $decoded The decoded document
	 *
	 * @return array<mixed> The objects to import
	 */
	private function jsonImportObjects(array $decoded): array {
		if (isset($decoded['components']['objects']) === true && is_array($decoded['components']['objects']) === true) {
			return $decoded['components']['objects'];
		}

		if (isset($decoded['results']) === true && is_array($decoded['results']) === true) {
			return $decoded['results'];
		}

		return $decoded;
	}//end jsonImportObjects()

	/**
	 * Save every resolved JSON import row and record the outcome in the summary.
	 *
	 * Rows go through the single-object saveObject() path the REST create/update
	 * uses, which upserts by uuid; the bulk path silently skips dedicated-table
	 * schemas. A failing row is reported and the import goes on.
	 *
	 * @param list<array<string, mixed>> $rows   The resolved rows
	 * @param array<string, mixed>       $target The summary, register, schema, RBAC, multitenancy and user
	 *
	 * @return array<string, mixed> The summary with created, updated and errors filled in
	 */
	private function saveJsonImportRows(array $rows, array $target): array {
		$summary = $target['summary'];
		foreach ($rows as $row) {
			try {
				$outcome = 'created';
				if ($row['uuid'] !== null) {
					$outcome = 'updated';
				}

				$summary[$outcome][] = $this->saveJsonImportRow(row: $row, target: $target)->getUuid();
			} catch (\Throwable $e) {
				$summary['errors'][] = ['object' => $row['name'], 'error' => $e->getMessage(), 'type' => get_class($e)];
			}
		}

		return $summary;
	}//end saveJsonImportRows()

	/**
	 * Save one resolved row.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $target The register, schema and access flags of the import.
	 *
	 * @return ObjectEntity The saved object.
	 */
	private function saveJsonImportRow(array $row, array $target): ObjectEntity {
		return $this->stores->objectService->saveObject(
			object: $row['body'],
			register: $target['register'],
			schema: $target['schema'],
			uuid: $row['uuid'],
			_rbac: $target['_rbac'],
			_multitenancy: $target['_multitenancy'],
			currentUser: $target['currentUser']
		);
	}//end saveJsonImportRow()

	/**
	 * Resolve or auto-create a register (and its schemas) from a register-bundle
	 * envelope, for a JSON import whose target register could not be resolved.
	 *
	 * Reuses `ConfigurationImportHandler::importRegister()` and `::importSchema()`
	 * — the SAME register/schema creation path `ConfigurationService::importFromApp()`
	 * and the Repair-step register importers already use — instead of duplicating
	 * creation logic (ADR-011). Both methods look the target up by slug first, so
	 * re-importing a bundle whose register already exists skips creation and is a
	 * no-op here (idempotent — REQ-IMP-AC-03); the existing schema/object upsert
	 * then proceeds unchanged.
	 *
	 * @param array<string, mixed> $bundle Decoded register-bundle envelope — MUST carry
	 *                                     `components.registers` (validated by the caller).
	 * @param Schema|null $schemaHint A schema the caller already resolved explicitly; when
	 *                                given, it is returned as-is (bundle schemas are still
	 *                                created/updated) rather than re-derived from the bundle.
	 *
	 * @return array{0: Register, 1: ?Schema} The resolved/created register and, when resolvable
	 *                                        (either `$schemaHint` or the bundle's single referenced
	 *                                        schema — this importer is single-schema, like CSV), the schema.
	 *
	 * @throws InvalidArgumentException When the bundle's register references anything other than
	 *                                  exactly one schema and no `$schemaHint` was given — this
	 *                                  importer cannot disambiguate which schema the objects belong to.
	 *
	 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-auto-create-a-missing-register-from-a-bundle-req-imp-ac-01
	 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-idempotent-register-bundle-re-import-req-imp-ac-03
	 */
	private function autoCreateRegisterFromBundle(array $bundle, ?Schema $schemaHint): array {
		// Lazily resolved via the container to avoid a circular dependency,
		// mirroring ConfigurationService::getImportHandler().
		$importHandler = $this->runtime->container->get(ConfigurationImportHandler::class);
		assert($importHandler instanceof ConfigurationImportHandler);

		$registersData = $bundle['components']['registers'];
		$schemasData = $bundle['components']['schemas'] ?? [];

		// The envelope this method understands describes a single register per
		// import (mirrors ExportHandler::exportConfig() for one register) —
		// take its (only) entry.
		$registerKey = array_key_first($registersData);
		$registerData = $registersData[$registerKey];

		$slugsAndIdsMap = $this->stores->schemaMapper->getSlugToIdMap();

		$createdSchemas = $this->importBundleSchemas(
			importHandler: $importHandler,
			schemas: ['data' => $schemasData, 'slugsAndIds' => $slugsAndIdsMap]
		);

		$register = $importHandler->importRegister(data: $registerData);

		return [$register, ($schemaHint ?? self::onlyBundleSchema(createdSchemas: $createdSchemas))];
	}//end autoCreateRegisterFromBundle()

	/**
	 * Create the schemas of a register bundle; an untitled schema takes its key as title.
	 *
	 * @param ConfigurationImportHandler $importHandler The configuration import handler.
	 * @param array                      $schemas       `data`: the bundle's schemas, `slugsAndIds`: the known slugs.
	 *
	 * @return array<string, Schema> The created schemas, by slug.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importBundleSchemas(ConfigurationImportHandler $importHandler, array $schemas): array {
		$createdSchemas = [];
		foreach ($schemas['data'] as $schemaKey => $schemaData) {
			if (isset($schemaData['title']) === false && is_string($schemaKey) === true) {
				$schemaData['title'] = $schemaKey;
			}

			$createdSchema = $importHandler->importSchema(
				definition: new ImportedSchemaLookup(data: $schemaData),
				slugsAndIdsMap: $schemas['slugsAndIds']
			);
			$createdSchemas[$createdSchema->getSlug()] = $createdSchema;
		}

		return $createdSchemas;
	}//end importBundleSchemas()

	/**
	 * The one schema of a bundle, for an import that names none.
	 *
	 * @param array<string, Schema> $createdSchemas The bundle's schemas.
	 *
	 * @return Schema The schema.
	 *
	 * @throws InvalidArgumentException When the bundle has not exactly one schema.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function onlyBundleSchema(array $createdSchemas): Schema {
		if (count($createdSchemas) === 1) {
			return array_values($createdSchemas)[0];
		}

		$msg = 'Register bundle import requires exactly one schema when no schema is ';
		$msg .= 'explicitly specified; found ' . count($createdSchemas) . ' in the bundle.';
		throw new InvalidArgumentException($msg);
	}//end onlyBundleSchema()

	/**
	 * Process spreadsheet with multiple schemas using batch saving for better performance
	 *
	 * @param Spreadsheet $spreadsheet The spreadsheet to process
	 * @param array       $import      The register (required here), the save flags, the deprecated
	 *                                 no-op publish and currentUser; each sheet sets its own schema
	 *
	 * @return array<string, array> Summary of import with sheet-based results
	 * @phpstan-return array<string, array{
	 *     found: int,
	 *     created: array<mixed>,
	 *     updated: array<mixed>,
	 *     unchanged: array<mixed>,
	 *     errors: array<mixed>,
	 *     schema?: array{id: int, slug: null|string, title: null|string},
	 *     debug?: array,
	 *     deduplication_efficiency?: string
	 * }>
	 * @psalm-return   array<string, array{
	 *     created: array<array-key, mixed>,
	 *     errors: array<array-key, mixed>,
	 *     found: int,
	 *     unchanged?: array<array-key, mixed>,
	 *     updated: array<array-key, mixed>,
	 *     debug: array{
	 *         headers: array<never, never>,
	 *         processableHeaders: array<never, never>,
	 *         schemaProperties: list<array-key>
	 *     },
	 *     deduplication_efficiency?: non-empty-lowercase-string,
	 *     schema: array{id: int, slug: null|string, title: null|string}|null
	 * }>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function processMultiSchemaSpreadsheetAsync(Spreadsheet $spreadsheet, array $import): array {
		$register = $import['register'];
		$summary = [];

		foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
			$schemaSlug = $worksheet->getTitle();
			$schema = $this->getSchemaBySlug(slug: $schemaSlug);

			// Initialize sheet summary even if no schema found.
			$summary[$schemaSlug] = [
				'found' => 0,
				'created' => [],
				'updated' => [],
				// TODO: Renamed from 'skipped' - more descriptive (objects skipped because content was unchanged).
				'errors' => [],
				'schema' => null,
				'debug' => [
					'headers' => [],
					'schemaProperties' => [],
					'processableHeaders' => [],
				],
			];

			// Skip sheets that don't correspond to a valid schema.
			// Note: getSchemaBySlug() returns Schema (non-nullable) or throws exception.
			try {
				$schema = $this->getSchemaBySlug(slug: $schemaSlug);
				// Schema is guaranteed to be non-null if we reach here (exception thrown otherwise).
				// Add schema information to the summary.
				$summary[$schemaSlug]['schema'] = self::schemaSummary(schema: $schema);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				$summary[$schemaSlug]['errors'][] = [
					'sheet' => $schemaSlug,
					'register' => [
						'id' => $register->getId(),
						'name' => $register->getTitle(),
					],
					'schema' => null,
					'error' => 'No matching schema found for sheet: ' . $schemaSlug,
					'type' => 'SchemaNotFoundException',
				];
				continue;
			}//end try

			// Update debug information with schema properties.
			$schemaProperties = $schema->getProperties();
			$propertyKeys = array_keys($schemaProperties);
			$summary[$schemaSlug]['debug']['schemaProperties'] = $propertyKeys;

			// Set the worksheet as active and process using batch saving for better performance.
			$spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($worksheet));
			$import['schema'] = $schema;
			$sheetSummary = $this->processSpreadsheetBatch(spreadsheet: $spreadsheet, import: $import);

			// Merge the sheet summary with the existing summary (preserve debug info).
			$summary[$schemaSlug] = array_merge($summary[$schemaSlug], $sheetSummary);
		}//end foreach

		return $summary;
	}//end processMultiSchemaSpreadsheetAsync()

	/**
	 * Process spreadsheet with single schema using batch saving for better performance
	 *
	 * @param Spreadsheet $spreadsheet The spreadsheet to process
	 * @param Register|null $register Optional register to associate with imported objects
	 * @param Schema|null $schema Optional schema to associate with imported objects
	 * @param int $chunkSize Number of rows to process in each chunk
	 *
	 * @return array<string, array> Summary of import with sheet-based results
	 * @phpstan-return array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
	 * @psalm-return   array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
	 */

	/**
	 * Flush accumulated notify_push collection events after a bulk import.
	 *
	 * Lazily resolves notify_push's IQueue from the container and emits one
	 * broadcast `or-collection-{register-slug}-{schema-slug}` event per
	 * (register, schema) pair accumulated while batch mode was active.
	 *
	 * Soft-fails: when notify_push is not installed (IQueue not resolvable)
	 * this is a silent no-op — nothing was accumulated in that case, so we
	 * return before even touching the container. A resolution failure with
	 * pending events (partial install / config drift) logs at most one
	 * DEBUG entry and never interrupts the import.
	 *
	 * MUST be called before setBatchMode(false), which clears the accumulator.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	private function flushNotifyPushBatch(): void {
		if ($this->runtime->pushBatch->hasBatchedCollections() === false) {
			return;
		}

		try {
			$queue = $this->runtime->container->get('OCA\NotifyPush\Queue\IQueue');
		} catch (\Throwable $e) {
			// Notify_push unavailable — soft-fail with a single DEBUG log.
			$this->runtime->logger->debug(
				message: '[ImportService] notify_push IQueue not available; skipping batch flush',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return;
		}

		$this->runtime->pushBatch->flushBatch(queue: $queue);

	}//end flushNotifyPushBatch()

	/**
	 * Queue a notify_push collection hint derived from the import's own context.
	 *
	 * Bulk saves run with lifecycle events DISABLED by default (`events=false`
	 * everywhere in the import call chain), so NotifyPushListener::handle()
	 * never fires and the batch accumulator would stay empty. The import knows
	 * exactly which (register, schema) collection it just changed — queue the
	 * pair directly from the entities' slugs. Deduplicated with any
	 * event-driven accumulation when events ARE enabled. Soft-fails on any
	 * slug-resolution error (a missed hint must never break the import).
	 *
	 * @param RegisterSchema $table The register and schema the import saved into.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	private function queueNotifyPushCollectionHint(RegisterSchema $table): void {
		try {
			$registerSlug = (string)($table->register->getSlug() ?? '');
			$schemaSlug = (string)($table->schema->getSlug() ?? '');
			$this->runtime->pushBatch->addBatchedCollection(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
		} catch (\Throwable $e) {
			// Slug not resolvable — skip the hint, never break the import.
			$this->runtime->logger->debug(
				message: '[ImportService] Could not queue notify_push collection hint',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}

	}//end queueNotifyPushCollectionHint()

	/**
	 * Process a single spreadsheet sheet using batch saving for better performance
	 *
	 * @param Spreadsheet $spreadsheet The spreadsheet to process; its active sheet is read
	 * @param array       $import      The register and schema (either may be null), the save flags
	 *                                 (validation, events, _rbac, _multitenancy, enrich), the
	 *                                 deprecated no-op publish and currentUser
	 *
	 * @return array Batch processing results
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function processSpreadsheetBatch(Spreadsheet $spreadsheet, array $import): array {
		$sheet = $spreadsheet->getActiveSheet();
		$summary = [
			'found' => 0,
			'created' => [],
			'updated' => [],
			'unchanged' => [],
			'errors' => [],
		];

		$mapped = new MappedSheet(sheet: $sheet, columnMapping: $this->buildColumnMapping(sheet: $sheet));
		$sheetError = $this->spreadsheetSheetError(sheet: $sheet, columnMapping: $mapped->columnMapping);
		if ($sheetError !== null) {
			$summary['errors'][] = ['sheet' => $sheet->getTitle(), 'row' => 1, 'object' => [], 'error' => $sheetError];
			return $summary;
		}

		// Row errors are not suppressed: they bubble up.
		$allObjects = $this->spreadsheetRowObjects(
			sheet: $mapped,
			target: new ImportTarget(register: $import['register'], schema: $import['schema'], currentUser: $import['currentUser'])
		);
		$summary['found'] = count($allObjects);

		if (empty($allObjects) === false && $import['register'] !== null && $import['schema'] !== null) {
			$summary = $this->saveImportedObjects(
				objects: $allObjects,
				import: $import + ['summary' => $summary, 'sheetTitle' => $sheet->getTitle()]
			);
		}

		return $summary;
	}//end processSpreadsheetBatch()

	/**
	 * Why a sheet has nothing to import: no usable headers, or no rows below them.
	 *
	 * @param Worksheet $sheet         The sheet
	 * @param array     $columnMapping The column mapping built from its headers
	 *
	 * @return string|null The error for the summary, or null when the sheet has data rows
	 */
	private function spreadsheetSheetError(Worksheet $sheet, array $columnMapping): ?string {
		if (empty($columnMapping) === true) {
			return 'No valid headers found in sheet';
		}

		if ($sheet->getHighestRow() <= 1) {
			return 'No data rows found in sheet';
		}

		return null;
	}//end spreadsheetSheetError()

	/**
	 * Parse every non-empty data row of a sheet into an object.
	 *
	 * @param MappedSheet  $sheet  The sheet and its column mapping
	 * @param ImportTarget $target The register, schema and importing user
	 *
	 * @return list<array> The parsed objects
	 */
	private function spreadsheetRowObjects(MappedSheet $sheet, ImportTarget $target): array {
		$objects = [];
		$highestRow = $sheet->sheet->getHighestRow();
		for ($row = 2; $row <= $highestRow; $row++) {
			$rowData = $this->extractRowData(sheet: $sheet, row: $row);
			if (empty($rowData) === true) {
				continue;
			}

			$object = $this->transformExcelRowToObject(rowData: $rowData, target: $target);
			if ($object !== null) {
				$objects[] = $object;
			}
		}

		return $objects;
	}//end spreadsheetRowObjects()

	/**
	 * Process CSV sheet and import all objects in batches
	 *
	 * @param Worksheet $sheet  The worksheet to process
	 * @param array     $import The register, schema, save flags (validation,
	 *                          events, _rbac, _multitenancy, enrich), the
	 *                          deprecated no-op publish, currentUser, the
	 *                          optional migration pack and dryRun
	 *
	 * @return array CSV sheet processing results
	 *
	 * @throws InvalidArgumentException When no register is given
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	private function processCsvSheet(Worksheet $sheet, array $import): array {
		if (($import['register'] instanceof Register) === false) {
			throw new InvalidArgumentException('CSV import requires a register');
		}

		$summary = [
			'found' => 0,
			'created' => [],
			'updated' => [],
			'unchanged' => [],
			'errors' => [],
		];
		$startTime = microtime(true);

		$mapped = new MappedSheet(sheet: $sheet, columnMapping: $this->buildColumnMapping(sheet: $sheet));
		if (empty($mapped->columnMapping) === true) {
			$summary['errors'][] = ['row' => 1, 'object' => [], 'error' => 'No valid headers found in CSV file'];
			return $summary;
		}

		if ($sheet->getHighestRow() <= 1) {
			$summary['errors'][] = ['row' => 1, 'object' => [], 'error' => 'No data rows found in CSV file'];
			return $summary;
		}

		$parsed = $this->parseCsvRows(sheet: $mapped, parse: $import);
		$summary['errors'] = $parsed['errors'];
		$summary['found'] = count($parsed['objects']);

		// Dry-run: map + validate every row, save NOTHING, so an operator can see
		// exactly what would happen before committing to a real import.
		// ValidateObject::validateObject() only issues read-only lookups, and no
		// save is called on this path.
		if ($import['dryRun'] === true) {
			return $this->buildDryRunSummary(
				summary: $summary,
				batch: new DryRunBatch(objects: $parsed['objects'], schema: $import['schema'], startTime: $startTime)
			);
		}

		if (empty($parsed['objects']) === false) {
			$summary = $this->saveCsvObjects(objects: $parsed['objects'], import: $import + ['summary' => $summary]);
		}

		$summary['performance'] = $this->importPerformance(summary: $summary, startTime: $startTime);
		return $summary;
	}//end processCsvSheet()

	/**
	 * Parse every data row of a CSV sheet into an object, through the migration pack when one is given.
	 *
	 * A row the pack skips or that is empty is left out. A row with mapping errors
	 * (missing required source, or an unresolved lookup/reference) is reported and
	 * excluded, never partially mapped.
	 *
	 * @param MappedSheet $sheet The worksheet to parse, with its column mapping
	 * @param array       $parse The import options
	 *
	 * @return array{objects: list<array>, errors: list<array>} The objects and the mapping errors
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	private function parseCsvRows(MappedSheet $sheet, array $parse): array {
		$parsed = ['objects' => [], 'errors' => []];
		$table = new RegisterSchema(register: $parse['register'], schema: $parse['schema']);
		$highestRow = $sheet->sheet->getHighestRow();
		for ($row = 2; $row <= $highestRow; $row++) {
			if ($this->isMappedOut(pack: $parse['pack'], row: $row) === true) {
				continue;
			}

			$rowData = $this->extractRowData(sheet: $sheet, row: $row);
			if (empty($rowData) === false) {
				$this->parseCsvRow(parsed: $parsed, line: ['parse' => $parse, 'table' => $table, 'row' => $row, 'data' => $rowData]);
			}
		}

		return $parsed;
	}//end parseCsvRows()

	/**
	 * Whether a mapping pack skips a row.
	 *
	 * @param array|null $pack The mapping pack, if any.
	 * @param int        $row  The row number.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function isMappedOut(?array $pack, int $row): bool {
		return $pack !== null && $this->codecs->mappingEngine->isRowSkipped(pack: $pack, rowNumber: $row) === true;
	}//end isMappedOut()

	/**
	 * Add one row to the parse result: its mapping errors, or its object.
	 *
	 * @param array $parsed The parse result, changed in place.
	 * @param array $line   `parse`: the parse settings, `table`: the register and schema, `row`: the row number, `data`: the row data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function parseCsvRow(array &$parsed, array $line): void {
		$rowData = $line['data'];
		$parse = $line['parse'];
		if ($parse['pack'] !== null) {
			$mapped = $this->codecs->mappingEngine->mapRow(pack: $parse['pack'], sourceRow: $rowData, rowNumber: $line['row']);
			if (empty($mapped['errors']) === false) {
				foreach ($mapped['errors'] as $mappingError) {
					$parsed['errors'][] = (new MappingErrorRow())->format(error: $mappingError);
				}

				return;
			}

			$rowData = $mapped['data'];
		}

		$target = new RowImportTarget(table: $line['table'], importer: $parse['currentUser']);
		$parsed['objects'][] = $this->transformCsvRow(rowData: $rowData, target: $target);
	}//end parseCsvRow()

	/**
	 * Save the parsed CSV objects in one bulk save and fill the summary from its result.
	 *
	 * Deduplication is handled by SaveObjects::saveObjects() (deduplicateIds=true
	 * by default), the same for every bulk save. The publish flag is a deprecated
	 * no-op: publication control is handled via RBAC authorization rules.
	 *
	 * @param list<array> $objects The parsed objects
	 * @param array       $import  The import options with the summary so far
	 *
	 * @return array The summary with created, updated, unchanged and validation errors
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function saveCsvObjects(array $objects, array $import): array {
		$this->runtime->logger->debug(
			message: '[ImportService] CSV import processing objects',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'objectCount' => count($objects),
				'publish' => $import['publish'],
			]
		);

		return $this->saveImportedObjects(objects: $objects, import: ['sheetTitle' => null] + $import);
	}//end saveCsvObjects()

	/**
	 * Bulk-save parsed objects and fill the summary from the result. Every row the save
	 * refused is reported, whatever the validation flag says, with the sheet title first
	 * when `$import['sheetTitle']` is set.
	 *
	 * @param list<array> $objects The parsed objects
	 * @param array       $import  The import options with the summary so far and the sheetTitle (null for CSV)
	 *
	 * @return array The summary with created, updated, unchanged and validation errors
	 */
	private function saveImportedObjects(array $objects, array $import): array {
		$this->warnIfPublishing(publish: $import['publish']);

		$saveResult = $this->bulkSaveWithNotifyPush(objects: $objects, import: $import);
		$summary = $this->withSaveOutcome(summary: $import['summary'], saveResult: $saveResult);
		foreach (($saveResult['invalid'] ?? []) as $invalidItem) {
			$summary['errors'][] = $this->validationError(invalidItem: $invalidItem, sheetTitle: $import['sheetTitle']);
		}

		return $summary;
	}//end saveImportedObjects()

	/**
	 * One summary error for an object the bulk save rejected, with the sheet title first when there is one.
	 *
	 * @param mixed       $invalidItem The rejected item from the save result
	 * @param string|null $sheetTitle  The sheet title, null for CSV
	 *
	 * @return array<string, mixed>
	 */
	private function validationError(mixed $invalidItem, ?string $sheetTitle): array {
		$error = [
			'object' => $invalidItem['object'] ?? $invalidItem,
			'error' => $invalidItem['error'] ?? 'Validation failed',
			'type' => $invalidItem['type'] ?? 'ValidationException',
		];
		if ($sheetTitle === null) {
			return $error;
		}

		return ['sheet' => $sheetTitle] + $error;
	}//end validationError()

	/**
	 * Bulk-save objects with per-object notify_push events suppressed, then flush one collection event.
	 *
	 * On completion (success OR failure, since partial saves still happened)
	 * one deduplicated collection event per (register, schema) pair is flushed
	 * so connected clients refetch their lists. The hint is derived from the
	 * save RESULT, not from lifecycle events: bulk saves run with events
	 * disabled by default, so the listener never accumulates on its own.
	 *
	 * @param list<array> $objects The objects to save
	 * @param array       $import  The register, schema and save flags
	 *
	 * @return array The saveObjects() result: saved, updated, unchanged and invalid
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function bulkSaveWithNotifyPush(array $objects, array $import): array {
		$this->runtime->pushBatch->setBatchMode(true);
		$saveResult = null;
		try {
			$saveResult = $this->stores->objectService->saveObjects(
				objects: $objects,
				register: $import['register'],
				schema: $import['schema'],
				_rbac: $import['_rbac'],
				_multitenancy: $import['_multitenancy'],
				validation: $import['validation'],
				events: $import['events'],
				enrich: $import['enrich']
			);
		} finally {
			// Null result = save threw; partial saves may have landed, so hint conservatively.
			$collectionChanged = $saveResult === null
				|| empty($saveResult['saved'] ?? []) === false
				|| empty($saveResult['updated'] ?? []) === false;
			if ($collectionChanged === true) {
				$this->queueNotifyPushCollectionHint(table: new RegisterSchema(register: $import['register'], schema: $import['schema']));
			}

			// Flush BEFORE disabling batch mode — setBatchMode(false) clears the accumulator.
			$this->flushNotifyPushBatch();
			$this->runtime->pushBatch->setBatchMode(false);
		}//end try

		return $saveResult;
	}//end bulkSaveWithNotifyPush()

	/**
	 * Fill created, updated and unchanged from a bulk save result, with the deduplication efficiency.
	 *
	 * SaveObjects returns ObjectEntity->jsonSerialize() arrays where the UUID is in @self.id.
	 *
	 * @param array $summary    The import summary
	 * @param array $saveResult The saveObjects() result
	 *
	 * @return array The summary with the saved ids
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function withSaveOutcome(array $summary, array $saveResult): array {
		$summary['created'] = $this->savedObjectIds(objects: ($saveResult['saved'] ?? []));
		$summary['updated'] = $this->savedObjectIds(objects: ($saveResult['updated'] ?? []));
		$summary['unchanged'] = $this->savedObjectIds(objects: ($saveResult['unchanged'] ?? []));

		$efficiency = $this->deduplicationEfficiency(summary: $summary);
		if ($efficiency !== null) {
			$summary['deduplication_efficiency'] = $efficiency;
		}

		return $summary;
	}//end withSaveOutcome()

	/**
	 * The ids of saved objects: @self.id, else uuid, else id, else null.
	 *
	 * @param array $objects The ObjectEntity->jsonSerialize() arrays of a bulk save result
	 *
	 * @return array The ids, keyed like the objects
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function savedObjectIds(array $objects): array {
		return array_map(
			static fn (array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
			$objects
		);
	}//end savedObjectIds()

	/**
	 * The share of processed objects a bulk save left unchanged, when any were.
	 *
	 * @param array $summary The import summary with the created, updated and unchanged ids
	 *
	 * @return string|null The efficiency, e.g. "33.3% operations avoided", or null when nothing was unchanged
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function deduplicationEfficiency(array $summary): ?string {
		$unchangedCount = count($summary['unchanged']);
		if ($unchangedCount === 0) {
			return null;
		}

		$totalProcessed = count($summary['created']) + count($summary['updated']) + $unchangedCount;
		return round(($unchangedCount / $totalProcessed) * 100, 1) . '% operations avoided';
	}//end deduplicationEfficiency()

	/**
	 * Timing and speed metrics for an import, like SaveObjects reports.
	 *
	 * @param array $summary   The import summary, with the number of objects found
	 * @param float $startTime When the import started
	 *
	 * @return array{totalTime: float, totalTimeMs: float, objectsPerSecond: float, totalProcessed: int, totalFound: int, efficiency: int|float}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function importPerformance(array $summary, float $startTime): array {
		$totalImportTime = microtime(true) - $startTime;
		$processed = $summary['found'];
		$efficiency = 0;
		if ($summary['found'] > 0) {
			$efficiency = round(($processed / $summary['found']) * 100, 1);
		}

		return [
			'totalTime' => round($totalImportTime, 3),
			'totalTimeMs' => round($totalImportTime * 1000, 2),
			'objectsPerSecond' => round($processed / max($totalImportTime, 0.001), 2),
			'totalProcessed' => $processed,
			'totalFound' => $summary['found'],
			'efficiency' => $efficiency,
		];
	}//end importPerformance()

	/**
	 * Build a dry-run summary: every mapped row is validated against the
	 * target schema via `ValidateObject::validateObject()` (read-only —
	 * schema resolution + uniqueness SELECTs, no writes) and NOTHING is
	 * saved. `created`/`updated`/`unchanged` stay empty since no object was
	 * actually persisted; `rows` carries the per-row valid/invalid verdict
	 * migration quoting needs.
	 *
	 * @param array<string, mixed> $summary The summary accumulated so far (found/errors/etc).
	 * @param DryRunBatch          $batch   The objects that would be saved, their schema and the start time.
	 *
	 * @return array<string, mixed> The dry-run summary.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#dry-run
	 */
	private function buildDryRunSummary(array $summary, DryRunBatch $batch): array {
		$summary['dryRun'] = true;
		$summary['rows'] = [];
		$summary['validRows'] = 0;
		$summary['invalidRows'] = 0;

		foreach ($batch->objects as $index => $object) {
			$result = $this->stores->validator->validateObject(object: $object, schema: $batch->schema);
			$isValid = $result->isValid();

			if ($isValid === true) {
				$summary['validRows']++;
			} else {
				$summary['invalidRows']++;
			}

			$rowErrors = [];
			if ($isValid === false) {
				$rowErrors = [$this->stores->validator->generateErrorMessage(result: $result)];
			}

			$summary['rows'][] = [
				'index' => $index,
				'valid' => $isValid,
				'errors' => $rowErrors,
				'preview' => $object,
			];
		}//end foreach

		$totalTime = microtime(true) - $batch->startTime;
		$summary['performance'] = [
			'totalTime' => round($totalTime, 3),
			'totalTimeMs' => round($totalTime * 1000, 2),
			'totalProcessed' => count($batch->objects),
			'totalFound' => $summary['found'],
		];

		return $summary;
	}//end buildDryRunSummary()

	/**
	 * Transform a CSV row to object format for batch saving, for one register and schema.
	 *
	 * Public because the previewed import maps its rows through this exact
	 * method. A preview produced by a second transform would be a preview of
	 * something other than what the write does, which is the failure D-1
	 * exists to prevent.
	 *
	 * @param array           $rowData Row data from CSV
	 * @param RowImportTarget $target  The register and schema, and the importing user
	 *
	 * @return array<string, mixed> The object, with its `@self` section
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function transformCsvRow(array $rowData, RowImportTarget $target): array {
		$isAdmin = $this->isRowImporterAdmin(user: $target->importer);
		$table = $target->table;

		// Translatable-property pre-pass (register-i18n Phase 3 wire-in):
		// turn flat `field_lang` columns into the nested `field: {lang: value}`
		// shape the rest of the save pipeline expects. The codec is
		// tolerant of empty cells and unrelated underscore-suffixed
		// columns; the rest of this method sees the un-flattened shape.
		$rowData = $this->codecs->translationCsvCodec->unflattenFromCsv($rowData, $table->schema);

		[$selfColumns, $columns] = $this->splitRowColumns(rowData: $rowData, isAdmin: $isAdmin);
		$selfData = array_replace(['register' => $table->register->getId(), 'schema' => $table->schema->getId()], $selfColumns);
		if (empty($rowData['id']) === false) {
			$selfData['id'] = $rowData['id'];
		}

		$objectData = $this->typedColumns(columns: $columns, schema: $table->schema);
		$objectData['@self'] = $selfData;

		// Validate that we're not accidentally creating invalid properties.
		$this->validateObjectProperties(objectData: $objectData, _schemaId: (string)$table->schema->getId());

		return $objectData;
	}//end transformCsvRow()

	/**
	 * Split the filled cells of a row into `@self` values and object properties.
	 *
	 * `_` columns are ignored; of the `@` columns only `@self.*`, and only for an admin.
	 *
	 * @param array $rowData The row, by column name.
	 * @param bool  $isAdmin Whether the importing user is an admin.
	 *
	 * @return array{0: array, 1: array} The `@self` values and the properties.
	 */
	private function splitRowColumns(array $rowData, bool $isAdmin): array {
		$selfColumns = [];
		$columns = [];
		foreach (array_filter($rowData, static fn ($value): bool => $value !== null && $value !== '') as $key => $value) {
			$selfPropertyName = $this->selfColumnProperty(key: $key, isAdmin: $isAdmin);
			if ($selfPropertyName !== null) {
				$selfColumns[$selfPropertyName] = $this->valueCaster()->castSelfProperty(propertyName: $selfPropertyName, value: $value);
			}

			if (self::isDataColumn(key: $key) === true) {
				$columns[$key] = $value;
			}
		}

		return [$selfColumns, $columns];
	}//end splitRowColumns()

	/**
	 * Whether a column holds object data: its name does not start with `_` or `@`.
	 *
	 * @param int|string $key The column name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function isDataColumn(int|string $key): bool {
		$firstChar = ((string)$key)[0] ?? '';

		return $firstChar !== '_' && $firstChar !== '@';
	}//end isDataColumn()

	/**
	 * The columns with the values of schema properties converted to their type.
	 *
	 * @param array  $columns The properties, by column name.
	 * @param Schema $schema  The schema.
	 *
	 * @return array The properties.
	 */
	private function typedColumns(array $columns, Schema $schema): array {
		$schemaProperties = $this->cachedSchemaProperties(schema: $schema);
		foreach ($columns as $key => $value) {
			if (($schemaProperties[$key] ?? null) !== null) {
				$columns[$key] = $this->valueCaster()->cast(value: $value, propertyDef: $schemaProperties[$key]);
			}
		}

		return $columns;
	}//end typedColumns()

	/**
	 * The @self property an `@self.<name>` column sets, for an admin; null for any other column.
	 *
	 * @param int|string $key     The column name.
	 * @param bool       $isAdmin Whether the importing user is an admin.
	 *
	 * @return string|null The property name.
	 */
	private function selfColumnProperty(int|string $key, bool $isAdmin): ?string {
		if ($isAdmin === false || is_string($key) === false || str_starts_with($key, '@self.') === false) {
			return null;
		}

		return substr($key, 6);
	}//end selfColumnProperty()

	/**
	 * A schema's properties, cached per schema id on this instance.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array The property definitions.
	 */
	private function cachedSchemaProperties(Schema $schema): array {
		$schemaIdKey = (string)$schema->getId();
		if (isset($this->schemaPropertiesCache[$schemaIdKey]) === false) {
			$this->schemaPropertiesCache[$schemaIdKey] = $schema->getProperties() ?? [];
		}

		return $this->schemaPropertiesCache[$schemaIdKey];
	}//end cachedSchemaProperties()

	/**
	 * Whether the importing user is an admin, logged at debug level per row.
	 *
	 * @param IUser|null $user The importing user.
	 *
	 * @return bool
	 */
	private function isRowImporterAdmin(?IUser $user): bool {
		$isAdmin = $this->isUserAdmin(user: $user);
		$this->runtime->logger->debug(
			message: '[ImportService] Processing CSV row',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'isAdmin' => $isAdmin,
				'username' => $user?->getUID() ?? 'null',
			]
		);

		return $isAdmin;
	}//end isRowImporterAdmin()

	/**
	 * Transform Excel row data to object format for batch saving
	 *
	 * @param array        $rowData Row data from Excel
	 * @param ImportTarget $target  The optional register and schema, and the importing user
	 *
	 * @return array<string, mixed>|null Object data or null if transformation fails
	 *
	 */
	private function transformExcelRowToObject(array $rowData, ImportTarget $target): ?array {
		[$selfData, $objectData] = $this->splitRowColumns(rowData: $rowData, isAdmin: $this->isUserAdmin(user: $target->currentUser));

		return $this->castRowObject(
			objectData: self::withSelfSection(
				objectData: $objectData,
				selfData: self::withRowIdentity(selfData: $selfData, row: [$target->register, $target->schema, $rowData])
			),
			schema: $target->schema
		);
	}//end transformExcelRowToObject()

	/**
	 * Row metadata with the register, the schema and, for updates, the row's id.
	 *
	 * @param array $selfData The row's @self columns.
	 * @param array $row      The register, the schema and the row data.
	 *
	 * @return array The @self section.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function withRowIdentity(array $selfData, array $row): array {
		[$register, $schema, $rowData] = $row;
		if ($register !== null) {
			$selfData['register'] = $register->getId();
		}

		if ($schema !== null) {
			$selfData['schema'] = $schema->getId();
		}

		// Add ID if present in the data (for updates).
		if (empty($rowData['id']) === false) {
			$selfData['id'] = $rowData['id'];
		}

		return $selfData;
	}//end withRowIdentity()

	/**
	 * An object with its @self section, when that has anything in it.
	 *
	 * @param array $objectData The object.
	 * @param array $selfData   The @self section.
	 *
	 * @return array The object.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function withSelfSection(array $objectData, array $selfData): array {
		if (empty($selfData) === false) {
			$objectData['@self'] = $selfData;
		}

		return $objectData;
	}//end withSelfSection()

	/**
	 * An object with its values cast to the schema's types, when there is a schema.
	 *
	 * @param array       $objectData The object.
	 * @param Schema|null $schema     The schema.
	 *
	 * @return array The object.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function castRowObject(array $objectData, ?Schema $schema): array {
		if ($schema === null) {
			return $objectData;
		}

		return $this->valueCaster()->castObject(objectData: $objectData, properties: $schema->getProperties());
	}//end castRowObject()

	/**
	 * Build column mapping from spreadsheet headers
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet The worksheet
	 *
	 * @return array<string, string> Column mapping (column letter -> column name)
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Coordinate::stringFromColumnIndex is standard PhpSpreadsheet pattern
	 */
	private function buildColumnMapping(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array {
		$columnMapping = [];
		// Column letter -> column name.
		$columnIndex = 1;

		// Use PhpSpreadsheet built-in method to get column letters.
		while ($columnIndex <= 50) {
			// Check up to 50 columns.
			$columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
			$cellValue = $sheet->getCell($columnLetter . '1')->getValue();

			if ($cellValue === null || trim($cellValue) === '') {
				// Found empty column, stop here.
				break;
			}

			$cleanColumnName = trim($cellValue);
			$columnMapping[$columnLetter] = $cleanColumnName;

			$columnIndex++;
		}

		return $columnMapping;
	}//end buildColumnMapping()

	/**
	 * Extract data from a single row
	 *
	 * @param MappedSheet $sheet The worksheet and its column mapping
	 * @param int         $row   Row number
	 *
	 * @return string[]
	 *
	 * @psalm-return array<string, string>
	 */
	private function extractRowData(MappedSheet $sheet, int $row): array {
		$rowData = [];
		// Name -> value.
		$hasData = false;

		// Loop through each column in the mapping.
		foreach ($sheet->columnMapping as $columnLetter => $columnName) {
			$cellValue = $sheet->sheet->getCell($columnLetter . $row)->getValue();

			// Convert cell value to string and trim whitespace.
			$cleanCellValue = '';
			if ($cellValue !== null) {
				$cleanCellValue = trim((string)$cellValue);
			}

			if ($cleanCellValue !== '') {
				$rowData[$columnName] = $cleanCellValue;
				$hasData = true;
			}
		}

		if ($hasData === true) {
			return $rowData;
		}

		return [];
	}//end extractRowData()

	/**
	 * Get schema by slug
	 *
	 * @param string $slug The schema slug
	 *
	 * @return Schema The schema or null if not found
	 */
	private function getSchemaBySlug(string $slug): Schema {
		// NO ERROR SUPPRESSION: Let schema lookup errors bubble up immediately!
		$schema = $this->stores->schemaMapper->find($slug);
		return $schema;
	}//end getSchemaBySlug()

	/**
	 * Clear all internal caches to prevent issues between imports
	 *
	 * @return void
	 *
	 * @spec exclude One-line reset of the internal schema-properties cache; no business logic.
	 */
	public function clearCaches(): void {
		$this->schemaPropertiesCache = [];
	}//end clearCaches()

	/**
	 * Validate that object data only contains valid ObjectEntity properties
	 *
	 * @param array $objectData The object data to validate
	 * @param string $_schemaId Schema ID for debugging (unused, for future use)
	 *
	 * @return void
	 */
	private function validateObjectProperties(array $objectData, string $_schemaId): void {
		// Check for invalid properties (common mistakes).
		$invalidProperties = ['data', 'content', 'body', 'payload'];

		foreach (array_keys($objectData) as $key) {
			// Skip @self as it's handled separately.
			if ($key === '@self') {
				continue;
			}

			// Check for invalid properties that commonly cause issues.
			if (in_array($key, $invalidProperties) === true) {
			}
		}
	}//end validateObjectProperties()

	/**
	 * Serialize per-row import errors to a UTF-8 CSV blob with BOM.
	 *
	 * Walks the sheet-based import summary, extracting any `errors` entries
	 * and emitting one row per failure. Columns: `sheet`, `row`, `field`,
	 * `error_message`, `original_value`. Output is UTF-8 with BOM so Excel
	 * opens it cleanly (matches the existing template-export pattern).
	 *
	 * Returns an empty string when the summary contains no errors so callers
	 * can cheaply skip attaching the artefact.
	 *
	 * @param array<string, mixed> $summary The sheet-based import summary as
	 *                                      returned by importFromExcel /
	 *                                      importFromCsv.
	 *
	 * @return string The CSV payload (UTF-8 BOM prefixed) or empty string.
	 *
	 * @phpstan-param array<string, array{errors?: array<int, mixed>}> $summary
	 *
	 * @spec openspec/specs/data-import-export/spec.md#import-must-provide-detailed-error-reporting-with-downloadable-error-files
	 */
	public function serializeErrorsToCsv(array $summary): string {
		return (new ImportErrorsCsv())->render(summary: $summary);
	}//end serializeErrorsToCsv()
}//end class
