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
 * schema-based tables, supporting all the search patterns available in ObjectEntityMapper
 * but optimized for schema-specific table structures.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Search handler requires many specialized query building methods
 * @SuppressWarnings(PHPMD.TooManyMethods)           Search requires per-operator and per-type conversion methods
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Search handler bridges schema, register, and query builder layers
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
     * Constructor for MagicSearchHandler
     *
     * @param IDBConnection            $db                  Database connection for queries
     * @param LoggerInterface          $logger              Logger for debugging and error reporting
     * @param MagicRbacHandler         $rbacHandler         RBAC handler for access control
     * @param MagicOrganizationHandler $organizationHandler Organization handler for multi-tenancy
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly MagicRbacHandler $rbacHandler,
        private readonly MagicOrganizationHandler $organizationHandler
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
        $platform = $this->db->getDatabasePlatform();
        if (str_contains(get_class($platform), 'PostgreSQL') === false) {
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
     * This method provides the same search capabilities as ObjectEntityMapper::searchObjects()
     * but optimized for schema-specific dynamic tables.
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
        if ($search !== null && trim($search) !== '') {
            $searchTerm = trim($search);
        } else {
            $searchTerm = null;
        }

        $fuzzyParam = $query['_fuzzy'] ?? null;
        if ($fuzzyParam === true || $fuzzyParam === 'true' || $fuzzyParam === '1' || $fuzzyParam === 1) {
            $fuzzyEnabled = $this->hasPgTrgmExtension();
        }

        // Add SELECT clause based on count vs search.
        if ($count === true) {
            $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'count');
        } else {
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
            }

            $queryBuilder->setMaxResults($limit)
                ->setFirstResult($offset);
        }//end if

        // Execute query and return results.
        if ($count === true) {
            $result = $queryBuilder->executeQuery();
            return (int) $result->fetchOne();
        }

        return $this->executeSearchQuery(qb: $queryBuilder, register: $register, schema: $schema, tableName: $tableName);
    }//end searchObjects()

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
        $search         = $query['_search'] ?? null;
        $includeDeleted = $query['_includeDeleted'] ?? false;
        $ids            = $query['_ids'] ?? null;
        $rbac           = $query['_rbac'] ?? true;
        $multitenancy   = $query['_multitenancy'] ?? true;
        $relationsContains = $query['_relations_contains'] ?? null;
        $source            = $query['_source'] ?? null;

        // Resolve multitenancy flag based on public schema access and explicit request.
        $multitenancyExplicit = $this->isExplicitlyTrue(value: $query['_multitenancy_explicit'] ?? false);
        $multitenancy         = $this->resolveMultitenancyFlag(
            multitenancy: $multitenancy,
            multitenancyExplicit: $multitenancyExplicit,
            source: $source,
            schema: $schema
        );

        // Extract and clean filters from the query.
        $metadataFilters = $query['@self'] ?? [];
        $objectFilters   = array_filter(
            $query,
            function ($key) {
                return $key !== '@self' && !str_starts_with($key, '_');
            },
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
            rbac: $rbac,
            multitenancy: $multitenancy,
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

        return $queryBuilder;
    }//end buildFilteredQuery()

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
     * @param array  $query  Search parameters including filters.
     * @param Schema $schema The schema for property filtering.
     *
     * @return string[] Array of SQL WHERE conditions (without leading AND/WHERE).
     */
    public function buildWhereConditionsSql(array $query, Schema $schema): array
    {
        $conditions = [];
        // Get connection for value quoting through QueryBuilder.
        $qb         = $this->db->getQueryBuilder();
        $connection = $qb->getConnection();

        // Extract options from query.
        $search         = $query['_search'] ?? null;
        $includeDeleted = $query['_includeDeleted'] ?? false;
        $rbac           = $query['_rbac'] ?? true;

        // 1. Deleted filter.
        if ($includeDeleted === false) {
            $conditions[] = '_deleted IS NULL';
        }

        // 2. RBAC filter (role-based access control).
        if ($rbac === true) {
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
                connection: $connection
            );
            if ($searchCondition !== null) {
                $conditions[] = $searchCondition;
            }
        }

        // 5. Object field filters (non-reserved, non-metadata).
        $objectConditions = $this->buildObjectFilterConditionsSql(
            query: $query,
            schema: $schema,
            connection: $connection
        );
        $conditions       = array_merge($conditions, $objectConditions);

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
     * @param string $search     Trimmed search term
     * @param Schema $schema     Schema for determining searchable columns
     * @param array  $query      Full query array for extracting _fuzzy param
     * @param object $connection Database connection for value quoting
     *
     * @return string|null SQL condition or null if no search conditions generated
     */
    private function buildSearchConditionSql(
        string $search,
        Schema $schema,
        array $query,
        object $connection
    ): ?string {
        $searchConditions = [];
        $likePattern      = $connection->quote('%'.$search.'%');
        $quotedTerm       = $connection->quote($search);

        // Check if fuzzy search is explicitly requested via _fuzzy=true parameter.
        $fuzzyEnabled = $this->isFuzzySearchEnabled(fuzzyParam: $query['_fuzzy'] ?? null);

        // Search in schema string properties (ILIKE only for performance).
        $properties = $schema->getProperties() ?? [];
        foreach ($properties as $propName => $propDef) {
            $type = $propDef['type'] ?? 'string';
            if ($type === 'string') {
                $columnName         = $this->sanitizeColumnName(name: $propName);
                $searchConditions[] = "{$columnName}::text ILIKE {$likePattern}";
            }
        }

        // Search in metadata text fields (ILIKE for all).
        $searchConditions[] = "_name::text ILIKE {$likePattern}";
        $searchConditions[] = "_description::text ILIKE {$likePattern}";
        $searchConditions[] = "_summary::text ILIKE {$likePattern}";

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
     * @param array  $query      Full query array
     * @param Schema $schema     Schema for property type lookup
     * @param object $connection Database connection for value quoting
     *
     * @return string[] Array of SQL WHERE conditions
     */
    private function buildObjectFilterConditionsSql(array $query, Schema $schema, object $connection): array
    {
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
            $propertyType = $properties[$key]['type'] ?? 'string';

            // Handle array-type properties (JSONB columns) with JSON containment operator.
            if ($propertyType === 'array') {
                $conditions[] = $this->buildArrayPropertyConditionSql(
                    columnName: $columnName,
                    value: $value,
                    connection: $connection
                );
                continue;
            }

            // Handle array filter values with IN clause (for non-array property types).
            if (is_array($value) === true) {
                if (empty($value) === false) {
                    $quotedValues = array_map(
                        fn($v) => $connection->quote((string) $v),
                        $value
                    );
                    $conditions[] = "{$columnName} IN (".implode(', ', $quotedValues).')';
                }

                continue;
            }

            // Simple equality filter.
            $conditions[] = "{$columnName} = ".$connection->quote((string) $value);
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
        if (is_array($value) === true) {
            $values = $value;
        } else {
            $values = [$value];
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
            '_source',
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
     * @param bool        $multitenancy         Current multitenancy flag
     * @param bool        $multitenancyExplicit Whether multitenancy was explicitly requested
     * @param string|null $source               Data source type
     * @param Schema      $schema               Schema to check for public access
     *
     * @return bool Resolved multitenancy flag
     */
    private function resolveMultitenancyFlag(
        bool $multitenancy,
        bool $multitenancyExplicit,
        ?string $source,
        Schema $schema
    ): bool {
        if ($multitenancy === true && $source !== 'database') {
            $schemaAuth = $schema->getAuthorization();
            $readGroups = $schemaAuth['read'] ?? [];
            $hasPublic  = $this->hasPublicReadAccess(readRules: $readGroups);

            // Public schemas bypass multitenancy UNLESS user explicitly set _multi=true.
            if ($hasPublic === true && $multitenancyExplicit === false) {
                return false;
            }
        }

        return $multitenancy;
    }//end resolveMultitenancyFlag()

    /**
     * Apply access control filters (multitenancy and RBAC) to the query
     *
     * Handles the interaction between RBAC and multitenancy:
     * - When user has NO RBAC access: Apply multitenancy as normal (AND restriction)
     * - When user HAS RBAC access AND _multi=true: Apply multitenancy AFTER RBAC
     * - When user HAS RBAC access AND _multi=false: Skip multitenancy (RBAC handles access)
     *
     * @param IQueryBuilder $qb                   Query builder to modify
     * @param Schema        $schema               Schema for access control rules
     * @param bool          $rbac                 Whether RBAC filtering is enabled
     * @param bool          $multitenancy         Whether multitenancy filtering is enabled
     * @param bool          $multitenancyExplicit Whether multitenancy was explicitly requested
     *
     * @return void
     */
    private function applyAccessControlFilters(
        IQueryBuilder $qb,
        Schema $schema,
        bool $rbac,
        bool $multitenancy,
        bool $multitenancyExplicit
    ): void {
        // Check if user qualifies for any RBAC rule (simple or conditional).
        // When user has RBAC access, multitenancy is bypassed by default (RBAC controls access).
        $userHasRbacAccess = false;
        if ($rbac === true) {
            $userHasRbacAccess = $this->rbacHandler->hasConditionalRulesBypassingMultitenancy(
                schema: $schema,
                action: 'read'
            );
        }

        // Apply multitenancy filter based on RBAC access and explicit request.
        if ($multitenancy === true) {
            $applyMultitenancy = false;

            if ($userHasRbacAccess === false) {
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
        if ($rbac === true) {
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
     */
    private function applyObjectFilters(IQueryBuilder $qb, array $filters, Schema $schema): void
    {
        $properties = $schema->getProperties();

        foreach ($filters as $field => $value) {
            // Check if this field exists as a column in the schema.
            if (($properties[$field] ?? null) !== null) {
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
                    $this->applyJsonObjectFilter(qb: $qb, columnName: $columnName, value: $value);
                    continue;
                }

                if (is_array($value) === true) {
                    $qb->andWhere(
                        $qb->expr()->in(
                            "t.{$columnName}",
                            $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)
                        )
                    );
                    continue;
                }

                $qb->andWhere($qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($value)));
            } else {
                // Property doesn't exist in this schema but a filter was requested.
                // Track the ignored filter for client feedback.
                $this->ignoredFilters[] = $field;

                // Add a condition that always evaluates to false to return zero results.
                // This ensures multi-schema searches don't return unfiltered results
                // from schemas that lack the filtered property.
                $qb->andWhere('1 = 0');
            }//end if
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
     *
     * @return void
     */
    private function applyJsonObjectFilter(IQueryBuilder $qb, string $columnName, mixed $value): void
    {
        // Normalize value to array.
        $values = [$value];
        if (is_array($value) === true) {
            $values = $value;
        }

        if (count($values) === 1) {
            // Single value: match both plain UUID and JSON format using text comparison.
            // Plain format: column contains exactly "uuid".
            // JSON format: column contains "value": "uuid" pattern.
            $param       = $qb->createNamedParameter($values[0]);
            $jsonPattern = $qb->createNamedParameter('%"value": "'.$values[0].'"%');
            $qb->andWhere(
                "(t.{$columnName}::text = {$param} OR t.{$columnName}::text LIKE {$jsonPattern})"
            );
            return;
        }

        // Multiple values: check if value matches ANY of the values (OR logic).
        $orConditions = $qb->expr()->orX();
        foreach ($values as $v) {
            $param       = $qb->createNamedParameter($v);
            $jsonPattern = $qb->createNamedParameter('%"value": "'.$v.'"%');
            $orConditions->add(
                "(t.{$columnName}::text = {$param} OR t.{$columnName}::text LIKE {$jsonPattern})"
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

        // Use lowercase search for case-insensitive matching.
        $lowerSearch     = strtolower($search);
        $searchPattern   = $qb->createNamedParameter('%'.$lowerSearch.'%');
        $searchTermParam = $qb->createNamedParameter($search);

        // Search in text-based schema properties (LIKE only for performance).
        // Skip date/time formatted fields — PostgreSQL LOWER() only works on text columns.
        $dateFormats = ['date', 'date-time', 'time'];
        foreach ($properties ?? [] as $field => $propertyConfig) {
            if (($propertyConfig['type'] ?? '') === 'string'
                && in_array($propertyConfig['format'] ?? '', $dateFormats, true) === false
            ) {
                $columnName = $this->sanitizeColumnName(name: $field);
                $searchConditions->add(
                    $qb->expr()->like(
                        $qb->createFunction("LOWER(t.{$columnName})"),
                        $searchPattern
                    )
                );
            }
        }

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
     * Convert database row from dynamic table to ObjectEntity
     *
     * @param array    $row       Database row data
     * @param Register $register  Register context
     * @param Schema   $schema    Schema context
     * @param string   $tableName Target dynamic table name
     *
     * @return ObjectEntity|null ObjectEntity object or null if conversion fails
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
            $propertyTypes       = [];
            $columnToPropertyMap = [];
            foreach ($schema->getProperties() as $propName => $propDef) {
                $propertyTypes[$propName] = $propDef['type'] ?? 'string';
                $columnName = $this->sanitizeColumnName(name: $propName);
                $columnToPropertyMap[$columnName] = $propName;
            }

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
                $propertyType = $propertyTypes[$propertyName] ?? 'string';
                $objectData[$propertyName] = $this->convertValueByType(value: $value, type: $propertyType);
            }

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
                if (is_string($metadataData['relations']) === true) {
                    $relations = json_decode($metadataData['relations'], true);
                } else {
                    $relations = $metadataData['relations'];
                }

                if (is_array($relations) === true) {
                    $objectEntity->setRelations($relations);
                } else {
                    $objectEntity->setRelations([]);
                }
            }

            if (($metadataData['files'] ?? null) !== null) {
                if (is_string($metadataData['files']) === true) {
                    $files = json_decode($metadataData['files'], true);
                } else {
                    $files = $metadataData['files'];
                }

                if (is_array($files) === true) {
                    $objectEntity->setFiles($files);
                } else {
                    $objectEntity->setFiles([]);
                }
            }

            if (($metadataData['locked'] ?? null) !== null) {
                if (is_string($metadataData['locked']) === true) {
                    $locked = json_decode($metadataData['locked'], true);
                } else {
                    $locked = $metadataData['locked'];
                }

                if (is_array($locked) === true) {
                    $objectEntity->setLocked($locked);
                } else {
                    $objectEntity->setLocked(null);
                }
            }

            if (($metadataData['groups'] ?? null) !== null) {
                if (is_string($metadataData['groups']) === true) {
                    $groups = json_decode($metadataData['groups'], true);
                } else {
                    $groups = $metadataData['groups'];
                }

                if (is_array($groups) === true) {
                    $objectEntity->setGroups($groups);
                } else {
                    $objectEntity->setGroups([]);
                }
            }

            if (($metadataData['authorization'] ?? null) !== null) {
                if (is_string($metadataData['authorization']) === true) {
                    $auth = json_decode($metadataData['authorization'], true);
                } else {
                    $auth = $metadataData['authorization'];
                }

                if (is_array($auth) === true) {
                    $objectEntity->setAuthorization($auth);
                } else {
                    $objectEntity->setAuthorization([]);
                }
            }

            if (($metadataData['validation'] ?? null) !== null) {
                if (is_string($metadataData['validation']) === true) {
                    $validation = json_decode($metadataData['validation'], true);
                } else {
                    $validation = $metadataData['validation'];
                }

                if (is_array($validation) === true) {
                    $objectEntity->setValidation($validation);
                } else {
                    $objectEntity->setValidation([]);
                }
            }

            if (($metadataData['geo'] ?? null) !== null) {
                if (is_string($metadataData['geo']) === true) {
                    $geo = json_decode($metadataData['geo'], true);
                } else {
                    $geo = $metadataData['geo'];
                }

                if (is_array($geo) === true) {
                    $objectEntity->setGeo($geo);
                } else {
                    $objectEntity->setGeo([]);
                }
            }

            if (($metadataData['retention'] ?? null) !== null) {
                if (is_string($metadataData['retention']) === true) {
                    $retention = json_decode($metadataData['retention'], true);
                } else {
                    $retention = $metadataData['retention'];
                }

                if (is_array($retention) === true) {
                    $objectEntity->setRetention($retention);
                } else {
                    $objectEntity->setRetention([]);
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
     * Convert value based on schema property type
     *
     * Schema type determines the conversion, not the data format.
     *
     * @param mixed  $value Value to convert
     * @param string $type  Schema property type (string, number, boolean, array, object, integer)
     *
     * @return mixed Converted value
     */
    private function convertValueByType(mixed $value, string $type): mixed
    {
        // Handle null values.
        if ($value === null) {
            return null;
        }

        // Convert based on schema type (schema is authoritative, not data format).
        return match ($type) {
            'array', 'object' => $this->convertArrayOrObjectValue(value: $value),
            'number'          => $this->convertNumberValue(value: $value),
            'integer'         => $this->convertIntegerValue(value: $value),
            'boolean'         => $this->convertBooleanValue(value: $value),
            default           => $this->convertStringValue(value: $value, type: $type),
        };
    }//end convertValueByType()

    /**
     * Convert a value to array or object type
     *
     * Schema says this should be array/object - decode if it's a JSON string.
     *
     * @param mixed $value Value to convert
     *
     * @return mixed Decoded array/object or original value
     */
    private function convertArrayOrObjectValue(mixed $value): mixed
    {
        if (is_string($value) === true) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Already an array/object or failed to decode - return as-is.
        return $value;
    }//end convertArrayOrObjectValue()

    /**
     * Convert a value to number (float) type
     *
     * Schema says this should be a number (float).
     *
     * @param mixed $value Value to convert
     *
     * @return mixed Float value or original if not numeric
     */
    private function convertNumberValue(mixed $value): mixed
    {
        if (is_numeric($value) === true) {
            return (float) $value;
        }

        return $value;
    }//end convertNumberValue()

    /**
     * Convert a value to integer type
     *
     * Schema says this should be an integer.
     *
     * @param mixed $value Value to convert
     *
     * @return mixed Integer value or original if not numeric
     */
    private function convertIntegerValue(mixed $value): mixed
    {
        if (is_numeric($value) === true) {
            return (int) $value;
        }

        return $value;
    }//end convertIntegerValue()

    /**
     * Convert a value to boolean type
     *
     * Schema says this should be a boolean.
     *
     * @param mixed $value Value to convert
     *
     * @return bool Boolean value
     */
    private function convertBooleanValue(mixed $value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_string($value) === true) {
            return in_array(strtolower($value), ['true', '1', 'yes'], true);
        }

        return (bool) $value;
    }//end convertBooleanValue()

    /**
     * Convert a value for string or unknown schema type
     *
     * For backwards compatibility, if the value looks like a JSON array or object
     * (starts with [ or {), try to decode it. This handles cases where schema is
     * incorrectly defined as string but the actual data is array/object, matching
     * MagicMapper::convertRowToObjectEntity behavior.
     *
     * @param mixed  $value Value to convert
     * @param string $type  The schema type (used to check for explicit 'string')
     *
     * @return mixed Converted value
     */
    private function convertStringValue(mixed $value, string $type): mixed
    {
        if (is_string($value) === true) {
            $trimmed          = trim($value);
            $startsWithArrObj = (
                str_starts_with($trimmed, '[') === true || str_starts_with($trimmed, '{') === true
            );

            if ($startsWithArrObj === true) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && ($decoded !== null || $value === 'null')) {
                    return $decoded;
                }
            }

            return $value;
        }

        // For schema type 'string', ensure we return a string.
        // This handles cases where the database driver returns numeric values as integers
        // even though they're stored in TEXT/VARCHAR columns (e.g., "45" returned as int 45).
        if ($type === 'string' && (is_int($value) === true || is_float($value) === true)) {
            return (string) $value;
        }

        return $value;
    }//end convertStringValue()

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
