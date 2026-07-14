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
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-9
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-10
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-23
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-27
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectHandling;
use OCA\OpenRegister\Listener\NotifyPushListener;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUser;
use Symfony\Component\Uid\Uuid;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use DateTime;
use InvalidArgumentException;
use Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\Async\PromiseInterface;
use React\Promise\Promise;
use React\EventLoop\Loop;

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
class ImportService
{

    /**
     * Schema mapper instance
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Object service instance
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

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
     * Logger interface for logging operations
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Group manager for checking admin group membership
     *
     * @var IGroupManager
     */
    private readonly IGroupManager $groupManager;

    /**
     * Translation CSV codec for column projection on import/export.
     *
     * @var \OCA\OpenRegister\Service\Translation\TranslationCsvCodec
     */
    private readonly \OCA\OpenRegister\Service\Translation\TranslationCsvCodec $translationCsvCodec;

    /**
     * Constructor for the ImportService
     *
     * @param SchemaMapper                                              $schemaMapper          The schema mapper
     * @param ObjectService                                             $objectService         The object service
     * @param LoggerInterface                                           $logger                The logger interface
     * @param IGroupManager                                             $groupManager          The group manager
     * @param \OCA\OpenRegister\Service\Translation\TranslationCsvCodec $translationCsvCodec   Translation CSV codec
     * @param AuditTrailMapper                                          $auditTrailMapper      The audit trail mapper
     * @param MappingEngine                                             $mappingEngine         Migration-pack mapping engine
     * @param ValidateObject                                            $validateObjectHandler Schema validator, used for genuinely
     *                                                                                         side-effect-free dry-run imports
     * @param ContainerInterface                                        $container             DI container for lazy IQueue resolution
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        ObjectService $objectService,
        LoggerInterface $logger,
        IGroupManager $groupManager,
        \OCA\OpenRegister\Service\Translation\TranslationCsvCodec $translationCsvCodec,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly MappingEngine $mappingEngine,
        private readonly ValidateObject $validateObjectHandler,
        private readonly ContainerInterface $container
    ) {
        $this->schemaMapper  = $schemaMapper;
        $this->objectService = $objectService;
        $this->logger        = $logger;
        $this->groupManager  = $groupManager;
        $this->translationCsvCodec = $translationCsvCodec;

        // Initialize cache arrays to prevent issues.
        $this->schemaPropertiesCache = [];
    }//end __construct()

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
     * @spec openspec/specs/data-import-export/spec.md#import-rollback-on-critical-failure (rolls back an import
     *       unit: finds every create-audited object for the import job UUID and soft-deletes them, reporting
     *       per-object outcomes)
     */
    public function softDeleteByImportJobId(string $importJobId): array
    {
        $auditRows = $this->auditTrailMapper->findByImportJobId(importJobId: $importJobId, action: 'create');
        $report    = [
            'importJobId' => $importJobId,
            'candidates'  => count($auditRows),
            'softDeleted' => [],
            'errors'      => [],
        ];

        foreach ($auditRows as $row) {
            $objectUuid = $row->getObjectUuid();
            if ($objectUuid === null || $objectUuid === '') {
                continue;
            }

            try {
                $this->objectService->deleteObject(uuid: $objectUuid);
                $report['softDeleted'][] = $objectUuid;
            } catch (\Throwable $e) {
                $report['errors'][] = [
                    'uuid'  => $objectUuid,
                    'error' => $e->getMessage(),
                ];
                $this->logger->warning(
                    message: '[ImportService] Failed to soft-delete object during import rollback',
                    context: [
                        'importJobId' => $importJobId,
                        'objectUuid'  => $objectUuid,
                        'error'       => $e->getMessage(),
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
    private function isUserAdmin(?IUser $user): bool
    {
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
     * @param string        $filePath  The path to the Excel file.
     * @param Register|null $register  Optional register to associate with imported objects.
     * @param Schema|null   $schema    Optional schema to associate with imported objects.
     * @param int           $chunkSize Number of rows to process in each chunk (default: 100).
     *
     * @return PromiseInterface<array<string, array>> Promise that resolves to import summary.
     */



    /**
     * Import data from Excel file.
     *
     * @param string        $filePath  The path to the Excel file.
     * @param Register|null $register  Optional register to associate with imported objects.
     * @param Schema|null   $schema    Optional schema to associate with imported objects.
     * @param int           $chunkSize Number of rows to process in each chunk (default: 100).
     *
     * @return         array<string, array> Summary of import with sheet-based results.
     * @phpstan-return array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */

    /**
     * Import data from Excel file.
     *
     * @param string        $filePath      The path to the Excel file.
     * @param Register|null $register      Optional register to associate with imported objects.
     * @param Schema|null   $schema        Optional schema to associate with imported objects.
     * @param bool          $validation    Whether to validate objects against schema definitions (default: false).
     * @param bool          $events        Whether to dispatch object lifecycle events (default: false).
     * @param bool          $_rbac         Whether to apply RBAC checks (default: true, unused).
     * @param bool          $_multitenancy Whether to apply multitenancy checks (default: true, unused).
     * @param bool          $publish       DEPRECATED: No-op. Object-level publish metadata removed; use RBAC $now rules.
     * @param IUser|null    $currentUser   The current user performing the import (optional).
     * @param bool          $enrich        Whether to enrich objects with metadata (default: true).
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
     * }>
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-9
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-23
     */
    public function importFromExcel(
        string $filePath,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null,
        bool $enrich=true
    ): array {
        // Clear caches at the start of each import to prevent stale data issues.
        $this->clearCaches();

        // Generate a per-import UUID and stamp it on every audit row
        // produced during this call. ImportService::softDeleteByImportJobId
        // uses this UUID to roll the import back. The set/clear pair is
        // wrapped in try/finally so the request-scoped field is always
        // cleared, including when the set itself or any subsequent line
        // throws — guards against cross-request bleed on long-lived
        // workers where the singleton mapper is reused.
        $importJobId = Uuid::v4()->toRfc4122();

        try {
            $this->auditTrailMapper->setRequestImportJobId(importJobId: $importJobId);

            $reader = new Xlsx();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);

            // If we have a register but no schema, process each sheet as a different schema.
            if ($register !== null && $schema === null) {
                $multi = $this->processMultiSchemaSpreadsheetAsync(
                    spreadsheet: $spreadsheet,
                    register: $register,
                    validation: $validation,
                    events: $events,
                    _rbac: $_rbac,
                    _multitenancy: $_multitenancy,
                    publish: $publish,
                    currentUser: $currentUser,
                    enrich: $enrich
                );
                $multi['importJobId'] = $importJobId;
                return $multi;
            }

            // Single schema processing - use batch processing for better performance.
            $sheetTitle   = $spreadsheet->getActiveSheet()->getTitle();
            $sheetSummary = $this->processSpreadsheetBatch(
                spreadsheet: $spreadsheet,
                register: $register,
                schema: $schema,
                validation: $validation,
                events: $events,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                publish: $publish,
                currentUser: $currentUser,
                enrich: $enrich
            );

            // Add schema information to the summary (consistent with multi-sheet Excel import).
            if ($schema !== null) {
                $sheetSummary['schema'] = [
                    'id'    => $schema->getId(),
                    'title' => $schema->getTitle(),
                    'slug'  => $schema->getSlug(),
                ];
            }

            // Schedule SOLR warmup job after successful Excel import.
            $finalResult = [
                $sheetTitle   => $sheetSummary,
                'importJobId' => $importJobId,
            ];

            return $finalResult;
        } finally {
            $this->auditTrailMapper->setRequestImportJobId(importJobId: null);
        }//end try
    }//end importFromExcel()

    /**
     * Import data from CSV file.
     *
     * @param string        $filePath      The path to the CSV file.
     * @param Register|null $register      Optional register to associate with imported objects.
     * @param Schema|null   $schema        Optional schema to associate with imported objects.
     * @param bool          $validation    Whether to validate objects against schema definitions (default: false).
     * @param bool          $events        Whether to dispatch object lifecycle events (default: false).
     * @param bool          $_rbac         Whether to enforce RBAC checks (default: true, unused).
     * @param bool          $_multitenancy Whether to enable multi-tenancy (default: true, unused).
     * @param bool          $publish       DEPRECATED: No-op. Object-level publish metadata removed; use RBAC $now rules.
     * @param IUser|null    $currentUser   Current user for RBAC checks (default: null).
     * @param bool          $enrich        Whether to enrich objects with metadata (default: true).
     * @param array|null    $pack          Optional migration pack definition (decoded JSON). When given, each row is
     *                                     mapped through `MappingEngine::mapRow()` before the normal validate/save
     *                                     pipeline.
     * @param bool          $dryRun        When true, rows are mapped and validated but nothing is saved.
     *
     * @return array Import results by schema
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags control import behavior options
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the existing importFrom* signatures; pack/dryRun extend it.
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-23
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    public function importFromCsv(
        string $filePath,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null,
        bool $enrich=true,
        ?array $pack=null,
        bool $dryRun=false
    ): array {
        // Clear caches at the start of each import to prevent stale data issues.
        $this->clearCaches();

        // CSV can only handle a single schema.
        if ($schema === null) {
            throw new InvalidArgumentException('CSV import requires a specific schema');
        }

        // Per-import UUID — see importFromExcel() for the rationale.
        $importJobId = Uuid::v4()->toRfc4122();

        try {
            $this->auditTrailMapper->setRequestImportJobId(importJobId: $importJobId);

            // Use PhpSpreadsheet CSV reader (works perfectly for multiline fields).
            $reader = new Csv();
            $reader->setReadDataOnly(true);
            $reader->setDelimiter(',');
            $reader->setEnclosure('"');
            $spreadsheet = $reader->load($filePath);

            // Get the sheet title for CSV (usually just 'Worksheet' or similar).
            $sheetTitle   = $spreadsheet->getActiveSheet()->getTitle();
            $sheetSummary = $this->processCsvSheet(
                sheet: $spreadsheet->getActiveSheet(),
                register: $register,
                schema: $schema,
                validation: $validation,
                events: $events,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                publish: $publish,
                currentUser: $currentUser,
                enrich: $enrich,
                pack: $pack,
                dryRun: $dryRun
            );

            // Add schema information to the summary (consistent with Excel import).
            $sheetSummary['schema'] = [
                'id'    => $schema->getId(),
                'title' => $schema->getTitle(),
                'slug'  => $schema->getSlug(),
            ];

            // Schedule SOLR warmup job after successful CSV import.
            $finalResult = [
                $sheetTitle   => $sheetSummary,
                'importJobId' => $importJobId,
            ];

            return $finalResult;
        } finally {
            $this->auditTrailMapper->setRequestImportJobId(importJobId: null);
        }//end try
    }//end importFromCsv()

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
     * @param string        $filePath      Path to the uploaded JSON file
     * @param Register|null $register      Register to import into
     * @param Schema|null   $schema        Target schema (required — JSON import is single-schema, like
     *                                     CSV)
     * @param bool          $validation    Whether to validate objects against the schema
     * @param bool          $events        Whether to dispatch object lifecycle events
     * @param bool          $_rbac         Whether to apply RBAC permissions
     * @param bool          $_multitenancy Whether to apply multi-tenancy filtering
     * @param bool          $publish       DEPRECATED no-op; publication is RBAC-driven
     * @param IUser|null    $currentUser   The current user performing the import
     * @param bool          $enrich        Whether to enrich objects with metadata
     * @param array|null    $pack          Optional migration pack definition (decoded JSON). When given, each JSON
     *                                     object is mapped through `MappingEngine::mapRow()` (source resolved via
     *                                     JSON-Pointer-style paths against the raw decoded object) before save.
     * @param bool          $dryRun        When true, rows are mapped and validated but nothing is saved.
     *
     * @return array<string, mixed> Sheet-shaped summary (keyed 'JSON') plus importJobId
     *
     * @throws InvalidArgumentException When no schema is given or the payload is not an array of objects
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags control import behavior options
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the Excel/CSV importer signatures
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)  validation/events/enrich are kept for signature parity with importFromExcel/importFromCsv
     *
     * @spec exclude Retrofit — JSON object import/export added alongside the existing Excel/CSV importers; no dedicated openspec change.
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    public function importFromJson(
        string $filePath,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null,
        bool $enrich=true,
        ?array $pack=null,
        bool $dryRun=false
    ): array {
        // Clear caches at the start of each import to prevent stale data issues.
        $this->clearCaches();
        $startTime = microtime(true);

        if ($schema === null) {
            throw new InvalidArgumentException('JSON import requires a specific schema');
        }

        if ($publish === true) {
            $this->logger->warning(
                message: '[ImportService] The $publish parameter is deprecated. Use RBAC $now rules instead.',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }

        $raw     = file_get_contents($filePath);
        $decoded = null;
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
        }

        if (is_array($decoded) === false) {
            throw new InvalidArgumentException('JSON import expects an array of objects');
        }

        // Accept both a bare array and a `{ "results": [...] }` envelope (the
        // shape some object-list endpoints return).
        $objects = $decoded;
        if (isset($decoded['results']) === true && is_array($decoded['results']) === true) {
            $objects = $decoded['results'];
        }

        // ExportService::exportToJson() emits ObjectEntity::jsonSerialize() —
        // schema properties at the top level PLUS an `@self` block and
        // entity-level fields (uuid/version/slug/dates) that are NOT schema
        // properties. Persist each object through the single-object saveObject()
        // path — the same one the REST create/update uses — which reliably
        // upserts by uuid for this register/schema. (The bulk saveObjects()
        // path silently skips these objects for dedicated-table schemas.) The
        // body is reduced to the schema's own properties, with empty strings
        // coerced to null.
        $propertyKeys = array_flip(array_keys($schema->getProperties()));

        $importJobId = Uuid::v4()->toRfc4122();

        try {
            $this->auditTrailMapper->setRequestImportJobId(importJobId: $importJobId);

            $summary = [
                'found'     => count($objects),
                'created'   => [],
                'updated'   => [],
                'unchanged' => [],
                'errors'    => [],
            ];

            // Phase 1: resolve every row to an (uuid, body) pair. When a pack is
            // given, each source object is mapped through it first (source
            // resolved via JSON-Pointer-style paths against the raw decoded
            // object) — a row with mapping errors (missing required source, or
            // an unresolved lookup/reference — the literal-leak guard) is
            // reported and excluded, never partially mapped or saved.
            $resolved = [];
            foreach ($objects as $rowIndex => $raw) {
                if (is_array($raw) === false) {
                    continue;
                }

                // Keys are list indexes for a JSON array export; cast guards
                // against a decoded object-with-string-keys envelope.
                $rowNumber = ((int) $rowIndex + 1);
                if ($pack !== null && $this->mappingEngine->isRowSkipped(pack: $pack, rowNumber: $rowNumber) === true) {
                    continue;
                }

                if ($pack !== null) {
                    $mapped = $this->mappingEngine->mapRow(pack: $pack, sourceRow: $raw, rowNumber: $rowNumber);
                    if (empty($mapped['errors']) === false) {
                        foreach ($mapped['errors'] as $mappingError) {
                            $summary['errors'][] = $this->formatMappingError(error: $mappingError);
                        }

                        continue;
                    }

                    $body = $mapped['data'];
                    $uuid = $body['id'] ?? null;
                    unset($body['id']);
                    if ($uuid !== null) {
                        $uuid = (string) $uuid;
                    }
                } else {
                    // Upsert key: prefer @self.id, then a top-level id/uuid.
                    $uuid = ($raw['@self']['id'] ?? $raw['id'] ?? $raw['uuid'] ?? null);
                    if ($uuid !== null) {
                        $uuid = (string) $uuid;
                    }

                    // Body = schema properties only; empty strings → null.
                    $body = array_intersect_key($raw, $propertyKeys);
                    foreach ($body as $key => $value) {
                        if ($value === '') {
                            $body[$key] = null;
                        }
                    }
                }//end if

                $resolved[] = [
                    'uuid' => $uuid,
                    'body' => $body,
                    'name' => ($raw['name'] ?? $uuid),
                ];
            }//end foreach

            // Phase 2a: dry-run — validate every resolved row, save NOTHING.
            if ($dryRun === true) {
                $summary = $this->buildDryRunSummary(
                    summary: $summary,
                    objects: array_column($resolved, 'body'),
                    schema: $schema,
                    startTime: $startTime
                );

                $summary['schema'] = [
                    'id'    => $schema->getId(),
                    'title' => $schema->getTitle(),
                    'slug'  => $schema->getSlug(),
                ];

                return [
                    'JSON'        => $summary,
                    'importJobId' => $importJobId,
                ];
            }

            // Phase 2b: persist each resolved row through the same single-object
            // saveObject() path the REST create/update uses (unchanged from the
            // non-pack behaviour — the bulk saveObjects() path silently skips
            // dedicated-table schemas, see the class-level note above).
            foreach ($resolved as $row) {
                try {
                    $saved = $this->objectService->saveObject(
                        object: $row['body'],
                        register: $register,
                        schema: $schema,
                        uuid: $row['uuid'],
                        _rbac: $_rbac,
                        _multitenancy: $_multitenancy,
                        currentUser: $currentUser
                    );

                    if ($row['uuid'] !== null) {
                        $summary['updated'][] = $saved->getUuid();
                    } else {
                        $summary['created'][] = $saved->getUuid();
                    }
                } catch (\Throwable $e) {
                    $summary['errors'][] = [
                        'object' => $row['name'],
                        'error'  => $e->getMessage(),
                        'type'   => get_class($e),
                    ];
                }//end try
            }//end foreach

            $summary['schema'] = [
                'id'    => $schema->getId(),
                'title' => $schema->getTitle(),
                'slug'  => $schema->getSlug(),
            ];

            $finalResult = [
                'JSON'        => $summary,
                'importJobId' => $importJobId,
            ];

            return $finalResult;
        } finally {
            $this->auditTrailMapper->setRequestImportJobId(importJobId: null);
        }//end try
    }//end importFromJson()

    /**
     * Process spreadsheet with multiple schemas using batch saving for better performance
     *
     * @param Spreadsheet $spreadsheet   The spreadsheet to process
     * @param Register    $register      The register to associate with imported objects
     * @param bool        $validation    Whether to validate objects against schema definitions
     * @param bool        $events        Whether to dispatch object lifecycle events
     * @param bool        $_rbac         Whether to apply RBAC permissions
     * @param bool        $_multitenancy Whether to apply multi-tenancy filtering
     * @param bool        $publish       DEPRECATED: No-op. Object-level publish metadata removed; use RBAC $now rules
     * @param IUser|null  $currentUser   The current user performing the import.
     * @param bool        $enrich        Whether to enrich objects with metadata.
     *
     * @return         array<string, array> Summary of import with sheet-based results
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
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control import behavior options
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-27
     */
    private function processMultiSchemaSpreadsheetAsync(
        Spreadsheet $spreadsheet,
        Register $register,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null,
        bool $enrich=true
    ): array {
        $summary = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $schemaSlug = $worksheet->getTitle();
            $schema     = $this->getSchemaBySlug(slug: $schemaSlug);

            // Initialize sheet summary even if no schema found.
            $summary[$schemaSlug] = [
                'found'   => 0,
                'created' => [],
                'updated' => [],
            // TODO: Renamed from 'skipped' - more descriptive (objects skipped because content was unchanged).
                'errors'  => [],
                'schema'  => null,
                'debug'   => [
                    'headers'            => [],
                    'schemaProperties'   => [],
                    'processableHeaders' => [],
                ],
            ];

            // Skip sheets that don't correspond to a valid schema.
            // Note: getSchemaBySlug() returns Schema (non-nullable) or throws exception.
            try {
                $schema = $this->getSchemaBySlug(slug: $schemaSlug);
                // Schema is guaranteed to be non-null if we reach here (exception thrown otherwise).
                // Add schema information to the summary.
                $summary[$schemaSlug]['schema'] = [
                    'id'    => $schema->getId(),
                    'title' => $schema->getTitle(),
                    'slug'  => $schema->getSlug(),
                ];
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                $summary[$schemaSlug]['errors'][] = [
                    'sheet'    => $schemaSlug,
                    'register' => [
                        'id'   => $register->getId(),
                        'name' => $register->getTitle(),
                    ],
                    'schema'   => null,
                    'error'    => 'No matching schema found for sheet: '.$schemaSlug,
                    'type'     => 'SchemaNotFoundException',
                ];
                continue;
            }//end try

            // Update debug information with schema properties.
            $schemaProperties = $schema->getProperties();
            $propertyKeys     = array_keys($schemaProperties);
            $summary[$schemaSlug]['debug']['schemaProperties'] = $propertyKeys;

            // Set the worksheet as active and process using batch saving for better performance.
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($worksheet));
            $sheetSummary = $this->processSpreadsheetBatch(
                spreadsheet: $spreadsheet,
                register: $register,
                schema: $schema,
                validation: $validation,
                events: $events,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                publish: $publish,
                currentUser: $currentUser,
                enrich: $enrich
            );

            // Merge the sheet summary with the existing summary (preserve debug info).
            $summary[$schemaSlug] = array_merge($summary[$schemaSlug], $sheetSummary);
        }//end foreach

        return $summary;
    }//end processMultiSchemaSpreadsheetAsync()

    /**
     * Process spreadsheet with single schema using batch saving for better performance
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to process
     * @param Register|null $register  Optional register to associate with imported objects
     * @param Schema|null   $schema    Optional schema to associate with imported objects
     * @param int           $chunkSize Number of rows to process in each chunk
     *
     * @return         array<string, array> Summary of import with sheet-based results
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
     * @SuppressWarnings(PHPMD.StaticAccess) NotifyPushListener batch API is static by design (accessible without DI from import context)
     *
     * @spec openspec/specs/realtime-updates/spec.md
     */
    private function flushNotifyPushBatch(): void
    {
        if (NotifyPushListener::hasBatchedCollections() === false) {
            return;
        }

        try {
            $queue = $this->container->get('OCA\NotifyPush\Queue\IQueue');
        } catch (\Throwable $e) {
            // Notify_push unavailable — soft-fail with a single DEBUG log.
            $this->logger->debug(
                message: '[ImportService] notify_push IQueue not available; skipping batch flush',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return;
        }

        NotifyPushListener::flushBatch(queue: $queue);

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
     * @param Register $register The register the import saved into.
     * @param Schema   $schema   The schema the import saved into.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) NotifyPushListener batch API is static by design (accessible without DI from import context)
     *
     * @spec openspec/specs/realtime-updates/spec.md
     */
    private function queueNotifyPushCollectionHint(Register $register, Schema $schema): void
    {
        try {
            $registerSlug = (string) ($register->getSlug() ?? '');
            $schemaSlug   = (string) ($schema->getSlug() ?? '');
            NotifyPushListener::addBatchedCollection(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
        } catch (\Throwable $e) {
            // Slug not resolvable — skip the hint, never break the import.
            $this->logger->debug(
                message: '[ImportService] Could not queue notify_push collection hint',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }

    }//end queueNotifyPushCollectionHint()

    /**
     * Process a single spreadsheet sheet using batch saving for better performance
     *
     * @param Spreadsheet   $spreadsheet   The spreadsheet to process
     * @param Register|null $register      Optional register to associate with imported objects
     * @param Schema|null   $schema        Optional schema to associate with imported objects
     * @param bool          $validation    Whether to validate objects against schema definitions
     * @param bool          $events        Whether to dispatch object lifecycle events
     * @param bool          $_rbac         Whether to apply RBAC permissions
     * @param bool          $_multitenancy Whether to apply multi-tenancy filtering
     * @param bool          $publish       Whether to publish objects after import
     * @param IUser|null    $currentUser   The current user performing the import
     * @param bool          $enrich        Whether to enrich objects with metadata
     *
     * @return array Batch processing results
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flags control import behavior options
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Spreadsheet batch processing requires many validation branches
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple row/column validation paths needed for data integrity
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Batch processing consolidates related operations for performance
     * @SuppressWarnings(PHPMD.StaticAccess)          NotifyPushListener::setBatchMode/flushBatch are NC idiom static calls
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-27
     */
    private function processSpreadsheetBatch(
        Spreadsheet $spreadsheet,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null,
        bool $enrich=true
    ): array {
        $summary = [
            'found'     => 0,
            'created'   => [],
            'updated'   => [],
        // TODO: Renamed from 'skipped' - more descriptive.
            'unchanged' => [],
            'errors'    => [],
        ];

        // Get the active sheet.
        $sheet      = $spreadsheet->getActiveSheet();
        $sheetTitle = $sheet->getTitle();

        // Build column mapping from headers.
        $columnMapping = $this->buildColumnMapping(sheet: $sheet);

        if (empty($columnMapping) === true) {
            $summary['errors'][] = [
                'sheet'  => $sheetTitle,
                'row'    => 1,
                'object' => [],
                'error'  => 'No valid headers found in sheet',
            ];
            return $summary;
        }

        // Get total rows in the sheet.
        $highestRow = $sheet->getHighestRow();

        if ($highestRow <= 1) {
            $summary['errors'][] = [
                'sheet'  => $sheetTitle,
                'row'    => 1,
                'object' => [],
                'error'  => 'No data rows found in sheet',
            ];
            return $summary;
        }

        // Parse ALL rows into objects array (no chunking here!).
        $allObjects = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            // NO ERROR SUPPRESSION: Let row processing errors bubble up immediately!
            $rowData = $this->extractRowData(sheet: $sheet, columnMapping: $columnMapping, row: $row);

            if (empty($rowData) === true) {
                continue;
                // Skip empty rows.
            }

            // Transform row data to object format.
            $object = $this->transformExcelRowToObject(
                rowData: $rowData,
                register: $register,
                schema: $schema,
                currentUser: $currentUser
            );

            if ($object !== null) {
                $allObjects[] = $object;
            }
        }//end for

        $summary['found'] = count($allObjects);

        // Call saveObjects ONCE with all objects - NO ERROR SUPPRESSION!
        // This will reveal the real bulk save problem immediately.
        if ((empty($allObjects) === false) && $register !== null && $schema !== null) {
            // DEPRECATED: Object-level published metadata has been removed.
            // Publication control is now handled via RBAC authorization rules with $now.
            // The $publish parameter is kept for backward compatibility but is a no-op.
            if ($publish === true) {
                $this->logger->warning(
                    message: '[ImportService] The $publish parameter is deprecated. Use RBAC $now rules instead.',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                    ]
                );
            }

            // Suppress per-object notify_push events during the bulk save; on
            // completion (success OR failure — partial saves still happened)
            // flush one deduplicated collection event per (register, schema)
            // pair so connected clients refetch their lists. The hint is
            // derived from the save RESULT, not from lifecycle events: bulk
            // saves run with events disabled by default, so the listener
            // never accumulates on its own.
            NotifyPushListener::setBatchMode(true);
            $saveResult = null;
            try {
                $saveResult = $this->objectService->saveObjects(
                    objects: $allObjects,
                    register: $register,
                    schema: $schema,
                    _rbac: $_rbac,
                    _multitenancy: $_multitenancy,
                    validation: $validation,
                    events: $events,
                    enrich: $enrich
                );
            } finally {
                // Null result = save threw; partial saves may have landed, so hint conservatively.
                $collectionChanged = $saveResult === null
                    || empty($saveResult['saved'] ?? []) === false
                    || empty($saveResult['updated'] ?? []) === false;
                if ($collectionChanged === true) {
                    $this->queueNotifyPushCollectionHint(register: $register, schema: $schema);
                }

                // Flush BEFORE disabling batch mode — setBatchMode(false) clears the accumulator.
                $this->flushNotifyPushBatch();
                NotifyPushListener::setBatchMode(false);
            }//end try

            // Use the structured return from saveObjects with smart deduplication.
            // SaveObjects returns ObjectEntity->jsonSerialize() arrays where UUID is in @self.id.
            $summary['created'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['saved'] ?? []
            );
            $summary['updated'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['updated'] ?? []
            );

            // TODO: Handle unchanged objects from smart deduplication (renamed from 'skipped').
            $summary['unchanged'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['unchanged'] ?? []
            );

            // Add efficiency metrics from smart deduplication.
            $createdCount   = count($summary['created']);
            $updatedCount   = count($summary['updated']);
            $unchangedCount = count($summary['unchanged']);
            $totalProcessed = $createdCount + $updatedCount + $unchangedCount;
            if ($totalProcessed > 0 && $unchangedCount > 0) {
                $efficiency = round(($unchangedCount / $totalProcessed) * 100, 1);
                $summary['deduplication_efficiency'] = $efficiency.'% operations avoided';
            }

            // Handle validation errors if validation was enabled.
            if ($validation === true && empty($saveResult['invalid'] ?? []) === false) {
                foreach (($saveResult['invalid'] ?? []) as $invalidItem) {
                    $summary['errors'][] = [
                        'sheet'  => $sheetTitle,
                        'object' => $invalidItem['object'] ?? $invalidItem,
                        'error'  => $invalidItem['error'] ?? 'Validation failed',
                        'type'   => $invalidItem['type'] ?? 'ValidationException',
                    ];
                }
            }
        }//end if

        // NO ERROR SUPPRESSION: Row parsing errors will bubble up immediately - no need to collect them.
        // Note: Processing time calculation removed as it was unused.
        // $processingTime = microtime(true) - $startTime;.
        return $summary;
    }//end processSpreadsheetBatch()

    /**
     * Process CSV sheet and import all objects in batches
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet to process
     * @param Register                                      $register      The register to associate with imported objects
     * @param Schema                                        $schema        The schema to associate with imported objects
     * @param bool                                          $validation    Whether to validate objects
     * @param bool                                          $events        Whether to dispatch events
     * @param bool                                          $_rbac         Whether to apply RBAC
     * @param bool                                          $_multitenancy Multi-tenancy filtering
     * @param bool                                          $publish       DEPRECATED: No-op. Publish metadata removed.
     * @param IUser|null                                    $currentUser   The current user performing the import
     * @param bool                                          $enrich        Whether to enrich objects with metadata
     * @param array|null                                    $pack          Optional migration pack definition; each row is mapped through it first
     * @param bool                                          $dryRun        When true, rows are mapped and validated but nothing is saved
     *
     * @return array CSV sheet processing results
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags control import behavior options
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)   CSV processing requires many conditional branches for data handling
     * @SuppressWarnings(PHPMD.NPathComplexity)        CSV processing requires many conditional row/column handling
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  CSV processing consolidates related operations for performance
     * @SuppressWarnings(PHPMD.StaticAccess)           NotifyPushListener::setBatchMode/flushBatch are NC idiom static calls
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) pack/dryRun extend the existing signature
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-27
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    private function processCsvSheet(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        Register $register,
        Schema $schema,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null,
        bool $enrich=true,
        ?array $pack=null,
        bool $dryRun=false
    ): array {
        $summary = [
            'found'     => 0,
            'created'   => [],
            'updated'   => [],
        // TODO: Renamed from 'skipped' - more descriptive.
            'unchanged' => [],
            'errors'    => [],
        ];

        // REMOVED ERROR SUPPRESSION: Let CSV bulk save errors bubble up immediately!
        $startTime = microtime(true);

        // Build column mapping from headers.
        $columnMapping = $this->buildColumnMapping(sheet: $sheet);

        if (empty($columnMapping) === true) {
            $summary['errors'][] = [
                'row'    => 1,
                'object' => [],
                'error'  => 'No valid headers found in CSV file',
            ];
            return $summary;
        }

        // Get total rows in the sheet.
        $highestRow = $sheet->getHighestRow();

        if ($highestRow <= 1) {
            $summary['errors'][] = [
                'row'    => 1,
                'object' => [],
                'error'  => 'No data rows found in CSV file',
            ];
            return $summary;
        }

        // Parse ALL rows into objects array (no chunking here!).
        $allObjects = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            if ($pack !== null && $this->mappingEngine->isRowSkipped(pack: $pack, rowNumber: $row) === true) {
                continue;
            }

            // NO ERROR SUPPRESSION: Let CSV row processing errors bubble up immediately!
            $rowData = $this->extractRowData(sheet: $sheet, columnMapping: $columnMapping, row: $row);

            if (empty($rowData) === true) {
                continue;
                // Skip empty rows.
            }

            // Migration pack mapping: source columns -> target schema properties,
            // with transforms applied. A row with mapping errors (missing required
            // source, or an unresolved lookup/reference — the literal-leak guard)
            // is reported and excluded from the batch, never partially mapped.
            if ($pack !== null) {
                $mapped = $this->mappingEngine->mapRow(pack: $pack, sourceRow: $rowData, rowNumber: $row);
                if (empty($mapped['errors']) === false) {
                    foreach ($mapped['errors'] as $mappingError) {
                        $summary['errors'][] = $this->formatMappingError(error: $mappingError);
                    }

                    continue;
                }

                $rowData = $mapped['data'];
            }

            // Transform row data to object format.
            $object = $this->transformCsvRowToObject(
                rowData: $rowData,
                register: $register,
                schema: $schema,
                currentUser: $currentUser
            );

            if ($object !== null) {
                $allObjects[] = $object;
            }
        }//end for

        $summary['found'] = count($allObjects);

        // Dry-run: map + validate every row, save NOTHING. This is the killer
        // feature for migration quoting — an operator can see exactly what
        // would happen before committing to a real import. Genuinely
        // side-effect-free: ValidateObject::validateObject() only issues
        // read-only lookups (schema resolution, uniqueness SELECTs), and
        // ObjectService::saveObjects()/saveObject() is never called on this path.
        if ($dryRun === true) {
            return $this->buildDryRunSummary(summary: $summary, objects: $allObjects, schema: $schema, startTime: $startTime);
        }

        // NOTE: Deduplication is now handled by SaveObjects::saveObjects() (deduplicateIds=true by default).
        // This ensures consistent deduplication across ALL bulk save operations (CSV, Excel, API, etc.).
        // Call saveObjects ONCE with all objects - deduplication happens automatically.
        if (empty($allObjects) === false) {
            // Log publish processing for debugging.
            $this->logger->debug(
                message: '[ImportService] CSV import processing objects',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'objectCount' => count($allObjects),
                    'publish'     => $publish,
                ]
            );

            // DEPRECATED: Object-level published metadata has been removed.
            // Publication control is now handled via RBAC authorization rules with $now.
            // The $publish parameter is kept for backward compatibility but is a no-op.
            if ($publish === true) {
                $this->logger->warning(
                    message: '[ImportService] The $publish parameter is deprecated. Use RBAC $now rules instead.',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                    ]
                );
            }

            // Suppress per-object notify_push events during the bulk save; on
            // completion (success OR failure — partial saves still happened)
            // flush one deduplicated collection event per (register, schema)
            // pair so connected clients refetch their lists. The hint is
            // derived from the save RESULT, not from lifecycle events: bulk
            // saves run with events disabled by default, so the listener
            // never accumulates on its own.
            NotifyPushListener::setBatchMode(true);
            $saveResult = null;
            try {
                $saveResult = $this->objectService->saveObjects(
                    objects: $allObjects,
                    register: $register,
                    schema: $schema,
                    _rbac: $_rbac,
                    _multitenancy: $_multitenancy,
                    validation: $validation,
                    events: $events,
                    enrich: $enrich
                );
            } finally {
                // Null result = save threw; partial saves may have landed, so hint conservatively.
                $collectionChanged = $saveResult === null
                    || empty($saveResult['saved'] ?? []) === false
                    || empty($saveResult['updated'] ?? []) === false;
                if ($collectionChanged === true) {
                    $this->queueNotifyPushCollectionHint(register: $register, schema: $schema);
                }

                // Flush BEFORE disabling batch mode — setBatchMode(false) clears the accumulator.
                $this->flushNotifyPushBatch();
                NotifyPushListener::setBatchMode(false);
            }//end try

            // Use the structured return from saveObjects with smart deduplication.
            // SaveObjects returns ObjectEntity->jsonSerialize() arrays where UUID is in @self.id.
            $summary['created'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['saved'] ?? []
            );
            $summary['updated'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['updated'] ?? []
            );

            // TODO: Handle unchanged objects from smart deduplication (renamed from 'skipped').
            $summary['unchanged'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['unchanged'] ?? []
            );

            // Add efficiency metrics from smart deduplication.
            $createdCount   = count($summary['created']);
            $updatedCount   = count($summary['updated']);
            $unchangedCount = count($summary['unchanged']);
            $totalProcessed = $createdCount + $updatedCount + $unchangedCount;
            if ($totalProcessed > 0 && $unchangedCount > 0) {
                $efficiency = round(($unchangedCount / $totalProcessed) * 100, 1);
                $summary['deduplication_efficiency'] = $efficiency.'% operations avoided';
            }

            // Handle validation errors if validation was enabled.
            if ($validation === true && empty($saveResult['invalid'] ?? []) === false) {
                foreach (($saveResult['invalid'] ?? []) as $invalidItem) {
                    $summary['errors'][] = [
                        'object' => $invalidItem['object'] ?? $invalidItem,
                        'error'  => $invalidItem['error'] ?? 'Validation failed',
                        'type'   => $invalidItem['type'] ?? 'ValidationException',
                    ];
                }
            }
        }//end if

        // NO ERROR SUPPRESSION: Row parsing errors will bubble up immediately - no need to collect them.
        $totalImportTime      = microtime(true) - $startTime;
        $overallRowsPerSecond = count($allObjects) / max($totalImportTime, 0.001);

        // Calculate efficiency.
        $efficiency = 0;
        if ($summary['found'] > 0) {
            $efficiency = round((count($allObjects) / $summary['found']) * 100, 1);
        }

        // ADD PERFORMANCE METRICS: Include timing and speed metrics like SaveObjects does.
        $summary['performance'] = [
            'totalTime'        => round($totalImportTime, 3),
            'totalTimeMs'      => round($totalImportTime * 1000, 2),
            'objectsPerSecond' => round($overallRowsPerSecond, 2),
            'totalProcessed'   => count($allObjects),
            'totalFound'       => $summary['found'],
            'efficiency'       => $efficiency,
        ];

        return $summary;
    }//end processCsvSheet()

    /**
     * Format one MappingEngine row error into the shape `serializeErrorsToCsv()`
     * and the controller's `summary['errors']` contract already expect
     * (`row`/`field`/`error`/`type`), so migration-pack mapping failures show
     * up in the same per-row error report as any other import error —
     * clearly labeled with the source column and transform that failed.
     *
     * @param array{row: int, source: string, target: ?string, transform: ?string, message: string} $error One MappingEngine error entry.
     *
     * @return array{row: int, field: string, error: string, type: string, original_value: string}
     *
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    private function formatMappingError(array $error): array
    {
        $label = 'source column "'.$error['source'].'"';
        if ($error['transform'] !== null) {
            $label .= ' (transform: '.$error['transform'].')';
        }

        return [
            'row'            => $error['row'],
            'field'          => $error['target'] ?? $error['source'],
            'error'          => '[migration-pack] '.$error['message'].' — '.$label,
            'type'           => 'MigrationPackMappingError',
            'original_value' => $error['source'],
        ];
    }//end formatMappingError()

    /**
     * Build a dry-run summary: every mapped row is validated against the
     * target schema via `ValidateObject::validateObject()` (read-only —
     * schema resolution + uniqueness SELECTs, no writes) and NOTHING is
     * saved. `created`/`updated`/`unchanged` stay empty since no object was
     * actually persisted; `rows` carries the per-row valid/invalid verdict
     * migration quoting needs.
     *
     * @param array<string, mixed> $summary   The summary accumulated so far (found/errors/etc).
     * @param array<int, array>    $objects   The mapped+type-coerced objects that would be saved.
     * @param Schema               $schema    The target schema to validate against.
     * @param float                $startTime `microtime(true)` at the start of processing, for performance metrics.
     *
     * @return array<string, mixed> The dry-run summary.
     *
     * @spec openspec/specs/migration-mapping-packs/spec.md#dry-run
     */
    private function buildDryRunSummary(array $summary, array $objects, Schema $schema, float $startTime): array
    {
        $summary['dryRun']      = true;
        $summary['rows']        = [];
        $summary['validRows']   = 0;
        $summary['invalidRows'] = 0;

        foreach ($objects as $index => $object) {
            $result  = $this->validateObjectHandler->validateObject(object: $object, schema: $schema);
            $isValid = $result->isValid();

            if ($isValid === true) {
                $summary['validRows']++;
            } else {
                $summary['invalidRows']++;
            }

            $rowErrors = [];
            if ($isValid === false) {
                $rowErrors = [$this->validateObjectHandler->generateErrorMessage(result: $result)];
            }

            $summary['rows'][] = [
                'index'   => $index,
                'valid'   => $isValid,
                'errors'  => $rowErrors,
                'preview' => $object,
            ];
        }//end foreach

        $totalTime = microtime(true) - $startTime;
        $summary['performance'] = [
            'totalTime'      => round($totalTime, 3),
            'totalTimeMs'    => round($totalTime * 1000, 2),
            'totalProcessed' => count($objects),
            'totalFound'     => $summary['found'],
        ];

        return $summary;
    }//end buildDryRunSummary()

    /**
     * Transform CSV row data to object format for batch saving
     *
     * @param array      $rowData     Row data from CSV
     * @param Register   $register    The register
     * @param Schema     $schema      The schema
     * @param IUser|null $currentUser The current user performing the import
     *
     * @return ((int|mixed|string)[]|mixed)[]
     *
     * @psalm-return array{'@self': array<string, int|mixed|string>,...}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Row transformation requires many type-specific branches
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple column types and transformations create execution paths
     */
    private function transformCsvRowToObject(
        array $rowData,
        Register $register,
        Schema $schema,
        ?IUser $currentUser=null
    ): array {
        // Use instance cache instead of static to prevent issues between requests.
        $schemaId = $schema->getId();
        // Ensure schemaId is string for array key.
        $schemaIdKey = (string) $schemaId;

        if (isset($this->schemaPropertiesCache[$schemaIdKey]) === false) {
            $properties = $schema->getProperties();
            $this->schemaPropertiesCache[$schemaIdKey] = $properties ?? [];
        }

        $schemaProperties = $this->schemaPropertiesCache[$schemaIdKey];

        // Pre-allocate arrays for better performance.
        $objectData = [];
        $selfData   = [
            'register' => $register->getId(),
            'schema'   => $schemaId,
        ];

        // Single pass through row data with proper column filtering.
        $isAdmin = $this->isUserAdmin(user: $currentUser);

        // Debug log to verify admin status.
        $importUsername = 'null';
        if ($currentUser !== null) {
            $importUsername = $currentUser->getUID();
        }

        $this->logger->debug(
                message: '[ImportService] Processing CSV row',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'isAdmin'  => $isAdmin,
                    'username' => $importUsername,
                ]
                );

        // Translatable-property pre-pass (register-i18n Phase 3 wire-in):
        // turn flat `field_lang` columns into the nested `field: {lang: value}`
        // shape the rest of the save pipeline expects. The codec is
        // tolerant of empty cells and unrelated underscore-suffixed
        // columns; the rest of this loop sees the un-flattened shape.
        $rowData = $this->translationCsvCodec->unflattenFromCsv($rowData, $schema);

        foreach ($rowData as $key => $value) {
            // Skip empty values early.
            if ($value === null || $value === '') {
                continue;
            }

            // Ensure $key is a string before accessing as array.
            $keyString = (string) $key;
            if (is_string($key) === true) {
                $keyString = $key;
            }

            $firstChar = $keyString[0] ?? '';

            if ($firstChar === '_') {
                // REQUIREMENT: Columns starting with _ are completely ignored.
                continue;
            } else if ($firstChar === '@') {
                // REQUIREMENT: @ columns only processed if user is admin.
                if ($isAdmin === false) {
                    continue;
                    // Skip @ columns for non-admin users.
                }

                if (str_starts_with($key, '@self.') === true) {
                    // Move properties starting with @self. to @self array and remove the @self. prefix.
                    $selfPropertyName = substr($key, 6);

                    // Transform special @self properties.
                    $selfData[$selfPropertyName] = $this->transformSelfProperty(
                        propertyName: $selfPropertyName,
                        value: $value
                    );
                }

                // Note: Other @ columns that don't start with @self. are ignored.
                continue;
            }//end if

            // Regular properties - transform based on schema if needed.
            $objectData[$key]  = $value;
            $hasSchemaProperty = ($schemaProperties[$key] ?? null) !== null;
            if (is_array($schemaProperties) === true && $hasSchemaProperty === true) {
                $objectData[$key] = $this->transformValueByType(value: $value, propertyDef: $schemaProperties[$key]);
            }
        }//end foreach

        // Add ID if present in the data (for updates) - check once at the end.
        if (empty($rowData['id']) === false) {
            $selfData['id'] = $rowData['id'];
        }

        // Add @self array to object data.
        $objectData['@self'] = $selfData;

        // Validate that we're not accidentally creating invalid properties.
        $this->validateObjectProperties(objectData: $objectData, _schemaId: (string) $schemaId);

        return $objectData;
    }//end transformCsvRowToObject()

    /**
     * Transform datetime values from various formats to MySQL datetime format
     *
     * @param string $value The datetime value to transform
     *
     * @return string The transformed datetime value in MySQL format
     */
    private function transformDateTimeValue(string $value): string
    {
        // Early return if already in MySQL datetime format (Y-m-d H:i:s).
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        // Handle ISO 8601 format with timezone (e.g., "2025-01-01T00:00:00+00:00").
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
            try {
                $dateTime = new DateTime($value);
                // BUG-SVC-5: normalise to UTC before stripping the offset, so
                // an offset-bearing timestamp persists the correct instant
                // instead of its local wall-clock reading (e.g. +05:00 input
                // must shift back five hours, not just drop the offset).
                $dateTime->setTimezone(new \DateTimeZone('UTC'));
                return $dateTime->format(format: 'Y-m-d H:i:s');
            } catch (Exception $e) {
                // Fallback to original value if parsing fails.
                return $value;
            }
        }

        // Handle ISO 8601 format without timezone (e.g., "2025-01-01T00:00:00").
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            try {
                $dateTime = new DateTime($value);
                return $dateTime->format(format: 'Y-m-d H:i:s');
            } catch (Exception $e) {
                // Fallback to original value if parsing fails.
                return $value;
            }
        }

        // Handle date-only format (e.g., "2025-01-01").
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value.' 00:00:00';
        }

        // Return original value if no transformation needed.
        return $value;
    }//end transformDateTimeValue()

    /**
     * Transform @self properties based on their type
     *
     * @param string $propertyName The name of the @self property
     * @param string $value        The value to transform
     *
     * @return string The transformed value
     */
    private function transformSelfProperty(string $propertyName, string $value): string
    {
        // Transform datetime properties to MySQL datetime format.
        if (in_array($propertyName, ['published', 'created', 'updated'], true) === true) {
            return $this->transformDateTimeValue(value: $value);
        }

        // Transform organisation property - ensure it's a valid UUID.
        if ($propertyName === 'organisation') {
            // Validate UUID format.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === true) {
                return $value;
            }

            // If not a valid UUID, return as-is (might be a slug that needs resolution).
            return $value;
        }

        // Return original value for other properties.
        return $value;
    }//end transformSelfProperty()

    /**
     * Transform Excel row data to object format for batch saving
     *
     * @param array         $rowData     Row data from Excel
     * @param Register|null $register    Optional register
     * @param Schema|null   $schema      Optional schema
     * @param IUser|null    $currentUser The current user performing the import
     *
     * @return array<string, mixed>|null Object data or null if transformation fails
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Excel row transformation requires many type-specific branches
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple column types and transformations create execution paths
     */
    private function transformExcelRowToObject(
        array $rowData,
        ?Register $register,
        ?Schema $schema,
        ?IUser $currentUser=null
    ): ?array {
        // Separate regular properties from system properties.
        $objectData = [];
        $selfData   = [];

        // Check if current user is admin for column filtering.
        $isAdmin = $this->isUserAdmin(user: $currentUser);

        foreach ($rowData as $key => $value) {
            // Skip empty values.
            if ($value === null || $value === '') {
                continue;
            }

            if (str_starts_with($key, '_') === true) {
                // REQUIREMENT: Columns starting with _ are completely ignored.
                continue;
            } else if (str_starts_with($key, '@') === true) {
                // REQUIREMENT: @ columns only processed if user is admin.
                if ($isAdmin === false) {
                    continue;
                    // Skip @ columns for non-admin users.
                }

                if (str_starts_with($key, '@self.') === true) {
                    // Move properties starting with @self. to @self array and remove the @self. prefix.
                    $selfPropertyName = substr($key, 6);

                    // Transform special @self properties.
                    $selfData[$selfPropertyName] = $this->transformSelfProperty(
                        propertyName: $selfPropertyName,
                        value: $value
                    );
                }

                // Note: Other @ columns that don't start with @self. are ignored.
                continue;
            }//end if

            // Regular properties go to main object data.
            $objectData[$key] = $value;
        }//end foreach

        // Build @self section with metadata if available.
        if ($register !== null) {
            $selfData['register'] = $register->getId();
        }

        if ($schema !== null) {
            $selfData['schema'] = $schema->getId();
        }

        // Add ID if present in the data (for updates).
        if (($rowData['id'] ?? null) !== null && empty($rowData['id']) === false) {
            $selfData['id'] = $rowData['id'];
        }

        // Add @self array to object data if we have self properties.
        if (empty($selfData) === false) {
            $objectData['@self'] = $selfData;
        }

        // Transform object data based on schema property types if schema is available.
        $transformedData = $objectData;
        if ($schema !== null) {
            $transformedData = $this->transformObjectBySchema(objectData: $objectData, schema: $schema);
        }

        return $transformedData;
    }//end transformExcelRowToObject()

    /**
     * Build column mapping from spreadsheet headers
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet The worksheet
     *
     * @return array<string, string> Column mapping (column letter -> column name)
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Coordinate::stringFromColumnIndex is standard PhpSpreadsheet pattern
     */
    private function buildColumnMapping(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $columnMapping = [];
        // Column letter -> column name.
        $columnIndex = 1;

        // Use PhpSpreadsheet built-in method to get column letters.
        while ($columnIndex <= 50) {
            // Check up to 50 columns.
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $cellValue    = $sheet->getCell($columnLetter.'1')->getValue();

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
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet
     * @param array<string, string>                         $columnMapping Column mapping
     * @param int                                           $row           Row number
     *
     * @return string[]
     *
     * @psalm-return array<string, string>
     */
    private function extractRowData(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $columnMapping,
        int $row
    ): array {
        $rowData = [];
        // Name -> value.
        $hasData = false;

        // Loop through each column in the mapping.
        foreach ($columnMapping as $columnLetter => $columnName) {
            $cellValue = $sheet->getCell($columnLetter.$row)->getValue();

            // Convert cell value to string and trim whitespace.
            $cleanCellValue = '';
            if ($cellValue !== null) {
                $cleanCellValue = trim((string) $cellValue);
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
    private function getSchemaBySlug(string $slug): Schema
    {
        // NO ERROR SUPPRESSION: Let schema lookup errors bubble up immediately!
        $schema = $this->schemaMapper->find($slug);
        return $schema;
    }//end getSchemaBySlug()

    /**
     * Transform object data based on schema property definitions
     *
     * This method transforms string values from Excel to the expected types defined in the schema.
     * It handles type conversion for integers, numbers, booleans, arrays, and objects.
     *
     * @param array  $objectData The object data to transform
     * @param Schema $schema     The schema containing property definitions
     *
     * @return array The transformed object data
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return   array<string, mixed>
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-10
     */
    private function transformObjectBySchema(array $objectData, Schema $schema): array
    {
        // NO ERROR SUPPRESSION: Let schema transformation errors bubble up immediately!
        $schemaProperties = $schema->getProperties();
        $transformedData  = [];

        foreach ($objectData as $propertyName => $value) {
            // Skip @self array - it's handled separately.
            if ($propertyName === '@self') {
                $transformedData[$propertyName] = $value;
                continue;
            }

            // Get property definition from schema.
            $propertyDef = $schemaProperties[$propertyName] ?? null;

            if ($propertyDef === null) {
                // Property not in schema, keep as is.
                $transformedData[$propertyName] = $value;
                continue;
            }

            // Transform based on type.
            $transformedData[$propertyName] = $this->transformValueByType(value: $value, propertyDef: $propertyDef);
        }

        return $transformedData;
    }//end transformObjectBySchema()

    /**
     * Transform a value based on its property definition type
     *
     * @param mixed $value       The value to transform
     * @param array $propertyDef The property definition from the schema
     *
     * @return mixed The transformed value
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Type transformation switch requires branches for each data type
     * @SuppressWarnings(PHPMD.StaticAccess)         ObjectHandling::relates() is the established enum-style helper idiom
     */
    private function transformValueByType($value, array $propertyDef)
    {
        // If value is empty or null, return as is.
        if ($value === null || $value === '') {
            return $value;
        }

        $type = $propertyDef['type'] ?? 'string';

        switch ($type) {
            case 'integer':
                return (int) $value;

            case 'number':
                return (float) $value;

            case 'boolean':
                return $this->stringToBoolean(value: $value);

            case 'array':
                return $this->stringToArray(value: $value);

            case 'object':
                // Check if this is a related-object that should store UUID strings directly.
                if (($propertyDef['objectConfiguration']['handling'] ?? null) !== null
                    && ObjectHandling::relates($propertyDef['objectConfiguration']['handling']) === true
                ) {
                    // For related objects, store UUID strings directly instead of wrapping in objects.
                    return (string) $value;
                }
                return $this->stringToObject(value: $value);

            default:
                return (string) $value;
        }//end switch
    }//end transformValueByType()

    /**
     * Convert string to boolean
     *
     * @param mixed $value The value to convert
     *
     * @return bool The boolean value
     */
    private function stringToBoolean($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['true', '1', 'yes', 'on', 'enabled']);
    }//end stringToBoolean()

    /**
     * Convert string to object
     *
     * @param mixed $value The value to convert
     *
     * @return array|object The object value
     */
    private function stringToObject($value)
    {
        if (is_array($value) === true || is_object($value) === true) {
            return $value;
        }

        $value = trim((string) $value);

        // Try to parse as JSON first.
        if (str_starts_with($value, '{') === true && str_ends_with($value, '}') === true) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // If not JSON, return as single-key object.
        return ['value' => $value];
    }//end stringToObject()

    /**
     * Convert string to array handling multiple formats
     *
     * This method handles various array formats:
     * - Comma-separated: 1,2,3
     * - Quoted comma-separated: "1","2","3"
     * - JSON arrays: ["1","2","3"]
     * - Mixed formats
     *
     * @param mixed $value The value to convert
     *
     * @return array The array value
     *
     * @phpstan-return array<int|string, mixed>
     * @psalm-return   array<int|string, mixed>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Array parsing requires branches for JSON, CSV, quoted formats
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple array format detection paths needed
     */
    private function stringToArray($value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_string($value) === false) {
            return [$value];
        }

        $value = trim($value);

        // Empty string returns empty array.
        if ($value === '') {
            return [];
        }

        // Try JSON first.
        if (str_starts_with($value, '[') === true && str_ends_with($value, ']') === true) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
                return $decoded;
            }
        }

        // Handle comma-separated values.
        if (str_contains($value, ',') === true) {
            $parts  = explode(',', $value);
            $result = [];

            foreach ($parts as $part) {
                $part = trim($part);

                // Remove surrounding quotes.
                if ((str_starts_with($part, '"') === true && str_ends_with($part, '"') === true)
                    || (str_starts_with($part, "'") === true && str_ends_with($part, "'") === true)
                ) {
                    $part = substr($part, 1, -1);
                }

                $result[] = $part;
            }

            return $result;
        }

        // Single value - return as array with one element.
        return [$value];
    }//end stringToArray()

    /**
     * Clear all internal caches to prevent issues between imports
     *
     * @return void
     *
     * @spec exclude One-line reset of the internal schema-properties cache; no business logic.
     */
    public function clearCaches(): void
    {
        $this->schemaPropertiesCache = [];
    }//end clearCaches()

    /**
     * Validate that object data only contains valid ObjectEntity properties
     *
     * @param array  $objectData The object data to validate
     * @param string $_schemaId  Schema ID for debugging (unused, for future use)
     *
     * @return void
     */
    private function validateObjectProperties(array $objectData, string $_schemaId): void
    {
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/data-import-export/tasks.md#task-error-csv
     */
    public function serializeErrorsToCsv(array $summary): string
    {
        $errors = [];

        foreach ($summary as $sheetTitle => $sheetData) {
            if (is_array($sheetData) === false) {
                continue;
            }

            $sheetErrors = $sheetData['errors'] ?? [];
            if (is_array($sheetErrors) === false || count($sheetErrors) === 0) {
                continue;
            }

            foreach ($sheetErrors as $error) {
                if (is_array($error) === false) {
                    $error = ['error' => (string) $error];
                }

                $originalValue = ($error['object'] ?? ($error['original_value'] ?? ''));
                $errors[]      = [
                    'sheet'          => (string) ($error['sheet'] ?? $sheetTitle),
                    'row'            => (string) ($error['row'] ?? ''),
                    'field'          => (string) ($error['field'] ?? ($error['type'] ?? '')),
                    'error_message'  => (string) ($error['error'] ?? ''),
                    'original_value' => $this->stringifyOriginalValue(value: $originalValue),
                ];
            }//end foreach
        }//end foreach

        if (count($errors) === 0) {
            return '';
        }

        $headers = ['sheet', 'row', 'field', 'error_message', 'original_value'];

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }

        // Excel needs the UTF-8 BOM to detect encoding.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers);

        foreach ($errors as $row) {
            fputcsv(
                $stream,
                [
                    $row['sheet'],
                    $row['row'],
                    $row['field'],
                    $row['error_message'],
                    $row['original_value'],
                ]
            );
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            return '';
        }

        return $csv;

    }//end serializeErrorsToCsv()

    /**
     * Stringify the `original_value` carried by an error entry.
     *
     * Errors collected by the import pipeline embed either a row-level scalar
     * (e.g. a malformed cell), an associative array (the parsed object that
     * failed validation), or nothing at all. A single CSV cell needs a stable
     * string projection of all three.
     *
     * @param mixed $value The raw value lifted off the error envelope.
     *
     * @return string Compact string representation suitable for a CSV cell.
     */
    private function stringifyOriginalValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_scalar($value) === true) {
            return (string) $value;
        }

        if (is_array($value) === true || is_object($value) === true) {
            $encoded = json_encode($value, (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($encoded === false) {
                return '';
            }

            return $encoded;
        }

        return '';

    }//end stringifyOriginalValue()
}//end class
