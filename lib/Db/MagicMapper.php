<?php

/**
 * MagicMapper - Dynamic Schema-Based Table Management
 *
 * This mapper provides dynamic table creation and management based on JSON schema objects.
 * It creates dedicated tables for each schema, providing better performance and organization
 * compared to storing all objects in a single generic table.
 *
 * Key Features:
 * - Dynamic table creation based on JSON schema properties
 * - Automatic table updates when schemas change
 * - Schema-specific search and retrieval operations
 * - Integration with existing ObjectEntity metadata system
 * - Support for all major database systems (MySQL, MariaDB, PostgreSQL)
 *
 * Table Structure:
 * - Table naming: oc_openregister_table_[schema_slug]
 * - Metadata columns from ObjectEntity prefixed with underscore (_)
 * - Schema property columns mapped from JSON schema to SQL types
 * - Automatic indexing for performance optimization
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12.
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Service\Audit\PurposeGuardInterface;
use OCA\OpenRegister\Db\MagicMapper\PurposeBoundTables;
use DateTime;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait;
use OCA\OpenRegister\Db\MagicMapper\MagicDroppedProperties;
use OCA\OpenRegister\Db\MagicMapper\MagicFlaggedEncryption;
use OCA\OpenRegister\Db\MagicMapper\MagicPropertyColumns;
use OCA\OpenRegister\Db\MagicMapper\MagicRowMetadata;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnSql;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicTableCreator;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumns;
use OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns;
use RuntimeException;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkSaveGroup;
use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableHit;
use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableScan;
use OCA\OpenRegister\Db\MagicMapper\MagicIdentifierProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicRowWrite;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectLookup;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn;
use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeMembership;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSequentialOrder;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableSql;
use OCA\OpenRegister\Db\MagicMapper\MagicTableTarget;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\MagicMapper\MagicUnionArm;
use OCA\OpenRegister\Db\MagicMapper\MagicIdentifierFind;
use OCA\OpenRegister\Db\MagicMapper\MagicPaginatedSearch;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchRequest;
use OCA\OpenRegister\Db\MagicMapper\LinkedEntityRef;
use OCA\OpenRegister\Db\MagicMapper\FolderRecord;
use OCA\OpenRegister\Db\MagicMapper\MagicUpsertBatch;
use OCA\OpenRegister\Db\MagicMapper\RelationBatchLookup;
use OCA\OpenRegister\Db\MagicMapper\UuidBatchLookup;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectChange;
use OCA\OpenRegister\Db\MagicMapper\MagicRelationSql;
use OCA\OpenRegister\Db\MagicMapper\MagicSlugBackfill;
use OCA\OpenRegister\Db\MagicMapper\MagicSlugTable;
use OCA\OpenRegister\Db\MagicMapper\SlugBackfillCursor;
use OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder;
use OCA\OpenRegister\Db\MagicMapper\MagicUnionPage;
use OCA\OpenRegister\Db\MagicMapper\MagicUnionScore;
use OCA\OpenRegister\Db\MagicMapper\MultiSchemaSearch;
use OCA\OpenRegister\Db\MagicMapper\RecentLensOrder;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnAdder;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\MagicMapper\MagicTableAlterer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableColumnReader;
use OCA\OpenRegister\Db\MagicMapper\TableStructureSync;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\DraftAlreadyLeftException;
use OCA\OpenRegister\Exception\PropertyAlreadySetException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowLookups;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Support\OrderParameter;
use OCA\OpenRegister\Support\QueryLimit;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugTargetInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;

/**
 * Dynamic Schema-Based Table Management Service
 *
 * ARCHITECTURAL OVERVIEW:
 * This service implements dynamic table creation and management based on JSON schema definitions.
 * Instead of storing all objects in a single generic table, it creates dedicated tables for each
 * schema, providing better performance, cleaner data organization, and schema-specific optimizations.
 *
 * KEY RESPONSIBILITIES:
 * - Dynamic table creation from JSON schema definitions
 * - Automatic table schema updates when JSON schemas change
 * - Schema-to-SQL type mapping with validation
 * - High-performance search within schema-specific tables
 * - Integration with existing ObjectEntity metadata system
 * - Support for complex relationships and references
 *
 * TABLE DESIGN:
 * - Naming Convention: oc_openregister_table_{schema_slug}
 * - Metadata Columns: All ObjectEntity properties prefixed with underscore (_)
 * - Schema Columns: JSON schema properties mapped to appropriate SQL types
 * - Indexes: Automatic creation for performance optimization
 *
 * PERFORMANCE BENEFITS:
 * - Reduced table size (schema-specific vs. generic)
 * - Better indexing strategies (schema-aware indexes)
 * - Faster queries (no schema filtering needed)
 * - Optimized storage (appropriate column types vs. JSON)
 * - Better database statistics and query planning
 *
 * DATABASE COMPATIBILITY:
 * - MySQL/MariaDB: Full support with optimized column types
 * - PostgreSQL: Full support with JSONB for complex objects
 * - SQLite: Basic support for development environments
 *
 * @psalm-type SchemaPropertyConfig = array{
 *     type: string,
 *     format?: string,
 *     items?: array,
 *     properties?: array,
 *     required?: array<string>,
 *     maxLength?: int,
 *     minLength?: int,
 *     maximum?: int,
 *     minimum?: int
 * }
 *
 * @psalm-type TableColumnConfig = array{
 *     name: string,
 *     type: string,
 *     length?: int,
 *     nullable: bool,
 *     default?: mixed,
 *     index?: bool,
 *     unique?: bool,
 *     precision?: int,
 *     scale?: int,
 *     autoincrement?: bool,
 *     primary?: bool
 * }
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class MagicMapper extends AbstractObjectMapper implements MagicMapperInterface {
	use MagicColumnNameTrait;

	/**
	 * Hard ceiling on a NUMERIC page size, per the objects-crud requirement
	 * "List page size is bounded by a hard maximum".
	 *
	 * A client asking for `_limit=1000000` is not making a considered request,
	 * so it is reduced to this. A client asking for `_limit=false` IS making
	 * one, and that path skips the clause entirely — see the LIMIT/OFFSET block
	 * in the UNION query and {@see \OCA\OpenRegister\Support\QueryLimit}.
	 *
	 * Previously spelled as the literal `1000` inside `min()`, which made it
	 * invisible to anything that wanted to state or test the bound.
	 *
	 * @var int
	 */
	private const MAX_PAGE_SIZE = 1000;

	/**
	 * Metadata column prefix to avoid conflicts with schema properties
	 */
	private const METADATA_PREFIX = '_';

	/**
	 * Whether the last single-object write INSERTED or UPDATED.
	 *
	 * The mapper is the only layer that knows. `SaveObject` performs its own
	 * existence lookup and labels the operation from that — but the mapper then
	 * looks again, and between the two lookups a concurrent writer can land the
	 * row, so the mapper takes the UPDATE branch on an operation the service
	 * has already called a create. The audit trail then records `create` for a
	 * write that updated somebody else's row.
	 *
	 * Measured while debugging #2212: three audit `create` entries against a
	 * single `_id` that was inserted once. The audit trail said three objects
	 * were created; one was.
	 *
	 * Read it with {@see getLastWriteAction()} immediately after the write.
	 * Per-request state on a per-request service, in the same spirit as the
	 * `lastRun*` fields elsewhere in the fleet — it is a report of what just
	 * happened, not a cache.
	 *
	 * @var string One of `create` or `update`; empty before any write.
	 */
	private string $lastWriteAction = '';

	/**
	 * The stored row the last insert() replaced because its identifier was taken, null when it created.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $replacedByLastInsert = null;

	/**
	 * Writes that may only land on a live row, by uuid, with the refusal to throw when they cannot.
	 *
	 * A refused create that is carried out as an update of the stored row may not
	 * bring a deleted object back, also when the delete lands after the check, for
	 * instance in an ObjectUpdatingEvent listener. While a uuid is listed here, the
	 * table write updates only a row whose `_deleted` is empty and never inserts.
	 *
	 * @var array<string, HookStoppedException>
	 */
	private array $liveRowOnlyWrites = [];

	/**
	 * Number of magic tables probed per UNION ALL query in the locate phase.
	 *
	 * `findAcrossAllMagicTables` used to issue one `SELECT *` per magic table,
	 * which on a live instance with ~1,000 live tables meant ~1,000 queries for
	 * a SINGLE bare-identifier lookup. The locate phase now probes tables in
	 * chunks of this size with one narrow UNION ALL query per chunk.
	 *
	 * Why 100:
	 * - Bounds the SQL text (~100 branches, ~20KB) and the bind-parameter count
	 *   (4 per branch = 400), far below the PostgreSQL 65535-parameter limit and
	 *   any practical MySQL packet limit.
	 * - Bounds the blast radius of a structurally broken table: a chunk whose
	 *   UNION fails degrades to a per-table probe for THAT chunk only (100
	 *   queries worst case) instead of poisoning the whole scan.
	 * - Larger chunks buy little: 1,000 tables need 10 queries at 100/chunk vs
	 *   2 at 500/chunk, while quadrupling the fallback cost and the SQL size.
	 */
	private const MAGIC_TABLE_SCAN_CHUNK_SIZE = 100;

	/**
	 * Rows the identifier lookup reads at most (key matches first, then id order).
	 */
	private const IDENTIFIER_MATCH_LIMIT = 50;

	/**
	 * Request-scoped memo for {@see checkTableExistsInDatabase()}.
	 *
	 * Whether a magic table exists changes only when a register/schema pair is
	 * created or dropped, which cannot happen concurrently with the request that
	 * asks. Measured 2026-07-30: an object UPDATE issued 27
	 * `information_schema.tables` probes and a DELETE 27, against 9 for a
	 * create — all asking the same handful of questions repeatedly.
	 *
	 * @var array<string, boolean>
	 */
	private array $tableExistsMemo = [];

	/**
	 * Request-scoped memo for {@see getLiveMagicTables()}.
	 *
	 * That method lists EVERY magic table from `information_schema` and then
	 * fetches the full register and schema id lists to discard orphans — on this
	 * instance, 2,728 tables. Measured 2026-07-30: an object UPDATE called it 12
	 * times, ~60ms of the request, for an answer that cannot change mid-request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $liveMagicTablesMemo = null;

	/**
	 * Cache timeout for table existence checks (5 minutes)
	 *
	 * @internal Used by MagicTableHandler
	 */
	public const TABLE_CACHE_TIMEOUT = 300;

	/**
	 * Maximum table name length (MySQL limit)
	 *
	 * @internal Used by MagicTableHandler
	 */
	public const MAX_TABLE_NAME_LENGTH = 64;

	/**
	 * Property-column budget for a cross-schema UNION projection.
	 *
	 * Postgres caps a SELECT target list at 1664 entries. Each UNION arm also
	 * projects ~30 metadata columns plus a couple of synthetic columns, so the
	 * property superset must stay well under 1664. When it exceeds this budget
	 * the cross-schema search falls back to a metadata-only projection so the
	 * query never trips SQLSTATE 54011 ("target lists can have at most 1664
	 * entries"), regardless of how many schemas are searched.
	 */
	private const UNION_PROPERTY_COLUMN_BUDGET = 1500;

	/**
	 * Maximum number of UNION arms in a single cross-schema statement.
	 *
	 * The column budget above bounds the WIDTH of one arm; this bounds their
	 * COUNT. A cross-schema search over every searchable schema on a large
	 * instance (measured: 1,272 schemas) is one statement with 1,272 arms,
	 * each carrying its own WHERE, its own score expression and its own bound
	 * parameters. That fails long before the database refuses it: MariaDB's
	 * default `max_allowed_packet` is 1 MiB and an arm is a few kilobytes of
	 * SQL, the planner cost grows with the arm count, and every arm's
	 * parameters land in the same statement.
	 *
	 * 50 matches the schema chunk the unified-search provider already applies
	 * (`ObjectsProvider::SCHEMA_CHUNK_SIZE`), so the two bounds agree instead
	 * of interacting. Pairs beyond one batch are searched in further batches
	 * and merged in PHP; see searchAcrossMultipleTablesWithUnion().
	 */
	private const UNION_ARM_BATCH_SIZE = 50;

	/**
	 * Cache for table existence to avoid repeated database queries
	 * Key format: 'registerId_schemaId' => timestamp
	 *
	 * @var array<string, int>
	 */
	private static array $tableExistsCache = [];

	/**
	 * Cache for register+schema table mappings
	 * Key format: 'registerId_schemaId' => 'table_name'
	 *
	 * @var array<string, string>
	 */
	private static array $regSchemaTableCache = [];

	/**
	 * Cache for table structure versions to detect schema changes
	 * Key format: 'registerId_schemaId' => 'version_hash'
	 *
	 * @var array<string, string>
	 */
	private static array $tableStructureCache = [];

	/**
	 * Cache for calculated schema versions (avoids recalculating MD5 hash)
	 * Key format: 'registerId_schemaId' => 'calculated_version_hash'
	 *
	 * @var array<string, string>
	 */
	private static array $calcVersionCache = [];

	/**
	 * PERF-12: request-scoped memo of the column→property map per schema id,
	 * used by rowToObjectEntity(). Without it the schema was re-resolved and
	 * the map rebuilt for every single row in fallback hydration.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $rowColumnToPropertyCache = [];

	/**
	 * Cache recording whether a register+schema table's columns have been verified
	 * in the current PHP process. Eliminates repeated information_schema queries
	 * on the happy path. Cleared by invalidateTableCache() and clearAllStaticCaches().
	 * Key format: 'registerId_schemaId' => true
	 *
	 * @var array<string, bool>
	 */
	private static array $tableColumnsVerifiedCache = [];

	/**
	 * Cache for column existence checks to avoid repeated information_schema queries.
	 * Key format: 'tableName' => ['column1' => true, 'column2' => true, ...]
	 *
	 * @var array<string, array<string, bool>>
	 */
	private static array $columnExistsCache = [];

	/**
	 * Lower-cased data_type per column, filled by the same information_schema
	 * query as $columnExistsCache (so it costs no extra query).
	 * Key format: 'tableName' => ['column1' => 'timestamp without time zone', ...]
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $columnTypeCache = [];

	/**
	 * Handler instances for specialized functionality
	 */

	/**
	 * Search handler for dynamic table operations
	 *
	 * @var MagicSearchHandler|null
	 */
	private ?MagicSearchHandler $searchHandler = null;

	/**
	 * RBAC handler for permission filtering
	 *
	 * @var MagicRbacHandler|null
	 */
	private ?MagicRbacHandler $rbacHandler = null;

	/**
	 * Bulk operations handler for high-performance operations
	 *
	 * @var MagicBulkHandler|null
	 */
	private ?MagicBulkHandler $bulkHandler = null;

	/**
	 * Organization handler for multi-tenancy support
	 *
	 * @var MagicOrganizationHandler|null
	 */
	private ?MagicOrganizationHandler $organizationHandler = null;

	/**
	 * Facet handler for aggregations and faceting
	 *
	 * @var MagicFacetHandler|null
	 */
	private ?MagicFacetHandler $facetHandler = null;

	/**
	 * Table management handler for table lifecycle operations
	 *
	 * @var MagicTableHandler|null
	 */
	private ?MagicTableHandler $tableHandler = null;

	/**
	 * Finds and adds the metadata columns a magic table lacks.
	 *
	 * Shared by the request path, the reconcile command and the repair step.
	 *
	 * @var MagicMetadataColumnHealer|null
	 */
	private ?MagicMetadataColumnHealer $metadataColumnHealer = null;

	/**
	 * Statistics handler for aggregations and chart data
	 *
	 * @var MagicStatisticsHandler|null
	 */
	private ?MagicStatisticsHandler $statisticsHandler = null;

	/**
	 * Answers once whether pg_trgm is available.
	 *
	 * @var MagicPgTrgmProbe|null
	 */
	private ?MagicPgTrgmProbe $pgTrgmProbe = null;

	/**
	 * Remembers the undeclared properties reported as discarded.
	 *
	 * MagicMapper is one shared service per process, so this lives for one
	 * request (or one occ/cron run).
	 *
	 * @var MagicDroppedProperties|null
	 */
	private ?MagicDroppedProperties $droppedProperties = null;

	/**
	 * Database connection for table operations.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Mapper for schema operations.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Mapper for register operations.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Container for lazy loading services.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * User session for authentication context.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager for RBAC operations.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * User manager for user operations.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * Nextcloud config for settings.
	 *
	 * @var IConfig
	 */
	private readonly IConfig $config;

	/**
	 * App configuration for feature flags.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Event dispatcher for audit trail events.
	 *
	 * @var IEventDispatcher
	 */
	private readonly IEventDispatcher $eventDispatcher;

	/**
	 * Logger for debugging and monitoring.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor for MagicMapper service.
	 *
	 * Initializes the service with required dependencies for database operations,
	 * schema and register management, configuration handling, logging, and specialized handlers.
	 *
	 * @param IDBConnection       $db       Database connection for table operations
	 * @param MagicMapperLookups  $lookups  Schema and register mappers and the container for lazy loading
	 * @param MagicMapperIdentity $identity User session, group manager and user manager for RBAC
	 * @param MagicMapperRuntime  $runtime  System and app config, event dispatcher and logger
	 */
	public function __construct(
		IDBConnection $db,
		MagicMapperLookups $lookups,
		MagicMapperIdentity $identity,
		MagicMapperRuntime $runtime,
	) {
		$this->db              = $db;
		$this->schemaMapper    = $lookups->schemaMapper;
		$this->registerMapper  = $lookups->registerMapper;
		$this->container       = $lookups->container;
		$this->userSession     = $identity->userSession;
		$this->groupManager    = $identity->groupManager;
		$this->userManager     = $identity->userManager;
		$this->config          = $runtime->config;
		$this->appConfig       = $runtime->appConfig;
		$this->eventDispatcher = $runtime->eventDispatcher;
		$this->logger          = $runtime->logger;

		// Initialize specialized handlers for modular functionality.
		//
		// The circular-construction guard that lived here previously (#1564)
		// was a holdover from a March 2026 refactor; the cycles it guarded
		// (CacheHandler→RegisterMapper→MagicMapper, SettingsService→MagicMapper)
		// were since broken via lazy container resolution + the
		// `objectMapper`-removed-from-SettingsService fix.
		// Verification trail for future readers (PR #1431 review minor):
		// - CacheHandler→RegisterMapper cycle: `CacheHandler` no longer
		// eagerly constructs a `RegisterMapper`; it resolves via its
		// injected `ContainerInterface $container` at first use.
		// - SettingsService→MagicMapper cycle: `SettingsService` no
		// longer carries the `$objectMapper` field that previously
		// transitively pulled in `MagicMapper` at construction.
		// Both paths can be re-verified by inspection:
		// git grep -nE 'new RegisterMapper|RegisterMapper \$' lib/Service/Object/CacheHandler.php
		// git grep -nE '\$objectMapper|MagicMapper \$' lib/Service/SettingsService.php
		// If a future change re-introduces either pattern, restoring a
		// static `$constructCount` is the documented escape hatch — see
		// issue #1564 for the original trigger conditions.
		$this->initializeHandlers();
	}//end __construct()

	/**
	 * Get the configured database table prefix.
	 *
	 * Reads `dbtableprefix` from the Nextcloud system config once per call,
	 * defaulting to `oc_`. An empty value stays empty: the connection applies
	 * the same value to every query builder table, so raw SQL must too.
	 *
	 * @return string The configured table prefix (e.g. `oc_`).
	 */
	private function getTablePrefix(): string {
		return (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
	}//end getTablePrefix()

	/**
	 * Build the full, prefixed table name for a bare magic table name.
	 *
	 * Uses the configured `dbtableprefix` rather than a hardcoded `oc_` so
	 * raw-SQL paths work on installs with a custom prefix (BUG-DB-3).
	 *
	 * @param string $tableName The bare table name (without prefix).
	 *
	 * @return string The full table name including the configured prefix.
	 */
	private function getFullTableName(string $tableName): string {
		return $this->getTablePrefix() . $tableName;
	}//end getFullTableName()

	/**
	 * Initialize specialized handler instances
	 *
	 * Creates instances of all MagicMapper handlers for modular functionality.
	 * Handlers are initialized lazily to improve performance and reduce memory usage.
	 *
	 * @return void
	 */
	private function initializeHandlers(): void {
		$this->initializeAccessHandlers();
		$this->initializeSearchHandler();
		$this->initializeAnalysisHandlers();
	}//end initializeHandlers()

	/**
	 * Build the metadata column healer, the RBAC handler and the organisation handler.
	 *
	 * @return void
	 */
	private function initializeAccessHandlers(): void {
		// Built first: the RBAC handler asks it which metadata columns a
		// degraded table still lacks, so the read predicate does not name a
		// `_status` column the table could not be given.
		$this->metadataColumnHealer = new MagicMetadataColumnHealer(
			sql: $this->tableSql(),
			magicMapper: $this,
			cache: $this->createMetadataColumnCache()
		);

		$this->rbacHandler = new MagicRbacHandler(
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			userManager: $this->userManager,
			appConfig: $this->appConfig,
			conditionMatcher: $this->container->get(\OCA\OpenRegister\Service\ConditionMatcher::class),
			container: $this->container,
			logger: $this->logger,
			metadataColumns: $this->metadataColumnHealer
		);

		$this->organizationHandler = new MagicOrganizationHandler(
			identity: new MagicMapperIdentity(userSession: $this->userSession, groupManager: $this->groupManager, userManager: $this->userManager),
			appConfig: $this->appConfig,
			container: $this->container,
			logger: $this->logger
		);
	}//end initializeAccessHandlers()

	/**
	 * Build the table handler and the search handler with its related-row applier.
	 *
	 * The table handler is built BEFORE the search handler because the
	 * related-row applier needs it, and the search handler needs the applier.
	 * The applier is assembled by hand rather than resolved from the container,
	 * for the same reason the cache handler above is not: the container would
	 * walk MagicMapper → applier → MagicTableHandler → MagicMapper and recurse.
	 * The applier also answers the property read question for the search
	 * handler's nested-path filters: a filter that reaches through a reference
	 * and one on a path inside an object property are the same oracle on a
	 * field the caller may not read.
	 *
	 * The search handler gets the request's language state, so a translatable
	 * property sorts by the value the response shows
	 * (order-filters-and-notification-links), and the request's reveal state:
	 * filtering or sorting on an explicit-reveal property is refused unless the
	 * code is trusted (property-explicit-reveal).
	 *
	 * @return void
	 */
	private function initializeSearchHandler(): void {
		$this->initializeTableHandler();
		$relatedRowApplier = $this->createRelatedRowApplier();
		$this->searchHandler = new MagicSearchHandler(
			db: $this->db,
			logger: $this->logger,
			rbacHandler: $this->rbacHandler,
			organizationHandler: $this->organizationHandler,
			schemaTypeConverter: $this->container->get(SchemaTypeConverterInterface::class),
			dateTimeNormalizer: $this->container->get(DateTimeNormalizerInterface::class),
			relatedRows: $relatedRowApplier,
			languageService: $this->container->get(\OCA\OpenRegister\Service\LanguageServiceInterface::class),
			metadataColumns: $this->metadataColumnHealer,
			explicitReveal: $this->container->get(\OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface::class)
		);
	}//end initializeSearchHandler()

	/**
	 * Build the table handler.
	 *
	 * It depends on nothing built later, so it can come before the search
	 * handler; the facet handler still comes after the search handler it
	 * consumes. The metadata-column healer is built before it, because the
	 * table handler asks it on every first use of a table.
	 *
	 * @return void
	 */
	private function initializeTableHandler(): void {
		$this->tableHandler = new MagicTableHandler(
			sql: $this->tableSql(),
			appConfig: $this->appConfig,
			magicMapper: $this,
			metadataColumns: $this->metadataColumnHealer
		);
	}//end initializeTableHandler()

	/**
	 * Assemble the related-row applier from the RBAC and table handlers.
	 *
	 * @return RelatedRowQueryApplier
	 */
	private function createRelatedRowApplier(): RelatedRowQueryApplier {
		$relatedRowAccess = new RelatedRowAccess(
			rbac: $this->rbacHandler,
			logger: $this->logger,
			guard: new PropertyReadGuard(container: $this->container, logger: $this->logger)
		);
		return new RelatedRowQueryApplier(
			lookups: new RelatedRowLookups(schemaMapper: $this->schemaMapper, tableHandler: $this->tableHandler),
			db: $this->db,
			logger: $this->logger,
			access: $relatedRowAccess
		);
	}//end createRelatedRowApplier()

	/**
	 * Build the bulk, facet and statistics handlers.
	 *
	 * @return void
	 */
	private function initializeAnalysisHandlers(): void {
		$this->bulkHandler = new MagicBulkHandler(
			sql: $this->tableSql(),
			eventDispatcher: $this->eventDispatcher,
			dateTimeNormalizer: $this->container->get(DateTimeNormalizerInterface::class)
		);

		$this->initializeFacetHandler();
		$this->initializeStatisticsHandler();
	}//end initializeAnalysisHandlers()

	/**
	 * Build the facet handler on the search handler it consumes.
	 *
	 * CacheHandler and ICacheFactory are resolved lazily via container to
	 * avoid circular DI: MagicMapper → CacheHandler → RegisterMapper → MagicMapper.
	 *
	 * @return void
	 */
	private function initializeFacetHandler(): void {
		$this->facetHandler = new MagicFacetHandler(
			db: $this->db,
			logger: $this->logger,
			searchHandler: $this->searchHandler,
			container: $this->container
		);
	}//end initializeFacetHandler()

	/**
	 * Build the statistics handler and give it the count callback.
	 *
	 * @return void
	 */
	private function initializeStatisticsHandler(): void {
		$this->statisticsHandler = new MagicStatisticsHandler(
			sql: $this->tableSql(),
			catalog: new SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this),
			dateTimeNormalizer: $this->container->get(DateTimeNormalizerInterface::class),
			schemaTypeConverter: $this->container->get(SchemaTypeConverterInterface::class)
		);

		$this->wireStatisticsCountCallback();
	}//end initializeStatisticsHandler()

	/**
	 * Give the statistics handler its count callback.
	 *
	 * Setter injection, to avoid a circular dependency on this mapper.
	 *
	 * @return void
	 */
	private function wireStatisticsCountCallback(): void {
		$this->statisticsHandler->setCountCallback(
			function (array $query, Register $register, Schema $schema): int {
				return $this->countObjectsInRegisterSchemaTable(
					query: $query,
					register: $register,
					schema: $schema
				);
			}
		);
	}//end wireStatisticsCountCallback()

	/**
	 * What the magic-table handlers need to run hand-built SQL.
	 *
	 * @return MagicTableSql The connection, the system config and the logger.
	 */
	private function tableSql(): MagicTableSql {
		return new MagicTableSql(db: $this->db, config: $this->config, logger: $this->logger);
	}//end tableSql()

	/**
	 * The distributed cache the metadata-column healer shares across requests.
	 *
	 * Resolved from the container rather than injected, like the facet cache,
	 * so the constructor signature every caller and test uses stays the same.
	 *
	 * @return \OCP\ICache|null The cache, or null when none can be made.
	 */
	private function createMetadataColumnCache(): ?\OCP\ICache {
		try {
			$factory = $this->container->get(ICacheFactory::class);
			if ($factory instanceof ICacheFactory === false) {
				return null;
			}

			return $factory->createDistributed('openregister_magic_metadata');
		} catch (\Throwable $e) {
			return null;
		}
	}//end createMetadataColumnCache()

	/**
	 * The metadata column definitions every magic table carries.
	 *
	 * @return array<string, array> Column definitions keyed by column name.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function getMetadataColumnDefinitions(): array {
		return MagicMetadataColumns::ALL;
	}//end getMetadataColumnDefinitions()

	/**
	 * Render the statement that adds one column to an existing magic table.
	 *
	 * The column is added nullable unless it has a default, because the table
	 * may already hold rows (see addMissingColumns()). On PostgreSQL it carries
	 * IF NOT EXISTS, so two requests adding the same column both succeed.
	 *
	 * @param string $tableName  Bare magic table name.
	 * @param array  $definition Column definition as MagicMetadataColumns::ALL declares it.
	 *
	 * @return string The ALTER TABLE statement.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function buildAddColumnSql(string $tableName, array $definition): string {
		$platform = $this->db->getDatabasePlatform();
		$isPostgres = ($platform instanceof PostgreSQLPlatform);

		$table = $this->quoteIdentifier(name: $this->getFullTableName(tableName: $tableName), isPostgres: $isPostgres);
		$column = $this->quoteIdentifier(name: (string)$definition['name'], isPostgres: $isPostgres);
		$type = $this->mapColumnTypeToSQL(type: (string)$definition['type'], column: $definition);

		$ifNotExists = '';
		if ($isPostgres === true) {
			$ifNotExists = 'IF NOT EXISTS ';
		}

		$sql = 'ALTER TABLE '.$table.' ADD COLUMN '.$ifNotExists.$column.' '.$type;
		if (isset($definition['default']) === true) {
			$sql .= ' DEFAULT '.$this->formatDefaultValueForSQL(default: $definition['default']);
			if (($definition['nullable'] ?? true) === false) {
				$sql .= ' NOT NULL';
			}
		}

		return $sql;
	}//end buildAddColumnSql()

	/**
	 * Every physical magic table, as bare names, whether or not a register still lists its schema.
	 *
	 * The reconcile command and the repair step walk this rather than the
	 * register to schema links, because a table whose schema a register no
	 * longer lists can still be queried and still lack a column.
	 *
	 * @return list<string> Bare table names (`openregister_table_<register>_<schema>`).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function listMagicTableNames(): array {
		$prefix = $this->getTablePrefix();
		$names = [];
		foreach (array_keys($this->discoverMagicTables()) as $fullTableName) {
			$names[] = substr((string)$fullTableName, strlen($prefix));
		}

		sort($names);

		return array_values(array_unique($names));
	}//end listMagicTableNames()

	/**
	 * The metadata columns a magic table lacks, read fresh.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return array<string, array> Missing column definitions keyed by column name.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function inspectMetadataColumns(string $tableName): array {
		return $this->getMetadataColumnHealer()->inspect(tableName: $tableName);
	}//end inspectMetadataColumns()

	/**
	 * Add the metadata columns a magic table lacks.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return array{added: list<string>, failed: array<string, string>} What was added, and what failed with why.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function healMetadataColumns(string $tableName): array {
		return $this->getMetadataColumnHealer()->heal(tableName: $tableName);
	}//end healMetadataColumns()

	/**
	 * The shared metadata-column healer.
	 *
	 * @return MagicMetadataColumnHealer The healer.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function getMetadataColumnHealer(): MagicMetadataColumnHealer {
		if ($this->metadataColumnHealer === null) {
			$this->metadataColumnHealer = new MagicMetadataColumnHealer(
				sql: $this->tableSql(),
				magicMapper: $this,
				cache: $this->createMetadataColumnCache()
			);
		}

		return $this->metadataColumnHealer;
	}//end getMetadataColumnHealer()

	/**
	 * Get a value from the table exists cache.
	 *
	 * @param string $key Cache key.
	 *
	 * @return int|null Cached timestamp or null if not cached.
	 *
	 * @internal Used by MagicTableHandler.
	 */
	public static function getTableExistsCache(string $key): ?int {
		return self::$tableExistsCache[$key] ?? null;
	}//end getTableExistsCache()

	/**
	 * Set a value in the table exists cache.
	 *
	 * @param string $key Cache key.
	 * @param int $value Timestamp value.
	 *
	 * @return void
	 *
	 * @internal Used by MagicTableHandler.
	 */
	public static function setTableExistsCache(string $key, int $value): void {
		self::$tableExistsCache[$key] = $value;
	}//end setTableExistsCache()

	/**
	 * Unset a value from the table exists cache.
	 *
	 * @param string $key Cache key to remove.
	 *
	 * @return void
	 *
	 * @internal Used by MagicTableHandler.
	 */
	public static function unsetTableExistsCache(string $key): void {
		unset(self::$tableExistsCache[$key]);
	}//end unsetTableExistsCache()

	/**
	 * Set a value in the register+schema table name cache.
	 *
	 * @param string $key Cache key.
	 * @param string $value Table name to cache.
	 *
	 * @return void
	 *
	 * @internal Used by MagicTableHandler.
	 */
	public static function setRegSchemaTableCache(string $key, string $value): void {
		self::$regSchemaTableCache[$key] = $value;
	}//end setRegSchemaTableCache()

	/**
	 * Mark a register+schema table's columns as verified for this process.
	 *
	 * @param string $cacheKey The cache key (registerId_schemaId)
	 *
	 * @return void
	 *
	 * @internal Used by MagicTableHandler.
	 */
	public static function setTableColumnsVerified(string $cacheKey): void {
		self::$tableColumnsVerifiedCache[$cacheKey] = true;
	}//end setTableColumnsVerified()

	/**
	 * Check whether a register+schema table's columns have been verified.
	 *
	 * @param string $cacheKey The cache key (registerId_schemaId)
	 *
	 * @return bool True if columns have been verified in this process
	 *
	 * @internal Used by MagicTableHandler.
	 */
	public static function isTableColumnsVerified(string $cacheKey): bool {
		return isset(self::$tableColumnsVerifiedCache[$cacheKey]);
	}//end isTableColumnsVerified()

	/**
	 * Clear all static caches used by MagicMapper.
	 *
	 * @return void
	 *
	 * @internal Used by MagicTableHandler.
	 */
	public static function clearAllStaticCaches(): void {
		self::$tableExistsCache = [];
		self::$regSchemaTableCache = [];
		self::$tableStructureCache = [];
		self::$calcVersionCache = [];
		self::$tableColumnsVerifiedCache = [];
		MagicMetadataColumnHealer::reset();
	}//end clearAllStaticCaches()

	/**
	 * Check if PostgreSQL pg_trgm extension is available
	 *
	 * This extension provides the similarity() function and % operator
	 * for fuzzy text searching. Result is cached for the request lifetime.
	 *
	 * @return bool True if pg_trgm is available, false otherwise
	 */
	private function hasPgTrgmExtension(): bool {
		return $this->trgmProbe()->available();
	}//end hasPgTrgmExtension()

	/**
	 * The pg_trgm probe of this mapper.
	 *
	 * @return MagicPgTrgmProbe
	 */
	private function trgmProbe(): MagicPgTrgmProbe {
		$this->pgTrgmProbe ??= new MagicPgTrgmProbe(db: $this->db, logger: $this->logger);

		return $this->pgTrgmProbe;
	}//end trgmProbe()

	/**
	 * Create or update table for a specific register+schema combination
	 *
	 * This method analyzes the JSON schema and creates/updates the corresponding
	 * database table with appropriate columns, indexes, and constraints.
	 *
	 * @param Register $register The register context for the table
	 * @param Schema $schema The schema to create/update table for
	 * @param bool $force Whether to force recreation even if table exists
	 *
	 * @throws Exception If table creation/update fails
	 *
	 * @return bool True if table was created/updated successfully
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag allows table recreation
	 */
	public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force = false): bool {
		return $this->tableHandler->ensureTableForRegisterSchema(register: $register, schema: $schema, force: $force);
	}//end ensureTableForRegisterSchema()

	/**
	 * Get table name for a specific register+schema combination
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return string The table name for the register+schema combination
	 */
	public function getTableNameForRegisterSchema(Register $register, Schema $schema): string {
		return $this->tableHandler->getTableNameForRegisterSchema(register: $register, schema: $schema);
	}//end getTableNameForRegisterSchema()

	/**
	 * Check if a specialized table exists for register+schema combination
	 *
	 * This method provides fast existence checking with intelligent caching
	 * to avoid repeated database calls. Cache is automatically invalidated
	 * when tables are created, updated, or dropped.
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return bool True if specialized table exists, false if should use generic storage
	 */
	public function existsTableForRegisterSchema(Register $register, Schema $schema): bool {
		return $this->tableHandler->existsTableForRegisterSchema(register: $register, schema: $schema);
	}//end existsTableForRegisterSchema()

	/**
	 * Save objects to register+schema-specific table
	 *
	 * @param array $objects Array of object data to save
	 * @param Register $register The register context for table selection
	 * @param Schema $schema The schema for table selection
	 *
	 * @throws Exception If save operation fails
	 *
	 * @return string[]
	 *
	 * @psalm-return list<non-empty-string>
	 */
	public function saveObjectsToRegisterSchemaTable(array $objects, Register $register, Schema $schema): array {
		return $this->saveObjectsIn(table: new RegisterSchema(register: $register, schema: $schema), objects: $objects);
	}//end saveObjectsToRegisterSchemaTable()

	/**
	 * Save multiple objects to a register+schema table.
	 *
	 * @param RegisterSchema $table   The register and schema whose table to use.
	 * @param array          $objects The objects to save.
	 *
	 * @return string[]
	 * @throws Exception If save operation fails
	 * @psalm-return list<non-empty-string>
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function saveObjectsIn(RegisterSchema $table, array $objects): array {
		$register = $table->register;
		$schema   = $table->schema;

		// Ensure table exists for this register+schema combination.
		$this->ensureTableForRegisterSchema(register: $register, schema: $schema);

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$savedUuids = [];

		$this->logger->debug(
			message: '[MagicMapper] Saving objects to register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				...$this->tableIds(register: $register, schema: $schema),
				'tableName' => $tableName,
				'objectCount' => count($objects),
			]
		);

		try {
			foreach ($objects as $object) {
				$uuid = $this->saveObjectToRegisterSchemaTable(
					write: new MagicRowWrite(objectData: $object, register: $register, schema: $schema, tableName: $tableName)
				);
				if ($uuid !== '') {
					$savedUuids[] = $uuid;
				}
			}

			$this->logger->debug(
				message: '[MagicMapper] Successfully saved objects to register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'savedCount' => count($savedUuids),
				]
			);

			return $savedUuids;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to save objects to register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'error' => $e->getMessage(),
				]
			);

			throw $e;
		}//end try
	}//end saveObjectsIn()

	/**
	 * Search objects in register+schema-specific table
	 *
	 * @param array $query Search query parameters
	 * @param Register $register The register context for table selection
	 * @param Schema $schema The schema for table selection
	 *
	 * @throws Exception If search operation fails
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function searchObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): array {
		return $this->searchIn(table: new RegisterSchema(register: $register, schema: $schema), query: $query);
	}//end searchObjectsInRegisterSchemaTable()

	/**
	 * Search objects in a register+schema table.
	 *
	 * @param RegisterSchema $table The register and schema whose table to use.
	 * @param array          $query Search parameters.
	 *
	 * @return ObjectEntity[]
	 * @throws Exception If search operation fails
	 * @psalm-return list<ObjectEntity>
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function searchIn(RegisterSchema $table, array $query): array {
		$register = $table->register;
		$schema   = $table->schema;

		if ($this->ensureTableWhenMagicEnabled(register: $register, schema: $schema) === false) {
			$this->logger->debug(
				message: '[MagicMapper] Register+schema table does not exist, should use generic storage',
				context: ['file' => __FILE__, 'line' => __LINE__, ...$this->tableIds(register: $register, schema: $schema)]
			);
			return [];
		}

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

		try {
			// Use MagicSearchHandler for search with RBAC and multi-tenancy support.
			$result = $this->searchHandler->searchObjects(
				query: $query,
				register: $register,
				schema: $schema,
				tableName: $tableName,
				dateTimeColumns: $this->getDateTimeColumns(tableName: $tableName)
			);

			// If result is an integer (count), return empty array.
			if (is_int($result) === true) {
				return [];
			}

			return $result;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to search register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'error' => $e->getMessage(),
				]
			);

			throw $e;
		}//end try
	}//end searchIn()

	/**
	 * Get the list of filter properties that were ignored during the last search.
	 *
	 * These are properties that were requested as filters but don't exist in the schema.
	 * Useful for providing feedback to API clients about invalid filter parameters.
	 *
	 * @return array<string> List of ignored filter property names
	 */
	public function getIgnoredFilters(): array {
		return $this->searchHandler->getIgnoredFilters();
	}//end getIgnoredFilters()

	/**
	 * Count objects in a register+schema specific table.
	 *
	 * This method counts objects from a dedicated table based on register+schema combination.
	 * It supports basic filtering and returns only the count for better performance.
	 * A count that cannot be made answers 0 (logged); a caller that acts on the
	 * number uses countObjectsOrFail() instead.
	 *
	 * @param array    $query    Search parameters for filtering (excluding pagination).
	 * @param Register $register The register context for table selection.
	 * @param Schema   $schema   The schema for table selection.
	 *
	 * @return int Count of matching objects.
	 */
	public function countObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): int {
		return $this->countIn(table: new RegisterSchema(register: $register, schema: $schema), query: $query);
	}//end countObjectsInRegisterSchemaTable()

	/**
	 * Count the objects of a register+schema table that match a query, 0 when the count fails (see countObjectsInRegisterSchemaTable()).
	 *
	 * @param RegisterSchema $table The register and schema whose table to count in.
	 * @param array          $query Search parameters for filtering (excluding pagination).
	 *
	 * @return int Count of matching objects.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function countIn(RegisterSchema $table, array $query): int {
		$register = $table->register;
		$schema   = $table->schema;

		$target = $this->resolveCountTable(register: $register, schema: $schema);
		if ($target === null) {
			return 0;
		}

		$tableName = $target->tableName;

		try {
			$result = $this->runCountQuery(query: $query, target: $target);

			$count = 0;
			if (is_int($result) === true) {
				$count = $result;
			}

			$this->logger->debug(
				message: '[MagicMapper] Count query completed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'count' => $count,
					'hasSearch' => empty($query['_search']) === false,
					'query' => array_keys($query),
				]
			);

			return $count;
		} catch (\OCA\OpenRegister\Exception\EncryptedFieldFilterException $e) {
			// Fail loud (design intent): never degrade a filter on an encrypted
			// property to a silent count of 0.
			throw $e;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to count in register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'error' => $e->getMessage(),
				]
			);

			// Return 0 on error instead of throwing.
			return 0;
		}//end try
	}//end countIn()

	/**
	 * Count objects in a register+schema table, throwing when the count cannot be made.
	 *
	 * The same count as countObjectsInRegisterSchemaTable(), for a caller that
	 * acts on the number (a quota): a failed query or an answer that is not a
	 * number throws instead of reading as 0, so a broken count can never pass
	 * for an empty table (live pass O10).
	 *
	 * @param array    $query    Search parameters for filtering (excluding pagination).
	 * @param Register $register The register context for table selection.
	 * @param Schema   $schema   The schema for table selection.
	 *
	 * @return int Count of matching objects.
	 *
	 * @throws RuntimeException When the count query does not answer with a number.
	 * @throws Exception        When the count query fails.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function countObjectsOrFail(array $query, Register $register, Schema $schema): int {
		return $this->countOrFailIn(table: new RegisterSchema(register: $register, schema: $schema), query: $query);
	}//end countObjectsOrFail()

	/**
	 * Count the objects of a register+schema table that match a query, refusing when no number comes back (see countObjectsOrFail()).
	 *
	 * @param RegisterSchema $table The register and schema whose table to count in.
	 * @param array          $query Search parameters for filtering (excluding pagination).
	 *
	 * @return int Count of matching objects.
	 * @throws RuntimeException When the count query did not answer with a number.
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function countOrFailIn(RegisterSchema $table, array $query): int {
		$register = $table->register;
		$schema   = $table->schema;

		$target = $this->resolveCountTable(register: $register, schema: $schema);
		if ($target === null) {
			return 0;
		}

		$tableName = $target->tableName;

		$result = $this->runCountQuery(query: $query, target: $target);
		if (is_int($result) === false) {
			throw new RuntimeException('The count query did not answer with a number.');
		}

		return $result;
	}//end countOrFailIn()

	/**
	 * The table a register+schema count runs in, creating it when magic mapping is on.
	 *
	 * @param Register $register The register context for table selection.
	 * @param Schema   $schema   The schema for table selection.
	 *
	 * @return MagicTableTarget|null The register, schema and table name, or null when there is no table and magic mapping is off (count 0).
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	private function resolveCountTable(Register $register, Schema $schema): ?MagicTableTarget {
		if ($this->ensureTableWhenMagicEnabled(register: $register, schema: $schema) === false) {
			$this->logger->debug(
				message: '[MagicMapper] Register+schema table does not exist for count, returning 0',
				context: ['file' => __FILE__, 'line' => __LINE__, ...$this->tableIds(register: $register, schema: $schema)]
			);
			return null;
		}

		$target = new MagicTableTarget(register: $register, schema: $schema);
		$target->tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

		return $target;
	}//end resolveCountTable()

	/**
	 * Run a count query through the search handler (RBAC and multi-tenancy filters apply).
	 *
	 * @param array            $query  Search parameters for filtering (excluding pagination).
	 * @param MagicTableTarget $target The register, schema and the table to count in.
	 *
	 * @return mixed The handler's answer: an integer when the count was made.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	private function runCountQuery(array $query, MagicTableTarget $target): mixed {
		// Add _count flag to use MagicSearchHandler with RBAC and multi-tenancy filters.
		$countQuery = $query;
		$countQuery['_count'] = true;

		return $this->searchHandler->searchObjects(
			query: $countQuery,
			register: $target->register,
			schema: $target->schema,
			tableName: $target->tableName
		);
	}//end runCountQuery()

	/**
	 * Get simple facets for a register+schema specific table.
	 *
	 * This method retrieves facet data (aggregations) from a dedicated magic mapper table
	 * based on register+schema combination. It supports both metadata facets (@self fields)
	 * and schema property facets.
	 *
	 * @param array $query Search parameters including _facets configuration.
	 * @param Register $register The register context for table selection.
	 * @param Schema $schema The schema for table selection.
	 *
	 * @return array Facet results with buckets.
	 */
	public function getSimpleFacetsFromRegisterSchemaTable(array $query, Register $register, Schema $schema): array {
		return $this->facetsIn(table: new RegisterSchema(register: $register, schema: $schema), query: $query);
	}//end getSimpleFacetsFromRegisterSchemaTable()

	/**
	 * Get simple facets for a register+schema table.
	 *
	 * @param RegisterSchema $table The register and schema whose table to use.
	 * @param array          $query The search query with facet configuration.
	 *
	 * @return array Facet results with buckets.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function facetsIn(RegisterSchema $table, array $query): array {
		$register = $table->register;
		$schema   = $table->schema;

		// Use fast cached existence check.
		if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
			// Check if magic mapping is enabled for this schema - if so, create the table.
			$isMagicEnabled = $register->isMagicMappingEnabledForSchema(
				schemaId: $schema->getId(),
				schemaSlug: $schema->getSlug()
			);
			if ($isMagicEnabled !== true) {
				$this->logger->debug(
					message: '[MagicMapper] Register+schema table does not exist for facets, returning empty',
					context: ['file' => __FILE__, 'line' => __LINE__, ...$this->tableIds(register: $register, schema: $schema)]
				);
				return [];
			}

			$msg = '[MagicMapper] Register+schema table does not exist';
			$msg .= ' but magic mapping enabled, creating table for facets';
			$this->logger->debug(
				message: $msg,
				context: ['file' => __FILE__, 'line' => __LINE__, ...$this->tableIds(register: $register, schema: $schema)]
			);
			$this->ensureTableForRegisterSchema(register: $register, schema: $schema);
		}//end if

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

		try {
			// Initialize facet handler if not already done.
			$this->initializeHandlers();

			return $this->facetHandler->getSimpleFacets(
				tableName: $tableName,
				query: $query,
				register: $register,
				schema: $schema
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to get facets from register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'error' => $e->getMessage(),
				]
			);

			return [];
		}//end try
	}//end facetsIn()

	/**
	 * Get facets using UNION ALL across multiple register+schema tables.
	 *
	 * This method is optimized for multi-schema faceting by executing ONE query
	 * per facet field using UNION ALL, instead of separate queries per table.
	 * Benchmarks show 2-2.5x speedup for large datasets.
	 *
	 * @param array $query Search parameters including _facets configuration.
	 * @param Register $register The register context.
	 * @param array $schemas Array of Schema objects to include.
	 * @param array $registerSchemaPairs Register/schema pairs.
	 *
	 * @return array Merged facet results.
	 */
	public function getSimpleFacetsUnion(
		array $query,
		?Register $register = null,
		array $schemas = [],
		array $registerSchemaPairs = [],
	): array {
		// Build table configs for each schema.
		$tableConfigs = [];

		// Support new register+schema pairs format (multi-register).
		if (empty($registerSchemaPairs) === false) {
			foreach ($registerSchemaPairs as $pair) {
				$pairRegister = $pair['register'];
				$pairSchema = $pair['schema'];
				if ($this->existsTableForRegisterSchema(register: $pairRegister, schema: $pairSchema) === false) {
					continue;
				}

				$tableName = $this->getTableNameForRegisterSchema(register: $pairRegister, schema: $pairSchema);
				$tableConfigs[] = [
					'tableName' => $tableName,
					'register' => $pairRegister,
					'schema' => $pairSchema,
				];
			}
		} elseif ($register !== null) {
			// Legacy single-register format.
			foreach ($schemas as $schema) {
				if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
					continue;
				}

				$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
				$tableConfigs[] = [
					'tableName' => $tableName,
					'register' => $register,
					'schema' => $schema,
				];
			}
		}//end if

		if (empty($tableConfigs) === true) {
			return [];
		}

		// Initialize handlers if needed.
		$this->initializeHandlers();

		return $this->facetHandler->getSimpleFacetsUnion(
			tableConfigs: $tableConfigs,
			query: $query
		);
	}//end getSimpleFacetsUnion()

	/**
	 * Search across multiple register+schema tables simultaneously.
	 *
	 * This method performs a cross-table search by:
	 * 1. Querying each register+schema table separately with fuzzy search.
	 * 2. Combining results using array merge (since we can't easily UNION with different schemas).
	 * 3. Sorting by relevance score globally.
	 *
	 * @param array $query Search parameters including _search term.
	 * @param array $registerSchemaPairs Array of ['register' => Register, 'schema' => Schema] pairs.
	 *
	 * @return array Array of ObjectEntity objects from all tables, sorted by relevance.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	public function searchAcrossMultipleTables(array $query, array $registerSchemaPairs): array {
		// The `_recent` lens (`recently-opened-means-opened`): the same order,
		// paging and `@self.viewedAt` as the single-schema path. When the
		// history orders the page, the restricted set is fetched whole (it is
		// bounded by `_ids`, at most the history's 100 objects), sorted, and
		// only then paged; paging first would cut the wrong slice.
		$recentViews = RecentLensOrder::views(query: $query);
		if ($recentViews === null) {
			return $this->dispatchCrossTableSearch(query: $query, registerSchemaPairs: $registerSchemaPairs);
		}

		if (RecentLensOrder::ordersPage(query: $query) === false) {
			$results = $this->dispatchCrossTableSearch(query: $query, registerSchemaPairs: $registerSchemaPairs);
			RecentLensOrder::stamp(objects: $results, views: $recentViews);
			return $results;
		}

		$wholeQuery = $query;
		$wholeQuery['_offset'] = 0;
		$wholeQuery['_limit'] = false;
		$results = RecentLensOrder::sort(
			objects: $this->dispatchCrossTableSearch(query: $wholeQuery, registerSchemaPairs: $registerSchemaPairs),
			views: $recentViews
		);

		$offset = max(0, (int)($query['_offset'] ?? 0));
		$normalisedLimit = QueryLimit::normalise($query['_limit'] ?? null);
		$length = null;
		if ($normalisedLimit !== null) {
			$length = min($normalisedLimit, self::MAX_PAGE_SIZE);
		}

		$results = array_slice($results, $offset, $length);
		RecentLensOrder::stamp(objects: $results, views: $recentViews);

		return $results;
	}//end searchAcrossMultipleTables()

	/**
	 * Run a cross-table search on the UNION path or the sequential one.
	 *
	 * @param array $query               Search parameters.
	 * @param array $registerSchemaPairs Array of ['register' => Register, 'schema' => Schema] pairs.
	 *
	 * @return array Array of ObjectEntity objects from all tables.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	private function dispatchCrossTableSearch(array $query, array $registerSchemaPairs): array {
		$this->logger->debug(
			message: '[MagicMapper] Starting cross-table search',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'pairCount' => count($registerSchemaPairs),
				'queryKeys' => array_keys($query),
			]
		);

		$query = (new MagicUnionOrder(isPostgres: false))->orderable(
			query: $query,
			columns: $this->collectAllPropertyColumns(registerSchemaPairs: $registerSchemaPairs)
		);

		// OPTIMIZATION: Use UNION ALL for multi-table search in a single query.
		// This is MUCH faster than looping through tables individually.
		if (count($registerSchemaPairs) > 1 && $this->shouldUseUnionQuery(query: $query) === true) {
			return $this->searchAcrossMultipleTablesWithUnion(
				query: $query,
				registerSchemaPairs: $registerSchemaPairs
			);
		}

		// Fallback: Individual table queries (for complex queries or single table).
		return $this->searchAcrossMultipleTablesSequential(
			query: $query,
			registerSchemaPairs: $registerSchemaPairs
		);
	}//end dispatchCrossTableSearch()

	/**
	 * Count every row a cross-table search matches, before paging.
	 *
	 * Follows the search's own path, so the total and the rows agree on
	 * every filter: the UNION path is counted with one `SUM` over `COUNT(*)`
	 * arms built from the same WHERE as its search arms (one statement per
	 * UNION_ARM_BATCH_SIZE tables), the sequential path by summing the
	 * single-table count it pages with.
	 *
	 * @param array $query               Search parameters (`_limit` and `_offset` are ignored).
	 * @param array $registerSchemaPairs Array of ['register' => Register, 'schema' => Schema] pairs.
	 *
	 * @return int The number of matching rows across all tables.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
	 */
	public function countAcrossMultipleTables(array $query, array $registerSchemaPairs): int {
		if (count($registerSchemaPairs) > 1 && $this->shouldUseUnionQuery(query: $query) === true) {
			$total = 0;
			foreach (array_chunk($registerSchemaPairs, self::UNION_ARM_BATCH_SIZE) as $batch) {
				$total += $this->countUnionBatch(query: $query, registerSchemaPairs: $batch);
			}

			return $total;
		}

		$total = 0;
		foreach ($registerSchemaPairs as $pair) {
			$register = ($pair['register'] ?? null);
			$schema = ($pair['schema'] ?? null);
			if ($register === null || $schema === null) {
				continue;
			}

			$total += $this->countObjectsInRegisterSchemaTable(query: $query, register: $register, schema: $schema);
		}

		return $total;
	}//end countAcrossMultipleTables()

	/**
	 * Count one batch of UNION arms in one statement.
	 *
	 * Each arm is `SELECT COUNT(*) AS cnt FROM <table> WHERE <the search arm's
	 * WHERE>`, so the count matches the rows runUnionBatch() would return
	 * without its LIMIT. A table that does not exist holds no rows.
	 *
	 * @param array $query               Search parameters.
	 * @param array $registerSchemaPairs The pairs of this batch.
	 *
	 * @return int The rows matched in this batch.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
	 */
	private function countUnionBatch(array $query, array $registerSchemaPairs): int {
		$arms = [];
		foreach ($registerSchemaPairs as $pair) {
			$arm = $this->unionCountArm(query: $query, pair: $pair);
			if ($arm !== null) {
				$arms[] = $this->unionCountSql(arm: $arm);
			}
		}

		if ($arms === []) {
			return 0;
		}

		$stmt = $this->db->getQueryBuilder()->getConnection()->prepare(
			'SELECT SUM(cnt) AS total FROM ('.implode(' UNION ALL ', $arms).') counted'
		);
		$stmt->execute();
		$row = $stmt->fetch();

		return (int) ($row['total'] ?? 0);
	}//end countUnionBatch()

	/**
	 * The arm a count reads for one pair, or null without a pair or a table.
	 *
	 * A count never creates a table: a missing one holds no rows.
	 *
	 * @param array $query The search query.
	 * @param array $pair  The register+schema pair.
	 *
	 * @return MagicUnionArm|null The arm, with its table's own columns.
	 */
	private function unionCountArm(array $query, array $pair): ?MagicUnionArm {
		$register = ($pair['register'] ?? null);
		$schema = ($pair['schema'] ?? null);
		if ($register === null || $schema === null
			|| $this->existsTableForRegisterSchema(register: $register, schema: $schema) === false
		) {
			return null;
		}

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$arm = new MagicUnionArm(tableName: $tableName, query: $query, schema: $schema, register: $register);
		$arm->existingColumns = $this->unionOwnColumns(arm: $arm);

		return $arm;
	}//end unionCountArm()

	/**
	 * The `COUNT(*)` statement of one arm, on the same WHERE as its search arm.
	 *
	 * @param MagicUnionArm $arm The arm.
	 *
	 * @return string The statement.
	 */
	private function unionCountSql(MagicUnionArm $arm): string {
		$sql = 'SELECT COUNT(*) AS cnt FROM '.$this->getFullTableName(tableName: $arm->tableName);
		$where = $this->unionArmWhereClauses(arm: $arm);
		if (empty($where) === false) {
			$sql .= ' WHERE '.implode(' AND ', $where);
		}

		return $sql;
	}//end unionCountSql()

	/**
	 * Determine if we should use UNION ALL optimization.
	 *
	 * UNION ALL is faster but has limitations:
	 * - All tables must exist
	 * - No complex aggregations
	 * - No related-row filters (`_related`, `_referenced`) or `_relations_contains`
	 * - Simpler query structure
	 *
	 * @param array $query Search query parameters.
	 *
	 * @return bool True if UNION ALL can be used.
	 */
	private function shouldUseUnionQuery(array $query): bool {
		// Don't use UNION for aggregations (not supported). `_facets` does not count:
		// this search returns rows only and facets are computed separately. The
		// sequential fallback orders and pages like UNION, but runs one query per table.
		if (isset($query['_aggregations']) === true) {
			return false;
		}

		// 🔴 `_related`, `_referenced` and `_element` are EXISTS subqueries that only the
		// QueryBuilder path (the sequential one) builds. The raw-SQL UNION builder
		// refuses them, which on this route would end in an uncaught exception
		// (HTTP 500), so a query carrying either takes the sequential path.
		// `_relations_contains` has no raw-SQL twin either: a union would answer
		// without the filter.
		$builderOnly = ['_related', '_referenced', '_element', '_relations_contains'];
		if (array_intersect($builderOnly, array_keys($query)) !== []) {
			return false;
		}

		// UNION ALL is safe for simple searches.
		return true;
	}//end shouldUseUnionQuery()

	/**
	 * Search across multiple tables using UNION ALL, in bounded batches.
	 *
	 * Up to UNION_ARM_BATCH_SIZE pairs are one statement and the database does
	 * the ordering, the offset and the limit, exactly as before. Beyond that
	 * the pairs are split into batches; each batch over-fetches `offset +
	 * limit` rows in its own statement, the batches are merged and sorted in
	 * PHP on the same keys the SQL would have used, and the page is taken from
	 * the merged set. Without that merge a page would be the first batch's
	 * page, not the search's.
	 *
	 * @param array $query Search parameters.
	 * @param array $registerSchemaPairs Array of register+schema pairs.
	 *
	 * @return array Array of ObjectEntity objects from all tables.
	 *
	 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
	 */
	private function searchAcrossMultipleTablesWithUnion(array $query, array $registerSchemaPairs): array {
		$batches = array_chunk($registerSchemaPairs, self::UNION_ARM_BATCH_SIZE);

		// One batch: the single-statement path, unchanged.
		if (count($batches) <= 1) {
			return $this->convertUnionRowsToEntities(
				rows: $this->runUnionBatch(query: $query, registerSchemaPairs: $registerSchemaPairs)
			);
		}

		// Many batches: each one answers the same question over its own arms,
		// so each must over-fetch far enough that the merged set can serve the
		// requested page. A batch that only fetched `limit` rows could not
		// contribute row `offset + limit - 1` even when it owns it.
		$rows = [];
		foreach ($batches as $batch) {
			foreach ($this->unionBatchRows(query: $query, registerSchemaPairs: $batch) as $row) {
				$rows[] = $row;
			}
		}

		$platform = $this->db->getDatabasePlatform();
		$isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

		$rows = $this->mergeUnionBatchRows(rows: $rows, query: $query, isPostgres: $isPostgres);

		$this->logger->debug(
			message: '[MagicMapper] Cross-batch union search merged',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'batchCount' => count($batches),
				'pairCount' => count($registerSchemaPairs),
				'pageSize' => count($rows),
			]
		);

		return $this->convertUnionRowsToEntities(rows: $rows);
	}//end searchAcrossMultipleTablesWithUnion()

	/**
	 * Run one UNION ALL batch and return its raw rows.
	 *
	 * This is the former body of searchAcrossMultipleTablesWithUnion(): it
	 * builds one statement over the pairs it is given, orders it, applies
	 * LIMIT/OFFSET and returns the rows. It returns rows rather than entities
	 * so the caller can merge several batches before paying for conversion.
	 *
	 * @param array $query Search parameters.
	 * @param array $registerSchemaPairs Array of register+schema pairs, already bounded by the caller.
	 *
	 * @return array Raw result rows.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	private function runUnionBatch(array $query, array $registerSchemaPairs): array {
		$qb = $this->db->getQueryBuilder();
		$parts = [];

		// Collect superset of all property columns across all schemas for UNION compatibility.
		// Each SELECT must include the same columns; schemas that lack a column get NULL AS alias.
		$allPropertyColumns = $this->unionPropertyColumns(registerSchemaPairs: $registerSchemaPairs);

		foreach ($registerSchemaPairs as $pair) {
			$tableName = $this->unionTableForPair(pair: $pair);
			if ($tableName === null) {
				continue;
			}

			// Build SELECT for this table with schema/register metadata and property columns.
			$arm = new MagicUnionArm(tableName: $tableName, query: $query, schema: $pair['schema'], register: $pair['register']);
			$arm->allPropertyColumns = ($allPropertyColumns ?? []);
			$arm->metadataOnly = ($allPropertyColumns === null);
			$selectPart = $this->buildUnionSelectPart(arm: $arm);

			if ($selectPart !== null) {
				$parts[] = $selectPart;
			}
		}

		if (empty($parts) === true) {
			return [];
		}

		// The batch orders on what it projects: a column of another batch is null in all of its rows.
		$orderQuery = (new MagicUnionOrder(isPostgres: false))->orderable(query: $query, columns: ($allPropertyColumns ?? []));
		$unionSql = implode(' UNION ALL ', $parts) . $this->unionOrderByClause(query: $orderQuery) . $this->unionPage(query: $query)->limitClause();

		// Execute the combined query.
		$stmt = $qb->getConnection()->prepare($unionSql);
		$stmt->execute();
		$rows = $stmt->fetchAll();

		$this->logger->debug(
			message: '[MagicMapper] Union batch completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'armCount' => count($parts),
				'rowCount' => count($rows),
			]
		);

		return $rows;
	}//end runUnionBatch()

	/**
	 * The rows of one batch from its first row up to the end of the caller's page.
	 *
	 * Read in statements of at most MAX_PAGE_SIZE rows, so a page past that
	 * bound still sees every row before it. A short statement ends the batch.
	 *
	 * @param array $query               Search parameters.
	 * @param array $registerSchemaPairs Array of register+schema pairs, already bounded by the caller.
	 *
	 * @return array Raw result rows.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	private function unionBatchRows(array $query, array $registerSchemaPairs): array {
		$rows = [];
		foreach ($this->unionPage(query: $query)->batchSteps() as $step) {
			$stepRows = $this->runUnionBatch(query: $step, registerSchemaPairs: $registerSchemaPairs);
			$rows = array_merge($rows, $stepRows);
			if (count($stepRows) < (int)($step['_limit'] ?? 0)) {
				break;
			}
		}

		return $rows;
	}//end unionBatchRows()

	/**
	 * The property column superset every union arm projects, or null for a
	 * metadata-only projection.
	 *
	 * Postgres caps a SELECT target list at 1664 entries. Each UNION arm
	 * projects ~30 metadata columns plus this property superset, so with
	 * many schemas the superset alone blows the cap (SQLSTATE 54011) and
	 * the entire cross-schema search fails — e.g. the unified-search
	 * provider returns nothing. When the superset is too large, fall back
	 * to a metadata-only projection: each arm projects only the constant
	 * `_`-prefixed metadata columns (uuid / name / description / summary /
	 * register / schema / …), which is enough to build a result row. The
	 * search term still matches each table's OWN property columns in the
	 * WHERE/score clauses (see buildUnionSelectPart); a caller needing full
	 * property values re-hydrates by id.
	 *
	 * @param array $registerSchemaPairs The register+schema pairs of the batch.
	 *
	 * @return array<string, string>|null Column name => property name, or null when over budget.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	private function unionPropertyColumns(array $registerSchemaPairs): ?array {
		$allPropertyColumns = $this->collectAllPropertyColumns(registerSchemaPairs: $registerSchemaPairs);
		if (count($allPropertyColumns) <= self::UNION_PROPERTY_COLUMN_BUDGET) {
			return $allPropertyColumns;
		}

		$this->logger->warning(
			message: '[MagicMapper] Cross-schema property superset exceeds the column budget; using metadata-only projection',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'budget' => self::UNION_PROPERTY_COLUMN_BUDGET,
				'schemaCount' => count($registerSchemaPairs),
			]
		);

		return null;
	}//end unionPropertyColumns()

	/**
	 * The magic table a union arm reads for one register+schema pair.
	 *
	 * A missing table is created when magic mapping is enabled for the schema;
	 * otherwise the pair is skipped.
	 *
	 * @param array $pair The pair, with 'register' and 'schema'.
	 *
	 * @return string|null The table name, or null to skip the pair.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	private function unionTableForPair(array $pair): ?string {
		$register = $pair['register'] ?? null;
		$schema = $pair['schema'] ?? null;

		if ($register === null || $schema === null) {
			return null;
		}

		if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
			$isMagicEnabled = $register->isMagicMappingEnabledForSchema(
				schemaId: $schema->getId(),
				schemaSlug: $schema->getSlug()
			);
			if ($isMagicEnabled !== true) {
				return null;
			}

			$msg = '[MagicMapper] Register+schema table does not exist';
			$msg .= ' but magic mapping enabled, creating table for cross-search';
			$this->logger->debug(
				message: $msg,
				context: ['file' => __FILE__, 'line' => __LINE__, ...$this->tableIds(register: $register, schema: $schema)]
			);
			$this->ensureTableForRegisterSchema(register: $register, schema: $schema);
		}//end if

		return $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
	}//end unionTableForPair()

	/**
	 * The ORDER BY clause of a union: the _order parameter, or search score.
	 *
	 * The keys come from buildUnionOrderKeys() so that the PHP-side merge of
	 * several batches sorts on exactly what the SQL sorts on.
	 *
	 * @param array $query Search parameters.
	 *
	 * @return string The clause, with a leading space.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	private function unionOrderByClause(array $query): string {
		$isPostgres = stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;

		$orderClauses = [];
		foreach ($this->buildUnionOrderKeys(query: $query, isPostgres: $isPostgres) as $orderKey) {
			$orderClauses[] = $orderKey['sql'] . ' ' . $orderKey['dir'];
		}

		return ' ORDER BY ' . implode(', ', $orderClauses);
	}//end unionOrderByClause()

	/**
	 * Resolve the ORDER BY keys for a cross-schema UNION search.
	 *
	 * Returns one entry per key, in order, each carrying the SQL expression to
	 * put in the statement and the plain row key to read when the same order
	 * has to be reproduced in PHP across batches. The list is never empty: the
	 * uuid tiebreaker always closes it, because LIMIT/OFFSET over an unordered
	 * UNION returns a different page each time it runs.
	 *
	 * @param array $query      Search parameters.
	 * @param bool  $isPostgres Whether the platform is PostgreSQL (identifier quoting).
	 *
	 * @return array<int, array{row: string, sql: string, dir: string}> Ordered sort keys.
	 *
	 */
	private function buildUnionOrderKeys(array $query, bool $isPostgres): array {
		return (new MagicUnionOrder(isPostgres: $isPostgres))->keys(query: $query);
	}//end buildUnionOrderKeys()

	/**
	 * The per-batch query: page one, wide enough to cover the caller's page.
	 *
	 * @param array $query Search parameters.
	 *
	 * @return array The query to run per batch.
	 */
	private function buildUnionBatchQuery(array $query): array {
		return $this->unionPage(query: $query)->batchQuery();
	}//end buildUnionBatchQuery()

	/**
	 * Merge the rows of several UNION batches into one page.
	 *
	 * Sorts on the SQL's own order keys and then takes the caller's page from
	 * the merged set, which is the whole point of the batching: the page has
	 * to be the search's page, not the first batch's.
	 *
	 * @param array $rows       Rows from every batch, in batch order.
	 * @param array $query      The caller's search parameters (offset and limit).
	 * @param bool  $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return array The merged page.
	 */
	private function mergeUnionBatchRows(array $rows, array $query, bool $isPostgres): array {
		$order = new MagicUnionOrder(isPostgres: $isPostgres);

		return $this->unionPage(query: $query)->cut(rows: $order->sorted(rows: $rows, orderKeys: $order->keys(query: $query)));
	}//end mergeUnionBatchRows()

	/**
	 * The page of a union search.
	 *
	 * @param array $query Search parameters.
	 *
	 * @return MagicUnionPage The page.
	 */
	private function unionPage(array $query): MagicUnionPage {
		return new MagicUnionPage(query: $query, maxSize: self::MAX_PAGE_SIZE);
	}//end unionPage()

	/**
	 * Convert raw UNION rows to ObjectEntity objects.
	 *
	 * @param array $rows Raw result rows.
	 *
	 * @return array Array of ObjectEntity objects.
	 */
	private function convertUnionRowsToEntities(array $rows): array {
		$results = [];
		foreach ($rows as $row) {
			try {
				$entity = $this->convertUnionRowToObjectEntity(row: $row);
				if ($entity !== null) {
					$results[] = $entity;
				}
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to convert union row to entity',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
				continue;
			}
		}

		$this->logger->debug(
			message: '[MagicMapper] Union search completed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'resultCount' => count($results)]
		);

		return $results;
	}//end convertUnionRowsToEntities()

	/**
	 * Build the SELECT of one UNION ALL arm.
	 *
	 * Every arm must project identical columns in the same order: the metadata
	 * columns, the superset of property columns across all arms (absent ones as
	 * typed NULLs, present ones cast to text so the same column from two
	 * schemas stays type-compatible), the arm's register and schema ids, and a
	 * search score.
	 *
	 * @param MagicUnionArm $arm The arm.
	 *
	 * @return string|null SQL SELECT statement.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	private function buildUnionSelectPart(MagicUnionArm $arm): ?string {
		$arm->isPostgres = stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;

		$selectColumns = array_merge(
			$this->unionMetadataSelect(arm: $arm),
			$this->unionPropertySelect(arm: $arm)
		);
		$selectColumns[] = "'{$arm->register->getId()}' AS _union_register_id";
		$selectColumns[] = "'{$arm->schema->getId()}' AS _union_schema_id";
		$selectColumns[] = $this->unionSearchScore(arm: $arm);

		$fullTableName = $this->getFullTableName(tableName: $arm->tableName);
		$selectSql = 'SELECT ' . implode(', ', $selectColumns) . " FROM {$fullTableName}";

		$whereClauses = $this->unionArmWhereClauses(arm: $arm);
		if (empty($whereClauses) === false) {
			$selectSql .= ' WHERE ' . implode(' AND ', $whereClauses);
		}

		return $selectSql;
	}//end buildUnionSelectPart()

	/**
	 * The metadata columns of a UNION arm, as text, or as typed NULLs for the
	 * newer metadata columns (such as `_tmlo`) an older table lacks.
	 *
	 * @param MagicUnionArm $arm The arm.
	 *
	 * @return array<int, string> The select expressions.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	private function unionMetadataSelect(MagicUnionArm $arm): array {
		$selectColumns = [];
		foreach (array_keys(MagicMetadataColumns::ALL) as $metaCol) {
			if ($this->columnExistsInTable(tableName: $arm->tableName, columnName: $metaCol) === true) {
				$selectColumns[] = $arm->castAsText(column: $metaCol);
				continue;
			}

			$selectColumns[] = $arm->nullAs(column: $metaCol);
		}

		return $selectColumns;
	}//end unionMetadataSelect()

	/**
	 * The property columns of a UNION arm, and the ones its table has.
	 *
	 * A metadata-only arm projects no property columns (that superset is what
	 * trips PostgreSQL's 1664-column cap) but still resolves its own table's
	 * property columns, which the search terms and score are matched against.
	 *
	 * @param MagicUnionArm $arm The arm; its existingColumns are filled.
	 *
	 * @return array<int, string> The select expressions.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	private function unionPropertySelect(MagicUnionArm $arm): array {
		$arm->existingColumns = [];
		if ($arm->metadataOnly === true) {
			$arm->existingColumns = $this->unionOwnColumns(arm: $arm);
			return [];
		}

		$selectColumns = [];
		foreach (array_keys($arm->allPropertyColumns) as $columnName) {
			$quotedCol = $this->quoteIdentifier(name: $columnName, isPostgres: $arm->isPostgres);
			if ($this->columnExistsInTable(tableName: $arm->tableName, columnName: $columnName) === true) {
				$selectColumns[] = $arm->castAsText(column: $quotedCol);
				$arm->existingColumns[] = $columnName;
				continue;
			}

			$selectColumns[] = $arm->nullAs(column: $quotedCol);
		}

		return $selectColumns;
	}//end unionPropertySelect()

	/**
	 * The columns of the arm's own schema that its table has.
	 *
	 * @param MagicUnionArm $arm The arm.
	 *
	 * @return array<int, string> The column names.
	 */
	private function unionOwnColumns(MagicUnionArm $arm): array {
		$columns = [];
		foreach (array_keys($arm->schema->getProperties() ?? []) as $ownProperty) {
			$ownColumn = $this->sanitizeColumnName(name: $ownProperty);
			if ($this->columnExistsInTable(tableName: $arm->tableName, columnName: $ownColumn) === true) {
				$columns[] = $ownColumn;
			}
		}

		return $columns;
	}//end unionOwnColumns()

	/**
	 * The `_search_score` column of a UNION arm.
	 *
	 * With a search term, each string or text property column this table has
	 * is scored: pg_trgm similarity() on PostgreSQL with the extension, ILIKE
	 * on PostgreSQL without it, LIKE on MariaDB/MySQL; the arm scores the
	 * greatest of them. Columns from other schemas are NULL in this arm and
	 * cannot be scored. Without a term, or without scorable columns, it is 0.
	 *
	 * @param MagicUnionArm $arm The arm.
	 *
	 * @return string The select expression.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	private function unionSearchScore(MagicUnionArm $arm): string {
		$searchTerm = $arm->query['_search'] ?? '';
		$schemaProps = $arm->schema->getProperties() ?? [];
		if (empty($searchTerm) === true || empty($schemaProps) === true) {
			return '0 AS _search_score';
		}

		// The connection quote() adds the quotes itself.
		$score = new MagicUnionScore(
			quotedTerm: $this->db->getQueryBuilder()->getConnection()->quote($searchTerm),
			isPostgres: $arm->isPostgres,
			hasTrgm: $this->hasPgTrgmExtension()
		);

		$scores = [];
		foreach ($this->unionScorableColumns(arm: $arm) as $columnName) {
			$scores[] = $score->ofColumn(column: $this->quoteIdentifier(name: $columnName, isPostgres: $arm->isPostgres));
		}

		return $score->greatest(scores: $scores);
	}//end unionSearchScore()

	/**
	 * The string and text columns of the arm's schema that its table has.
	 *
	 * @param MagicUnionArm $arm The arm.
	 *
	 * @return array<int, string> The column names.
	 */
	private function unionScorableColumns(MagicUnionArm $arm): array {
		$columns = [];
		foreach (($arm->schema->getProperties() ?? []) as $propName => $propDef) {
			$columnName = $this->sanitizeColumnName(name: $propName);
			if (in_array($propDef['type'] ?? 'string', ['string', 'text'], true) === true
				&& $this->columnExistsInTable(tableName: $arm->tableName, columnName: $columnName) === true
			) {
				$columns[] = $columnName;
			}
		}

		return $columns;
	}//end unionScorableColumns()

	/**
	 * The WHERE conditions of a UNION arm, from the shared filter builder that
	 * search, count and facets all use.
	 *
	 * The `@self.schema` filter is scoped to this arm's schema: the incoming
	 * allow-list (every searchable schema) is redundant on an arm that reads
	 * one schema's table, and trips the 1000-element IN-list cap. The register
	 * goes with the question, so the organisation boundary widens by a shared
	 * master data holder here the same way it does on the single-table path.
	 *
	 * @param MagicUnionArm $arm The arm.
	 *
	 * @return array<int, string> The conditions.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	private function unionArmWhereClauses(MagicUnionArm $arm): array {
		$armQuery = $arm->query;
		if (isset($armQuery['@self']) === true && is_array($armQuery['@self']) === true
			&& array_key_exists('schema', $armQuery['@self']) === true
		) {
			$armQuery['@self']['schema'] = $arm->schema->getId();
		}

		return $this->searchHandler->buildWhereConditionsSql(
			query: $armQuery,
			schema: $arm->schema,
			existingColumns: $arm->existingColumns,
			registerId: $arm->register->getId(),
			tableName: $arm->tableName
		);
	}//end unionArmWhereClauses()

	/**
	 * Collect the superset of all sanitized property column names across schemas.
	 *
	 * For UNION queries, all SELECTs must have the same columns.
	 * This method collects all unique property columns from all schemas
	 * so that each SELECT can include them (real column or NULL AS alias).
	 *
	 * @param array $registerSchemaPairs Array of register+schema pairs.
	 *
	 * @return array Associative array of sanitized_column_name => original_property_name.
	 */
	private function collectAllPropertyColumns(array $registerSchemaPairs): array {
		$allColumns = [];

		foreach ($registerSchemaPairs as $pair) {
			$schema = $pair['schema'] ?? null;
			if ($schema === null) {
				continue;
			}

			$properties = $schema->getProperties() ?? [];
			if (is_array($properties) === false) {
				continue;
			}

			foreach ($properties as $propertyName => $propertyConfig) {
				if (in_array($propertyName, MagicSchemaColumns::CONFIGURATION_FIELDS, true) === true) {
					continue;
				}

				if (is_array($propertyConfig) === false) {
					continue;
				}

				$columnName = $this->sanitizeColumnName(name: $propertyName);
				// Only store the first mapping for each column name.
				if (isset($allColumns[$columnName]) === false) {
					$allColumns[$columnName] = $propertyName;
				}
			}//end foreach
		}//end foreach

		return $allColumns;
	}//end collectAllPropertyColumns()

	/**
	 * Convert UNION query row to ObjectEntity.
	 *
	 * @param array $row Database row from UNION query.
	 *
	 * @return ObjectEntity|null ObjectEntity or null if conversion fails.
	 */
	private function convertUnionRowToObjectEntity(array $row): ?ObjectEntity {
		$registerId = $row['_union_register_id'] ?? null;
		$schemaId = $row['_union_schema_id'] ?? null;
		$searchScore = $row['_search_score'] ?? null;

		if ($registerId === null || $schemaId === null) {
			return null;
		}

		// Remove metadata columns before converting to ObjectEntity.
		unset($row['_union_register_id'], $row['_union_schema_id'], $row['_search_score']);

		// Convert to ObjectEntity using existing logic.
		try {
			$register = $this->registerMapper->find((int)$registerId, _multitenancy: false, _rbac: false);
			$schema = $this->schemaMapper->find((int)$schemaId, _multitenancy: false, _rbac: false);

			$entity = $this->convertRowToObjectEntity(
				row: $row,
				_register: $register,
				_schema: $schema
			);

			// Set relevance score from UNION search score (converted to percentage 0-100).
			// The _search_score from UNION is already a similarity score (0-1), convert to percentage.
			if ($entity !== null && $searchScore !== null) {
				$relevancePercent = round((float)$searchScore * 100);
				$entity->setRelevance($relevancePercent);
			}

			return $entity;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to convert union row',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end convertUnionRowToObjectEntity()

	/**
	 * Search across multiple tables sequentially (FALLBACK).
	 *
	 * This is the original implementation - slower but more flexible.
	 *
	 * Paging is applied ONCE, to the merged result. Each table is asked for
	 * its first `offset + limit` rows, the tables are merged and ordered, and
	 * only then is the page cut. Passing `_offset` to every table made each
	 * one skip that many rows on its own, so later pages lost rows and pages
	 * ran up to limit x tables.
	 *
	 * The merged rows are ordered exactly as the UNION path orders them: the
	 * requested `_order`, else the search score, closed by the uuid. Joining
	 * the tables one after the other gave the same question a different
	 * order, and a different page 2, depending on the path that ran.
	 *
	 * A numeric `_limit` is bounded by MAX_PAGE_SIZE, as on the UNION path;
	 * only `_limit=false` asks for every row.
	 *
	 * @param array $query Search parameters.
	 * @param array $registerSchemaPairs Array of register+schema pairs.
	 *
	 * @return array Array of ObjectEntity objects.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-list-page-size-is-bounded-by-a-hard-maximum
	 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	private function searchAcrossMultipleTablesSequential(array $query, array $registerSchemaPairs): array {
		// Each table answers the merged question's first `offset + limit` rows,
		// the same over-fetch buildUnionBatchQuery() uses for UNION batches.
		$pageQuery = $this->boundedPageQuery(query: $query);
		$query = $this->buildUnionBatchQuery(query: $pageQuery);

		// Every table is asked for its rows in the UNION path's order, closed by
		// the uuid; on its own default (`_id`) a table's first `offset + limit`
		// rows are not the ones the merged page needs.
		$order = new MagicSequentialOrder(query: $pageQuery);
		$query['_order'] = $order->tableOrder();

		$allResults = [];
		foreach ($registerSchemaPairs as $pair) {
			$allResults = array_merge($allResults, $this->searchSequentialPair(query: $query, pair: $pair));
		}

		$this->logger->debug(
			message: '[MagicMapper] Sequential search completed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'totalResults' => count($allResults)]
		);

		// Order the merged rows on the same keys, with the same comparator, as
		// the UNION path merges its batches; a search term orders by score.
		$allResults = $order->sorted(results: $allResults);

		// The caller's page, cut once from the merged rows.
		$allResults = array_slice(
			$allResults,
			max(0, (int)($pageQuery['_offset'] ?? 0)),
			QueryLimit::normalise($pageQuery['_limit'] ?? null)
		);

		$this->logger->debug(
			message: '[MagicMapper] Cross-table search completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'tableCount' => count($registerSchemaPairs),
				'resultCount' => count($allResults),
			]
		);

		return $allResults;
	}//end searchAcrossMultipleTablesSequential()

	/**
	 * The query with a numeric `_limit` reduced to MAX_PAGE_SIZE.
	 *
	 * @param array $query Search parameters.
	 *
	 * @return array The query; an unlimited one is returned as it came.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-list-page-size-is-bounded-by-a-hard-maximum
	 */
	private function boundedPageQuery(array $query): array {
		$limit = QueryLimit::normalise($query['_limit'] ?? null);
		if ($limit !== null) {
			$query['_limit'] = min($limit, self::MAX_PAGE_SIZE);
		}

		return $query;
	}//end boundedPageQuery()

	/**
	 * The rows one register+schema pair contributes to a sequential search.
	 *
	 * An invalid pair, or a table whose search fails, is logged and
	 * contributes no rows, so the other tables still answer.
	 *
	 * @param array $query The per-table query.
	 * @param array $pair  The register+schema pair.
	 *
	 * @return ObjectEntity[] The table's rows, tagged with their register and schema.
	 */
	private function searchSequentialPair(array $query, array $pair): array {
		$register = $pair['register'] ?? null;
		$schema = $pair['schema'] ?? null;

		if ($register === null || $schema === null) {
			$this->logger->warning(
				message: '[MagicMapper] Invalid register+schema pair in cross-table search',
				context: ['file' => __FILE__, 'line' => __LINE__, 'pair' => $pair]
			);
			return [];
		}

		try {
			return $this->searchSequentialTable(query: $query, pair: $pair);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to search in register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					...$this->tableIds(register: $register, schema: $schema),
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}//end searchSequentialPair()

	/**
	 * Search one register+schema table and tag its rows with that pair.
	 *
	 * @param array $query The per-table query.
	 * @param array $pair  The register+schema pair, both set.
	 *
	 * @return ObjectEntity[] The table's rows.
	 */
	private function searchSequentialTable(array $query, array $pair): array {
		$register = $pair['register'];
		$schema = $pair['schema'];

		$this->logger->debug(
			message: '[MagicMapper] Searching table (sequential)',
			context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schema->getId()]
		);

		$results = $this->searchObjectsInRegisterSchemaTable(
			query: $query,
			register: $register,
			schema: $schema
		);

		$this->logger->debug(
			message: '[MagicMapper] Table search completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => $schema->getId(),
				'schemaTitle' => $schema->getTitle(),
				'resultCount' => count($results),
			]
		);

		// Add schema information to each result for context.
		foreach ($results as $result) {
			$result->setSchema((string)$schema->getId());
			$result->setRegister((string)$register->getId());
		}

		return $results;
	}//end searchSequentialTable()

	/**
	 * Get cache key for register+schema combination
	 *
	 * @param int $registerId The register ID
	 * @param int $schemaId The schema ID
	 *
	 * @return string Cache key for the combination
	 */
	public function getCacheKey(int $registerId, int $schemaId): string {
		return $registerId . '_' . $schemaId;
	}//end getCacheKey()

	/**
	 * Check if table exists in database (bypassing cache)
	 *
	 * Uses Nextcloud 32+ compatible API for checking table existence.
	 *
	 * @param string $tableName The table name to check
	 *
	 * @return bool True if table exists in database
	 */
	public function checkTableExistsInDatabase(string $tableName): bool {
		if (isset($this->tableExistsMemo[$tableName]) === true) {
			return $this->tableExistsMemo[$tableName];
		}

		$exists = $this->probeTableExistsInDatabase(tableName: $tableName);

		// Only a POSITIVE answer is memoised. A negative one can legitimately
		// become positive within the request — ensureTableExists() creates the
		// table and then writes to it — and caching "no" would make that write
		// fail against a table that now exists.
		if ($exists === true) {
			$this->tableExistsMemo[$tableName] = true;
		}

		return $exists;
	}//end checkTableExistsInDatabase()

	/**
	 * Ask the database whether a table exists, without memoisation.
	 *
	 * @param string $tableName The table name to check.
	 *
	 * @return boolean True if the table exists.
	 */
	private function probeTableExistsInDatabase(string $tableName): bool {
		try {
			\OCA\OpenRegister\Service\WritePhase::count(event: 'tableExists.probe');

			// Check if table exists in information_schema.
			// NOTE: We use raw SQL here because information_schema is a system table.
			$fullTableName = $this->getFullTableName(tableName: $tableName);

			// Get database platform to use correct schema check.
			// MySQL/MariaDB: table_schema = DATABASE().
			// PostgreSQL: table_schema = current_schema().
			$platform = $this->db->getDatabasePlatform();
			$isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

			// MySQL/MariaDB/SQLite.
			$sql = 'SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = DATABASE() LIMIT 1';
			if ($isPostgres === true) {
				$sql = 'SELECT 1 FROM information_schema.tables';
				$sql .= ' WHERE table_name = ? AND table_schema = current_schema() LIMIT 1';
			}

			$stmt = $this->db->prepare($sql);
			$stmt->execute([$fullTableName]);
			$result = $stmt->fetch();

			return $result !== false;
		} catch (Exception $e) {
			// Table doesn't exist or query failed.
			$this->logger->debug(
				message: '[MagicMapper] Table does not exist in database',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'error' => $e->getMessage(),
				]
			);

			return false;
		}//end try
	}//end probeTableExistsInDatabase()

	/**
	 * Invalidate table cache for specific register+schema
	 *
	 * @param string $cacheKey The cache key to invalidate
	 *
	 * @return void
	 */
	public function invalidateTableCache(string $cacheKey): void {
		unset(self::$tableExistsCache[$cacheKey]);
		unset(self::$regSchemaTableCache[$cacheKey]);
		unset(self::$tableStructureCache[$cacheKey]);
		unset(self::$calcVersionCache[$cacheKey]);
		unset(self::$tableColumnsVerifiedCache[$cacheKey]);

		// PERF-12: this instance-scoped map is keyed by schema id (not the
		// composite cacheKey), so drop it wholesale on any table-structure
		// invalidation to avoid serving a stale column→property map.
		$this->rowColumnToPropertyCache = [];

		$this->logger->debug(
			message: '[MagicMapper] Invalidated table cache',
			context: ['file' => __FILE__, 'line' => __LINE__, 'cacheKey' => $cacheKey]
		);
	}//end invalidateTableCache()

	/**
	 * Create table for specific register+schema combination
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema to create table for
	 *
	 * @throws Exception If table creation fails
	 *
	 * @return true True if table created successfully
	 */
	public function createTableForRegisterSchema(Register $register, Schema $schema): bool {
		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$registerId = $register->getId();
		$schemaId = $schema->getId();
		$cacheKey = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

		// Info, not debug: creating a table is a schema change. It happens once
		// per register+schema, not per operation, and it is the sort of thing
		// you want in the record when explaining what a system did.
		$this->logger->info(
			message: '[MagicMapper] Creating new register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $registerId,
				'schemaId' => $schemaId,
				'tableName' => $tableName,
			]
		);

		// Get table structure from schema.
		$columns = $this->buildTableColumnsFromSchema(schema: $schema);

		// Create table with columns.
		$this->createTable(tableName: $tableName, columns: $columns);

		// A new table invalidates both request-scoped memos: the existence memo
		// may hold a stale "no" for this name, and the live-table enumeration no
		// longer lists everything.
		$this->invalidateTableMemos();

		// Create indexes for performance.
		$this->createTableIndexes(tableName: $tableName, schema: $schema);

		// Store schema version for change detection.
		$this->storeRegisterSchemaVersion(register: $register, schema: $schema);

		// Update cache with current timestamp.
		self::$tableExistsCache[$cacheKey] = time();
		self::$regSchemaTableCache[$cacheKey] = $tableName;

		$this->logger->debug(
			message: '[MagicMapper] Successfully created register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'tableName' => $tableName,
				'columnCount' => count($columns),
				'cacheKey' => $cacheKey,
			]
		);

		return true;
	}//end createTableForRegisterSchema()

	/**
	 * Synchronize table structure with schema definition.
	 *
	 * This is a public method that can be called to update an existing magic table
	 * to match the current schema definition. It will:
	 * - Add missing columns
	 * - De-require columns that are no longer required in schema
	 * - Drop duplicate camelCase columns when snake_case exists
	 * - Make obsolete columns nullable
	 * - Update indexes for relations and facetable fields
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema definition
	 *
	 * @return array Statistics about what was changed
	 *
	 * @throws Exception If table sync fails
	 */
	public function syncTableForRegisterSchema(Register $register, Schema $schema): array {
		return $this->tableHandler->syncTableForRegisterSchema(register: $register, schema: $schema);
	}//end syncTableForRegisterSchema()

	/**
	 * Build table columns from JSON schema properties
	 *
	 * This method analyzes the JSON schema and creates appropriate SQL column
	 * definitions for each property, plus all metadata columns from ObjectEntity.
	 *
	 * @param Schema $schema The schema to analyze
	 *
	 * @return (bool|int|mixed|null|string)[][] Column definitions.
	 */
	public function buildTableColumnsFromSchema(Schema $schema): array {
		return (new MagicSchemaColumns(logger: $this->logger))->forSchema(schema: $schema);
	}//end buildTableColumnsFromSchema()

	/**
	 * Create a new table with specified columns.
	 *
	 * @param string $tableName The table name
	 * @param array $columns The column definitions
	 *
	 * @throws Exception If table creation fails
	 *
	 * @return void
	 */
	private function createTable(string $tableName, array $columns): void {
		(new MagicTableCreator(sql: $this->tableSql()))->create(tableName: $tableName, columns: $columns);
	}//end createTable()

	/**
	 * Map column type to SQL type string.
	 *
	 * @param string $type The Doctrine type
	 * @param array $column The column configuration
	 *
	 * @return string The SQL type string
	 */
	private function mapColumnTypeToSQL(string $type, array $column): string {
		$isPostgres = $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform;

		return (new MagicColumnSql(isPostgres: $isPostgres))->type(type: $type, column: $column);
	}//end mapColumnTypeToSQL()

	/**
	 * Create indexes for table performance
	 *
	 * @param string $tableName The table name
	 * @param Schema $schema    The schema for index analysis
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	private function createTableIndexes(string $tableName, Schema $schema): void {
		$run = new MagicTableIndexRun(tableName: $tableName, fullTableName: $this->getFullTableName(tableName: $tableName));
		$indexer = new MagicTableIndexer(db: $this->db, logger: $this->logger, trgm: $this->trgmProbe());
		$indexer->create(run: $run, schema: $schema);
	}//end createTableIndexes()

	/**
	 * Save single object to register+schema-specific table
	 *
	 * @param MagicRowWrite $write The object, its table and the claim flag; its insertedId
	 *                             is set to the generated `_id` when this call inserted, so the
	 *                             caller learns the key without selecting the row back.
	 *
	 * @throws Exception If save operation fails
	 *
	 * @return string The UUID of the saved object — the identity callers actually use
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	protected function saveObjectToRegisterSchemaTable(MagicRowWrite $write): string {
		$write->row = $this->prepareObjectDataForTable(
			objectData: $write->objectData,
			table: new RegisterSchema(register: $write->register, schema: $write->schema)
		);
		$this->refuseNumbersTheColumnsWouldRound(row: $write->row, tableName: $write->tableName);
		$write->row[self::METADATA_PREFIX . 'uuid'] = $this->rowUuid(write: $write);
		$write->uuid = $write->row[self::METADATA_PREFIX . 'uuid'];

		try {
			if ($this->findObjectInRegisterSchemaTable(uuid: $write->uuid, tableName: $write->tableName) === null) {
				return $this->insertClaimedRow(write: $write);
			}

			return $this->updateFoundRow(write: $write);
		} catch (Exception $e) {
			$this->logFailedSave(error: $e, where: ['uuid' => $write->uuid, 'tableName' => $write->tableName]);
			throw $e;
		}
	}//end saveObjectToRegisterSchemaTable()

	/**
	 * The uuid a row is written under: the one in the prepared row, in `@self`
	 * or at the top of the object, or a new v4 when none is set.
	 *
	 * @param MagicRowWrite $write The write; its row is prepared.
	 *
	 * @return mixed The uuid.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function rowUuid(MagicRowWrite $write): mixed {
		$objectData = $write->objectData;
		$uuidFromMeta = $write->row[self::METADATA_PREFIX . 'uuid'] ?? null;
		$uuidFromSelf = $objectData['@self']['id'] ?? $objectData['@self']['uuid'] ?? null;
		$uuidFromTop = $objectData['id'] ?? $objectData['uuid'] ?? null;
		$existingUuid = $uuidFromMeta ?? $uuidFromSelf ?? $uuidFromTop;

		if (empty($existingUuid) === false) {
			return $existingUuid;
		}

		return Uuid::v4()->toRfc4122();
	}//end rowUuid()

	/**
	 * Insert a row whose identifier the lookup found free.
	 *
	 * The lookup and the insert are two operations, so concurrent writers can
	 * all find nothing and all arrive here (openregister#2212). The `_uuid`
	 * unique constraint lets the database arbitrate: exactly one INSERT wins,
	 * and the losers are handled by applyLostInsertRace(). Catching the
	 * violation by reason keeps this portable across MySQL and PostgreSQL.
	 *
	 * @param MagicRowWrite $write The write; its insertedId is set on success.
	 *
	 * @return string The uuid written.
	 *
	 * @throws ObjectExistsException When the race is lost and the caller claims the identifier.
	 * @throws DbException When the insert fails for another reason.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function insertClaimedRow(MagicRowWrite $write): string {
		try {
			$write->insertedId = $this->insertObjectInRegisterSchemaTable(data: $write->row, tableName: $write->tableName);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			return $this->applyLostInsertRace(write: $write);
		}

		$this->logger->debug(
			message: '[MagicMapper] Inserted object in register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $write->uuid,
				'tableName' => $write->tableName,
			]
		);
		$this->lastWriteAction = 'create';
		return $write->uuid;
	}//end insertClaimedRow()

	/**
	 * Handle an insert that lost the race for its identifier.
	 *
	 * A caller that claims the identifier is told it is taken. Any other
	 * caller's upsert intent is applied as the update the lookup would have
	 * chosen had it run a moment later.
	 *
	 * @param MagicRowWrite $write The write.
	 *
	 * @return string The uuid written.
	 *
	 * @throws ObjectExistsException When the caller claims the identifier.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function applyLostInsertRace(MagicRowWrite $write): string {
		if ($write->failIfExists === true) {
			throw new ObjectExistsException(
				message: sprintf('An object with identifier "%s" already exists.', $write->uuid),
				uuid: $write->uuid
			);
		}

		$this->logger->debug(
			message: '[MagicMapper] Insert lost a race; applying as update',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $write->uuid,
				'tableName' => $write->tableName,
			]
		);
		$this->updateObjectInRegisterSchemaTable(write: $write);
		$this->lastWriteAction = 'update';
		return $write->uuid;
	}//end applyLostInsertRace()

	/**
	 * Update a row the lookup found.
	 *
	 * A caller that claims the identifier is refused here too: a concurrent
	 * writer can land the row between the service-level lookup and this one,
	 * and without the refusal the claim would quietly overwrite it while the
	 * audit trail records a create (openregister#2212).
	 *
	 * @param MagicRowWrite $write The write.
	 *
	 * @return string The uuid written.
	 *
	 * @throws ObjectExistsException When the caller claims the identifier.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function updateFoundRow(MagicRowWrite $write): string {
		if ($write->failIfExists === true) {
			throw new ObjectExistsException(
				message: sprintf('An object with identifier "%s" already exists.', $write->uuid),
				uuid: $write->uuid
			);
		}

		$this->updateExistingObject(write: $write);
		$this->lastWriteAction = 'update';
		$this->logger->debug(
			message: '[MagicMapper] Updated object in register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $write->uuid,
				'tableName' => $write->tableName,
			]
		);
		return $write->uuid;
	}//end updateFoundRow()

	/**
	 * Log a failed table write. A taken identifier is an answer for the caller, not a failure, so it is not logged.
	 *
	 * @param Exception                              $error The exception the write threw.
	 * @param array{uuid: string, tableName: string} $where The row's uuid and its table.
	 *
	 * @return void
	 */
	private function logFailedSave(Exception $error, array $where): void {
		if ($error instanceof ObjectExistsException) {
			return;
		}

		$this->logger->error(
			message: '[MagicMapper] Failed to save object to register+schema table',
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $error->getMessage()] + $where
		);
	}//end logFailedSave()

	/**
	 * What the last single-object write actually did.
	 *
	 * `create` or `update` — as decided by THIS layer's lookup, which is the
	 * one that ran last and therefore the one that matches the row. A caller
	 * that labelled the operation from its own earlier lookup should prefer
	 * this for anything it records, because between the two lookups a
	 * concurrent writer can turn a create into an update.
	 *
	 * Empty before any write in this request.
	 *
	 * @return string `create`, `update`, or ''.
	 */
	public function getLastWriteAction(): string {
		return $this->lastWriteAction;
	}//end getLastWriteAction()

	/**
	 * Prepare object data for storage in register+schema table
	 *
	 * @param array          $objectData The object data to prepare
	 * @param RegisterSchema $table      The register context and the schema for validation
	 *
	 * @return (false|mixed|null|string)[]
	 *
	 * @psalm-return array<string, false|mixed|null|string>
	 */
	private function prepareObjectDataForTable(array $objectData, RegisterSchema $table): array {
		$now    = new DateTime();
		$schema = $table->schema;

		$data = $objectData;
		unset($data['@self']);

		// An encrypted property is written only as an envelope, whichever path
		// got here (see MagicFlaggedEncryption).
		$data = (new MagicFlaggedEncryption(container: $this->container))->forObject(data: $data, schema: $schema);

		$rowMetadata = new MagicRowMetadata(container: $this->container);
		$metadata = $rowMetadata->authoritative(
			objectData: $objectData,
			ids: ['register' => $table->register->getId(), 'schema' => $schema->getId()]
		);
		$metadata['created'] ??= $now;
		$metadata['updated'] ??= $now;

		$preparedData = $rowMetadata->columns(metadata: $metadata, schema: $schema);
		$propertyColumns = new MagicPropertyColumns(container: $this->container, logger: $this->logger);
		$preparedData = array_replace($preparedData, $propertyColumns->forObject(data: $data, schema: $schema));

		$this->droppedProperties ??= new MagicDroppedProperties(logger: $this->logger);
		$this->droppedProperties->report(data: $data, schema: $schema);

		return $preparedData;
	}//end prepareObjectDataForTable()

	/**
	 * Convert database row to ObjectEntity.
	 *
	 * This method is public to allow bulk handlers to convert rows for event dispatching.
	 * Delegates to MagicStatisticsHandler.
	 *
	 * @param array $row Database row
	 * @param Register $_register Register context
	 * @param Schema $_schema Schema context
	 *
	 * @return ObjectEntity|null Converted entity or null on failure
	 */
	public function convertRowToObjectEntity(array $row, Register $_register, Schema $_schema): ?ObjectEntity {
		return $this->statisticsHandler->convertRowToObjectEntity(
			row: $row,
			_register: $_register,
			_schema: $_schema
		);
	}//end convertRowToObjectEntity()

	/**
	 * Check if register+schema table exists (with caching)
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return bool True if table exists
	 */
	public function tableExistsForRegisterSchema(Register $register, Schema $schema): bool {
		return $this->tableHandler->tableExistsForRegisterSchema(register: $register, schema: $schema);
	}//end tableExistsForRegisterSchema()

	/**
	 * Convert snake_case column name back to camelCase property name.
	 *
	 * Used when reading data from magic mapper tables to restore the original
	 * property names that OpenRegister expects.
	 *
	 * Examples:
	 * - in_stock -> inStock
	 * - first_name -> firstName
	 * - is_active -> isActive
	 *
	 * /**
	 * Check if register+schema combination has changed since last table update
	 *
	 * @param Register $register The register to check
	 * @param Schema $schema The schema to check
	 *
	 * @return bool True if register+schema has changed
	 */
	public function hasRegisterSchemaChanged(Register $register, Schema $schema): bool {
		$registerId = $register->getId();
		$schemaId = $schema->getId();
		$this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

		$currentVersion = $this->getStoredRegisterSchemaVersion(registerId: $registerId, schemaId: $schemaId);
		$newVersion = $this->calculateRegisterSchemaVersion(register: $register, schema: $schema);

		return $currentVersion !== $newVersion;
	}//end hasRegisterSchemaChanged()

	/**
	 * Store register+schema version for change detection
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema to store version for
	 *
	 * @return void
	 */
	public function storeRegisterSchemaVersion(Register $register, Schema $schema): void {
		$registerId = $register->getId();
		$schemaId = $schema->getId();
		$cacheKey = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

		$version = $this->calculateRegisterSchemaVersion(register: $register, schema: $schema);
		$configKey = 'table_version_' . $cacheKey;

		$this->appConfig->setValueString('openregister', $configKey, $version);

		// Also update structure cache.
		self::$tableStructureCache[$cacheKey] = $version;
	}//end storeRegisterSchemaVersion()

	/**
	 * Get stored register+schema version
	 *
	 * @param int $registerId The register ID
	 * @param int $schemaId The schema ID
	 *
	 * @return null|string The stored version or null if not found
	 */
	private function getStoredRegisterSchemaVersion(int $registerId, int $schemaId): ?string {
		$cacheKey = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

		// Check in-memory cache first to avoid database query.
		if (isset(self::$tableStructureCache[$cacheKey]) === true) {
			return self::$tableStructureCache[$cacheKey];
		}

		// Fall back to appConfig (database).
		$configKey = 'table_version_' . $cacheKey;
		$version = $this->appConfig->getValueString('openregister', $configKey, '');

		if ($version === '') {
			return null;
		}

		// Store in cache for future calls.
		self::$tableStructureCache[$cacheKey] = $version;

		return $version;
	}//end getStoredRegisterSchemaVersion()

	/**
	 * Calculate register+schema version hash for change detection
	 *
	 * @param Register $register The register to calculate version for
	 * @param Schema $schema The schema to calculate version for
	 *
	 * @return string Register+schema version hash
	 */
	private function calculateRegisterSchemaVersion(Register $register, Schema $schema): string {
		$registerId = $register->getId();
		$schemaId = $schema->getId();

		// BUG-DB-13: the request-scoped memo was keyed only by register+schema id,
		// so a schema edited within the same request returned the stale hash and
		// hasRegisterSchemaChanged() missed the change (table sync skipped).
		// Fold the schema/register version + updated timestamp into the memo key
		// so an intra-request mutation produces a fresh key (and a fresh hash).
		$schemaUpdated = $schema->getUpdated();
		$registerUpdated = $register->getUpdated();

		$schemaUpdatedStamp = '0';
		if ($schemaUpdated !== null) {
			$schemaUpdatedStamp = (string)$schemaUpdated->getTimestamp();
		}

		$registerUpdatedStamp = '0';
		if ($registerUpdated !== null) {
			$registerUpdatedStamp = (string)$registerUpdated->getTimestamp();
		}

		$cacheKey = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId)
			. '_' . (string)$schema->getVersion()
			. '_' . $schemaUpdatedStamp
			. '_' . (string)$register->getVersion()
			. '_' . $registerUpdatedStamp;

		// Check cache first to avoid expensive json_encode + md5.
		if (isset(self::$calcVersionCache[$cacheKey]) === true) {
			return self::$calcVersionCache[$cacheKey];
		}

		$combinedData = [
			'register' => [
				'id' => $registerId,
				'title' => $register->getTitle(),
				'version' => $register->getVersion(),
			],
			'schema' => [
				'id' => $schemaId,
				'properties' => $schema->getProperties(),
				'required' => $schema->getRequired(),
				'title' => $schema->getTitle(),
				'version' => $schema->getVersion(),
			],
		];

		$version = md5(json_encode($combinedData));

		// Cache for future calls within this request.
		self::$calcVersionCache[$cacheKey] = $version;

		return $version;
	}//end calculateRegisterSchemaVersion()

	/**
	 * Find object in register+schema table by UUID
	 *
	 * @param string $uuid The object UUID
	 * @param string $tableName The table name
	 *
	 * @return array|null Object data or null if not found
	 */
	protected function findObjectInRegisterSchemaTable(string $uuid, string $tableName): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($tableName)
				->where($qb->expr()->eq(self::METADATA_PREFIX . 'uuid', $qb->createNamedParameter($uuid)));

			$result = $qb->executeQuery();
			$row = $result->fetch();

			if (is_array($row) === false) {
				return null;
			}

			return $row;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to find object in register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'tableName' => $tableName,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}//end try
	}//end findObjectInRegisterSchemaTable()

	/**
	 * Insert object into register+schema table
	 *
	 * @param array $data The object data to insert
	 * @param string $tableName The table name
	 *
	 * @throws Exception If insert fails
	 *
	 * @return int The generated `_id` of the inserted row.
	 */
	private function insertObjectInRegisterSchemaTable(array $data, string $tableName): int {
		// A write that may only land on a live row never inserts: the row it was meant for is gone.
		$refusal = ($this->liveRowOnlyWrites[(string)($data[self::METADATA_PREFIX . 'uuid'] ?? '')] ?? null);
		if ($refusal !== null) {
			throw $refusal;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert($tableName);

		foreach ($data as $column => $value) {
			// Convert empty strings to NULL to prevent PostgreSQL JSON/JSONB column errors.
			// PostgreSQL rejects empty strings for JSON columns with "invalid input syntax for type json".
			if ($value === '') {
				$value = null;
			}

			$qb->setValue($column, $qb->createNamedParameter($value));
		}

		$qb->executeStatement();

		// Read the generated key from the INSERT itself rather than by selecting
		// the row back. This used to return void, which is the only reason a
		// re-read was needed at all: `_id` is the sole database-generated column
		// on these tables, so throwing it away here forced a SELECT against a
		// table written milliseconds earlier — Nextcloud's "dirty table reads"
		// condition, ~5.9 MB of serialised backtrace per object.
		//
		// On PostgreSQL this resolves to lastval(), which is session-scoped
		// rather than table-scoped. That is exact here for two reasons that must
		// both hold: these tables carry exactly ONE sequence (`_id` — verified
		// against information_schema, no other column has a default), and this
		// call is the very next statement on the same connection. If a second
		// serial column is ever added to the magic-table shape, this must change
		// to an explicit RETURNING/sequence read.
		return (int)$this->db->lastInsertId($tableName);
	}//end insertObjectInRegisterSchemaTable()

	/**
	 * Update a stored object, as a compare-and-swap when the save moves it out of draft.
	 *
	 * A save that leaves draft writes its status conditionally: of two
	 * requests that both read the object as a draft, the database lets one
	 * change the row and the other changes nothing and is refused, so the
	 * creation is announced once (DraftStatusPolicy::consumeLeaving()). Only that
	 * first write is conditional: a later write of the same object in the same
	 * request finds it active and is an ordinary update.
	 *
	 * @param MagicRowWrite $write The write: its uuid, prepared column values (row) and table name.
	 *
	 * @return void
	 *
	 * @throws DraftAlreadyLeftException When the save leaves draft and the stored object already left it.
	 * @throws Exception If the update fails.
	 */
	private function updateExistingObject(MagicRowWrite $write): void {
		$uuid    = $write->uuid;
		$claimed = EmptyPropertyClaim::consume(uuid: $uuid);
		if ($claimed !== null) {
			$this->updateClaimedObject(uuid: $uuid, write: ['data' => $write->row, 'table' => $write->tableName, 'property' => $claimed]);
			return;
		}

		$leavingDraft = DraftStatusPolicy::consumeLeaving(uuid: $uuid);
		$onlyFromStatus = null;
		if ($leavingDraft === true) {
			$onlyFromStatus = ObjectEntity::STATUS_DRAFT;
		}

		$changed = $this->updateObjectInRegisterSchemaTable(write: $write, onlyFromStatus: $onlyFromStatus);
		if ($leavingDraft === true && $changed === 0) {
			throw new DraftAlreadyLeftException(message: 'This draft has already left draft.');
		}
	}//end updateExistingObject()

	/**
	 * Update a stored object only while the claimed property's column is still empty.
	 *
	 * @param string                                                     $uuid  The object UUID.
	 * @param array{data: array<string, mixed>, table: string, property: string} $write The column values, the table and the claimed property.
	 *
	 * @return void
	 *
	 * @throws PropertyAlreadySetException When the property was filled after it was read as empty.
	 *
	 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
	 */
	private function updateClaimedObject(string $uuid, array $write): void {
		$column = $this->sanitizeColumnName(name: $write['property']);
		$qb = $this->db->getQueryBuilder();
		$qb->update($write['table']);
		foreach ($write['data'] as $name => $value) {
			if ($name === self::METADATA_PREFIX . 'uuid') {
				continue;
			}

			if ($value === '') {
				$value = null;
			}

			$qb->set($name, $qb->createNamedParameter($value));
		}

		$qb->where($qb->expr()->eq(self::METADATA_PREFIX . 'uuid', $qb->createNamedParameter($uuid)));
		$qb->andWhere($qb->expr()->orX($qb->expr()->isNull($column), $qb->expr()->eq($column, $qb->createNamedParameter(''))));

		if ((int)$qb->executeStatement() === 0) {
			throw new PropertyAlreadySetException(message: 'This object is already assigned.');
		}
	}//end updateClaimedObject()

	/**
	 * Update object in register+schema table
	 *
	 * @param MagicRowWrite $write          The write: its uuid, the column values to update (row) and the table name
	 * @param string|null   $onlyFromStatus When set, the row is only changed while its stored `_status` is this (compare-and-swap).
	 *
	 * @throws HookStoppedException When the write may only land on a live row and the row is deleted.
	 * @throws Exception If update fails
	 *
	 * @return int The number of rows changed.
	 */
	private function updateObjectInRegisterSchemaTable(MagicRowWrite $write, ?string $onlyFromStatus = null): int {
		$uuid = $write->uuid;
		$qb   = $this->db->getQueryBuilder();
		$qb->update($write->tableName);

		foreach ($write->row as $column => $value) {
			// Don't update the UUID itself.
			if ($column !== self::METADATA_PREFIX . 'uuid') {
				// Convert empty strings to NULL to prevent PostgreSQL JSON/JSONB column errors.
				// PostgreSQL rejects empty strings for JSON columns with "invalid input syntax for type json".
				if ($value === '') {
					$value = null;
				}

				$qb->set($column, $qb->createNamedParameter($value));
			}
		}

		$qb->where($qb->expr()->eq(self::METADATA_PREFIX . 'uuid', $qb->createNamedParameter($uuid)));
		if ($onlyFromStatus !== null) {
			$qb->andWhere($qb->expr()->eq(self::METADATA_PREFIX . 'status', $qb->createNamedParameter($onlyFromStatus)));
		}

		$liveOnly = ($this->liveRowOnlyWrites[$uuid] ?? null);
		if ($liveOnly !== null) {
			$qb->andWhere($qb->expr()->isNull(self::METADATA_PREFIX . 'deleted'));
		}

		$changed = (int)$qb->executeStatement();
		if ($liveOnly !== null && $changed === 0) {
			throw $liveOnly;
		}

		return $changed;
	}//end updateObjectInRegisterSchemaTable()

	/**
	 * Get existing table columns
	 *
	 * @param string $tableName The table name
	 *
	 * @throws Exception If unable to get table columns
	 *
	 * @return (bool|mixed)[][] Array of existing column definitions
	 */
	public function getExistingTableColumns(string $tableName): array {
		return (new MagicTableColumnReader(db: $this->db, logger: $this->logger))->read(
			tableName: $tableName,
			fullTableName: $this->getFullTableName(tableName: $tableName)
		);
	}//end getExistingTableColumns()

	/**
	 * Update table structure with new columns
	 *
	 * @param string $tableName The table name
	 * @param array $currentColumns Current column definitions
	 * @param array $requiredColumns Required column definitions
	 *
	 * @throws Exception If table update fails
	 *
	 * @return array Column operation statistics with keys columnsAdded, columnsDeRequired, columnsReRequired, columnsDropped
	 */
	public function updateTableStructure(string $tableName, array $currentColumns, array $requiredColumns): array {
		$columns = new MagicColumnDrift(current: $currentColumns, required: $requiredColumns);

		return $this->tableAlterer()->update(sync: $this->tableSync(tableName: $tableName, columns: $columns));
	}//end updateTableStructure()

	/**
	 * The table alterer of this mapper.
	 *
	 * @return MagicTableAlterer
	 */
	private function tableAlterer(): MagicTableAlterer {
		$sizes = new MagicNumberColumnSizes(db: $this->db, tablePrefix: $this->getTablePrefix());

		return new MagicTableAlterer(sql: $this->tableSql(), sizes: $sizes);
	}//end tableAlterer()

	/**
	 * Whether the connection is PostgreSQL.
	 *
	 * @return bool
	 */
	private function onPostgres(): bool {
		return $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform;
	}//end onPostgres()

	/**
	 * The sync of one table against the given columns.
	 *
	 * @param string           $tableName The table name, without prefix.
	 * @param MagicColumnDrift $columns   The live and required columns.
	 *
	 * @return TableStructureSync
	 */
	private function tableSync(string $tableName, MagicColumnDrift $columns): TableStructureSync {
		return new TableStructureSync(
			tableName: $tableName,
			fullTableName: $this->getFullTableName(tableName: $tableName),
			columns: $columns,
			isPostgres: $this->onPostgres()
		);
	}//end tableSync()

	/**
	 * Identify required columns whose physical column is absent from the live table.
	 *
	 * Required columns are keyed by schema PROPERTY name (camelCase) while
	 * getExistingTableColumns() keys by PHYSICAL column name (snake_case). A raw
	 * array_diff_key() between the two therefore reports every multi-word
	 * camelCase property (e.g. `allowedApps` → `allowed_apps`) as missing on
	 * every request, forcing a table sync that can never converge. Resolve the
	 * physical name first — the same resolution addMissingColumns() applies.
	 *
	 * @param array $currentColumns Live column definitions keyed by physical name (see
	 *                              getExistingTableColumns()).
	 * @param array $requiredColumns Required column definitions keyed by property name (see
	 *                               buildTableColumnsFromSchema()).
	 *
	 * @return array Missing column definitions keyed by property name.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function findMissingColumns(array $currentColumns, array $requiredColumns): array {
		return (new MagicColumnDrift(current: $currentColumns, required: $requiredColumns))->missing();
	}//end findMissingColumns()

	/**
	 * Identify object-typed columns still backed by PostgreSQL JSONB that need retyping
	 * to JSON for key-order preservation (issue #1720).
	 *
	 * Returns the list of column names where:
	 *   - the schema column definition is type `json_ordered` (object-typed schema property), and
	 *   - the live database column is `jsonb` (the legacy storage type).
	 *
	 * MySQL/MariaDB JSON already preserves document order; this helper short-circuits to
	 * an empty list on any non-PostgreSQL platform, so the fast-path check stays cheap.
	 *
	 * @param array $currentColumns Live column definitions from information_schema (see
	 *                              getExistingTableColumns()).
	 * @param array $requiredColumns Required column definitions from the schema (see
	 *                               buildTableColumnsFromSchema()).
	 *
	 * @return string[] Column names whose storage type needs to change from JSONB to JSON.
	 */
	public function findJsonbColumnsNeedingRetype(array $currentColumns, array $requiredColumns): array {
		if ($this->onPostgres() === false) {
			return [];
		}

		return (new MagicColumnDrift(current: $currentColumns, required: $requiredColumns))->storedAs(declaredType: 'json_ordered', liveTypes: ['jsonb']);
	}//end findJsonbColumnsNeedingRetype()

	/**
	 * Find columns that must become a UUID reference but are still stored as JSON.
	 *
	 * When a property is turned INTO a relation (its handling becomes related-schema /
	 * related-object), it stops holding a document and starts holding a bare UUID
	 * string. A pre-existing `json` / `jsonb` column then rejects every write:
	 *
	 *     SQLSTATE[22P02]: invalid input syntax for type json
	 *     DETAIL: Token "b25f5f9c" is invalid.
	 *
	 * Without this the table is broken FOREVER after such an edit: the sync only ever
	 * added missing columns and migrated jsonb→json, so it never noticed that an
	 * existing column's type no longer matches what the property now stores.
	 *
	 * @param array $currentColumns Live columns, keyed by physical column name.
	 * @param array $requiredColumns Desired columns, keyed by property name.
	 *
	 * @return array<string> Physical column names needing a JSON → VARCHAR retype.
	 *
	 * @spec exclude schema-drift detection for the magic-table sync
	 */
	public function findRelationColumnsNeedingRetype(array $currentColumns, array $requiredColumns): array {
		return (new MagicColumnDrift(current: $currentColumns, required: $requiredColumns))->storedAs(declaredType: 'string', liveTypes: ['json', 'jsonb']);
	}//end findRelationColumnsNeedingRetype()

	/**
	 * Convert JSON columns that now hold a UUID reference to VARCHAR.
	 *
	 * Deliberately NON-destructive. The ALTER is attempted inside a try/catch, and a
	 * failure is logged rather than swallowed or forced: a column that still holds real
	 * documents (because the property used to be `nested-*`) cannot become a 255-char
	 * UUID without losing data, and quietly truncating a user's objects to make a schema
	 * edit "work" would be far worse than the edit failing loudly.
	 *
	 * `#>> '{}'` extracts a JSON scalar as text, so a column that already holds only
	 * UUID strings (or nulls) converts cleanly.
	 *
	 * @param string           $tableName The magic table.
	 * @param MagicColumnDrift $columns   The live columns (keyed by physical column name) and the desired ones (keyed by property name).
	 *
	 * @return array<string> The column names actually retyped.
	 *
	 * @spec exclude magic-table DDL sync
	 */
	public function migrateRelationColumnsToVarchar(string $tableName, MagicColumnDrift $columns): array {
		return $this->tableAlterer()->retypeRelations(sync: $this->tableSync(tableName: $tableName, columns: $columns));
	}//end migrateRelationColumnsToVarchar()

	/**
	 * Find number columns narrower than their property now maps to.
	 *
	 * A table made before a number column took its scale from the schema has
	 * DECIMAL(10,2) columns, which round every value with more decimals.
	 *
	 * @param array $currentColumns  Live columns, keyed by physical column name.
	 * @param array $requiredColumns Desired columns, keyed by property name.
	 *
	 * @return array<string> Physical column names to widen.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function findDecimalColumnsNeedingWiden(array $currentColumns, array $requiredColumns): array {
		$columns = new MagicColumnDrift(current: $currentColumns, required: $requiredColumns);

		return array_keys($this->tableAlterer()->widener()->widenings(columns: $columns));
	}//end findDecimalColumnsNeedingWiden()

	/**
	 * Widen number columns in place; never narrows, so no stored value changes.
	 *
	 * A failure is logged and the column left as it is: the write path refuses
	 * a value the property's column size cannot hold, so nothing is rounded.
	 *
	 * @param string           $tableName The magic table (without prefix).
	 * @param MagicColumnDrift $columns   The live columns (keyed by physical column name) and the desired ones (keyed by property name).
	 *
	 * @return array<string> The column names widened.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widenDecimalColumns(string $tableName, MagicColumnDrift $columns): array {
		return $this->tableAlterer()->widener()->widen(sync: $this->tableSync(tableName: $tableName, columns: $columns));
	}//end widenDecimalColumns()

	/**
	 * Widen every number column of a table to at least the default DECIMAL(20,6).
	 *
	 * The upgrade step runs this over the physical tables, so a table whose
	 * schema no register lists any more is widened too. A property whose
	 * multipleOf needs a larger scale gets it from the reconcile command or its
	 * next schema change.
	 *
	 * @param string $tableName The magic table (without prefix).
	 *
	 * @return array<string> The column names widened.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widenNumberColumnsToDefault(string $tableName): array {
		$currentColumns = $this->getExistingTableColumns(tableName: $tableName);
		$requiredColumns = $this->tableAlterer()->widener()->defaultSizes(currentColumns: $currentColumns);

		return $this->widenDecimalColumns(tableName: $tableName, columns: new MagicColumnDrift(current: $currentColumns, required: $requiredColumns));
	}//end widenNumberColumnsToDefault()

	/**
	 * Refuse a row holding a number its table's column would round.
	 *
	 * Reads the column's actual size, so a table not yet widened refuses what
	 * its old DECIMAL(10,2) would round instead of rounding it.
	 *
	 * @param array  $row       The prepared row, keyed by column name.
	 * @param string $tableName The magic table (without prefix).
	 *
	 * @throws \OCA\OpenRegister\Exception\ValidationException When a column cannot hold its value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-the-column-cannot-hold-is-refused-not-rounded-req-mtc-002
	 */
	private function refuseNumbersTheColumnsWouldRound(array $row, string $tableName): void {
		$sizes = (new MagicNumberColumnSizes(db: $this->db, tablePrefix: $this->getTablePrefix()))->sizes(tableName: $tableName);
		(new MagicNumberColumn())->assertRowFits(row: $row, columnSizes: $sizes);
	}//end refuseNumbersTheColumnsWouldRound()

	/**
	 * Quote a column or identifier name for the current database platform.
	 *
	 * @param string $name The unquoted identifier name.
	 * @param bool $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return string The quoted identifier.
	 */
	private function quoteIdentifier(string $name, bool $isPostgres): string {
		return (new MagicColumnSql(isPostgres: $isPostgres))->quote(name: $name);
	}//end quoteIdentifier()

	/**
	 * Format a default value for SQL statement.
	 *
	 * @param mixed $default The default value
	 *
	 * @return string SQL-formatted default value
	 */
	private function formatDefaultValueForSQL(mixed $default): string {
		return (new MagicColumnAdder(db: $this->db, logger: $this->logger))->defaultLiteral(default: $default);
	}//end formatDefaultValueForSQL()

	/**
	 * Update table indexes
	 *
	 * @param string $tableName The table name
	 * @param Register $register The register context
	 * @param Schema $schema The schema for index analysis
	 *
	 * @return void
	 */
	public function updateTableIndexes(string $tableName, Register $register, Schema $schema): void {
		// For now, recreate all indexes (more complex differential updates can be added later).
		$this->createTableIndexes(tableName: $tableName, schema: $schema);
	}//end updateTableIndexes()

	/**
	 * Drop table
	 *
	 * @param string $tableName The table name to drop
	 *
	 * @throws Exception If table drop fails
	 *
	 * @return void
	 *
	 * @psalm-suppress UndefinedInterfaceMethod quoteIdentifier exists via DBAL Connection
	 */
	public function dropTable(string $tableName): void {
		try {
			// Use direct SQL to drop table (Nextcloud 32 compatible).
			$qb = $this->db->getQueryBuilder();
			$connection = $qb->getConnection();
			$fullTableName = $this->getFullTableName(tableName: $tableName);

			// Quote via the PLATFORM, not the connection: Nextcloud's
			// ConnectionAdapter has no quoteIdentifier(), so the previous
			// $connection->quoteIdentifier() threw "Call to undefined method"
			// on EVERY call — dropTable() could never actually drop anything,
			// which is why deleted schemas kept leaving their magic table behind.
			// getDatabasePlatform()->quoteIdentifier() is the pattern the rest of
			// the codebase already uses (DbalObjectSourceProvider, DatabaseIntrospectionService).
			$quotedTable = $connection->getDatabasePlatform()->quoteIdentifier($fullTableName);
			$connection->executeStatement('DROP TABLE IF EXISTS ' . $quotedTable);

			// A table recreated under this name must be inspected afresh.
			$this->getMetadataColumnHealer()->forget(tableName: $tableName);

			// Clear from cache - need to clear by table name pattern.
			foreach (array_keys(self::$tableExistsCache) as $cacheKey) {
				if ((self::$regSchemaTableCache[$cacheKey] ?? null) !== null
					&& self::$regSchemaTableCache[$cacheKey] === $tableName
				) {
					$this->invalidateTableCache(cacheKey: $cacheKey);
					break;
				}
			}

			$this->logger->debug(
				message: '[MagicMapper] Dropped register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
				]
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to drop table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'error' => $e->getMessage(),
				]
			);

			throw $e;
		}//end try
	}//end dropTable()

	/**
	 * Clear all caches for MagicMapper.
	 *
	 * @param int|null $registerId Optional register ID to clear cache for specific register
	 * @param int|null $schemaId Optional schema ID to clear cache for specific schema
	 *
	 * @return void
	 */
	public function clearCache(?int $registerId = null, ?int $schemaId = null): void {
		$this->tableHandler->clearCache(registerId: $registerId, schemaId: $schemaId);
	}//end clearCache()

	/**
	 * Get all existing register+schema tables
	 *
	 * This method scans the database for all tables matching our naming pattern
	 * and returns them as an array of register+schema combinations.
	 *
	 * @return (int|string)[][] Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string].
	 */
	public function getExistingRegisterSchemaTables(): array {
		return $this->tableHandler->getExistingRegisterSchemaTables();
	}//end getExistingRegisterSchemaTables()

	/**
	 * Check if MagicMapper is enabled for a register+schema combination
	 *
	 * @param Register $_register The register to check
	 * @param Schema $schema The schema to check
	 *
	 * @return bool True if MagicMapper should be used for this register+schema
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function isMagicMappingEnabled(Register $_register, Schema $schema): bool {
		return $this->tableHandler->isMagicMappingEnabled(_register: $_register, schema: $schema);
	}//end isMagicMappingEnabled()

	/**
	 * BACKWARD COMPATIBILITY: Check if MagicMapper is enabled for a schema only
	 *
	 * @param Schema $schema The schema to check
	 *
	 * @deprecated Use isMagicMappingEnabled(Register, Schema) instead
	 * @return bool True if MagicMapper should be used for this schema
	 */
	public function isMagicMappingEnabledForSchema(Schema $schema): bool {
		return $this->tableHandler->isMagicMappingEnabledForSchema(schema: $schema);
	}//end isMagicMappingEnabledForSchema()

	// ==================================================================================.
	// OBJECTENTITY-COMPATIBLE METHODS (Internal Magic Table Operations).
	// ==================================================================================.

	/**
	 * Find object in register+schema table by identifier (ID, UUID, slug, or URI).
	 *
	 * This method provides ObjectEntity compatibility for the public find() method.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $_rbac Whether to apply RBAC.
	 * @param bool $_multitenancy Whether to apply multi-tenancy.
	 * @param bool $includeDeleted Whether to include soft-deleted objects.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 *
	 * @return ObjectEntity The found object.
	 */
	public function findInRegisterSchemaTable(
		string|int $identifier,
		Register $register,
		Schema $schema,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $includeDeleted = false,
	): ObjectEntity {
		$find = new MagicIdentifierFind(identifier: $identifier, includeDeleted: $includeDeleted);
		$table = new RegisterSchema(register: $register, schema: $schema);
		return $this->findInTable(table: $table, find: $find, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end findInRegisterSchemaTable()

	/**
	 * Find an object in a register+schema table by identifier, under the same access filters as the list path (see findInRegisterSchemaTable()).
	 *
	 * @param RegisterSchema      $table         The register and schema whose table to search.
	 * @param MagicIdentifierFind $find          The identifier and whether deleted objects count.
	 * @param bool                $_rbac         Whether to apply RBAC checks.
	 * @param bool                $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return ObjectEntity The found object.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findInTable(RegisterSchema $table, MagicIdentifierFind $find, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity {
		$register       = $table->register;
		$schema         = $table->schema;
		$identifier     = $find->identifier;
		$includeDeleted = $find->includeDeleted;

		$this->ensureTableWhenMagicEnabled(register: $register, schema: $schema);

		$lookup = (new MagicObjectLookup(register: $register, schema: $schema))->lookingFor(
			identifier: $identifier,
			tableName: $this->getTableNameForRegisterSchema(register: $register, schema: $schema)
		);

		$this->logger->debug(
			message: '[MagicMapper] Finding object in register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => $identifier,
				'tableName' => $lookup->tableName,
				'rbac' => $_rbac,
				'multitenancy' => $_multitenancy,
			]
		);

		$qb = $this->identifierLookupQuery(lookup: $lookup);

		// Exclude deleted objects by default (unless includeDeleted is true).
		if ($includeDeleted === false) {
			$qb->andWhere($qb->expr()->isNull('t.' . self::METADATA_PREFIX . 'deleted'));
		}

		// SECURITY (cross-org IDOR fix): apply the SAME multitenancy + RBAC
		// access-control filters the list/search path uses, through the search
		// handler's shared logic, which:
		// - drops the org filter for `public`-read schemas (published reads,
		// including anonymous, keep working),
		// - lets admins (rbac/multitenancy disabled by the controller) and
		// in-org / owner reads through,
		// - filters out other-org rows → no row → DoesNotExistException → 404
		// (existence is not leaked).
		if ($_rbac === true || OrganisationContext::multitenancy(requested: $_multitenancy) === true) {
			$this->searchHandler->applyAccessControlToQuery(
				qb: $qb,
				schema: $schema,
				_rbac: $_rbac,
				_multitenancy: $_multitenancy,
				registerId: $register->getId(),
				tableName: $lookup->tableName
			);
		}

		return $this->fetchSingleObject(qb: $qb, lookup: $lookup);
	}//end findInTable()

	/**
	 * Create the register+schema table when it is missing and magic mapping is enabled for the schema.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return bool False when the table is missing and magic mapping is off.
	 */
	private function ensureTableWhenMagicEnabled(Register $register, Schema $schema): bool {
		if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === true) {
			return true;
		}

		$isMagicEnabled = $register->isMagicMappingEnabledForSchema(
			schemaId: $schema->getId(),
			schemaSlug: $schema->getSlug()
		);
		if ($isMagicEnabled === false) {
			return false;
		}

		$this->logger->debug(
			message: '[MagicMapper] Register+schema table does not exist but magic mapping enabled, creating table',
			context: ['file' => __FILE__, 'line' => __LINE__, ...$this->tableIds(register: $register, schema: $schema)]
		);
		$this->ensureTableForRegisterSchema(register: $register, schema: $schema);
		return true;
	}//end ensureTableWhenMagicEnabled()

	/**
	 * The register and schema ids of a magic table, as log context.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array{registerId: int|null, schemaId: int|null} The ids.
	 *
	 * @spec exclude logging plumbing, not a capability of its own
	 */
	private function tableIds(Register $register, Schema $schema): array {
		return ['registerId' => $register->getId(), 'schemaId' => $schema->getId()];
	}//end tableIds()

	/**
	 * The query selecting the rows whose ID, UUID, slug or URI is the identifier, key matches first.
	 *
	 * The table alias `t` lets the shared access-control filters (which reference
	 * t._organisation / t._owner) apply, so the single-object read enforces the
	 * same isolation as the list path.
	 *
	 * @param MagicObjectLookup $lookup The lookup.
	 *
	 * @return IQueryBuilder The query.
	 */
	private function identifierLookupQuery(MagicObjectLookup $lookup): IQueryBuilder {
		$identifier = $lookup->identifier;
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($lookup->tableName, 't');

		$idParam = ($this->identifierAsId(identifier: $identifier) ?? -1);

		$idCol = 't.' . self::METADATA_PREFIX . 'id';
		$uuidCol = 't.' . self::METADATA_PREFIX . 'uuid';
		$slugCol = 't.' . self::METADATA_PREFIX . 'slug';
		$uriCol = 't.' . self::METADATA_PREFIX . 'uri';
		$idNamed = $qb->createNamedParameter($idParam, IQueryBuilder::PARAM_INT);
		$uuidNamed = $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR);
		$qb->where(
			$qb->expr()->orX(
				$qb->expr()->eq($idCol, $idNamed),
				$qb->expr()->eq($uuidCol, $uuidNamed),
				$qb->expr()->eq($slugCol, $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq($uriCol, $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
			)
		);

		$this->orderKeyMatchesFirst(qb: $qb, keys: [$idCol => $idNamed, $uuidCol => $uuidNamed]);

		return $qb;
	}//end identifierLookupQuery()

	/**
	 * Run the lookup query and convert its single row to an object.
	 *
	 * @param IQueryBuilder     $qb     The lookup query.
	 * @param MagicObjectLookup $lookup The lookup.
	 *
	 * @throws DoesNotExistException If no row matches, or the query or conversion fails.
	 * @throws MultipleObjectsReturnedException If the identifier is ambiguous.
	 *
	 * @return ObjectEntity The found object.
	 */
	private function fetchSingleObject(IQueryBuilder $qb, MagicObjectLookup $lookup): ObjectEntity {
		try {
			$rows = $this->fetchRows(result: $qb->executeQuery());
			if ($rows === []) {
				throw new DoesNotExistException('Object not found in magic table');
			}

			// Several rows: a key match wins, otherwise the identifier is ambiguous (#4037).
			$row = $this->singleRow(rows: $rows, lookup: $lookup);

			$objectEntity = $this->convertRowToObjectEntity(row: $row, _register: $lookup->register, _schema: $lookup->schema);

			if ($objectEntity === null) {
				throw new DoesNotExistException('Failed to convert row to ObjectEntity');
			}

			return $objectEntity;
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			throw $e;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to find object in register+schema table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $lookup->identifier,
					'tableName' => $lookup->tableName,
					'error' => $e->getMessage(),
				]
			);

			throw new DoesNotExistException($e->getMessage());
		}//end try
	}//end fetchSingleObject()

	/**
	 * The object id an identifier names, when it is a canonical non-negative integer.
	 *
	 * `2024` is an id; `007`, `7.0`, `1e3` and `7 ` are not, they can only be a slug
	 * (or uuid or uri). The query's id condition and the precedence pick both use this,
	 * so a non-canonical number never reaches an id and never beats an exact slug match.
	 *
	 * @param string|int $identifier The identifier the caller sent.
	 *
	 * @return int|null The id, or null when the identifier is not a canonical integer.
	 */
	private function identifierAsId(string|int $identifier): ?int {
		$text = (string)$identifier;
		if (ctype_digit($text) === false || (string)(int)$text !== $text) {
			return null;
		}

		return (int)$text;
	}//end identifierAsId()

	/**
	 * Pick the row an identifier means among the rows it matched.
	 *
	 * One row is the answer. Several rows: the one whose `_id` or `_uuid` is the
	 * identifier, when exactly one is; when one row has it as `_id` and another as
	 * `_uuid`, the `_uuid` row; otherwise none (the identifier is ambiguous).
	 *
	 * @param list<array<string, mixed>> $rows       The matched rows, at least one.
	 * @param string|int                 $identifier The identifier the caller sent.
	 *
	 * @return array<string, mixed>|null The row, or null when the identifier is ambiguous.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	private function rowForIdentifier(array $rows, string|int $identifier): ?array {
		if (count($rows) === 1) {
			return $rows[0];
		}

		$byKey = [];
		$byUuid = [];
		foreach ($rows as $row) {
			$isUuid = strcasecmp((string)($row[self::METADATA_PREFIX . 'uuid'] ?? ''), (string)$identifier) === 0;
			if ($isUuid === true || $this->rowHasId(row: $row, identifier: $identifier) === true) {
				$byKey[] = $row;
			}

			if ($isUuid === true) {
				$byUuid[] = $row;
			}
		}

		if (count($byKey) === 1) {
			return $byKey[0];
		}

		// "1" can be one object's id and another object's uuid; the uuid is the public identity.
		if (count($byUuid) === 1) {
			return $byUuid[0];
		}

		return null;
	}//end rowForIdentifier()

	/**
	 * Whether a row's `_id` is the identifier, read as the query's id condition reads it.
	 *
	 * @param array<string, mixed> $row        A matched row.
	 * @param string|int           $identifier The identifier the caller sent.
	 *
	 * @return bool True when the identifier is a canonical integer equal to the row's id.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	private function rowHasId(array $row, string|int $identifier): bool {
		$idParam = $this->identifierAsId(identifier: $identifier);

		return $idParam !== null
			&& isset($row[self::METADATA_PREFIX . 'id']) === true
			&& (int)$row[self::METADATA_PREFIX . 'id'] === $idParam;
	}//end rowHasId()

	/**
	 * The one row an identifier means, or an ambiguity refusal.
	 *
	 * At most IDENTIFIER_MATCH_LIMIT rows come back, key matches first. More than
	 * one row, whatever is cut off, is still ambiguous unless a key match singles
	 * one out: a cut never turns several rows into a single pick.
	 *
	 * Several rows can match one identifier only through a slug, because the
	 * id and uuid are unique. A row matched by its id or uuid wins over rows
	 * matched by a slug that happens to look the same (a numeric slug "2024"
	 * next to the object with id 2024); only when that does not single out
	 * one row is the identifier ambiguous. Both holders are logged for the
	 * administrator and kept out of the message, which can reach an API caller.
	 *
	 * @param list<array<string, mixed>> $rows   The matched rows, at least one.
	 * @param MagicObjectLookup          $lookup The lookup: the identifier the caller sent, its register and schema (log context).
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @throws MultipleObjectsReturnedException When the identifier is ambiguous.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	private function singleRow(array $rows, MagicObjectLookup $lookup): array {
		$identifier = $lookup->identifier;
		$register   = $lookup->register;
		$schema     = $lookup->schema;
		$row        = $this->rowForIdentifier(rows: $rows, identifier: $identifier);
		if ($row !== null) {
			return $row;
		}

		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Identifier "%s" is ambiguous in register %s (%s) / schema %s (%s): matches objects %s, %s',
				(string)$identifier,
				(string)$register->getSlug(),
				(string)$register->getId(),
				(string)$schema->getSlug(),
				(string)$schema->getId(),
				(string)($rows[0][self::METADATA_PREFIX . 'uuid'] ?? '?'),
				(string)($rows[1][self::METADATA_PREFIX . 'uuid'] ?? '?')
			),
			context: [
				'app' => 'openregister',
				'path' => 'lookup',
				'registerId' => $register->getId(),
				'schemaId' => $schema->getId(),
				'slug' => (string)$identifier,
				'uuids' => array_map(static fn (array $match) => ($match[self::METADATA_PREFIX . 'uuid'] ?? null), $rows),
			]
		);
		throw new MultipleObjectsReturnedException(
			sprintf('Identifier "%s" matches more than one object in this register and schema', (string)$identifier)
		);
	}//end singleRow()

	/**
	 * Sort the rows an identifier matched by its id or uuid first, then by id, and cap them.
	 *
	 * So the precedence pick in singleRow() sees a key match whatever the cap cuts off,
	 * and the same data always gives the same answer.
	 *
	 * @param IQueryBuilder                                          $qb   The identifier query.
	 * @param array<string, \OCP\DB\QueryBuilder\IParameter|string> $keys Key column => its named parameter (id first, then uuid).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	private function orderKeyMatchesFirst(IQueryBuilder $qb, array $keys): void {
		$matches = [];
		foreach ($keys as $column => $named) {
			$matches[] = $column . ' = ' . $named;
		}

		$qb->addOrderBy($qb->createFunction('CASE WHEN ' . implode(' OR ', $matches) . ' THEN 0 ELSE 1 END'), 'ASC');
		$qb->addOrderBy((string)array_key_first($keys), 'ASC');
		$qb->setMaxResults(self::IDENTIFIER_MATCH_LIMIT);
	}//end orderKeyMatchesFirst()

	/**
	 * Every row of a result, with the cursor closed.
	 *
	 * @param IResult $result The executed query.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function fetchRows(IResult $result): array {
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = $row;
		}

		$result->closeCursor();
		return $rows;
	}//end fetchRows()

	/**
	 * The slug column of a register+schema table, or null without a table.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return MagicSlugTable|null
	 */
	private function slugTable(Register $register, Schema $schema): ?MagicSlugTable {
		if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
			return null;
		}

		return new MagicSlugTable(db: $this->db, tableName: $this->getTableNameForRegisterSchema(register: $register, schema: $schema));
	}//end slugTable()

	/**
	 * The live objects whose slug starts with a prefix, as slug => uuid pairs.
	 *
	 * For the seed import to find an object stored under a suffixed slug (`<slug>-<14 digits>`);
	 * the caller keeps the rows that really are a suffixed variant. No RBAC or multitenancy
	 * filter, as for every slug uniqueness query.
	 *
	 * @param RegisterSchema $table  The register and schema whose table to use.
	 * @param string         $prefix The slug prefix, taken literally.
	 * @param int            $limit  Most rows to return.
	 *
	 * @return list<array{slug: string, uuid: string}> The matches.
	 */
	public function findSlugsByPrefix(RegisterSchema $table, string $prefix, int $limit=50): array {
		return ($this->slugTable(register: $table->register, schema: $table->schema)?->byPrefix(prefix: $prefix, limit: $limit) ?? []);
	}//end findSlugsByPrefix()

	/**
	 * The slug an object keeps when a move or migration takes it to another register or schema (#4037).
	 *
	 * Resolved lazily, as on restore: the slug handler needs this mapper.
	 *
	 * @param ObjectEntity   $entity The object being moved (still carrying its uuid).
	 * @param RegisterSchema $table  The target register and schema.
	 *
	 * @return string|null The slug to store at the target.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function slugForMove(ObjectEntity $entity, RegisterSchema $table): ?string {
		return $this->container->get(ObjectSlugTargetInterface::class)->slugForMove(entity: $entity, table: $table);
	}//end slugForMove()

	/**
	 * Return the uuids of live objects that hold a slug in one register+schema table.
	 *
	 * No RBAC or multitenancy filter: uniqueness is a property of the data, so a
	 * caller who cannot see the holder must still not take its slug. Soft-deleted
	 * rows hold no slug, as on the read path.
	 *
	 * @param RegisterSchema $table       The register and schema whose table to use.
	 * @param string         $slug        The slug to look for.
	 * @param string|null $excludeUuid The object being saved, which never collides with itself.
	 *
	 * @return list<string> The holders' uuids (at most one: enough to know the slug is taken).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function findSlugHolders(RegisterSchema $table, string $slug, ?string $excludeUuid=null): array {
		return ($this->slugTable(register: $table->register, schema: $table->schema)?->holders(slug: $slug, excludeUuid: $excludeUuid) ?? []);
	}//end findSlugHolders()

	/**
	 * Load the slug state a bulk save or a backfill decides against, in two queries.
	 *
	 * `holders` maps each requested slug to the live uuids holding it; `stored`
	 * maps each requested uuid to the slug its live row holds now (null when the
	 * row has none, absent when the row does not exist yet). The IN lists are
	 * split into chunks of 1,000 values. No access filter, as findSlugHolders().
	 *
	 * @param RegisterSchema $table The register and schema whose table to use.
	 * @param list<string>   $slugs Candidate slugs.
	 * @param list<string>   $uuids Uuids of the rows being decided.
	 *
	 * @return array{holders: array<string, list<string>>, stored: array<string, string|null>}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function findSlugState(RegisterSchema $table, array $slugs, array $uuids): array {
		$column = $this->slugTable(register: $table->register, schema: $table->schema);
		return ($column?->state(slugs: $slugs, uuids: $uuids) ?? ['holders' => [], 'stored' => []]);
	}//end findSlugState()

	/**
	 * List every slug held by more than one live object in a register+schema table.
	 *
	 * Slugs are compared case-folded; the report is keyed by the lower-cased slug.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, list<string>> Slug => holder uuids, oldest first.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function findDuplicateSlugs(Register $register, Schema $schema): array {
		return ($this->slugTable(register: $register, schema: $schema)?->duplicates() ?? []);
	}//end findDuplicateSlugs()

	/**
	 * Every slug held by a live object in a register+schema table, with its holders.
	 *
	 * Only `_uuid` and `_slug` are read, so this stays cheap on large tables.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, list<string>> Slug => holder uuids.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function findAllSlugs(Register $register, Schema $schema): array {
		return ($this->slugTable(register: $register, schema: $schema)?->all() ?? []);
	}//end findAllSlugs()

	/**
	 * Page over the live rows of a register+schema table for the slug backfill.
	 *
	 * Keyset-paginated on `_id` (stable while the run writes). Returns the uuid,
	 * the stored slug, `_created` for the oldest-first ordering the caller applies,
	 * and the business data the slug is built from.
	 *
	 * A row that cannot be converted into an object is left out of `rows` and
	 * logged with its uuid, but still counts in `fetched` and moves `lastId`: the
	 * caller pages on what the database returned, so one unreadable row neither
	 * ends the scan early nor is read again.
	 *
	 * @param Register $register   The register.
	 * @param Schema   $schema     The schema.
	 * @param int      $afterId    Last `_id` of the previous page (0 for the first).
	 * @param int      $pageSize   Rows per page.
	 *
	 * @return array{
	 *     rows: list<array{id: int, uuid: string, slug: string|null, created: string|null, object: array}>,
	 *     fetched: int,
	 *     lastId: int
	 * } The convertible rows, how many rows the query returned, and the `_id` of the last one (`$afterId` when none).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function findSlugBackfillPage(
		Register $register,
		Schema $schema,
		int $afterId,
		int $pageSize,
	): array {
		$cursor = new SlugBackfillCursor(afterId: $afterId, pageSize: $pageSize);
		return $this->findSlugBackfillPageIn(registerSchema: new RegisterSchema(register: $register, schema: $schema), cursor: $cursor);
	}//end findSlugBackfillPage()

	/**
	 * One page of the rows a slug backfill reads from a register+schema table (see findSlugBackfillPage()).
	 *
	 * @param RegisterSchema     $registerSchema The register and schema.
	 * @param SlugBackfillCursor $cursor         Where the page starts and how many rows it reads.
	 *
	 * @return array{
	 *     rows: list<array{id: int, uuid: string, slug: string|null, created: string|null, object: array}>,
	 *     fetched: int,
	 *     lastId: int
	 * } The page.
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function findSlugBackfillPageIn(RegisterSchema $registerSchema, SlugBackfillCursor $cursor): array {
		$register = $registerSchema->register;
		$schema   = $registerSchema->schema;
		$afterId  = $cursor->afterId;
		$pageSize = $cursor->pageSize;

		$table = $this->slugTable(register: $register, schema: $schema);
		if ($table === null) {
			return ['rows' => [], 'fetched' => 0, 'lastId' => $afterId];
		}

		$convert = fn (array $row): ?ObjectEntity => $this->convertRowToObjectEntity(row: $row, _register: $register, _schema: $schema);

		return (new MagicSlugBackfill(table: $table, convert: $convert, logger: $this->logger))->page(afterId: $afterId, pageSize: $pageSize);
	}//end findSlugBackfillPageIn()

	/**
	 * Write one object's slug column directly, for the slug backfill.
	 *
	 * No version bump, audit-trail entry or object event: the slug is derived
	 * metadata, the same as openregister:backfill-system-owner (design D11).
	 *
	 * Only when the row still has no slug (null or empty), so a save that set
	 * the slug while the backfill was planning is not undone.
	 *
	 * @param RegisterSchema $table The register and schema whose table to use.
	 * @param string         $uuid  The object.
	 * @param string         $slug  The new slug.
	 *
	 * @return int Rows updated: 0 when the row changed in the meantime.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function updateSlugColumn(RegisterSchema $table, string $uuid, string $slug): int {
		$tableName = $this->getTableNameForRegisterSchema(register: $table->register, schema: $table->schema);

		return (new MagicSlugTable(db: $this->db, tableName: $tableName))->fillEmpty(uuid: $uuid, slug: $slug);
	}//end updateSlugColumn()

	/**
	 * Find objects by a list of uuids within ONE register+schema table.
	 *
	 * The batched counterpart of {@see findInRegisterSchemaTable()}: one indexed
	 * `IN` query answers "which of these uuids already exist here" for a whole
	 * batch, where the single-object method answers it one round trip at a time.
	 *
	 * Bulk save needs exactly this question — it has to classify every row of a
	 * batch as create or update before it writes any of them — and asking it per
	 * row made the create/update probe the dominant cost of a bulk write, and
	 * O(N) queries in the one code path whose entire purpose is to avoid them.
	 *
	 * Deliberately bounded to the given register+schema. The unbounded
	 * equivalent ({@see findMultipleAcrossAllMagicTables()}) unions every magic
	 * table on the instance, which answers a DIFFERENT question — "does this
	 * uuid exist anywhere" — and is the wrong one when the caller is deciding
	 * how to write into one specific table.
	 *
	 * No access-control filters are applied: this is an existence probe for
	 * callers that then run their own per-row RBAC check against the returned
	 * entity's owner, exactly as the per-row lookup it replaces did with
	 * `_rbac: false, _multitenancy: false`.
	 *
	 * @param Register $register The register whose table to search.
	 * @param Schema $schema The schema whose table to search.
	 * @param array<int, string> $uuids The uuids to look for.
	 * @param bool $includeDeleted Whether to include soft-deleted rows.
	 *
	 * @return array<string, ObjectEntity> Found entities keyed by uuid; missing uuids are simply absent.
	 */
	public function findObjectsByUuidsInRegisterSchema(
		Register $register,
		Schema $schema,
		array $uuids,
		bool $includeDeleted = false,
	): array {
		$lookup = new UuidBatchLookup(uuids: $uuids, includeDeleted: $includeDeleted);
		return $this->findByUuidsIn(table: new RegisterSchema(register: $register, schema: $schema), lookup: $lookup);
	}//end findObjectsByUuidsInRegisterSchema()

	/**
	 * Find objects by a list of uuids within ONE register+schema table, without access-control filters (see findObjectsByUuidsInRegisterSchema()).
	 *
	 * @param RegisterSchema  $table  The register and schema whose table to use.
	 * @param UuidBatchLookup $lookup The uuids to look for and whether soft-deleted rows count.
	 *
	 * @return array<string, ObjectEntity> Found entities keyed by uuid; missing uuids are simply absent.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findByUuidsIn(RegisterSchema $table, UuidBatchLookup $lookup): array {
		$register       = $table->register;
		$schema         = $table->schema;
		$uuids          = $lookup->uuids;
		$includeDeleted = $lookup->includeDeleted;

		if (empty($uuids) === true) {
			return [];
		}

		if ($this->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			return [];
		}

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$uuidCol = self::METADATA_PREFIX . 'uuid';

		$found = [];

		// Chunked so the parameter list cannot outgrow what the driver accepts;
		// a bulk save chunk can carry tens of thousands of rows.
		foreach (array_chunk(array_values(array_unique($uuids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($tableName)
				->where(
					$qb->expr()->in(
						$uuidCol,
						$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)
					)
				);

			if ($includeDeleted === false) {
				$qb->andWhere($qb->expr()->isNull(self::METADATA_PREFIX . 'deleted'));
			}

			try {
				$result = $qb->executeQuery();
				while (($row = $result->fetch()) !== false) {
					$entity = $this->convertRowToObjectEntity(row: $row, _register: $register, _schema: $schema);
					if ($entity === null) {
						continue;
					}

					$entity->setSource('orm');
					$found[(string)$entity->getUuid()] = $entity;
				}

				$result->closeCursor();
			} catch (Exception $e) {
				// An unreadable probe must not be reported as "none of these
				// exist" — that would classify every row as a create and, on a
				// re-run, write duplicates. Let the caller fall back to its
				// per-row lookup instead.
				$this->logger->error(
					message: '[MagicMapper] Bulk uuid existence probe failed',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'tableName' => $tableName,
						'uuids' => count($chunk),
						'error' => $e->getMessage(),
					]
				);

				throw $e;
			}//end try
		}//end foreach

		return $found;
	}//end findByUuidsIn()

	/**
	 * Fetch the set of existing integer primary keys from a metadata table.
	 *
	 * Used to filter out orphaned magic tables in findAcrossAllMagicTables: a
	 * magic table whose register or schema row was deleted can never hold a
	 * resolvable object, so we skip it instead of querying it.
	 *
	 * @param string $table Unprefixed table name (e.g. `openregister_registers`).
	 *
	 * @return int[] The `id` values present in the table.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function getExistingIds(string $table): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($table);
		$ids = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);

		return array_map('intval', $ids);
	}//end getExistingIds()

	/**
	 * Find an object across all magic tables without knowing register/schema upfront.
	 *
	 * This method searches all existing magic tables for an object by its identifier.
	 * It's useful for operations like lock/unlock where the caller doesn't know
	 * which storage backend contains the object.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param int|null $registerIdScope Restrict the scan to one register's tables.
	 *                                  A caller that named a register has already
	 *                                  said where the object lives; widening the
	 *                                  search past it is never a correct answer,
	 *                                  and it is what makes this method cost
	 *                                  seconds. Null keeps the historic
	 *                                  everything-everywhere behaviour.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
	 *                                                                                   The found object with its register and schema context.
	 *
	 * @throws DoesNotExistException If object not found in any magic table.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public function findAcrossAllMagicTables(
		string|int $identifier,
		bool $includeDeleted = false,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?int $registerIdScope = null,
	): array {
		$find = new MagicIdentifierFind(identifier: $identifier, includeDeleted: $includeDeleted, registerIdScope: $registerIdScope);
		return $this->findAcross(find: $find, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end findAcrossAllMagicTables()

	/**
	 * Find an object by identifier across the magic tables (see findAcrossAllMagicTables()).
	 *
	 * @param MagicIdentifierFind $find          The identifier, whether deleted objects count and the register to narrow to.
	 * @param bool                $_rbac         Whether to apply RBAC checks.
	 * @param bool                $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
	 * @throws DoesNotExistException If the object is in no magic table.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findAcross(MagicIdentifierFind $find, bool $_rbac=true, bool $_multitenancy=true): array {
		$identifier      = $find->identifier;
		$includeDeleted  = $find->includeDeleted;
		$registerIdScope = $find->registerIdScope;

		$this->logger->debug(
			message: '[MagicMapper] findAcrossAllMagicTables: Starting search',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => $identifier,
			]
		);

		$template = new MagicCrossTableHit(identifier: $identifier);
		$template->includeDeleted = $includeDeleted;
		$template->rbac           = $_rbac;
		$template->multitenancy   = $_multitenancy;

		// Two phases instead of one `SELECT *` per magic table (an N+1 of ~1,000
		// queries on a live instance). LOCATE: one UNION ALL per chunk of
		// MAGIC_TABLE_SCAN_CHUNK_SIZE tables selecting only (table, _id). FETCH:
		// one `SELECT *` from the table that holds the row. The shared metadata
		// columns are what make the union across heterogeneous tables well-typed.
		$candidates = $this->liveMagicTablesInScope(registerIdScope: $registerIdScope);
		foreach (array_chunk($candidates, self::MAGIC_TABLE_SCAN_CHUNK_SIZE, true) as $chunk) {
			$found = $this->findInMagicTableChunk(chunk: $chunk, template: $template);
			if ($found !== null) {
				return $found;
			}
		}

		// Not found in any magic table.
		throw new DoesNotExistException("Object with identifier '$identifier' not found in any magic table");
	}//end findAcross()

	/**
	 * The live (non-orphaned) magic tables, keyed by unprefixed table name, narrowed to one register when named.
	 *
	 * A caller that named a register gets its tables ONLY. Scanning every
	 * register is both wrong (another register's object does not answer
	 * "is this one of mine?") and ruinous: one union branch per magic table
	 * cost ~3.4s to plan on an instance with 2,728 of them (measured
	 * 2026-07-29, ~1.9s of a ~3.0s object create).
	 *
	 * @param int|null $registerIdScope The register to stay inside, or null for every register.
	 *
	 * @return array<string, array{registerId: int|string, schemaId: int|string}>
	 */
	private function liveMagicTablesInScope(?int $registerIdScope): array {
		$candidates = $this->getLiveMagicTables();
		if ($registerIdScope !== null) {
			$candidates = array_filter(
				$candidates,
				static fn (array $meta): bool => (int)$meta['registerId'] === $registerIdScope
			);
		}

		$this->logger->debug(
			message: '[MagicMapper] findAcrossAllMagicTables: Found magic tables',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'count' => count($candidates),
			]
		);

		return $candidates;
	}//end liveMagicTablesInScope()

	/**
	 * Locate, fetch and resolve the identifier in one chunk of magic tables.
	 *
	 * @param array<string, array{registerId: int|string, schemaId: int|string}> $chunk    The tables, keyed by name
	 * @param MagicCrossTableHit                                                 $template The identifier and flags of the lookup
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}|null The answer, or null to keep scanning.
	 */
	private function findInMagicTableChunk(array $chunk, MagicCrossTableHit $template): ?array {
		$probe   = new MagicIdentifierProbe(identifier: (string)$template->identifier, includeDeleted: $template->includeDeleted);
		$located = $this->locateIdentifierInMagicTables(tables: array_keys($chunk), probe: $probe);
		if ($located === null) {
			return null;
		}

		$hit = clone $template;
		$hit->tableName = $located['table'];
		$hit->id        = (int)$located['id'];
		$row = $this->fetchMagicRowById(hit: $hit);
		if ($row === null) {
			// Gone between locate and fetch (concurrent hard delete), or the
			// table is unreadable. Keep scanning the other chunks.
			return null;
		}

		$hit->row        = $row;
		$hit->registerId = $chunk[$located['table']]['registerId'];
		$hit->schemaId   = $chunk[$located['table']]['schemaId'];
		return $this->resolveCrossTableHit(hit: $hit);
	}//end findInMagicTableChunk()

	/**
	 * Turn a located row into the lookup's answer: load its register and
	 * schema, check the caller may read it, and convert it.
	 *
	 * @param MagicCrossTableHit $hit The located row.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}|null The answer, or null to keep scanning.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function resolveCrossTableHit(MagicCrossTableHit $hit): ?array {
		try {
			$hit->register = $this->registerMapper->find(id: $hit->registerId, _multitenancy: false);
			$hit->schema = $this->schemaMapper->find(id: $hit->schemaId, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] findAcrossAllMagicTables: Could not load register/schema',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'registerId' => $hit->registerId,
					'schemaId' => $hit->schemaId,
					'error' => $e->getMessage(),
				]
			);
		}

		if ($this->crossTableHitIsReadable(hit: $hit) === false) {
			return null;
		}

		$object = $this->convertRowToObjectEntity(
			row: $hit->row,
			_register: $hit->register,
			_schema: $hit->schema
		);

		// The converter returns null for a row it cannot hydrate. Skip that row
		// and keep searching: one unreadable row must not fail the lookup.
		if ($object === null) {
			$this->logger->warning(
				message: '[MagicMapper] findAcrossAllMagicTables: row matched but could not be hydrated; skipping it',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $hit->identifier,
					'registerId' => $hit->registerId,
					'schemaId' => $hit->schemaId,
				]
			);
			return null;
		}

		$this->logger->debug(
			message: '[MagicMapper] findAcrossAllMagicTables: Found object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $object->getUuid(),
				'registerId' => $hit->registerId,
				'schemaId' => $hit->schemaId,
			]
		);

		return [
			'object' => $object,
			'register' => $hit->register,
			'schema' => $hit->schema,
		];
	}//end resolveCrossTableHit()

	/**
	 * Whether the caller may read a located row.
	 *
	 * SECURITY (cross-org IDOR, openregister#2137): the row is re-read under
	 * the same access control filters the list/search path uses, scoped to
	 * this table. A row those filters remove is treated exactly like "not
	 * found in this table": no distinct error, no existence leak. A row whose
	 * schema could not be resolved fails closed. With RBAC off and
	 * multitenancy not in force the row is readable as is.
	 *
	 * @param MagicCrossTableHit $hit The located row, with its register and schema loaded.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext::multitenancy() is a pure request-state read
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function crossTableHitIsReadable(MagicCrossTableHit $hit): bool {
		if ($hit->rbac === false && OrganisationContext::multitenancy(requested: $hit->multitenancy) === false) {
			return true;
		}

		if ($hit->schema === null) {
			$this->logger->warning(
				message: '[MagicMapper] findAcrossAllMagicTables: Denying unverifiable cross-table hit (schema unresolved)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $hit->tableName,
					'registerId' => $hit->registerId,
					'schemaId' => $hit->schemaId,
				]
			);
			return false;
		}

		$accessQb = $this->db->getQueryBuilder();
		$accessQb->select('t.' . self::METADATA_PREFIX . 'id')->from($hit->tableName, 't');
		$accessQb->where(
			$accessQb->expr()->eq(
				't.' . self::METADATA_PREFIX . 'id',
				$accessQb->createNamedParameter($hit->id, IQueryBuilder::PARAM_INT)
			)
		);
		if ($hit->includeDeleted === false) {
			$accessQb->andWhere($accessQb->expr()->isNull('t.' . self::METADATA_PREFIX . 'deleted'));
		}

		$this->searchHandler->applyAccessControlToQuery(
			qb: $accessQb,
			schema: $hit->schema,
			_rbac: $hit->rbac,
			_multitenancy: $hit->multitenancy,
			registerId: $hit->register?->getId(),
			tableName: $hit->tableName
		);

		$accessResult = $accessQb->executeQuery();
		$accessRow = $accessResult->fetch();
		$accessResult->closeCursor();

		if ($accessRow === false) {
			$this->logger->debug(
				message: '[MagicMapper] findAcrossAllMagicTables: Denying cross-org/unauthorized hit',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $hit->tableName,
					'registerId' => $hit->registerId,
					'schemaId' => $hit->schemaId,
				]
			);
			return false;
		}

		return true;
	}//end crossTableHitIsReadable()

	/**
	 * List the live (non-orphaned) magic tables on this instance.
	 *
	 * A table `{prefix}openregister_table_{registerId}_{schemaId}` is only a live
	 * source when BOTH its register row and its schema row still exist; deleting a
	 * register or schema leaves its magic table behind, and dev/CI instances
	 * accumulate thousands of these. A stored object always lives in a table whose
	 * register AND schema rows exist, so filtering to live ids is coverage-safe.
	 *
	 * @return array<string, array{registerId: int, schemaId: int}> Unprefixed table
	 *                                                              name => parent ids.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function getLiveMagicTables(): array {
		if ($this->liveMagicTablesMemo !== null) {
			return $this->liveMagicTablesMemo;
		}

		$this->liveMagicTablesMemo = $this->loadLiveMagicTables();

		return $this->liveMagicTablesMemo;
	}//end getLiveMagicTables()

	/**
	 * Drop the request-scoped table memos.
	 *
	 * Called whenever a magic table is created, so a write that follows a table
	 * creation in the same request sees the new table rather than a cached
	 * "does not exist" / stale enumeration. A background job calls it before it
	 * looks objects up: a long-running `background-job:worker` outlives the
	 * request the memos were made for, and would not see tables created since.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function invalidateTableMemos(): void {
		$this->tableExistsMemo = [];
		$this->liveMagicTablesMemo = null;

		// The statistics handler keeps a THIRD memo of the same fact, and it was
		// not cleared here. A register whose magic table was created after the
		// first statistics call of the request therefore counted zero objects
		// while its table already held them, which is what the dashboard and
		// `exploreSchemaProperties()` were reporting.
		$this->statisticsHandler?->forgetMagicTableList();

	}//end invalidateTableMemos()

	/**
	 * Enumerate the live magic tables from the database, without memoisation.
	 *
	 * @return array<string, array<string, mixed>> Live tables keyed by unprefixed name.
	 */
	private function loadLiveMagicTables(): array {
		\OCA\OpenRegister\Service\WritePhase::count(event: 'magicTables.enumerate');

		// Get all magic tables from information_schema.
		// NOTE: We use raw SQL here because the query builder adds the table prefix.
		// to information_schema, which is a system schema and shouldn't be prefixed.
		$prefix = $this->getTablePrefix();
		$tablePattern = $prefix . 'openregister_table_%';

		$sql = 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE ?';
		$stmt = $this->db->prepare($sql);
		$result = $stmt->execute([$tablePattern]);
		$tables = $result->fetchAll();

		// PERF: skip orphaned magic tables instead of querying them.
		$validRegisterIds = array_flip($this->getExistingIds(table: 'openregister_registers'));
		$validSchemaIds = array_flip($this->getExistingIds(table: 'openregister_schemas'));

		$liveTables = [];

		foreach ($tables as $tableRow) {
			$fullTableName = $tableRow['table_name'] ?? $tableRow['TABLE_NAME'] ?? null;
			if ($fullTableName === null) {
				continue;
			}

			// Extract register and schema IDs from table name: oc_openregister_table_{registerId}_{schemaId}.
			$tableName = str_replace($prefix, '', $fullTableName);
			if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName, $matches) !== 1) {
				continue;
			}

			$registerId = (int)$matches[1];
			$schemaId = (int)$matches[2];

			// Orphaned table (register or schema row deleted) — cannot hold a
			// resolvable object; skip without querying it.
			if (isset($validRegisterIds[$registerId]) === false || isset($validSchemaIds[$schemaId]) === false) {
				continue;
			}

			$liveTables[$tableName] = [
				'registerId' => $registerId,
				'schemaId' => $schemaId,
			];
		}//end foreach

		return $liveTables;
	}//end loadLiveMagicTables()

	/**
	 * Locate which magic table in a chunk holds an identifier (phase 1 of the scan).
	 *
	 * Probes every table in the chunk with a SINGLE `UNION ALL` query instead of one
	 * query per table.
	 *
	 * MEMORY SAFETY: each union branch selects only two scalar columns — the table
	 * name and the bigint `_id` — and the whole union is capped with `LIMIT 1`. It
	 * never selects full object rows. A `SELECT *` union across every magic table
	 * materialises every matching object body at once and has previously caused an
	 * out-of-memory event on this codebase; the narrow projection plus the chunking
	 * plus the LIMIT keep the result set at a single tiny row regardless of how many
	 * tables exist.
	 *
	 * @param string[]             $tables Unprefixed magic table names to probe.
	 * @param MagicIdentifierProbe $probe  What to look for.
	 *
	 * @return array{table: string, id: int}|null The matching table and row id, or null.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function locateIdentifierInMagicTables(array $tables, MagicIdentifierProbe $probe): ?array {
		if (empty($tables) === true) {
			return null;
		}

		$branches = $this->identifierUnionBranches(tables: $tables, probe: $probe);
		if (empty($branches) === true) {
			return null;
		}

		try {
			$row = $this->runIdentifierUnion(branches: $branches, probe: $probe);
		} catch (\Exception $e) {
			// One structurally broken table fails the whole chunk query. Degrade to a
			// per-table probe for THIS chunk only, so a single bad table cannot hide the
			// other live tables in the chunk.
			$this->logger->debug(
				message: '[MagicMapper] findAcrossAllMagicTables: UNION locate failed, probing chunk per table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tables' => count($branches),
					'error' => $e->getMessage(),
				]
			);

			return $this->locateIdentifierPerTable(tables: $tables, probe: $probe);
		}//end try

		if ($row === false || $row === null) {
			return null;
		}

		$hitTable = ($row['src_table'] ?? $row['SRC_TABLE'] ?? null);
		$hitId = ($row['hit_id'] ?? $row['HIT_ID'] ?? null);

		if ($hitTable === null || $hitId === null) {
			return null;
		}

		return [
			'table' => (string)$hitTable,
			'id' => (int)$hitId,
		];
	}//end locateIdentifierInMagicTables()

	/**
	 * Build one `UNION ALL` branch per magic table for the locate query.
	 *
	 * SAFETY GATE: only a table name that matches the strict magic table pattern is
	 * ever embedded. Names originate from information_schema and are re-validated
	 * here, so neither the FROM clause nor the src_table literal can be injected.
	 * The identifier itself is never interpolated: it is always bound.
	 *
	 * @param string[]             $tables Unprefixed magic table names to probe.
	 * @param MagicIdentifierProbe $probe  What to look for.
	 *
	 * @return string[] The branches, in table order.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function identifierUnionBranches(array $tables, MagicIdentifierProbe $probe): array {
		$prefix = $this->getTablePrefix();
		$isPostgres = stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;
		$quote = fn (string $name): string => $this->quoteIdentifier(name: $name, isPostgres: $isPostgres);

		[$idCol, $uuidCol, $slugCol, $uriCol, $deletedCol] = array_map(
			fn (string $column): string => $quote(self::METADATA_PREFIX . $column),
			['id', 'uuid', 'slug', 'uri', 'deleted']
		);

		$deletedCondition = ' AND ' . $deletedCol . ' IS NULL';
		if ($probe->includeDeleted === true) {
			$deletedCondition = '';
		}

		$where = sprintf('(%s = ? OR %s = ? OR %s = ? OR %s = ?)%s', $idCol, $uuidCol, $slugCol, $uriCol, $deletedCondition);

		$branches = [];
		foreach ($tables as $tableName) {
			if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName) !== 1) {
				continue;
			}

			$branches[] = sprintf(
				"(SELECT '%s' AS src_table, %s AS hit_id FROM %s WHERE %s)",
				$tableName,
				$idCol,
				$quote($prefix . $tableName),
				$where
			);
		}

		return $branches;
	}//end identifierUnionBranches()

	/**
	 * Run the locate union and return its single row.
	 *
	 * Binds four parameters per branch, in branch order: _id as a true integer so
	 * a UUID never reaches the bigint column, then _uuid, _slug and _uri.
	 *
	 * @param string[]             $branches The union branches.
	 * @param MagicIdentifierProbe $probe    What to look for.
	 *
	 * @return mixed The fetched row, or false when nothing matched.
	 *
	 * @throws \Exception When the database rejects the query.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function runIdentifierUnion(array $branches, MagicIdentifierProbe $probe): mixed {
		$stmt = $this->db->prepare(implode(' UNION ALL ', $branches) . ' LIMIT 1');

		$parameterCount = (4 * count($branches));
		for ($position = 1; $position <= $parameterCount; $position++) {
			$value = $probe->identifier;
			$type = IQueryBuilder::PARAM_STR;
			if ($position % 4 === 1) {
				$value = $probe->idParam;
				$type = IQueryBuilder::PARAM_INT;
			}

			$stmt->bindValue($position, $value, $type);
		}

		$result = $stmt->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return $row;
	}//end runIdentifierUnion()

	/**
	 * Locate an identifier by probing each magic table separately (chunk fallback).
	 *
	 * Used only when the chunked UNION query fails, typically because one table in
	 * the chunk lacks the expected metadata columns. A table that errors is logged
	 * and skipped.
	 *
	 * @param string[]             $tables Unprefixed magic table names to probe.
	 * @param MagicIdentifierProbe $probe  What to look for.
	 *
	 * @return array{table: string, id: int}|null The matching table and row id, or null.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function locateIdentifierPerTable(array $tables, MagicIdentifierProbe $probe): ?array {
		foreach ($tables as $tableName) {
			try {
				$hitId = $this->probeTableForIdentifier(tableName: $tableName, probe: $probe);
			} catch (\Exception $e) {
				// Table might not have the expected structure, skip it.
				$this->logger->debug(
					message: '[MagicMapper] findAcrossAllMagicTables: Error searching table',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'table' => $tableName,
						'error' => $e->getMessage(),
					]
				);
				continue;
			}

			if ($hitId !== null) {
				return [
					'table' => $tableName,
					'id' => $hitId,
				];
			}
		}//end foreach

		return null;
	}//end locateIdentifierPerTable()

	/**
	 * Look an identifier up in one magic table, selecting only `_id`.
	 *
	 * @param string               $tableName Unprefixed magic table name; the query builder adds the prefix.
	 * @param MagicIdentifierProbe $probe     What to look for.
	 *
	 * @return int|null The matching row id, or null when the table holds no match.
	 *
	 * @throws \Exception When the database rejects the query.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function probeTableForIdentifier(string $tableName, MagicIdentifierProbe $probe): ?int {
		$idCol = self::METADATA_PREFIX . 'id';
		$searchQb = $this->db->getQueryBuilder();
		$searchQb->select($idCol)->from($tableName);

		$matches = [];
		foreach (['id', 'uuid', 'slug', 'uri'] as $column) {
			$value = $probe->identifier;
			$type = IQueryBuilder::PARAM_STR;
			if ($column === 'id') {
				$value = $probe->idParam;
				$type = IQueryBuilder::PARAM_INT;
			}

			$matches[] = $searchQb->expr()->eq(self::METADATA_PREFIX . $column, $searchQb->createNamedParameter($value, $type));
		}

		$searchQb->where($searchQb->expr()->orX(...$matches));

		if ($probe->includeDeleted === false) {
			$searchQb->andWhere($searchQb->expr()->isNull(self::METADATA_PREFIX . 'deleted'));
		}

		$searchQb->setMaxResults(1);

		$searchResult = $searchQb->executeQuery();
		$row = $searchResult->fetch();
		$searchResult->closeCursor();

		if ($row !== false && $row !== null && isset($row[$idCol]) === true) {
			return (int)$row[$idCol];
		}

		return null;
	}//end probeTableForIdentifier()

	/**
	 * Fetch one full magic-table row by its primary key (phase 2 of the scan).
	 *
	 * This is the only place the scan selects `*`, and it runs against exactly ONE
	 * table — the one the locate phase matched.
	 *
	 * @param MagicCrossTableHit $hit The located hit: its unprefixed magic table name, the `_id`
	 *                                primary key of the row to fetch and whether to include soft-deleted objects.
	 *
	 * @return array<string, mixed>|null The row, or null when it is gone/unreadable.
	 */
	private function fetchMagicRowById(MagicCrossTableHit $hit): ?array {
		$tableName = $hit->tableName;
		$id        = $hit->id;
		$idCol = self::METADATA_PREFIX . 'id';
		$deletedCol = self::METADATA_PREFIX . 'deleted';

		try {
			// NOTE: Use the unprefixed name because QueryBuilder adds the prefix.
			$fetchQb = $this->db->getQueryBuilder();
			$fetchQb->select('*')->from($tableName);
			$fetchQb->where(
				$fetchQb->expr()->eq(
					$idCol,
					$fetchQb->createNamedParameter($id, IQueryBuilder::PARAM_INT)
				)
			);

			// Exclude deleted unless requested — the locate phase already applied this,
			// re-applying it keeps the semantics exact under concurrent soft deletes.
			if ($hit->includeDeleted === false) {
				$fetchQb->andWhere($fetchQb->expr()->isNull($deletedCol));
			}

			$fetchQb->setMaxResults(1);

			$fetchResult = $fetchQb->executeQuery();
			$row = $fetchResult->fetch();
			$fetchResult->closeCursor();
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[MagicMapper] findAcrossAllMagicTables: Error fetching row',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => $tableName,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}//end try

		if ($row === false || $row === null) {
			return null;
		}

		return $row;
	}//end fetchMagicRowById()

	/**
	 * Find multiple objects by UUIDs across ALL magic tables.
	 *
	 * This method efficiently searches all magic tables for multiple UUIDs in batch.
	 * It's optimized for performance by searching all UUIDs in each table with a single query.
	 *
	 * @param array    $uuids           Array of UUIDs to search for.
	 * @param bool     $includeDeleted  Whether to include soft-deleted objects.
	 * @param int|null $registerIdScope Only look in this register's tables (null: every table).
	 *
	 * @return ObjectEntity[] Array of found objects (may be fewer than requested if some not found).
	 */
	public function findMultipleAcrossAllMagicTables(
		array $uuids,
		bool $includeDeleted = false,
		?int $registerIdScope = null,
	): array {
		return $this->findMultipleAcross(lookup: new UuidBatchLookup(uuids: $uuids, includeDeleted: $includeDeleted), registerIdScope: $registerIdScope);
	}//end findMultipleAcrossAllMagicTables()

	/**
	 * Find objects by uuid across the magic tables, narrowed to one register when named (see findMultipleAcrossAllMagicTables()).
	 *
	 * @param UuidBatchLookup $lookup          The uuids and whether soft-deleted rows count.
	 * @param int|null        $registerIdScope Restrict the scan to one register's tables.
	 *
	 * @return array The found objects.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findMultipleAcross(UuidBatchLookup $lookup, ?int $registerIdScope=null): array {
		$uuids          = $lookup->uuids;
		$includeDeleted = $lookup->includeDeleted;

		if (empty($uuids) === true) {
			return [];
		}

		$uuids = array_unique($uuids);
		$scan = new MagicCrossTableScan(label: 'findMultipleAcrossAllMagicTables');
		$scan->includeDeleted = $includeDeleted;
		$scan->registerIdScope = $registerIdScope;
		$scan->predicate = '_uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')';
		$scan->parameters = $uuids;

		$uuidsByTable = $this->locateCrossTableMatches(scan: $scan);
		if ($uuidsByTable === null) {
			return [];
		}

		$foundObjects = $this->fetchCrossTableMatches(scan: $scan, uuidsByTable: $uuidsByTable);

		$this->logger->debug(
			message: '[MagicMapper] findMultipleAcrossAllMagicTables: Batch search complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'requestedCount' => count($uuids),
				'foundCount' => count($foundObjects),
				'tablesWithMatches' => count($uuidsByTable),
			]
		);

		return $foundObjects;
	}//end findMultipleAcross()

	/**
	 * Find objects by uuid across all magic tables, each with every stored column.
	 *
	 * The plain lookup, findMultipleAcrossAllMagicTables(), fills only the name, register,
	 * schema and data. Anything that writes an object back, or decides on its owner, status
	 * or retention, reads it here.
	 *
	 * @param array $uuids          The uuids to search for.
	 * @param bool  $includeDeleted Whether to include soft-deleted objects.
	 *
	 * @return ObjectEntity[] The found objects (may be fewer than requested).
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findStoredAcrossAllMagicTables(array $uuids, bool $includeDeleted = false): array {
		if (empty($uuids) === true) {
			return [];
		}

		$uuids = array_unique($uuids);
		$scan = new MagicCrossTableScan(label: 'findStoredAcrossAllMagicTables');
		$scan->includeDeleted = $includeDeleted;
		$scan->storedRows = true;
		$scan->predicate = '_uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')';
		$scan->parameters = $uuids;

		$uuidsByTable = $this->locateCrossTableMatches(scan: $scan);
		if ($uuidsByTable === null) {
			return [];
		}

		return array_values(array_filter($this->fetchCrossTableMatches(scan: $scan, uuidsByTable: $uuidsByTable)));
	}//end findStoredAcrossAllMagicTables()

	/**
	 * Discover every magic table name (oc_openregister_table_<reg>_<schema>).
	 *
	 * @return array<string, array{registerId: int, schemaId: int}> Map of full
	 *                                                              table name to its register/schema ids.
	 */
	private function discoverMagicTables(): array {
		$prefix = $this->getTablePrefix();
		$tablePattern = $prefix . 'openregister_table_%';

		$sql = 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE ?';
		$stmt = $this->db->prepare($sql);
		$result = $stmt->execute([$tablePattern]);
		$tables = $result->fetchAll();

		$map = [];
		foreach ($tables as $tableRow) {
			$fullTableName = ($tableRow['table_name'] ?? $tableRow['TABLE_NAME'] ?? null);
			if ($fullTableName === null) {
				continue;
			}

			$bareName = str_replace($prefix, '', $fullTableName);
			if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $bareName, $matches) !== 1) {
				continue;
			}

			$map[$fullTableName] = [
				'registerId' => (int)$matches[1],
				'schemaId' => (int)$matches[2],
			];
		}//end foreach

		return $map;
	}//end discoverMagicTables()

	/**
	 * Find all soft-deleted objects across ALL magic tables.
	 *
	 * Objects live in per-register/schema magic tables, so there is no single
	 * table to query for `/api/deleted`. This scans every magic table for rows
	 * whose `_deleted` marker is set and returns them newest-first. Replaces the
	 * broken register/schema-less `searchObjectsPaginated()` path which always
	 * fell through to an empty result.
	 *
	 * Each table is narrowed IN THE QUERY to the rows the caller may read, with
	 * the same organisation and RBAC filters the object list applies
	 * ({@see MagicSearchHandler::applyAccessControlToQuery()}), so a trashed
	 * object is never shown to someone who could not read it before it was
	 * deleted, and the total the count answers matches the pages this returns
	 * (openregister#4078). A table whose schema cannot be resolved is skipped:
	 * whether the caller may read it cannot be answered, and the answer is no.
	 *
	 * @param int|null $limit Maximum rows to return.
	 * @param int|null $offset Rows to skip (pagination).
	 * @param bool $_rbac Apply the schema's read rules for the caller (false only for an admin or system caller).
	 * @param bool $_multitenancy Apply the organisation boundary for the caller.
	 *
	 * @return ObjectEntity[] Soft-deleted objects across all magic tables.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function findDeletedAcrossAllMagicTables(
		?int $limit = null,
		?int $offset = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$page = new ListQuery(limit: ($limit ?? ListQuery::UNBOUNDED), offset: $offset);
		return $this->findDeletedAcross(page: $page, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end findDeletedAcrossAllMagicTables()

	/**
	 * Find the soft-deleted objects of every magic table the caller may read, newest first (see findDeletedAcrossAllMagicTables()).
	 *
	 * @param ListQuery $page          The limit (unbounded for all) and offset, applied after the merge.
	 * @param bool      $_rbac         Whether to apply RBAC.
	 * @param bool      $_multitenancy Whether to apply multitenancy.
	 *
	 * @return ObjectEntity[] The deleted objects.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function findDeletedAcross(ListQuery $page, bool $_rbac=true, bool $_multitenancy=true): array {
		$deletedCol = 't.' . self::METADATA_PREFIX . 'deleted';
		$updatedCol = self::METADATA_PREFIX . 'updated';

		// Collect (entity, sortKey) pairs so the global newest-first ordering is
		// driven by the RAW `_updated` column value rather than the hydrated
		// entity's getUpdated() (which is not always populated and would leave
		// the merged set ordered only by table-discovery order, pushing freshly
		// deleted rows in high-id tables past the pagination window).
		$items = [];

		foreach ($this->discoverMagicTables() as $fullTableName => $info) {
			$bareTableName = substr($fullTableName, strlen($this->getTablePrefix()));
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select('*')
					->from($bareTableName, 't')
					->where($qb->expr()->isNotNull($deletedCol))
					->orderBy('t.' . $updatedCol, 'DESC');

				if ($this->scopeDeletedScanToCaller(qb: $qb, table: $info, _rbac: $_rbac, _multitenancy: $_multitenancy) === false) {
					continue;
				}

				$items = array_merge($items, $this->deletedItems(rows: $qb->executeQuery()->fetchAll(), table: $info));
			} catch (\Exception $e) {
				$this->logger->debug(
					message: '[MagicMapper] findDeletedAcrossAllMagicTables: skipping table',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'table' => $fullTableName,
						'error' => $e->getMessage(),
					]
				);
				continue;
			}//end try
		}//end foreach

		return $this->newestFirstPage(items: $items, page: $page);
	}//end findDeletedAcross()

	/**
	 * The deleted rows of one magic table as entities with their raw `_updated` sort key.
	 *
	 * @param array $rows  The rows.
	 * @param array $table The table's registerId and schemaId.
	 *
	 * @return list<array{entity: ObjectEntity, sortKey: string}> The items.
	 */
	private function deletedItems(array $rows, array $table): array {
		$updatedCol = self::METADATA_PREFIX . 'updated';
		$items = [];
		foreach ($rows as $row) {
			$row['_register'] = (string)$table['registerId'];
			$row['_schema'] = (string)$table['schemaId'];
			$entity = $this->rowToObjectEntity(row: $row);
			if ($entity !== null) {
				$entity->setSource('orm');
				$items[] = ['entity' => $entity, 'sortKey' => (string)($row[$updatedCol] ?? '')];
			}
		}

		return $items;
	}//end deletedItems()

	/**
	 * Order deleted items newest first by raw `_updated` value and take the page.
	 *
	 * @param list<array{entity: ObjectEntity, sortKey: string}> $items The items of every table.
	 * @param ListQuery                                          $page  The limit (unbounded for all) and offset.
	 *
	 * @return ObjectEntity[] The page.
	 */
	private function newestFirstPage(array $items, ListQuery $page): array {
		// Newest-first by raw `_updated` value (ISO-ish strings sort lexically).
		usort(
			$items,
			static function (array $first, array $second): int {
				return strcmp($second['sortKey'], $first['sortKey']);
			}
		);

		$found = array_map(static fn (array $item): ObjectEntity => $item['entity'], $items);

		// Apply pagination after the global merge.
		$limit = $page->limitOrNull();
		if ($page->offset !== null || $limit !== null) {
			$found = array_slice($found, ($page->offset ?? 0), $limit);
		}

		return $found;
	}//end newestFirstPage()

	/**
	 * The file ids of the object folders that belong to one organisation.
	 *
	 * Scans every magic table for live rows whose `_organisation` is the given
	 * uuid and returns their distinct numeric `_folder` values. Not narrowed to
	 * a caller: it measures the organisation, and the caller decides who may
	 * see the measurement.
	 *
	 * @param string $organisationUuid The organisation uuid.
	 *
	 * @return list<int> The folder file ids.
	 *
	 * @throws RuntimeException When a table could not be read: a sum over the tables that could be read would
	 *                           be too low and look measured, so the whole measurement fails (and is logged).
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	public function findFolderIdsByOrganisation(string $organisationUuid): array {
		$organisationCol = 't.' . self::METADATA_PREFIX . 'organisation';
		$folderCol = 't.' . self::METADATA_PREFIX . 'folder';
		$deletedCol = 't.' . self::METADATA_PREFIX . 'deleted';
		$folderIds = [];
		$failed    = [];

		foreach (array_keys($this->discoverMagicTables()) as $fullTableName) {
			$bareTableName = substr($fullTableName, strlen($this->getTablePrefix()));
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->selectDistinct($folderCol)
					->from($bareTableName, 't')
					->where($qb->expr()->eq($organisationCol, $qb->createNamedParameter($organisationUuid)))
					->andWhere($qb->expr()->isNotNull($folderCol))
					->andWhere($qb->expr()->isNull($deletedCol));
				$result = $qb->executeQuery();
				while (($folder = $result->fetchOne()) !== false) {
					if (is_numeric($folder) === true) {
						$folderIds[(int)$folder] = true;
					}
				}

				$result->closeCursor();
			} catch (\Exception $e) {
				// 🔴 NEVER SKIPPED IN SILENCE. A table that fails (a missing
				// _folder/_organisation column, a lock, a timeout) drops its folders
				// from the sum, and the caller would show a measured-looking number
				// that is too low. The service contract is "a figure that cannot be
				// measured is null, never 0", so this fails after the scan, naming
				// every table it could not read.
				$this->logger->warning(
					message: '[MagicMapper] findFolderIdsByOrganisation: could not read a table',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'table' => $fullTableName,
						'organisation' => $organisationUuid,
						'error' => $e->getMessage(),
					]
				);
				$failed[] = $fullTableName;
			}//end try
		}//end foreach

		if ($failed !== []) {
			throw new RuntimeException(
				'The folders of organisation ' . $organisationUuid . ' could not be read from: ' . implode(', ', $failed)
			);
		}

		return array_keys($folderIds);
	}//end findFolderIdsByOrganisation()

	/**
	 * Count an organisation's live objects across the magic tables, of the
	 * given schemas only when ids are given.
	 *
	 * A table that cannot be read fails the count after the scan, naming the
	 * tables, so a caller never shows a figure that is too low.
	 *
	 * @param string          $organisationUuid The organisation.
	 * @param array<int, int> $schemaIds        The schemas to count; empty counts every schema.
	 *
	 * @return int The number of live objects.
	 *
	 * @throws RuntimeException When a table could not be read.
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function countObjectsByOrganisation(string $organisationUuid, array $schemaIds=[]): int {
		$organisationCol = 't.'.self::METADATA_PREFIX.'organisation';
		$deletedCol      = 't.'.self::METADATA_PREFIX.'deleted';
		$count           = 0;
		$failed          = [];

		foreach ($this->discoverMagicTables() as $fullTableName => $table) {
			if ($schemaIds !== [] && in_array($table['schemaId'], $schemaIds, true) === false) {
				continue;
			}

			$bareTableName = substr($fullTableName, strlen($this->getTablePrefix()));
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select($qb->func()->count('*', 'n'))
					->from($bareTableName, 't')
					->where($qb->expr()->eq($organisationCol, $qb->createNamedParameter($organisationUuid)))
					->andWhere($qb->expr()->isNull($deletedCol));
				$result = $qb->executeQuery();
				$count += (int) $result->fetchOne();
				$result->closeCursor();
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] countObjectsByOrganisation: could not read a table',
					context: ['table' => $fullTableName, 'organisation' => $organisationUuid, 'error' => $e->getMessage()]
				);
				$failed[] = $fullTableName;
			}
		}//end foreach

		if ($failed !== []) {
			throw new RuntimeException(
				'The objects of organisation '.$organisationUuid.' could not be counted in: '.implode(', ', $failed)
			);
		}

		return $count;
	}//end countObjectsByOrganisation()

	/**
	 * Count all soft-deleted objects across ALL magic tables.
	 *
	 * Narrowed per table exactly as {@see findDeletedAcrossAllMagicTables()}
	 * is, so the total never counts a row the listing would not return.
	 *
	 * @param bool $_rbac Apply the schema's read rules for the caller (false only for an admin or system caller).
	 * @param bool $_multitenancy Apply the organisation boundary for the caller.
	 *
	 * @return int Total soft-deleted object count.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function countDeletedAcrossAllMagicTables(bool $_rbac = true, bool $_multitenancy = true): int {
		$deletedCol = 't.' . self::METADATA_PREFIX . 'deleted';
		$total = 0;

		foreach ($this->discoverMagicTables() as $fullTableName => $info) {
			$bareTableName = substr($fullTableName, strlen($this->getTablePrefix()));
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select($qb->func()->count('*', 'cnt'))
					->from($bareTableName, 't')
					->where($qb->expr()->isNotNull($deletedCol));

				if ($this->scopeDeletedScanToCaller(qb: $qb, table: $info, _rbac: $_rbac, _multitenancy: $_multitenancy) === false) {
					continue;
				}

				$res = $qb->executeQuery();
				$row = $res->fetch();
				$res->closeCursor();
				$total += (int)($row['cnt'] ?? 0);
			} catch (\Exception $e) {
				continue;
			}
		}//end foreach

		return $total;
	}//end countDeletedAcrossAllMagicTables()

	/**
	 * Narrow one magic table's trash scan to the rows the caller may read.
	 *
	 * Delegates to the list path's own access control, so the trash and the
	 * object list cannot disagree about who sees a row.
	 *
	 * @param IQueryBuilder $qb The scan, already reading the table as alias `t`.
	 * @param array{registerId: int, schemaId: int} $table The table's register and schema.
	 * @param bool $_rbac Apply the schema's read rules.
	 * @param bool $_multitenancy Apply the organisation boundary.
	 *
	 * @return bool False when the table must be skipped because its schema cannot be resolved.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function scopeDeletedScanToCaller(
		IQueryBuilder $qb,
		array $table,
		bool $_rbac,
		bool $_multitenancy,
	): bool {
		if ($_rbac === false && OrganisationContext::multitenancy(requested: $_multitenancy) === false) {
			return true;
		}

		try {
			$schema = $this->schemaMapper->find(id: $table['schemaId'], _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			return false;
		}

		$this->searchHandler->applyAccessControlToQuery(
			qb: $qb,
			schema: $schema,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy,
			registerId: $table['registerId'],
			tableName: self::TABLE_PREFIX . $table['registerId'] . '_' . $table['schemaId']
		);

		return true;
	}//end scopeDeletedScanToCaller()

	/**
	 * Find all objects across ALL magic tables that have the given UUID in their relations.
	 *
	 * This method searches across all magic tables to find objects that reference the given UUID.
	 * Relations are stored as JSON objects like {"fieldName": "uuid", ...}.
	 *
	 * @param string $uuid The UUID to search for in relations.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 *
	 * @return ObjectEntity[] Array of found ObjectEntity objects.
	 */
	public function findByRelationAcrossAllMagicTables(
		string $uuid,
		bool $includeDeleted = false,
	): array {
		if (empty($uuid) === true) {
			return [];
		}

		$relations = 'CAST(_relations AS CHAR)';
		if (stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false) {
			$relations = '_relations::text';
		}

		// Relations are stored as JSON like {"fieldName": "uuid"}, so the quoted
		// uuid anywhere in the text is a reference to it.
		$scan = new MagicCrossTableScan(label: 'findByRelationAcrossAllMagicTables');
		$scan->includeDeleted = $includeDeleted;
		$scan->predicate = "{$relations} LIKE ?";
		$scan->parameters = ['%"' . $uuid . '"%'];

		$uuidsByTable = $this->locateCrossTableMatches(scan: $scan);
		if ($uuidsByTable === null) {
			return [];
		}

		$foundObjects = $this->fetchCrossTableMatches(scan: $scan, uuidsByTable: $uuidsByTable);

		$this->logger->debug(
			message: '[MagicMapper] findByRelationAcrossAllMagicTables: Search complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $uuid,
				'foundCount' => count($foundObjects),
				'tablesWithMatches' => count($uuidsByTable),
			]
		);

		return $foundObjects;
	}//end findByRelationAcrossAllMagicTables()

	/**
	 * List the magic tables a cross-table scan reads, from information_schema,
	 * limited to the scan's register when it has one.
	 *
	 * @param MagicCrossTableScan $scan The scan; its tables are filled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function listCrossTableScanTables(MagicCrossTableScan $scan): void {
		$prefix = $this->getTablePrefix();
		$stmt = $this->db->prepare('SELECT table_name FROM information_schema.tables WHERE table_name LIKE ?');
		$tables = $stmt->execute([$prefix . 'openregister_table_%'])->fetchAll();

		$scan->tables = [];
		foreach ($tables as $tableRow) {
			$fullTableName = $tableRow['table_name'] ?? $tableRow['TABLE_NAME'] ?? null;
			if ($fullTableName === null
				|| preg_match('/^openregister_table_(\d+)_(\d+)$/', str_replace($prefix, '', $fullTableName), $matches) !== 1
			) {
				continue;
			}

			$registerId = (int)$matches[1];
			if ($scan->registerIdScope !== null && $registerId !== $scan->registerIdScope) {
				continue;
			}

			$scan->tables[$fullTableName] = ['registerId' => $registerId, 'schemaId' => (int)$matches[2]];
		}
	}//end listCrossTableScanTables()

	/**
	 * Find which uuids match the scan's predicate, per table, with one
	 * UNION ALL over every scanned table.
	 *
	 * @param MagicCrossTableScan $scan The scan.
	 *
	 * @return array<string, array<int, string>>|null The matching uuids by full table name, or null when no table is scanned or the query failed.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function locateCrossTableMatches(MagicCrossTableScan $scan): ?array {
		$this->listCrossTableScanTables(scan: $scan);
		if (empty($scan->tables) === true) {
			return null;
		}

		[$unionSql, $unionParams] = $scan->unionStatement();
		try {
			$matches = $this->db->prepare($unionSql)->execute($unionParams)->fetchAll();
		} catch (\Exception $e) {
			$this->logger->error(
				message: "[MagicMapper] {$scan->label}: UNION query failed",
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}

		$uuidsByTable = [];
		foreach ($matches as $match) {
			$uuidsByTable[$match['_source_table']][] = $match['found_uuid'];
		}

		return $uuidsByTable;
	}//end locateCrossTableMatches()

	/**
	 * Fetch the full rows of the matched uuids, one query per table, as objects.
	 *
	 * A table whose register or schema cannot be loaded is skipped. The
	 * register and schema lookups are cached for the process, per scan kind.
	 *
	 * @param MagicCrossTableScan               $scan         The scan.
	 * @param array<string, array<int, string>> $uuidsByTable The matching uuids by full table name.
	 *
	 * @return ObjectEntity[] The found objects.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function fetchCrossTableMatches(MagicCrossTableScan $scan, array $uuidsByTable): array {
		static $registerCache = [];
		static $schemaCache = [];

		$prefix = $this->getTablePrefix();
		$foundObjects = [];
		foreach ($uuidsByTable as $fullTableName => $tableUuids) {
			$tableInfo = $scan->tables[$fullTableName] ?? null;
			if ($tableInfo === null) {
				continue;
			}

			$registerId = $tableInfo['registerId'];
			$schemaId = $tableInfo['schemaId'];
			try {
				$registerCache[$scan->label][$registerId] ??= $this->registerMapper->find(id: $registerId, _multitenancy: false);
				$schemaCache[$scan->label][$schemaId] ??= $this->schemaMapper->find(id: $schemaId, _multitenancy: false);

				$searchQb = $this->db->getQueryBuilder();
				$searchQb->select('*')->from(str_replace($prefix, '', $fullTableName));
				$searchQb->where(
					$searchQb->expr()->in('_uuid', $searchQb->createNamedParameter($tableUuids, IQueryBuilder::PARAM_STR_ARRAY))
				);
				if ($scan->includeDeleted === false) {
					$searchQb->andWhere($searchQb->expr()->isNull('_deleted'));
				}

				$searchResult = $searchQb->executeQuery();
				$rows = $searchResult->fetchAll();
				$searchResult->closeCursor();

				foreach ($rows as $row) {
					$row['_register'] = (string)$registerId;
					$row['_schema'] = (string)$schemaId;
					if ($scan->storedRows === true) {
						$foundObjects[] = $this->convertRowToObjectEntity(
							row: $row,
							_register: $registerCache[$scan->label][$registerId],
							_schema: $schemaCache[$scan->label][$schemaId]
						);
						continue;
					}

					$foundObjects[] = $this->rowToObjectEntity(row: $row);
				}
			} catch (\Exception $e) {
				$this->logger->debug(
					message: "[MagicMapper] {$scan->label}: Error fetching from table",
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'table' => $fullTableName,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $foundObjects;
	}//end fetchCrossTableMatches()

	/**
	 * Find all objects in register+schema table with filtering and pagination.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param int|null $limit Maximum number of results.
	 * @param int|null $offset Offset for pagination.
	 * @param array|null $filters Filters to apply.
	 * @param array $sort Sort order.
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findAllInRegisterSchemaTable(
		Register $register,
		Schema $schema,
		?int $limit = null,
		?int $offset = null,
		?array $filters = null,
		array $sort = [],
	): array {
		$listing = new ListQuery(limit: ($limit ?? ListQuery::UNBOUNDED), offset: $offset, filters: ($filters ?? []), sort: $sort);
		return $this->findAllIn(table: new RegisterSchema(register: $register, schema: $schema), listing: $listing);
	}//end findAllInRegisterSchemaTable()

	/**
	 * Find all objects in a register+schema table with filtering and pagination.
	 *
	 * @param RegisterSchema $table   The register and schema whose table to use.
	 * @param ListQuery      $listing The limit (unbounded for all rows), offset, filters and sort.
	 *
	 * @return ObjectEntity[]
	 * @psalm-return list<ObjectEntity>
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findAllIn(RegisterSchema $table, ListQuery $listing): array {
		$register = $table->register;
		$schema   = $table->schema;
		$limit    = $listing->limitOrNull();
		$offset   = $listing->offset;
		$filters  = $listing->filters;
		$sort     = $listing->sort;

		$query = [];

		if ($limit !== null) {
			$query['_limit'] = $limit;
		}

		if ($offset !== null) {
			$query['_offset'] = $offset;
		}

		if (empty($sort) === false) {
			$query['_order'] = $sort;
		}

		$query = array_merge($query, $filters);

		return $this->searchObjectsInRegisterSchemaTable(query: $query, register: $register, schema: $schema);
	}//end findAllIn()

	/**
	 * The row-level access predicate for a raw query over one magic table.
	 *
	 * For a caller that writes its own SQL against the table (the aggregation
	 * fast path) and must count the rows a list read would return, no more.
	 * The predicate is {@see MagicRbacHandler::buildRbacRowPredicateSql()}'s, so
	 * the list and the raw query cannot drift apart. Columns are unqualified:
	 * the query must read the magic table without an alias.
	 *
	 * The predicate is the one for reading the rows.
	 *
	 * @param Schema        $schema   The schema of the table being read.
	 * @param Register|null $register The register of the table, so a metadata column the table could not
	 *                                be given is left out of the predicate. Null keeps every predicate.
	 *
	 * @return string|null Null when no row restriction applies (an admin); otherwise
	 *                     a predicate safe to AND into a WHERE, `1 = 0` for deny-all.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rbacRowPredicateSql(Schema $schema, ?Register $register = null): ?string {
		$tableName = null;
		if ($register !== null) {
			$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		}

		return $this->rbacHandler->buildRbacRowPredicateSql(schema: $schema, action: 'read', tableName: $tableName);
	}//end rbacRowPredicateSql()

	/**
	 * Whether the current caller qualifies for at least one rule of an action.
	 *
	 * @param Schema $schema The schema whose rules to consult.
	 * @param string $action The action (default: 'read').
	 *
	 * @return bool True when a rule for the action admits the caller.
	 *
	 * @see MagicRbacHandler::callerQualifiesForAction()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function callerQualifiesForAction(Schema $schema, string $action = 'read'): bool {
		return $this->rbacHandler->callerQualifiesForAction(schema: $schema, action: $action);
	}//end callerQualifiesForAction()

	/**
	 * Whether a `public` read rule lifts the organisation filter for the current caller.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the `public` rule may lift the organisation filter.
	 *
	 * @see MagicRbacHandler::publicReadLiftsOrganisationFilter()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-when-inheritfrompublic-is-false-authenticated-users-must-not-qualify-for-public-rules
	 */
	public function publicReadLiftsOrganisationFilter(Schema $schema): bool {
		return $this->rbacHandler->publicReadLiftsOrganisationFilter(schema: $schema);
	}//end publicReadLiftsOrganisationFilter()

	/**
	 * Whether a default read's organisation boundary carries a branch for the
	 * caller's conditional `crossOrganisation` rules.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when it does.
	 *
	 * @see MagicSearchHandler::organisationBoundaryHasCrossOrganisationBranch()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
	 */
	public function organisationBoundaryHasCrossOrganisationBranch(Schema $schema): bool {
		return $this->searchHandler->organisationBoundaryHasCrossOrganisationBranch(schema: $schema);
	}//end organisationBoundaryHasCrossOrganisationBranch()

	/**
	 * Whether a default list read waives the organisation boundary for this
	 * caller because RBAC governs the rows instead.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the list would not apply the organisation filter.
	 *
	 * @see MagicSearchHandler::organisationBoundaryWaivedByRbac()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function organisationBoundaryWaivedByRbac(Schema $schema): bool {
		return $this->searchHandler->organisationBoundaryWaivedByRbac(schema: $schema);
	}//end organisationBoundaryWaivedByRbac()

	/**
	 * Whether a default list read widens the organisation boundary by the
	 * rows with no organisation, because the caller's grant comes from the
	 * register cascade.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the list's organisation filter also admits org-less rows.
	 *
	 * @see MagicSearchHandler::organisationlessRowsAdmittedByRbac()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function organisationlessRowsAdmittedByRbac(Schema $schema): bool {
		return $this->searchHandler->organisationlessRowsAdmittedByRbac(schema: $schema);
	}//end organisationlessRowsAdmittedByRbac()

	/**
	 * Whether a stored row lies inside the caller's organisations.
	 *
	 * Decided on the row's own organisation through the same scope the read
	 * filter renders, but without the read rules: a `public` read rule that
	 * lifts the organisation filter for reading does not widen this answer.
	 * The row's register and schema name the table, for shared master data.
	 *
	 * @param ObjectEntity $row The stored row.
	 *
	 * @return bool True when the caller's organisation scope holds the row.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function callerOrganisationsHold(ObjectEntity $row): bool {
		if ($this->organizationHandler === null) {
			return false;
		}

		$scope = $this->organizationHandler->resolveOrganizationScope(
			adminBypassEnabled: $this->organizationHandler->isAdminOverrideEnabled(),
			registerId: $this->numericIdOrNull(value: $row->getRegister()),
			schemaId: $this->numericIdOrNull(value: $row->getSchema())
		);

		return OrganisationScopeMembership::holds(scope: $scope, organisation: $row->getOrganisation());
	}//end callerOrganisationsHold()

	/**
	 * A stored numeric id as an integer, or null when it is not one.
	 *
	 * @param string|null $value The stored id.
	 *
	 * @return int|null
	 */
	private function numericIdOrNull(?string $value): ?int {
		if ($value === null || ctype_digit($value) === false) {
			return null;
		}

		return (int) $value;
	}//end numericIdOrNull()

	/**
	 * Insert ObjectEntity into register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to insert.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $dispatchEvents Whether to dispatch events.
	 * @param bool $failIfExists Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @throws Exception If insertion fails.
	 *
	 * @return ObjectEntity The inserted object entity.
	 */
	public function insertObjectEntity(
		ObjectEntity $entity,
		Register $register,
		Schema $schema,
		bool $dispatchEvents = true,
		bool $failIfExists = false,
	): ObjectEntity {
		// A refusal is held until the write knows whether this is a create at all:
		// over a taken identifier it is an update, judged by the update guards.
		$refusal = null;
		if ($dispatchEvents === true) {
			$refusal = $this->runCreatingHooks(entity: $entity);
		}

		$this->ensureTableForRegisterSchema(register: $register, schema: $schema);

		$write = [
			'register' => $register,
			'schema' => $schema,
			'table' => $this->getTableNameForRegisterSchema(register: $register, schema: $schema),
			'failIfExists' => $failIfExists,
			'refusal' => $refusal,
		];
		$this->logger->debug(
			message: '[MagicMapper] Inserting object entity into register+schema table',
			context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $entity->getUuid(), 'tableName' => $write['table']]
		);

		$write['data'] = $this->insertableObject(entity: $entity, write: $write);

		[$uuid, $insertedId] = $this->writeClaimingIdentifier(entity: $entity, write: $write);

		// Event dispatching is handled by the public insert/update/delete methods.
		$entity->setId($insertedId ?? $this->storedIdAfterLostInsert(uuid: $uuid, tableName: $write['table']));

		return $entity;
	}//end insertObjectEntity()

	/**
	 * The object as table data, with its register, schema and uuid filled in.
	 *
	 * @param ObjectEntity $entity The object being created.
	 * @param array        $write  `register` and `schema`.
	 *
	 * @return array The serialised object.
	 */
	private function insertableObject(ObjectEntity $entity, array $write): array {
		if ($entity->getRegister() === null) {
			$entity->setRegister((string)$write['register']->getId());
		}

		if ($entity->getSchema() === null) {
			$entity->setSchema((string)$write['schema']->getId());
		}

		if ($entity->getUuid() === null || $entity->getUuid() === '') {
			$entity->setUuid(Uuid::v4()->toRfc4122());
		}

		return $entity->jsonSerialize();
	}//end insertableObject()

	/**
	 * The `_id` of a row whose INSERT lost a uuid race and was applied as an update.
	 *
	 * The only write that costs a read: a won INSERT reports its own generated
	 * key. Keeping the lost-write check matters: the alternative is returning an
	 * entity with a null id to a caller with no way to tell a write landed,
	 * which is exactly what #2212 turned out to be.
	 *
	 * @param string $uuid      The uuid written.
	 * @param string $tableName The table.
	 *
	 * @throws Exception When the row is not in the table.
	 *
	 * @return int The stored id.
	 */
	private function storedIdAfterLostInsert(string $uuid, string $tableName): int {
		$row = $this->findObjectInRegisterSchemaTable(uuid: $uuid, tableName: $tableName);
		if ($row !== null) {
			return (int)$row[self::METADATA_PREFIX . 'id'];
		}

		$this->logger->error(
			message: '[MagicMapper] Inserted object is not in the table afterwards — refusing to report success',
			context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'tableName' => $tableName]
		);

		throw new Exception(sprintf('Object "%s" was written to %s but is not there afterwards.', $uuid, $tableName));
	}//end storedIdAfterLostInsert()

	/**
	 * Write a create, claiming its identifier.
	 *
	 * When the identifier is already taken, the caller that asked to fail gets
	 * ObjectExistsException; any other caller's upsert intent is carried out by
	 * updateTakenIdentifier(), so the update guard runs before the row changes.
	 *
	 * @param ObjectEntity $entity The object being created.
	 * @param array        $write  `data` (the serialised object), `register`, `schema`, `table` and `failIfExists`.
	 *
	 * @return array{0: string, 1: int|null} The uuid written, and the generated `_id` when this call inserted.
	 *
	 * @throws ObjectExistsException When the identifier is taken and the caller asked to fail.
	 *
	 * @spec openspec/changes/insert-race-runs-update-guard/specs/object-lifecycle/spec.md#requirement-a-create-that-finds-its-identifier-taken-runs-the-update-guard
	 */
	private function writeClaimingIdentifier(ObjectEntity $entity, array $write): array {
		// Reset here, after the creating listeners ran, so an insert nested in one of them cannot leave its flag behind.
		$this->replacedByLastInsert = null;
		if (($write['refusal'] ?? null) !== null) {
			return [$this->updateRefusedCreate(entity: $entity, write: $write), null];
		}

		$rowWrite = new MagicRowWrite(
			objectData: $write['data'],
			register: $write['register'],
			schema: $write['schema'],
			tableName: $write['table']
		);
		$rowWrite->failIfExists = true;
		try {
			$uuid = $this->saveObjectToRegisterSchemaTable(write: $rowWrite);
		} catch (ObjectExistsException $e) {
			if ($write['failIfExists'] === true) {
				throw $e;
			}

			$uuid = $this->updateTakenIdentifier(entity: $entity, target: $write);
		}

		return [$uuid, $rowWrite->insertedId];
	}//end writeClaimingIdentifier()

	/**
	 * Dispatch ObjectCreatingEvent and merge what the hooks set; the refusal when a hook stopped it.
	 *
	 * @param ObjectEntity $entity The object being created.
	 *
	 * @return HookStoppedException|null The refusal to throw unless the write turns out to be an update.
	 *
	 * @spec openspec/changes/create-over-taken-identifier-runs-update-path/specs/object-lifecycle/spec.md#requirement-a-refused-create-over-a-taken-identifier-is-judged-as-an-update
	 */
	private function runCreatingHooks(ObjectEntity $entity): ?HookStoppedException {
		$creatingEvent = new ObjectCreatingEvent(object: $entity);
		$this->eventDispatcher->dispatchTyped($creatingEvent);
		if ($creatingEvent->isPropagationStopped() === true) {
			return new HookStoppedException(
				message: (string)($creatingEvent->getErrors()['message'] ?? 'Object creation rejected by hook'),
				errors: $creatingEvent->getErrors()
			);
		}

		$modifiedData = $creatingEvent->getModifiedData();
		if (empty($modifiedData) === false) {
			$entity->setObject(array_merge(($entity->getObject() ?? []), $modifiedData));
		}

		return null;
	}//end runCreatingHooks()

	/**
	 * A create its creating hooks refused: an update when its identifier names a live row, else the refusal.
	 *
	 * The refusal judged a create. When the caller did not ask to fail on a taken
	 * identifier and a live row exists, the write is an update of that row, so the
	 * update guards judge it instead. A soft-deleted row keeps the refusal: writing
	 * over it would bring the object back without the create rules.
	 *
	 * @param ObjectEntity $entity The object being created.
	 * @param array        $write  `table`, `register`, `schema`, `failIfExists` and the `refusal`.
	 *
	 * @return string The uuid written.
	 *
	 * @throws HookStoppedException The creating refusal, when this is a create after all.
	 *
	 * @spec openspec/changes/create-over-taken-identifier-runs-update-path/specs/object-lifecycle/spec.md#requirement-a-refused-create-over-a-taken-identifier-is-judged-as-an-update
	 */
	private function updateRefusedCreate(ObjectEntity $entity, array $write): string {
		$stored = null;
		if ($write['failIfExists'] === false) {
			$stored = $this->storedRowOf(entity: $entity, target: $write);
		}

		if ($stored === null || empty($stored->getDeleted()) === false) {
			throw $write['refusal'];
		}

		// The row is read again right before the write in updateTakenIdentifier(), so a row that went away in between is not written.
		return $this->updateTakenIdentifier(entity: $entity, target: $write);
	}//end updateRefusedCreate()

	/**
	 * The stored row a create's identifier names, read raw (a soft-deleted row counts too), or null.
	 *
	 * @param ObjectEntity                                           $entity The object being created.
	 * @param array{register: Register, schema: Schema, table: string} $target Where it is written.
	 *
	 * @return ObjectEntity|null
	 */
	private function storedRowOf(ObjectEntity $entity, array $target): ?ObjectEntity {
		$row = $this->findObjectInRegisterSchemaTable(uuid: (string)$entity->getUuid(), tableName: $target['table']);
		if ($row === null) {
			return null;
		}

		return $this->convertRowToObjectEntity(row: $row, _register: $target['register'], _schema: $target['schema']);
	}//end storedRowOf()

	/**
	 * Write a create whose identifier is already taken as an update of the stored row.
	 *
	 * The stored row is read raw (a soft-deleted row counts too, as it did for the
	 * upsert) and passed as the old object, so ObjectUpdatingEvent and its guards see
	 * the real transition. A stopped event throws HookStoppedException and the row
	 * stays as stored.
	 *
	 * @param ObjectEntity $entity The object being created.
	 * @param array        $target Where it is written (`register`, `schema`, `table`), and the creating
	 *                             `refusal` when the create was refused: then the row must still be live.
	 *
	 * @return string The uuid written.
	 *
	 * @throws HookStoppedException When a listener stops the update, or the refused create's row is no longer live.
	 *
	 * @spec openspec/changes/insert-race-runs-update-guard/specs/object-lifecycle/spec.md#requirement-a-create-that-finds-its-identifier-taken-runs-the-update-guard
	 */
	private function updateTakenIdentifier(ObjectEntity $entity, array $target): string {
		$stored = $this->storedRowOf(entity: $entity, target: $target);

		// A refused create may only update a row that is still live when it is written.
		$refusal = ($target['refusal'] ?? null);
		if ($refusal !== null && ($stored === null || empty($stored->getDeleted()) === false)) {
			throw $refusal;
		}

		// A write of the same uuid can run nested inside another's events: each
		// write carries only its own refusal, and hands back the outer one after.
		$uuid = (string)$entity->getUuid();
		$outerRefusal = ($this->liveRowOnlyWrites[$uuid] ?? null);
		unset($this->liveRowOnlyWrites[$uuid]);
		if ($refusal !== null) {
			$this->liveRowOnlyWrites[$uuid] = $refusal;
		}

		try {
			$updated = $this->updateObjectEntity(
				entity: $entity,
				register: $target['register'],
				schema: $target['schema'],
				oldEntity: $stored
			);
		} finally {
			unset($this->liveRowOnlyWrites[$uuid]);
			if ($outerRefusal !== null) {
				$this->liveRowOnlyWrites[$uuid] = $outerRefusal;
			}
		}

		$this->replacedByLastInsert = ($stored ?? clone $entity);
		return (string)$updated->getUuid();
	}//end updateTakenIdentifier()

	/**
	 * Update ObjectEntity in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to update.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param ObjectEntity|null $oldEntity The old entity for comparison.
	 *
	 * @throws Exception If update fails.
	 *
	 * @return ObjectEntity The updated object entity.
	 */
	public function updateObjectEntity(
		ObjectEntity $entity,
		Register $register,
		Schema $schema,
		?ObjectEntity $oldEntity = null,
	): ObjectEntity {
		$change = new MagicObjectChange(entity: $entity, oldEntity: $oldEntity);
		return $this->updateObjectIn(table: new RegisterSchema(register: $register, schema: $schema), change: $change);
	}//end updateObjectEntity()

	/**
	 * Update an object entity in its register+schema table (see updateObjectEntity()).
	 *
	 * @param RegisterSchema    $table  The register and schema whose table holds the object.
	 * @param MagicObjectChange $change The object to write and, when known, the object as stored.
	 *
	 * @return ObjectEntity The updated entity.
	 * @throws Exception If the update fails.
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function updateObjectIn(RegisterSchema $table, MagicObjectChange $change): ObjectEntity {
		$register  = $table->register;
		$schema    = $table->schema;
		$entity    = $change->entity;
		$oldEntity = $change->oldEntity;

		$this->refusePartialRead(entity: $entity);

		// Use provided oldEntity or fetch from database.
		$oldObject = $oldEntity;
		if ($oldEntity === null) {
			$oldObject = $this->findInRegisterSchemaTable(
				identifier: $entity->getUuid(),
				register: $register,
				schema: $schema,
				// Internal pre-update read of the existing row: bypass
				// access-control filtering (authorization for the update is
				// enforced upstream by the lock/permission guards).
				_rbac: false,
				_multitenancy: false
			);
		}

		$this->logger->debug(
			message: '[MagicMapper] updateObjectEntity called - UUID: ' . $entity->getUuid(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		// Dispatch updating event (pre-save hook).
		$updatingEvent = new ObjectUpdatingEvent(newObject: $entity, oldObject: $oldObject);
		$this->eventDispatcher->dispatchTyped($updatingEvent);

		// Check if a hook stopped propagation (reject mode).
		if ($updatingEvent->isPropagationStopped() === true) {
			throw new HookStoppedException(
				message: (string)($updatingEvent->getErrors()['message'] ?? 'Object update rejected by hook'),
				errors: $updatingEvent->getErrors()
			);
		}

		// Merge modified data from hooks if any.
		$modifiedData = $updatingEvent->getModifiedData();
		if (empty($modifiedData) === false) {
			$objectData = $entity->getObject() ?? [];
			$entity->setObject(array_merge($objectData, $modifiedData));
		}

		// Ensure table has all required columns before updating (e.g. _tmlo added after table was created).
		$this->ensureTableForRegisterSchema(register: $register, schema: $schema);

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$uuid = $entity->getUuid();

		if ($uuid === null) {
			throw new Exception('Cannot update object entity without UUID');
		}

		$this->logger->debug(
			message: '[MagicMapper] Updating object entity in register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $uuid,
				'tableName' => $tableName,
			]
		);

		// Convert entity to array for table storage.
		$objectArray = $entity->jsonSerialize();

		// Update in table.
		$this->saveObjectToRegisterSchemaTable(
			write: new MagicRowWrite(objectData: $objectArray, register: $register, schema: $schema, tableName: $tableName)
		);

		// The re-read that used to sit here is gone, and nothing is lost with it.
		//
		// It was justified as fetching "correct updated timestamps, ID, etc.",
		// but the table has exactly ONE database-generated column, `_id`
		// (nextval), and on update that id is already known — we looked the row
		// up by uuid to write it. `_created` and `_updated` carry NO column
		// default (verified against the live schema: 59,292 of 59,292 rows have
		// both set), so PHP writes them and they are already on this entity.
		// The read therefore returned the values we had just sent.
		//
		// That the input entity is an acceptable result was already settled: the
		// catch this replaces returned exactly that whenever the re-fetch failed.
		//
		// It was not free. A SELECT against a table written moments earlier, in
		// no transaction, is precisely Nextcloud's "dirty table reads" condition
		// (lib/private/DB/Connection.php), which logs a synthetic exception —
		// ~5.9 MB of serialised backtrace per occurrence. On every object update.
		return $entity;
	}//end updateObjectIn()

	/**
	 * Refuse to write back an entity that holds only part of its row.
	 *
	 * A write replaces the whole row, so the columns such an entity never read would be
	 * emptied: owner, organisation, version, relations, status, retention, created date.
	 *
	 * @param ObjectEntity $entity The entity about to be written.
	 *
	 * @return void
	 *
	 * @throws Exception When the entity holds only part of its row.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	private function refusePartialRead(ObjectEntity $entity): void {
		if ($entity instanceof PartialObjectEntity) {
			throw new Exception('Cannot write object ' . $entity->getUuid() . ' back: it was read without its metadata.');
		}
	}//end refusePartialRead()

	/**
	 * Ask the delete guards whether an object may be deleted.
	 *
	 * Dispatches the stoppable ObjectDeletingEvent, on which the frozen, the
	 * working-calendar and the concept guards listen. Both the permanent and
	 * the soft delete ask it before they write.
	 *
	 * @param ObjectEntity $entity The object about to be deleted.
	 *
	 * @return void
	 *
	 * @throws HookStoppedException When a listener stops the delete.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function assertDeletable(ObjectEntity $entity): void {
		$deletingEvent = new ObjectDeletingEvent(object: $entity);
		$this->eventDispatcher->dispatchTyped($deletingEvent);

		if ($deletingEvent->isPropagationStopped() === true) {
			throw new HookStoppedException(
				message: (string)($deletingEvent->getErrors()['message'] ?? 'Object deletion rejected by hook'),
				errors: $deletingEvent->getErrors()
			);
		}
	}//end assertDeletable()

	/**
	 * Delete ObjectEntity from register+schema table.
	 *
	 * Supports both soft delete (sets _deleted field) and hard delete (removes from table).
	 *
	 * @param ObjectEntity $entity The object entity to delete.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $hardDelete Whether to perform hard delete (default: false for soft delete).
	 * @param bool $dispatchEvents Whether to dispatch events.
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @return ObjectEntity The deleted object entity.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
	 */
	public function deleteObjectEntity(
		ObjectEntity $entity,
		Register $register,
		Schema $schema,
		bool $hardDelete = false,
		bool $dispatchEvents = true,
	): ObjectEntity {
		if ($dispatchEvents === true) {
			$this->assertDeletable(entity: $entity);
		}

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$uuid = $entity->getUuid();

		if ($uuid === null) {
			throw new Exception('Cannot delete object entity without UUID');
		}

		$this->logger->debug(
			message: '[MagicMapper] Deleting object entity from register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $uuid,
				'tableName' => $tableName,
				'hardDelete' => $hardDelete,
			]
		);

		$target = new MagicTableTarget(register: $register, schema: $schema);
		$target->tableName = $tableName;
		if ($hardDelete === true) {
			$this->hardDeleteRow(target: $target, uuid: $uuid);
		}

		if ($hardDelete === false) {
			$this->softDeleteRow(target: $target, entity: $entity);
		}

		// NOTE: Event dispatching is handled by the public insert/update/delete methods to avoid duplicate events.
		// Do NOT dispatch ObjectDeletedEvent here.
		return $entity;
	}//end deleteObjectEntity()

	/**
	 * Hard delete: remove the row with this uuid from the table.
	 *
	 * @param MagicTableTarget $target The register, schema and table name.
	 * @param string           $uuid   The object uuid.
	 *
	 * @return void
	 */
	private function hardDeleteRow(MagicTableTarget $target, string $uuid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($target->tableName)
			->where($qb->expr()->eq(self::METADATA_PREFIX . 'uuid', $qb->createNamedParameter($uuid)));
		$qb->executeStatement();

		$this->logger->debug(
			message: '[MagicMapper] Hard deleted object from register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $uuid,
				'tableName' => $target->tableName,
			]
		);
	}//end hardDeleteRow()

	/**
	 * Soft delete: mark the entity deleted (unless it already is) and write it back to the table.
	 *
	 * @param MagicTableTarget $target The register, schema and table name.
	 * @param ObjectEntity     $entity The object entity.
	 *
	 * @return void
	 */
	private function softDeleteRow(MagicTableTarget $target, ObjectEntity $entity): void {
		if ($entity->getDeleted() === null || empty($entity->getDeleted()) === true) {
			// Mark as deleted using entity method.
			$entity->delete($this->userSession, 'Soft deleted via MagicMapper', 30);
		}

		// Update entity in table with deleted field set.
		$this->updateObjectEntity(entity: $entity, register: $target->register, schema: $target->schema);

		$this->logger->debug(
			message: '[MagicMapper] Soft deleted object in register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $entity->getUuid(),
				'tableName' => $target->tableName,
			]
		);
	}//end softDeleteRow()

	/**
	 * Soft-delete multiple pre-resolved objects with ONE UPDATE per magic table.
	 *
	 * PERF: the per-object soft-delete path re-serialises and rewrites the whole
	 * row (updateObjectEntity → saveObjectToRegisterSchemaTable) and re-fetches
	 * it afterwards — for a cascade of N children that is ~5 queries per child.
	 * This method batches children of the same (register, schema) pair into a
	 * single `UPDATE ... SET _deleted = CASE _uuid ... END WHERE _uuid IN (...)`
	 * statement while preserving the per-object event contract:
	 *
	 * - `ObjectUpdatingEvent` is dispatched per entity BEFORE the write; a hook
	 *   that stops propagation skips that entity (parity with the per-object
	 *   path, where HookStoppedException aborted the child's delete).
	 * - A hook that modifies the payload routes that entity through the
	 *   full-row save so the modification persists (the batched UPDATE only
	 *   writes the deleted marker).
	 * - `ObjectUpdatedEvent` is dispatched per entity AFTER the write.
	 *
	 * The caller must set the `deleted` metadata on every entity beforehand
	 * (its JSON is what the batched UPDATE writes) — deletion attribution is
	 * the caller's domain, persistence is this mapper's.
	 *
	 * @param array $entities ObjectEntity list to soft-delete
	 *                        (validated at runtime); uuid, numeric
	 *                        register/schema ids and `deleted`
	 *                        metadata must be set on every entity.
	 * @param array<string, ObjectEntity> $oldEntities Optional pre-delete snapshots keyed
	 *                                                 by uuid, used as event old-objects.
	 *                                                 Missing snapshots are derived by
	 *                                                 cloning the (mutated) entity.
	 *
	 * @return ObjectEntity[] The entities that were actually soft-deleted.
	 *
	 * @throws Exception If a batched UPDATE fails for every table.
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function softDeleteMultipleObjectEntities(array $entities, array $oldEntities = []): array {
		if (empty($entities) === true) {
			return [];
		}

		$groups = [];
		$completed = [];
		foreach ($entities as $entity) {
			$item = $this->softDeleteItem(entity: $entity, oldEntities: $oldEntities);
			if ($item === null) {
				continue;
			}

			if (empty($item['modified']) === false) {
				// Hook modified the payload — persist via the full-row save so
				// the modification is not lost by the marker-only batched UPDATE.
				$entity->setObject(array_merge(($entity->getObject() ?? []), $item['modified']));
				if ($this->softDeleteSingleFullRow(entity: $entity) === true) {
					$completed[] = ['entity' => $entity, 'old' => $item['old']];
				}

				continue;
			}

			$registerId = (int)$entity->getRegister();
			$schemaId = (int)$entity->getSchema();
			$groupKey = $registerId . '_' . $schemaId;
			$groups[$groupKey]['registerId'] = $registerId;
			$groups[$groupKey]['schemaId'] = $schemaId;
			$groups[$groupKey]['items'][] = ['entity' => $entity, 'old' => $item['old']];
		}//end foreach

		$completed = array_merge($completed, $this->executeSoftDeleteGroups(groups: $groups));

		return $this->announceSoftDeleted(completed: $completed);
	}//end softDeleteMultipleObjectEntities()

	/**
	 * Prepare one entity of a batched soft delete and run its pre-save hook.
	 *
	 * @param mixed                       $entity      The entity to soft-delete.
	 * @param array<string, ObjectEntity> $oldEntities Pre-delete snapshots keyed by uuid.
	 *
	 * @return array{old: ObjectEntity, modified: array}|null The snapshot and the hook's
	 *     modified data, or null when the entity is skipped.
	 *
	 * @throws Exception When the entity is not an ObjectEntity.
	 */
	private function softDeleteItem(mixed $entity, array $oldEntities): ?array {
		if ($entity instanceof ObjectEntity === false) {
			throw new Exception('softDeleteMultipleObjectEntities() expects ObjectEntity instances');
		}

		$uuid = $entity->getUuid();
		if ($uuid === null || $uuid === ''
			|| is_numeric($entity->getRegister()) === false
			|| is_numeric($entity->getSchema()) === false
		) {
			$this->logger->warning(
				message: '[MagicMapper] Skipping batched soft delete for entity without uuid/register/schema',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
			);
			return null;
		}

		$oldEntity = ($oldEntities[$uuid] ?? clone $entity);

		// Pre-save hook — parity with the single-object update path.
		$updatingEvent = new ObjectUpdatingEvent(newObject: $entity, oldObject: $oldEntity);
		$this->eventDispatcher->dispatchTyped($updatingEvent);
		if ($updatingEvent->isPropagationStopped() === true) {
			$this->logger->debug(
				message: '[MagicMapper] Batched soft delete skipped by hook',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
			);
			return null;
		}

		return ['old' => $oldEntity, 'modified' => $updatingEvent->getModifiedData()];
	}//end softDeleteItem()

	/**
	 * Run one batched soft-delete UPDATE per magic table.
	 *
	 * A table whose UPDATE fails is logged; its items are not completed.
	 *
	 * @param array<string, array{registerId: int, schemaId: int, items: array}> $groups Items per table.
	 *
	 * @return array The completed ['entity', 'old'] items, in table order.
	 */
	private function executeSoftDeleteGroups(array $groups): array {
		$completed = [];
		foreach ($groups as $group) {
			try {
				// Same convention as MagicTableHandler::getTableNameForRegisterSchema()
				// (numeric ids never exceed the max table-name length).
				$this->executeBatchSoftDeleteUpdate(
					tableName: self::TABLE_PREFIX . $group['registerId'] . '_' . $group['schemaId'],
					items: $group['items']
				);
				$completed = array_merge($completed, $group['items']);
			} catch (Exception $e) {
				$this->logger->error(
					message: '[MagicMapper] Batched soft delete UPDATE failed for magic table',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'registerId' => $group['registerId'],
						'schemaId' => $group['schemaId'],
						'count' => count($group['items']),
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $completed;
	}//end executeSoftDeleteGroups()

	/**
	 * Dispatch the updated event for every soft-deleted entity, unless lifecycle events are withheld.
	 *
	 * @param array $completed The completed ['entity', 'old'] items.
	 *
	 * @return ObjectEntity[] The soft-deleted entities.
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	private function announceSoftDeleted(array $completed): array {
		$result = [];
		$suppress = $this->suppressLifecycleEvents();
		foreach ($completed as $item) {
			if ($suppress === false) {
				(new ObjectEventGate(events: $this->eventDispatcher))->updated(new: $item['entity'], old: $item['old']);
			}

			$result[] = $item['entity'];
		}

		return $result;
	}//end announceSoftDeleted()

	/**
	 * Persist one hook-modified entity via the full-row save path (soft delete).
	 *
	 * @param ObjectEntity $entity The (already deleted-marked, hook-modified) entity.
	 *
	 * @return bool True when the row was written.
	 */
	private function softDeleteSingleFullRow(ObjectEntity $entity): bool {
		try {
			$this->refusePartialRead(entity: $entity);
			$register = $this->registerMapper->find((int)$entity->getRegister(), _rbac: false, _multitenancy: false);
			$schema = $this->schemaMapper->find((int)$entity->getSchema(), _rbac: false, _multitenancy: false);
			$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
			$this->saveObjectToRegisterSchemaTable(
				write: new MagicRowWrite(objectData: $entity->jsonSerialize(), register: $register, schema: $schema, tableName: $tableName)
			);
			return true;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Full-row soft delete failed for hook-modified entity',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $entity->getUuid(),
					'error' => $e->getMessage(),
				]
			);
			return false;
		}//end try
	}//end softDeleteSingleFullRow()

	/**
	 * Execute one batched soft-delete UPDATE against a single magic table.
	 *
	 * Writes each row's (per-object) deleted-metadata JSON via a parameterised
	 * `CASE _uuid WHEN ... THEN ... END` expression — one statement per table
	 * regardless of how many rows it soft-deletes.
	 *
	 * @param string $tableName The magic table, named after the register and schema ids.
	 * @param array  $items     List of ['entity' => ObjectEntity, 'old' => ObjectEntity] pairs.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If the UPDATE fails.
	 */
	private function executeBatchSoftDeleteUpdate(string $tableName, array $items): void {
		$uuidCol = self::METADATA_PREFIX . 'uuid';
		$deletedCol = self::METADATA_PREFIX . 'deleted';

		$qb = $this->db->getQueryBuilder();

		// PostgreSQL's CASE type-resolution infers the expression's overall
		// type from its WHEN/THEN literals (here: text), so assigning the
		// result straight into a jsonb column fails with SQLSTATE 42804
		// ("column is of type jsonb but expression is of type text"). An
		// explicit `::jsonb` cast on the whole CASE result fixes the
		// mismatch; MySQL's JSON column accepts the string form as-is and
		// does not need (nor understand) a Postgres-style cast.
		$platform = $this->db->getDatabasePlatform();
		$isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

		$caseSql = '(CASE ' . $uuidCol;
		$uuids = [];
		foreach ($items as $item) {
			$entity = $item['entity'];
			$uuids[] = $entity->getUuid();

			$caseSql .= ' WHEN ' . $qb->createNamedParameter($entity->getUuid());
			$caseSql .= ' THEN ' . $qb->createNamedParameter(json_encode($entity->getDeleted()));
		}

		$caseSql .= ' END)';
		if ($isPostgres === true) {
			$caseSql .= '::jsonb';
		}

		$qb->update($tableName)
			->set($deletedCol, $qb->createFunction($caseSql))
			->where(
				$qb->expr()->in(
					$uuidCol,
					$qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		$qb->executeStatement();
	}//end executeBatchSoftDeleteUpdate()

	/**
	 * Delete all objects belonging to a specific schema from the magic table.
	 *
	 * This method performs an optimized bulk deletion from the magic table
	 * for all objects belonging to a given register/schema combination.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $hardDelete Whether to perform a hard delete (default: false for soft delete).
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @return int The number of objects deleted.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
	 */
	public function deleteObjectsBySchema(
		Register $register,
		Schema $schema,
		bool $hardDelete = false,
	): int {
		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$scope     = [$register->getId(), $schema->getId()];
		$context   = ['registerId' => $scope[0], 'schemaId' => $scope[1], 'tableName' => $tableName];

		// Info: bulk destruction. Never routine, always worth explaining later.
		$this->logger->info(
			message: '[MagicMapper] Deleting all objects from magic table for schema',
			context: array_merge(['file' => __FILE__, 'line' => __LINE__], $context, ['hardDelete' => $hardDelete])
		);

		if ($this->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			$this->logger->warning(
				message: '[MagicMapper] Cannot delete from magic table - table does not exist',
				context: array_merge(['file' => __FILE__, 'line' => __LINE__], $context)
			);
			return 0;
		}

		$qb      = $this->db->getQueryBuilder();
		$message = '[MagicMapper] Hard deleted objects from magic table';
		if ($hardDelete === true) {
			$qb->delete($tableName);
			$this->whereRegisterAndSchema(qb: $qb, scope: $scope);
		}

		if ($hardDelete === false) {
			$message = '[MagicMapper] Soft deleted objects in magic table';
			$qb->update($tableName)->set(
				self::METADATA_PREFIX . 'deleted',
				$qb->createNamedParameter($this->bulkSoftDeleteMetadata(), \PDO::PARAM_STR)
			);
			$this->whereRegisterAndSchema(qb: $qb, scope: $scope);
			// Only soft-delete objects that aren't already soft-deleted.
			$qb->andWhere($qb->expr()->isNull(self::METADATA_PREFIX . 'deleted'));
		}

		$deletedCount = $qb->executeStatement();

		$this->logger->debug(
			message: $message,
			context: array_merge(['file' => __FILE__, 'line' => __LINE__, 'deletedCount' => $deletedCount], $context)
		);

		return $deletedCount;
	}//end deleteObjectsBySchema()

	/**
	 * Restrict a query to the rows of one register and schema.
	 *
	 * @param IQueryBuilder $qb    The query to restrict.
	 * @param array         $scope The register id and the schema id.
	 *
	 * @psalm-param array{0: int|null, 1: int|null} $scope
	 *
	 * @return void
	 */
	private function whereRegisterAndSchema(IQueryBuilder $qb, array $scope): void {
		$qb->where(
			$qb->expr()->eq(self::METADATA_PREFIX . 'register', $qb->createNamedParameter($scope[0], \PDO::PARAM_INT))
		)->andWhere(
			$qb->expr()->eq(self::METADATA_PREFIX . 'schema', $qb->createNamedParameter($scope[1], \PDO::PARAM_INT))
		);
	}//end whereRegisterAndSchema()

	/**
	 * The _deleted metadata a bulk soft delete writes, as JSON.
	 *
	 * @return string The encoded deletion metadata.
	 */
	private function bulkSoftDeleteMetadata(): string {
		return (string) json_encode(
			[
				'time' => (new DateTime())->format('Y-m-d H:i:s'),
				'user' => $this->userSession->getUser()?->getUID() ?? 'system',
				'reason' => 'Bulk soft delete via deleteObjectsBySchema',
				'retention' => 30,
			]
		);
	}//end bulkSoftDeleteMetadata()

	/**
	 * Hard-delete the rows of a register+schema table whose `_expires` has passed.
	 *
	 * The sweep for rows written through bulkUpsert() with an expiry, i.e. raw
	 * appends. There is no soft-delete and no audit entry: those rows never had
	 * either, and keeping expired telemetry as tombstones would defeat the
	 * retention the expiry expresses. Rows with a NULL `_expires` are never
	 * touched. A table that does not exist yet has nothing to purge and answers 0.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return int The number of rows removed.
	 *
	 * @throws Exception If the delete fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
	 */
	public function purgeExpired(Register $register, Schema $schema): int {
		if ($this->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			return 0;
		}

		return $this->bulkHandler->purgeExpired(
			register: $register,
			schema: $schema,
			tableName: $this->getTableNameForRegisterSchema(register: $register, schema: $schema)
		);
	}//end purgeExpired()

	/**
	 * Batch delete objects by UUID list from a register+schema magic table.
	 *
	 * Performs a single SQL statement for all UUIDs instead of one-by-one.
	 * Supports both soft delete (sets _deleted metadata) and hard delete.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param array $uuids Array of object UUIDs to delete.
	 * @param bool $hardDelete Whether to hard delete (default: soft delete).
	 *
	 * @return int Number of objects deleted.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function deleteObjectsByUuids(
		Register $register,
		Schema $schema,
		array $uuids,
		bool $hardDelete = false,
	): int {
		if (empty($uuids) === true) {
			return 0;
		}

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

		if ($this->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			return 0;
		}

		$uuidCol = self::METADATA_PREFIX . 'uuid';
		$qb = $this->db->getQueryBuilder();

		if ($hardDelete === true) {
			$qb->delete($tableName)
				->where($qb->expr()->in($uuidCol, $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)));

			return $qb->executeStatement();
		}

		// Soft delete — set _deleted metadata in a single UPDATE.
		$deletedMetadata = json_encode(
			[
				'time' => (new DateTime())->format('Y-m-d H:i:s'),
				'user' => $this->userSession->getUser()?->getUID() ?? 'system',
				'reason' => 'Bulk soft delete via deleteObjectsByUuids',
				'retention' => 30,
			]
		);

		$qb->update($tableName)
			->set(self::METADATA_PREFIX . 'deleted', $qb->createNamedParameter($deletedMetadata, \PDO::PARAM_STR))
			->where($qb->expr()->in($uuidCol, $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNull(self::METADATA_PREFIX . 'deleted'));

		return $qb->executeStatement();
	}//end deleteObjectsByUuids()

	/**
	 * Lock object in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to lock.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param int|null $lockDuration Lock duration in seconds (null for default).
	 * @param string|null $process What the lock was taken for.
	 * @param string|null $runUuid Flow run taking the lock, for a run-scoped lock.
	 *
	 * @throws Exception If locking fails.
	 *
	 * @return ObjectEntity The locked object entity.
	 */
	public function lockObjectEntity(
		ObjectEntity $entity,
		Register $register,
		Schema $schema,
		?int $lockDuration = null,
		?string $process = null,
		?string $runUuid = null,
	): ObjectEntity {
		// Lock using entity method.
		//
		// `$process` used to be dropped here: this method hardcoded the
		// literal 'MagicMapper lock', so every caller's process tag was
		// discarded before it reached the payload. integriq mints a fresh
		// UUID per lock and passes it in, and no caller has ever been able to
		// read back what a lock was taken FOR.
		$entity->lock(
			userSession: $this->userSession,
			process: ($process ?? 'MagicMapper lock'),
			duration: $lockDuration,
			runUuid: $runUuid
		);

		// Update entity in table with locked field set.
		$this->updateObjectEntity(entity: $entity, register: $register, schema: $schema);

		$this->logger->debug(
			message: '[MagicMapper] Locked object in register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $entity->getUuid(),
				'duration' => $lockDuration,
			]
		);

		// Dispatch locked event for audit trails.
		$this->eventDispatcher->dispatchTyped(new ObjectLockedEvent(object: $entity));

		return $entity;
	}//end lockObjectEntity()

	/**
	 * Unlock object in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to unlock.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param string|null $runUuid Flow run releasing the lock, for a run-scoped lock.
	 * @param bool $break Release regardless of holder (administrator break-lock).
	 *
	 * @throws Exception If unlocking fails.
	 *
	 * @return ObjectEntity The unlocked object entity.
	 */
	public function unlockObjectEntity(
		ObjectEntity $entity,
		Register $register,
		Schema $schema,
		?string $runUuid = null,
		bool $break = false,
	): ObjectEntity {
		// Unlock using entity method.
		$entity->unlock($this->userSession, $runUuid, $break);

		// Update entity in table with locked field cleared.
		$this->updateObjectEntity(entity: $entity, register: $register, schema: $schema);

		$this->logger->debug(
			message: '[MagicMapper] Unlocked object in register+schema table',
			context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $entity->getUuid()]
		);

		// Dispatch unlocked event for audit trails.
		$this->eventDispatcher->dispatchTyped(new ObjectUnlockedEvent(object: $entity));

		return $entity;
	}//end unlockObjectEntity()

	/**
	 * Perform bulk upsert operation on register+schema table
	 *
	 * This method provides high-performance bulk insert/update operations for dynamic tables.
	 * It delegates to MagicBulkHandler for the actual database operations.
	 *
	 * @param array $objects Array of object data in standard format
	 * @param Register $register Register context
	 * @param Schema $schema Schema context
	 * @param string $tableName Target table name
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows (audit changeset or update
	 *                                  event). False narrows the fetch to the uuid.
	 *
	 * @return array[] Array of complete objects with object_status field
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @psalm-return list<array<string, mixed>>
	 */
	public function bulkUpsert(
		array $objects,
		Register $register,
		Schema $schema,
		string $tableName,
		bool $needsPreUpdateState = true,
	): array {
		$target = new MagicTableTarget(register: $register, schema: $schema);
		$target->tableName = $tableName;
		return $this->bulkUpsertInto(target: $target, batch: new MagicUpsertBatch(objects: $objects, needsPreUpdateState: $needsPreUpdateState));
	}//end bulkUpsert()

	/**
	 * Bulk upsert rows into a register+schema table, creating the table when it is missing (see bulkUpsert()).
	 *
	 * @param MagicTableTarget $target The register, schema and table name to write to.
	 * @param MagicUpsertBatch $batch  The rows and whether their pre-update state is needed.
	 *
	 * @return array The handler's upsert result.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function bulkUpsertInto(MagicTableTarget $target, MagicUpsertBatch $batch): array {
		$register            = $target->register;
		$schema              = $target->schema;
		$tableName           = $target->tableName;
		$objects             = $batch->objects;
		$needsPreUpdateState = $batch->needsPreUpdateState;

		$this->logger->debug(
			message: '[MagicMapper] Delegating bulk upsert to MagicBulkHandler',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'register' => $register->getId(),
				'schema' => $schema->getId(),
				'table' => $tableName,
				'object_count' => count($objects),
			]
		);

		// The bulk path never went through SaveObject's encryption step. An
		// encrypted property used to have no column, so bulk dropped its value;
		// now that it has one, encrypt here so it can only ever hold an envelope.
		$objects = (new MagicFlaggedEncryption(container: $this->container))->forRows(objects: $objects, schema: $schema);

		try {
			return $this->bulkHandler->bulkUpsert(
				objects: $objects,
				register: $register,
				schema: $schema,
				tableName: $tableName,
				needsPreUpdateState: $needsPreUpdateState
			);
		} catch (\Exception $e) {
			$target = new MagicTableTarget(register: $register, schema: $schema);
			$target->tableName = $tableName;
			$this->createMissingBulkTable(error: $e, target: $target);

			// Retry the bulk upsert.
			return $this->bulkHandler->bulkUpsert(
				objects: $objects,
				register: $register,
				schema: $schema,
				tableName: $tableName
			);
		}//end try
	}//end bulkUpsertInto()

	/**
	 * Create the table a failed bulk upsert found missing, or rethrow the failure.
	 *
	 * ONLY CREATE A TABLE THAT IS ACTUALLY MISSING. `force: true` makes
	 * ensureTableForRegisterSchema() DROP an existing table before
	 * recreating it, which destroys every row with no audit entry — and
	 * the message test is wider than its name: PostgreSQL says
	 * `column "x" of relation "y" does not exist` for a missing COLUMN
	 * (42703) as well as for a missing table (42P01), so a schema whose
	 * magic table was merely out of sync could reach this recovery and
	 * come back empty. If the table is there, the failure was something
	 * else, so surface it instead of resolving it destructively.
	 *
	 * Asked of the connection rather than of tableExistsForRegisterSchema(),
	 * whose positive answers are cached for TABLE_CACHE_TIMEOUT: a stale
	 * "yes" here would turn a genuine missing-table recovery into an error.
	 *
	 * @param \Exception       $error  The bulk upsert failure.
	 * @param MagicTableTarget $target The register, schema and table name of the upsert.
	 *
	 * @return void
	 *
	 * @throws \Exception The failure itself, unless it is a missing table that is now created.
	 */
	private function createMissingBulkTable(\Exception $error, MagicTableTarget $target): void {
		$message = $error->getMessage();
		if ($this->namesAMissingTable(message: $message) === false) {
			// Re-throw if it's not a table-not-found error.
			throw $error;
		}

		$this->logger->warning(
			message: '[MagicMapper] Table does not exist, creating and retrying bulkUpsert',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'register' => $target->register->getId(),
				'schema' => $target->schema->getId(),
				'table' => $target->tableName,
				'error' => $message,
			]
		);

		if ($this->db->tableExists($target->tableName) === true) {
			throw $error;
		}

		$this->ensureTableForRegisterSchema(register: $target->register, schema: $target->schema, force: true);
	}//end createMissingBulkTable()

	/**
	 * Whether a database error message reads as "table does not exist" (PostgreSQL: 42P01, MySQL: 1146).
	 *
	 * @param string $message The error message.
	 *
	 * @return bool
	 */
	private function namesAMissingTable(string $message): bool {
		return str_contains($message, '42P01') === true
			|| str_contains($message, 'does not exist') === true
			|| str_contains($message, "doesn't exist") === true
			|| str_contains($message, '1146') === true;
	}//end namesAMissingTable()

	/**
	 * Find objects that reference a specific UUID in any of their columns.
	 *
	 * This method searches across ALL magic mapper tables for objects that
	 * contain the specified UUID in any column. This is used for inverse
	 * relationship resolution.
	 *
	 * For PostgreSQL, it uses casting to text and LIKE for JSON columns.
	 * For other databases, it uses LIKE on all columns.
	 *
	 * @param string $uuid The UUID to search for
	 * @param string|null $_search Optional search filter
	 * @param bool $_partialMatch Whether to use partial matching
	 *
	 * @return ObjectEntity[] Array of objects that contain the UUID
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelation(string $uuid, ?string $_search = null, bool $_partialMatch = false): array {
		return $this->findRelatedTo(uuid: $uuid);
	}//end findByRelation()

	/**
	 * Find the objects of every magic table whose relations hold the given uuid.
	 *
	 * See findByRelation(), whose search and partial-match arguments were never applied.
	 *
	 * @param string $uuid The UUID to search for.
	 *
	 * @return ObjectEntity[] The related objects.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findRelatedTo(string $uuid): array {
		if (empty($uuid) === true) {
			return [];
		}

		$tables  = $this->getAllMagicMapperTables();
		$results = [];
		foreach ($tables as $tableName) {
			$results = array_merge($results, $this->findByRelationInTable(uuid: $uuid, tableName: $tableName));
		}

		$this->logger->debug(
			message: '[MagicMapper] findByRelation completed',
			context: ['uuid' => $uuid, 'tableCount' => count($tables), 'resultCount' => count($results)]
		);

		return $results;
	}//end findRelatedTo()

	/**
	 * Find objects that reference a UUID using the _relations column.
	 *
	 * This is a PERFORMANT alternative to findByRelation() that uses
	 * PostgreSQL's JSONB @> operator on the indexed _relations column
	 * instead of slow full-text LIKE searches.
	 *
	 * The _relations column stores an array of UUIDs that the object references,
	 * making containment queries very efficient (O(log n) with GIN index).
	 *
	 * @param string $uuid The UUID to search for in _relations
	 *
	 * @return ObjectEntity[] Array of objects that have this UUID in their _relations
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelationUsingRelationsColumn(string $uuid): array {
		if (empty($uuid) === true) {
			return [];
		}

		$startTime = microtime(true);
		$tables    = $this->getAllMagicMapperTables();
		$results   = [];
		foreach ($tables as $tableName) {
			$results = array_merge($results, $this->relationColumnMatches(uuid: $uuid, tableName: $tableName));
		}

		$this->logger->debug(
			message: '[MagicMapper] findByRelationUsingRelationsColumn completed',
			context: [
				'uuid' => $uuid,
				'tableCount' => count($tables),
				'resultCount' => count($results),
				'executionTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
			]
		);

		return $results;
	}//end findByRelationUsingRelationsColumn()

	/**
	 * The objects of one table that reference a uuid; a failing table holds none.
	 *
	 * @param string $uuid      The uuid to look for.
	 * @param string $tableName The table.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function relationColumnMatches(string $uuid, string $tableName): array {
		try {
			return $this->relationObjects(uuid: $uuid, tableName: $tableName);
		} catch (Exception $e) {
			$this->logger->debug(
				message: '[MagicMapper] findByRelationUsingRelationsColumn table query failed',
				context: ['tableName' => $tableName, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end relationColumnMatches()

	/**
	 * The objects of one table that reference a uuid, within the caller's organisation filter.
	 *
	 * @param string $uuid      The uuid to look for.
	 * @param string $tableName The table.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function relationObjects(string $uuid, string $tableName): array {
		$fullTableName  = $this->getFullTableName(tableName: $tableName);
		[$sql, $params] = $this->relationSql()->referencing(fullTableName: $fullTableName, uuid: $uuid);

		return $this->objectsOfStatement(sql: $sql, params: $params);
	}//end relationObjects()

	/**
	 * The relation statements for this platform and the caller's organisation filter.
	 *
	 * @return MagicRelationSql The statements.
	 */
	private function relationSql(): MagicRelationSql {
		return new MagicRelationSql(
			isPostgres: $this->isPostgresPlatform(),
			organisationFilter: $this->buildOrganisationFilterForRelation()
		);
	}//end relationSql()

	/**
	 * Find objects in a specific schema's magic table that have a given entity ID in a linked type column.
	 *
	 * Used for reverse lookups: "find all objects linked to mail 1/6".
	 * The linked type columns (_mail, _contacts, etc.) store JSON arrays of string IDs.
	 *
	 * @param Schema $schema The schema whose magic table to search
	 * @param string $columnName The column name (e.g., '_mail', '_contacts')
	 * @param string $entityId The entity ID to search for
	 *
	 * @return array Array of ObjectEntity objects
	 */
	public function findByLinkedEntity(Schema $schema, string $columnName, string $entityId): array {
		return $this->findLinkedTo(schema: $schema, link: new LinkedEntityRef(columnName: $columnName, entityId: $entityId));
	}//end findByLinkedEntity()

	/**
	 * Find the objects of a schema's magic table that link to an entity (see findByLinkedEntity()).
	 *
	 * @param Schema          $schema The schema whose magic table to search.
	 * @param LinkedEntityRef $link   The column holding the link and the linked entity id.
	 *
	 * @return ObjectEntity[] The linked objects.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findLinkedTo(Schema $schema, LinkedEntityRef $link): array {
		$columnName = $link->columnName;
		$entityId   = $link->entityId;

		$tableName = $this->getTableName(schema: $schema);
		if ($tableName === null) {
			return [];
		}

		$fullTableName = $this->getFullTableName(tableName: $tableName);

		try {
			$sql = $this->relationSql()->linkedTo(fullTableName: $fullTableName, columnName: $columnName);
			return $this->objectsOfStatement(sql: $sql, params: [$entityId]);
		} catch (Exception $e) {
			$this->logger->debug(
				'[MagicMapper] findByLinkedEntity query failed',
				['table' => $fullTableName, 'column' => $columnName, 'entityId' => $entityId, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end findLinkedTo()

	/**
	 * Get the magic table name for a schema.
	 *
	 * @param Schema $schema The schema
	 *
	 * @return string|null The table name or null
	 */
	private function getTableName(Schema $schema): ?string {
		// The table name follows the pattern: TABLE_PREFIX + registerId + "_" + schemaId.
		// We need to find the register for this schema.
		$tables = $this->getAllMagicMapperTables();

		$schemaId = (string)$schema->getId();
		foreach ($tables as $tableName) {
			if (str_ends_with($tableName, '_' . $schemaId) === true) {
				return $tableName;
			}
		}

		return null;
	}//end getTableName()

	/**
	 * Batch find objects in a specific schema that reference ANY of the given UUIDs.
	 *
	 * This is a CRITICAL performance optimization for inverse relationship preloading.
	 * Instead of N queries (one per entity), we do ONE query per target schema to find
	 * ALL objects that reference ANY of our entities.
	 *
	 * Uses the _relations GIN index for efficient containment queries.
	 *
	 * @param array $uuids Array of UUIDs to search for in _relations.
	 * @param int $schemaId The target schema ID to search in.
	 * @param int $registerId The register ID for the magic table.
	 * @param string $fieldName The field name to check (for logging/debugging).
	 * @param array $additionalFieldNames Additional field names to check.
	 *
	 * @return ObjectEntity[] Array of objects that have ANY of the UUIDs in _relations
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelationBatchInSchema(
		array $uuids,
		int $schemaId,
		int $registerId,
		string $fieldName,
		array $additionalFieldNames = [],
	): array {
		$lookup = new RelationBatchLookup(uuids: $uuids, fieldName: $fieldName, additionalFieldNames: $additionalFieldNames);
		return $this->findByRelationBatchIn(ref: new RegisterSchemaRef(register: $registerId, schema: $schemaId), lookup: $lookup);
	}//end findByRelationBatchInSchema()

	/**
	 * Find the objects of one register+schema table whose relation fields hold any of the given uuids (see findByRelationBatchInSchema()).
	 *
	 * @param RegisterSchemaRef   $ref    The register and schema ids of the table.
	 * @param RelationBatchLookup $lookup The uuids and the relation fields to match in.
	 *
	 * @return ObjectEntity[] The matching objects.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findByRelationBatchIn(RegisterSchemaRef $ref, RelationBatchLookup $lookup): array {
		$uuids                = $lookup->uuids;
		$schemaId             = (int)$ref->schema;
		$registerId           = (int)$ref->register;
		$fieldName            = $lookup->fieldName;
		$additionalFieldNames = $lookup->additionalFieldNames;

		if (empty($uuids) === true) {
			return [];
		}

		// Construct the magic table name directly: openregister_table_{registerId}_{schemaId}.
		$tableName  = self::TABLE_PREFIX . $registerId . '_' . $schemaId;
		$logContext = ['tableName' => $this->getFullTableName(tableName: $tableName), 'schemaId' => $schemaId, 'registerId' => $registerId];

		if ($this->checkTableExistsInDatabase(tableName: $tableName) === false) {
			$this->logger->debug(message: '[MagicMapper] findByRelationBatchInSchema: table does not exist', context: $logContext);
			return [];
		}

		$fullTableName = $logContext['tableName'];
		$logContext += ['fieldName' => $fieldName, 'uuidCount' => count($uuids)];
		try {
			[$where, $params] = $this->relationSql()->batchWhere(uuids: $uuids, extraFields: $additionalFieldNames);
			$results = $this->objectsOfStatement(sql: "SELECT * FROM {$fullTableName} WHERE {$where} LIMIT 1000", params: $params);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] findByRelationBatchInSchema failed',
				context: array_merge($logContext, ['error' => $e->getMessage()])
			);
			return [];
		}

		$this->logger->debug(
			message: '[MagicMapper] findByRelationBatchInSchema completed',
			context: array_merge($logContext, ['resultCount' => count($results)])
		);

		return $results;
	}//end findByRelationBatchIn()

	/**
	 * The objects a statement selects.
	 *
	 * @param string $sql    The statement.
	 * @param array  $params Its parameters.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function objectsOfStatement(string $sql, array $params): array {
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		return $this->rowsToObjectEntities(rows: $stmt->fetchAll());
	}//end objectsOfStatement()

	/**
	 * Whether the connection runs on PostgreSQL.
	 *
	 * @return boolean True on PostgreSQL.
	 */
	private function isPostgresPlatform(): bool {
		return stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;
	}//end isPostgresPlatform()

	/**
	 * Convert magic table rows to object entities, skipping rows that do not convert.
	 *
	 * @param array $rows The database rows.
	 *
	 * @return ObjectEntity[] The converted entities.
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	private function rowsToObjectEntities(array $rows): array {
		$entities = [];
		foreach ($rows as $row) {
			try {
				$entity = $this->rowToObjectEntity(row: $row);
			} catch (Exception $e) {
				$this->logger->debug(message: '[MagicMapper] Failed to convert row to ObjectEntity', context: ['error' => $e->getMessage()]);
				continue;
			}

			if ($entity !== null) {
				$entities[] = $entity;
			}
		}

		return $entities;
	}//end rowsToObjectEntities()

	/**
	 * Build a multi-tenancy SQL fragment and params for _organisation filtering.
	 *
	 * Returns the SQL condition string and any additional params to bind.
	 * Ensures that inverse relationship lookups (via _extend) respect RBAC:
	 * - Unauthenticated users: only see objects with no organisation (prevents PII exposure)
	 * - Authenticated users: can see all related objects (cross-org access via publications)
	 *
	 * This addresses the GDPR/AVG issue where contactpersonen of gemeente organisations
	 * were publicly accessible via the _extend=contactpersonen mechanism.
	 *
	 * @return array{string, array} Tuple of [sqlFragment, params]
	 */
	private function buildOrganisationFilterForRelation(): array {
		$user = $this->userSession->getUser();

		if ($user !== null) {
			// Authenticated user: allow cross-org access for inverse relations.
			// The publications endpoint is designed to show data from all orgs,.
			// and authenticated users should see contact information.
			return ['', []];
		}

		// Unauthenticated: only allow objects with no organisation set.
		// This prevents PII exposure (names, emails, phone numbers) of.
		// gemeente/samenwerking contact persons to the public internet.
		return [
			' AND _organisation IS NULL',
			[],
		];
	}//end buildOrganisationFilterForRelation()

	/**
	 * Search for objects containing a UUID in a specific magic mapper table.
	 *
	 * @param string $uuid The UUID to search for
	 * @param string $tableName The table name to search in
	 *
	 * @return ObjectEntity[] Array of matching objects
	 */
	private function findByRelationInTable(string $uuid, string $tableName): array {
		try {
			return $this->relationObjects(uuid: $uuid, tableName: $tableName);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] findByRelationInTable query failed',
				context: ['tableName' => $this->getFullTableName(tableName: $tableName), 'uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end findByRelationInTable()

	/**
	 * Get all magic mapper table names from the database.
	 *
	 * Magic mapper tables follow the naming convention: openregister_table_{registerId}_{schemaId}
	 *
	 * @return string[] Array of table names (without prefix)
	 */
	private function getAllMagicMapperTables(): array {
		try {
			$platform = $this->db->getDatabasePlatform();
			$isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

			$prefix = $this->getTablePrefix();
			$tablePattern = $prefix . 'openregister_table_%';

			$sql = 'SELECT table_name FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                    AND table_name LIKE ?';
			if ($isPostgres === true) {
				$sql = 'SELECT table_name FROM information_schema.tables
                        WHERE table_schema = current_schema()
                        AND table_name LIKE ?';
			}

			$stmt = $this->db->prepare($sql);
			$stmt->execute([$tablePattern]);
			$tables = [];

			while (($row = $stmt->fetch()) !== false) {
				// Remove the configured prefix to get the table name for query builder.
				$tableName = $row['table_name'];
				if (str_starts_with($tableName, $prefix) === true) {
					$tableName = substr($tableName, strlen($prefix));
				}

				$tables[] = $tableName;
			}

			return $tables;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to get magic mapper tables',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end getAllMagicMapperTables()

	/**
	 * Get all register/schema pairs that have magic tables in the database.
	 *
	 * Discovers magic tables from information_schema and extracts register/schema IDs
	 * from table names (oc_openregister_table_{registerId}_{schemaId}).
	 *
	 * @return array<array{registerId: int, schemaId: int}> Array of register/schema ID pairs
	 */
	public function getAllRegisterSchemaPairs(): array {
		return $this->statisticsHandler->getAllRegisterSchemaPairs();
	}//end getAllRegisterSchemaPairs()

	/**
	 * Convert a database row from a magic mapper table to an ObjectEntity.
	 *
	 * @param array $row The database row
	 *
	 * @return ObjectEntity|null The ObjectEntity or null if conversion fails
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	private function rowToObjectEntity(array $row): ?ObjectEntity {
		if (isset($row['_uuid']) === false) {
			return null;
		}

		$entity = new PartialObjectEntity();
		$entity->setUuid($row['_uuid']);

		$this->applyRowMetadata(entity: $entity, row: $row);
		$entity->setObject($this->rowObjectData(row: $row, columnToPropertyMap: $this->rowColumnToPropertyMap(row: $row)));

		return $entity;
	}//end rowToObjectEntity()

	/**
	 * Set the metadata a row holds; register and schema are stored as strings.
	 *
	 * @param ObjectEntity $entity The object.
	 * @param array        $row    The database row.
	 *
	 * @return void
	 */
	private function applyRowMetadata(ObjectEntity $entity, array $row): void {
		if (isset($row['_register']) === true) {
			$entity->setRegister((string)$row['_register']);
		}

		if (isset($row['_schema']) === true) {
			$entity->setSchema((string)$row['_schema']);
		}

		if (isset($row['_name']) === true) {
			$entity->setName($row['_name']);
		}
	}//end applyRowMetadata()

	/**
	 * The property each column of a row holds, memoised per schema (PERF-12).
	 *
	 * This restores original property names ('e-mailadres') from their
	 * sanitized columns ('e_mailadres'). A schema that cannot be loaded leaves
	 * the column names as they are, and is asked for again on the next row.
	 *
	 * @param array $row The database row.
	 *
	 * @return array<string, string> Property name per column.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	private function rowColumnToPropertyMap(array $row): array {
		if (isset($row['_schema']) === false) {
			return [];
		}

		$schemaId = (int)$row['_schema'];

		return ($this->rowColumnToPropertyCache[$schemaId] ?? $this->loadColumnToPropertyMap(schemaId: $schemaId));
	}//end rowColumnToPropertyMap()

	/**
	 * Load and memoise the column map of a schema, or none when it cannot be loaded.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return array<string, string> Property name per column.
	 */
	private function loadColumnToPropertyMap(int $schemaId): array {
		try {
			$map = $this->schemaColumnToPropertyMap(schema: $this->schemaMapper->find($schemaId));
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[MagicMapper] Could not load schema for property mapping',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId, 'error' => $e->getMessage()]
			);
			return [];
		}

		$this->rowColumnToPropertyCache[$schemaId] = $map;

		return $map;
	}//end loadColumnToPropertyMap()

	/**
	 * The property of each column of a schema's table.
	 *
	 * BUG-DB-8: the same disambiguated column names the write path produces, so
	 * collision-resolved columns round-trip back to their original property.
	 * Metadata columns are left out; a schema property whose name starts with
	 * an underscore stays.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, string> Property name per column.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	private function schemaColumnToPropertyMap(Schema $schema): array {
		$properties = ($schema->getProperties() ?? []);
		$map = [];
		foreach ($this->buildTableColumnsFromSchema(schema: $schema) as $propertyName => $columnDef) {
			if (str_starts_with((string)$propertyName, '_') === false || isset($properties[$propertyName]) === true) {
				$map[($columnDef['name'] ?? $this->sanitizeColumnName(name: $propertyName))] = $propertyName;
			}
		}

		return $map;
	}//end schemaColumnToPropertyMap()

	/**
	 * The object data of a row: its non-metadata columns under their property names.
	 *
	 * @param array $row                 The database row.
	 * @param array $columnToPropertyMap Property name per column.
	 *
	 * @return array The object data.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	private function rowObjectData(array $row, array $columnToPropertyMap): array {
		$metadata   = new MagicMetadataColumn();
		$objectData = [];
		foreach ($row as $column => $value) {
			if ($metadata->field(column: $column, columnToPropertyMap: $columnToPropertyMap) === null) {
				$objectData[($columnToPropertyMap[$column] ?? $column)] = $this->decodedColumnValue(value: $value);
			}
		}

		return $objectData;
	}//end rowObjectData()

	/**
	 * A column value, decoded when it is a string holding JSON.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return mixed The value.
	 */
	private function decodedColumnValue(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		$decoded = json_decode($value, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}

		return $value;
	}//end decodedColumnValue()

	/**
	 * Check if a column exists in a database table.
	 *
	 * Queries information_schema to verify column existence. Used to prevent
	 * SQL errors when filtering on columns that don't exist in the table.
	 *
	 * @param string $tableName The table name (without oc_ prefix).
	 * @param string $columnName The column name to check.
	 *
	 * @return bool True if the column exists, false otherwise.
	 */
	private function columnExistsInTable(string $tableName, string $columnName): bool {
		try {
			$fullTableNameLower = $this->loadTableColumnCache(tableName: $tableName);

			return isset(self::$columnExistsCache[$fullTableNameLower][strtolower($columnName)]);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to check column existence',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'column' => $columnName,
					'error' => $e->getMessage(),
				]
			);
			// Return false on error to prevent invalid queries.
			return false;
		}//end try
	}//end columnExistsInTable()

	/**
	 * Load a table's column names and types into the static caches, once per process.
	 *
	 * One information_schema query fills both $columnExistsCache (names, as
	 * columnExistsInTable() has always used it) and $columnTypeCache (types).
	 *
	 * @param string $tableName The table name (with or without the oc_ prefix).
	 *
	 * @return string The lower-cased, prefixed table name used as cache key.
	 *
	 * @throws \Exception If the information_schema query fails.
	 */
	private function loadTableColumnCache(string $tableName): string {
		// Ensure table name has prefix for information_schema lookup.
		$prefix = $this->getTablePrefix();
		$fullTableName = $tableName;
		if (str_starts_with($tableName, $prefix) === false) {
			$fullTableName = $prefix . $tableName;
		}

		// PostgreSQL stores unquoted identifiers in lowercase.
		$fullTableNameLower = strtolower($fullTableName);

		// OPTIMIZATION: Check in-memory cache first.
		if (isset(self::$columnExistsCache[$fullTableNameLower]) === true
			&& isset(self::$columnTypeCache[$fullTableNameLower]) === true
		) {
			return $fullTableNameLower;
		}

		// Load ALL columns for this table in one query (instead of one query per column).
		// table_name is compared as stored (magic tables are created lower-case).
		// Wrapping that column in a function keeps PostgreSQL from using the
		// catalog index, so each lookup walked the columns of every table
		// (89k columns over 1.7k magic tables: 18-33 ms against 3-4 ms).
		$sql = 'SELECT LOWER(column_name) AS col, LOWER(data_type) AS col_type FROM information_schema.columns
                WHERE table_name = ?';

		$stmt = $this->db->prepare($sql);
		$stmt->execute([$fullTableNameLower]);

		// Cache all columns for this table.
		$names = [];
		$types = [];
		while (($row = $stmt->fetch()) !== false) {
			$names[$row['col']] = true;
			$types[$row['col']] = (string)($row['col_type'] ?? '');
		}

		self::$columnExistsCache[$fullTableNameLower] = $names;
		self::$columnTypeCache[$fullTableNameLower] = $types;

		return $fullTableNameLower;
	}//end loadTableColumnCache()

	/**
	 * List the columns of a table whose real database type is a date or time stamp.
	 *
	 * PostgreSQL reports `date` and `timestamp with[out] time zone`; MySQL and
	 * MariaDB report `date`, `datetime` and `timestamp`. A property whose schema
	 * says date-time but whose column predates that format stays text, because
	 * the table sync adds columns and never retypes them. The search handler
	 * uses this list so it only wraps a real date column in COALESCE.
	 *
	 * @param string $tableName The table name (with or without the oc_ prefix).
	 *
	 * @return array<int, string> Lower-cased column names; empty when the lookup fails.
	 */
	private function getDateTimeColumns(string $tableName): array {
		try {
			$fullTableNameLower = $this->loadTableColumnCache(tableName: $tableName);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to read column types',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}

		$columns = [];
		foreach (self::$columnTypeCache[$fullTableNameLower] ?? [] as $column => $type) {
			$type = strtolower($type);
			if ($type === 'date' || $type === 'datetime' || str_starts_with($type, 'timestamp') === true) {
				$columns[] = (string)$column;
			}
		}

		return $columns;
	}//end getDateTimeColumns()

	// ==================================================================================
	// ABSTRACT OBJECT MAPPER IMPLEMENTATION (public API facade methods)
	// ==================================================================================

	/**
	 * Extract register and schema from an ObjectEntity.
	 *
	 * @param ObjectEntity $entity The object entity.
	 *
	 * @return array{Register|null, Schema|null} Array with [register, schema].
	 */
	private function getResolvedRegisterAndSchema(ObjectEntity $entity): array {
		$register = null;
		$schema   = null;
		if ($entity->getRegister() !== null) {
			try {
				$register = $this->registerMapper->find(id: (int)$entity->getRegister(), _rbac: false, _multitenancy: false);
			} catch (Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to resolve register from entity',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'registerId' => $entity->getRegister(),
						'error' => $e->getMessage(),
					]
				);
			}
		}

		if ($entity->getSchema() !== null) {
			try {
				$schema = $this->schemaMapper->find(id: (int)$entity->getSchema(), _rbac: false, _multitenancy: false);
			} catch (Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to resolve schema from entity',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schemaId' => $entity->getSchema(),
						'error' => $e->getMessage(),
					]
				);
			}
		}

		return [$register, $schema];
	}//end getResolvedRegisterAndSchema()

	/**
	 * Find an object entity by identifier (ID, UUID, slug, or URI).
	 *
	 * When register+schema context is available, searches the specific magic table.
	 * Otherwise searches across all magic tables.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param Register|null $register Optional register to filter by.
	 * @param Schema|null $schema Optional schema to filter by.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return ObjectEntity The found object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function find(
		string|int $identifier,
		?Register $register = null,
		?Schema $schema = null,
		bool $includeDeleted = false,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ObjectEntity {
		$find  = new MagicIdentifierFind(identifier: $identifier, includeDeleted: $includeDeleted, registerIdScope: $register?->getId());
		$table = $this->registerSchemaOrNull(register: $register, schema: $schema);
		return $this->findObject(find: $find, table: $table, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end find()

	/**
	 * Find an object by identifier: in its register+schema table when given, else across the magic tables (see find()).
	 *
	 * @param MagicIdentifierFind $find          The identifier, whether deleted objects count and, without a table, the register to narrow to.
	 * @param RegisterSchema|null $table         The register and schema whose table to search, or null to scan.
	 * @param bool                $_rbac         Whether to apply RBAC checks (default: true).
	 * @param bool                $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return ObjectEntity The found object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findObject(MagicIdentifierFind $find, ?RegisterSchema $table=null, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity {
		if ($table !== null) {
			$entity = $this->findInRegisterSchemaTable(
				identifier: $find->identifier,
				register: $table->register,
				schema: $table->schema,
				_rbac: $_rbac,
				_multitenancy: $_multitenancy,
				includeDeleted: $find->includeDeleted
			);
			$entity->setSource('orm');
			return $entity;
		}

		// A register WITHOUT a schema still bounds the search: only that
		// register's magic tables can hold the object. Passing it turns an
		// instance-wide union into a handful of branches.
		$result = $this->findAcrossAllMagicTables(
			identifier: $find->identifier,
			includeDeleted: $find->includeDeleted,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy,
			registerIdScope: $find->registerIdScope
		);
		$result['object']->setSource('orm');
		return $result['object'];
	}//end findObject()

	/**
	 * Find an object across all storage sources (all magic tables).
	 *
	 * Searches all magic tables to find an object by its identifier without
	 * requiring register/schema context.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param int|null $registerIdScope Restrict the scan to one register's tables.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found in any source.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findAcrossAllSources(
		string|int $identifier,
		bool $includeDeleted = false,
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
		bool $_rbac = true,
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
		bool $_multitenancy = true,
		?int $registerIdScope = null,
	): array {
		return $this->findAcrossAllMagicTables(
			identifier: $identifier,
			includeDeleted: $includeDeleted,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy,
			registerIdScope: $registerIdScope
		);
	}//end findAcrossAllSources()

	/**
	 * Find all ObjectEntities of one register+schema table with filtering, pagination, ids, search and relation narrowing.
	 *
	 * @param MagicListRequest $request The table, the listing (limit unbounded for all rows) and the ids and relation to narrow to.
	 *
	 * @return ObjectEntity[]
	 * @throws \OCP\DB\Exception If a database error occurs.
	 * @psalm-return list<ObjectEntity>
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findAllListed(MagicListRequest $request): array {
		$table   = $request->table;
		$listing = $request->query;
		$ids     = $request->ids;
		$search  = $listing->search;

		if ($table === null) {
			$this->logger->warning(
				message: '[MagicMapper] findAllListed() called without register/schema context',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		// Thread id and full-text search through to the register+schema search.
		// Without this, `ids`/`search` passed to findAllListed() were silently dropped,
		// so an in-request findAll(['ids' => [<just-written-uuid>]]) returned
		// nothing even though the object exists. Inject them as the reserved
		// `_ids`/`_search` query params the search handler understands.
		$filters = $listing->filters;
		if ($ids !== null && empty($ids) === false) {
			$filters['_ids'] = $ids;
		}

		if ($search !== null && trim($search) !== '') {
			$filters['_search'] = $search;
		}

		$filters = $this->narrowedToUses(query: $filters, uses: $request->uses);

		$entities = $this->findAllIn(
			table: $table,
			listing: new ListQuery(limit: $listing->limit, offset: $listing->offset, filters: $filters, sort: $listing->sort)
		);
		foreach ($entities as $entity) {
			$entity->setSource('orm');
		}

		return $entities;
	}//end findAllListed()

	/**
	 * The register and schema as one table, or null when either is missing.
	 *
	 * @param Register|null $register The register, if any.
	 * @param Schema|null   $schema   The schema, if any.
	 *
	 * @return RegisterSchema|null The pair, or null.
	 */
	private function registerSchemaOrNull(?Register $register, ?Schema $schema): ?RegisterSchema {
		if ($register === null || $schema === null) {
			return null;
		}

		return new RegisterSchema(register: $register, schema: $schema);
	}//end registerSchemaOrNull()

	/**
	 * Find multiple objects by their IDs or UUIDs.
	 *
	 * @param array $ids Array of IDs or UUIDs.
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findMultiple(array $ids): array {
		return $this->findMultipleAcrossAllMagicTables(uuids: $ids);
	}//end findMultiple()

	/**
	 * Which of these UUIDs, all living in one register+schema table, may the caller read?
	 *
	 * The answer comes from the same access-control filter the single-object read
	 * applies (applyAccessControlToQuery: RBAC plus multitenancy, with every rule
	 * that widens or narrows them), so a name lookup can never disagree with
	 * `GET /api/objects/{register}/{schema}/{id}`. Soft-deleted rows are never
	 * readable here. Any failure answers "none": a lookup that cannot establish
	 * read access must not disclose.
	 *
	 * @param int           $registerId The register the table belongs to.
	 * @param int           $schemaId   The schema the table belongs to.
	 * @param array<string> $uuids      UUIDs to test, all expected in that table.
	 *
	 * @return array<int, string> The subset of $uuids the caller may read.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function filterReadableUuids(int $registerId, int $schemaId, array $uuids): array {
		return ($this->readableUuidsOrNull(registerId: $registerId, schemaId: $schemaId, uuids: $uuids) ?? []);
	}//end filterReadableUuids()

	/**
	 * The same filter as filterReadableUuids(), but a failure is told apart from "none".
	 *
	 * A caller that reports completeness (a tree that says which bound stopped
	 * it) must not read "read access could not be established" as "nothing is
	 * readable here": the first is an incomplete answer, the second is a
	 * complete one. Null means the check itself failed (and was logged);
	 * an empty array means it ran and the caller may read none of the uuids.
	 *
	 * @param int           $registerId The register the table belongs to.
	 * @param int           $schemaId   The schema the table belongs to.
	 * @param array<string> $uuids      UUIDs to test, all expected in that table.
	 *
	 * @return array<int, string>|null The readable subset, or null when read access could not be established.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function readableUuidsOrNull(int $registerId, int $schemaId, array $uuids): ?array {
		$uuids = array_values(array_unique(array_filter($uuids, fn ($uuid): bool => is_string($uuid) && $uuid !== '')));
		if (empty($uuids) === true) {
			return [];
		}

		$readable = [];
		try {
			// Metadata lookups only: which table, and which rules. The rows themselves
			// are filtered by the access-control seam below.
			$register = $this->registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false);
			$schema = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
			if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
				return [];
			}

			$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
			$uuidCol = 't.' . self::METADATA_PREFIX . 'uuid';

			// Chunked so a long list never exceeds the driver's parameter limit.
			foreach (array_chunk($uuids, 500) as $chunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->select($uuidCol)
					->from($tableName, 't')
					->where($qb->expr()->in($uuidCol, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
					->andWhere($qb->expr()->isNull('t.' . self::METADATA_PREFIX . 'deleted'));

				$this->searchHandler->applyAccessControlToQuery(
					qb: $qb,
					schema: $schema,
					_rbac: true,
					_multitenancy: true,
					registerId: $registerId,
					tableName: $tableName
				);

				$result = $qb->executeQuery();
				while (($row = $result->fetch()) !== false) {
					$uuid = $row[self::METADATA_PREFIX . 'uuid'] ?? null;
					if (is_string($uuid) === true) {
						$readable[] = $uuid;
					}
				}

				$result->closeCursor();
			}//end foreach
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[MagicMapper] filterReadableUuids: read access could not be established; disclosing none',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'registerId' => $registerId,
					'schemaId' => $schemaId,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		return $readable;
	}//end readableUuidsOrNull()

	/**
	 * Find all objects for a given schema.
	 *
	 * Searches across all magic tables that belong to the given schema.
	 *
	 * PERF-13: the optional $limit/$offset/$filter arguments are pushed down
	 * into SQL (via findAllInRegisterSchemaTable) so callers that only need a
	 * bounded/filtered slice no longer pull every row of the schema into PHP.
	 * They are optional and default to the previous unbounded full-table read,
	 * so existing callers are unaffected.
	 *
	 * @param int $schemaId Schema ID.
	 * @param int|null $limit Optional maximum number of rows (pushed to SQL).
	 * @param int|null $offset Optional rows to skip for pagination (pushed to SQL).
	 * @param array|null $filter Optional filter criteria (pushed to SQL).
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findBySchema(int $schemaId, ?int $limit = null, ?int $offset = null, ?array $filter = null): array {
		$listing = new ListQuery(limit: ($limit ?? ListQuery::UNBOUNDED), offset: $offset, filters: ($filter ?? []));
		return $this->findBySchemaListed(schemaId: $schemaId, listing: $listing);
	}//end findBySchema()

	/**
	 * Find the objects of one schema across every register that holds it (see findBySchema()).
	 *
	 * @param int       $schemaId The schema id.
	 * @param ListQuery $listing  The limit (unbounded for all rows), offset and filters per table.
	 *
	 * @return ObjectEntity[] The objects.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findBySchemaListed(int $schemaId, ListQuery $listing): array {
		$limit  = $listing->limitOrNull();
		$offset = $listing->offset;
		$filter = $listing->filters;

		// Find all register+schema pairs that include this schema.
		$allPairs = $this->getAllRegisterSchemaPairs();
		$results = [];

		foreach ($allPairs as $pair) {
			if ((int)$pair['schemaId'] !== $schemaId) {
				continue;
			}

			try {
				$register = $this->registerMapper->find($pair['registerId'], _multitenancy: false, _rbac: false);
				$schema = $this->schemaMapper->find($pair['schemaId'], _multitenancy: false, _rbac: false);

				$entities = $this->findAllInRegisterSchemaTable(
					register: $register,
					schema: $schema,
					limit: $limit,
					offset: $offset,
					filters: $filter
				);
				$results = array_merge($results, $entities);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to search table for findBySchema',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'registerId' => $pair['registerId'],
						'schemaId' => $pair['schemaId'],
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $results;
	}//end findBySchemaListed()

	/**
	 * Insert a new object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to insert.
	 * @param Register|null $register Optional register for routing.
	 * @param Schema|null $schema Optional schema for routing.
	 * @param bool $failIfExists Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @return ObjectEntity Inserted entity.
	 *
	 * @throws Exception If insertion fails.
	 */
	public function insert(Entity $entity, ?Register $register = null, ?Schema $schema = null, bool $failIfExists = false): Entity {
		if ($entity instanceof ObjectEntity === false) {
			throw new Exception('Entity must be an instance of ObjectEntity');
		}

		if ($register === null || $schema === null) {
			[$register, $schema] = $this->getResolvedRegisterAndSchema(entity: $entity);
		}

		if ($register === null || $schema === null) {
			throw new Exception('Cannot insert object without register and schema context');
		}

		\OCA\OpenRegister\Service\WritePhase::mark('mm:pre');
		$insertedEntity = $this->insertObjectEntity(entity: $entity, register: $register, schema: $schema, failIfExists: $failIfExists);
		\OCA\OpenRegister\Service\WritePhase::mark('mm:DB-INSERT');

		$this->announceInsert(inserted: $insertedEntity, target: new RegisterSchema(register: $register, schema: $schema));
		\OCA\OpenRegister\Service\WritePhase::mark('mm:EVENT-DISPATCH');

		return $insertedEntity;
	}//end insert()

	/**
	 * Announce what an insert did: an update of the stored row when its identifier was taken, else a creation.
	 *
	 * Which event that is, is decided in ObjectEventGate (decision 227). A draft is never
	 * deferred: only the listeners that keep the stored draft right receive its event, and
	 * they need it now.
	 *
	 * @param ObjectEntity   $inserted The object as written.
	 * @param RegisterSchema $target   Where it was written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/insert-race-follow-ups/specs/object-lifecycle/spec.md#requirement-a-create-written-as-an-update-is-announced-as-an-update
	 */
	private function announceInsert(ObjectEntity $inserted, RegisterSchema $target): void {
		$replaced = $this->replacedByLastInsert;
		$this->replacedByLastInsert = null;
		if ($this->suppressLifecycleEvents() === true) {
			return;
		}

		$gate = new ObjectEventGate(events: $this->eventDispatcher);
		if ($replaced !== null) {
			$gate->updated(new: $inserted, old: $replaced);
			return;
		}

		if ($inserted->isDraft() === true
			|| $this->dispatchCreatedDeferred(entity: $inserted, table: $target) === false
		) {
			$gate->created(object: $inserted);
		}
	}//end announceInsert()

	/**
	 * Hand the created-event off to a background job instead of dispatching it inline.
	 *
	 * `ObjectCreatedEvent` has roughly two dozen listeners across the fleet, and
	 * dispatched inline the caller waits for all of them. Measured 2026-07-29,
	 * that dispatch was 234-501 ms of a ~530 ms create, against a 76 ms insert.
	 * None of it is needed to tell the caller its object was saved.
	 *
	 * OPT-IN, and deliberately so. Deferring changes an observable contract: a
	 * 2xx stops meaning "and every side effect has been applied". A synchronous
	 * flow (`executionMode: sync`) in particular exists precisely so its effects
	 * land before the save returns, and deferring would silently break that
	 * promise. So this stays off until an instance decides it wants the trade,
	 * via `occ config:app:set openregister defer_object_events --value=1`.
	 *
	 * Returns false — meaning "dispatch it inline yourself" — whenever the
	 * deferral cannot be set up, so a misconfiguration degrades to the current
	 * behaviour rather than to silently losing every event.
	 *
	 * @param ObjectEntity   $entity The object just inserted.
	 * @param RegisterSchema $table  Its register and schema.
	 *
	 * @return boolean True when the event was queued and must NOT be dispatched inline.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	private function dispatchCreatedDeferred(ObjectEntity $entity, RegisterSchema $table): bool {
		if ($this->appConfig->getValueString('openregister', 'defer_object_events', '') !== '1') {
			return false;
		}

		$uuid = (string)$entity->getUuid();
		if ($uuid === '') {
			// Without an identifier the job cannot re-read the object, so there
			// is nothing to defer TO. Dispatch inline.
			return false;
		}

		try {
			$jobList = $this->container->get(\OCP\BackgroundJob\IJobList::class);
			$jobList->add(
				\OCA\OpenRegister\BackgroundJob\JobHandles::DEFERRED_OBJECT_EVENT,
				[
					'uuid' => $uuid,
					'registerId' => (int)($table->register->getId() ?? 0),
					'schemaId' => (int)($table->schema->getId() ?? 0),
					'action' => 'created',
					// The acting user travels WITH the job. A background job has
					// no session, and OpenRegister reads are organisation-filtered
					// against the session user, so a listener that asks "are there
					// active subscriptions?" from an unauthenticated context is
					// told "no" and silently does nothing. Verified 2026-07-29:
					// without this the deferred dispatch ran clean, logged nothing,
					// and produced ZERO CloudEvents where the inline path produced
					// one — a perfectly green no-op.
					'user' => ($this->userSession->getUser()?->getUID() ?? ''),
				]
			);

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[MagicMapper] Could not queue a deferred object event; dispatching inline instead: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
			);

			return false;
		}//end try

	}//end dispatchCreatedDeferred()

	/**
	 * Update an existing object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to update.
	 * @param Register|null $register Optional register for routing.
	 * @param Schema|null $schema Optional schema for routing.
	 * @param ObjectEntity|null $oldEntity Old entity for comparison.
	 *
	 * @return ObjectEntity Updated entity.
	 *
	 * @throws Exception If update fails.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function update(
		Entity $entity,
		?Register $register = null,
		?Schema $schema = null,
		?ObjectEntity $oldEntity = null,
	): Entity {
		if ($entity instanceof ObjectEntity === false) {
			throw new Exception('Entity must be an instance of ObjectEntity');
		}

		$change = new MagicObjectChange(entity: $entity, oldEntity: $oldEntity);
		return $this->updateChange(change: $change, table: $this->registerSchemaOrNull(register: $register, schema: $schema));
	}//end update()

	/**
	 * Update an existing object with event dispatching (see update()).
	 *
	 * @param MagicObjectChange   $change The object to write and, when known, the object as stored.
	 * @param RegisterSchema|null $table  The register and schema to route to, or null to resolve them from the object.
	 *
	 * @return ObjectEntity Updated entity.
	 * @throws Exception If update fails.
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function updateChange(MagicObjectChange $change, ?RegisterSchema $table=null): ObjectEntity {
		$entity    = $change->entity;
		$oldEntity = $change->oldEntity;
		$register  = $table?->register;
		$schema    = $table?->schema;

		if ($register === null || $schema === null) {
			[$register, $schema] = $this->getResolvedRegisterAndSchema(entity: $entity);
		}

		if ($oldEntity === null) {
			try {
				$oldEntity = $this->find(
					identifier: $entity->getUuid(),
					register: $register,
					schema: $schema,
					includeDeleted: false,
					_rbac: false,
					_multitenancy: false
				);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Could not fetch old entity for update event',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'entityId' => $entity->getId(),
						'entityUuid' => $entity->getUuid(),
						'error' => $e->getMessage(),
					]
				);
				$oldEntity = $entity;
			}//end try
		}//end if

		if ($register === null || $schema === null) {
			throw new Exception('Cannot update object without register and schema context');
		}

		$updatedEntity = $this->updateObjectEntity(
			entity: $entity,
			register: $register,
			schema: $schema,
			oldEntity: $oldEntity
		);

		$this->logger->debug(
			message: '[MagicMapper] Dispatching ObjectUpdatedEvent',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityUuid' => $updatedEntity->getUuid(),
			]
		);
		if ($this->suppressLifecycleEvents() === false) {
			(new ObjectEventGate(events: $this->eventDispatcher))->updated(new: $updatedEntity, old: $oldEntity);
		}

		return $updatedEntity;
	}//end updateChange()

	/**
	 * Whether object lifecycle events should be withheld for this write.
	 *
	 * Eight apps in the fleet subscribe to ObjectCreatedEvent/ObjectUpdatedEvent
	 * — docudesk, softwarecatalog, opencatalogi, openbuild, hermiq,
	 * zaakafhandelapp, hrmq and more. Every one of them wakes for every object
	 * written. During a configuration import that is a fan-out over baseline
	 * content: measured on this instance mid-repair, 155 "DocuDesk: Processing
	 * event", 116 compliance-subscriber calls and 116 queued extraction jobs,
	 * for SEED data — a document-extraction pass over content that shipped with
	 * the app, and a compliance score for a module nobody has configured yet.
	 *
	 * Seeding is not a user action, and there is no user intent for a listener
	 * to react to. SystemOperationContext already marks exactly that condition
	 * and is already trusted for RBAC decisions in RenderObject and
	 * PermissionHandlerInterface; nothing had yet used it for events.
	 *
	 * Gating the DISPATCH rather than each listener is deliberate: it is one
	 * change here instead of eight across apps we do not all own, and it cannot
	 * be half-adopted — a listener that never learns of the event cannot forget
	 * to check.
	 *
	 * This is scoped to writes made INSIDE SystemOperationContext::run(). A
	 * normal save is unaffected.
	 *
	 * @return bool True when the caller is a system operation and events are withheld.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext is an explicit
	 *   ambient-context marker; a static read is the whole point of it.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function suppressLifecycleEvents(): bool {
		if (\OCA\OpenRegister\Service\SystemOperationContext::isActive() === false) {
			return false;
		}

		$this->logger->debug(
			message: '[MagicMapper] System operation in progress; withholding object lifecycle event',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return true;
	}//end suppressLifecycleEvents()

	/**
	 * Delete an object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to delete.
	 *
	 * @return ObjectEntity Deleted entity.
	 *
	 * @throws Exception If deletion fails.
	 */
	public function delete(Entity $entity): Entity {
		if ($entity instanceof ObjectEntity === false) {
			throw new Exception('Entity must be an instance of ObjectEntity');
		}

		[$register, $schema] = $this->getResolvedRegisterAndSchema(entity: $entity);

		if ($register === null || $schema === null) {
			throw new Exception('Cannot delete object without register and schema context');
		}

		$deletedEntity = $this->deleteObjectEntity(
			entity: $entity,
			register: $register,
			schema: $schema,
			hardDelete: true
		);

		(new ObjectEventGate(events: $this->eventDispatcher))->deleted(object: $deletedEntity);

		return $deletedEntity;
	}//end delete()

	/**
	 * Lock an object.
	 *
	 * @param string $uuid The object UUID
	 * @param int|null $lockDuration Lock duration in seconds
	 *
	 * @return array Lock result.
	 *
	 * @psalm-return array{locked: mixed, uuid: string}
	 */
	public function lockObject(string $uuid, ?int $lockDuration = null): array {
		$result = $this->findAcrossAllSources(
			identifier: $uuid,
			_multitenancy: false,
			_rbac: false
		);
		$entity = $result['object'];
		$register = $result['register'];
		$schema = $result['schema'];

		$locked = $this->lockObjectEntity(
			entity: $entity,
			register: $register,
			schema: $schema,
			lockDuration: $lockDuration
		);

		return ['locked' => $locked->getLocked(), 'uuid' => $uuid];
	}//end lockObject()

	/**
	 * Unlock an object.
	 *
	 * @param string $uuid The object UUID
	 *
	 * @return bool True on success
	 */
	public function unlockObject(string $uuid): bool {
		$result = $this->findAcrossAllSources(
			identifier: $uuid,
			_multitenancy: false,
			_rbac: false
		);
		$entity = $result['object'];
		$register = $result['register'];
		$schema = $result['schema'];

		$this->unlockObjectEntity(entity: $entity, register: $register, schema: $schema);

		return true;
	}//end unlockObject()

	/**
	 * Restore a soft-deleted object by clearing its `_deleted` marker.
	 *
	 * Objects live in per-register/schema magic tables (oc_openregister_table_*),
	 * NOT in the legacy generic `openregister_objects` table. A direct
	 * `UPDATE openregister_objects` therefore matches zero rows — which is why
	 * the previous restore reported success but never un-deleted anything. This
	 * resolves the object's owning magic table (including deleted rows) and
	 * sets `_deleted = NULL` on the correct table.
	 *
	 * @param string $uuid The UUID (or id/slug) of the deleted object.
	 *
	 * @return ObjectEntity The restored object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found.
	 * @throws Exception If register/schema context cannot be resolved.
	 */
	public function restoreObject(string $uuid): ObjectEntity {
		$result = $this->findAcrossAllSources(
			identifier: $uuid,
			includeDeleted: true,
			_rbac: false,
			_multitenancy: false
		);
		$entity = $result['object'];
		$register = $result['register'];
		$schema = $result['schema'];

		if ($register === null || $schema === null) {
			throw new Exception('Cannot restore object without resolvable register and schema context');
		}

		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

		// The slug it held may have been given to another object since the delete
		// (#4037). Resolved lazily: the handler needs this mapper.
		$restoredSlug = $this->container->get(ObjectSlugTargetInterface::class)->slugForRestore(
			entity: $entity,
			table: new RegisterSchema(register: $register, schema: $schema)
		);

		// Clear the soft-delete marker directly on the owning magic table.
		// A native UPDATE avoids the Entity-layer array->null serialisation
		// quirk for JSON columns.
		$qb = $this->db->getQueryBuilder();
		$qb->update($tableName)
			->set(self::METADATA_PREFIX . 'deleted', $qb->createNamedParameter(null, \PDO::PARAM_NULL))
			->where(
				$qb->expr()->eq(
					self::METADATA_PREFIX . 'uuid',
					$qb->createNamedParameter($entity->getUuid())
				)
			);
		if ($restoredSlug !== $entity->getSlug()) {
			$slugType = \PDO::PARAM_STR;
			if ($restoredSlug === null) {
				$slugType = \PDO::PARAM_NULL;
			}

			$qb->set(self::METADATA_PREFIX . 'slug', $qb->createNamedParameter($restoredSlug, $slugType));
			$entity->setSlug($restoredSlug);
		}

		$qb->executeStatement();

		$entity->setDeleted(null);

		$this->logger->debug(
			message: '[MagicMapper] Restored soft-deleted object in register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $entity->getUuid(),
				'tableName' => $tableName,
			]
		);

		return $entity;
	}//end restoreObject()

	/**
	 * Record the node id of an object's files folder as bookkeeping, not as an edit of the object.
	 *
	 * The files folder is made on demand, often by a request that only lists
	 * the object's files (a portal showing a case's documents). Storing its id
	 * through update() ran the whole save path for that read: the updating and
	 * updated events, so every save-time listener (calculations, quality
	 * scoring, retention, other apps' handlers) ran as whoever was reading,
	 * with no audit row and no version bump to show for it. This write touches
	 * the `_folder` column of the one row and nothing else, dispatches no
	 * lifecycle event, and only lands while the stored value is still empty or
	 * what the caller read, so it never repoints a folder another request
	 * recorded first. It mirrors RegisterFolderRecorder for registers.
	 *
	 * @param ObjectEntity $entity The object whose folder was made.
	 * @param string|null $expected The folder value read before the folder was made: null or '' for none,
	 *                              or the legacy path that no longer resolves.
	 * @param string $folderId The node id of the folder the file service made or found.
	 *
	 * @return bool True when this call recorded the id; false when another request recorded one first.
	 *
	 * @throws Exception If the object has no uuid or its register and schema cannot be resolved.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-recording-an-objects-folder-id-is-bookkeeping-req-ofib-001
	 */
	public function recordFolder(ObjectEntity $entity, ?string $expected, string $folderId): bool {
		return $this->recordFolderOf(entity: $entity, record: new FolderRecord(expected: $expected, folderId: $folderId));
	}//end recordFolder()

	/**
	 * Record the folder an object's files live in (see recordFolder()).
	 *
	 * @param ObjectEntity $entity The object whose folder was made.
	 * @param FolderRecord $record The folder value read before and the folder node id.
	 *
	 * @return bool See recordFolder().
	 * @throws Exception When the object has no uuid or no register and schema context.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function recordFolderOf(ObjectEntity $entity, FolderRecord $record): bool {
		$expected = $record->expected;
		$folderId = $record->folderId;

		$uuid = $entity->getUuid();
		if ($uuid === null || $uuid === '') {
			throw new Exception('Cannot record an object folder without the object uuid');
		}

		[$register, $schema] = $this->getResolvedRegisterAndSchema(entity: $entity);
		if ($register === null || $schema === null) {
			throw new Exception('Cannot record an object folder without register and schema context');
		}

		$folderCol = self::METADATA_PREFIX . 'folder';
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableNameForRegisterSchema(register: $register, schema: $schema))
			->set($folderCol, $qb->createNamedParameter($folderId))
			->where($qb->expr()->eq(self::METADATA_PREFIX . 'uuid', $qb->createNamedParameter($uuid)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull($folderCol),
					$qb->expr()->eq($folderCol, $qb->createNamedParameter((string)$expected))
				)
			);

		return $qb->executeStatement() > 0;
	}//end recordFolderOf()

	/**
	 * Ultra-fast bulk save operation with automatic routing.
	 *
	 * Returns complete objects with database-computed classification (created/updated/unchanged).
	 *
	 * @param array $insertObjects Objects to insert/upsert
	 * @param array $updateObjects Objects to update (legacy parameter)
	 * @param Register|null $register Optional register context
	 * @param Schema|null $schema Optional schema context
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows (audit changeset or update
	 *                                  event). False narrows the fetch to the uuid.
	 *
	 * @return array Array of complete objects with object_status field
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	public function ultraFastBulkSave(
		array $insertObjects = [],
		array $updateObjects = [],
		?Register $register = null,
		?Schema $schema = null,
		bool $needsPreUpdateState = true,
	): array {
		$group = new MagicBulkSaveGroup(insertObjects: $insertObjects, needsPreUpdateState: $needsPreUpdateState);
		$group->register = $register;
		$group->schema   = $schema;
		return $this->ultraFastBulkSaveGroup(group: $group);
	}//end ultraFastBulkSave()

	/**
	 * Bulk save a batch of new objects, split per register+schema when no schema is given (see ultraFastBulkSave()).
	 *
	 * @param MagicBulkSaveGroup $group The objects, the pre-update-state flag and the register and schema, if known.
	 *
	 * @return array The saved rows.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function ultraFastBulkSaveGroup(MagicBulkSaveGroup $group): array {
		$insertObjects       = $group->insertObjects;
		$needsPreUpdateState = $group->needsPreUpdateState;
		$register            = $group->register;
		$schema              = $group->schema;

		$this->logger->debug(
			message: '[MagicMapper] ultraFastBulkSave called',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'insertCount' => count($insertObjects),
				// This path only inserts: update rows were never written, only counted here.
				'updateCount' => 0,
				'hasRegister' => $register !== null,
				'hasSchema' => $schema !== null,
			]
		);

		// MIXED SCHEMA SUPPORT: If schema is null and we have objects with different schemas,
		// group them by register+schema and process each group separately.
		if ($schema === null && count($insertObjects) > 0) {
			$schemaGroups = $this->groupBulkInsertsBySchema(insertObjects: $insertObjects, register: $register);

			if (count($schemaGroups) > 1) {
				$allResults = [];
				foreach ($schemaGroups as $groupKey => $groupObjects) {
					[$groupRegisterId, $groupSchemaId] = explode('_', $groupKey);

					$group = new MagicBulkSaveGroup(insertObjects: $groupObjects, needsPreUpdateState: $needsPreUpdateState);
					$group->register = $this->bulkGroupRegister(register: $register, registerId: $groupRegisterId);
					$group->schema   = $this->bulkGroupSchema(schemaId: $groupSchemaId);
					$groupResults    = $this->ultraFastBulkSaveSingleSchema(group: $group);

					$allResults = array_merge($allResults, $groupResults);
				}

				return $allResults;
			}//end if
		}//end if

		$group = new MagicBulkSaveGroup(insertObjects: $insertObjects, needsPreUpdateState: $needsPreUpdateState);
		$group->register = $register;
		$group->schema   = $schema;
		return $this->ultraFastBulkSaveSingleSchema(group: $group);
	}//end ultraFastBulkSaveGroup()

	/**
	 * Group bulk inserts by their register and schema; objects without a schema are left out.
	 *
	 * @param array         $insertObjects The objects to insert.
	 * @param Register|null $register      The register an object without its own falls back to.
	 *
	 * @return array<string, array> The objects per "registerId_schemaId" key, in first-seen order.
	 */
	private function groupBulkInsertsBySchema(array $insertObjects, ?Register $register): array {
		$schemaGroups = [];
		foreach ($insertObjects as $obj) {
			$objSchemaId = $obj['@self']['schema'] ?? null;
			$objRegisterId = $obj['@self']['register'] ?? ($register?->getId());
			if ($objSchemaId !== null) {
				$groupKey = "{$objRegisterId}_{$objSchemaId}";
				$schemaGroups[$groupKey][] = $obj;
			}
		}

		return $schemaGroups;
	}//end groupBulkInsertsBySchema()

	/**
	 * The register one schema group of a bulk save writes to.
	 *
	 * @param Register|null $register   The register given for the whole save, if any.
	 * @param string        $registerId The group's register id.
	 *
	 * @return Register|null The register, or null when it cannot be found.
	 */
	private function bulkGroupRegister(?Register $register, string $registerId): ?Register {
		if ($register !== null) {
			return $register;
		}

		try {
			return $this->registerMapper->find(id: (int)$registerId, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to resolve register for group',
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $registerId]
			);
			return null;
		}
	}//end bulkGroupRegister()

	/**
	 * The schema one schema group of a bulk save writes to.
	 *
	 * @param string $schemaId The group's schema id.
	 *
	 * @return Schema|null The schema, or null when it cannot be found.
	 */
	private function bulkGroupSchema(string $schemaId): ?Schema {
		try {
			return $this->schemaMapper->find(id: (int)$schemaId, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to resolve schema for group',
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $schemaId]
			);
			return null;
		}
	}//end bulkGroupSchema()

	/**
	 * Ultra-fast bulk save for a single schema (internal method).
	 *
	 * A register or schema the group lacks is read from the `@self` of its
	 * first object.
	 *
	 * @param MagicBulkSaveGroup $group The objects and their table context.
	 *
	 * @throws Exception When the register or schema cannot be resolved.
	 *
	 * @return array Array of complete objects with object_status field
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function ultraFastBulkSaveSingleSchema(MagicBulkSaveGroup $group): array {
		$self = ($group->insertObjects[0] ?? [])['@self'] ?? [];
		$group->register ??= $this->findBulkRegister(id: $self['register'] ?? null);
		$group->schema   ??= $this->findBulkSchema(id: $self['schema'] ?? null);

		$register = $group->register;
		$schema   = $group->schema;
		if ($register === null || $schema === null) {
			throw new Exception('Cannot bulk save without register and schema context');
		}

		$this->ensureTableForRegisterSchema(register: $register, schema: $schema);

		return $this->bulkUpsert(
			objects: $group->insertObjects,
			register: $register,
			schema: $schema,
			tableName: 'openregister_table_' . $register->getId() . '_' . $schema->getId(),
			needsPreUpdateState: $group->needsPreUpdateState
		);
	}//end ultraFastBulkSaveSingleSchema()

	/**
	 * The register a bulk save names in its first object, or null.
	 *
	 * @param mixed $id The register id from `@self`, or null.
	 *
	 * @return Register|null The register, or null when absent or not found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function findBulkRegister(mixed $id): ?Register {
		if ($id === null) {
			return null;
		}

		try {
			return $this->registerMapper->find(id: $id, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->warning(message: '[MagicMapper] Failed to resolve register', context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id]);
			return null;
		}
	}//end findBulkRegister()

	/**
	 * The schema a bulk save names in its first object, or null.
	 *
	 * @param mixed $id The schema id from `@self`, or null.
	 *
	 * @return Schema|null The schema, or null when absent or not found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function findBulkSchema(mixed $id): ?Schema {
		if ($id === null) {
			return null;
		}

		try {
			return $this->schemaMapper->find(id: $id, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->warning(message: '[MagicMapper] Failed to resolve schema', context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id]);
			return null;
		}
	}//end findBulkSchema()

	/**
	 * Delete multiple objects.
	 *
	 * @param array $uuids Object UUIDs to delete
	 * @param bool $hardDelete Whether to hard delete
	 *
	 * @return array Delete results
	 *
	 * @psalm-return list<mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
	 */
	public function deleteObjects(array $uuids = [], bool $hardDelete = false): array {
		$results = [];
		// Group UUIDs by register+schema for batch deletion.
		$grouped = [];
		foreach ($uuids as $uuid) {
			try {
				$result = $this->findAcrossAllSources(
					identifier: $uuid,
					_multitenancy: false,
					_rbac: false
				);
			} catch (\Exception $e) {
				// Skip UUIDs that can't be found.
				continue;
			}

			$this->assertDeletable(entity: $result['object']);
			$register = $result['register'];
			$schema = $result['schema'];
			$key = $register->getId() . '-' . $schema->getId();
			$grouped[$key] ??= ['register' => $register, 'schema' => $schema, 'uuids' => []];
			$grouped[$key]['uuids'][] = $uuid;
		}

		foreach ($grouped as $group) {
			$count = $this->deleteObjectsByUuids(
				register: $group['register'],
				schema: $group['schema'],
				uuids: $group['uuids'],
				hardDelete: $hardDelete
			);
			$results[] = ['count' => $count, 'uuids' => $group['uuids']];
		}

		return $results;
	}//end deleteObjects()

	/**
	 * Get statistics for objects across all magic tables.
	 *
	 * @param StatisticsScope $scope The registers and schemas to cover and the pairs to leave out; every table when omitted.
	 *
	 * @return int[] Statistics data
	 *
	 * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function getStatistics(StatisticsScope $scope=new StatisticsScope()): array {
		return $this->statisticsHandler->getStatistics(scope: $scope);
	}//end getStatistics()

	/**
	 * Get object statistics grouped by schema for multiple schemas.
	 *
	 * Returns per-schema statistics using one count query per register-schema pair,
	 * grouped by schema ID. Replaces N individual getStatistics() calls.
	 *
	 * @param int[] $schemaIds Array of schema IDs to get statistics for.
	 *
	 * @return array<int, array{total: int, size: int}> Map of schemaId => statistics array.
	 */
	public function getStatisticsGroupedBySchema(array $schemaIds): array {
		return $this->statisticsHandler->getStatisticsGroupedBySchema(schemaIds: $schemaIds);
	}//end getStatisticsGroupedBySchema()

	/**
	 * Get register chart data.
	 *
	 * @param int|null $registerId Register ID filter
	 * @param int|null $schemaId Schema ID filter
	 *
	 * @return (int|mixed|string)[][] Chart data
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 */
	public function getRegisterChartData(?int $registerId = null, ?int $schemaId = null): array {
		return $this->statisticsHandler->getRegisterChartData(
			registerId: $registerId,
			schemaId: $schemaId
		);
	}//end getRegisterChartData()

	/**
	 * Get schema chart data.
	 *
	 * @param int|null $registerId Register ID filter
	 * @param int|null $schemaId Schema ID filter
	 *
	 * @return (int|mixed|string)[][] Chart data
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 */
	public function getSchemaChartData(?int $registerId = null, ?int $schemaId = null): array {
		return $this->statisticsHandler->getSchemaChartData(
			registerId: $registerId,
			schemaId: $schemaId
		);
	}//end getSchemaChartData()

	/**
	 * Get simple facets.
	 *
	 * Routes to the correct faceting method based on query parameters.
	 *
	 * @param array $query Search query containing register, schema, and _facets configuration.
	 *
	 * @return array Facets data.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function getSimpleFacets(array $query = []): array {
		$registerId = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
		$schemaIds = $query['@self']['schemas'] ?? $query['_schemas'] ?? null;
		$schemaId = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;
		$registerIds = $query['@self']['registers'] ?? $query['_registers'] ?? null;

		// Multi-schema faceting.
		if ($schemaIds !== null && is_array($schemaIds) === true
			&& ($registerId !== null
			|| ($registerIds !== null
			&& is_array($registerIds) === true
			&& count($registerIds) > 0))
		) {
			$allRegisterIds = [(int)$registerId];
			if ($registerIds !== null && is_array($registerIds) === true && count($registerIds) > 0) {
				$allRegisterIds = array_map('intval', $registerIds);
			}

			$registerSchemaPairs = $this->facetRegisterSchemaPairs(
				registers: $this->facetRegisters(registerIds: $allRegisterIds),
				schemaIds: array_map('intval', $schemaIds)
			);
			if (empty($registerSchemaPairs) === true) {
				return [];
			}

			return $this->getSimpleFacetsUnion(query: $query, registerSchemaPairs: $registerSchemaPairs);
		}

		// Single schema faceting.
		if ($registerId !== null && $schemaId !== null) {
			try {
				$register = $this->registerMapper->find((int)$registerId, _multitenancy: false, _rbac: false);
				$schema = $this->schemaMapper->find((int)$schemaId, _multitenancy: false, _rbac: false);

				return $this->getSimpleFacetsFromRegisterSchemaTable(
					query: $query,
					register: $register,
					schema: $schema
				);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to resolve register/schema for facets',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
				return [];
			}
		}

		return [];
	}//end getSimpleFacets()

	/**
	 * The registers of a multi-schema facet request, keyed by id; a register that cannot be found is skipped.
	 *
	 * @param array $registerIds Array of register IDs to search.
	 *
	 * @return array<int, Register> The registers found.
	 */
	private function facetRegisters(array $registerIds): array {
		$registers = [];
		foreach ($registerIds as $regId) {
			try {
				$register = $this->registerMapper->find($regId, _multitenancy: false, _rbac: false);
				$registers[$register->getId()] = $register;
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to find register for multi-schema facets',
					context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $regId, 'error' => $e->getMessage()]
				);
			}
		}

		return $registers;
	}//end facetRegisters()

	/**
	 * Pair each schema of a multi-schema facet request with the register that holds it.
	 *
	 * @param array<int, Register> $registers The registers of the request, at least one for any pair.
	 * @param array                $schemaIds Array of schema IDs to aggregate.
	 *
	 * @return list<array{register: Register, schema: Schema}> The pairs; a schema that cannot be found is skipped.
	 */
	private function facetRegisterSchemaPairs(array $registers, array $schemaIds): array {
		if (empty($registers) === true) {
			return [];
		}

		$registerSchemaPairs = [];
		foreach ($schemaIds as $sId) {
			try {
				$schema = $this->schemaMapper->find($sId, _multitenancy: false, _rbac: false);
				$registerSchemaPairs[] = ['register' => $this->registerHoldingSchema(registers: $registers, schemaId: $sId), 'schema' => $schema];
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to find schema for multi-schema facets',
					context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $sId, 'error' => $e->getMessage()]
				);
			}
		}

		return $registerSchemaPairs;
	}//end facetRegisterSchemaPairs()

	/**
	 * The first register that lists the schema (as a value or a key), else the first register.
	 *
	 * @param array<int, Register> $registers The registers, at least one.
	 * @param int                  $schemaId  The schema id.
	 *
	 * @return Register The register.
	 */
	private function registerHoldingSchema(array $registers, int $schemaId): Register {
		foreach ($registers as $register) {
			$registerSchemas = $register->getSchemas() ?? [];
			if (is_array($registerSchemas) === true) {
				$schemaIdStr = (string)$schemaId;
				$inValues = in_array($schemaId, $registerSchemas, false)
					|| in_array($schemaIdStr, $registerSchemas, false);
				$inKeys = array_key_exists($schemaId, $registerSchemas)
					|| array_key_exists($schemaIdStr, $registerSchemas);
				if ($inValues === true || $inKeys === true) {
					return $register;
				}
			}
		}

		return reset($registers);
	}//end registerHoldingSchema()

	/**
	 * Get facetable fields from schemas.
	 *
	 * Collects facetable fields from all schemas referenced in the query.
	 *
	 * @param array $baseQuery Base query
	 *
	 * @return array[] Facetable fields
	 *
	 * @psalm-return array<string, array>
	 */
	public function getFacetableFieldsFromSchemas(array $baseQuery = []): array {
		$facetableFields = [];
		$schemaIds = $baseQuery['@self']['schemas'] ?? $baseQuery['_schemas'] ?? null;
		$schemaId = $baseQuery['@self']['schema'] ?? $baseQuery['_schema'] ?? $baseQuery['schema'] ?? null;

		if ($schemaId !== null && $schemaIds === null) {
			$schemaIds = [$schemaId];
		}

		if ($schemaIds === null || is_array($schemaIds) === false) {
			return $facetableFields;
		}

		foreach ($schemaIds as $sId) {
			try {
				$schema = $this->schemaMapper->find((int)$sId, _multitenancy: false, _rbac: false);
				$properties = $schema->getProperties();
				if (is_string($properties) === true) {
					$properties = json_decode($properties, true) ?? [];
				}

				if (is_array($properties) === false) {
					continue;
				}

				foreach ($properties as $propName => $propConfig) {
					if (isset($facetableFields[$propName]) === false) {
						$facetableFields[$propName] = $propConfig;
					}
				}
			} catch (\Exception $e) {
				// Skip missing schemas.
				$this->logger->debug(
					message: '[MagicMapper] Skipping facetable fields of a schema that could not be loaded',
					context: [
						'schemaId' => $sId,
						'exception' => $e->getMessage(),
					]
				);
			}
		}//end foreach

		return $facetableFields;
	}//end getFacetableFieldsFromSchemas()

	/**
	 * Whether the query names more registers or schemas than the single-table
	 * path can answer for.
	 *
	 * Schemas decide it: the UNION path resolves one table per schema, so a
	 * register set with no schema set has nothing to union and is left to the
	 * caller's own fail-closed branch rather than guessed at.
	 *
	 * @param mixed $registerRaw The register bound exactly as the query carries it.
	 * @param mixed $schemaRaw The schema bound exactly as the query carries it.
	 *
	 * @return bool True when the search must go through the multi-schema path.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function namesSeveral(mixed $registerRaw, mixed $schemaRaw): bool {
		$schemaIds = $this->identifierList(value: $schemaRaw);
		if (count($schemaIds) === 0) {
			return false;
		}

		return (count($schemaIds) > 1 || count($this->identifierList(value: $registerRaw)) > 1);
	}//end namesSeveral()

	/**
	 * The numeric ids a register or schema bound names.
	 *
	 * @param mixed $value A single id, a list of them, or null.
	 *
	 * @return array<int, int> The de-duplicated numeric ids, list-indexed.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function identifierList(mixed $value): array {
		if ($value === null) {
			return [];
		}

		$candidates = [$value];
		if (is_array($value) === true) {
			$candidates = $value;
		}

		$ids = [];
		foreach ($candidates as $candidate) {
			if (is_int($candidate) === true
				|| (is_string($candidate) === true && ctype_digit($candidate) === true)
			) {
				$ids[(int)$candidate] = true;
			}
		}

		return array_keys($ids);
	}//end identifierList()

	/**
	 * The one identifier a bound names, or null when it names none or several.
	 *
	 * Returning the value as stored rather than as an int is deliberate: the
	 * mappers resolve an id, a uuid or a slug, and only a caller that already
	 * knows the bound is numeric may narrow it.
	 *
	 * @param mixed $value A single identifier, a list holding exactly one, or null.
	 *
	 * @return string|int|null The sole identifier, or null when there is not exactly one.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function soleIdentifier(mixed $value): string|int|null {
		if (is_array($value) === false) {
			if (is_string($value) === true || is_int($value) === true) {
				return $value;
			}

			return null;
		}

		$values = array_values($value);
		if (count($values) !== 1) {
			return null;
		}

		if (is_string($values[0]) === true || is_int($values[0]) === true) {
			return $values[0];
		}

		return null;
	}//end soleIdentifier()

	/**
	 * Search objects.
	 *
	 * @param array $query Search query
	 * @param string|null $_activeOrgUuid Organisation UUID
	 * @param bool $_rbac Apply RBAC
	 * @param bool $_multitenancy Apply multitenancy
	 * @param array|null $ids Specific IDs
	 * @param string|null $uses Uses filter
	 *
	 * @return list<ObjectEntity>|int
	 *
	 * @psalm-suppress                              LessSpecificImplementedReturnType
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-export-of-selected-ids-holds-only-those-objects
	 */
	public function searchObjects(
		array $query = [],
		?string $_activeOrgUuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
	): array|int {
		return $this->searchBy(search: new MagicSearchRequest(query: $query, ids: $ids, uses: $uses), _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end searchObjects()

	/**
	 * Search objects of the register(s) and schema(s) the query names (see searchObjects()).
	 *
	 * @param MagicSearchRequest $search        The search parameters and the ids and relation to narrow to.
	 * @param bool               $_rbac         Whether to apply RBAC checks.
	 * @param bool               $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return array|int The objects.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function searchBy(MagicSearchRequest $search, bool $_rbac=true, bool $_multitenancy=true): array|int {
		$query = $search->query;
		$ids   = $search->ids;
		$uses  = $search->uses;

		$query = $this->narrowedToIds(query: $query, ids: $ids);
		if ($this->selectsNothing(query: $query, ids: $ids) === true) {
			return [];
		}

		$query = $this->narrowedToUses(query: $query, uses: $uses);

		$registerRaw = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
		$schemaRaw = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;

		// A view-scoped search reaches this entry point with `@self.register` and
		// `@self.schema` as ARRAYS - ViewScopeApplier merges a view's registers
		// and schemas into lists. `(int)` on a non-empty array is 1, silently and
		// without a warning, so such a read targeted register 1 / schema 1 rather
		// than the ones the view names. On an anonymous access link the view is
		// the ONLY bound left on the query, which made that somebody else's data.
		// searchObjectsPaginated() has branched on the array form since the
		// unified-search work; this entry point never did.
		if ($this->namesSeveral(registerRaw: $registerRaw, schemaRaw: $schemaRaw) === true) {
			$result = $this->searchObjectsPaginatedMultiSchema(
				search: (new MultiSchemaSearch(
					searchQuery: $query,
					countQuery: $query,
					registerIds: $this->identifierList(value: $registerRaw),
					schemaIds: $this->identifierList(value: $schemaRaw)
				))->securedBy(rbac: $_rbac, multitenancy: $_multitenancy)
			);

			return ($result['results'] ?? []);
		}

		$registerId = $this->soleIdentifier(value: $registerRaw);
		$schemaId = $this->soleIdentifier(value: $schemaRaw);

		if ($registerId !== null && $schemaId !== null) {
			try {
				// Passed through as given, NOT cast: find() resolves an id, a uuid
				// or a slug, and `(int)` turned every non-numeric identifier into 0.
				$register = $this->registerMapper->find($registerId, _multitenancy: false, _rbac: false);
				$schema = $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false);

				$query['_rbac'] = $_rbac;
				$query['_multitenancy'] = $_multitenancy;
				return $this->searchObjectsInRegisterSchemaTable(
					query: $query,
					register: $register,
					schema: $schema
				);
			} catch (\OCA\OpenRegister\Exception\EncryptedFieldFilterException $e) {
				// Fail loud (design intent): a filter on an encrypted property must
				// reach the caller as a clear error, never a swallowed warning that
				// degrades into an empty/zero result.
				throw $e;
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to resolve register/schema for search',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
			}//end try
		}//end if

		$this->logger->warning(
			message: '[MagicMapper] searchObjects() called without register/schema context',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return [];
	}//end searchBy()

	/**
	 * The query narrowed to the given ids, within any `_ids` it already carries.
	 *
	 * @param array      $query The search query.
	 * @param array|null $ids   The ids to keep, or null for no narrowing.
	 *
	 * @return array The query.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-export-of-selected-ids-holds-only-those-objects
	 */
	private function narrowedToIds(array $query, ?array $ids): array {
		if ($ids === null) {
			return $query;
		}

		$given = ($query['_ids'] ?? null);
		if (is_array($given) === true) {
			$ids = array_intersect($given, $ids);
		}

		$query['_ids'] = array_values($ids);

		return $query;
	}//end narrowedToIds()

	/**
	 * Whether a given id list left nothing to select.
	 *
	 * @param array      $query The narrowed search query.
	 * @param array|null $ids   The ids the caller gave, or null for none.
	 *
	 * @return bool True when no row can match.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-export-of-selected-ids-holds-only-those-objects
	 */
	private function selectsNothing(array $query, ?array $ids): bool {
		return ($ids !== null && $query['_ids'] === []);
	}//end selectsNothing()

	/**
	 * The query narrowed to the objects that use the given object.
	 *
	 * A relation the query already asks for stays: the objects have to use both.
	 *
	 * @param array       $query The search query.
	 * @param string|null $uses  The object they have to use, or null or empty for no narrowing.
	 *
	 * @return array The query.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function narrowedToUses(array $query, ?string $uses): array {
		if ($uses === null || $uses === '') {
			return $query;
		}

		$relations = array_unique([...(array)($query['_relations_contains'] ?? []), $uses]);
		$query['_relations_contains'] = $uses;
		if (count($relations) > 1) {
			$query['_relations_contains'] = array_values($relations);
		}

		return $query;
	}//end narrowedToUses()

	/**
	 * Count search objects.
	 *
	 * @param array $query Search query
	 * @param string|null $_activeOrgUuid Organisation UUID
	 * @param bool $_rbac Apply RBAC
	 * @param bool $_multitenancy Apply multitenancy
	 * @param array|null $ids Specific IDs
	 * @param string|null $uses Uses filter
	 *
	 * @return int Object count
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-export-of-selected-ids-holds-only-those-objects
	 */
	public function countSearchObjects(
		array $query = [],
		?string $_activeOrgUuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
	): int {
		return $this->countBy(search: new MagicSearchRequest(query: $query, ids: $ids, uses: $uses), _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end countSearchObjects()

	/**
	 * Count the objects a search matches (see countSearchObjects()).
	 *
	 * @param MagicSearchRequest $search        The search parameters and the ids and relation to narrow to.
	 * @param bool               $_rbac         Whether to apply RBAC checks.
	 * @param bool               $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return int The count.
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function countBy(MagicSearchRequest $search, bool $_rbac=true, bool $_multitenancy=true): int {
		$query = $search->query;
		$ids   = $search->ids;
		$uses  = $search->uses;

		$query = $this->narrowedToIds(query: $query, ids: $ids);
		if ($this->selectsNothing(query: $query, ids: $ids) === true) {
			return 0;
		}

		$query = $this->narrowedToUses(query: $query, uses: $uses);

		$registerRaw = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
		$schemaRaw = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;

		// Same array collapse as searchObjects(): a count that silently answered
		// for register 1 is the same defect wearing a different return type.
		if ($this->namesSeveral(registerRaw: $registerRaw, schemaRaw: $schemaRaw) === true) {
			$result = $this->searchObjectsPaginatedMultiSchema(
				search: (new MultiSchemaSearch(
					searchQuery: $query,
					countQuery: $query,
					registerIds: $this->identifierList(value: $registerRaw),
					schemaIds: $this->identifierList(value: $schemaRaw)
				))->securedBy(rbac: $_rbac, multitenancy: $_multitenancy)
			);

			return (int)($result['total'] ?? 0);
		}

		$registerId = $this->soleIdentifier(value: $registerRaw);
		$schemaId = $this->soleIdentifier(value: $schemaRaw);

		if ($registerId !== null && $schemaId !== null) {
			try {
				$register = $this->registerMapper->find($registerId, _multitenancy: false, _rbac: false);
				$schema = $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false);

				return $this->countObjectsInRegisterSchemaTable(
					query: $query,
					register: $register,
					schema: $schema
				);
			} catch (\OCA\OpenRegister\Exception\EncryptedFieldFilterException $e) {
				// Fail loud (design intent): never degrade a filter on an encrypted
				// property to a silent count of 0.
				throw $e;
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to resolve register/schema for count',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
			}
		}//end if

		return 0;
	}//end countBy()

	/**
	 * Count all objects with optional filtering.
	 *
	 * @param array|null $_filters Filters
	 * @param Schema|null $schema Schema filter
	 * @param Register|null $register Register filter
	 *
	 * @return int Object count
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function countAll(?array $_filters = null, ?Schema $schema = null, ?Register $register = null): int {
		// If register+schema context provided, count in the specific table.
		if ($register !== null && $schema !== null) {
			return $this->countObjectsInRegisterSchemaTable(
				query: $_filters ?? [],
				register: $register,
				schema: $schema
			);
		}

		return $this->countInPairs(pairs: $this->pairsInScope(register: $register, schema: $schema), filters: ($_filters ?? []));
	}//end countAll()

	/**
	 * The register+schema pairs of every magic table, narrowed to a register and a schema when given.
	 *
	 * @param Register|null $register Register filter.
	 * @param Schema|null   $schema   Schema filter.
	 *
	 * @return array<int, array{registerId: int|string, schemaId: int|string}> The pairs.
	 */
	private function pairsInScope(?Register $register, ?Schema $schema): array {
		$pairs = [];
		foreach ($this->getAllRegisterSchemaPairs() as $pair) {
			if ($register !== null && (int)$pair['registerId'] !== $register->getId()) {
				continue;
			}

			if ($schema !== null && (int)$pair['schemaId'] !== $schema->getId()) {
				continue;
			}

			$pairs[] = $pair;
		}

		return $pairs;
	}//end pairsInScope()

	/**
	 * The sum of the counts in each pair's table; a table that cannot be counted is skipped.
	 *
	 * @param array<int, array{registerId: int|string, schemaId: int|string}> $pairs   The pairs.
	 * @param array                                                           $filters The filters.
	 *
	 * @return int The total.
	 */
	private function countInPairs(array $pairs, array $filters): int {
		$total = 0;
		foreach ($pairs as $pair) {
			try {
				$pairRegister = $this->registerMapper->find($pair['registerId'], _multitenancy: false, _rbac: false);
				$pairSchema = $this->schemaMapper->find($pair['schemaId'], _multitenancy: false, _rbac: false);

				$total += $this->countObjectsInRegisterSchemaTable(query: $filters, register: $pairRegister, schema: $pairSchema);
			} catch (\Exception $e) {
				// Skip.
			}
		}

		return $total;
	}//end countInPairs()

	/**
	 * Get query builder instance.
	 *
	 * @return IQueryBuilder Query builder instance
	 */
	public function getQueryBuilder(): IQueryBuilder {
		return $this->db->getQueryBuilder();
	}//end getQueryBuilder()

	/**
	 * Get max allowed packet size.
	 *
	 * @return int Max packet size
	 */
	public function getMaxAllowedPacketSize(): int {
		// `SHOW VARIABLES` is a MySQL-ism. On PostgreSQL the statement is a
		// syntax error — and although the exception is swallowed here, a
		// failed statement inside an OPEN TRANSACTION aborts the whole
		// transaction on postgres (SQLSTATE 25P02: "current transaction is
		// aborted"), breaking any caller that wraps a save in a transaction
		// (e.g. the ADR-051 handoff engine). Only probe on MySQL/MariaDB.
		if ($this->db->getDatabaseProvider() !== \OCP\IDBConnection::PLATFORM_MYSQL) {
			return 16777216;
		}

		try {
			$result = $this->db->executeQuery("SHOW VARIABLES LIKE 'max_allowed_packet'");
			$row = $result->fetch();
			if ($row !== false && isset($row['Value']) === true) {
				return (int)$row['Value'];
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to get max_allowed_packet',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		// Default to 16MB.
		return 16777216;
	}//end getMaxAllowedPacketSize()

	/**
	 * Optimized paginated search that loads register/schema once and performs both search and count.
	 *
	 * @param array $searchQuery Query for search (with _limit, _offset).
	 * @param array $countQuery Query for count (without pagination).
	 * @param string|null $_activeOrgUuid Active organization UUID.
	 * @param bool $_rbac Whether to apply RBAC.
	 * @param bool $_multitenancy Whether to apply multitenancy.
	 * @param array|null $ids Optional ID filter.
	 * @param string|null $uses Optional uses filter.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
	 *
	 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
	 */
	public function searchObjectsPaginated(
		array $searchQuery = [],
		array $countQuery = [],
		?string $_activeOrgUuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
	): array {
		$search = new MagicPaginatedSearch(searchQuery: $searchQuery, countQuery: $countQuery, ids: $ids, uses: $uses);
		return $this->searchPaginatedBy(search: $search, _rbac: $_rbac, _multitenancy: $_multitenancy, _activeOrgUuid: $_activeOrgUuid);
	}//end searchObjectsPaginated()

	/**
	 * Paginated search that loads register/schema once and performs both search and count (see searchObjectsPaginated()).
	 *
	 * @param MagicPaginatedSearch $search         The page query, the count query and the ids and relation to narrow to.
	 * @param bool                 $_rbac          Whether to apply RBAC checks.
	 * @param bool                 $_multitenancy  Whether to apply multitenancy filtering.
	 * @param string|null          $_activeOrgUuid Active organization UUID.
	 *
	 * @return array<string, mixed>
	 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
	 */
	public function searchPaginatedBy(MagicPaginatedSearch $search, bool $_rbac=true, bool $_multitenancy=true, ?string $_activeOrgUuid=null): array {
		$searchQuery = $search->searchQuery;
		$countQuery  = $search->countQuery;
		$ids         = $search->ids;
		$uses        = $search->uses;

		$searchQuery = $this->narrowedToIds(query: $searchQuery, ids: $ids);
		if ($this->selectsNothing(query: $searchQuery, ids: $ids) === true) {
			return ['results' => [], 'total' => 0, 'registers' => [], 'schemas' => []];
		}

		$request = [
			'search'        => $this->narrowedToUses(query: $searchQuery, uses: $uses),
			'count'         => $this->narrowedToUses(query: $this->narrowedToIds(query: $countQuery, ids: $ids), uses: $uses),
			'activeOrgUuid' => $_activeOrgUuid,
			'rbac'          => $_rbac,
			'multitenancy'  => $_multitenancy,
			'ids'           => $ids,
			'uses'          => $uses,
		];
		$scope   = $this->paginatedSearchScope(searchQuery: $searchQuery);

		return ($this->searchMultiSchema(request: $request, scope: $scope)
			?? $this->searchSingleSchema(request: $request, scope: $scope)
			?? $this->searchUnresolvedScope(request: $request, scope: $scope));
	}//end searchPaginatedBy()

	/**
	 * A search that resolved no register and schema: given ids or `uses` narrow
	 * a search of those, so alone they select nothing.
	 *
	 * @param array $request The search request: queries and flags
	 * @param array $scope   The search scope
	 *
	 * @return array<string, mixed>
	 */
	private function searchUnresolvedScope(array $request, array $scope): array {
		if ($request['ids'] !== null || ($request['uses'] ?? '') !== '') {
			return ['results' => [], 'total' => 0, 'registers' => [], 'schemas' => []];
		}

		return $this->searchWithoutSchema(request: $request, scope: $scope);
	}//end searchUnresolvedScope()

	/**
	 * A multi-schema search, or null when the scope does not name a set of schemas.
	 *
	 * Fires whenever a non-empty set of schema ids is given, even with NO register
	 * filter. The unified-search provider passes a searchable-schema set and no
	 * register; previously that fell through to the (empty) central table and returned
	 * nothing. Without a register filter the register list is left empty and
	 * searchObjectsPaginatedMultiSchema() resolves each schema's owning register.
	 *
	 * @param array $request The search request: queries and flags
	 * @param array $scope   The search scope
	 *
	 * @return array<string, mixed>|null
	 */
	private function searchMultiSchema(array $request, array $scope): ?array {
		if ($scope['schemaId'] !== null || is_array($scope['schemaIds']) === false || count($scope['schemaIds']) === 0) {
			return null;
		}

		return $this->searchObjectsPaginatedMultiSchema(
			search: (new MultiSchemaSearch(
				searchQuery: $request['search'],
				countQuery: $request['count'],
				registerIds: $this->multiSchemaRegisterIds(scope: $scope),
				schemaIds: $scope['schemaIds']
			))->securedBy(rbac: $request['rbac'], multitenancy: $request['multitenancy'])
		);
	}//end searchMultiSchema()

	/**
	 * The register and schema filters of a paginated search, from `@self`, `_` or plain keys.
	 *
	 * A schema given as a list in `@self.schema` is a multi-schema search.
	 *
	 * @param array $searchQuery The search query
	 *
	 * @return array{registerId: mixed, schemaId: mixed, schemaIds: mixed, registerIds: mixed}
	 */
	private function paginatedSearchScope(array $searchQuery): array {
		$schemaId  = $searchQuery['@self']['schema'] ?? $searchQuery['_schema'] ?? $searchQuery['schema'] ?? null;
		$schemaIds = $searchQuery['@self']['schemas'] ?? $searchQuery['_schemas'] ?? null;
		if (is_array($schemaId) === true && count($schemaId) > 0) {
			$schemaIds = $schemaId;
			$schemaId  = null;
		}

		return [
			'registerId'  => ($searchQuery['@self']['register'] ?? $searchQuery['_register'] ?? $searchQuery['register'] ?? null),
			'schemaId'    => $schemaId,
			'schemaIds'   => $schemaIds,
			'registerIds' => ($searchQuery['@self']['registers'] ?? $searchQuery['_registers'] ?? null),
		];
	}//end paginatedSearchScope()

	/**
	 * The registers a multi-schema search is limited to: the register list, else the
	 * single register, else none (each schema's owning register is then looked up).
	 *
	 * @param array $scope The search scope
	 *
	 * @return int[]
	 */
	private function multiSchemaRegisterIds(array $scope): array {
		if (is_array($scope['registerIds']) === true && count($scope['registerIds']) > 0) {
			return array_map('intval', $scope['registerIds']);
		}

		if ($scope['registerId'] !== null) {
			return [(int)$scope['registerId']];
		}

		return [];
	}//end multiSchemaRegisterIds()

	/**
	 * Search and count one register and schema table, or null when the scope does not name both.
	 *
	 * @param array $request The search request: queries and flags
	 * @param array $scope   The search scope
	 *
	 * @return array<string, mixed>|null
	 */
	private function searchSingleSchema(array $request, array $scope): ?array {
		if ($scope['registerId'] === null || $scope['schemaId'] === null) {
			return null;
		}

		try {
			$register = $this->registerMapper->find((int)$scope['registerId'], _multitenancy: false, _rbac: false);
			$schema   = $this->schemaMapper->find((int)$scope['schemaId'], _multitenancy: false, _rbac: false);
			$pair     = [
				'register'  => $register,
				'schema'    => $schema,
				'registers' => [$register->getId() => $register->jsonSerialize()],
				'schemas'   => [$schema->getId() => $schema->jsonSerialize()],
			];
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to resolve register/schema',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}

		return $this->searchRegisterSchemaPage(request: $request, pair: $pair);
	}//end searchSingleSchema()

	/**
	 * One page of a register and schema table with its total, both timed.
	 *
	 * @param array $request The search request: queries and flags
	 * @param array $pair    The register and schema, and both serialised for the response
	 *
	 * @return array<string, mixed>
	 */
	private function searchRegisterSchemaPage(array $request, array $pair): array {
		$flags = ['_rbac' => $request['rbac'], '_multitenancy' => $request['multitenancy']];

		$searchStart = microtime(true);
		$results     = $this->searchObjectsInRegisterSchemaTable(
			query: array_replace($request['search'], $flags),
			register: $pair['register'],
			schema: $pair['schema']
		);
		$searchTime  = round((microtime(true) - $searchStart) * 1000, 2);

		$countStart = microtime(true);
		$total      = $this->countObjectsInRegisterSchemaTable(
			query: array_replace($request['count'], $flags),
			register: $pair['register'],
			schema: $pair['schema']
		);
		$countTime  = round((microtime(true) - $countStart) * 1000, 2);

		return [
			'results' => $results,
			'total' => $total,
			'registers' => $pair['registers'],
			'schemas' => $pair['schemas'],
			'ignoredFilters' => $this->getIgnoredFilters(),
			'metrics' => [
				'search_ms' => $searchTime,
				'count_ms' => $countTime,
			],
		];
	}//end searchRegisterSchemaPage()

	/**
	 * A search without a resolved register and schema: by ids, by relation, or by text
	 * across every table; an empty answer and a warning otherwise.
	 *
	 * @param array $request The search request: queries and flags
	 * @param array $scope   The search scope
	 *
	 * @return array<string, mixed>
	 */
	private function searchWithoutSchema(array $request, array $scope): array {
		$searchQuery = $request['search'];
		$queryIds    = $searchQuery['_ids'] ?? null;
		if (is_array($queryIds) === true && count($queryIds) > 0) {
			$pairs = $this->tablesOfRows(rows: $this->findMultipleAcrossAllMagicTables(uuids: $queryIds, includeDeleted: false));
			return $this->searchTables(pairs: $this->purposeReadableTables(pairs: $pairs, request: $request), request: $request);
		}

		$unscoped = ($scope['registerId'] === null && $scope['schemaId'] === null);

		$relationsContains = $searchQuery['_relations_contains'] ?? null;
		if ($unscoped === true && is_string($relationsContains) === true && empty($relationsContains) === false) {
			$relResults = $this->findByRelationAcrossAllMagicTables(uuid: $relationsContains, includeDeleted: false);
			return $this->searchTables(pairs: $this->purposeBoundTables()->readable(pairs: $this->tablesOfRows(rows: $relResults)), request: $request);
		}

		$searchTerm = $searchQuery['_search'] ?? null;
		if ($unscoped === true && is_string($searchTerm) === true && trim($searchTerm) !== '') {
			return $this->searchObjectsGloballyBySearch(request: $request);
		}

		$this->logger->warning(
			message: '[MagicMapper] searchObjectsPaginated() called without register/schema context',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return ['results' => [], 'total' => 0, 'registers' => [], 'schemas' => []];
	}//end searchWithoutSchema()

	/**
	 * Extract integer schema ids from a register's `schemas` membership list.
	 *
	 * The list may hold ids by value or by key, as ints or numeric strings;
	 * this normalises all forms to a flat list of distinct integer ids.
	 *
	 * @param array $registerSchemas The register's getSchemas()/decoded schemas array.
	 *
	 * @return int[] Distinct integer schema ids.
	 */
	private function extractSchemaIds(array $registerSchemas): array {
		// A plain list (`[4306, 4307]`) carries ids by VALUE; its integer keys
		// are positional, not ids. An id-keyed map (`{4310: "Pet"}`) carries
		// ids by KEY. Only consider keys for the map shape so a list of
		// non-numeric values can never inject positional indices as schema ids.
		$isList = array_is_list($registerSchemas);
		$ids = [];
		foreach ($registerSchemas as $schemaKey => $schemaValue) {
			$candidates = [$schemaKey, $schemaValue];
			if ($isList === true) {
				$candidates = [$schemaValue];
			}

			foreach ($candidates as $candidate) {
				if (is_int($candidate) === true
					|| (is_string($candidate) === true && ctype_digit($candidate) === true)
				) {
					$ids[(int)$candidate] = true;
				}
			}
		}

		return array_keys($ids);
	}//end extractSchemaIds()

	/**
	 * Resolve which register owns each schema, and load those registers.
	 *
	 * A schema belongs to exactly one register, and the magic table is named
	 * after the pair. Pairing a schema with the wrong register asks a table
	 * that does not exist, which is what made cross-schema search answer
	 * nothing. A schema whose owning register cannot be resolved is left out of
	 * the map and skipped (logged) by the caller rather than guessed at.
	 *
	 * @param array $registerIds The register filter, or an empty array when the
	 *                           query names schemas only (unified search).
	 *
	 * @return array{registers: array, registersCache: array, schemaToRegisterId: array<int, int>}
	 *
	 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
	 */
	private function resolveSchemaOwnership(array $registerIds): array {
		$registers = [];
		$registersCache = [];
		$schemaToRegisterId = [];

		if (empty($registerIds) === false) {
			foreach ($registerIds as $regId) {
				try {
					$register = $this->registerMapper->find($regId, _multitenancy: false, _rbac: false);
					$registers[$register->getId()] = $register;
					$registersCache[$register->getId()] = $register->jsonSerialize();
					$registerSchemas = ($register->getSchemas() ?? []);
					if (is_array($registerSchemas) === true) {
						foreach ($this->extractSchemaIds(registerSchemas: $registerSchemas) as $sid) {
							if (isset($schemaToRegisterId[$sid]) === false) {
								$schemaToRegisterId[$sid] = $register->getId();
							}
						}
					}
				} catch (\Exception $e) {
					$this->logger->warning(
						message: '[MagicMapper] Failed to find register for multi-schema search',
						context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $regId, 'error' => $e->getMessage()]
					);
				}
			}
		} else {
			try {
				$rqb = $this->db->getQueryBuilder();
				$rqb->select('id', 'schemas')->from('openregister_registers');
				$res = $rqb->executeQuery();
				while (($row = $res->fetch()) !== false) {
					$regId = (int)$row['id'];
					$schemas = json_decode((string)($row['schemas'] ?? '[]'), true);
					if (is_array($schemas) === false) {
						continue;
					}

					foreach ($this->extractSchemaIds(registerSchemas: $schemas) as $sid) {
						if (isset($schemaToRegisterId[$sid]) === false) {
							$schemaToRegisterId[$sid] = $regId;
						}
					}
				}

				$res->closeCursor();
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to build schema->register map for multi-schema search',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
			}//end try
		}//end if
		return [
			'registers' => $registers,
			'registersCache' => $registersCache,
			'schemaToRegisterId' => $schemaToRegisterId,
		];
	}//end resolveSchemaOwnership()

	/**
	 * Search objects across multiple schemas using UNION queries.
	 *
	 * Each searched schema is paired with its REAL owning register, so the right
	 * magic table is targeted; a schema with no owning register is skipped (logged)
	 * rather than forced onto an unrelated register.
	 *
	 * @param MultiSchemaSearch $search The queries, scope and flags of the search.
	 *
	 * @return array{results: ObjectEntity[], total: int, registers: array, schemas: array}
	 *
	 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
	 */
	private function searchObjectsPaginatedMultiSchema(MultiSchemaSearch $search): array {
		// When no register filter is given (unified search passes a searchable-schema
		// set only) the register->schema membership is read with a DIRECT query, NOT
		// registerMapper::findAll: findAll applies an organisation filter that can
		// hide most schemas' owning registers and make cross-schema search return nothing.
		$ownership = $this->resolveSchemaOwnership(registerIds: $search->registerIds);
		$search->registers          = $ownership['registers'];
		$search->registersCache     = $ownership['registersCache'];
		$search->schemaToRegisterId = $ownership['schemaToRegisterId'];

		if (empty($search->schemaToRegisterId) === true) {
			return [
				'results' => [],
				'total' => 0,
				'registers' => [],
				'schemas' => [],
			];
		}

		foreach ($search->schemaIds as $schemaId) {
			$this->addSchemaToMultiSchemaSearch(search: $search, schemaId: $schemaId);
		}

		$results = [];
		if (empty($search->pairs) === false) {
			$results = $this->searchAcrossMultipleTables(
				query: $search->withFlags(query: $search->searchQuery),
				registerSchemaPairs: $search->pairs
			);
		}

		return [
			'results' => $results,
			'total' => $search->total,
			'registers' => $search->registersCache,
			'schemas' => $search->schemasCache,
			'ignoredFilters' => [],
			'source' => 'magic_mapper',
		];
	}//end searchObjectsPaginatedMultiSchema()

	/**
	 * Add one schema's table to a multi-schema search and count its matches.
	 *
	 * @param MultiSchemaSearch $search   The search.
	 * @param mixed             $schemaId The schema id as the caller gave it.
	 *
	 * @throws \OCA\OpenRegister\Exception\EncryptedFieldFilterException When the count filters on an encrypted field.
	 *
	 * @return void
	 */
	private function addSchemaToMultiSchemaSearch(MultiSchemaSearch $search, mixed $schemaId): void {
		$schemaIdInt = (int)$schemaId;
		$register    = $this->owningRegisterForSchema(search: $search, schemaId: $schemaIdInt);
		if ($register === null) {
			$this->logger->debug(
				message: '[MagicMapper] Skipping schema with no resolvable owning register',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaIdInt]
			);
			return;
		}

		try {
			$schema = $this->schemaMapper->find($schemaIdInt, _multitenancy: false, _rbac: false);
			$search->schemasCache[$schema->getId()] = $schema->jsonSerialize();
			$search->pairs[] = ['register' => $register, 'schema' => $schema];

			// Scope the per-table count to THIS schema: re-applying a large `@self.schema`
			// allow-list as an IN() on each single-table count is redundant and trips
			// the database's 1000-element IN-list cap.
			$countQuery = $search->withFlags(query: $search->countQuery);
			$countQuery['@self']['schema'] = $schema->getId();
			$search->total += $this->countObjectsInRegisterSchemaTable(
				query: $countQuery,
				register: $register,
				schema: $schema
			);
		} catch (\OCA\OpenRegister\Exception\EncryptedFieldFilterException $e) {
			// Fail loud: never silently drop a schema from the total because its
			// count hit an encrypted-field filter.
			throw $e;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to load schema for multi-schema search',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId, 'error' => $e->getMessage()]
			);
		}//end try
	}//end addSchemaToMultiSchemaSearch()

	/**
	 * The owning register of a schema, loaded on first use.
	 *
	 * The lookup runs with _multitenancy:false so it resolves regardless of the active
	 * organisation; a register that fails to load is logged and answers null.
	 *
	 * @param MultiSchemaSearch $search   The search.
	 * @param int               $schemaId The schema id.
	 *
	 * @return Register|null The owning register, or null when it cannot be resolved.
	 */
	private function owningRegisterForSchema(MultiSchemaSearch $search, int $schemaId): ?Register {
		$registerId = ($search->schemaToRegisterId[$schemaId] ?? null);
		if ($registerId === null) {
			return null;
		}

		if (isset($search->registers[$registerId]) === false) {
			try {
				$register = $this->registerMapper->find($registerId, _multitenancy: false, _rbac: false);
				$search->registers[$register->getId()]      = $register;
				$search->registersCache[$register->getId()] = $register->jsonSerialize();
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[MagicMapper] Failed to load owning register for multi-schema search',
					context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $registerId, 'error' => $e->getMessage()]
				);
			}
		}

		return ($search->registers[$registerId] ?? null);
	}//end owningRegisterForSchema()

	/**
	 * The search, run on the tables that hold the rows a cross-table scan found.
	 *
	 * A cross-table scan applies no read rules, so its rows only name the
	 * tables; the answer comes from the search of those tables.
	 *
	 * @param array $pairs   The register and schema of each table.
	 * @param array $request The search request: queries and flags
	 *
	 * @return array{results: array, total: int, registers: array, schemas: array}
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
	 */
	private function searchTables(array $pairs, array $request): array {
		$query   = self::tableQuery(request: $request);
		$results = $this->searchAcrossMultipleTables(query: $query, registerSchemaPairs: $pairs);

		return [
			'results' => $results,
			'total'   => $this->countAcrossMultipleTables(query: $query, registerSchemaPairs: $pairs),
		] + $this->serialisedTablesOfPage(results: $results, pairs: $pairs);
	}//end searchTables()

	/**
	 * The query of a table search, with the request's read rules.
	 *
	 * @param array $request The search request: queries and flags
	 *
	 * @return array The query.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
	 */
	private static function tableQuery(array $request): array {
		return array_replace($request['search'], ['_rbac' => $request['rbac'], '_multitenancy' => $request['multitenancy']]);
	}//end tableQuery()

	/**
	 * The tables a search by ids may run on.
	 *
	 * A table that asks for a purpose the request does not carry refuses the
	 * search only when the caller may read one of the rows in it. A row the
	 * caller may not read answers as a missing row does.
	 *
	 * @param array $pairs   The register and schema of each table.
	 * @param array $request The search request: queries and flags
	 *
	 * @return array The tables the search may run on.
	 *
	 * @throws \OCA\OpenRegister\Service\Audit\PurposeRefusedException When the caller may read a row in a table that refuses.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function purposeReadableTables(array $pairs, array $request): array {
		$tables = $this->purposeBoundTables();
		$query  = self::tableQuery(request: $request);
		foreach ($tables->unreadable(pairs: $pairs) as $pair) {
			if ($this->countAcrossMultipleTables(query: $query, registerSchemaPairs: [$pair]) > 0) {
				$tables->refuseUnbound(pairs: [$pair]);
			}
		}

		return $tables->readable(pairs: $pairs);
	}//end purposeReadableTables()

	/**
	 * The purpose rule of each table, for a search that finds its own tables.
	 *
	 * @return PurposeBoundTables
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function purposeBoundTables(): PurposeBoundTables {
		return new PurposeBoundTables(guard: $this->container->get(PurposeGuardInterface::class));
	}//end purposeBoundTables()

	/**
	 * The register and schema of every table the rows come from, each table once.
	 *
	 * @param ObjectEntity[] $rows The rows.
	 *
	 * @return list<array{register: Register, schema: Schema}> The pairs.
	 */
	private function tablesOfRows(array $rows): array {
		$pairs = [];
		foreach ($rows as $row) {
			$table = $row->getRegister().'_'.$row->getSchema();
			if (array_key_exists($table, $pairs) === false) {
				$pairs[$table] = $this->tableOfRow(row: $row);
			}
		}

		return array_values(array_filter($pairs));
	}//end tablesOfRows()

	/**
	 * The register and schema of a row, or null when one of them is gone.
	 *
	 * @param ObjectEntity $row The row.
	 *
	 * @return array{register: Register, schema: Schema}|null The pair.
	 */
	private function tableOfRow(ObjectEntity $row): ?array {
		try {
			return [
				'register' => $this->registerMapper->find((int)$row->getRegister(), _multitenancy: false, _rbac: false),
				'schema'   => $this->schemaMapper->find((int)$row->getSchema(), _multitenancy: false, _rbac: false),
			];
		} catch (\Exception $e) {
			return null;
		}
	}//end tableOfRow()

	/**
	 * The serialised registers and schemas of the rows on a page, and no others.
	 *
	 * @param array $results The page.
	 * @param array $pairs   The searched tables.
	 *
	 * @return array{registers: array, schemas: array}
	 */
	private function serialisedTablesOfPage(array $results, array $pairs): array {
		$onPage = [];
		foreach ($results as $row) {
			$onPage[$row->getRegister().'_'.$row->getSchema()] = true;
		}

		$tables = ['registers' => [], 'schemas' => []];
		foreach ($pairs as $pair) {
			if (isset($onPage[$pair['register']->getId().'_'.$pair['schema']->getId()]) === true) {
				$tables['registers'][$pair['register']->getId()] = $pair['register']->jsonSerialize();
				$tables['schemas'][$pair['schema']->getId()]     = $pair['schema']->jsonSerialize();
			}
		}

		return $tables;
	}//end serialisedTablesOfPage()

	/**
	 * Search for objects across ALL magic tables using a text search term.
	 *
	 * Reads the search query (with `_search`), the count query and the RBAC and
	 * multitenancy flags from the search request; both queries carry the flags.
	 *
	 * @param array $request The search request: `search`, `count`, `rbac`, `multitenancy`.
	 *
	 * @return array Search results with pagination info.
	 */
	private function searchObjectsGloballyBySearch(array $request): array {
		$tables = $this->globallyReadableTables();
		if (empty($tables['pairs']) === true) {
			return [
				'results' => [],
				'total' => 0,
				'registers' => $tables['registers'],
				'schemas' => $tables['schemas'],
				'@self' => ['source' => 'magic_mapper'],
			];
		}

		$flags = ['_rbac' => $request['rbac'], '_multitenancy' => $request['multitenancy']];
		return [
			'results' => $this->searchAcrossMultipleTables(query: array_replace($request['search'], $flags), registerSchemaPairs: $tables['pairs']),
			'total' => $this->countInTables(pairs: $tables['pairs'], countQuery: array_replace($request['count'], $flags)),
			'registers' => $tables['registers'],
			'schemas' => $tables['schemas'],
			'ignoredFilters' => [],
			'@self' => ['source' => 'magic_mapper'],
		];
	}//end searchObjectsGloballyBySearch()

	/**
	 * Every register+schema table the purpose gate lets this request read, with its serialised register and schema.
	 *
	 * A pair whose register or schema cannot be loaded is skipped.
	 *
	 * @return array{pairs: list<array{register: Register, schema: Schema}>, registers: array, schemas: array}
	 */
	private function globallyReadableTables(): array {
		$idPairs = $this->getAllRegisterSchemaPairs();
		$gate    = $this->purposeBoundTables();
		$found   = ['pairs' => [], 'registers' => [], 'schemas' => []];
		foreach ($idPairs as $idPair) {
			$table = $this->readableTableOf(idPair: $idPair, gate: $gate);
			if ($table === null) {
				continue;
			}

			$found['registers'][$idPair['registerId']] ??= $table['registerJson'];
			$found['schemas'][$idPair['schemaId']]     ??= $table['schemaJson'];
			$found['pairs'][] = ['register' => $table['register'], 'schema' => $table['schema']];
		}

		return $found;
	}//end globallyReadableTables()

	/**
	 * Load one register+schema pair, or null when it cannot be loaded or the purpose gate refuses it.
	 *
	 * @param array{registerId: int, schemaId: int} $idPair The register and schema ids
	 * @param PurposeBoundTables                    $gate   The purpose gate of this request
	 *
	 * @return array{register: Register, schema: Schema, registerJson: array, schemaJson: array}|null
	 */
	private function readableTableOf(array $idPair, PurposeBoundTables $gate): ?array {
		try {
			$register = $this->registerMapper->find($idPair['registerId'], _multitenancy: false, _rbac: false);
			$schema   = $this->schemaMapper->find($idPair['schemaId'], _multitenancy: false, _rbac: false);
			if ($gate->readable(pairs: [['register' => $register, 'schema' => $schema]]) === []) {
				return null;
			}

			return ['register' => $register, 'schema' => $schema, 'registerJson' => $register->jsonSerialize(), 'schemaJson' => $schema->jsonSerialize()];
		} catch (\Exception $e) {
			return null;
		}
	}//end readableTableOf()

	/**
	 * Sum the count query over the given register+schema tables.
	 *
	 * @param list<array{register: Register, schema: Schema}> $pairs      The tables
	 * @param array                                           $countQuery The count query, flags included
	 *
	 * @return int
	 */
	private function countInTables(array $pairs, array $countQuery): int {
		$total = 0;
		foreach ($pairs as $pair) {
			$total += $this->countObjectsInRegisterSchemaTable(query: $countQuery, register: $pair['register'], schema: $pair['schema']);
		}

		return $total;
	}//end countInTables()

	/**
	 * Get size distribution chart data for objects.
	 *
	 * @param int|null $registerId Optional register ID filter.
	 * @param int|null $schemaId Optional schema ID filter.
	 *
	 * @return array{labels: list<string>, series: list<int>} Chart data.
	 */
	public function getSizeDistributionChartData(?int $registerId = null, ?int $schemaId = null): array {
		return [
			'labels' => [],
			'series' => [],
		];
	}//end getSizeDistributionChartData()
}//end class
