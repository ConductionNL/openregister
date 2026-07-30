<?php

/**
 * OpenReg  ister Audit Trail
 *
 * This file contains the class for handling audit trail related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\OrganisationService;
use Exception;
use RuntimeException;
use ReflectionClass;
use stdClass;
use OCA\OpenRegister\Exception\ValidationException;
use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\Aggregation\AggregationAnnotationValidator;
use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use OCA\OpenRegister\Service\Mcp\McpAnnotationValidator;
use OCA\OpenRegister\Service\Merge\MergeAnnotationValidator;
use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use OCA\OpenRegister\Service\Quality\DedupAnnotationValidator;
use OCA\OpenRegister\Service\Quality\QualityAnnotationValidator;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator;

/**
 * SchemaMapper handles database operations for Schema entities
 *
 * Mapper for Schema entities with multi-tenancy and RBAC support.
 * Provides CRUD operations with automatic organisation filtering, RBAC checks,
 * schema extension resolution, and event dispatching.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @method Schema insert(Entity $entity)
 * @method Schema update(Entity $entity)
 * @method Schema insertOrUpdate(Entity $entity)
 * @method Schema delete(Entity $entity, bool $force=false)
 * @method Schema find(int|string $id, ?array $_extend=[], bool $_rbac=true, bool $_multitenancy=true)
 * @method Schema findEntity(IQueryBuilder $query)
 * @method Schema[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<Schema> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Schema>
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required for schema management and analysis
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SchemaMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Maximum number of expressions allowed in a single SQL IN() list.
     *
     * Nextcloud's QueryBuilder refuses to emit more than 1000 expressions in an
     * IN() list because Oracle rejects them; exceeding it logs an error and
     * emits an "Undefined array key 0" PHP warning. Batched id lookups must be
     * chunked below this bound.
     *
     * @var integer
     */
    private const MAX_IN_LIST_SIZE = 1000;

    /**
     * Event dispatcher instance
     *
     * Dispatches events when schemas are created, updated, or deleted.
     *
     * @var IEventDispatcher Event dispatcher instance
     */
    private readonly IEventDispatcher $eventDispatcher;

    /**
     * Schema property validator instance
     *
     * Validates schema property definitions and types.
     *
     * @var PropertyValidatorHandler Schema property validator instance
     */
    private readonly PropertyValidatorHandler $validator;

    /**
     * Organisation mapper for multi-tenancy
     *
     * Used to get active organisation and apply organisation filters.
     *
     * @var OrganisationMapper Organisation mapper instance
     */
    protected readonly OrganisationMapper $organisationMapper;

    /**
     * App configuration for multi-tenancy settings
     *
     * Used by MultiTenancyTrait to check multi-tenancy status.
     *
     * @var IAppConfig App configuration instance
     */
    private IAppConfig $appConfig;

    /**
     * Request-scoped in-memory cache for find() results
     *
     * Prevents redundant DB queries when the same schema is looked up
     * multiple times within one request (e.g. controller → service → render).
     * Keys are composed of identifier + RBAC + multitenancy flags.
     *
     * @var array<string, Schema>
     */
    private array $findCache = [];

    /**
     * Read-attribution counters, keyed by "<mapper method>|<caller signature>".
     *
     * Only populated when {@see self::$perfTrace} is on. Static so the picture
     * spans every mapper instance in the request.
     *
     * @var array<string, int>
     */
    private static array $perfCounts = [];

    /**
     * Whether read attribution is on: null until resolved, then a bool.
     *
     * Resolved once per process — reading app config per schema read would
     * itself be one of the reads we are trying to count.
     *
     * @var boolean|null
     */
    private static ?bool $perfTrace = null;

    /**
     * User session for current user
     *
     * Used to get current user context for RBAC and multi-tenancy.
     *
     * @var IUserSession User session instance
     */
    private readonly IUserSession $userSession;

    /**
     * Group manager for RBAC
     *
     * Used to check user group memberships for permission verification.
     *
     * @var IGroupManager Group manager instance
     */
    private readonly IGroupManager $groupManager;

    // Note: $appConfig is provided by MultiTenancyTrait (protected ?IAppConfig $appConfig=null)
    // We assign it in the constructor to make it available to the trait methods.

    /**
     * Constructor
     *
     * Initializes mapper with database connection and required dependencies
     * for multi-tenancy, RBAC, validation, and event dispatching.
     *
     * @param IDBConnection            $db                 Database connection for queries
     * @param IEventDispatcher         $eventDispatcher    Event dispatcher for schema events
     * @param PropertyValidatorHandler $validator          Schema property validator for validation
     * @param OrganisationMapper       $organisationMapper Organisation mapper for multi-tenancy
     * @param IUserSession             $userSession        User session for current user context
     * @param IGroupManager            $groupManager       Group manager for RBAC checks
     * @param IAppConfig               $appConfig          App configuration for multitenancy settings
     * @param LoggerInterface          $logger             Structured logger (R07: surfaces unknown annotation keys).
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        IEventDispatcher $eventDispatcher,
        PropertyValidatorHandler $validator,
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
        // Initialize parent mapper with table name and entity class.
        parent::__construct(db: $db, tableName: 'openregister_schemas', entityClass: Schema::class);

        // Store dependencies for use in mapper methods.
        $this->eventDispatcher    = $eventDispatcher;
        $this->validator          = $validator;
        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->groupManager       = $groupManager;
        // Assign appConfig to trait's protected property.
        $this->appConfig = $appConfig;
    }//end __construct()

    /**
     * Record one schema read against its caller, when attribution is enabled.
     *
     * A single object create was measured issuing 5,135 sequential scans of
     * `oc_openregister_schemas` (2026-07-28). The count alone does not say
     * which path is responsible, and the fix differs per path — a cache key
     * that is too specific is a different repair from an uncached sibling
     * method. This records `<mapper method>|<caller signature>` so the answer
     * is measured rather than guessed.
     *
     * Off by default and gated on `openregister perf_trace_schema_reads`,
     * resolved once per process: reading app config on every schema read would
     * itself be one of the reads being counted.
     *
     * @param string $method The mapper method issuing the read.
     *
     * @return void
     *
     * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
     */
    private function traceRead(string $method): void
    {
        if (self::$perfTrace === null) {
            self::$perfTrace = ($this->appConfig->getValueString('openregister', 'perf_trace_schema_reads', '') === '1');
            if (self::$perfTrace === true) {
                register_shutdown_function(
                    static function (): void {
                        if (empty(self::$perfCounts) === true) {
                            return;
                        }

                        @file_put_contents(
                            '/tmp/or-schema-reads.jsonl',
                            json_encode(self::$perfCounts).PHP_EOL,
                            (FILE_APPEND | LOCK_EX)
                        );
                        self::$perfCounts = [];
                    }
                );
            }
        }

        if (self::$perfTrace === false) {
            return;
        }

        // Four frames past this one is enough to name the responsible path
        // without paying for a full backtrace on every read.
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        $sig    = [];
        foreach (array_slice($frames, 2, 4) as $frame) {
            $sig[] = (($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '?'));
        }

        $key = $method.'|'.implode('<', $sig);
        if (isset(self::$perfCounts[$key]) === false) {
            self::$perfCounts[$key] = 0;
        }

        self::$perfCounts[$key]++;

    }//end traceRead()

    /**
     * Finds a schema by id, with optional extension for statistics
     *
     * This method automatically resolves schema extensions. If the schema has
     * an 'extend' property set, it will load the parent schema and merge its
     * properties with the current schema, providing the complete resolved schema.
     *
     * @param int|string $id            The id of the schema
     * @param array      $_extend       Optional array of extensions (e.g., ['@self.stats'])
     * @param bool       $_rbac         Whether to apply RBAC permission checks (default: true)
     * @param bool       $_multitenancy Whether to apply multi-tenancy filtering (default: true)
     *                                  Set to false to bypass organization filter
     *                                  (e.g., when expanding schemas for registers)
     *
     * @return Schema The schema, possibly with stats and resolved extensions
     * @throws \Exception If user doesn't have read permission
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
     * @SuppressWarnings(PHPMD.StaticAccess)          Schema::fromRow is a standard entity factory pattern
     */
    public function find(
        string | int $id,
        ?array $_extend=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): Schema {
        // Check request-scoped cache to avoid redundant DB queries for the same schema.
        $rbacFlag = '0';
        if ($_rbac === true) {
            $rbacFlag = '1';
        }

        $mtFlag = '0';
        if ($_multitenancy === true) {
            $mtFlag = '1';
        }

        $cacheSuffix = ':'.$rbacFlag.':'.$mtFlag;
        $cacheKey    = strtolower((string) $id).$cacheSuffix;
        if (isset($this->findCache[$cacheKey]) === true) {
            return $this->findCache[$cacheKey];
        }

        // Verify RBAC permission to read if RBAC is enabled.
        if ($_rbac === true) {
            // @todo: remove this hotfix for solr - uncomment when ready
            // $this->verifyRbacPermission('read', 'schema');
        }

        $this->traceRead(method: 'find');
        \OCA\OpenRegister\Service\WritePhaseProbe::count(event: 'schema.db.read');

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_schemas');

        // Build OR conditions for matching against id, uuid, or slug.
        // Note: Only include id comparison if $id is actually numeric (PostgreSQL strict typing).
        // Slug comparison is case-insensitive using LOWER() function.
        $lowerId = strtolower((string) $id);
        // Default: match by uuid or slug only.
        $orConditions = $qb->expr()->orX(
            $qb->expr()->eq('uuid', $qb->createNamedParameter(value: $id, type: IQueryBuilder::PARAM_STR)),
            $qb->expr()->eq(
                $qb->func()->lower('slug'),
                $qb->createNamedParameter(value: $lowerId, type: IQueryBuilder::PARAM_STR)
            )
        );

        if (is_numeric($id) === true) {
            $idParam = $qb->createNamedParameter(value: (int) $id, type: IQueryBuilder::PARAM_INT);
            $orConditions->add(
                $qb->expr()->eq('id', $idParam)
            );
        }

        $qb->where($orConditions);

        // BUG-DB-10: when the identifier is numeric it is ambiguous (it could be a
        // primary key id OR a numeric uuid/slug). Prefer the exact primary-key
        // match by ordering an `id = ?` hit first, so a row whose slug happens to
        // be "5" never shadows the row with id 5.
        if (is_numeric($id) === true) {
            $qb->addOrderBy(
                $qb->createFunction(
                    'CASE WHEN id = '.$idParam.' THEN 0 ELSE 1 END'
                ),
                'ASC'
            );
        }

        // Apply organisation filter.
        // Set $_multitenancy=false to bypass organization filter (e.g., when expanding schemas for registers).
        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true,
            multiTenancyEnabled: $_multitenancy
        );

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            // Include diagnostic info in exception message for debugging.
            $debugInfo = sprintf(
                'Schema not found (id=%s, multitenancy=%s, rbac=%s)',
                var_export($id, true),
                var_export($_multitenancy, true),
                var_export($_rbac, true)
            );
            throw new DoesNotExistException($debugInfo);
        }

        $schema = Schema::fromRow($row);

        // Resolve schema composition if present (allOf, oneOf, anyOf).
        $schema = $this->resolveSchemaExtension(schema: $schema);

        // Cache by all possible identifiers to handle lookups by id, uuid, or slug.
        // BUG-DB-10: reuse the exact same suffix (rbac + multitenancy + published)
        // as the read-side key so cache writes and reads stay consistent.
        $this->findCache[$cacheKey] = $schema;
        $this->findCache[(string) $schema->getId().$cacheSuffix] = $schema;

        // BUG-DB-10: guard against a null uuid before strtolower().
        $schemaUuid = $schema->getUuid();
        if ($schemaUuid !== null) {
            $this->findCache[strtolower($schemaUuid).$cacheSuffix] = $schema;
        }

        if ($schema->getSlug() !== null) {
            $this->findCache[strtolower($schema->getSlug()).$cacheSuffix] = $schema;
        }

        return $schema;
    }//end find()

    /**
     * Resolve a schema by slug, scoped to a single owning application.
     *
     * The plain {@see find()} matches a schema by `LOWER(slug)` GLOBALLY across
     * every application on the instance and returns the FIRST row it fetches.
     * On a shared instance where ~20 apps live in one OpenRegister, that lets a
     * generic slug (e.g. `conversation`, `order`, `task`) from app B silently
     * bind to — or, on import, OVERWRITE — app A's schema that happens to share
     * the lower(slug). This scoped lookup is the import-side fix: an app only
     * ever matches (and therefore updates) a schema IT owns, so importing a
     * colliding slug creates the app's OWN schema instead of clobbering a
     * foreign one.
     *
     * @param string $slug        The schema slug (matched case-insensitively).
     * @param string $application The owning application id (exact match).
     *
     * @return Schema|null The application-owned schema, or null when the app
     *                     does not (yet) own a schema with this slug.
     */
    public function findByApplicationAndSlug(string $slug, string $application): ?Schema
    {
        $this->traceRead(method: 'findByApplicationAndSlug');

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_schemas')
            ->where(
                $qb->expr()->eq(
                    $qb->func()->lower('slug'),
                    $qb->createNamedParameter(value: strtolower($slug), type: IQueryBuilder::PARAM_STR)
                )
            )
            ->andWhere(
                $qb->expr()->eq('application', $qb->createNamedParameter(value: $application, type: IQueryBuilder::PARAM_STR))
            )
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return $this->resolveSchemaExtension(schema: Schema::fromRow($row));
    }//end findByApplicationAndSlug()

    /**
     * Resolve a schema by slug, scoped to a set of schema ids.
     *
     * This is the runtime-resolution half of the cross-app slug-collision fix.
     * When an object operation carries a register context, a schema slug must
     * resolve to the schema THAT REGISTER references — not to whichever
     * same-slug row {@see find()} happens to fetch first. Callers pass the
     * register's own `schemas` id list; the slug is matched only among those.
     *
     * @param string $slug      The schema slug (matched case-insensitively).
     * @param int[]  $schemaIds The candidate schema ids (a register's schemas list).
     *
     * @return Schema|null The matching schema within the id set, or null when
     *                     none of the register's schemas carry this slug.
     */
    public function findBySlugInIds(string $slug, array $schemaIds): ?Schema
    {
        // Normalise to a list of positive integers; an empty set can never match.
        $ids = [];
        foreach ($schemaIds as $candidate) {
            if (is_numeric($candidate) === true && (int) $candidate > 0) {
                $ids[] = (int) $candidate;
            }
        }

        if ($ids === []) {
            return null;
        }

        $this->traceRead(method: 'findBySlugInIds');

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_schemas')
            ->where(
                $qb->expr()->eq(
                    $qb->func()->lower('slug'),
                    $qb->createNamedParameter(value: strtolower($slug), type: IQueryBuilder::PARAM_STR)
                )
            )
            ->andWhere(
                $qb->expr()->in('id', $qb->createNamedParameter(value: $ids, type: IQueryBuilder::PARAM_INT_ARRAY))
            )
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return $this->resolveSchemaExtension(schema: Schema::fromRow($row));
    }//end findBySlugInIds()

    /**
     * Clear the request-scoped find cache for a specific schema
     *
     * Used by the runtime-schema-api CRUD path to drop the in-memory
     * cache entry after a mutation, so the next find() call re-reads
     * from the database. Clears every cache key that referenced the
     * given schema (by id, uuid, slug) across both RBAC/multi-tenancy
     * flag combinations.
     *
     * @param int $schemaId The schema ID to drop from the find cache.
     *
     * @return void
     */
    public function clearFindCache(int $schemaId): void
    {
        // Find every cache key whose value points at this schema ID and unset.
        foreach (array_keys($this->findCache) as $key) {
            $cached = $this->findCache[$key];
            if ($cached instanceof Schema && $cached->getId() === $schemaId) {
                unset($this->findCache[$key]);
            }
        }
    }//end clearFindCache()

    /**
     * Finds multiple schemas by id
     *
     * @param array $ids           The ids of the schemas
     * @param bool  $_rbac         Whether to apply RBAC permission checks (default: true)
     * @param bool  $_multitenancy Whether to apply multi-tenancy filtering (default: true)
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If a schema does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple schemas are found
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @todo: refactor this into find all
     *
     * @return Schema[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Schema>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function findMultiple(array $ids, bool $_rbac=true, bool $_multitenancy=true): array
    {
        $result = [];
        foreach ($ids as $id) {
            try {
                $result[] = $this->find(
                    id: $id,
                    _extend: [],
                    _rbac: $_rbac,
                    _multitenancy: $_multitenancy
                );
            } catch (DoesNotExistException | MultipleObjectsReturnedException | DBException) {
                // Catch all exceptions but do nothing.
            }
        }

        return $result;
    }//end findMultiple()

    /**
     * Find multiple schemas by IDs using a single optimized query
     *
     * This method performs a single database query to fetch multiple schemas,
     * register: * significantly improving performance compared to individual queries.
     *
     * @param array $ids Array of schema IDs to find
     *
     * @return Entity&Schema[]
     *
     * @psalm-return array<Entity&Schema>
     */
    public function findMultipleOptimized(array $ids): array
    {
        if (empty($ids) === true) {
            return [];
        }

        $schemas = [];

        // An IN() list is capped at MAX_IN_LIST_SIZE expressions: Nextcloud's
        // QueryBuilder logs "More than 1000 expressions in a list are not allowed
        // on Oracle" (and emits an "Undefined array key 0" PHP warning) once the
        // list exceeds that. This instance already holds 1,233 schemas, so the
        // registers-with-stats endpoint tripped it on every request. Chunk it.
        foreach (array_chunk($ids, self::MAX_IN_LIST_SIZE) as $chunk) {
            $this->traceRead(method: 'findMultipleOptimized');

            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('openregister_schemas')
                ->where(
                    $qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY))
                );

            $result = $qb->executeQuery();

            while (($row = $result->fetch()) !== false) {
                $schema = new Schema();
                $schema = $schema->fromRow($row);

                $schemas[$row['id']] = $schema;
            }

            $result->closeCursor();
        }//end foreach

        return $schemas;
    }//end findMultipleOptimized()

    /**
     * Finds schemas by slug
     *
     * Searches for schemas matching the given slug with optional
     * multi-tenancy and RBAC filtering.
     *
     * @param string $slug          The slug to search for
     * @param int    $limit         Maximum number of results (default: 10)
     * @param int    $offset        Offset for pagination (default: 0)
     * @param bool   $_rbac         Whether to apply RBAC permission checks (default: true)
     * @param bool   $_multitenancy Whether to apply multi-tenancy filtering (default: true)
     *
     * @return Schema[] Array of matching schemas
     *
     * @psalm-return list<Schema>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function findBySlug(
        string $slug,
        int $limit=10,
        int $offset=0,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        $this->traceRead(method: 'findBySlug');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_schemas')
            ->where(
                $qb->expr()->eq('slug', $qb->createNamedParameter($slug, IQueryBuilder::PARAM_STR))
            );

        // Apply organisation filter.
        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true,
            multiTenancyEnabled: $_multitenancy
        );

        $qb->setMaxResults($limit)
            ->setFirstResult($offset);

        $result  = $qb->executeQuery();
        $schemas = [];

        while (($row = $result->fetch()) !== false) {
            $schema    = Schema::fromRow($row);
            $schemas[] = $schema;
        }

        $result->closeCursor();

        return $schemas;
    }//end findBySlug()

    /**
     * Finds all schemas, files: with optional extension for statistics
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions The search conditions to apply
     * @param array|null $searchParams     The search parameters to apply
     * @param array      $_extend          Optional array of extensions (e.g., ['@self.stats'])
     * @param bool       $_rbac            Whether to apply RBAC permission checks (default: true)
     * @param bool       $_multitenancy    Whether to apply multi-tenancy filtering (default: true)
     *
     * @return Schema[]
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return                                  list<OCA\OpenRegister\Db\Schema>
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        ?array $_extend=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // Verify RBAC permission to read if RBAC is enabled.
        if ($_rbac === true) {
            // @todo: remove this hotfix for solr - uncomment when ready
            // $this->verifyRbacPermission('read', 'schema');
        }

        $this->traceRead(method: 'findAll');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_schemas')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters ?? [] as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
                continue;
            }

            if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
                continue;
            }

            $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams ?? [] as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Apply organisation filter.
        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true,
            multiTenancyEnabled: $_multitenancy
        );

        // Just return the entities; do not attach stats here.
        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Inserts a schema entity into the database
     *
     * @param Entity $entity The entity to insert
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If user doesn't have create permission
     *
     * @return Entity The inserted entity
     *
     * @psalm-suppress LessSpecificImplementedReturnType - Schema is more specific than Entity
     */
    public function insert(Entity $entity): Entity
    {
        // Verify RBAC permission to create.
        $this->verifyRbacPermission(action: 'create', entityType: 'schema');
        // Auto-set organisation from active session.
        $this->setOrganisationOnCreate(entity: $entity);

        // Auto-set owner from current user session.
        $this->setOwnerOnCreate(entity: $entity);

        $entity = parent::insert(entity: $entity);

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new SchemaCreatedEvent(schema: $entity));

        return $entity;
    }//end insert()

    /**
     * Ensures that a schema object has a UUID and a slug.
     *
     * @param Schema $schema The schema object to clean
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
     */
    private function cleanObject(Schema $schema): void
    {
        $this->cleanRefProperties(schema: $schema);
        $this->ensureSchemaIdentifiers(schema: $schema);
        $this->validateConfigurationFields(schema: $schema);
        $this->buildRequiredFieldsArray(schema: $schema);
        $this->autoPopulateConfigurationFields(schema: $schema);
        $this->validateLifecycleAnnotation(schema: $schema);
        $this->validateAggregationsAnnotation(schema: $schema);
        $this->validateCalculationsAnnotation(schema: $schema);
        $this->validateQualityAnnotation(schema: $schema);
        $this->validateDedupAnnotation(schema: $schema);
        $this->validateSurvivorshipAnnotation(schema: $schema);
        $this->validateMergeAnnotation(schema: $schema);
        $this->validateNotificationsAnnotation(schema: $schema);
        $this->validateWidgetsAnnotation(schema: $schema);
        $this->validateArchivalAnnotation(schema: $schema);
        $this->validateHandoffAnnotation(schema: $schema);
        $this->validateHandoffContractBinding(schema: $schema);
        $this->validateMcpAnnotation(schema: $schema);
        $this->logDroppedAnnotationKeys(schema: $schema);
    }//end cleanObject()

    /**
     * R07: surface dropped `x-openregister-*` keys via the structured
     * logger. Schema::validateConfigurationArray accumulates unknown
     * keys on the entity (no DI surface for a logger inside the
     * entity); we drain the buffer here at save time so admins see a
     * single warning per save call rather than nothing at all.
     *
     * @param Schema $schema Schema being saved.
     *
     * @return void
     */
    private function logDroppedAnnotationKeys(Schema $schema): void
    {
        $dropped = $schema->consumeDroppedAnnotationKeys();
        if (count($dropped) === 0) {
            return;
        }

        $message  = sprintf(
            '[OpenRegister.SchemaMapper] Dropped %d unknown x-openregister-* key(s) on schema "%s": %s.',
            count($dropped),
            (string) ($schema->getSlug() ?? ''),
            implode(', ', $dropped)
        );
        $message .= ' Typo? See Schema::ANNOTATION_VOCABULARY for the declared keys.';
        $this->logger->warning($message);
    }//end logDroppedAnnotationKeys()

    /**
     * Validate the optional `x-openregister-lifecycle` annotation.
     *
     * The annotation is stored under `configuration['x-openregister-lifecycle']`.
     * Errors are aggregated by LifecycleAnnotationValidator and thrown here as
     * a single message so callers see a clear schema-save failure.
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the annotation is malformed.
     *
     * @return void
     */
    private function validateLifecycleAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-lifecycle'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        // Validator expects the annotation at top-level alongside `properties`.
        $shape = [
            'properties'               => ($schema->getProperties() ?? []),
            'x-openregister-lifecycle' => $annotation,
        ];

        $errors = (new LifecycleAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        // Lifecycle is ADVISORY metadata (a state-machine hint), not a storage
        // requirement: a schema with a malformed or non-canonical lifecycle block
        // still stores objects correctly. Rejecting the whole schema import over an
        // advisory annotation breaks register imports for every app that ships a
        // partial / different-dialect lifecycle block. Degrade to a non-fatal
        // warning and import the schema (the lifecycle simply won't drive a status
        // workflow) instead of throwing.
        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        $this->logger->warning(
            'x-openregister-lifecycle annotation on schema "'.((string) ($schema->getSlug() ?? '')).'" is '
            .'invalid and was ignored (no status workflow applied): '.implode(' ', $messages)
        );
    }//end validateLifecycleAnnotation()

    /**
     * Validate the optional `x-openregister-aggregations` annotation.
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the annotation is malformed.
     *
     * @return void
     */
    private function validateAggregationsAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-aggregations'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'                  => ($schema->getProperties() ?? []),
            'x-openregister-aggregations' => $annotation,
        ];

        $errors = (new AggregationAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        // Aggregations are ADVISORY report-metadata, not a storage requirement.
        // A malformed / empty / non-canonical aggregation block must not abort the
        // whole schema import — the schema still stores objects, the aggregation
        // simply won't be runnable. Degrade to a non-fatal warning.
        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        $this->logger->warning(
            'x-openregister-aggregations annotation on schema "'.((string) ($schema->getSlug() ?? '')).'" is '
            .'invalid and was ignored (aggregation not registered): '.implode(' ', $messages)
        );
    }//end validateAggregationsAnnotation()

    /**
     * Validate the optional `x-openregister-calculations` annotation.
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the annotation is malformed.
     *
     * @return void
     */
    private function validateCalculationsAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-calculations'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'                  => ($schema->getProperties() ?? []),
            'x-openregister-calculations' => $annotation,
            'x-openregister-references'   => ($configuration['x-openregister-references'] ?? []),
        ];

        $errors = (new CalculationAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        // Calculations are ADVISORY derived-field metadata, not a storage
        // requirement. A malformed / non-canonical calculation block (e.g. a type
        // outside the canonical set, or a missing expression) must not abort the
        // whole schema import — the schema still stores objects, the calculation
        // simply won't be evaluated. Degrade to a non-fatal warning.
        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        $this->logger->warning(
            'x-openregister-calculations annotation on schema "'.((string) ($schema->getSlug() ?? '')).'" is '
            .'invalid and was ignored (calculation not evaluated): '.implode(' ', $messages)
        );
    }//end validateCalculationsAnnotation()

    /**
     * Validate the optional `x-openregister-quality` annotation.
     *
     * @param Schema $schema Schema to validate.
     *
     * @return void
     */
    private function validateQualityAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-quality'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'             => ($schema->getProperties() ?? []),
            'x-openregister-quality' => $annotation,
        ];

        $errors = (new QualityAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        // Quality scoring is ADVISORY derived-field metadata, not a storage
        // requirement. A malformed quality block must not abort the whole schema
        // import — the schema still stores objects, the score simply won't be
        // materialised. Degrade to a non-fatal warning.
        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        $this->logger->warning(
            'x-openregister-quality annotation on schema "'.((string) ($schema->getSlug() ?? '')).'" is '
            .'invalid and was ignored (quality score not materialised): '.implode(' ', $messages)
        );
    }//end validateQualityAnnotation()

    /**
     * Validate the optional `x-openregister-dedup` annotation.
     *
     * @param Schema $schema Schema to validate.
     *
     * @return void
     */
    private function validateDedupAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-dedup'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'           => ($schema->getProperties() ?? []),
            'x-openregister-dedup' => $annotation,
        ];

        $errors = (new DedupAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        // Dedup match rules are ADVISORY metadata consumed on demand by
        // DuplicateDetectionService. A malformed block must not abort the schema
        // import — duplicate detection simply falls back to caller-supplied rules.
        // Degrade to a non-fatal warning.
        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        $this->logger->warning(
            'x-openregister-dedup annotation on schema "'.((string) ($schema->getSlug() ?? '')).'" is '
            .'invalid and was ignored (declared match rules not used): '.implode(' ', $messages)
        );
    }//end validateDedupAnnotation()

    /**
     * Validate the optional `x-openregister-survivorship` annotation.
     *
     * @param Schema $schema Schema to validate.
     *
     * @return void
     */
    private function validateSurvivorshipAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-survivorship'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'                  => ($schema->getProperties() ?? []),
            'x-openregister-survivorship' => $annotation,
        ];

        $errors = (new SurvivorshipAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        // Survivorship is ADVISORY derived-field metadata, not a storage
        // requirement. A malformed survivorship block must not abort the whole
        // schema import — the schema still stores objects, the golden record
        // simply won't be materialised. Degrade to a non-fatal warning.
        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        $this->logger->warning(
            'x-openregister-survivorship annotation on schema "'.((string) ($schema->getSlug() ?? '')).'" is '
            .'invalid and was ignored (golden record not materialised): '.implode(' ', $messages)
        );
    }//end validateSurvivorshipAnnotation()

    /**
     * Validate the optional `x-openregister-merge` annotation.
     *
     * @param Schema $schema Schema to validate.
     *
     * @return void
     */
    private function validateMergeAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-merge'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'           => ($schema->getProperties() ?? []),
            'x-openregister-merge' => $annotation,
        ];

        $errors = (new MergeAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        // Merge config is ADVISORY steward-action metadata, not a storage
        // requirement. A malformed merge block must not abort the whole schema
        // import — the schema still stores objects, merges simply fall back to
        // the documented defaults. Degrade to a non-fatal warning.
        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        $this->logger->warning(
            'x-openregister-merge annotation on schema "'.((string) ($schema->getSlug() ?? '')).'" is '
            .'invalid and was ignored (merge falls back to defaults): '.implode(' ', $messages)
        );
    }//end validateMergeAnnotation()

    /**
     * Validate the optional `x-openregister-notifications` annotation.
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the annotation is malformed.
     *
     * @return void
     */
    private function validateNotificationsAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-notifications'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'                   => ($schema->getProperties() ?? []),
            'x-openregister-notifications' => $annotation,
        ];

        $errors = (new NotificationAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        $messages = array_map(static fn(array $err) => $err['message'], $errors);

        // A malformed OPTIONAL notification annotation must NOT block the schema
        // itself — and, on config import, the entire register + every schema
        // that references it (the import aborts and 0 objects land). The runtime
        // dispatcher only fires notifications whose trigger matches a known
        // event, so an invalid/legacy notification spec (e.g. the older
        // `onCreate`/`onStatusChange` shape without a trigger.type) is simply
        // inert. Log a warning so it can be cleaned up, but keep the schema
        // valid and importable.
        $this->logger->warning(
            'Schema "'.$schema->getSlug().'" has invalid x-openregister-notifications (ignored at runtime, fix to enable): '.implode(' ', $messages),
            ['schema' => $schema->getSlug()]
        );
    }//end validateNotificationsAnnotation()

    /**
     * Validate the optional `x-openregister-widgets` annotation.
     *
     * Schemas that pre-declare widgets (e.g. the `dashboard` schema in
     * the `reports` register) carry the array under
     * `configuration['x-openregister-widgets']`. Operators see shape
     * errors at schema-save time rather than at first render.
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the annotation is malformed.
     *
     * @return void
     */
    private function validateWidgetsAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-widgets'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = ['x-openregister-widgets' => $annotation];

        $errors = (new WidgetAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        throw new Exception('x-openregister-widgets: '.implode(' ', $messages));
    }//end validateWidgetsAnnotation()

    /**
     * Validate the optional `x-openregister-archival` annotation.
     *
     * The annotation is stored under `configuration['x-openregister-archival']`.
     * Errors are aggregated by ArchivalAnnotationValidator and thrown here as
     * a single message so callers see a clear schema-save failure.
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the annotation is malformed.
     *
     * @return void
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function validateArchivalAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-archival'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = ['x-openregister-archival' => $annotation];

        $errors = (new ArchivalAnnotationValidator())->validate(schema: $shape);
        if (count($errors) === 0) {
            return;
        }

        $messages = array_map(static fn(array $err) => $err['message'], $errors);
        throw new Exception('x-openregister-archival: '.implode(' ', $messages));
    }//end validateArchivalAnnotation()

    /**
     * Validate the optional `x-openregister-handoff` annotation (ADR-051).
     *
     * The annotation is stored under `configuration['x-openregister-handoff']`.
     * A malformed handoff declaration REJECTS the schema (contract: schema-save
     * validation SHALL reject with the typed handoff-* error codes) — unlike
     * advisory annotations, a broken handoff would otherwise surface as a
     * runtime conversion failure on user action.
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the annotation is malformed.
     *
     * @return void
     *
     * @spec openspec/specs/semantic-object-handoff/spec.md
     *   (Requirement: `x-openregister-handoff` declarative dialect)
     */
    private function validateHandoffAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-handoff'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'               => ($schema->getProperties() ?? []),
            'x-openregister-handoff'   => $annotation,
            'x-openregister-lifecycle' => ($configuration['x-openregister-lifecycle'] ?? null),
        ];

        $errors = (new HandoffAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        $messages = array_map(static fn(array $err) => $err['code'].': '.$err['message'], $errors);
        throw new Exception('x-openregister-handoff: '.implode(' ', $messages));
    }//end validateHandoffAnnotation()

    /**
     * Validate the optional `handoffContract` binding block (ADR-051,
     * provider side). Stored at `configuration['handoffContract']`; when
     * present, every mandatory contract field of each bound kind must map to
     * an existing own property — otherwise the schema is rejected with
     * `handoff-contract-incomplete` listing the missing fields. A schema that
     * implements a kind with NO binding block passes untouched (it is simply
     * not a handoff provider).
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the binding block is incomplete or malformed.
     *
     * @return void
     *
     * @spec openspec/specs/semantic-object-handoff/spec.md
     *   (Scenario: Implementer omits a mandatory contract field)
     */
    private function validateHandoffContractBinding(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        if (array_key_exists('handoffContract', $configuration) === false) {
            return;
        }

        $shape = [
            'properties'      => ($schema->getProperties() ?? []),
            'handoffContract' => $configuration['handoffContract'],
            'implements'      => ($configuration['implements'] ?? null),
            'jsonld'          => ($configuration['jsonld'] ?? null),
            'x-schema-org'    => ($configuration['x-schema-org'] ?? null),
        ];

        $errors = (new HandoffContractBindingValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        $messages = array_map(static fn(array $err) => $err['code'].': '.$err['message'], $errors);
        throw new Exception('handoffContract: '.implode(' ', $messages));
    }//end validateHandoffContractBinding()

    /**
     * Validate the optional `x-openregister-mcp` annotation (ADR-063).
     *
     * The annotation is stored under `configuration['x-openregister-mcp']`.
     * This change defines and validates the declaration only — no MCP tool
     * is emitted and no serving surface is altered by this validator (that
     * is the `or-mcp-derived-tool-provider` change). A malformed block
     * fails the schema save loudly, consistent with every sibling
     * `x-openregister-*` dialect validator.
     *
     * @param Schema $schema Schema to validate.
     *
     * @throws Exception When the annotation is malformed.
     *
     * @return void
     *
     * @spec openspec/specs/ai-mcp/spec.md
     *   (Requirement: REQ-DIALECT-002 — Save-time validation of the dialect shape)
     */
    private function validateMcpAnnotation(Schema $schema): void
    {
        $configuration = ($schema->getConfiguration() ?? []);
        $annotation    = ($configuration['x-openregister-mcp'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $shape = [
            'properties'         => ($schema->getProperties() ?? []),
            'x-openregister-mcp' => $annotation,
        ];

        $errors = (new McpAnnotationValidator())->validate($shape);
        if (count($errors) === 0) {
            return;
        }

        $messages = array_map(static fn(array $err) => $err['code'].': '.$err['message'], $errors);
        throw new Exception('x-openregister-mcp: '.implode(' ', $messages));
    }//end validateMcpAnnotation()

    /**
     * Clean $ref properties to ensure they are strings
     *
     * @param Schema $schema Schema to clean
     *
     * @return void
     */
    private function cleanRefProperties(Schema $schema): void
    {
        $properties = $schema->getProperties() ?? [];
        $this->enforceRefIsStringRecursive(properties: $properties);
        $schema->setProperties($properties);
    }//end cleanRefProperties()

    /**
     * Ensure schema has UUID, slug, version and source
     *
     * @param Schema $schema Schema to update
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
     */
    private function ensureSchemaIdentifiers(Schema $schema): void
    {
        if ($schema->getUuid() === null) {
            $schema->setUuid((string) Uuid::v4());
        }

        if (empty($schema->getSlug()) === true) {
            $schema->setSlug($this->generateSlug(title: $schema->getTitle() ?? 'schema'));
        }

        if ($schema->getVersion() === null) {
            $schema->setVersion('0.0.1');
        }

        if ($schema->getSource() === null || $schema->getSource() === '') {
            $schema->setSource('internal');
        }
    }//end ensureSchemaIdentifiers()

    /**
     * Generate a slug from a title
     *
     * @param string $title Title to convert
     *
     * @return string Generated slug
     */
    private function generateSlug(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }//end generateSlug()

    /**
     * Validate that configuration fields exist in properties
     *
     * @param Schema $schema Schema to validate
     *
     * @throws \Exception If field doesn't exist
     *
     * @return void
     */
    private function validateConfigurationFields(Schema $schema): void
    {
        $propertyKeys  = array_keys($schema->getProperties() ?? []);
        $configuration = $schema->getConfiguration() ?? [];

        $objectNameField = $configuration['objectNameField'] ?? '';
        if (empty($objectNameField) === false) {
            $this->validateConfigField(
                fieldValue: $objectNameField,
                propertyKeys: $propertyKeys,
                fieldName: 'objectNameField'
            );
        }

        $objDescField = $configuration['objectDescriptionField'] ?? '';
        if (empty($objDescField) === false) {
            $this->validateConfigField(
                fieldValue: $objDescField,
                propertyKeys: $propertyKeys,
                fieldName: 'objectDescriptionField'
            );
        }
    }//end validateConfigurationFields()

    /**
     * Validate a configuration field value against property keys
     *
     * Supports multiple formats:
     * - Simple property names: "name"
     * - Twig-style templates: "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}"
     * - Pipe-separated fallbacks: "name | identifier | type" (uses first available)
     *
     * @param string $fieldValue   The field value to validate
     * @param array  $propertyKeys Array of valid property keys
     * @param string $fieldName    Name of the field for error messages
     *
     * @throws \Exception If field references non-existent properties
     *
     * @return void
     */
    private function validateConfigField(string $fieldValue, array $propertyKeys, string $fieldName): void
    {
        // Check if it's a Twig-style template (contains {{ ... }}).
        if (strpos($fieldValue, '{{') !== false && strpos($fieldValue, '}}') !== false) {
            // Extract property names from template: {{ propName }}.
            preg_match_all('/\{\{\s*([a-zA-Z0-9_-]+)\s*\}\}/', $fieldValue, $matches);
            $templateProps = $matches[1] ?? [];

            if (empty($templateProps) === true) {
                // Template syntax but no valid property references found.
                return;
            }

            // Validate each property in the template exists.
            foreach ($templateProps as $prop) {
                if (in_array($prop, $propertyKeys, true) === false) {
                    throw new Exception(
                        "The template property '{$prop}' in {$fieldName} does not exist."
                    );
                }
            }

            return;
        }//end if

        // Check if it's a pipe-separated fallback list (e.g., "name | identifier | type").
        if (strpos($fieldValue, '|') !== false) {
            $fallbackFields = array_map('trim', explode('|', $fieldValue));

            // Validate that at least one fallback field exists in properties.
            $hasValidField = false;
            foreach ($fallbackFields as $field) {
                if (in_array($field, $propertyKeys, true) === true) {
                    $hasValidField = true;
                    break;
                }
            }

            if ($hasValidField === false) {
                throw new Exception(
                    "None of the fallback fields in {$fieldName} ('{$fieldValue}') exist as properties in the schema."
                );
            }

            return;
        }//end if

        // Simple property name - must exist in property keys.
        if (in_array($fieldValue, $propertyKeys, true) === false) {
            throw new Exception(
                "The value for {$fieldName} ('{$fieldValue}') does not exist as a property in the schema."
            );
        }
    }//end validateConfigField()

    /**
     * Build required fields array from schema or property flags
     *
     * @param Schema $schema Schema to update
     *
     * @return void
     */
    private function buildRequiredFieldsArray(Schema $schema): void
    {
        $existingRequired = $schema->getRequired();

        if (empty($existingRequired) === false) {
            return;
        }

        $requiredFields = [];
        $properties     = $schema->getProperties() ?? [];

        foreach ($properties as $propertyKey => $property) {
            if ($this->isPropertyRequired(property: $property) === true) {
                $requiredFields[] = $propertyKey;
            }
        }

        $schema->setRequired($requiredFields);
    }//end buildRequiredFieldsArray()

    /**
     * Check if a property is marked as required
     *
     * @param array $property Property definition
     *
     * @return bool True if required
     */
    private function isPropertyRequired(array $property): bool
    {
        $requiredValue = $property['required'] ?? null;

        if ($requiredValue === null) {
            return false;
        }

        if ($requiredValue === true || $requiredValue === 'true') {
            return true;
        }

        return is_string($requiredValue) === true && strtolower(trim($requiredValue)) === 'true';
    }//end isPropertyRequired()

    /**
     * Auto-populate configuration name/description fields if empty
     *
     * @param Schema $schema Schema to update
     *
     * @return void
     */
    private function autoPopulateConfigurationFields(Schema $schema): void
    {
        $propertyKeys  = array_keys($schema->getProperties() ?? []);
        $configuration = $schema->getConfiguration() ?? [];

        $nameFieldKeys = ['name', 'naam', 'title', 'titel'];
        $descFieldKeys = ['description', 'beschrijving', 'omschrijving', 'summary'];

        $objectNameField = $configuration['objectNameField'] ?? '';
        if (empty($objectNameField) === true) {
            $matchedKey = $this->findFirstMatchingKey(
                propertyKeys: $propertyKeys,
                candidates: $nameFieldKeys
            );
            if ($matchedKey !== null) {
                $configuration['objectNameField'] = $matchedKey;
                $schema->setConfiguration($configuration);
            }
        }

        $objDescField = $configuration['objectDescriptionField'] ?? '';
        if (empty($objDescField) === true) {
            $matchedKey = $this->findFirstMatchingKey(
                propertyKeys: $propertyKeys,
                candidates: $descFieldKeys
            );
            if ($matchedKey !== null) {
                $configuration['objectDescriptionField'] = $matchedKey;
                $schema->setConfiguration($configuration);
            }
        }
    }//end autoPopulateConfigurationFields()

    /**
     * Find first key from candidates that exists in property keys
     *
     * @param array $propertyKeys Property key array
     * @param array $candidates   Candidate keys to search for
     *
     * @return string|null Found key or null
     */
    private function findFirstMatchingKey(array $propertyKeys, array $candidates): string|null
    {
        foreach ($candidates as $key) {
            if (in_array($key, $propertyKeys) === true) {
                return $key;
            }
        }

        return null;
    }//end findFirstMatchingKey()

    /**
     * Recursively enforce that $ref is always a string in all properties and array items
     *
     * @param array $properties The properties array to check (passed by reference)
     *
     * @return void
     *
     * @throws \Exception If $ref is not a string or cannot be converted
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function enforceRefIsStringRecursive(array &$properties): void
    {
        foreach ($properties as $key => &$property) {
            // If property is not an array, skip.
            if (is_array($property) === false) {
                continue;
            }

            // Check $ref at this level.
            if (($property['$ref'] ?? null) !== null) {
                if (is_array($property['$ref']) === true && (($property['$ref']['id'] ?? null) !== null)) {
                    $property['$ref'] = $property['$ref']['id'];
                } else if (is_object($property['$ref']) === true && (($property['$ref']->id ?? null) !== null)) {
                    $property['$ref'] = $property['$ref']->id;
                } else if (is_int($property['$ref']) === true) {
                } else if (is_string($property['$ref']) === false && $property['$ref'] !== '') {
                    $refValue = json_encode($property['$ref']);
                    $msg      = "Schema property '$key' has a \$ref that is not a string or empty: ".$refValue;
                    throw new Exception($msg);
                }
            }

            // Check array items recursively.
            if (($property['items'] ?? null) !== null && is_array($property['items']) === true) {
                $this->enforceRefIsStringRecursive(properties: $property['items']);
            }

            // Check nested properties recursively.
            if (($property['properties'] ?? null) !== null && is_array($property['properties']) === true) {
                $this->enforceRefIsStringRecursive(properties: $property['properties']);
            }
        }//end foreach
    }//end enforceRefIsStringRecursive()

    /**
     * Creates a schema from an array
     *
     * This method handles schema extension by extracting only the delta
     * (differences from parent schema) before saving when the schema extends another.
     *
     * @param array $object The object to create
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If property validation fails
     *
     * @return Schema The created schema
     */
    public function createFromArray(array $object): Schema
    {
        $schema = new Schema();

        // Ensure required field is always set to avoid NULL in database.
        // This must be done BEFORE hydrate() so it gets marked as updated.
        if (isset($object['required']) === false || $object['required'] === null) {
            $object['required'] = [];
        }

        $schema->hydrate(object: $object, validator: $this->validator);

        // Clean the schema object to ensure UUID, slug, and version are set.
        $this->cleanObject(schema: $schema);

        // **SCHEMA COMPOSITION**: Extract delta if schema uses composition (allOf).
        // This ensures we only store the differences, not the full resolved schema.
        // NOTE: Circular reference validation is done during resolveSchemaExtension().
        $schema = $this->extractSchemaDelta(schema: $schema);

        // **PERFORMANCE OPTIMIZATION**: Generate facet configuration from schema properties.
        $this->generateFacetConfiguration(schema: $schema);

        $schema = $this->insert(entity: $schema);

        return $schema;
    }//end createFromArray()

    /**
     * Updates a schema entity in the database
     *
     * This method handles schema extension by extracting only the delta
     * (differences from parent schema) before saving when the schema extends another.
     *
     * @param Entity $entity The entity to update
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple entities are found
     * @throws \Exception If user doesn't have update permission or access to this organisation
     *
     * @return Entity The updated entity
     *
     * @psalm-suppress LessSpecificImplementedReturnType - Schema is more specific than Entity
     */
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update.
        $this->verifyRbacPermission(action: 'update', entityType: 'schema');
        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess(entity: $entity);

        // Fetch old entity directly without organisation filter for event comparison.
        $this->traceRead(method: 'update');

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_schemas')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
        $oldSchema = $this->findEntity(query: $qb);

        // Clean the schema object to ensure UUID, slug, and version are set.
        $this->cleanObject(schema: $entity);

        // **SCHEMA COMPOSITION**: Extract delta if schema uses composition (allOf).
        // This ensures we only store the differences, not the full resolved schema.
        // NOTE: Circular reference validation is done during resolveSchemaExtension().
        $entity = $this->extractSchemaDelta(schema: $entity);

        // **PERFORMANCE OPTIMIZATION**: Generate facet configuration from schema properties.
        $this->generateFacetConfiguration(schema: $entity);

        $entity = parent::update(entity: $entity);

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new SchemaUpdatedEvent(newSchema: $entity, oldSchema: $oldSchema));

        return $entity;
    }//end update()

    /**
     * Updates a schema from an array
     *
     * @param int   $id     The id of the schema to update
     * @param array $object The object to update
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the schema does not exist
     * @throws \Exception If property validation fails
     *
     * @return Schema The updated schema
     */
    public function updateFromArray(int $id, array $object): Schema
    {
        // Disable multitenancy filtering for update operations.
        // When updating by ID, we want to find the schema regardless of organisation.
        // Access verification happens in update() method via verifyOrganisationAccess().
        $schema = $this->find(id: $id, _multitenancy: false);

        // Set or update the version.
        if (isset($object['version']) === false) {
            $currentVersion = $schema->getVersion() ?? '0.0.0';
            $version        = explode('.', $currentVersion);
            $version[2]     = ((int) $version[2] + 1);
            $schema->setVersion(implode('.', $version));
        }

        $schema->hydrate(object: $object, validator: $this->validator);

        // Update the schema in the database.
        $schema = $this->update(entity: $schema);

        return $schema;
    }//end updateFromArray()

    /**
     * Delete a schema
     *
     * **DELETE SAFETY (runtime-schema-api)**: refuses to delete a schema that still
     * holds objects, unless the caller explicitly asks to orphan them ($force).
     *
     * This guard is enforced HERE, at the mapper, rather than only in
     * SchemasController::destroy(), because the mapper is the choke point every
     * deletion caller shares — including the AI/LLM-invokable SchemasToolProvider
     * and SchemaTool surfaces, which have no controller in front of them.
     *
     * @param Entity $entity The schema entity to delete
     * @param bool   $force  Bypass the object-count guard and deliberately orphan the
     *                       objects. Only the `?force=true` disposition of
     *                       `DELETE /api/schemas/{id}` may pass true. The cascade
     *                       disposition needs no bypass: it deletes the rows first, so
     *                       the guard naturally counts 0.
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws ValidationException If objects are still attached and $force is false
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     *
     * @return Schema The deleted schema
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The force flag is the API-level disposition, mirrored from the endpoint.
     *
     * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
     */
    public function delete(Entity $entity, bool $force=false): Schema
    {
        // Verify RBAC permission to delete.
        $this->verifyRbacPermission(action: 'delete', entityType: 'schema');
        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess(entity: $entity);

        $schemaId = $entity->id;
        if (method_exists($entity, 'getId') === true) {
            $schemaId = $entity->getId();
        }

        if ($force === false) {
            $count = $this->countAttachedObjects(schemaId: (int) $schemaId);
            if ($count > 0) {
                throw new ValidationException(
                    message: 'Cannot delete schema: '.$count.' objects are still attached.'
                );
            }
        }

        // Proceed with deletion.
        $result = parent::delete(entity: $entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(
            new SchemaDeletedEvent(schema: $entity)
        );

        return $result;
    }//end delete()

    /**
     * Count the objects still attached to a schema.
     *
     * Objects live in the per-register/schema MAGIC tables
     * (`openregister_table_{registerId}_{schemaId}`). The previous implementation of
     * this guard counted rows in the retired `openregister_objects` blob table, which
     * is always empty for magic-table objects — so it counted 0 and waved every
     * deletion through.
     *
     * This is a direct DB query, not a call into MagicMapper/MagicStatisticsHandler,
     * and that is deliberate: MagicStatisticsHandler injects SchemaMapper, so injecting
     * it back here would be a genuine circular dependency. The table name is
     * deterministic, so the count can be taken without the mapper.
     *
     * Soft-deleted rows are excluded, matching the object count the controller's guard
     * reports to the caller.
     *
     * @param int $schemaId The schema id.
     *
     * @return int The number of live objects attached to the schema.
     *
     * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
     */
    private function countAttachedObjects(int $schemaId): int
    {
        $total = 0;

        foreach ($this->getRegisterIdsWithSchema(schemaId: $schemaId) as $registerId) {
            $tableName = MagicMapper::TABLE_PREFIX.$registerId.'_'.$schemaId;

            if ($this->db->tableExists($tableName) === false) {
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*'))
                ->from($tableName)
                ->where($qb->expr()->isNull('_deleted'));

            $result = $qb->executeQuery();
            $total += (int) $result->fetchOne();
            $result->closeCursor();
        }

        return $total;
    }//end countAttachedObjects()

    /**
     * Find the ids of every register that references a schema.
     *
     * Direct query + decode in PHP, mirroring RegisterMapper::getAllRegisterIdsWithSchema():
     * the `schemas` column is JSON on Postgres and text elsewhere, so there is no
     * portable SQL predicate for "array contains N". Registers are O(10s) per install,
     * so the cost is trivial. RegisterMapper itself cannot be injected here — it
     * injects SchemaMapper.
     *
     * @param int $schemaId The schema id.
     *
     * @return array<int, int> The register ids.
     */
    private function getRegisterIdsWithSchema(int $schemaId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'schemas')
            ->from('openregister_registers');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        $needle      = (string) $schemaId;
        $registerIds = [];

        foreach ($rows as $row) {
            $schemas = json_decode(($row['schemas'] ?? '[]'), true);
            if (is_array($schemas) === false) {
                continue;
            }

            foreach ($schemas as $candidate) {
                if ((string) $candidate === $needle) {
                    $registerIds[] = (int) $row['id'];
                    break;
                }
            }
        }

        return $registerIds;
    }//end getRegisterIdsWithSchema()

    /**
     * Get the number of registers associated with each schema
     *
     * This method returns an associative array where the key is the schema ID
     * and the value is the number of registers that reference that schema.
     *
     * @phpstan-return array<int,int>  Associative array of schema ID => register count
     *
     * @psalm-return array<int, int>
     * @return       int[]
     */
    public function getRegisterCountPerSchema(): array
    {
        // TODO: Optimize for large datasets (current approach loads all registers into memory).
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'schemas')
            ->from('openregister_registers');
        $result = $qb->executeQuery()->fetchAll();

        $counts = [];
        foreach ($result as $row) {
            // Decode the schemas JSON array for each register.
            $decoded = json_decode($row['schemas'], true);
            $schemas = [];
            if ($decoded !== null && $decoded !== false) {
                $schemas = $decoded;
            }

            foreach ($schemas as $schemaId) {
                $counts[(int) $schemaId] = ($counts[(int) $schemaId] ?? 0) + 1;
            }
        }

        return $counts;
    }//end getRegisterCountPerSchema()

    /**
     * Get all schema ID to slug mappings
     *
     * @return array<string,string> Array mapping schema IDs to their slugs
     */
    public function getIdToSlugMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['id']] = $row['slug'];
        }

        return $mappings;
    }//end getIdToSlugMap()

    /**
     * Get all schema slug to ID mappings
     *
     * @return array<string,string> Array mapping schema slugs to their IDs
     */
    public function getSlugToIdMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['slug']] = $row['id'];
        }

        return $mappings;
    }//end getSlugToIdMap()

    /**
     * Find schemas that have properties referencing the given schema
     *
     * This method searches through all schemas to find ones that have properties
     * with $ref pointing to the target schema, indicating a relationship.
     *
     * @param Schema|int|string $schema The target schema to find references to
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the target schema does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple target schemas are found
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return Schema[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Schema>
     */
    public function getRelated(Schema|int|string $schema): array
    {
        // If we received a Schema entity, get its ID, otherwise find the schema.
        if ($schema instanceof Schema === false) {
            // Find the target schema to get all its identifiers.
            $targetSchema     = $this->find(id: $schema);
            $targetSchemaId   = (string) $targetSchema->getId();
            $targetSchemaUuid = $targetSchema->getUuid();
            $targetSchemaSlug = $targetSchema->getSlug();
        }

        if ($schema instanceof Schema === true) {
            $targetSchemaId   = (string) $schema->getId();
            $targetSchemaUuid = $schema->getUuid();
            $targetSchemaSlug = $schema->getSlug();
        }

        // Get all schemas to search through their properties.
        $allSchemas     = $this->findAll();
        $relatedSchemas = [];

        foreach ($allSchemas as $currentSchema) {
            // Skip the target schema itself.
            if ($currentSchema->getId() === (int) $targetSchemaId) {
                continue;
            }

            // Get the properties of the current schema.
            $properties = $currentSchema->getProperties() ?? [];

            // Search for references to the target schema.
            if ($this->hasReferenceToSchema(
                    properties: $properties,
                    targetSchemaId: $targetSchemaId,
                    targetSchemaUuid: $targetSchemaUuid,
                    targetSchemaSlug: $targetSchemaSlug
                ) === true
            ) {
                $relatedSchemas[] = $currentSchema;
            }
        }//end foreach

        return $relatedSchemas;
    }//end getRelated()

    /**
     * Recursively check if properties contain a reference to the target schema
     *
     * This method searches through properties recursively to find $ref values
     * that match the target schema's ID, files: UUID, rbac: or slug.
     *
     * @param array  $properties       The properties array to search through
     * @param string $targetSchemaId   The target schema ID to look for
     * @param string $targetSchemaUuid The target schema UUID to look for
     * @param string $targetSchemaSlug The target schema slug to look for
     *
     * @return bool True if a reference to the target schema is found
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)      Recursive reference checking requires many conditions
     */
    public function hasReferenceToSchema(
        array $properties,
        string $targetSchemaId,
        string $targetSchemaUuid,
        string $targetSchemaSlug
    ): bool {
        foreach ($properties as $property) {
            // Skip non-array properties.
            if (is_array($property) === false) {
                continue;
            }

            // Check if this property has a $ref that matches our target schema.
            if (($property['$ref'] ?? null) !== null) {
                $ref = $property['$ref'];

                // Check exact matches first.
                if ($ref === $targetSchemaId
                    || $ref === $targetSchemaUuid
                    || $ref === $targetSchemaSlug
                    || $ref === (int) $targetSchemaId
                ) {
                    return true;
                }

                // Check if the ref contains the target schema slug in JSON Schema format.
                // Format: "#/components/schemas/slug" or "components/schemas/slug" etc.
                if (is_string($ref) === true && empty($targetSchemaSlug) === false) {
                    if (str_contains($ref, '/schemas/'.$targetSchemaSlug) === true
                        || str_contains($ref, 'schemas/'.$targetSchemaSlug) === true
                        || str_ends_with($ref, '/'.$targetSchemaSlug) === true
                    ) {
                        return true;
                    }
                }

                // Check if the entity contains the target schema UUID.
                if (is_string($ref) === true && empty($targetSchemaUuid) === false) {
                    if (str_contains($ref, $targetSchemaUuid) === true) {
                        return true;
                    }
                }
            }//end if

            // Recursively check nested properties.
            if (($property['properties'] ?? null) !== null && is_array($property['properties']) === true) {
                if ($this->hasReferenceToSchema(
                        properties: $property['properties'],
                        targetSchemaId: $targetSchemaId,
                        targetSchemaUuid: $targetSchemaUuid,
                        targetSchemaSlug: $targetSchemaSlug
                    ) === true
                ) {
                    return true;
                }
            }

            // Check array items for references.
            if (($property['items'] ?? null) !== null && is_array($property['items']) === true) {
                if ($this->hasReferenceToSchema(
                        properties: [$property['items']],
                        targetSchemaId: $targetSchemaId,
                        targetSchemaUuid: $targetSchemaUuid,
                        targetSchemaSlug: $targetSchemaSlug
                    ) === true
                ) {
                    return true;
                }
            }
        }//end foreach

        return false;
    }//end hasReferenceToSchema()

    /**
     * Generate facet configuration from schema properties
     *
     * @param Schema $schema The schema to generate facets for.
     *
     * @return void
     *
     * @deprecated This method is no longer needed since facets are now computed at runtime
     *             from property-level `facetable: true` settings. The system automatically
     *             reads facetable properties when processing facet requests.
     *             This method is kept for backward compatibility only.
     */
    private function generateFacetConfiguration(Schema $schema): void
    {
        $properties  = $schema->getProperties() ?? [];
        $facetConfig = [
            '@self'         => [
                'register'  => ['type' => 'terms'],
                'schema'    => ['type' => 'terms'],
                'created'   => ['type' => 'date_histogram', 'interval' => 'month'],
                'updated'   => ['type' => 'date_histogram', 'interval' => 'month'],
                'published' => ['type' => 'date_histogram', 'interval' => 'month'],
                'owner'     => ['type' => 'terms'],
            ],
            'object_fields' => [],
        ];

        // Analyze properties for facetable fields.
        foreach ($properties as $fieldName => $property) {
            if (is_array($property) === false) {
                continue;
            }

            $facetType = $this->determineFacetTypeForProperty(
                property: $property,
                fieldName: $fieldName
            );
            if ($facetType !== null) {
                $facetConfig['object_fields'][$fieldName] = ['type' => $facetType];

                // Add interval for date histograms.
                if ($facetType === 'date_histogram') {
                    $facetConfig['object_fields'][$fieldName]['interval'] = 'month';
                }
            }
        }

        // Store the facet configuration in the schema.
        // $facetConfig always contains at least '@self', so it's never empty.
        $schema->setFacets($facetConfig);
    }//end generateFacetConfiguration()

    /**
     * Determine the appropriate facet type for a schema property
     *
     * PERFORMANCE OPTIMIZATION**: Smart detection of facetable fields based on
     * property characteristics, names, and explicit facetable markers.
     *
     * @param array  $property  The property definition
     * @param string $fieldName The field name
     *
     * @return null|string The facet type ('terms', 'date_histogram') or null if not facetable
     *
     * @psalm-return 'date_histogram'|'terms'|null
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function determineFacetTypeForProperty(array $property, string $fieldName): string|null
    {
        // Check if explicitly marked as not facetable (facetable: false).
        // This must be checked first to prevent auto-detection from overriding explicit exclusion.
        $facetable = $property['facetable'] ?? null;
        if ($facetable === false || $facetable === 'false'
            || (is_string($facetable) === true && strtolower(trim($facetable)) === 'false')
        ) {
            return null;
        }

        // Check if explicitly marked as facetable.
        $isFacetableString = is_string($facetable) === true
            && strtolower(trim($facetable)) === 'true';
        if ($facetable !== null
            && ($facetable === true || $facetable === 'true'
            || $isFacetableString === true) === true
        ) {
            return $this->determineFacetTypeFromProperty(property: $property);
        }

        // Auto-detect common facetable field names.
        $commonFacetFields = [
            'type',
            'status',
            'category',
            'tags',
            'label',
            'group',
            'department',
            'location',
            'priority',
            'state',
            'classification',
            'genre',
            'brand',
            'model',
            'version',
            'license',
            'language',
        ];

        $lowerFieldName = strtolower($fieldName);
        if (in_array($lowerFieldName, $commonFacetFields) === true) {
            return $this->determineFacetTypeFromProperty(property: $property);
        }

        // Auto-detect enum properties (good for faceting).
        if (($property['enum'] ?? null) !== null && is_array($property['enum']) === true && count($property['enum']) > 0) {
            return 'terms';
        }

        // Auto-detect date/datetime fields.
        $propertyType = $property['type'] ?? '';
        if (in_array($propertyType, ['date', 'datetime', 'date-time']) === true) {
            return 'date_histogram';
        }

        // Check for date-like field names.
        $dateFields = ['created', 'updated', 'modified', 'date', 'time', 'timestamp'];
        foreach ($dateFields as $dateField) {
            if (str_contains($lowerFieldName, $dateField) === true) {
                return 'date_histogram';
            }
        }

        return null;
    }//end determineFacetTypeForProperty()

    /**
     * Determine facet type from property characteristics
     *
     * @param array $property The property definition
     *
     * @return string The facet type ('terms' or 'date_histogram')
     *
     * @psalm-return 'date_histogram'|'terms'
     */
    private function determineFacetTypeFromProperty(array $property): string
    {
        $propertyType   = $property['type'] ?? 'string';
        $propertyFormat = $property['format'] ?? '';

        // Date/datetime properties use date_histogram.
        // Checks both 'type' (e.g. type: date) and 'format' (e.g. type: string, format: date)
        // because JSON Schema represents dates as type: "string" with format: "date".
        if (in_array($propertyType, ['date', 'datetime', 'date-time']) === true
            || in_array($propertyFormat, ['date', 'date-time', 'datetime']) === true
        ) {
            return 'date_histogram';
        }

        // Enum properties use terms.
        if (($property['enum'] ?? null) !== null && is_array($property['enum']) === true) {
            return 'terms';
        }

        // Boolean, integer, number with small ranges use terms.
        if (in_array($propertyType, ['boolean', 'integer', 'number']) === true) {
            return 'terms';
        }

        // Default to terms for other types.
        return 'terms';
    }//end determineFacetTypeFromProperty()

    /**
     * Resolve schema composition by merging referenced schemas
     *
     * This method implements JSON Schema composition patterns conforming to the specification:
     * 1. Handles 'allOf' - instance must validate against ALL schemas (multiple inheritance)
     * 2. Handles 'oneOf' - instance must validate against EXACTLY ONE schema
     * 3. Handles 'anyOf' - instance must validate against AT LEAST ONE schema
     *
     * The method enforces the Liskov Substitution Principle:
     * - Extended schemas can ONLY ADD constraints, never relax them
     * - Metadata (title, description, order) can be overridden
     * - Validation rules (type, format, enum, min/max, pattern) cannot be relaxed
     *
     * @param Schema $schema  The schema to resolve
     * @param array  $visited Array of visited schema IDs to prevent circular references
     *
     * @throws \Exception If circular reference is detected or referenced schema not found
     *
     * @return Schema The resolved schema with merged properties
     */
    private function resolveSchemaExtension(Schema $schema, array $visited=[]): Schema
    {
        // Get current schema identifier for tracking.
        $currentId = $schema->getId() ?? $schema->getUuid() ?? 'unknown';

        // Check for circular references.
        if (in_array($currentId, $visited) === true) {
            throw new Exception("Circular schema composition detected: schema '{$currentId}' creates a loop");
        }

        // Add current schema to visited list.
        $visited[] = $currentId;

        // Check for composition patterns (in order of precedence).
        $allOf = $schema->getAllOf();
        $oneOf = $schema->getOneOf();
        $anyOf = $schema->getAnyOf();

        // If schema has allOf, resolve it (most common for extension/inheritance).
        if ($allOf !== null && count($allOf) > 0) {
            return $this->resolveAllOf(schema: $schema, allOf: $allOf, visited: $visited);
        }

        // If schema has oneOf, resolve it.
        if ($oneOf !== null && count($oneOf) > 0) {
            return $this->resolveOneOf(schema: $schema, oneOf: $oneOf, visited: $visited);
        }

        // If schema has anyOf, resolve it.
        if ($anyOf !== null && count($anyOf) > 0) {
            return $this->resolveAnyOf(schema: $schema, anyOf: $anyOf, visited: $visited);
        }

        // No composition - return schema as-is.
        return $schema;
    }//end resolveSchemaExtension()

    /**
     * Resolve allOf composition pattern
     *
     * Instance must validate against ALL referenced schemas.
     * This is the recommended pattern for schema extension/inheritance.
     * Properties from all schemas are merged with the child schema.
     *
     * @param Schema $schema  The child schema
     * @param array  $allOf   Array of schema identifiers to merge
     * @param array  $visited Visited schemas for circular reference detection
     *
     * @throws \Exception If referenced schema not found or circular reference detected
     *
     * @return Schema Resolved schema with all properties merged
     */
    private function resolveAllOf(Schema $schema, array $allOf, array $visited): Schema
    {
        $currentId = $schema->getId() ?? $schema->getUuid() ?? 'unknown';

        // Start with empty properties and required fields.
        $mergedProperties = [];
        $mergedRequired   = [];

        // Iterate through each referenced schema in allOf.
        foreach ($allOf as $parentRef) {
            // Skip empty or null references.
            if (empty($parentRef) === true) {
                continue;
            }

            // Skip inline constraint schemas. JSON Schema allows an allOf entry to be an
            // inline schema object ({ "required": [...] }, { "properties": {...} }), not
            // only a $ref to another schema. OpenRegister's composition model treats
            // allOf/oneOf/anyOf as a list of schema IDENTIFIERS, so only string/int
            // entries are references to load — an array entry is a constraint that stands
            // on its own and must not be handed to loadSchema() (which would fatal on the
            // array). This unblocked openconnector's register import, silently broken by
            // lti_deployment's `oneOf: [{required, not}, ...]` XOR constraint.
            if (is_string($parentRef) === false && is_int($parentRef) === false) {
                continue;
            }

            // Check for self-reference.
            if ($parentRef === $currentId || $parentRef === $schema->getId()
                || $parentRef === $schema->getUuid() || $parentRef === $schema->getSlug()
            ) {
                throw new Exception("Schema '{$currentId}' cannot reference itself in allOf");
            }

            // Load and resolve the parent schema.
            $parentSchema = $this->loadSchema(identifier: $parentRef);
            $parentSchema = $this->resolveSchemaExtension(
                schema: $parentSchema,
                visited: $visited
            );

            // Merge properties from this parent.
            $mergedProperties = $this->mergeSchemaProperties(
                parentProperties: $mergedProperties,
                childProperties: $parentSchema->getProperties()
            );

            // Merge required fields (union - must satisfy all).
            $mergedRequired = array_unique(
                array_merge($mergedRequired, $parentSchema->getRequired())
            );
        }//end foreach

        // Now merge child schema properties on top (child can add constraints).
        $childProperties  = $schema->getProperties();
        $mergedProperties = $this->mergeSchemaPropertiesWithValidation(
            parentProperties: $mergedProperties,
            childProperties: $childProperties,
            schemaId: (string) $currentId
        );

        // Merge child required fields (can only add, not remove).
        $mergedRequired = array_unique(
            array_merge($mergedRequired, $schema->getRequired())
        );

        // Create resolved schema.
        $resolvedSchema = clone $schema;
        $resolvedSchema->setProperties($mergedProperties);
        $resolvedSchema->setRequired($mergedRequired);

        return $resolvedSchema;
    }//end resolveAllOf()

    /**
     * Get property source metadata for a schema
     *
     * Returns metadata about each property indicating whether it's native (defined in this schema)
     * or inherited (from a parent schema via allOf). For inherited properties, shows the source schema.
     *
     * @param Schema $schema The schema to analyze
     *
     * @return array<string, array<string, string|null>> Property metadata keyed by property name
     *
     * @psalm-return array<string,
     *     array{source: 'native'|'inherited', inheritedFrom: string|null}>
     */
    public function getPropertySourceMetadata(Schema $schema): array
    {
        $metadata         = [];
        $nativeProperties = [];

        // Get the raw schema data from database to see what properties it actually stores.
        // This is necessary because the resolved schema has merged properties.
        try {
            $this->traceRead(method: 'getPropertySourceMetadata');

            $qb = $this->db->getQueryBuilder();
            $qb->select('properties')
                ->from('openregister_schemas')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row !== false && ($row['properties'] ?? null) !== null) {
                $nativeProperties = json_decode($row['properties'], true) ?? [];
            }
        } catch (Exception $e) {
            // If we can't get raw data, use current properties.
            $nativeProperties = [];
        }//end try

        $allProperties = $schema->getProperties();
        $allOf         = $schema->getAllOf() ?? [];

        foreach ($allProperties as $propName => $propDef) {
            // Suppress unused variable warning for $propDef - only processing property names.
            unset($propDef);
            $isNative = isset($nativeProperties[$propName]);

            $source = 'inherited';
            if ($isNative === true) {
                $source = 'native';
            }

            $inheritedFrom = null;
            if ($isNative === false) {
                $inheritedFrom = $this->findPropertySource(
                    propertyName: $propName,
                    parentRefs: $allOf
                );
            }

            $metadata[$propName] = [
                'source'        => $source,
                'inheritedFrom' => $inheritedFrom,
            ];
        }//end foreach

        return $metadata;
    }//end getPropertySourceMetadata()

    /**
     * Find which parent schema a property was inherited from
     *
     * @param string $propertyName The property name to search for
     * @param array  $parentRefs   Array of parent schema references
     *
     * @return string|null The parent schema ID/UUID/slug, or null if not found
     */
    private function findPropertySource(string $propertyName, array $parentRefs): ?string
    {
        foreach ($parentRefs as $parentRef) {
            // Skip empty or null references.
            if (empty($parentRef) === true) {
                continue;
            }

            try {
                $parentSchema = $this->loadSchema(identifier: $parentRef);
                $parentSchema = $this->resolveSchemaExtension(schema: $parentSchema);

                if (isset($parentSchema->getProperties()[$propertyName]) === true) {
                    return (string) $parentRef;
                }
            } catch (Exception $e) {
                // Parent not found, continue.
                continue;
            }
        }

        return null;
    }//end findPropertySource()

    /**
     * Resolve oneOf composition pattern
     *
     * Instance must validate against EXACTLY ONE referenced schema.
     * This pattern is used for mutually exclusive options.
     * Properties from each schema are kept separate (not merged).
     *
     * @param Schema $schema  The schema with oneOf
     * @param array  $oneOf   Array of schema identifiers
     * @param array  $visited Visited schemas for circular reference detection
     *
     * @throws \Exception If referenced schema not found
     *
     * @return Schema The schema with resolved oneOf references
     */
    private function resolveOneOf(Schema $schema, array $oneOf, array $visited): Schema
    {
        // For oneOf, we don't merge properties - each option stands alone.
        // Just validate that all referenced schemas exist and resolve them.
        $currentId = $schema->getId() ?? $schema->getUuid() ?? 'unknown';

        foreach ($oneOf as $ref) {
            // Skip inline constraint schemas — a oneOf entry may be an inline schema object
            // ({ "required": [...], "not": {...} }) rather than a $ref to another schema.
            // Only string/int entries are schema identifiers to load. See resolveAllOf.
            if (is_string($ref) === false && is_int($ref) === false) {
                continue;
            }

            if ($ref === $currentId || $ref === $schema->getId()
                || $ref === $schema->getUuid() || $ref === $schema->getSlug()
            ) {
                throw new Exception("Schema '{$currentId}' cannot reference itself in oneOf");
            }

            // Load and resolve referenced schema (validates it exists).
            $referencedSchema = $this->loadSchema(identifier: $ref);
                $this->resolveSchemaExtension(
                    schema: $referencedSchema,
                    visited: $visited
                );
        }//end foreach

        // Return schema as-is (oneOf schemas are not merged).
        return $schema;
    }//end resolveOneOf()

    /**
     * Resolve anyOf composition pattern
     *
     * Instance must validate against AT LEAST ONE referenced schema.
     * This pattern provides flexible composition.
     * Properties from each schema are kept separate (not merged).
     *
     * @param Schema $schema  The schema with anyOf
     * @param array  $anyOf   Array of schema identifiers
     * @param array  $visited Visited schemas for circular reference detection
     *
     * @throws \Exception If referenced schema not found
     *
     * @return Schema The schema with resolved anyOf references
     */
    private function resolveAnyOf(Schema $schema, array $anyOf, array $visited): Schema
    {
        // For anyOf, we don't merge properties - each option stands alone.
        // Just validate that all referenced schemas exist and resolve them.
        $currentId = $schema->getId() ?? $schema->getUuid() ?? 'unknown';

        foreach ($anyOf as $ref) {
            // Skip inline constraint schemas — an anyOf entry may be an inline schema
            // object rather than a $ref. Only string/int entries are identifiers. See
            // resolveAllOf.
            if (is_string($ref) === false && is_int($ref) === false) {
                continue;
            }

            if ($ref === $currentId || $ref === $schema->getId()
                || $ref === $schema->getUuid() || $ref === $schema->getSlug()
            ) {
                throw new Exception("Schema '{$currentId}' cannot reference itself in anyOf");
            }

            // Load and resolve referenced schema (validates it exists).
            $referencedSchema = $this->loadSchema(identifier: $ref);
                $this->resolveSchemaExtension(
                    schema: $referencedSchema,
                    visited: $visited
                );
        }//end foreach

        // Return schema as-is (anyOf schemas are not merged).
        return $schema;
    }//end resolveAnyOf()

    /**
     * Load a schema by ID, UUID, or slug
     *
     * Helper method to load a schema from the database by any identifier type.
     *
     * @param string|int $identifier Schema ID, UUID, or slug
     *
     * @throws \Exception If schema not found
     *
     * @return Schema The loaded schema
     */
    private function loadSchema(string|int $identifier): Schema
    {
        try {
            $this->traceRead(method: 'loadSchema');

            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('openregister_schemas')
                ->where(
                    $qb->expr()->orX(
                        $qb->expr()->eq(
                            'id',
                            $qb->createNamedParameter(value: $identifier, type: IQueryBuilder::PARAM_INT)
                        ),
                        $qb->expr()->eq(
                            'uuid',
                            $qb->createNamedParameter(value: $identifier, type: IQueryBuilder::PARAM_STR)
                        ),
                        $qb->expr()->eq(
                            'slug',
                            $qb->createNamedParameter(value: $identifier, type: IQueryBuilder::PARAM_STR)
                        )
                    )
                );

            return $this->findEntity(query: $qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new Exception("Schema '{$identifier}' not found");
        }//end try
    }//end loadSchema()

    /**
     * Merge parent and child schema properties (without validation)
     *
     * This method performs a deep merge of schema properties where:
     * - Properties present in both parent and child: child values override parent values
     * - Properties only in parent: included in result
     * - Properties only in child: included in result
     * - For nested properties (objects), performs recursive merge
     *
     * NOTE: This method does NOT enforce Liskov Substitution Principle.
     * Use mergeSchemaPropertiesWithValidation() for extension scenarios.
     *
     * @param array $parentProperties Parent schema properties
     * @param array $childProperties  Child schema properties (overrides)
     *
     * @return array Merged properties array
     */
    private function mergeSchemaProperties(array $parentProperties, array $childProperties): array
    {
        // Start with parent properties as the base.
        $merged = $parentProperties;

        // Apply child properties on top (overriding parent where present).
        foreach ($childProperties as $propertyName => $propertyDefinition) {
            $mergedExists = ($merged[$propertyName] ?? null) !== null;
            $bothArrays   = is_array($propertyDefinition) === true && is_array($merged[$propertyName] ?? null) === true;
            if ($mergedExists === true && $bothArrays === true) {
                // If property exists in both and both are arrays, perform deep merge.
                $merged[$propertyName] = $this->deepMergeProperty(
                    parentProperty: $merged[$propertyName],
                    childProperty: $propertyDefinition
                );
                continue;
            }

            // Otherwise, child property completely replaces parent property.
            $merged[$propertyName] = $propertyDefinition;
        }

        return $merged;
    }//end mergeSchemaProperties()

    /**
     * Merge parent and child schema properties WITH Liskov Substitution validation
     *
     * This method enforces the Liskov Substitution Principle:
     * - Child schemas can ONLY ADD constraints, never relax them
     * - Metadata (title, description, order, icon) CAN be overridden
     * - Validation rules (type, format, enum, pattern, min/max) CANNOT be relaxed
     *
     * Examples of ALLOWED changes:
     * - Adding new properties
     * - Adding more restrictive validation (lower maxLength, higher minLength)
     * - Changing title, description, order (metadata)
     * - Removing enum values (more restrictive)
     *
     * Examples of FORBIDDEN changes:
     * - Changing property type (string to number)
     * - Relaxing validation (higher maxLength, lower minLength)
     * - Adding enum values (less restrictive)
     * - Removing required constraints
     *
     * @param array  $parentProperties Parent schema properties
     * @param array  $childProperties  Child schema properties
     * @param string $schemaId         Schema ID for error messages
     *
     * @throws \Exception If child violates Liskov Substitution Principle
     *
     * @return array Merged properties array
     */
    private function mergeSchemaPropertiesWithValidation(
        array $parentProperties,
        array $childProperties,
        string $schemaId
    ): array {
        // Start with parent properties as the base.
        $merged = $parentProperties;

        // Apply child properties on top with validation.
        foreach ($childProperties as $propertyName => $childProperty) {
            // If property doesn't exist in parent, it's new - allowed.
            if (isset($merged[$propertyName]) === false) {
                $merged[$propertyName] = $childProperty;
                continue;
            }

            $parentProperty = $merged[$propertyName];

            // If both are arrays, perform deep merge with validation.
            if (is_array($parentProperty) === false || is_array($childProperty) === false) {
                // Scalar replacement - validate it doesn't relax constraints.
                $this->validateConstraintAddition(
                    parentProperty: $parentProperty,
                    childProperty: $childProperty,
                    propertyName: $propertyName,
                    schemaId: $schemaId
                );
                $merged[$propertyName] = $childProperty;
                continue;
            }

            $merged[$propertyName] = $this->deepMergePropertyWithValidation(
                parentProperty: $parentProperty,
                childProperty: $childProperty,
                propertyName: $propertyName,
                schemaId: $schemaId
            );
        }//end foreach

        return $merged;
    }//end mergeSchemaPropertiesWithValidation()

    /**
     * Perform deep merge of a single property definition (WITHOUT validation)
     *
     * This method recursively merges property definitions, allowing child schemas
     * to override specific aspects of a property while preserving others.
     *
     * NOTE: This method does NOT enforce Liskov Substitution Principle.
     * Use deepMergePropertyWithValidation() for extension scenarios.
     *
     * Examples:
     * - Parent has 'minLength': 5, child has 'maxLength': 100 -> both are preserved
     * - Parent has 'title': 'Name', child has 'title': 'Full Name' -> child overrides
     * - For nested objects/arrays, performs recursive merge
     *
     * @param array $parentProperty Parent property definition
     * @param array $childProperty  Child property definition (overrides)
     *
     * @return array Merged property definition
     */
    private function deepMergeProperty(array $parentProperty, array $childProperty): array
    {
        $merged = $parentProperty;

        foreach ($childProperty as $key => $value) {
            if (($merged[$key] ?? null) === null || is_array($value) === false || is_array($merged[$key]) === false) {
                // Scalar values: child overrides parent.
                $merged[$key] = $value;
                continue;
            }

            // Recursively merge nested arrays.
            // Special handling for 'properties' and 'items' which need deep merge.
            if ($key !== 'properties' && $key !== 'items') {
                // For other arrays (like enum, required at property level), child replaces parent.
                $merged[$key] = $value;
                continue;
            }

                $merged[$key] = $this->deepMergeProperty(
                    parentProperty: $merged[$key],
                    childProperty: $value
                );
        }

        return $merged;
    }//end deepMergeProperty()

    /**
     * Perform deep merge of a single property WITH Liskov Substitution validation
     *
     * This method enforces that child properties only add constraints, never relax them.
     * Metadata fields (title, description, order, icon, etc.) can be freely overridden.
     * Validation fields (type, format, enum, pattern, min/max, etc.) cannot be relaxed.
     *
     * @param array  $parentProperty Parent property definition
     * @param array  $childProperty  Child property definition
     * @param string $propertyName   Property name for error messages
     * @param string $schemaId       Schema ID for error messages
     *
     * @throws \Exception If child violates Liskov Substitution Principle
     *
     * @return array Merged property definition
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function deepMergePropertyWithValidation(
        array $parentProperty,
        array $childProperty,
        string $propertyName,
        string $schemaId
    ): array {
        // List of metadata fields that can be freely overridden.
        $metadataFields = [
            'title',
            'description',
            'order',
            'icon',
            'placeholder',
            'help',
            'example',
            'examples',
            '$comment',
            'deprecated',
            'readOnly',
            'writeOnly',
            'default',
            'x-order',
            'x-display',
            'x-tabName',
            'x-section',
            'ui:order',
            'ui:widget',
            'ui:options',
        ];

        // List of validation fields that require constraint checking.
        $validationFields = [
            'type',
            'format',
            'pattern',
            'enum',
            'const',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'minLength',
            'maxLength',
            'minItems',
            'maxItems',
            'minProperties',
            'maxProperties',
            'multipleOf',
            'uniqueItems',
            'required',
            'additionalProperties',
            'patternProperties',
            'dependencies',
            'if',
            'then',
            'else',
        ];

        $merged = $parentProperty;

        foreach ($childProperty as $key => $childValue) {
            // If key doesn't exist in parent, it's new - allowed.
            if (isset($merged[$key]) === false) {
                $merged[$key] = $childValue;
                continue;
            }

            $parentValue = $merged[$key];

            // Metadata fields can be freely overridden.
            if (in_array($key, $metadataFields) === true) {
                $merged[$key] = $childValue;
                continue;
            }

            // Special handling for nested properties and items.
            $isNestedKey   = ($key === 'properties' || $key === 'items') === true;
            $bothAreArrays = is_array($childValue) === true && is_array($parentValue) === true;
            if ($isNestedKey === true && $bothAreArrays === true) {
                // Recursively validate nested properties.
                $mergedNested = [];
                foreach ($childValue as $nestedKey => $nestedChild) {
                    if (($parentValue[$nestedKey] ?? null) === null) {
                        // New nested property - allowed.
                        $mergedNested[$nestedKey] = $nestedChild;
                        continue;
                    }

                    $mergedNested[$nestedKey] = $this->deepMergePropertyWithValidation(
                        parentProperty: $parentValue[$nestedKey],
                        childProperty: $nestedChild,
                        propertyName: "{$propertyName}.{$key}.{$nestedKey}",
                        schemaId: $schemaId
                    );
                }

                // Include parent nested properties not in child.
                foreach ($parentValue as $nestedKey => $nestedParent) {
                    if (isset($mergedNested[$nestedKey]) === false) {
                        $mergedNested[$nestedKey] = $nestedParent;
                    }
                }

                $merged[$key] = $mergedNested;
                continue;
            }//end if

            // Validation fields require constraint checking.
            if (in_array($key, $validationFields) === true) {
                $this->validateConstraintChange(
                    parentValue: $parentValue,
                    childValue: $childValue,
                    constraint: $key,
                    propertyName: $propertyName,
                    schemaId: $schemaId
                );
                $merged[$key] = $childValue;
                continue;
            }

            // For other fields, perform standard merge.
            if (is_array($parentValue) === false || is_array($childValue) === false) {
                $merged[$key] = $childValue;
                continue;
            }

            $merged[$key] = $this->deepMergePropertyWithValidation(
                parentProperty: $parentValue,
                childProperty: $childValue,
                propertyName: "{$propertyName}.{$key}",
                schemaId: $schemaId
            );
        }//end foreach

        return $merged;
    }//end deepMergePropertyWithValidation()

    /**
     * Validate that a constraint change does not relax validation
     *
     * Enforces Liskov Substitution Principle for constraint modifications.
     *
     * @param mixed  $parentValue  Parent constraint value
     * @param mixed  $childValue   Child constraint value
     * @param string $constraint   Constraint name
     * @param string $propertyName Property name for error messages
     * @param string $schemaId     Schema ID for error messages
     *
     * @throws \Exception If constraint is relaxed
     *
     * @return void
     */
    private function validateConstraintChange(
        mixed $parentValue,
        mixed $childValue,
        string $constraint,
        string $propertyName,
        string $schemaId
    ): void {
        if ($constraint === 'type') {
            $this->validateTypeConstraint(
                parentValue: $parentValue,
                childValue: $childValue,
                propertyName: $propertyName,
                schemaId: $schemaId
            );
            return;
        }

        if ($constraint === 'format') {
            $this->validateFormatConstraint(
                parentValue: $parentValue,
                childValue: $childValue,
                propertyName: $propertyName,
                schemaId: $schemaId
            );
            return;
        }

        if ($constraint === 'enum') {
            $this->validateEnumConstraint(
                parentValue: $parentValue,
                childValue: $childValue,
                propertyName: $propertyName,
                schemaId: $schemaId
            );
            return;
        }

        if ($this->isMinimumConstraint(constraint: $constraint) === true) {
            $this->validateMinimumConstraint(
                parentValue: $parentValue,
                childValue: $childValue,
                constraint: $constraint,
                propertyName: $propertyName,
                schemaId: $schemaId
            );
            return;
        }

        if ($this->isMaximumConstraint(constraint: $constraint) === true) {
            $this->validateMaximumConstraint(
                parentValue: $parentValue,
                childValue: $childValue,
                constraint: $constraint,
                propertyName: $propertyName,
                schemaId: $schemaId
            );
            return;
        }

        if ($constraint === 'pattern') {
            $this->validatePatternConstraint(
                parentValue: $parentValue,
                childValue: $childValue,
                propertyName: $propertyName,
                schemaId: $schemaId
            );
        }
    }//end validateConstraintChange()

    /**
     * Check if constraint is a minimum constraint
     *
     * @param string $constraint Constraint name
     *
     * @return bool True if minimum constraint
     */
    private function isMinimumConstraint(string $constraint): bool
    {
        return in_array($constraint, ['minimum', 'minLength', 'minItems', 'minProperties'], true);
    }//end isMinimumConstraint()

    /**
     * Check if constraint is a maximum constraint
     *
     * @param string $constraint Constraint name
     *
     * @return bool True if maximum constraint
     */
    private function isMaximumConstraint(string $constraint): bool
    {
        return in_array($constraint, ['maximum', 'maxLength', 'maxItems', 'maxProperties'], true);
    }//end isMaximumConstraint()

    /**
     * Validate type constraint change
     *
     * @param mixed  $parentValue  Parent type value
     * @param mixed  $childValue   Child type value
     * @param string $propertyName Property name
     * @param string $schemaId     Schema ID
     *
     * @throws \Exception If type change is invalid
     *
     * @return void
     */
    private function validateTypeConstraint(
        mixed $parentValue,
        mixed $childValue,
        string $propertyName,
        string $schemaId
    ): void {
        if ($parentValue === $childValue) {
            return;
        }

        if (is_array($parentValue) === true && is_array($childValue) === true) {
            $diff = array_diff($childValue, $parentValue);
            if (count($diff) > 0) {
                $parentJson = json_encode($parentValue);
                $childJson  = json_encode($childValue);
                $message    = sprintf(
                    "Schema '%s': Property '%s' cannot change type from %s to %s (adds types not in parent)",
                    $schemaId,
                    $propertyName,
                    $parentJson,
                    $childJson
                );
                throw new Exception($message);
            }

            return;
        }

        if (is_array($parentValue) === false && is_array($childValue) === false) {
            $msg = sprintf(
                "Schema '%s': Property '%s' cannot change type from '%s' to '%s'",
                $schemaId,
                $propertyName,
                $parentValue,
                $childValue
            );
            throw new Exception($msg);
        }

        throw new Exception("Schema '{$schemaId}': Property '{$propertyName}' type change is not compatible");
    }//end validateTypeConstraint()

    /**
     * Validate format constraint change
     *
     * @param mixed  $parentValue  Parent format value
     * @param mixed  $childValue   Child format value
     * @param string $propertyName Property name
     * @param string $schemaId     Schema ID
     *
     * @throws \Exception If format change is invalid
     *
     * @return void
     */
    private function validateFormatConstraint(
        mixed $parentValue,
        mixed $childValue,
        string $propertyName,
        string $schemaId
    ): void {
        if ($parentValue !== null && $parentValue !== $childValue) {
            $msg = sprintf(
                "Schema '%s': Property '%s' cannot change format from '%s' to '%s'",
                $schemaId,
                $propertyName,
                $parentValue,
                $childValue
            );
            throw new Exception($msg);
        }
    }//end validateFormatConstraint()

    /**
     * Validate enum constraint change
     *
     * @param mixed  $parentValue  Parent enum value
     * @param mixed  $childValue   Child enum value
     * @param string $propertyName Property name
     * @param string $schemaId     Schema ID
     *
     * @throws \Exception If enum change is invalid
     *
     * @return void
     */
    private function validateEnumConstraint(
        mixed $parentValue,
        mixed $childValue,
        string $propertyName,
        string $schemaId
    ): void {
        if (is_array($parentValue) === false || is_array($childValue) === false) {
            return;
        }

        $diff = array_diff($childValue, $parentValue);
        if (count($diff) > 0) {
            $msg = sprintf(
                "Schema '%s': Property '%s' enum cannot add values not in parent (added: %s)",
                $schemaId,
                $propertyName,
                json_encode($diff)
            );
            throw new Exception($msg);
        }
    }//end validateEnumConstraint()

    /**
     * Validate minimum constraint change
     *
     * @param mixed  $parentValue  Parent minimum value
     * @param mixed  $childValue   Child minimum value
     * @param string $constraint   Constraint name
     * @param string $propertyName Property name
     * @param string $schemaId     Schema ID
     *
     * @throws \Exception If minimum constraint is relaxed
     *
     * @return void
     */
    private function validateMinimumConstraint(
        mixed $parentValue,
        mixed $childValue,
        string $constraint,
        string $propertyName,
        string $schemaId
    ): void {
        if (is_numeric($parentValue) === false || is_numeric($childValue) === false) {
            return;
        }

        if ($childValue < $parentValue) {
            $message = sprintf(
                "Schema '%s': Property '%s' %s cannot be decreased from %s to %s (relaxes constraint)",
                $schemaId,
                $propertyName,
                $constraint,
                $parentValue,
                $childValue
            );
            throw new Exception($message);
        }
    }//end validateMinimumConstraint()

    /**
     * Validate maximum constraint change
     *
     * @param mixed  $parentValue  Parent maximum value
     * @param mixed  $childValue   Child maximum value
     * @param string $constraint   Constraint name
     * @param string $propertyName Property name
     * @param string $schemaId     Schema ID
     *
     * @throws \Exception If maximum constraint is relaxed
     *
     * @return void
     */
    private function validateMaximumConstraint(
        mixed $parentValue,
        mixed $childValue,
        string $constraint,
        string $propertyName,
        string $schemaId
    ): void {
        if (is_numeric($parentValue) === false || is_numeric($childValue) === false) {
            return;
        }

        if ($childValue > $parentValue) {
            $message = sprintf(
                "Schema '%s': Property '%s' %s cannot be increased from %s to %s (relaxes constraint)",
                $schemaId,
                $propertyName,
                $constraint,
                $parentValue,
                $childValue
            );
            throw new Exception($message);
        }
    }//end validateMaximumConstraint()

    /**
     * Validate pattern constraint change
     *
     * @param mixed  $parentValue  Parent pattern value
     * @param mixed  $childValue   Child pattern value
     * @param string $propertyName Property name
     * @param string $schemaId     Schema ID
     *
     * @throws \Exception If pattern change is invalid
     *
     * @return void
     */
    private function validatePatternConstraint(
        mixed $parentValue,
        mixed $childValue,
        string $propertyName,
        string $schemaId
    ): void {
        if ($parentValue !== null && $parentValue !== $childValue) {
            $msg = sprintf(
                "Schema '%s': Property '%s' pattern cannot be changed from '%s' to '%s'",
                $schemaId,
                $propertyName,
                $parentValue,
                $childValue
            );
            throw new Exception($msg);
        }
    }//end validatePatternConstraint()

    /**
     * Validate that replacing a property doesn't relax constraints
     *
     * Used when entire property is replaced (not merged).
     *
     * @param mixed  $parentProperty Parent property value
     * @param mixed  $childProperty  Child property value
     * @param string $propertyName   Property name for error messages
     * @param string $schemaId       Schema ID for error messages
     *
     * @throws \Exception If constraint is relaxed
     *
     * @return void
     */
    private function validateConstraintAddition(
        mixed $parentProperty,
        mixed $childProperty,
        string $propertyName,
        string $schemaId
    ): void {
        // If parent had validation and child removes it, that's relaxing.
        if (empty($parentProperty) === false && empty($childProperty) === true) {
            $msg = sprintf(
                "Schema '%s': Property '%s' cannot remove constraints (parent had value, child is empty)",
                $schemaId,
                $propertyName
            );
            throw new Exception($msg);
        }
    }//end validateConstraintAddition()

    /**
     * Extract the delta (differences) between parent schemas and child schema properties
     *
     * This method is called before saving a schema that uses composition.
     * It removes any properties that are identical to the parent(s), keeping only
     * the differences (delta) in the child schema. This ensures we only store
     * what's actually changed, making schema composition more maintainable.
     *
     * Supports:
     * - allOf: Extracts delta against all parent schemas (merged)
     * - oneOf/anyOf: No delta extraction (properties not merged)
     *
     * @param Schema $schema The schema to extract delta from
     *
     * @throws \Exception If parent schema cannot be loaded
     *
     * @return Schema The schema with only delta properties
     */
    private function extractSchemaDelta(Schema $schema): Schema
    {
        // Get composition patterns.
        $allOf = $schema->getAllOf();
        $oneOf = $schema->getOneOf();
        $anyOf = $schema->getAnyOf();

        // For oneOf and anyOf, no delta extraction (properties not merged).
        if (($oneOf !== null && count($oneOf) > 0)
            || ($anyOf !== null && count($anyOf) > 0)
        ) {
            return $schema;
        }

        // For allOf, extract delta against all parents.
        if ($allOf !== null && count($allOf) > 0) {
            return $this->extractAllOfDelta(
                schema: $schema,
                allOf: $allOf
            );
        }

        // No composition - return as-is.
        return $schema;
    }//end extractSchemaDelta()

    /**
     * Extract delta for allOf composition (multiple parents)
     *
     * Merges all parent schemas and extracts only the differences
     * in the child schema.
     *
     * @param Schema $schema The child schema
     * @param array  $allOf  Array of parent schema identifiers
     *
     * @throws \Exception If parent schema not found
     *
     * @return Schema Schema with only delta properties
     */
    private function extractAllOfDelta(Schema $schema, array $allOf): Schema
    {
        try {
            // Start with empty merged parent properties.
            $mergedParentProps    = [];
            $mergedParentRequired = [];

            // Load and merge all parent schemas.
            foreach ($allOf as $parentRef) {
                // Skip empty or null references.
                if (empty($parentRef) === true) {
                    continue;
                }

                $parentSchema = $this->loadSchema(identifier: $parentRef);

                // Recursively resolve parent to get its full properties.
                if ($parentSchema->getAllOf() !== null) {
                    $parentSchema = $this->resolveSchemaExtension(schema: $parentSchema);
                }

                // Merge this parent's properties into the accumulated parent properties.
                $mergedParentProps = $this->mergeSchemaProperties(
                    parentProperties: $mergedParentProps,
                    childProperties: $parentSchema->getProperties()
                );

                    // Merge required fields.
                    $mergedParentRequired = array_unique(
                        array_merge($mergedParentRequired, $parentSchema->getRequired())
                    );
            }//end foreach

            // Extract only the properties that differ from merged parents.
            $deltaProperties = $this->extractPropertyDelta(
                parentProperties: $mergedParentProps,
                childProperties: $schema->getProperties()
            );

            // Extract only the required fields that differ from merged parents.
            $deltaRequired = array_diff(
                $schema->getRequired(),
                $mergedParentRequired
            );

            // Update the schema with delta only.
            $schema->setProperties($deltaProperties);
            $schema->setRequired(array_values($deltaRequired));
            // Re-index array.
            return $schema;
        } catch (Exception $e) {
            // If a parent schema doesn't exist yet (e.g. during import Pass 1),
            // return the schema without delta extraction. The full properties are
            // preserved and delta will be correctly extracted during Pass 2 (update)
            // when all schemas exist in the database.
            return $schema;
        }//end try
    }//end extractAllOfDelta()

    /**
     * Extract properties that differ from parent
     *
     * This method compares child properties with parent properties and returns
     * only the properties that are new or different.
     *
     * @param array $parentProperties Parent schema properties
     * @param array $childProperties  Child schema properties
     *
     * @return array Properties that differ from parent (delta)
     */
    private function extractPropertyDelta(array $parentProperties, array $childProperties): array
    {
        $delta = [];

        foreach ($childProperties as $propertyName => $childProperty) {
            // If property doesn't exist in parent, it's new - include in delta.
            if (isset($parentProperties[$propertyName]) === false) {
                $delta[$propertyName] = $childProperty;
                continue;
            }

            // If property exists in parent, check if it's different.
            $parentProperty = $parentProperties[$propertyName];

            // Deep comparison: if properties are different, include in delta.
            if ($this->arePropertiesDifferent(
                    parentProperty: $parentProperty,
                    childProperty: $childProperty
                ) === true
            ) {
                // For objects with nested properties, extract nested delta.
                if (is_array($childProperty) === false || is_array($parentProperty) === false) {
                    $delta[$propertyName] = $childProperty;
                    continue;
                }

                $delta[$propertyName] = $this->extractNestedPropertyDelta(
                    parentProperty: $parentProperty,
                    childProperty: $childProperty
                );
            }

            // If properties are identical, don't include in delta.
        }//end foreach

        return $delta;
    }//end extractPropertyDelta()

    /**
     * Check if two property definitions are different
     *
     * Performs deep comparison of property definitions to determine if they differ.
     *
     * @param mixed $parentProperty Parent property definition
     * @param mixed $childProperty  Child property definition
     *
     * @return bool True if properties are different
     */
    private function arePropertiesDifferent($parentProperty, $childProperty): bool
    {
        // Use JSON encoding for deep comparison.
        // This handles arrays, nested objects, and scalar values uniformly.
        return json_encode($parentProperty) !== json_encode($childProperty);
    }//end arePropertiesDifferent()

    /**
     * Extract nested property delta for object properties
     *
     * When a property is an object with nested properties, extract only
     * the nested properties that differ from the parent.
     *
     * @param array $parentProperty Parent property definition
     * @param array $childProperty  Child property definition
     *
     * @return array Property definition with only delta fields
     */
    private function extractNestedPropertyDelta(array $parentProperty, array $childProperty): array
    {
        $delta = [];

        foreach ($childProperty as $key => $value) {
            if (isset($parentProperty[$key]) === false) {
                // New field in child.
                $delta[$key] = $value;
            } else if ($this->arePropertiesDifferent(
                    parentProperty: $parentProperty[$key],
                    childProperty: $value
                ) === true
            ) {
                // Changed field.
                if ($key !== 'properties' || is_array($value) === false || is_array($parentProperty[$key]) === false) {
                    $delta[$key] = $value;
                    continue;
                }

                // Recursively extract delta for nested properties.
                $delta[$key] = $this->extractPropertyDelta(
                    parentProperties: $parentProperty[$key],
                    childProperties: $value
                );
            }//end if
        }//end foreach

        return $delta;
    }//end extractNestedPropertyDelta()

    /**
     * Find schemas that compose with a given schema
     *
     * Returns an array of schema UUIDs for schemas that reference the given schema
     * in their allOf, oneOf, or anyOf composition patterns.
     *
     * @param int|string  $schemaIdentifier The ID, UUID, or slug of the schema.
     * @param string|null $knownUuid        Pre-known UUID to avoid redundant lookups.
     * @param string|null $knownSlug        Pre-known slug to avoid redundant lookups.
     *
     * @return array Array of schema UUIDs that compose with this schema
     *
     * @psalm-return list{0?: mixed,...}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)      Schema composition search requires many conditional checks
     */
    public function findExtendedBy(int|string $schemaIdentifier, ?string $knownUuid=null, ?string $knownSlug=null): array
    {
        // Use pre-known values when available to avoid a redundant find() query per schema.
        $targetId   = (string) $schemaIdentifier;
        $targetUuid = $knownUuid;
        $targetSlug = $knownSlug;

        if ($knownUuid === null && $knownSlug === null) {
            // Fallback: fetch the schema to get all its identifiers.
            try {
                $targetSchema = $this->find(id: $schemaIdentifier);
            } catch (Exception $e) {
                return [];
            }

            $targetId   = (string) $targetSchema->getId();
            $targetUuid = $targetSchema->getUuid();
            $targetSlug = $targetSchema->getSlug();
        }

        // Build query to find schemas that reference this schema in composition.
        $qb = $this->db->getQueryBuilder();
        $qb->select('uuid')
            ->from($this->getTableName());

        // Add conditions for all possible ways to reference the schema.
        $orConditions = [];

        // Check in allOf field (JSON array).
        if ($targetId !== '') {
            $orConditions[] = $qb->expr()->like('all_of', $qb->createNamedParameter('%"'.$targetId.'"%'));
        }

        if ($targetUuid !== null && $targetUuid !== '') {
            $orConditions[] = $qb->expr()->like('all_of', $qb->createNamedParameter('%"'.$targetUuid.'"%'));
        }

        if ($targetSlug !== null && $targetSlug !== '') {
            $orConditions[] = $qb->expr()->like('all_of', $qb->createNamedParameter('%"'.$targetSlug.'"%'));
        }

        // Check in oneOf field (JSON array).
        if ($targetId !== '') {
            $orConditions[] = $qb->expr()->like('one_of', $qb->createNamedParameter('%"'.$targetId.'"%'));
        }

        if ($targetUuid !== null && $targetUuid !== '') {
            $orConditions[] = $qb->expr()->like('one_of', $qb->createNamedParameter('%"'.$targetUuid.'"%'));
        }

        if ($targetSlug !== null && $targetSlug !== '') {
            $orConditions[] = $qb->expr()->like('one_of', $qb->createNamedParameter('%"'.$targetSlug.'"%'));
        }

        // Check in anyOf field (JSON array).
        // Note: $targetId is cast to (string), so it can never be null, only empty string.
        if ($targetId !== '') {
            $orConditions[] = $qb->expr()->like('any_of', $qb->createNamedParameter('%"'.$targetId.'"%'));
        }

        if ($targetUuid !== null && $targetUuid !== '') {
            $orConditions[] = $qb->expr()->like('any_of', $qb->createNamedParameter('%"'.$targetUuid.'"%'));
        }

        if ($targetSlug !== null && $targetSlug !== '') {
            $orConditions[] = $qb->expr()->like('any_of', $qb->createNamedParameter('%"'.$targetSlug.'"%'));
        }

        if (empty($orConditions) === true) {
            return [];
        }

        $qb->where($qb->expr()->orX(...$orConditions));

        $result = $qb->executeQuery();
        $uuids  = [];

        while (($row = $result->fetch()) !== false) {
            if (($row['uuid'] ?? null) !== null) {
                $uuids[] = $row['uuid'];
            }
        }

        $result->closeCursor();

        return $uuids;
    }//end findExtendedBy()

    /**
     * Find all schema extension relationships in a single query
     *
     * Scans all schemas for allOf/oneOf/anyOf references and builds a reverse map
     * of which schemas extend which. Replaces N individual findExtendedBy() calls
     * with 1 query.
     *
     * @return array<int, string[]> Map of targetSchemaId => [extendingSchemaUuid, ...]
     */
    public function findAllExtendedBy(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'uuid', 'slug', 'all_of', 'one_of', 'any_of')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->isNotNull('all_of'),
                    $qb->expr()->isNotNull('one_of'),
                    $qb->expr()->isNotNull('any_of')
                )
            );

        $result = $qb->executeQuery();

        // Build lookup of all schemas by id/uuid/slug for resolving references.
        // We need a second query to get all schemas for reference resolution.
        $allSchemasQb = $this->db->getQueryBuilder();
        $allSchemasQb->select('id', 'uuid', 'slug')
            ->from($this->getTableName());
        $allSchemasResult = $allSchemasQb->executeQuery();

        $schemaLookup = [];
        while (($row = $allSchemasResult->fetch()) !== false) {
            $schemaLookup[(string) $row['id']] = (int) $row['id'];
            if (($row['uuid'] ?? null) !== null) {
                $schemaLookup[$row['uuid']] = (int) $row['id'];
            }

            if (($row['slug'] ?? null) !== null) {
                $schemaLookup[$row['slug']] = (int) $row['id'];
            }
        }

        $allSchemasResult->closeCursor();

        // Build the reverse map: targetSchemaId => [extendingSchemaUuid, ...].
        $extendedByMap = [];

        while (($row = $result->fetch()) !== false) {
            $extendingUuid = $row['uuid'] ?? null;
            if ($extendingUuid === null) {
                continue;
            }

            // Collect all referenced identifiers from allOf, oneOf, anyOf.
            $references = [];
            foreach (['all_of', 'one_of', 'any_of'] as $field) {
                $value = $row[$field] ?? null;
                if ($value === null) {
                    continue;
                }

                $decoded = json_decode(json: $value, associative: true);
                if (is_array($decoded) === true) {
                    $references = array_merge($references, $decoded);
                }
            }

            // Resolve each reference to a schema ID and add to reverse map.
            foreach ($references as $ref) {
                $ref = (string) $ref;
                if (isset($schemaLookup[$ref]) === true) {
                    $targetId = $schemaLookup[$ref];
                    $extendedByMap[$targetId][] = $extendingUuid;
                }
            }
        }//end while

        $result->closeCursor();

        // Deduplicate UUIDs per target schema.
        foreach ($extendedByMap as $targetId => $uuids) {
            $extendedByMap[$targetId] = array_values(array_unique($uuids));
        }

        return $extendedByMap;
    }//end findAllExtendedBy()

    /**
     * Return the IDs of all schemas whose `searchable` flag is false.
     *
     * Used by the unified search provider to exclude opted-out schemas from
     * Nextcloud unified search inside the query (rather than post-filtering a
     * result page, which would leak counts and break pagination). The lookup
     * is intentionally RBAC/tenant-agnostic: it only answers "which schemas
     * declared themselves non-searchable", the access filtering happens in the
     * object search itself.
     *
     * @return int[] List of schema IDs with `searchable = false`.
     *
     * @psalm-return   list<int>
     * @phpstan-return array<int, int>
     *
     * @spec openspec/specs/unified-search-provider/spec.md
     */
    public function findNonSearchableIds(): array
    {
        $this->traceRead(method: 'findNonSearchableIds');

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_schemas')
            ->where($qb->expr()->eq('searchable', $qb->createNamedParameter(value: false, type: IQueryBuilder::PARAM_BOOL)));

        $ids    = [];
        $result = $qb->executeQuery();
        while (($row = $result->fetch()) !== false) {
            if (isset($row['id']) === true) {
                $ids[] = (int) $row['id'];
            }
        }

        $result->closeCursor();

        return $ids;
    }//end findNonSearchableIds()

    /**
     * Return the IDs of all schemas whose `searchable` flag is true (default).
     *
     * The unified search provider passes this allow-list as the `@self.schema`
     * IN-filter so that only searchable schemas contribute results, applied
     * inside the query.
     *
     * @return int[] List of schema IDs with `searchable = true`.
     *
     * @psalm-return   list<int>
     * @phpstan-return array<int, int>
     *
     * @spec openspec/specs/unified-search-provider/spec.md
     */
    public function findSearchableIds(): array
    {
        $this->traceRead(method: 'findSearchableIds');

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_schemas')
            ->where($qb->expr()->eq('searchable', $qb->createNamedParameter(value: true, type: IQueryBuilder::PARAM_BOOL)));

        $ids    = [];
        $result = $qb->executeQuery();
        while (($row = $result->fetch()) !== false) {
            if (isset($row['id']) === true) {
                $ids[] = (int) $row['id'];
            }
        }

        $result->closeCursor();

        return $ids;
    }//end findSearchableIds()
}//end class
