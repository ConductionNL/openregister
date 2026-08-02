<?php

/**
 * MagicMapper Search Handler
 *
 * This handler provides advanced search capabilities for dynamic schema-based tables.
 * It implements sophisticated search functionality including metadata filtering,
 * object field searching, full-text search, and complex query building for
 * dynamically created tables.
 *
 * KEY RESPONSIBILITIES:
 * - Dynamic table search operations
 * - Metadata and object field filtering
 * - Full-text search within dynamic tables
 * - Query optimization for schema-specific tables
 * - Integration with ObjectEntity conversion
 *
 * SEARCH CAPABILITIES:
 * - Metadata searches (register, schema, owner, organization, etc.)
 * - Object field searches (JSON property searches)
 * - Combined searches with complex boolean logic
 * - Optimized counting and sizing operations
 * - Support for pagination and sorting
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper search capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Exception;
use RuntimeException;
use DateTime;

/**
 * Dynamic table search handler for MagicMapper
 *
 * This class provides comprehensive search functionality for dynamically created
 * schema-based tables, optimized for schema-specific table structures.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Search handler requires many specialized query building methods
 * @SuppressWarnings(PHPMD.TooManyMethods)           Search requires per-operator and per-type conversion methods
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Search handler bridges schema, register, and query builder layers
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class MagicSearchHandler
{

    /**
     * Tracks filter properties that don't exist in the schema during search.
     * Reset at the start of each searchObjects call.
     *
     * @var array<string>
     */
    private array $ignoredFilters = [];

    /**
     * Cached result of pg_trgm extension availability check.
     *
     * @var boolean|null
     */
    private ?bool $hasPgTrgm = null;

    /**
     * PERF-4: request-scoped memo of the per-schema property-type and
     * column-to-property maps, keyed by schema id. Building these ran
     * sanitizeColumnName for every property on every row; memoising it makes
     * it once-per-schema instead of once-per-row.
     *
     * @var array<int, array{types: array<string, string>, columns: array<string, string>}>
     */
    private array $schemaColumnMapCache = [];

    /**
     * Constructor for MagicSearchHandler
     *
     * @param IDBConnection            $db                  Database connection for queries
     * @param LoggerInterface          $logger              Logger for debugging and error reporting
     * @param MagicRbacHandler         $rbacHandler         RBAC handler for access control
     * @param MagicOrganizationHandler $organizationHandler Organization handler for multi-tenancy
     * @param SchemaTypeConverter      $schemaTypeConverter Schema-driven type converter for row values
     * @param DateTimeNormalizer       $dateTimeNormalizer  Normaliser for date/date-time property formats
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly MagicRbacHandler $rbacHandler,
        private readonly MagicOrganizationHandler $organizationHandler,
        private readonly SchemaTypeConverter $schemaTypeConverter,
        private readonly DateTimeNormalizer $dateTimeNormalizer
    ) {
    }//end __construct()

    /**
     * Check if PostgreSQL pg_trgm extension is available for fuzzy search.
     *
     * This extension provides the similarity() function and % operator
     * for fuzzy text searching. Result is cached for the request lifetime.
     *
     * @return bool True if pg_trgm is available, false otherwise.
     */
    public function hasPgTrgmExtension(): bool
    {
        // Return cached result if available.
        if ($this->hasPgTrgm !== null) {
            return $this->hasPgTrgm;
        }

        // Not PostgreSQL = no pg_trgm.
        if ($this->isPostgresPlatform() === false) {
            $this->hasPgTrgm = false;
            return false;
        }

        // Check if pg_trgm extension is installed.
        try {
            $stmt            = $this->db->prepare("SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_trgm'");
            $result          = $stmt->execute();
            $count           = (int) $result->fetchOne();
            $this->hasPgTrgm = $count > 0;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[MagicSearchHandler] Failed to check pg_trgm extension availability',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            $this->hasPgTrgm = false;
        }

        return $this->hasPgTrgm;
    }//end hasPgTrgmExtension()

    /**
     * Get the list of filter properties that were ignored during the last search.
     *
     * These are properties that were requested as filters but don't exist in the schema.
     *
     * @return array<string> List of ignored filter property names
     */
    public function getIgnoredFilters(): array
    {
        return $this->ignoredFilters;
    }//end getIgnoredFilters()

    /**
     * Search objects in a specific register-schema table using clean query structure
     *
     * This method provides comprehensive search capabilities optimized for
     * schema-specific dynamic tables.
     *
     * @param array    $query     Search query array with filters and options
     * @param Register $register  Register context for the search
     * @param Schema   $schema    Schema context for the search
     * @param string   $tableName Target dynamic table name
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int Array of ObjectEntity objects or count if _count=true
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @psalm-return int|list<ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function searchObjects(array $query, Register $register, Schema $schema, string $tableName): array|int
    {
        // Reset ignored filters tracking for this search.
        $this->ignoredFilters = [];

        // Extract options from query (prefixed with _).
        $limit  = $query['_limit'] ?? null;
        $offset = $query['_offset'] ?? null;
        $page   = $query['_page'] ?? null;
        $order  = $query['_order'] ?? [];
        // The _order parameter may arrive as a JSON string from URL query params.
        if (is_string($order) === true) {
            $decoded = json_decode($order, true);
            $order   = [];
            if (is_array($decoded) === true) {
                $order = $decoded;
            }
        }

        $count  = $query['_count'] ?? false;
        $search = $query['_search'] ?? null;

        // Convert page to offset if page is provided but offset is not.
        // Page is 1-indexed, so page 1 = offset 0, page 2 = offset $limit, etc.
        if ($page !== null && $offset === null && $limit !== null) {
            $offset = ((int) $page - 1) * (int) $limit;
        }

        // Build filtered query (applies all WHERE conditions).
        $queryBuilder = $this->buildFilteredQuery(
            query: $query,
            schema: $schema,
            tableName: $tableName
        );

        // Check if fuzzy search is enabled for relevance scoring.
        $fuzzyEnabled = false;
        $searchTerm   = null;
        if ($search !== null && trim($search) !== '') {
            $searchTerm = trim($search);
        }

        $fuzzyParam = $query['_fuzzy'] ?? null;
        if ($fuzzyParam === true || $fuzzyParam === 'true' || $fuzzyParam === '1' || $fuzzyParam === 1) {
            $fuzzyEnabled = $this->hasPgTrgmExtension();
        }

        // Add SELECT clause based on count vs search.
        if ($count === true) {
            $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'count');
            $result = $queryBuilder->executeQuery();
            return (int) $result->fetchOne();
        }

        $queryBuilder->select('t.*');

        // Add relevance score column when fuzzy search is enabled.
        // This allows us to return the similarity score as a percentage in @self.relevance.
        if ($fuzzyEnabled === true && $searchTerm !== null) {
            $searchTermParam = $queryBuilder->createNamedParameter($searchTerm);
            $queryBuilder->addSelect(
                $queryBuilder->createFunction(
                    'ROUND(similarity(t._name::text, '."{$searchTermParam}) * 100)::integer AS _relevance"
                )
            );
        }

        // Apply sorting BEFORE pagination so the query optimizer can use
        // indexes for ORDER BY … LIMIT instead of sorting the full result set.
        if (empty($order) === false) {
            $this->applySorting(qb: $queryBuilder, order: $order, schema: $schema, searchTerm: $searchTerm);
        } else {
            // BUG-DB-4: without an explicit order, LIMIT/OFFSET pagination is
            // non-deterministic (the database may return rows in any order),
            // causing duplicates/gaps across pages. Add a stable default order
            // on the monotonically increasing primary key.
            $queryBuilder->addOrderBy('t._id', 'ASC');
        }

        $queryBuilder->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->executeSearchQuery(qb: $queryBuilder, register: $register, schema: $schema, tableName: $tableName);
    }//end searchObjects()

    /**
     * Apply the SAME multitenancy + RBAC access-control filters used by the
     * list/search path to an arbitrary query builder.
     *
     * The single-object read path (MagicMapper::findInRegisterSchemaTable)
     * historically skipped org/RBAC filtering entirely, creating a cross-org
     * IDOR: a non-admin in org B could read an org-A object by id even though
     * the list path correctly hid it. This method exposes the search path's
     * access-control logic so the single-object read can enforce the exact
     * same isolation, returning no row (→ 404, no existence leak) for objects
     * the caller may not see.
     *
     * The query builder MUST already use the table alias `t` in its FROM clause,
     * because the underlying org/RBAC conditions reference `t._organisation`,
     * `t._owner`, etc.
     *
     * Public-published reads are preserved: resolveMultitenancyFlag() drops the
     * org filter for schemas that grant `public` read, and the RBAC filter then
     * grants the read — matching the list path exactly.
     *
     * @param IQueryBuilder $qb            Query builder (must use FROM alias `t`).
     * @param Schema        $schema        Schema for access-control rules.
     * @param bool          $_rbac         Whether to apply RBAC filtering.
     * @param bool          $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags mirror the search-path posture.
     */
    public function applyAccessControlToQuery(
        IQueryBuilder $qb,
        Schema $schema,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): void {
        // Mirror the list path: public schemas bypass multitenancy by default.
        // No explicit multitenancy request exists on the single-object read path,
        // so multitenancyExplicit is always false here.
        $resolvedMultitenancy = $this->resolveMultitenancyFlag(
            _multitenancy: $_multitenancy,
            multitenancyExplicit: false,
            schema: $schema
        );

        $this->applyAccessControlFilters(
            qb: $qb,
            schema: $schema,
            _rbac: $_rbac,
            _multitenancy: $resolvedMultitenancy,
            multitenancyExplicit: false
        );
    }//end applyAccessControlToQuery()

    /**
     * Build a filtered query with all WHERE conditions applied.
     *
     * This is the SINGLE SOURCE OF TRUTH for query filtering. Used by:
     * - searchObjects() for search results
     * - searchObjects() with _count=true for counting
     * - getFacetQuery() for facet aggregations
     *
     * Returns a QueryBuilder with FROM and WHERE clauses, but NO SELECT.
     * Caller must add SELECT clause based on their needs.
     *
     * @param array  $query     Search parameters including filters.
     * @param Schema $schema    The schema for property filtering.
     * @param string $tableName The table to query.
     *
     * @return IQueryBuilder QueryBuilder with all filters applied.
     */
    public function buildFilteredQuery(array $query, Schema $schema, string $tableName): IQueryBuilder
    {
        // Extract options from query (prefixed with _).
        $search = $query['_search'] ?? null;
        // Coerce to bool: query-string params arrive as strings (e.g.
        // "_includeDeleted=true" → "true"). applyBasicFilters() is bool-typed
        // under strict_types, so passing the raw string raised a TypeError →
        // HTTP 500 on any list call with _includeDeleted=true.
        $includeDeleted = filter_var($query['_includeDeleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $ids            = $query['_ids'] ?? null;
        $_rbac          = $query['_rbac'] ?? true;
        $_multitenancy  = $query['_multitenancy'] ?? true;
        $relationsContains = $query['_relations_contains'] ?? null;

        // Resolve multitenancy flag based on public schema access and explicit request.
        $multitenancyExplicit = $this->isExplicitlyTrue(value: $query['_multitenancy_explicit'] ?? false);
        $_multitenancy        = $this->resolveMultitenancyFlag(
            _multitenancy: $_multitenancy,
            multitenancyExplicit: $multitenancyExplicit,
            schema: $schema
        );

        // Extract and clean filters from the query.
        //
        // Reserved params (e.g. `register`, `schema`, `registers`, `schemas`,
        // `extend`) carry query CONTEXT, not object-field filters. They are NOT
        // underscore-prefixed, so without this guard they leak into
        // applyObjectFilters(), which treats them as unknown schema properties
        // and emits a `1 = 0` condition — silently returning ZERO results. This
        // is why `ObjectService::findAll(['filters' => ['register' => …,
        // 'schema' => …, …]])` (which always injects register/schema into the
        // filters) could not surface a just-written object in-request. Mirror
        // the raw-SQL UNION path, which already excludes getReservedParams().
        $metadataFilters = $query['@self'] ?? [];
        $objectFilters   = array_filter(
            $query,
            fn ($key): bool => $this->isObjectFieldFilterKey(key: $key),
            ARRAY_FILTER_USE_KEY
        );

        $queryBuilder = $this->db->getQueryBuilder();
        $queryBuilder->from($tableName, 't');

        // Apply basic filters (deleted, etc.).
        $this->applyBasicFilters(qb: $queryBuilder, includeDeleted: $includeDeleted);

        // Apply multi-tenancy and RBAC access control filters.
        $this->applyAccessControlFilters(
            qb: $queryBuilder,
            schema: $schema,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            multitenancyExplicit: $multitenancyExplicit
        );

        // Apply metadata filters.
        if (empty($metadataFilters) === false) {
            $this->applyMetadataFilters(qb: $queryBuilder, filters: $metadataFilters);
        }

        // Apply object field filters (schema-specific columns).
        if (empty($objectFilters) === false) {
            $this->applyObjectFilters(qb: $queryBuilder, filters: $objectFilters, schema: $schema);
        }

        // Apply ID filtering if provided.
        if ($ids !== null && empty($ids) === false) {
            $this->applyIdFilters(qb: $queryBuilder, ids: $ids);
        }

        // Apply full-text search if provided.
        // Fuzzy matching is only enabled when _fuzzy=true parameter is explicitly set.
        if ($search !== null && trim($search) !== '') {
            $fuzzyEnabled = $this->isFuzzySearchEnabled(fuzzyParam: $query['_fuzzy'] ?? null);
            $this->applyFullTextSearch(
                qb: $queryBuilder,
                search: trim($search),
                schema: $schema,
                fuzzyEnabled: $fuzzyEnabled
            );
        }

        // Apply relations contains filter if provided.
        if ($relationsContains !== null && empty($relationsContains) === false) {
            $this->applyRelationsContainsFilter(qb: $queryBuilder, uuid: $relationsContains);
        }

        // Apply dotted relation-field filters: `_relations.<field>` => <id>.
        $this->applyRelationFieldFilters(qb: $queryBuilder, query: $query);

        return $queryBuilder;
    }//end buildFilteredQuery()

    /**
     * Decide whether a query key is a genuine object-field filter.
     *
     * Excludes the `@self` metadata bag, every underscore-prefixed system
     * param, and every reserved context param (`register`, `schema`,
     * `registers`, `schemas`, `extend`). Without the reserved-param exclusion,
     * context keys leak into applyObjectFilters() and force a `1 = 0` condition
     * (they are not schema columns), silently returning zero results.
     *
     * @param string $key The query key to classify.
     *
     * @return bool True when the key is an object-field filter.
     */
    private function isObjectFieldFilterKey(string $key): bool
    {
        if ($key === '@self' || str_starts_with($key, '_') === true) {
            return false;
        }

        return in_array($key, $this->getReservedParams(), true) === false;
    }//end isObjectFieldFilterKey()

    /**
     * Apply every dotted relation-field filter (`_relations.<field>` => <id>)
     * present in the query to the QueryBuilder.
     *
     * Each pair matches only objects whose `_relations` references <id> under
     * the named relation field (honouring the filter VALUE, not merely the
     * presence of the relation field).
     *
     * @param IQueryBuilder       $qb    Query builder to modify.
     * @param array<string,mixed> $query The full search query.
     *
     * @return void
     */
    private function applyRelationFieldFilters(IQueryBuilder $qb, array $query): void
    {
        foreach ($this->extractRelationFieldFilters(query: $query) as $relField => $relValue) {
            $this->applyRelationFieldFilter(qb: $qb, field: $relField, value: $relValue);
        }
    }//end applyRelationFieldFilters()

    /**
     * Build WHERE conditions as raw SQL for use in UNION queries.
     *
     * This is the SINGLE SOURCE OF TRUTH for filter conditions used by:
     * - UNION search queries (MagicMapper::buildUnionSelectPart)
     * - UNION facet queries (MagicFacetHandler::getTermsFacetUnion)
     *
     * Includes RBAC filtering when enabled (default). Values are quoted inline
     * (not parameterized) for UNION query compatibility.
     *
     * @param array      $query           Search parameters including filters.
     * @param Schema     $schema          The schema for property filtering.
     * @param array|null $existingColumns Optional list of existing column names.
     *
     * @return string[] Array of SQL WHERE conditions (without leading AND/WHERE).
     */
    public function buildWhereConditionsSql(array $query, Schema $schema, ?array $existingColumns=null): array
    {
        $conditions = [];
        // Get connection for value quoting through QueryBuilder.
        $qb         = $this->db->getQueryBuilder();
        $connection = $qb->getConnection();
        // Detect platform once and pass down (avoids per-UNION-arm DBAL lookups).
        $isPostgres = $this->isPostgresPlatform();

        // Extract options from query.
        $search = $query['_search'] ?? null;
        // Coerce to bool: query-string "true"/"false" must not be compared by
        // identity against the boolean false (a non-empty string is never
        // === false, which would silently flip the deleted filter).
        $includeDeleted = filter_var($query['_includeDeleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $_rbac          = $query['_rbac'] ?? true;

        // 1. Deleted filter.
        if ($includeDeleted === false) {
            $conditions[] = '_deleted IS NULL';
        }

        // 2. RBAC filter (role-based access control).
        if ($_rbac === true) {
            $rbacCondition = $this->buildRbacConditionSql(schema: $schema);
            if ($rbacCondition !== null) {
                $conditions[] = $rbacCondition;
            }
        }

        // 4. Full-text search filter with optional fuzzy matching.
        if ($search !== null && trim($search) !== '') {
            $searchCondition = $this->buildSearchConditionSql(
                search: trim($search),
                schema: $schema,
                query: $query,
                connection: $connection,
                isPostgres: $isPostgres,
                existingColumns: $existingColumns
            );
            if ($searchCondition !== null) {
                $conditions[] = $searchCondition;
            }
        }

        // 5. Object field filters (non-reserved, non-metadata).
        $objectConditions = $this->buildObjectFilterConditionsSql(
            query: $query,
            schema: $schema,
            connection: $connection,
            isPostgres: $isPostgres
        );
        $conditions       = array_merge($conditions, $objectConditions);

        // 6. TMLO metadata JSON field filters (tmlo.archiefstatus, tmlo.archiefnominatie, etc.).
        $tmloConditions = $this->buildTmloFilterConditionsSql(
            query: $query,
            connection: $connection
        );
        $conditions     = array_merge($conditions, $tmloConditions);

        // 7. Dotted relation-field filters (`_relations.<field>` => <id>).
        $relationConditions = $this->buildRelationFilterConditionsSql(
            query: $query,
            connection: $connection
        );
        $conditions         = array_merge($conditions, $relationConditions);

        return $conditions;
    }//end buildWhereConditionsSql()

    /**
     * Build the RBAC SQL condition
     *
     * @param Schema $schema Schema for RBAC rules
     *
     * @return string|null SQL condition or null if no RBAC filtering needed
     */
    private function buildRbacConditionSql(Schema $schema): ?string
    {
        $rbacResult = $this->rbacHandler->buildRbacConditionsSql(schema: $schema, action: 'read');

        if ($rbacResult['bypass'] === false) {
            // User doesn't have unconditional access.
            if (empty($rbacResult['conditions']) === true) {
                // No access conditions met - deny all.
                return '1=0';
            }

            // OR together all RBAC conditions (access if ANY matches).
            return '('.implode(' OR ', $rbacResult['conditions']).')';
        }

        // If bypass=true, no RBAC filtering needed (user has full access).
        return null;
    }//end buildRbacConditionSql()

    /**
     * Build the full-text search SQL condition with optional fuzzy matching
     *
     * Fuzzy matching (pg_trgm similarity) is only enabled when _fuzzy=true parameter is set.
     * This gives users control over the performance vs typo-tolerance trade-off.
     * Without _fuzzy=true: ~140ms (ILIKE only)
     * With _fuzzy=true: ~160ms (ILIKE + similarity on _name)
     *
     * @param string     $search          Trimmed search term
     * @param Schema     $schema          Schema for determining searchable columns
     * @param array      $query           Full query array for extracting _fuzzy param
     * @param object     $connection      Database connection for value quoting
     * @param bool       $isPostgres      Whether the active platform is PostgreSQL
     * @param array|null $existingColumns Optional list of existing column names.
     *
     * @return string|null SQL condition or null if no search conditions generated
     */
    private function buildSearchConditionSql(
        string $search,
        Schema $schema,
        array $query,
        object $connection,
        bool $isPostgres,
        ?array $existingColumns=null
    ): ?string {
        $searchConditions = [];
        $likePattern      = $connection->quote('%'.$search.'%');
        $quotedTerm       = $connection->quote($search);

        // Check if fuzzy search is explicitly requested via _fuzzy=true parameter.
        $fuzzyEnabled = $this->isFuzzySearchEnabled(fuzzyParam: $query['_fuzzy'] ?? null);

        // Search in schema string properties (ILIKE/LIKE only for performance).
        $properties = $schema->getProperties() ?? [];
        foreach ($properties as $propName => $propDef) {
            $type = $propDef['type'] ?? 'string';
            if ($type === 'string') {
                $columnName = $this->sanitizeColumnName(name: $propName);
                // In UNION contexts, only search columns that actually exist in this table.
                if ($existingColumns !== null && in_array($columnName, $existingColumns, true) === false) {
                    continue;
                }

                // Quote column name to handle reserved words (e.g., 'case', 'status').
                $quotedCol = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);
                if ($isPostgres === true) {
                    // ILIKE is always case-insensitive on PostgreSQL.
                    $searchConditions[] = "{$quotedCol}::text ILIKE {$likePattern}";
                    continue;
                }

                // CAST + LOWER on both sides keeps MySQL case-insensitive regardless of
                // collation (e.g., utf8mb4_bin), matching applyFullTextSearch().
                $searchConditions[] = "LOWER(CAST({$quotedCol} AS CHAR)) LIKE LOWER({$likePattern})";
            }//end if
        }//end foreach

        // Search in metadata text fields.
        if ($isPostgres === true) {
            $searchConditions[] = "_name::text ILIKE {$likePattern}";
            $searchConditions[] = "_description::text ILIKE {$likePattern}";
            $searchConditions[] = "_summary::text ILIKE {$likePattern}";
        } else {
            $searchConditions[] = "LOWER(CAST(_name AS CHAR)) LIKE LOWER({$likePattern})";
            $searchConditions[] = "LOWER(CAST(_description AS CHAR)) LIKE LOWER({$likePattern})";
            $searchConditions[] = "LOWER(CAST(_summary AS CHAR)) LIKE LOWER({$likePattern})";
        }

        // Add fuzzy matching ONLY for _name when explicitly requested via _fuzzy=true.
        // This uses pg_trgm similarity() for typo tolerance at ~13% performance cost.
        if ($fuzzyEnabled === true) {
            $searchConditions[] = "similarity(_name::text, {$quotedTerm}) > 0.1";
        }

        if (empty($searchConditions) === false) {
            return '('.implode(' OR ', $searchConditions).')';
        }

        return null;
    }//end buildSearchConditionSql()

    /**
     * Build object field filter SQL conditions for non-reserved query parameters
     *
     * Column identifiers are quoted via quoteIdentifier() so that schema properties
     * named with SQL reserved words (e.g. 'status', 'case', 'order', 'group', 'key')
     * do not break the generated query. Mirrors the same defence the search path
     * already applies in buildSearchConditionSql().
     *
     * @param array  $query      Full query array
     * @param Schema $schema     Schema for property type lookup
     * @param object $connection Database connection for value quoting
     * @param bool   $isPostgres Whether the active platform is PostgreSQL (selects "" vs ``)
     *
     * @return string[] Array of SQL WHERE conditions
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function buildObjectFilterConditionsSql(
        array $query,
        Schema $schema,
        object $connection,
        bool $isPostgres
    ): array {
        $conditions     = [];
        $reservedParams = $this->getReservedParams();
        $properties     = $schema->getProperties() ?? [];

        foreach ($query as $key => $value) {
            // Skip reserved params, underscore-prefixed params, and @ metadata params.
            if (in_array($key, $reservedParams, true) === true
                || str_starts_with($key, '_') === true
                || str_starts_with($key, '@') === true
            ) {
                continue;
            }

            // Check if this property exists in the schema.
            if (isset($properties[$key]) === false) {
                // Property doesn't exist - add impossible condition.
                $conditions[] = '1=0';
                continue;
            }

            $columnName   = $this->sanitizeColumnName(name: $key);
            $quotedCol    = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);
            $propertyType = $properties[$key]['type'] ?? 'string';

            // Handle array-type properties (JSONB columns) with JSON containment operator.
            if ($propertyType === 'array') {
                $conditions[] = $this->buildArrayPropertyConditionSql(
                    columnName: $quotedCol,
                    value: $value,
                    connection: $connection
                );
                continue;
            }

            // Handle array filter values: comparison operators
            // (gte/lte/gt/lt/in/notIn/ne) or a bare IN clause.
            if (is_array($value) === true) {
                $comparisonOperators = ['gte', 'lte', 'gt', 'lt', 'in', 'notIn', 'ne'];
                if (empty(array_intersect(array_keys($value), $comparisonOperators)) === false) {
                    if (isset($value['gte']) === true) {
                        $colRef       = $this->buildFilterColumnRef(
                            columnRef: $quotedCol,
                            propertyType: $propertyType,
                            value: $value['gte'],
                            isPostgres: $isPostgres
                        );
                        $conditions[] = "{$colRef} >= ".$connection->quote((string) $value['gte']);
                    }

                    if (isset($value['lte']) === true) {
                        $colRef       = $this->buildFilterColumnRef(
                            columnRef: $quotedCol,
                            propertyType: $propertyType,
                            value: $value['lte'],
                            isPostgres: $isPostgres
                        );
                        $conditions[] = "{$colRef} <= ".$connection->quote((string) $value['lte']);
                    }

                    if (isset($value['gt']) === true) {
                        $colRef       = $this->buildFilterColumnRef(
                            columnRef: $quotedCol,
                            propertyType: $propertyType,
                            value: $value['gt'],
                            isPostgres: $isPostgres
                        );
                        $conditions[] = "{$colRef} > ".$connection->quote((string) $value['gt']);
                    }

                    if (isset($value['lt']) === true) {
                        $colRef       = $this->buildFilterColumnRef(
                            columnRef: $quotedCol,
                            propertyType: $propertyType,
                            value: $value['lt'],
                            isPostgres: $isPostgres
                        );
                        $conditions[] = "{$colRef} < ".$connection->quote((string) $value['lt']);
                    }

                    if (isset($value['in']) === true) {
                        $inValues = [$value['in']];
                        if (is_array($value['in']) === true) {
                            $inValues = $value['in'];
                        }

                        $colRef       = $this->buildFilterColumnRef(
                            columnRef: $quotedCol,
                            propertyType: $propertyType,
                            value: $inValues,
                            isPostgres: $isPostgres
                        );
                        $quotedValues = array_map(fn($v) => $connection->quote((string) $v), $inValues);
                        $conditions[] = "{$colRef} IN (".implode(', ', $quotedValues).')';
                    }

                    if (isset($value['notIn']) === true) {
                        $notInValues = [$value['notIn']];
                        if (is_array($value['notIn']) === true) {
                            $notInValues = $value['notIn'];
                        }

                        // An empty exclusion list excludes nothing — skip the
                        // clause rather than emit `NOT IN ()`.
                        if (count($notInValues) > 0) {
                            $colRef       = $this->buildFilterColumnRef(
                                columnRef: $quotedCol,
                                propertyType: $propertyType,
                                value: $notInValues,
                                isPostgres: $isPostgres
                            );
                            $quotedValues = array_map(fn($v) => $connection->quote((string) $v), $notInValues);
                            $conditions[] = "{$colRef} NOT IN (".implode(', ', $quotedValues).')';
                        }
                    }

                    if (isset($value['ne']) === true) {
                        $colRef       = $this->buildFilterColumnRef(
                            columnRef: $quotedCol,
                            propertyType: $propertyType,
                            value: $value['ne'],
                            isPostgres: $isPostgres
                        );
                        $conditions[] = "{$colRef} <> ".$connection->quote((string) $value['ne']);
                    }
                } else if (empty($value) === false) {
                    $colRef       = $this->buildFilterColumnRef(
                        columnRef: $quotedCol,
                        propertyType: $propertyType,
                        value: $value,
                        isPostgres: $isPostgres
                    );
                    $quotedValues = array_map(
                        fn($v) => $connection->quote((string) $v),
                        $value
                    );
                    $conditions[] = "{$colRef} IN (".implode(', ', $quotedValues).')';
                }//end if

                continue;
            }//end if

            // Simple equality filter. Cast numeric columns to text when filtered
            // by a non-numeric (e.g. UUID) value so PostgreSQL does not abort the
            // whole UNION query with SQLSTATE[22P02].
            $colRef       = $this->buildFilterColumnRef(
                columnRef: $quotedCol,
                propertyType: $propertyType,
                value: $value,
                isPostgres: $isPostgres
            );
            $conditions[] = "{$colRef} = ".$connection->quote((string) $value);
        }//end foreach

        return $conditions;
    }//end buildObjectFilterConditionsSql()

    /**
     * Build SQL condition for array-type (JSONB) property filtering
     *
     * Uses PostgreSQL JSONB containment operator (@>) to check if a JSON array
     * column contains the specified value(s).
     *
     * @param string $columnName Sanitized column name
     * @param mixed  $value      Filter value (string or array of strings)
     * @param object $connection Database connection for value quoting
     *
     * @return string SQL condition for the array property filter
     */
    private function buildArrayPropertyConditionSql(string $columnName, mixed $value, object $connection): string
    {
        // Normalize value to array.
        $values = [$value];
        if (is_array($value) === true) {
            $values = $value;
        }

        if (empty($values) === true || count($values) === 1) {
            // Single value (or empty): check if JSON array contains this value.
            $singleValue = $values[0] ?? '';
            $jsonValue   = $connection->quote(json_encode([$singleValue]));
            return "COALESCE({$columnName}, '[]')::jsonb @> {$jsonValue}::jsonb";
        }

        // Multiple values: check if JSON array contains ANY of the values (OR logic).
        $orParts = [];
        foreach ($values as $v) {
            $jsonValue = $connection->quote(json_encode([$v]));
            $orParts[] = "COALESCE({$columnName}, '[]')::jsonb @> {$jsonValue}::jsonb";
        }

        return '('.implode(' OR ', $orParts).')';
    }//end buildArrayPropertyConditionSql()

    /**
     * Build SQL conditions for TMLO metadata JSON field filters.
     *
     * Supports dot-notation filters like:
     * - tmlo.archiefstatus=semi_statisch (exact match on JSON sub-field)
     * - tmlo.archiefnominatie=vernietigen (exact match)
     * - tmlo.archiefactiedatum[from]=2025-01-01 (range filter)
     * - tmlo.archiefactiedatum[to]=2025-12-31 (range filter)
     * - tmlo.vernietigingsCategorie=cat1 (exact match)
     *
     * Uses PostgreSQL ->> operator for JSON field extraction.
     *
     * @param array  $query      The full query array
     * @param object $connection Database connection for value quoting
     *
     * @return string[] Array of SQL conditions
     */
    private function buildTmloFilterConditionsSql(array $query, object $connection): array
    {
        $conditions       = [];
        $archiefactieFrom = null;
        $archiefactieTo   = null;

        foreach ($query as $key => $value) {
            if (str_starts_with($key, 'tmlo.') === false) {
                continue;
            }

            $subField = substr($key, 5);

            // Handle date range filters for archiefactiedatum.
            if ($subField === 'archiefactiedatum[from]') {
                $archiefactieFrom = $value;
                continue;
            }

            if ($subField === 'archiefactiedatum[to]') {
                $archiefactieTo = $value;
                continue;
            }

            // Standard exact match on TMLO JSON sub-field.
            $quotedValue  = $connection->quote((string) $value);
            $conditions[] = "_tmlo::jsonb ->> ".$connection->quote($subField)." = {$quotedValue}";
        }//end foreach

        // Build archiefactiedatum range condition.
        if ($archiefactieFrom !== null) {
            $conditions[] = "_tmlo::jsonb ->> 'archiefactiedatum' >= ".$connection->quote($archiefactieFrom);
        }

        if ($archiefactieTo !== null) {
            $conditions[] = "_tmlo::jsonb ->> 'archiefactiedatum' <= ".$connection->quote($archiefactieTo);
        }

        return $conditions;
    }//end buildTmloFilterConditionsSql()

    /**
     * Get the list of reserved query parameter names
     *
     * These parameters are used for pagination, sorting, and internal flags
     * and should not be treated as object field filters.
     *
     * @return string[] List of reserved parameter names
     */
    private function getReservedParams(): array
    {
        return [
            '_limit',
            '_offset',
            '_page',
            '_order',
            '_sort',
            '_search',
            '_extend',
            '_fields',
            '_filter',
            '_unset',
            '_facets',
            '_facetable',
            '_aggregations',
            '_debug',
            '_rbac',
            '_multitenancy',
            '_validation',
            '_events',
            '_register',
            '_schema',
            '_schemas',
            '_ids',
            '_count',
            '_includeDeleted',
            '_relations_contains',
            '_multitenancy_explicit',
            '_fuzzy',
            '_empty',
            'register',
            'schema',
            'registers',
            'schemas',
            'extend',
        ];
    }//end getReservedParams()

    /**
     * Apply basic filters like deleted status
     *
     * @param IQueryBuilder $qb             Query builder to modify
     * @param bool          $includeDeleted Whether to include deleted objects
     *
     * @return void
     */
    private function applyBasicFilters(IQueryBuilder $qb, bool $includeDeleted): void
    {
        // Handle deleted filter.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('t._deleted'));
        }

    }//end applyBasicFilters()

    /**
     * Check if a mixed value represents an explicit boolean true
     *
     * Handles string, integer, and boolean representations of true.
     *
     * @param mixed $value The value to check
     *
     * @return bool True if the value is explicitly true
     */
    private function isExplicitlyTrue(mixed $value): bool
    {
        return $value === true
            || $value === 'true'
            || $value === '1'
            || $value === 1;
    }//end isExplicitlyTrue()

    /**
     * Resolve the multitenancy flag based on public schema access and explicit request
     *
     * Public schemas bypass multitenancy by default, UNLESS the user explicitly requests
     * multitenancy with _multi=true. This allows public data to be visible across orgs
     * while still giving users the option to filter by their own organisation.
     *
     * @param bool   $_multitenancy        Current multitenancy flag
     * @param bool   $multitenancyExplicit Whether multitenancy was explicitly requested
     * @param Schema $schema               Schema to check for public access
     *
     * @return bool Resolved multitenancy flag
     */
    private function resolveMultitenancyFlag(
        bool $_multitenancy,
        bool $multitenancyExplicit,
        Schema $schema
    ): bool {
        if ($_multitenancy === true) {
            $schemaAuth = $schema->getAuthorization();
            $readGroups = $schemaAuth['read'] ?? [];
            $hasPublic  = $this->hasPublicReadAccess(readRules: $readGroups);

            // Public schemas bypass multitenancy UNLESS user explicitly set _multi=true.
            if ($hasPublic === true && $multitenancyExplicit === false) {
                return false;
            }
        }

        return $_multitenancy;
    }//end resolveMultitenancyFlag()

    /**
     * Apply access control filters (multitenancy and RBAC) to the query
     *
     * Handles the interaction between RBAC and _multitenancy:
     * - When user has NO RBAC access: Apply multitenancy as normal (AND restriction)
     * - When user HAS RBAC access AND _multi=true: Apply multitenancy AFTER RBAC
     * - When user HAS RBAC access AND _multi=false: Skip multitenancy (RBAC handles access)
     *
     * @param IQueryBuilder $qb                   Query builder to modify
     * @param Schema        $schema               Schema for access control rules
     * @param bool          $_rbac                Whether RBAC filtering is enabled
     * @param bool          $_multitenancy        Whether multitenancy filtering is enabled
     * @param bool          $multitenancyExplicit Whether multitenancy was explicitly requested
     *
     * @return void
     */
    private function applyAccessControlFilters(
        IQueryBuilder $qb,
        Schema $schema,
        bool $_rbac,
        bool $_multitenancy,
        bool $multitenancyExplicit
    ): void {
        // Check if user qualifies for any RBAC rule (simple or conditional).
        // When user has RBAC access, multitenancy is bypassed by default (RBAC controls access).
        $userHasRbacAccess = false;
        if ($_rbac === true) {
            $userHasRbacAccess = $this->rbacHandler->hasConditionalRulesBypassingMultitenancy(
                schema: $schema,
                action: 'read'
            );
        }

        // A per-object grant must never widen the tenant edge (ADR-002; design
        // D3c). The grant branch is OR-ed into the RBAC filter, so on a schema
        // whose conditional rules would otherwise SKIP the organisation filter a
        // grant would become a cross-tenant hole. Forcing the EXISTING filter on
        // is deliberate: an `_organisation` term inside the grant branch would be
        // a second definition of the tenant edge, and this change exists because
        // second definitions of a rule drift apart. Cross-organisation sharing is
        // group 7's decision to take, not a side effect to inherit here.
        $hasObjectGrants = false;
        if ($_rbac === true) {
            $hasObjectGrants = $this->rbacHandler->currentCallerHoldsObjectGrants();
        }

        // Apply multitenancy filter based on RBAC access and explicit request.
        if ($_multitenancy === true) {
            $applyMultitenancy = false;

            if ($hasObjectGrants === true) {
                // Reached rows through a grant — the tenant edge stands.
                $applyMultitenancy = true;
            } else if ($userHasRbacAccess === false) {
                // No RBAC access - apply multitenancy as normal.
                $applyMultitenancy = true;
            } else if ($multitenancyExplicit === true) {
                // User has RBAC access but explicitly requested _multi=true
                // Apply multitenancy to further restrict results to their org.
                $applyMultitenancy = true;
            }

            // Otherwise: user has RBAC access and didn't request _multi=true
            // Skip multitenancy - let RBAC handle access control.
            if ($applyMultitenancy === true) {
                $this->organizationHandler->applyOrganizationFilter(
                    qb: $qb,
                    adminBypassEnabled: $this->organizationHandler->isAdminOverrideEnabled()
                );
            }
        }//end if

        // Apply RBAC filtering if enabled.
        if ($_rbac === true) {
            $this->rbacHandler->applyRbacFilters(
                qb: $qb,
                schema: $schema,
                action: 'read'
            );
        }
    }//end applyAccessControlFilters()

    /**
     * Check if fuzzy search should be enabled based on the _fuzzy parameter
     *
     * Fuzzy matching is only enabled when explicitly requested AND the pg_trgm
     * extension is available.
     *
     * @param mixed $fuzzyParam The raw _fuzzy parameter value
     *
     * @return bool True if fuzzy search should be enabled
     */
    private function isFuzzySearchEnabled(mixed $fuzzyParam): bool
    {
        if ($this->isExplicitlyTrue(value: $fuzzyParam) === true) {
            return $this->hasPgTrgmExtension();
        }

        return false;
    }//end isFuzzySearchEnabled()

    /**
     * Apply metadata filters to the query
     *
     * @param IQueryBuilder $qb      Query builder to modify
     * @param array         $filters Metadata filters to apply
     *
     * @return void
     */
    private function applyMetadataFilters(IQueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $field => $value) {
            $columnName = '_'.$field;
            // Metadata columns are prefixed with _.
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull("t.{$columnName}"));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull("t.{$columnName}"));
            } else if (is_array($value) === true) {
                $qb->andWhere(
                    $qb->expr()->in(
                        "t.{$columnName}",
                        $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)
                    )
                );
                continue;
            }

            $qb->andWhere($qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($value)));
        }
    }//end applyMetadataFilters()

    /**
     * Apply object field filters based on schema properties
     *
     * @param IQueryBuilder $qb      Query builder to modify
     * @param array         $filters Object field filters to apply
     * @param Schema        $schema  Schema for column mapping
     *
     * @return void
     *
     * @throws EncryptedFieldFilterException When a filter targets a property flagged
     *                                        `x-openregister-encrypted: true`.
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
     */
    private function applyObjectFilters(IQueryBuilder $qb, array $filters, Schema $schema): void
    {
        $properties = $schema->getProperties();

        // Fail loud BEFORE any query work rather than silently returning zero rows:
        // an encrypted property's value is ciphertext (and, since
        // buildTableColumnsFromSchema() gives it no dedicated column, may not even be
        // a real column at all), so a plaintext filter against it can never mean what
        // the caller intended. Checked up-front, ahead of platform detection and SQL
        // building, so the rejection is unconditional and cheap.
        foreach ($filters as $field => $value) {
            if (is_string($field) === true
                && ($properties[$field]['x-openregister-encrypted'] ?? false) === true
            ) {
                throw new EncryptedFieldFilterException(property: $field);
            }
        }

        $isPostgres = $this->isPostgresPlatform();

        foreach ($filters as $field => $value) {
            // Check if this field exists as a column in the schema.
            if (($properties[$field] ?? null) === null) {
                // Property doesn't exist in this schema but a filter was requested.
                // Track the ignored filter for client feedback.
                $this->ignoredFilters[] = $field;

                // Add a condition that always evaluates to false to return zero results.
                // This ensures multi-schema searches don't return unfiltered results
                // from schemas that lack the filtered property.
                $qb->andWhere('1 = 0');
                continue;
            }

            $columnName   = $this->sanitizeColumnName(name: $field);
            $propertyType = $properties[$field]['type'] ?? 'string';

            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull("t.{$columnName}"));
                continue;
            }

            if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull("t.{$columnName}"));
                continue;
            }

            // Handle array type columns (JSON arrays in PostgreSQL).
            if ($propertyType === 'array') {
                $this->applyJsonArrayFilter(qb: $qb, columnName: $columnName, value: $value);
                continue;
            }

            // Handle object type columns (JSON objects with 'value' key containing UUID).
            if ($propertyType === 'object') {
                $this->applyJsonObjectFilter(qb: $qb, columnName: $columnName, value: $value, isPostgres: $isPostgres);
                continue;
            }

            if (is_array($value) === true) {
                $comparisonOperators = ['gte', 'lte', 'gt', 'lt', 'in', 'notIn', 'ne'];
                if (empty(array_intersect(array_keys($value), $comparisonOperators)) === true) {
                    // Cast numeric columns to text when filtered by non-numeric
                    // (e.g. UUID) values so PostgreSQL does not abort with 22P02.
                    $columnRef = $this->buildFilterColumnRef(
                        columnRef: "t.{$columnName}",
                        propertyType: $propertyType,
                        value: $value,
                        isPostgres: $isPostgres
                    );
                    $qb->andWhere(
                        $qb->expr()->in(
                            $columnRef,
                            $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)
                        )
                    );
                    continue;
                }

                if (isset($value['gte']) === true) {
                    $columnRef = $this->buildFilterColumnRef(
                        columnRef: "t.{$columnName}",
                        propertyType: $propertyType,
                        value: $value['gte'],
                        isPostgres: $isPostgres
                    );
                    $qb->andWhere($qb->expr()->gte($columnRef, $qb->createNamedParameter($value['gte'])));
                }

                if (isset($value['lte']) === true) {
                    $columnRef = $this->buildFilterColumnRef(
                        columnRef: "t.{$columnName}",
                        propertyType: $propertyType,
                        value: $value['lte'],
                        isPostgres: $isPostgres
                    );
                    $qb->andWhere($qb->expr()->lte($columnRef, $qb->createNamedParameter($value['lte'])));
                }

                if (isset($value['gt']) === true) {
                    $columnRef = $this->buildFilterColumnRef(
                        columnRef: "t.{$columnName}",
                        propertyType: $propertyType,
                        value: $value['gt'],
                        isPostgres: $isPostgres
                    );
                    $qb->andWhere($qb->expr()->gt($columnRef, $qb->createNamedParameter($value['gt'])));
                }

                if (isset($value['lt']) === true) {
                    $columnRef = $this->buildFilterColumnRef(
                        columnRef: "t.{$columnName}",
                        propertyType: $propertyType,
                        value: $value['lt'],
                        isPostgres: $isPostgres
                    );
                    $qb->andWhere($qb->expr()->lt($columnRef, $qb->createNamedParameter($value['lt'])));
                }

                if (isset($value['in']) === true) {
                    $inValues = [$value['in']];
                    if (is_array($value['in']) === true) {
                        $inValues = $value['in'];
                    }

                    $columnRef = $this->buildFilterColumnRef(
                        columnRef: "t.{$columnName}",
                        propertyType: $propertyType,
                        value: $inValues,
                        isPostgres: $isPostgres
                    );
                    $qb->andWhere(
                        $qb->expr()->in(
                            $columnRef,
                            $qb->createNamedParameter($inValues, IQueryBuilder::PARAM_STR_ARRAY)
                        )
                    );
                }

                if (isset($value['notIn']) === true) {
                    $notInValues = [$value['notIn']];
                    if (is_array($value['notIn']) === true) {
                        $notInValues = $value['notIn'];
                    }

                    // An empty exclusion list excludes nothing, so skip the
                    // clause entirely — emitting `NOT IN ()` is a SQL error
                    // on some engines and a no-op-shaped clause on others.
                    if (count($notInValues) > 0) {
                        $columnRef = $this->buildFilterColumnRef(
                            columnRef: "t.{$columnName}",
                            propertyType: $propertyType,
                            value: $notInValues,
                            isPostgres: $isPostgres
                        );
                        $qb->andWhere(
                            $qb->expr()->notIn(
                                $columnRef,
                                $qb->createNamedParameter($notInValues, IQueryBuilder::PARAM_STR_ARRAY)
                            )
                        );
                    }
                }//end if

                if (isset($value['ne']) === true) {
                    $columnRef = $this->buildFilterColumnRef(
                        columnRef: "t.{$columnName}",
                        propertyType: $propertyType,
                        value: $value['ne'],
                        isPostgres: $isPostgres
                    );
                    $qb->andWhere($qb->expr()->neq($columnRef, $qb->createNamedParameter($value['ne'])));
                }

                continue;
            }//end if

            // Cast numeric columns to text when filtered by a non-numeric
            // (e.g. UUID) value so PostgreSQL does not abort the query with
            // SQLSTATE[22P02]; numeric values keep the indexed numeric path.
            $columnRef = $this->buildFilterColumnRef(
                columnRef: "t.{$columnName}",
                propertyType: $propertyType,
                value: $value,
                isPostgres: $isPostgres
            );
            $qb->andWhere($qb->expr()->eq($columnRef, $qb->createNamedParameter($value)));
        }//end foreach
    }//end applyObjectFilters()

    /**
     * Apply filter for JSON array columns using PostgreSQL jsonb operators
     *
     * @param IQueryBuilder $qb         Query builder to modify
     * @param string        $columnName Column name to filter
     * @param mixed         $value      Filter value (string or array of strings)
     *
     * @return void
     */
    private function applyJsonArrayFilter(IQueryBuilder $qb, string $columnName, mixed $value): void
    {
        // Normalize value to array.
        $values = [$value];
        if (is_array($value) === true) {
            $values = $value;
        }

        if (count($values) === 1) {
            // Single value: check if JSON array contains this value.
            // Use COALESCE to handle NULL values and avoid type cast issues with QueryBuilder.
            $jsonValue = json_encode([$values[0]]);
            $qb->andWhere(
                "COALESCE(t.{$columnName}, '[]')::jsonb @> ".$qb->createNamedParameter($jsonValue)
            );
            return;
        }

        // Multiple values: check if JSON array contains ANY of the values (OR logic).
        $orConditions = $qb->expr()->orX();
        foreach ($values as $v) {
            $jsonValue = json_encode([$v]);
            // Use raw SQL with COALESCE to handle NULL values properly.
            $orConditions->add(
                "COALESCE(t.{$columnName}, '[]')::jsonb @> ".$qb->createNamedParameter($jsonValue)
            );
        }

        $qb->andWhere($orConditions);
    }//end applyJsonArrayFilter()

    /**
     * Apply filter for object columns (related objects)
     *
     * Handles two storage formats:
     * 1. JSON object (jsonb column): {"value": "uuid"} - extracts value key
     * 2. Plain string (varchar column): "uuid" - direct comparison
     *
     * Uses text-based matching to work with both column types safely.
     *
     * @param IQueryBuilder $qb         Query builder to modify
     * @param string        $columnName Column name to filter
     * @param mixed         $value      Filter value (UUID string or array of UUIDs)
     * @param bool          $isPostgres Whether the backing database is PostgreSQL.
     *
     * @return void
     */
    private function applyJsonObjectFilter(IQueryBuilder $qb, string $columnName, mixed $value, bool $isPostgres): void
    {
        // Normalize value to array.
        $values = [$value];
        if (is_array($value) === true) {
            $values = $value;
        }

        if ($isPostgres === true) {
            $colCast = "t.{$columnName}::text";
        } else {
            $colCast = "CAST(t.{$columnName} AS CHAR)";
        }

        if (count($values) === 1) {
            // Single value: match both plain UUID and JSON format using text comparison.
            // Plain format: column contains exactly "uuid".
            // JSON format: column contains "value": "uuid" pattern.
            $param       = $qb->createNamedParameter($values[0]);
            $jsonPattern = $qb->createNamedParameter('%"value": "'.$values[0].'"%');
            $qb->andWhere(
                "({$colCast} = {$param} OR {$colCast} LIKE {$jsonPattern})"
            );
            return;
        }

        // Multiple values: check if value matches ANY of the values (OR logic).
        $orConditions = $qb->expr()->orX();
        foreach ($values as $v) {
            $param       = $qb->createNamedParameter($v);
            $jsonPattern = $qb->createNamedParameter('%"value": "'.$v.'"%');
            $orConditions->add(
                "({$colCast} = {$param} OR {$colCast} LIKE {$jsonPattern})"
            );
        }

        $qb->andWhere($orConditions);
    }//end applyJsonObjectFilter()

    /**
     * Apply ID-based filtering (UUID, slug, etc.)
     *
     * @param IQueryBuilder $qb  Query builder to modify
     * @param array         $ids Array of IDs to filter by
     *
     * @return void
     */
    private function applyIdFilters(IQueryBuilder $qb, array $ids): void
    {
        $orX = $qb->expr()->orX();
        $orX->add($qb->expr()->in('t._uuid', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
        $orX->add($qb->expr()->in('t._slug', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
        $qb->andWhere($orX);
    }//end applyIdFilters()

    /**
     * Apply relations contains filter to find objects referencing a specific UUID
     *
     * This uses PostgreSQL's JSONB @> operator to check if the _relations array
     * contains the specified UUID.
     *
     * @param IQueryBuilder $qb   Query builder to modify
     * @param string        $uuid UUID to search for in relations
     *
     * @return void
     */
    private function applyRelationsContainsFilter(IQueryBuilder $qb, string $uuid): void
    {
        // Relations can be stored as either:
        // - An array: ["uuid1", "uuid2", ...] (legacy/common format)
        // - An object: {"fieldName": "uuid", ...} (new format)
        // Handle both formats using jsonb_typeof to dispatch correctly.
        $param = $qb->createNamedParameter($uuid);
        $qb->andWhere(
            "(
                (jsonb_typeof(t._relations) = 'array' AND t._relations @> to_jsonb({$param}::text))
                OR
                (jsonb_typeof(t._relations) = 'object' AND EXISTS (
                    SELECT 1 FROM jsonb_each_text(t._relations) AS kv WHERE kv.value = {$param}
                ))
            )"
        );
    }//end applyRelationsContainsFilter()

    /**
     * Extract dotted relation-field filters (`_relations.<field>` => <id>) from a query.
     *
     * Filters of the form `_relations.<field>` are NOT object-column filters
     * (they are stripped by the leading-underscore guard everywhere else); they
     * target the `_relations` JSONB index and must match only objects whose
     * relation under `<field>` references the supplied id VALUE. Returns a map
     * of `<field>` => <id> for every such pair with a non-empty value.
     *
     * @param array<string,mixed> $query The full search query.
     *
     * @return array<string,string> Map of relation field name to referenced id.
     */
    private function extractRelationFieldFilters(array $query): array
    {
        $filters = [];
        foreach ($query as $key => $value) {
            if (str_starts_with($key, '_relations.') === false) {
                continue;
            }

            $field = substr($key, strlen('_relations.'));
            if ($field === '' || is_array($value) === true || $value === null) {
                continue;
            }

            $stringValue = (string) $value;
            if ($stringValue === '') {
                continue;
            }

            $filters[$field] = $stringValue;
        }//end foreach

        return $filters;
    }//end extractRelationFieldFilters()

    /**
     * Apply a dotted relation-field filter to a QueryBuilder.
     *
     * Matches objects whose `_relations` JSONB references `$value` under the
     * named `$field`. Honours both the object format (`{"<field>": "<id>", ...}`
     * — including array-indexed keys such as `<field>.0`) and the legacy array
     * format (`["<id>", ...]`), so the filter is never silently dropped.
     *
     * @param IQueryBuilder $qb    Query builder to modify.
     * @param string        $field The relation field name (the part after `_relations.`).
     * @param string        $value The referenced object id the relation must point at.
     *
     * @return void
     */
    private function applyRelationFieldFilter(IQueryBuilder $qb, string $field, string $value): void
    {
        $valueParam = $qb->createNamedParameter($value);
        $exactKey   = $qb->createNamedParameter($field);
        // Array-indexed relation keys are stored as `<field>.<n>` (e.g. `keywords.1`).
        $prefixParam = $qb->createNamedParameter($field.'.%');

        $qb->andWhere(
            "(
                (jsonb_typeof(t._relations) = 'object' AND EXISTS (
                    SELECT 1 FROM jsonb_each_text(t._relations) AS kv
                    WHERE kv.value = {$valueParam}
                      AND (kv.key = {$exactKey} OR kv.key LIKE {$prefixParam})
                ))
                OR
                (jsonb_typeof(t._relations) = 'array' AND t._relations @> to_jsonb({$valueParam}::text))
            )"
        );
    }//end applyRelationFieldFilter()

    /**
     * Build raw-SQL conditions for dotted relation-field filters (UNION path).
     *
     * Mirrors {@see applyRelationFieldFilter()} for the inline-quoted UNION
     * query path used by multi-schema searches and facets.
     *
     * @param array<string,mixed> $query      The full search query.
     * @param object              $connection Database connection for value quoting.
     *
     * @return string[] Array of SQL conditions (without leading AND/WHERE).
     */
    private function buildRelationFilterConditionsSql(array $query, object $connection): array
    {
        $conditions = [];
        foreach ($this->extractRelationFieldFilters(query: $query) as $field => $value) {
            $valueQuoted  = $connection->quote($value);
            $exactQuoted  = $connection->quote($field);
            $prefixQuoted = $connection->quote($field.'.%');

            $conditions[] = "(
                (jsonb_typeof(_relations) = 'object' AND EXISTS (
                    SELECT 1 FROM jsonb_each_text(_relations) AS kv
                    WHERE kv.value = {$valueQuoted}
                      AND (kv.key = {$exactQuoted} OR kv.key LIKE {$prefixQuoted})
                ))
                OR
                (jsonb_typeof(_relations) = 'array' AND _relations @> to_jsonb({$valueQuoted}::text))
            )";
        }

        return $conditions;
    }//end buildRelationFilterConditionsSql()

    /**
     * Apply full-text search across relevant columns
     *
     * Supports both substring matching (ILIKE) and optional fuzzy matching (pg_trgm similarity).
     * Fuzzy matching is only applied when explicitly requested via _fuzzy=true parameter.
     * When fuzzy is enabled, results are ordered by relevance (similarity score).
     *
     * @param IQueryBuilder $qb           Query builder to modify
     * @param string        $search       Search term
     * @param Schema        $schema       Schema for determining searchable fields
     * @param bool          $fuzzyEnabled Whether fuzzy matching is enabled (default: false)
     *
     * @return void
     */
    private function applyFullTextSearch(
        IQueryBuilder $qb,
        string $search,
        Schema $schema,
        bool $fuzzyEnabled=false
    ): void {
        $properties       = $schema->getProperties();
        $searchConditions = $qb->expr()->orX();
        $isPostgres       = $this->isPostgresPlatform();

        // Use lowercase search for case-insensitive matching.
        $lowerSearch     = strtolower($search);
        $searchPattern   = $qb->createNamedParameter('%'.$lowerSearch.'%');
        $searchTermParam = $qb->createNamedParameter($search);

        // Search in text-based schema properties (LIKE only for performance).
        // Skip date/time formatted fields — PostgreSQL LOWER() only works on text columns.
        $dateFormats = ['date', 'date-time', 'time'];
        foreach ($properties ?? [] as $field => $propertyConfig) {
            // Encrypted properties get no dedicated magic-table column (see
            // MagicMapper::buildTableColumnsFromSchema()); including one in a LIKE
            // full-text scan would either hit a non-existent column or, if a
            // legacy column still exists from before the flag was set, scan
            // ciphertext that can never match a plaintext search term. Skip
            // explicitly rather than let it silently fail to match.
            if (($propertyConfig['x-openregister-encrypted'] ?? false) === true) {
                continue;
            }

            if (($propertyConfig['type'] ?? '') === 'string'
                && in_array($propertyConfig['format'] ?? '', $dateFormats, true) === false
            ) {
                $columnName = $this->sanitizeColumnName(name: $field);
                // Quote the column to handle reserved words (e.g., 'case', 'status').
                // Without quoting, LOWER(t.case) raises a PostgreSQL syntax error.
                $quotedCol = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);
                $searchConditions->add(
                    $qb->expr()->like(
                        $qb->createFunction("LOWER(t.{$quotedCol})"),
                        $searchPattern
                    )
                );
            }
        }//end foreach

        // Search in metadata text fields (LIKE for all).
        $searchConditions->add(
            $qb->expr()->like($qb->createFunction('LOWER(t._name)'), $searchPattern)
        );
        $searchConditions->add(
            $qb->expr()->like($qb->createFunction('LOWER(t._description)'), $searchPattern)
        );
        $searchConditions->add(
            $qb->expr()->like($qb->createFunction('LOWER(t._summary)'), $searchPattern)
        );

        // Add fuzzy matching ONLY when explicitly requested via _fuzzy=true.
        // This uses pg_trgm similarity() for typo tolerance at ~13% performance cost.
        if ($fuzzyEnabled === true) {
            $searchConditions->add(
                $qb->createFunction("similarity(t._name::text, {$searchTermParam}) > 0.1")
            );
        }

        $qb->andWhere($searchConditions);
    }//end applyFullTextSearch()

    /**
     * Apply sorting to the query
     *
     * @param IQueryBuilder $qb         Query builder to modify
     * @param array         $order      Sort order configuration
     * @param Schema        $schema     Schema for column mapping
     * @param string|null   $searchTerm Search term for relevance sorting (optional)
     *
     * @return void
     */
    private function applySorting(
        IQueryBuilder $qb,
        array $order,
        Schema $schema,
        ?string $searchTerm=null
    ): void {
        $properties = $schema->getProperties();

        foreach ($order as $field => $direction) {
            $direction = strtoupper($direction);
            if (in_array($direction, ['ASC', 'DESC']) === false) {
                $direction = 'ASC';
            }

            // Special handling for relevance sorting (requires pg_trgm extension and a search term).
            // This uses PostgreSQL's similarity() function for fuzzy relevance scoring.
            if ($field === '_relevance') {
                if ($searchTerm !== null && $this->hasPgTrgmExtension() === true) {
                    // Use named parameter for safety and proper escaping.
                    $paramName = $qb->createNamedParameter($searchTerm);
                    // Nextcloud's QueryBuilder.addOrderBy() accepts expressions through createFunction().
                    $similarityExpr = "similarity(t._name::text, {$paramName})";
                    $qb->addOrderBy($qb->createFunction($similarityExpr), $direction);
                }

                // Skip _relevance if conditions aren't met (no search term or no pg_trgm).
                // Silently ignore to avoid errors - relevance ordering without search makes no sense.
                continue;
            }

            if (str_starts_with($field, '@self.') === true) {
                // Metadata field sorting (e.g., @self.created → t._created).
                $metadataField = '_'.str_replace('@self.', '', $field);
                $qb->addOrderBy("t.{$metadataField}", $direction);
            } else if (in_array(
                    $field,
                    [
                        '_created',
                        '_updated',
                        '_name',
                        '_description',
                        '_summary',
                        '_uuid',
                        '_register',
                        '_schema',
                        '_owner',
                        '_organisation',
                    ],
                    true
                    ) === true
            ) {
                // Direct metadata column reference (e.g., _created → t._created).
                $qb->addOrderBy("t.{$field}", $direction);
            } else if (($properties[$field] ?? null) !== null) {
                // Schema property field sorting.
                $columnName = $this->sanitizeColumnName(name: $field);
                $qb->addOrderBy("t.{$columnName}", $direction);
            }//end if
        }//end foreach
    }//end applySorting()

    /**
     * Execute search query and convert results to ObjectEntity objects
     *
     * @param IQueryBuilder $qb        Query builder to execute
     * @param Register      $register  Register context
     * @param Schema        $schema    Schema context
     * @param string        $tableName Table name for object conversion
     *
     * @return ObjectEntity[]
     *
     * @throws \OCP\DB\Exception If query execution fails
     *
     * @psalm-return list<ObjectEntity>
     */
    private function executeSearchQuery(IQueryBuilder $qb, Register $register, Schema $schema, string $tableName): array
    {
        $result  = $qb->executeQuery();
        $rows    = $result->fetchAll();
        $objects = [];

        foreach ($rows as $row) {
            $objectEntity = $this->convertRowToObjectEntity(
                row: $row,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );
            if ($objectEntity !== null) {
                $objects[] = $objectEntity;
            }
        }

        return $objects;
    }//end executeSearchQuery()

    /**
     * Build (and memoise) the property-type and column-to-property maps for a schema.
     *
     * PERF-4: computing these maps ran sanitizeColumnName() for every property on
     * every row. Memoising per schema id makes it once-per-schema-per-request.
     *
     * @param Schema $schema The schema to build maps for.
     *
     * @return array{types: array<string, string>, columns: array<string, string>}
     */
    private function getSchemaColumnMaps(Schema $schema): array
    {
        $schemaId = $schema->getId();
        if ($schemaId !== null && isset($this->schemaColumnMapCache[$schemaId]) === true) {
            return $this->schemaColumnMapCache[$schemaId];
        }

        $propertyTypes       = [];
        $propertyFormats     = [];
        $columnToPropertyMap = [];
        $properties          = $schema->getProperties();
        if (is_array($properties) === true) {
            foreach ($properties as $propName => $propDef) {
                $propertyTypes[$propName] = ($propDef['type'] ?? 'string');
                if (isset($propDef['format']) === true) {
                    $propertyFormats[$propName] = $propDef['format'];
                }

                $columnName = $this->sanitizeColumnName(name: $propName);
                $columnToPropertyMap[$columnName] = $propName;
            }
        }

        $maps = [
            'types'   => $propertyTypes,
            'formats' => $propertyFormats,
            'columns' => $columnToPropertyMap,
        ];
        if ($schemaId !== null) {
            $this->schemaColumnMapCache[$schemaId] = $maps;
        }

        return $maps;
    }//end getSchemaColumnMaps()

    /**
     * Convert database row from dynamic table to ObjectEntity
     *
     * @param array    $row       Database row data
     * @param Register $register  Register context
     * @param Schema   $schema    Schema context
     * @param string   $tableName Target dynamic table name
     *
     * @return ObjectEntity|null
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.NPathComplexity)       Row to entity conversion requires many field mappings
     */
    private function convertRowToObjectEntity(
        array $row,
        Register $register,
        Schema $schema,
        string $tableName=''
    ): ?ObjectEntity {
        try {
            $objectEntity = new ObjectEntity();

            // Extract metadata (prefixed with _).
            $metadataData = [];
            $objectData   = [];

            // Build property type map and column-to-property mapping from schema.
            // The column-to-property mapping allows us to restore original property names
            // (e.g., 'e-mailadres') from their sanitized column names (e.g., 'e_mailadres').
            // PERF-4: memoised per schema id so sanitizeColumnName runs once per
            // schema rather than once per property per row.
            $schemaMaps          = $this->getSchemaColumnMaps(schema: $schema);
            $propertyTypes       = $schemaMaps['types'];
            $propertyFormats     = ($schemaMaps['formats'] ?? []);
            $columnToPropertyMap = $schemaMaps['columns'];

            foreach ($row as $column => $value) {
                if (str_starts_with($column, '_') === true) {
                    // Metadata column - remove prefix and map to ObjectEntity.
                    $metadataField = substr($column, 1);
                    $metadataData[$metadataField] = $value;
                    continue;
                }

                // Map column name back to original property name using schema mapping.
                // Falls back to camelCase conversion if not found in mapping.
                $propertyName = $columnToPropertyMap[$column] ?? $this->columnNameToPropertyName(columnName: $column);

                // Convert value based on schema property type.
                // Delegates to the shared SchemaTypeConverter so this handler and
                // MagicStatisticsHandler agree on type semantics across read paths.
                $propertyType = $propertyTypes[$propertyName] ?? 'string';
                $value        = $this->schemaTypeConverter->convertValue(
                    value: $value,
                    schemaType: $propertyType
                );

                // Then agree on FORMAT semantics too. A `date`/`date-time` property
                // lives in a DATETIME column, so the driver returns 'Y-m-d H:i:s' —
                // which fails the schema's own `date-time` format when the object is
                // written straight back (every UI edit is a read-modify-write, so any
                // object with a populated date-time 400'd). MagicStatisticsHandler
                // already normalises here; this path did not, and this is the path
                // findAll() actually uses.
                $propertyFormat = ($propertyFormats[$propertyName] ?? null);
                if ($value !== null && is_string($value) === true && $propertyFormat !== null) {
                    if ($propertyFormat === 'date') {
                        $normalised = $this->dateTimeNormalizer->normalize($value);
                        $value      = null;
                        if ($normalised !== null) {
                            $value = $normalised->format('Y-m-d');
                        }
                    } else if ($propertyFormat === 'date-time') {
                        $value = $this->dateTimeNormalizer->formatForIso8601($value);
                    }
                }

                $objectData[$propertyName] = $value;
            }//end foreach

            // Set metadata properties.
            if (($metadataData['uuid'] ?? null) !== null) {
                $objectEntity->setUuid($metadataData['uuid']);
            }

            if (($metadataData['name'] ?? null) !== null) {
                $objectEntity->setName($metadataData['name']);
            }

            if (($metadataData['description'] ?? null) !== null) {
                $objectEntity->setDescription($metadataData['description']);
            }

            if (($metadataData['summary'] ?? null) !== null) {
                $objectEntity->setSummary($metadataData['summary']);
            }

            if (($metadataData['image'] ?? null) !== null) {
                $objectEntity->setImage($metadataData['image']);
            }

            if (($metadataData['slug'] ?? null) !== null) {
                $objectEntity->setSlug($metadataData['slug']);
            }

            if (($metadataData['uri'] ?? null) !== null) {
                $objectEntity->setUri($metadataData['uri']);
            }

            if (($metadataData['owner'] ?? null) !== null) {
                $objectEntity->setOwner($metadataData['owner']);
            }

            if (($metadataData['organisation'] ?? null) !== null) {
                $objectEntity->setOrganisation($metadataData['organisation']);
            }

            if (($metadataData['created'] ?? null) !== null) {
                $objectEntity->setCreated(new DateTime($metadataData['created']));
            }

            if (($metadataData['updated'] ?? null) !== null) {
                $objectEntity->setUpdated(new DateTime($metadataData['updated']));
            }

            if (($metadataData['deleted'] ?? null) !== null) {
                // Convert deleted timestamp to array format expected by setDeleted.
                $deletedDateTime = new DateTime($metadataData['deleted']);
                $objectEntity->setDeleted(
                    [
                        'deleted'   => $deletedDateTime->format('c'),
                        'deletedBy' => $metadataData['deletedBy'] ?? null,
                    ]
                );
            }

            // Set relevance score if present (from fuzzy search).
            // The _relevance column contains the similarity score as a percentage (0-100).
            if (($metadataData['relevance'] ?? null) !== null) {
                $objectEntity->setRelevance((float) $metadataData['relevance']);
            }

            // Set JSON metadata fields (stored as JSONB in magic tables).
            if (($metadataData['relations'] ?? null) !== null) {
                $relations = $metadataData['relations'];
                if (is_string($metadataData['relations']) === true) {
                    $relations = json_decode($metadataData['relations'], true);
                }

                $objectEntity->setRelations([]);
                if (is_array($relations) === true) {
                    $objectEntity->setRelations($relations);
                }
            }

            if (($metadataData['files'] ?? null) !== null) {
                $files = $metadataData['files'];
                if (is_string($metadataData['files']) === true) {
                    $files = json_decode($metadataData['files'], true);
                }

                $objectEntity->setFiles([]);
                if (is_array($files) === true) {
                    $objectEntity->setFiles($files);
                }
            }

            if (($metadataData['locked'] ?? null) !== null) {
                $locked = $metadataData['locked'];
                if (is_string($metadataData['locked']) === true) {
                    $locked = json_decode($metadataData['locked'], true);
                }

                $objectEntity->setLocked(null);
                if (is_array($locked) === true) {
                    $objectEntity->setLocked($locked);
                }
            }

            if (($metadataData['groups'] ?? null) !== null) {
                $groups = $metadataData['groups'];
                if (is_string($metadataData['groups']) === true) {
                    $groups = json_decode($metadataData['groups'], true);
                }

                $objectEntity->setGroups([]);
                if (is_array($groups) === true) {
                    $objectEntity->setGroups($groups);
                }
            }

            if (($metadataData['authorization'] ?? null) !== null) {
                $auth = $metadataData['authorization'];
                if (is_string($metadataData['authorization']) === true) {
                    $auth = json_decode($metadataData['authorization'], true);
                }

                $objectEntity->setAuthorization([]);
                if (is_array($auth) === true) {
                    $objectEntity->setAuthorization($auth);
                }
            }

            if (($metadataData['validation'] ?? null) !== null) {
                $validation = $metadataData['validation'];
                if (is_string($metadataData['validation']) === true) {
                    $validation = json_decode($metadataData['validation'], true);
                }

                $objectEntity->setValidation([]);
                if (is_array($validation) === true) {
                    $objectEntity->setValidation($validation);
                }
            }

            if (($metadataData['geo'] ?? null) !== null) {
                $geo = $metadataData['geo'];
                if (is_string($metadataData['geo']) === true) {
                    $geo = json_decode($metadataData['geo'], true);
                }

                $objectEntity->setGeo([]);
                if (is_array($geo) === true) {
                    $objectEntity->setGeo($geo);
                }
            }

            if (($metadataData['retention'] ?? null) !== null) {
                $retention = $metadataData['retention'];
                if (is_string($metadataData['retention']) === true) {
                    $retention = json_decode($metadataData['retention'], true);
                }

                $objectEntity->setRetention([]);
                if (is_array($retention) === true) {
                    $objectEntity->setRetention($retention);
                }
            }

            // Set scalar metadata fields.
            if (($metadataData['version'] ?? null) !== null) {
                $objectEntity->setVersion($metadataData['version']);
            }

            if (($metadataData['folder'] ?? null) !== null) {
                $objectEntity->setFolder($metadataData['folder']);
            }

            if (($metadataData['application'] ?? null) !== null) {
                $objectEntity->setApplication($metadataData['application']);
            }

            if (($metadataData['size'] ?? null) !== null) {
                $objectEntity->setSize($metadataData['size']);
            }

            // Set register and schema.
            $objectEntity->setRegister((string) $register->getId());
            $objectEntity->setSchema((string) $schema->getId());

            // Set the object data.
            $objectEntity->setObject($objectData);

            return $objectEntity;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[MagicSearchHandler] Failed to convert row to ObjectEntity',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'error'     => $e->getMessage(),
                    'tableName' => $tableName,
                    'row'       => $row,
                ]
            );

            return null;
        }//end try
    }//end convertRowToObjectEntity()

    /**
     * Whether the active database platform is PostgreSQL.
     *
     * Mirrors the idiom used in MagicMapper, MagicFacetHandler, SettingsService
     * and AggregationRunner so that all platform-detection sites in the codebase
     * agree on a single check.
     *
     * @return bool True when the connection's platform class name contains 'PostgreSQL'.
     */
    private function isPostgresPlatform(): bool
    {
        return stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;
    }//end isPostgresPlatform()

    /**
     * Whether a JSON-Schema property type maps onto a numeric SQL column.
     *
     * MagicMapper::mapSchemaPropertyToColumn() materialises `integer` as
     * smallint/integer/bigint and `number` as decimal. Filtering such a column
     * with a non-numeric value (e.g. a UUID — common when a relational key was
     * mistakenly declared `type: integer` in the schema) makes PostgreSQL reject
     * the whole query with `SQLSTATE[22P02] invalid input syntax for type
     * integer`, taking down the endpoint rather than returning zero rows.
     *
     * @param string $propertyType The declared JSON-Schema property type.
     *
     * @return bool True when the backing column is numeric-typed.
     */
    private function isNumericColumnType(string $propertyType): bool
    {
        return $propertyType === 'integer' || $propertyType === 'number';
    }//end isNumericColumnType()

    /**
     * Whether a filter value is a non-numeric scalar that would break a numeric
     * column comparison.
     *
     * A UUID, slug or any other non-numeric string bound against an integer /
     * decimal column triggers a database type-coercion error. When this returns
     * true the caller must cast the column to text so the comparison degrades to
     * a safe (always-false) string match instead of crashing the query.
     *
     * @param mixed $value The raw filter value.
     *
     * @return bool True when $value is a scalar that is not numeric.
     */
    private function isNonNumericScalar(mixed $value): bool
    {
        return is_scalar($value) === true && is_numeric($value) === false;
    }//end isNonNumericScalar()

    /**
     * Build the left-hand column reference for an object-field comparison,
     * casting numeric columns to text when the supplied value(s) cannot be
     * compared numerically.
     *
     * This is the core guard against `SQLSTATE[22P02]`: a UUID-valued filter on
     * a column the schema declared `integer`/`number` must compare as text, not
     * be coerced into the column's numeric type. Numeric values on numeric
     * columns are left untouched so ordinary integer filters keep using the
     * indexed numeric comparison.
     *
     * @param string $columnRef    The already-qualified, quoted column reference
     *                             (e.g. `t."amount"` or `t.amount`).
     * @param string $propertyType The declared JSON-Schema property type.
     * @param mixed  $value        The filter value (scalar or array of scalars).
     * @param bool   $isPostgres   Whether the active platform is PostgreSQL.
     *
     * @return string The (optionally text-cast) column reference.
     */
    private function buildFilterColumnRef(
        string $columnRef,
        string $propertyType,
        mixed $value,
        bool $isPostgres
    ): string {
        if ($this->isNumericColumnType(propertyType: $propertyType) === false) {
            return $columnRef;
        }

        // Determine whether any value in play is a non-numeric scalar.
        $needsTextCast = false;
        if (is_array($value) === true) {
            foreach ($value as $candidate) {
                if ($this->isNonNumericScalar(value: $candidate) === true) {
                    $needsTextCast = true;
                    break;
                }
            }
        } else {
            $needsTextCast = $this->isNonNumericScalar(value: $value);
        }

        if ($needsTextCast === false) {
            return $columnRef;
        }

        if ($isPostgres === true) {
            return $columnRef.'::text';
        }

        return 'CAST('.$columnRef.' AS CHAR)';
    }//end buildFilterColumnRef()

    /**
     * Quote a column or identifier name for the current database platform.
     *
     * Mirrors MagicMapper::quoteIdentifier(). Kept as a private helper here
     * because MagicSearchHandler is a collaborator of MagicMapper, not a
     * subclass, and the helper there is private.
     *
     * @param string $name       The unquoted identifier name.
     * @param bool   $isPostgres Whether the platform is PostgreSQL.
     *
     * @return string The quoted identifier.
     */
    private function quoteIdentifier(string $name, bool $isPostgres): string
    {
        if ($isPostgres === true) {
            return '"'.$name.'"';
        }

        return '`'.$name.'`';
    }//end quoteIdentifier()

    /**
     * Sanitize column name for safe database usage
     *
     * @param string $name Column name to sanitize
     *
     * @return string Sanitized column name
     */
    private function sanitizeColumnName(string $name): string
    {
        // Convert camelCase to snake_case (must match MagicMapper::sanitizeColumnName).
        // Insert underscore before uppercase letters, then lowercase everything.
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower($name);

        // Replace any remaining invalid characters with underscore.
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);

        // Ensure it starts with a letter or underscore.
        if (preg_match('/^[a-z_]/', $name) === 0) {
            $name = 'col_'.$name;
        }

        // Remove consecutive underscores.
        $name = preg_replace('/_+/', '_', $name);

        // Remove trailing underscores.
        $name = rtrim($name, '_');

        // Limit length to 64 characters (MySQL limit).
        return substr($name, 0, 64);
    }//end sanitizeColumnName()

    /**
     * Convert snake_case column name to camelCase property name
     *
     * @param string $columnName Column name in snake_case
     *
     * @return string Property name in camelCase
     */
    private function columnNameToPropertyName(string $columnName): string
    {
        // Convert snake_case to camelCase.
        return lcfirst(str_replace('_', '', ucwords($columnName, '_')));
    }//end columnNameToPropertyName()

    /**
     * Check if authorization rules include public read access
     *
     * Supports both simple "public" and conditional {"group": "public", ...} rules.
     *
     * @param array $readRules Array of read authorization rules
     *
     * @return bool True if any rule grants public access
     */
    private function hasPublicReadAccess(array $readRules): bool
    {
        foreach ($readRules as $rule) {
            // Simple rule: "public" string.
            if ($rule === 'public') {
                return true;
            }

            // Conditional rule: {"group": "public", ...}.
            if (is_array($rule) === true && ($rule['group'] ?? null) === 'public') {
                return true;
            }
        }

        return false;
    }//end hasPublicReadAccess()
}//end class
