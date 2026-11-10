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

use OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\LikeOperator;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use OCA\OpenRegister\Service\Query\RelatedRowFilterTarget;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use OCA\OpenRegister\Exception\UnknownMetadataFieldException;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\LanguageServiceInterface;
use OCA\OpenRegister\Service\Object\DraftListLens;
use OCA\OpenRegister\Service\Object\DraftListing;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Search\Service\Search\PropertySearchProfile;
use OCA\OpenRegister\Service\Search\SearchGrammar;
use OCA\OpenRegister\Service\Search\SearchTermParserInterface;
use OCA\OpenRegister\Service\Search\SearchTermSqlCompilerInterface;
use OCA\OpenRegister\Support\FilterParams;
use OCA\OpenRegister\Support\NullFilter;
use OCA\OpenRegister\Support\OrderParameter;
use OCA\OpenRegister\Support\QueryLimit;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

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
class MagicSearchHandler {
	use MagicColumnNameTrait;

	/**
	 * Comparison operators accepted inside a filter's value bag.
	 *
	 * Declared once and shared by the schema-property path and the `@self`
	 * metadata path so the two dialects cannot drift apart again. Bare keys,
	 * never `$`-prefixed — `$`-style belongs to OperatorEvaluator's policy
	 * language, which is a different subsystem.
	 *
	 * @var string[]
	 */
	private const COMPARISON_OPERATORS = ['gte', 'lte', 'gt', 'lt', 'in', 'notIn', 'ne', 'isnull', LikeOperator::KEY];

	/**
	 * Metadata columns a search may order by under their own name.
	 *
	 * @var array<int, string>
	 */
	private const SORTABLE_METADATA_COLUMNS = [
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
	];

	/**
	 * The metadata columns a row sets on its entity, in the order they are set, with how
	 * each is stored: a scalar, a datetime, the deleted marker, a float, or JSON whose
	 * fallback is an empty array (`json`) or null (`json-or-null`).
	 *
	 * @var array<string, string>
	 */
	private const ROW_METADATA_KINDS = [
		'uuid'               => 'scalar',
		'name'               => 'scalar',
		'description'        => 'scalar',
		'summary'            => 'scalar',
		'image'              => 'scalar',
		'slug'               => 'scalar',
		'uri'                => 'scalar',
		'owner'              => 'scalar',
		'organisation'       => 'scalar',
		'created'            => 'datetime',
		'updated'            => 'datetime',
		'last_user_activity' => 'datetime',
		'deleted'            => 'deleted',
		'relevance'          => 'float',
		'relations'          => 'json',
		'files'              => 'json',
		'locked'             => 'json-or-null',
		'groups'             => 'json',
		'authorization'      => 'json',
		'validation'         => 'json',
		'geo'                => 'json',
		'retention'          => 'json',
		'version'            => 'scalar',
		'folder'             => 'scalar',
		'application'        => 'scalar',
		'size'               => 'scalar',
	];

	/**
	 * The working set: archived rows are left out. The default.
	 *
	 * @var string
	 */
	public const ARCHIVED_EXCLUDE = 'exclude';

	/**
	 * The archived lens: archived rows and nothing else.
	 *
	 * @var string
	 */
	public const ARCHIVED_ONLY = 'only';

	/**
	 * Both lenses at once. The literal is the parameter value callers send
	 * (`_archived=any`), so the two cannot drift apart.
	 *
	 * @var string
	 */
	public const ARCHIVED_ANY = 'any';

	/**
	 * The deleted lens alone, for a consumer that checks whether this
	 * OpenRegister offers `_deleted` (dossiq's Deleted cases page does).
	 *
	 * @var string
	 */
	public const DELETED_ONLY = DeletedLens::ONLY;

	/**
	 * Metadata columns every magic table carries.
	 *
	 * Mirrors MagicMapper::getMetadataColumns(); kept as a literal here so a
	 * `@self` filter can be rejected by name before it ever reaches SQL, rather
	 * than being interpolated into a query that fails as an opaque HTTP 500.
	 *
	 * @var string[]
	 */
	private const METADATA_COLUMNS = [
		'_id',
		'_uuid',
		'_slug',
		'_uri',
		'_version',
		'_register',
		'_schema',
		'_schema_version',
		'_owner',
		'_organisation',
		'_application',
		'_name',
		'_description',
		'_summary',
		'_image',
		'_folder',
		'_size',
		'_created',
		'_updated',
		'_last_user_activity',
		'_expires',
		'_deleted',
		'_archived',
		'_frozen',
		'_status',
		'_locked',
		'_files',
		'_relations',
		'_geo',
		'_groups',
		'_retention',
		'_tmlo',
		'_validation',
		'_authorization',
	];

	/**
	 * Tracks filter properties that don't exist in the schema during search.
	 * Reset at the start of each searchObjects call.
	 *
	 * @var array<string>
	 */
	private array $ignoredFilters = [];

	/**
	 * The pg_trgm probe, built on first use.
	 *
	 * @var MagicPgTrgmProbe|null
	 */
	private ?MagicPgTrgmProbe $trgmProbe = null;

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
	 * Parser for boolean search terms.
	 *
	 * Built here rather than injected: it is pure and dependency-free, and
	 * adding a seventh constructor argument would break every caller that
	 * constructs this handler positionally.
	 *
	 * @var SearchTermParserInterface
	 */
	private SearchTermParserInterface $termParser;

	/**
	 * Compiler turning a parsed term into SQL.
	 *
	 * @var SearchTermSqlCompilerInterface
	 */
	private SearchTermSqlCompilerInterface $termCompiler;

	/**
	 * Tells metadata columns from underscore-named property columns.
	 *
	 * @var MagicMetadataColumn
	 */
	private MagicMetadataColumn $columnRoles;

	/**
	 * Reads filters on a path inside an object property and writes their SQL.
	 *
	 * @var NestedPathFilter
	 */
	private NestedPathFilter $nestedPaths;

	/**
	 * Constructor for MagicSearchHandler
	 *
	 * @param IDBConnection $db Database connection for queries
	 * @param LoggerInterface $logger Logger for debugging and error reporting
	 * @param MagicRbacHandler $rbacHandler RBAC handler for access control
	 * @param MagicOrganizationHandler $organizationHandler Organization handler for multi-tenancy
	 * @param SchemaTypeConverterInterface $schemaTypeConverter Schema-driven type converter for row values
	 * @param DateTimeNormalizerInterface $dateTimeNormalizer Normaliser for date/date-time property formats
	 * @param RelatedRowQueryApplier $relatedRows Applies a filter that reaches through a reference into the
	 *                                            related schema's own rows
	 * @param LanguageServiceInterface|null $languageService The request's languages, so a translatable property sorts by the
	 *                                              value the response shows
	 * @param MagicMetadataColumnHealer|null $metadataColumns Knows which metadata columns a table that could not
	 *                                                        be healed still lacks, so the lifecycle predicates
	 *                                                        leave them out
	 * @param ExplicitRevealInterface|null $explicitReveal The request's reveal state; a filter or sort on an explicit-reveal
	 *                                            property is refused unless the code is trusted
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) All parameters are DI-injected dependencies
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly MagicRbacHandler $rbacHandler,
		private readonly MagicOrganizationHandler $organizationHandler,
		private readonly SchemaTypeConverterInterface $schemaTypeConverter,
		private readonly DateTimeNormalizerInterface $dateTimeNormalizer,
		private readonly RelatedRowQueryApplier $relatedRows,
		private readonly ?LanguageServiceInterface $languageService = null,
		private readonly ?MagicMetadataColumnHealer $metadataColumns = null,
		private readonly ?ExplicitRevealInterface $explicitReveal = null,
	) {
		$this->termParser = SearchGrammar::termParser();
		$this->termCompiler = SearchGrammar::termCompiler();
		$this->columnRoles  = new MagicMetadataColumn();
		$this->nestedPaths  = new NestedPathFilter();
	}//end __construct()

	/**
	 * Check if PostgreSQL pg_trgm extension is available for fuzzy search.
	 *
	 * This extension provides the similarity() function and % operator
	 * for fuzzy text searching. Result is cached for the request lifetime.
	 *
	 * @return bool True if pg_trgm is available, false otherwise.
	 */
	public function hasPgTrgmExtension(): bool {
		$this->trgmProbe ??= new MagicPgTrgmProbe(db: $this->db, logger: $this->logger);

		return $this->trgmProbe->available();
	}//end hasPgTrgmExtension()

	/**
	 * Get the list of filter properties that were ignored during the last search.
	 *
	 * These are properties that were requested as filters but don't exist in the schema.
	 *
	 * @return array<string> List of ignored filter property names
	 */
	public function getIgnoredFilters(): array {
		return $this->ignoredFilters;
	}//end getIgnoredFilters()

	/**
	 * Search objects in a specific register-schema table using clean query structure
	 *
	 * This method provides comprehensive search capabilities optimized for
	 * schema-specific dynamic tables.
	 *
	 * @param array $query Search query array with filters and options
	 * @param Register $register Register context for the search
	 * @param Schema $schema Schema context for the search
	 * @param string $tableName Target dynamic table name
	 * @param array|null $dateTimeColumns Columns whose real type is a date or timestamp; null when unknown
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity[]|int Array of ObjectEntity objects or count if _count=true
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @phpstan-param array<string, mixed> $query
	 * @phpstan-param array<int, string>|null $dateTimeColumns
	 *
	 * @psalm-param array<string, mixed> $query
	 * @psalm-param array<int, string>|null $dateTimeColumns
	 *
	 * @psalm-return int|list<ObjectEntity>
	 */
	public function searchObjects(array $query, Register $register, Schema $schema, string $tableName, ?array $dateTimeColumns = null): array|int {
		$table = new RegisterSchema(register: $register, schema: $schema);
		return $this->searchTable(query: $query, tableSearch: new TableSearch(table: $table, tableName: $tableName, dateTimeColumns: $dateTimeColumns));
	}//end searchObjects()

	/**
	 * Search one register+schema table (see searchObjects()).
	 *
	 * @param array       $query       Search query array with filters and options.
	 * @param TableSearch $tableSearch The register, schema, table name and date-time columns searched.
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
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function searchTable(array $query, TableSearch $tableSearch): array|int {
		$register  = $tableSearch->table->register;
		$schema    = $tableSearch->table->schema;
		$tableName = $tableSearch->tableName;
		// Reset ignored filters tracking for this search.
		$this->ignoredFilters = [];

		// Extract options from query (prefixed with _).
		// `_limit` is normalised to a positive row count or null (= unlimited).
		// Doctrine reads null on setMaxResults() as "retrieve all results", so
		// this is the one place that decides whether the SQL carries a LIMIT.
		// Before normalisation `_limit=0` reached setMaxResults(0) and produced
		// `LIMIT 0` — an empty page, HTTP 200, no explanation.
		$limit = QueryLimit::normalise($query['_limit'] ?? null);
		$offset = $query['_offset'] ?? null;
		$page = $query['_page'] ?? null;
		$order = (new OrderParameter(order: $query['_order'] ?? []))->fields();

		$count = $query['_count'] ?? false;
		$search = $query['_search'] ?? null;

		// Convert page to offset if page is provided but offset is not.
		// Page is 1-indexed, so page 1 = offset 0, page 2 = offset $limit, etc.
		if ($page !== null && $offset === null && $limit !== null) {
			$offset = ((int)$page - 1) * (int)$limit;
		}

		// Build filtered query (applies all WHERE conditions).
		$queryBuilder = $this->buildTableQuery(
			query: $query,
			table: new MagicReadTable(schema: $schema, registerId: $register->getId(), tableName: $tableName)
		);

		// Check if fuzzy search is enabled for relevance scoring.
		$fuzzyEnabled = false;
		$searchTerm = null;
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
			return (int)$result->fetchOne();
		}

		$queryBuilder->select('t.*');

		// Add relevance score column when fuzzy search is enabled.
		// This allows us to return the similarity score as a percentage in @self.relevance.
		if ($fuzzyEnabled === true && $searchTerm !== null) {
			$searchTermParam = $queryBuilder->createNamedParameter($searchTerm);
			$queryBuilder->addSelect(
				$queryBuilder->createFunction(
					'ROUND(similarity(t._name::text, ' . "{$searchTermParam}) * 100)::integer AS _relevance"
				)
			);
		}

		// Apply sorting BEFORE pagination so the query optimizer can use
		// indexes for ORDER BY … LIMIT instead of sorting the full result set.
		$sort = new SortRequest(order: $order, schema: $schema);
		$sort->searchTerm = $searchTerm;
		$sort->recentViews = ($query['_recentViews'] ?? null);
		$sort->dateTimeColumns = $tableSearch->dateTimeColumns;
		$sort->sortLanguages = $this->sortLanguageChain(register: $register);
		$this->applyResultOrder(qb: $queryBuilder, sort: $sort);

		$queryBuilder->setMaxResults($limit)
			->setFirstResult($offset);

		$objects = $this->executeSearchQuery(qb: $queryBuilder, search: $tableSearch);
		// `@self.viewedAt` on a `_recent` page (read-history-on-audit-trail).
		$this->applyViewedAt(objects: $objects, recentViews: ($query['_recentViews'] ?? null));

		return $objects;
	}//end searchTable()

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
	 * @param IQueryBuilder $qb Query builder (must use FROM alias `t`).
	 * @param Schema $schema Schema for access-control rules.
	 * @param bool $_rbac Whether to apply RBAC filtering.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param int|null $registerId The register of the table being read, for shared master data.
	 * @param string|null $tableName The bare magic table being read, so a `_status` column it could not be
	 *                               given is left out of the draft predicate. Null keeps the predicate.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags mirror the search-path posture.
	 */
	public function applyAccessControlToQuery(
		IQueryBuilder $qb,
		Schema $schema,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?int $registerId = null,
		?string $tableName = null,
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
			run: new TableFilterRun(qb: $qb, query: [], table: new MagicReadTable(schema: $schema, registerId: $registerId, tableName: $tableName)),
			posture: new ReadPosture(rbac: $_rbac, multitenancy: $resolvedMultitenancy, multitenancyExplicit: false)
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
	 * @param array $query Search parameters including filters.
	 * @param Schema $schema The schema for property filtering.
	 * @param string $tableName The table to query.
	 * @param int|null $registerId The register this table belongs to. When omitted it is
	 *                             read from the query's reserved `register` key, which is
	 *                             how the facet paths carry it. A register that cannot be
	 *                             resolved simply yields no shared master data widening.
	 *
	 * @return IQueryBuilder QueryBuilder with all filters applied.
	 */
	public function buildFilteredQuery(array $query, Schema $schema, string $tableName, ?int $registerId = null): IQueryBuilder {
		return $this->buildTableQuery(query: $query, table: new MagicReadTable(schema: $schema, registerId: $registerId, tableName: $tableName));
	}//end buildFilteredQuery()

	/**
	 * Build a filtered query for one magic table with all WHERE conditions applied.
	 *
	 * The SINGLE SOURCE OF TRUTH for query filtering (see buildFilteredQuery()).
	 * Returns a QueryBuilder with FROM and WHERE clauses, but NO SELECT.
	 *
	 * @param array          $query Search parameters including filters.
	 * @param MagicReadTable $table The table to query, with its schema and register. A null
	 *                              register is read from the query's reserved `register` key,
	 *                              which is how the facet paths carry it.
	 *
	 * @return IQueryBuilder QueryBuilder with all filters applied.
	 */
	public function buildTableQuery(array $query, MagicReadTable $table): IQueryBuilder {
		$schema = $table->schema;
		// A filter or sort on a value that is only shown masked would read it without the reveal.
		$this->explicitReveal?->refuseOracleQuery(query: $query, schema: $schema);

		// Extract options from query (prefixed with _).
		$_rbac = $query['_rbac'] ?? true;
		$_multitenancy = $query['_multitenancy'] ?? true;

		// Resolve multitenancy flag based on public schema access and explicit request.
		$multitenancyExplicit = $this->isExplicitlyTrue(value: $query['_multitenancy_explicit'] ?? false);
		$_multitenancy = $this->resolveMultitenancyFlag(
			_multitenancy: $_multitenancy,
			multitenancyExplicit: $multitenancyExplicit,
			schema: $schema
		);

		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->from((string) $table->tableName, 't');

		// The register falls back to the query's reserved `register` key, for
		// the access-control filter and the related-row filter alike.
		$run = new TableFilterRun(
			qb: $queryBuilder,
			query: $query,
			table: new MagicReadTable(
				schema: $schema,
				registerId: ($table->registerId ?? $this->registerIdFromQuery(query: $query)),
				tableName: $table->tableName
			)
		);

		// Apply basic filters (deleted, archived, drafts).
		$this->applyBasicFilters(run: $run);

		// Apply multi-tenancy and RBAC access control filters.
		$this->applyAccessControlFilters(
			run: $run,
			posture: new ReadPosture(rbac: $_rbac, multitenancy: $_multitenancy, multitenancyExplicit: $multitenancyExplicit)
		);

		// Apply metadata, object-field and ID filters.
		$this->applyContentFilters(run: $run);

		// Apply the unread / favourites / recent lenses, full-text search and
		// relation filters.
		$this->applyLensAndSearchFilters(run: $run);

		// Narrow by rows of ANOTHER schema that point at this one. Does nothing
		// unless the query carries `_related`, so every existing call site is
		// unaffected; when it does, each block becomes an EXISTS subquery
		// carrying the RELATED schema's own access predicate.
		// The SAME register fallback the access-control filter above uses. Passing
		// the bare parameter here was wrong: the facet path calls this method
		// without a register id, so a facet request carrying `_related` was
		// refused even when the query itself named the register.
		$this->applyRelatedRowFilters(run: $run);

		// Narrow by one element of an array property (`_element`).
		$this->applyArrayElementFilters(run: $run);

		return $queryBuilder;
	}//end buildTableQuery()

	/**
	 * Narrow the query by `_element` blocks: an object matches when one element
	 * of the named array property carries every field of a block.
	 *
	 * A block on a property the schema does not declare as an array, or one
	 * the caller may not read, matches nothing and is reported as an ignored
	 * filter, the way an unknown nested path is. An encrypted property refuses.
	 *
	 * @param TableFilterRun $run The filter pass: query builder, request query and table.
	 *
	 * @return void
	 *
	 * @throws EncryptedFieldFilterException When the array property is encrypted.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-match-one-element-of-an-array-property
	 */
	private function applyArrayElementFilters(TableFilterRun $run): void {
		$qb         = $run->qb;
		$query      = $run->query;
		$schema     = $run->schema();
		$elements   = new ArrayElementFilter();
		$properties = ($schema->getProperties() ?? []);
		$isPostgres = $this->isPostgresPlatform();
		foreach ($elements->blocks(query: $query) as $block) {
			$root = $block['root'];
			if (($properties[$root]['x-openregister-encrypted'] ?? false) === true) {
				throw new EncryptedFieldFilterException(property: $root);
			}

			$condition = null;
			if (($properties[$root]['type'] ?? null) === 'array'
				&& $this->relatedRows->mayReadProperty(schema: $schema, property: $root) === true
			) {
				$column    = new NestedPathTarget(
					expression: 't.'.$this->sanitizeColumnName(name: $root),
					bind: static fn (string $value): string => (string)$qb->createNamedParameter($value),
					isPostgres: $isPostgres
				);
				$condition = $elements->condition(column: $column, fields: $block['fields']);
			}

			if ($condition === null) {
				$this->ignoredFilters[] = ArrayElementFilter::KEY.'['.$root.']';
				$qb->andWhere('1 = 0');
				continue;
			}

			$qb->andWhere($condition);
		}//end foreach
	}//end applyArrayElementFilters()

	/**
	 * Narrow the query by `_related` and `_referenced` blocks, or refuse it.
	 *
	 * 🔴 A REFUSAL HERE IS DELIBERATE AND MUST NOT BECOME A LOG LINE. Every
	 * other filter on this path that cannot be honoured is recorded in
	 * `$ignoredFilters` and skipped, which is right for a filter that narrows
	 * nothing. It is wrong for this one: a dropped `_related` block answers the
	 * UNFILTERED set to a deliberately narrow question, and the caller cannot
	 * tell from the response that the narrowing was never applied. So the
	 * exception travels.
	 *
	 * @param TableFilterRun $run The filter pass: query builder, request query and table.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a block names a schema that cannot be resolved.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	private function applyRelatedRowFilters(TableFilterRun $run): void {
		$query      = $run->query;
		$registerId = $run->table->registerId;
		if (array_key_exists('_related', $query) === false && array_key_exists('_referenced', $query) === false) {
			return;
		}

		if ($registerId === null) {
			throw new InvalidArgumentException(
				'A related-row filter needs to know which register to look the related schema up in. '
				. 'Filtering without it would read a table belonging to another register.'
			);
		}

		$register = new Register();
		$register->setId($registerId);

		$this->relatedRows->apply(
			target: new RelatedRowFilterTarget(
				qb: $run->qb,
				register: $register,
				organisationScopeFor: fn (Schema $related, ?int $relatedRegisterId): array => $this->organisationScopeForRelated(
					query: $query,
					related: new MagicReadTable(schema: $related, registerId: $relatedRegisterId)
				)
			),
			query: $query
		);
	}//end applyRelatedRowFilters()

	/**
	 * Refuse a query carrying `_related`, `_referenced` or `_element` where it cannot be honoured.
	 *
	 * 🔴 REFUSE, DO NOT DROP. The related-row filters are written for the
	 * QueryBuilder path (applyRelatedRowFilters). Underscore keys are skipped by
	 * the raw-SQL builder, so a cross-table UNION search or UNION facet carrying
	 * one answered the UNFILTERED set to a deliberately narrow question, and its
	 * counts disagreed with the single-schema facet for the same query.
	 *
	 * @param array<mixed> $query The request query.
	 *
	 * @return void
	 *
	 * @throws RelatedRowFilterNotSupportedException When the query carries either key.
	 *
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function refuseRelatedRowFilters(array $query): void {
		foreach (['_related', '_referenced', ArrayElementFilter::KEY] as $key) {
			if (array_key_exists($key, $query) === true) {
				throw new RelatedRowFilterNotSupportedException(
					message: sprintf(
						'\'%s\' filters are not supported on a query across several schemas; narrow the query to one schema. '
						. 'Answering without the filter would return rows it was meant to exclude.',
						$key
					)
				);
			}
		}
	}//end refuseRelatedRowFilters()

	/**
	 * The organisation scope a related-row subquery must apply to the related table.
	 *
	 * The SAME decision the list path takes for a table (`multitenancyApplies()`,
	 * the cascade widening, `resolveOrganizationScope()`), made for the RELATED
	 * schema with the request's own flags, so the subquery cannot consult rows
	 * the list of that table would not return. `SCOPE_ALL` when the boundary does
	 * not apply (system context, admin bypass, an RBAC rule that waives it).
	 *
	 * @param array<mixed>   $query   The request query, for `_rbac` and `_multitenancy`.
	 * @param MagicReadTable $related The related schema and the register its table lives in.
	 *
	 * @return array{mode: string, uuids: array<int, string>} The decided scope.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) admitOrganisationless() is a pure transform of the decided scope.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function organisationScopeForRelated(array $query, MagicReadTable $related): array {
		$registerId           = $related->registerId;
		$related              = $related->schema;
		$_rbac                = $this->flagFromQuery(value: ($query['_rbac'] ?? true));
		$multitenancyExplicit = $this->isExplicitlyTrue(value: ($query['_multitenancy_explicit'] ?? false));
		$resolved             = $this->resolveMultitenancyFlag(
			_multitenancy: $this->flagFromQuery(value: ($query['_multitenancy'] ?? true)),
			multitenancyExplicit: $multitenancyExplicit,
			schema: $related
		);

		$applies = $this->multitenancyApplies(
			schema: $related,
			_rbac: $_rbac,
			_multitenancy: $resolved,
			multitenancyExplicit: $multitenancyExplicit
		);
		if ($applies === false) {
			return ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []];
		}

		$scope = $this->organizationHandler->resolveOrganizationScope(
			adminBypassEnabled: $this->organizationHandler->isAdminOverrideEnabled(),
			registerId: $registerId,
			schemaId: $related->getId()
		);

		$posture = new ReadPosture(rbac: $_rbac, multitenancy: $resolved, multitenancyExplicit: $multitenancyExplicit);
		if ($this->organisationlessRowsAdmitted(schema: $related, posture: $posture) === true) {
			$scope = MagicOrganizationHandler::admitOrganisationless(scope: $scope);
		}

		return $scope;
	}//end organisationScopeForRelated()

	/**
	 * Apply metadata, object-field and ID filters to the query.
	 *
	 * Extracted from {@see buildFilteredQuery()} as a cohesive block; each filter
	 * guards itself so no-op filters add no conditions.
	 *
	 * Reserved params (e.g. `register`, `schema`, `registers`, `schemas`,
	 * `extend`) carry query CONTEXT, not object-field filters. They are NOT
	 * underscore-prefixed, so without the {@see isObjectFieldFilterKey()} guard
	 * they leak into applyObjectFilters(), which treats them as unknown schema
	 * properties and emits a `1 = 0` condition — silently returning ZERO results.
	 * This is why `ObjectService::findAll(['filters' => ['register' => …,
	 * 'schema' => …, …]])` (which always injects register/schema into the
	 * filters) could not surface a just-written object in-request. Mirror the
	 * raw-SQL UNION path, which already excludes getReservedParams().
	 *
	 * @param TableFilterRun $run The filter pass: query builder, request query and table.
	 *
	 * @return void
	 */
	private function applyContentFilters(TableFilterRun $run): void {
		$qb     = $run->qb;
		$query  = $run->query;
		$schema = $run->schema();
		// Extract and clean filters from the query.
		$metadataFilters = $query['@self'] ?? [];
		$objectFilters = array_filter(
			$query,
			fn ($key): bool => $this->isObjectFieldFilterKey(key: $key),
			ARRAY_FILTER_USE_KEY
		);
		$ids = $query['_ids'] ?? null;

		// Apply metadata filters.
		if (empty($metadataFilters) === false) {
			$this->applyMetadataFilters(qb: $qb, filters: $metadataFilters);
		}

		// Apply object field filters (schema-specific columns).
		if (empty($objectFilters) === false) {
			$this->applyObjectFilters(run: $run, filters: $objectFilters);
		}

		// Apply ID filtering if provided.
		if ($ids !== null && empty($ids) === false) {
			$this->applyIdFilters(qb: $qb, ids: $ids);
		}
	}//end applyContentFilters()

	/**
	 * Apply the personal lenses, full-text search and relation filters.
	 *
	 * Extracted from {@see buildFilteredQuery()} as a cohesive block. The lenses
	 * are resolved IN the query so the page, the total and the facets cannot
	 * disagree about what was excluded; each filter guards itself so this method
	 * keeps its branch count and no-op filters add no conditions.
	 *
	 * @param TableFilterRun $run The filter pass: query builder, request query and table.
	 *
	 * @return void
	 */
	private function applyLensAndSearchFilters(TableFilterRun $run): void {
		$qb     = $run->qb;
		$query  = $run->query;
		// The unread lens, resolved IN the query so the page, the total and the
		// facets cannot disagree about what was excluded.
		$this->applyUnreadFilter(qb: $qb, userId: ($query['_unreadFor'] ?? null));

		// The following lens, resolved in the query for the same reason.
		// `_watchingFor` also answers the deprecated `_favourite=true`, since a
		// favourite is a follow (`merge-follow-and-favourites`). The recent lens
		// needs no filter here: it arrives as `_ids`, resolved from the audit
		// trail's read history at the edge (SearchQueryHandler), so every search
		// path honours it, the cross-table UNION included.
		$this->applyPersonalLensFilter(qb: $qb, userId: ($query['_watchingFor'] ?? null));

		// Apply full-text search if provided.
		// Fuzzy matching is only enabled when _fuzzy=true parameter is explicitly set.
		$search = $query['_search'] ?? null;
		if ($search !== null && trim($search) !== '') {
			$fuzzyEnabled = $this->isFuzzySearchEnabled(fuzzyParam: $query['_fuzzy'] ?? null);
			$this->applyFullTextSearch(run: $run, fuzzyEnabled: $fuzzyEnabled);
		}

		$this->applyRelationsContains(qb: $qb, query: $query);

		// Apply dotted relation-field filters: `_relations.<field>` => <id>.
		$this->applyRelationFieldFilters(qb: $qb, query: $query);
	}//end applyLensAndSearchFilters()

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
	private function isObjectFieldFilterKey(string $key): bool {
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
	 * @param IQueryBuilder $qb Query builder to modify.
	 * @param array<string,mixed> $query The full search query.
	 *
	 * @return void
	 */
	private function applyRelationFieldFilters(IQueryBuilder $qb, array $query): void {
		foreach ($this->extractRelationFieldFilters(query: $query) as $relField => $relValue) {
			$this->applyRelationFieldFilter(qb: $qb, match: new RelationFieldMatch(field: $relField, value: $relValue));
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
	 * @param array $query Search parameters including filters.
	 * @param Schema $schema The schema for property filtering.
	 * @param array|null $existingColumns Optional list of existing column names.
	 * @param int|null $registerId The register whose table this condition set is built for. It is
	 *                             what lets a shared master data declaration (REQ-SLE-001) widen
	 *                             the organisation boundary here exactly as it widens it on the
	 *                             QueryBuilder path. A caller that names no register gets no
	 *                             widening, which is narrower and therefore safe.
	 * @param string|null $tableName The bare magic table the conditions are for, so a metadata column
	 *                               the table could not be given is left out of the lifecycle
	 *                               predicates. Null keeps every predicate.
	 *
	 * @return string[] Array of SQL WHERE conditions (without leading AND/WHERE).
	 *
	 * @throws UnknownMetadataFieldException When a `@self` key names no metadata column.
	 * @throws RelatedRowFilterNotSupportedException When the query carries `_related` or `_referenced`, which this raw-SQL builder cannot honour.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function buildWhereConditionsSql(
		array $query, Schema $schema, ?array $existingColumns = null, ?int $registerId = null, ?string $tableName = null
	): array {
		$table = new MagicReadTable(schema: $schema, registerId: $registerId, tableName: $tableName);
		return $this->buildWhereClause(request: new WhereClauseRequest(query: $query, table: $table, existingColumns: $existingColumns));
	}//end buildWhereConditionsSql()

	/**
	 * Build WHERE conditions as raw SQL for one magic table (see buildWhereConditionsSql()).
	 *
	 * @param WhereClauseRequest $request The request query, the table and its known columns.
	 *
	 * @return string[] Array of SQL WHERE conditions (without leading AND/WHERE).
	 *
	 * @throws UnknownMetadataFieldException When a `@self` key names no metadata column.
	 * @throws RelatedRowFilterNotSupportedException When the query carries `_related` or `_referenced`.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
	 */
	public function buildWhereClause(WhereClauseRequest $request): array {
		$query  = $request->query;
		$schema = $request->table->schema;
		$this->refuseRelatedRowFilters(query: $query);
		$this->explicitReveal?->refuseOracleQuery(query: $query, schema: $schema);

		// Get connection for value quoting through QueryBuilder.
		$qb = $this->db->getQueryBuilder();
		$connection = $qb->getConnection();
		// Detect platform once and pass down (avoids per-UNION-arm DBAL lookups).
		$isPostgres = $this->isPostgresPlatform();
		$run = new ConditionSqlRun(query: $query, table: $request->table, connection: $connection, isPostgres: $isPostgres);

		// Extract options from query.
		$search = $query['_search'] ?? null;

		$conditions = array_merge(
			$this->lifecycleConditionsSql(run: $run),
			$this->boundaryConditionsSql(run: $run)
		);

		// 3. `@self` metadata filters.
		// This step was missing entirely: the comment numbering jumped 2 → 4 and
		// buildObjectFilterConditionsSql() skips every `@`-prefixed key, so a
		// metadata filter was silently DROPPED on the UNION path. A dropped
		// filter returns too MANY rows, which is the dangerous direction — the
		// single-table path returned too few for the same query.
		$metadataConditions = $this->buildMetadataFilterConditionsSql(run: $run);
		$conditions = array_merge($conditions, $metadataConditions);

		// `_ids`, as applyIdFilters() applies it on the QueryBuilder path. It
		// was missing here, so a cross-table `_recent=true` search (the lens
		// arrives as `_ids`) returned every row of every arm.
		$idCondition = $this->idConditionSql(query: $query, connection: $connection);
		if ($idCondition !== null) {
			$conditions[] = $idCondition;
		}

		// 4. Full-text search filter with optional fuzzy matching.
		if ($search !== null && trim($search) !== '') {
			$scope = new SearchLeafScope(schema: $schema, isPostgres: $isPostgres);
			$scope->fuzzyEnabled = $this->isFuzzySearchEnabled(fuzzyParam: $query['_fuzzy'] ?? null);
			$scope->connection = $connection;
			$scope->existingColumns = $request->existingColumns;
			$conditions[] = $this->buildSearchConditionSql(search: trim($search), scope: $scope);
		}

		// 5. Object field filters (non-reserved, non-metadata).
		$objectConditions = $this->buildObjectFilterConditionsSql(run: $run);
		$conditions = array_merge($conditions, $objectConditions);

		// 6. TMLO metadata JSON field filters (tmlo.archiefstatus, tmlo.archiefnominatie, etc.).
		$tmloConditions = $this->buildTmloFilterConditionsSql(
			query: $query,
			connection: $connection
		);
		$conditions = array_merge($conditions, $tmloConditions);

		// 7. Dotted relation-field filters (`_relations.<field>` => <id>).
		$relationConditions = $this->buildRelationFilterConditionsSql(
			query: $query,
			connection: $connection
		);
		$conditions = array_merge($conditions, $relationConditions);

		return $conditions;
	}//end buildWhereClause()

	/**
	 * The `_ids` restriction, as one SQL fragment: uuid or slug.
	 *
	 * The string-built twin of applyIdFilters(). Every value is quoted by the
	 * connection, because this SQL is concatenated and cannot bind.
	 *
	 * @param array $query      The query parameters.
	 * @param mixed $connection The connection, for value quoting.
	 *
	 * @return string|null The condition, or null when no `_ids` restriction was asked.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	private function idConditionSql(array $query, mixed $connection): ?string {
		$ids = ($query['_ids'] ?? null);
		if (is_array($ids) === false || $ids === []) {
			return null;
		}

		$quoted = implode(
			', ',
			array_map(static fn ($id): string => (string) $connection->quote((string) $id), array_values($ids))
		);

		return '(_uuid IN ('.$quoted.') OR _slug IN ('.$quoted.'))';
	}//end idConditionSql()

	/**
	 * The deleted and archived predicates, as SQL fragments.
	 *
	 * Spelled here as well as in `applyBasicFilters()` because the two paths
	 * build the same WHERE by different means, and a condition added to only
	 * one of them is exactly the drift that made the UNION path silently
	 * return MORE rows than the single-table path for the same query. Too many
	 * rows is the dangerous direction, and an archived record surfacing in a
	 * working list is that failure with a record attached.
	 *
	 * @param ConditionSqlRun $run The condition pass: query, table, connection and platform.
	 *
	 * @return string[] The conditions, without leading AND.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 */
	private function lifecycleConditionsSql(ConditionSqlRun $run): array {
		$query          = $run->query;
		$schema         = $run->schema();
		$missingColumns = $this->missingMetadataColumns(tableName: $run->table->tableName);
		$conditions = [];

		$lens = new DeletedLens();
		$deleted = $lens->condition(mode: $lens->mode(query: $query), columnPrefix: '');
		if ($deleted !== null) {
			$conditions[] = $deleted;
		}

		$archivedMode = $this->resolveArchivedMode(query: $query);
		$columns = new LifecycleColumns(prefix: '', missing: $missingColumns);
		$archived = $this->archivedConditionSql(mode: $archivedMode, columns: $columns);
		if ($archived !== null) {
			$conditions[] = $archived;
		}

		// Decision 201: drafts stay out of a list unless asked for, on this
		// path as on applyBasicFilters(), with the caller's own kept in view.
		$userId = $this->rbacHandler->getCurrentUserId();
		$owner = null;
		if ($userId !== null && $userId !== '') {
			$owner = (string)$this->db->getQueryBuilder()->getConnection()->quote($userId);
		}

		$drafts = $this->draftConditionSql(columns: $columns, request: new DraftListRequest(query: $query, schema: $schema, owner: $owner));
		if ($drafts !== null) {
			$conditions[] = $drafts;
		}

		return $conditions;
	}//end lifecycleConditionsSql()

	/**
	 * The metadata columns a table is known to lack after an attempt to heal it.
	 *
	 * Empty in the normal case: a table is healed on its first use, before it
	 * is queried. Only a table the healer could not alter (no permission, or a
	 * DDL deferred out of an open transaction) answers non-empty.
	 *
	 * @param string|null $tableName Bare magic table name, null when unknown.
	 *
	 * @return string[] Missing metadata column names.
	 */
	private function missingMetadataColumns(?string $tableName): array {
		if ($this->metadataColumns === null || $tableName === null || $tableName === '') {
			return [];
		}

		return $this->metadataColumns->missingFor(tableName: $tableName);
	}//end missingMetadataColumns()

	/**
	 * The archive predicate, or null when it puts no condition on the list.
	 *
	 * A table without an `_archived` column holds no archived row, so the
	 * working set is every row and the archived lens is empty. Saying so in SQL
	 * is exact, not an approximation, and it keeps a table that could not be
	 * healed answering instead of failing every request.
	 *
	 * @param string           $mode    One of the ARCHIVED_* constants.
	 * @param LifecycleColumns $columns The table alias and the metadata columns this table lacks.
	 *
	 * @return string|null The predicate, without a leading AND.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 */
	private function archivedConditionSql(string $mode, LifecycleColumns $columns): ?string {
		if ($mode === self::ARCHIVED_ANY) {
			return null;
		}

		if ($columns->lacks(column: '_archived') === true) {
			if ($mode === self::ARCHIVED_ONLY) {
				return '1=0';
			}

			return null;
		}

		if ($mode === self::ARCHIVED_ONLY) {
			return $columns->prefix.'_archived IS NOT NULL';
		}

		return $columns->prefix.'_archived IS NULL';
	}//end archivedConditionSql()

	/**
	 * The draft predicate (decision 201), or null when it puts no condition on the list.
	 *
	 * Without a `_status` column no row is a draft, so the default lens keeps
	 * every row and the drafts-only lens is empty.
	 *
	 * @param LifecycleColumns $columns The column prefix and the metadata columns the table lacks.
	 * @param DraftListRequest $request The query, the schema listed and the caller.
	 *
	 * @return string|null The predicate, without a leading AND.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-schema-must-be-able-to-keep-the-callers-own-drafts-out-of-its-lists
	 */
	private function draftConditionSql(LifecycleColumns $columns, DraftListRequest $request): ?string {
		$lens = new DraftListLens();
		$mode = $lens->modeFor(query: $request->query, schema: $request->schema);

		if ($columns->lacks(column: '_status') === true) {
			if ($mode === DraftListLens::ONLY) {
				return '1=0';
			}

			return null;
		}

		return $lens->clauseFor(listing: new DraftListing(mode: $mode, owner: $request->owner), columnPrefix: $columns->prefix);
	}//end draftConditionSql()

	/**
	 * The organisation and RBAC predicates, as SQL fragments.
	 *
	 * The organisation boundary used to be missing here, and missing meant
	 * OPEN. The RBAC half has carried the scope-and-grant predicate since
	 * object-level-sharing landed, so the union path decided private scope and
	 * per-object grants correctly while returning rows from OTHER
	 * organisations, measured by
	 * `PrivateScopeParityIntegrationTest::testUnionPathDoesNotCrossTheTenantEdge`,
	 * which asserted the leak so that closing it would fail the test rather
	 * than pass unnoticed.
	 *
	 * The decision is the SAME one the QueryBuilder path takes
	 * (`multitenancyApplies()`); only the rendering differs, because these
	 * callers build SQL by string concatenation and cannot bind parameters.
	 *
	 * @param ConditionSqlRun $run The condition pass: query, table, connection and platform.
	 *
	 * @return string[] The conditions, without leading AND.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
	 */
	private function boundaryConditionsSql(ConditionSqlRun $run): array {
		$query      = $run->query;
		$table      = $run->table;
		$connection = $run->connection;
		$conditions = [];
		$schema     = $table->schema;
		$rbac       = ($query['_rbac'] ?? true);

		$multitenancyExplicit = $this->isExplicitlyTrue(value: $query['_multitenancy_explicit'] ?? false);
		$resolvedMultitenancy = $this->resolveMultitenancyFlag(
			_multitenancy: $this->flagFromQuery(value: ($query['_multitenancy'] ?? true)),
			multitenancyExplicit: $multitenancyExplicit,
			schema: $schema
		);

		$multitenancyApplies = $this->multitenancyApplies(
			schema: $schema,
			_rbac: $this->flagFromQuery(value: $rbac),
			_multitenancy: $resolvedMultitenancy,
			multitenancyExplicit: $multitenancyExplicit
		);

		if ($multitenancyApplies === true) {
			$orgCondition = $this->buildOrganizationConditionSql(
				boundary: new OrganisationBoundary(
					registerId: ($table->registerId ?? $this->registerIdFromQuery(query: $query)),
					schemaId: $schema->getId(),
					withOrgless: $this->organisationlessRowsAdmitted(
						schema: $schema,
						posture: new ReadPosture(
							rbac: $this->flagFromQuery(value: $rbac),
							multitenancy: $resolvedMultitenancy,
							multitenancyExplicit: $multitenancyExplicit
						)
					)
				),
				connection: $connection
			);
			$crossBranch = null;
			if ($multitenancyExplicit === false && $this->rbacHandler->hasCrossOrganisationBranch(schema: $schema) === true) {
				$crossBranch = $this->rbacHandler->getCrossOrganisationBranchSql(schema: $schema);
			}

			if ($orgCondition !== null && $crossBranch !== null) {
				$orgCondition = '(' . $orgCondition . ' OR ' . $crossBranch . ')';
			}

			if ($orgCondition !== null) {
				$conditions[] = $orgCondition;
			}
		}

		if ($rbac === true) {
			$rbacCondition = $this->buildRbacConditionSql(schema: $schema, tableName: $table->tableName);
			if ($rbacCondition !== null) {
				$conditions[] = $rbacCondition;
			}
		}

		return $conditions;
	}//end boundaryConditionsSql()

	/**
	 * Read a reserved boolean flag out of a query, failing closed.
	 *
	 * Query-string parameters arrive as strings, so `"false"` must not be read
	 * as the boolean true simply because it is a non-empty string, and `"true"`
	 * must not be read as false because it is not identical to true. A value
	 * that means neither (an array, an object, a typo) leaves the boundary ON:
	 * the only flag this reads is one that TURNS ACCESS CONTROL OFF, and an
	 * unreadable request is not permission to skip it.
	 *
	 * @param mixed $value The raw query value.
	 *
	 * @return bool The flag, defaulting to true.
	 */
	private function flagFromQuery(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($parsed === null) {
			return true;
		}

		return $parsed;
	}//end flagFromQuery()

	/**
	 * Decide whether the organisation boundary applies to this read.
	 *
	 * Extracted from {@see applyAccessControlFilters()} so the QueryBuilder
	 * path and the string-SQL path (UNION search, UNION facets) take ONE
	 * decision and only render it differently. The two disagreeing is exactly
	 * how the union path came to return another organisation's rows: the RBAC
	 * half was carried across and this half was not, and nothing compared them.
	 *
	 * @param Schema $schema               The schema being read.
	 * @param bool   $_rbac                Whether RBAC filtering is on.
	 * @param bool   $_multitenancy        The multitenancy flag, ALREADY resolved against the schema.
	 * @param bool   $multitenancyExplicit Whether the caller explicitly asked for it.
	 *
	 * @return bool True when the organisation filter must be emitted.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flags are the request posture, mirrored.
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	private function multitenancyApplies(
		Schema $schema,
		bool $_rbac,
		bool $_multitenancy,
		bool $multitenancyExplicit,
	): bool {
		// Code acting for an organisation always reads inside it.
		if (OrganisationContext::isActive() === true) {
			return true;
		}

		if ($_multitenancy === false) {
			return false;
		}

		// Without RBAC no rule can waive the boundary: apply multitenancy as normal.
		if ($_rbac === false) {
			return true;
		}

		return $this->rbacKeepsOrganisationBoundary(schema: $schema, multitenancyExplicit: $multitenancyExplicit);
	}//end multitenancyApplies()

	/**
	 * Whether the organisation boundary still applies once RBAC is on.
	 *
	 * A caller who qualifies for an RBAC rule (simple or conditional) that
	 * bypasses multitenancy reads without the boundary (RBAC controls access),
	 * unless they explicitly asked for it with `_multi=true`, or hold a
	 * per-object grant.
	 *
	 * @param Schema $schema               The schema being read.
	 * @param bool   $multitenancyExplicit Whether the caller explicitly asked for multitenancy.
	 *
	 * @return bool True when the organisation filter must be emitted.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the request posture, mirrored.
	 */
	private function rbacKeepsOrganisationBoundary(Schema $schema, bool $multitenancyExplicit): bool {
		$userHasRbacAccess = $this->rbacHandler->hasConditionalRulesBypassingMultitenancy(
			schema: $schema,
			action: 'read'
		);

		// A per-object grant must never widen the tenant edge (ADR-002; design
		// D3c). The grant branch is OR-ed into the RBAC filter, so on a schema
		// whose conditional rules would otherwise SKIP the organisation filter a
		// grant would become a cross-tenant hole. Reached rows through a grant:
		// the tenant edge stands.
		if ($this->rbacHandler->currentCallerHoldsObjectGrants() === true) {
			return true;
		}

		// No RBAC access: apply multitenancy as normal. With RBAC access, only an
		// explicit `_multi=true` keeps the boundary to restrict results to the org.
		return $userHasRbacAccess === false || $multitenancyExplicit === true;
	}//end rbacKeepsOrganisationBoundary()

	/**
	 * Whether the organisation boundary of a default read carries a branch for
	 * the rows the caller's conditional `crossOrganisation` rules admit, which a
	 * renderer of its own (the aggregation fast path) cannot express.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the boundary has a cross-organisation branch.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function organisationBoundaryHasCrossOrganisationBranch(Schema $schema): bool {
		return $this->rbacHandler->hasCrossOrganisationBranch(schema: $schema);
	}//end organisationBoundaryHasCrossOrganisationBranch()

	/**
	 * Whether a default read (no `_multi` asked for) waives the organisation
	 * boundary for this caller because RBAC governs the rows instead.
	 *
	 * The same decision {@see multitenancyApplies()} takes for a list, with the
	 * list's defaults (RBAC on, multitenancy on, not explicit), for a caller that
	 * renders the boundary in its own SQL (the aggregation fast path) and must
	 * agree with the list about which rows exist. A raw query that applied the
	 * boundary where the list waives it counted 0 of a teacher's 1189 readable
	 * rows (learniq attendance-record, measured 2026-10-02).
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the list would NOT apply the organisation filter.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function organisationBoundaryWaivedByRbac(Schema $schema): bool {
		$resolvedMultitenancy = $this->resolveMultitenancyFlag(
			_multitenancy: true,
			multitenancyExplicit: false,
			schema: $schema
		);

		return $this->multitenancyApplies(
			schema: $schema,
			_rbac: true,
			_multitenancy: $resolvedMultitenancy,
			multitenancyExplicit: false
		) === false;
	}//end organisationBoundaryWaivedByRbac()

	/**
	 * Whether a default read (no `_multi` asked for) widens the organisation
	 * boundary by the rows with no organisation, because the caller's grant
	 * comes from the register cascade.
	 *
	 * The aggregation fast path renders the boundary in its own SQL and must
	 * agree with the list about which rows exist, so it asks this with the
	 * list's defaults (RBAC on, not explicit).
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the list's organisation filter also admits org-less rows.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function organisationlessRowsAdmittedByRbac(Schema $schema): bool {
		return $this->organisationlessRowsAdmitted(
			schema: $schema,
			posture: new ReadPosture(rbac: true, multitenancy: true, multitenancyExplicit: false)
		);
	}//end organisationlessRowsAdmittedByRbac()

	/**
	 * Whether the organisation filter, where it applies, also admits the rows
	 * with no organisation.
	 *
	 * True only for a caller the REGISTER CASCADE grants on a schema with no
	 * authorization block of its own. A schema's own block keeps deciding
	 * through {@see multitenancyApplies()}, unchanged, and an explicit `_multi`
	 * keeps the strict filter, as it does for a schema's own rules.
	 *
	 * @param Schema      $schema  The schema being read.
	 * @param ReadPosture $posture The request posture: RBAC on, and whether multitenancy was asked for explicitly.
	 *
	 * @return bool True when org-less rows join the caller's organisation scope.
	 */
	private function organisationlessRowsAdmitted(Schema $schema, ReadPosture $posture): bool {
		if ($posture->rbac === false || $posture->multitenancyExplicit === true) {
			return false;
		}

		return $this->rbacHandler->admitsOrganisationlessRowsThroughCascade(schema: $schema, action: 'read');
	}//end organisationlessRowsAdmitted()

	/**
	 * Render the organisation boundary as raw SQL, for the string-built paths.
	 *
	 * The DECISION is {@see MagicOrganizationHandler::resolveOrganizationScope()},
	 * the single source of truth; this only renders it, the way
	 * `AggregationRunner` renders the same decision for its native SQL. The
	 * column is unqualified (`_organisation`, not `t._organisation`) because
	 * every caller of {@see buildWhereConditionsSql()} builds `FROM <table>`
	 * with no alias, exactly as the `_deleted IS NULL` condition above does.
	 *
	 * An unknown mode FAILS CLOSED here rather than returning null. The
	 * aggregation renderer can answer an unknown mode by refusing and falling
	 * back to the PHP path; a UNION arm has nothing to fall back to, so the only
	 * safe answer to "I cannot render this boundary" is to return no rows.
	 *
	 * @param OrganisationBoundary $boundary   The register, schema and org-less admission of the table.
	 * @param object               $connection The connection that quotes the organisation uuids.
	 *
	 * @return string|null The SQL condition, or null when every row is in scope.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) admitOrganisationless() is a pure transform of the decided scope.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
	 */
	private function buildOrganizationConditionSql(OrganisationBoundary $boundary, object $connection): ?string {
		$scope = $this->organisationScopeFor(boundary: $boundary);

		$column = '_organisation';

		// Read the mode rather than assume it: a decision that arrives without
		// one is a decision this renderer cannot read, and the answer to that is
		// the empty set, not the whole table.
		$mode = ($scope['mode'] ?? null);

		if ($mode === MagicOrganizationHandler::SCOPE_ALL) {
			return null;
		}

		if ($mode === MagicOrganizationHandler::SCOPE_NULL_ONLY) {
			return $column . ' IS NULL';
		}

		$scoped = [
			MagicOrganizationHandler::SCOPE_IN,
			MagicOrganizationHandler::SCOPE_IN_OR_NULL,
		];
		if (in_array($mode, $scoped, true) === false) {
			// SCOPE_NONE, and anything this renderer does not know.
			return '1 = 0';
		}

		$uuids = array_values(array_filter(
			($scope['uuids'] ?? []),
			static fn ($uuid): bool => is_string($uuid) === true && $uuid !== ''
		));

		if (empty($uuids) === true) {
			// "In these organisations" with no organisations named is the empty
			// set, not everything.
			return '1 = 0';
		}

		$quoted = array_map(
			static fn (string $uuid): string => $connection->quote($uuid),
			$uuids
		);

		$condition = $column . ' IN (' . implode(', ', $quoted) . ')';

		if ($mode === MagicOrganizationHandler::SCOPE_IN_OR_NULL) {
			// SQL `IN` never matches NULL, so the org-less rows an admin may see
			// need their own disjunct. Leaving it out is how the aggregation API
			// once made every org-less row invisible.
			$condition = '(' . $condition . ' OR ' . $column . ' IS NULL)';
		}

		return $condition;
	}//end buildOrganizationConditionSql()

	/**
	 * Build the RBAC SQL condition
	 *
	 * @param Schema      $schema    Schema for RBAC rules
	 * @param string|null $tableName The bare magic table, so a missing `_status` column is left out of the draft predicate
	 *
	 * @return string|null SQL condition or null if no RBAC filtering needed
	 */
	private function buildRbacConditionSql(Schema $schema, ?string $tableName = null): ?string {
		$rbacResult = $this->rbacHandler->buildRbacConditionsSql(schema: $schema, action: 'read', tableName: $tableName);

		if ($rbacResult['bypass'] === false) {
			// User doesn't have unconditional access.
			if (empty($rbacResult['conditions']) === true) {
				// No access conditions met - deny all.
				return '1=0';
			}

			// OR together all RBAC conditions (access if ANY matches).
			return '(' . implode(' OR ', $rbacResult['conditions']) . ')';
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
	 * A term carrying AND/OR/NOT, brackets, a quoted phrase or a wildcard is
	 * parsed and compiled; anything else keeps the substring match this search
	 * has always applied, byte for byte.
	 *
	 * @param string          $search Trimmed search term
	 * @param SearchLeafScope $scope  The schema, connection, platform, columns and fuzzy flag
	 *
	 * @return string The SQL condition.
	 */
	private function buildSearchConditionSql(string $search, SearchLeafScope $scope): string {
		if ($this->termParser->needsParsing(term: $search) === true) {
			return $this->termCompiler->compile(
				node: $this->termParser->parse(term: $search),
				leafBuilder: fn (string $pattern, string $literal): string => $this->buildSearchLeafSql(
					leaf: $scope->nullSafeTerm(pattern: $pattern, literal: $literal)
				)
			);
		}

		return $this->buildSearchLeafSql(leaf: $scope->term(pattern: ('%' . $search . '%'), literal: $search));
	}//end buildSearchConditionSql()

	/**
	 * Build the SQL for one literal term across every searchable column.
	 *
	 * The boolean compiler calls it once per leaf while the column rules stay in one place.
	 *
	 * @param SearchLeaf $leaf The term in its scope.
	 *
	 * @return string The SQL condition, already parenthesised.
	 */
	private function buildSearchLeafSql(SearchLeaf $leaf): string {
		$searchConditions = $this->propertyLeafConditions(leaf: $leaf);

		// Search in metadata text fields.
		$likePattern = $leaf->quote(value: $leaf->pattern);
		foreach (['_name', '_description', '_summary'] as $metadataColumn) {
			$searchConditions[] = $leaf->columnMatch(column: $metadataColumn, likePattern: $likePattern);
		}

		// Add fuzzy matching ONLY for _name when explicitly requested via _fuzzy=true.
		// This uses pg_trgm similarity() for typo tolerance at ~13% performance cost.
		if ($leaf->scope->fuzzyEnabled === true) {
			$searchConditions[] = $leaf->similarity(plain: '_name::text', text: '_name::text');
		}

		return '(' . implode(' OR ', $searchConditions) . ')';
	}//end buildSearchLeafSql()

	/**
	 * The raw-SQL conditions of one term over the schema's own columns.
	 *
	 * @param SearchLeaf $leaf The term in its scope.
	 *
	 * @return array<int, string> One condition per searchable column.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PropertySearchProfile reads a property array and holds no state.
	 */
	private function propertyLeafConditions(SearchLeaf $leaf): array {
		$conditions = [];
		foreach ($this->searchableProperties(scope: $leaf->scope) as $propName => $propDef) {
			$quotedCol = $this->searchColumn(scope: $leaf->scope, propName: $propName);
			$matchType = PropertySearchProfile::matchTypeFor(property: $propDef);
			if ($matchType === PropertySearchProfile::MATCH_FUZZY && $leaf->scope->isPostgres === true) {
				$conditions[] = $leaf->similarity(plain: $quotedCol, text: "{$quotedCol}::text");
				continue;
			}

			$pattern = $this->patternForMatchType(matchType: $matchType, leaf: $leaf);
			$conditions[] = $leaf->columnMatch(column: $quotedCol, likePattern: $leaf->quote(value: $pattern));
		}

		return $conditions;
	}//end propertyLeafConditions()

	/**
	 * The schema properties a free-text term is matched against, in schema order.
	 *
	 * Which properties the scan reads is the property's own business; in UNION
	 * contexts only the columns that exist in the table take part.
	 *
	 * @param SearchLeafScope $scope The search scope.
	 *
	 * @return array<string, array> The searchable property definitions, by name.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PropertySearchProfile reads a property array and holds no state.
	 */
	private function searchableProperties(SearchLeafScope $scope): array {
		$searchable = [];
		foreach (($scope->schema->getProperties() ?? []) as $propName => $propDef) {
			if (is_array($propDef) === true
				&& PropertySearchProfile::participatesInFreeText(property: $propDef) === true
				&& $scope->hasColumn(columnName: $this->sanitizeColumnName(name: $propName)) === true
			) {
				$searchable[$propName] = $propDef;
			}
		}

		return $searchable;
	}//end searchableProperties()

	/**
	 * The quoted column of a property, so reserved words (e.g. 'case', 'status') stay valid SQL.
	 *
	 * @param SearchLeafScope $scope    The search scope.
	 * @param int|string      $propName The property name; PHP keeps a numeric array key as an int.
	 *
	 * @return string The quoted column name.
	 */
	private function searchColumn(SearchLeafScope $scope, int|string $propName): string {
		return $this->quoteIdentifier(name: $this->sanitizeColumnName(name: $propName), isPostgres: $scope->isPostgres);
	}//end searchColumn()

	/**
	 * The LIKE pattern one property wants for this term.
	 *
	 * `fulltext` keeps the pattern the term itself produced, wildcards and all.
	 * `exact` and `prefix` are the property speaking over the term: an
	 * identifier column that declares `exact` should not answer to half an
	 * identifier, whatever the caller typed around it.
	 *
	 * @param string     $matchType The resolved match type.
	 * @param SearchLeaf $leaf      The term.
	 *
	 * @return string The pattern to compare this column against.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchGrammar::likeEscape() is a pure function over a
	 *                                      string, shared so a declared match type escapes a
	 *                                      user's `%` exactly as a parsed term does.
	 */
	private function patternForMatchType(string $matchType, SearchLeaf $leaf): string {
		if ($matchType === PropertySearchProfile::MATCH_EXACT) {
			return SearchGrammar::likeEscape(value: $leaf->literal);
		}

		if ($matchType === PropertySearchProfile::MATCH_PREFIX) {
			return SearchGrammar::likeEscape(value: $leaf->literal) . '%';
		}

		return $leaf->pattern;
	}//end patternForMatchType()

	/**
	 * Build a `like` condition with bound parameters.
	 *
	 * @param BoundQueryColumn $column The column and the query builder that binds values.
	 * @param mixed            $value  The `like` operand: one term or a list of terms.
	 *
	 * @return string|null The SQL condition, or null when no term is left.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	private function likeCondition(BoundQueryColumn $column, mixed $value): ?string {
		$like = new LikeOperator(databasePlatform: $this->db->getDatabasePlatform());
		$placeholders = [];
		foreach ($like->terms(value: $value) as $term) {
			$placeholders[] = (string)$column->qb->createNamedParameter($like->pattern(term: $term));
		}

		return $like->anyCondition(column: $column->ref, patternSqls: $placeholders);
	}//end likeCondition()

	/**
	 * Build a `like` condition for the raw UNION path.
	 *
	 * That path joins conditions as SQL text and has no parameters to bind, like
	 * every other condition it builds, so the escaped pattern goes through the
	 * platform's quote().
	 *
	 * @param QuotedSqlColumn $column The column and the connection that quotes values.
	 * @param mixed           $value  The `like` operand: one term or a list of terms.
	 *
	 * @return string|null The SQL condition, or null when no term is left.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	private function likeConditionSql(QuotedSqlColumn $column, mixed $value): ?string {
		$like = new LikeOperator(databasePlatform: $this->db->getDatabasePlatform());
		$quoted = [];
		foreach ($like->terms(value: $value) as $term) {
			$quoted[] = $column->connection->quote($like->pattern(term: $term));
		}

		return $like->anyCondition(column: $column->name, patternSqls: $quoted);
	}//end likeConditionSql()

	/**
	 * Whether an `isnull` operator value asks for IS NULL rather than IS NOT NULL.
	 *
	 * The operator is documented as a QUERY-STRING filter (`?afgehandeld_op_isnull=true`,
	 * openspec/specs/zoeken-filteren), and a query string can only ever deliver
	 * strings — so a strict `=== true` comparison against a PHP boolean can never
	 * match, and reading the value as raw truthiness would make the string "false"
	 * mean true. `FILTER_VALIDATE_BOOLEAN` accepts true/"true"/"1"/1/"on"/"yes" and
	 * reads everything else, "false"/"0"/"" included, as false.
	 *
	 * @param mixed $value The raw operator value as the request layer delivered it.
	 *
	 * @return bool True when the caller asked for IS NULL.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) NullFilter::isNullOperatorAsksForNull() is a pure function shared with the aggregation path.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function isNullOperatorAsksForNull(mixed $value): bool {
		return NullFilter::isNullOperatorAsksForNull(value: $value);
	}//end isNullOperatorAsksForNull()

	/**
	 * Build object field filter SQL conditions for non-reserved query parameters
	 *
	 * Column identifiers are quoted via quoteIdentifier() so that schema properties
	 * named with SQL reserved words (e.g. 'status', 'case', 'order', 'group', 'key')
	 * do not break the generated query. Mirrors the same defence the search path
	 * already applies in buildSearchConditionSql().
	 *
	 * @param ConditionSqlRun $run The condition pass: query, table, connection and platform.
	 *
	 * @return string[] Array of SQL WHERE conditions
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 * @SuppressWarnings(PHPMD.StaticAccess) NullFilter::conditionsFor() is the one reader of `orNull`, shared by every filter path.
	 */
	private function buildObjectFilterConditionsSql(ConditionSqlRun $run): array {
		$query      = $run->query;
		$schema     = $run->schema();
		$connection = $run->connection;
		$isPostgres = $run->isPostgres;
		$conditions = [];
		$reservedParams = $this->getReservedParams();
		$properties = $schema->getProperties() ?? [];

		[$query, $nestedFilters] = $this->nestedPaths->split(filters: $query, properties: $properties);
		foreach ($nestedFilters as $filter) {
			// A filter on a path inside an object property is an oracle on that
			// property: one the caller may not read matches nothing.
			if ($this->relatedRows->mayReadProperty(schema: $schema, property: $filter['root']) === false) {
				$conditions[] = '1=0';
				continue;
			}

			$quote  = static fn (string $value): string => (string)$connection->quote($value);
			$column = new NestedPathTarget(
				expression: $this->quoteIdentifier(name: $this->sanitizeColumnName(name: $filter['root']), isPostgres: $isPostgres),
				bind: $quote,
				isPostgres: $isPostgres
			);
			$expression = $this->nestedPaths->expression(column: $column, path: $filter['path']);
			if ($expression === null) {
				$conditions[] = '1=0';
				continue;
			}

			$target = new NestedPathTarget(expression: $expression, bind: $quote, isPostgres: $isPostgres);
			array_push(
				$conditions,
				...NullFilter::conditionsFor(
					criterion: $filter['value'],
					column: $expression,
					build: fn (mixed $criterion): array => $this->nestedPaths->conditions(target: $target, value: $criterion)
				)
			);
		}

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

			$columnName = $this->sanitizeColumnName(name: $key);
			$quotedCol = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);
			$propertyType = $properties[$key]['type'] ?? 'string';

			array_push(
				$conditions,
				...NullFilter::conditionsFor(
					criterion: $value,
					column: $quotedCol,
					build: fn (mixed $criterion): array => $this->objectFieldConditionsSql(
						value: $criterion,
						field: new RawObjectFieldFilter(quotedCol: $quotedCol, propertyType: $propertyType, connection: $connection, isPostgres: $isPostgres)
					)
				)
			);
		}//end foreach

		return $conditions;
	}//end buildObjectFilterConditionsSql()

	/**
	 * The SQL conditions one object-field filter contributes on the raw UNION path
	 *
	 * @param mixed                $value The filter value, without its `orNull` modifier
	 * @param RawObjectFieldFilter $field The column, its declared type, the quoting connection and the platform
	 *
	 * @return string[] The field's conditions, to be ANDed
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function objectFieldConditionsSql(mixed $value, RawObjectFieldFilter $field): array {
		$fieldConditions = [];

		// `like` first, for every column type; see applyObjectFilters().
		if (is_array($value) === true && array_key_exists(LikeOperator::KEY, $value) === true) {
			$quoted        = new QuotedSqlColumn(name: $field->quotedCol, connection: $field->connection);
			$likeCondition = $this->likeConditionSql(column: $quoted, value: $value[LikeOperator::KEY]);
			if ($likeCondition !== null) {
				$fieldConditions[] = $likeCondition;
			}

			unset($value[LikeOperator::KEY]);
			if ($value === []) {
				return $fieldConditions;
			}
		}

		// Handle array-type properties (JSONB columns) with JSON containment operator.
		if ($field->propertyType === 'array') {
			$fieldConditions[] = $this->buildArrayPropertyConditionSql(
				column: new QuotedSqlColumn(name: $field->quotedCol, connection: $field->connection),
				value: $value
			);
			return $fieldConditions;
		}

		// A PHP boolean compares in its string form (boolean-filter-values).
		$value = FilterParams::comparableValue(value: $value, propertyType: $field->propertyType);

		// Handle array filter values: comparison operators
		// (gte/lte/gt/lt/in/notIn/ne) or a bare IN clause.
		if (is_array($value) === true) {
			$comparisonOperators = self::COMPARISON_OPERATORS;
			if (empty(array_intersect(array_keys($value), $comparisonOperators)) === false) {
				return array_merge(
					$fieldConditions,
					$this->objectOperatorConditionsSql(value: $value, field: $field)
				);
			}

			if (empty($value) === false) {
				$colRef = $this->rawColumnRef(field: $field, value: $value);
				$fieldConditions[] = "{$colRef} IN (" . $field->quoteList(values: $value) . ')';
			}

			return $fieldConditions;
		}//end if

		// Simple equality filter. Cast numeric columns to text when filtered
		// by a non-numeric (e.g. UUID) value so PostgreSQL does not abort the
		// whole UNION query with SQLSTATE[22P02].
		$colRef = $this->rawColumnRef(field: $field, value: $value);
		$fieldConditions[] = "{$colRef} = " . $field->quote(value: $value);
		return $fieldConditions;
	}//end objectFieldConditionsSql()

	/**
	 * The conditions of one object field's operator bag on the raw UNION path
	 *
	 * @param array                $value The operator bag, in its comparable form
	 * @param RawObjectFieldFilter $field The column, its declared type, the quoting connection and the platform
	 *
	 * @return string[] The conditions, to be ANDed
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) A flat sequence of independent, ANDed operator checks
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function objectOperatorConditionsSql(array $value, RawObjectFieldFilter $field): array {
		$fieldConditions = [];
		foreach (['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<'] as $operator => $sqlOperator) {
			if (isset($value[$operator]) === true) {
				$colRef = $this->rawColumnRef(field: $field, value: $value[$operator]);
				$fieldConditions[] = "{$colRef} {$sqlOperator} " . $field->quote(value: $value[$operator]);
			}
		}

		if (isset($value['in']) === true) {
			$inValues = [$value['in']];
			if (is_array($value['in']) === true) {
				$inValues = $value['in'];
			}

			$colRef = $this->rawColumnRef(field: $field, value: $inValues);
			$fieldConditions[] = "{$colRef} IN (" . $field->quoteList(values: $inValues) . ')';
		}

		if (isset($value['notIn']) === true) {
			$notInValues = [$value['notIn']];
			if (is_array($value['notIn']) === true) {
				$notInValues = $value['notIn'];
			}

			// An empty exclusion list excludes nothing — skip the
			// clause rather than emit `NOT IN ()`.
			if (count($notInValues) > 0) {
				$colRef = $this->rawColumnRef(field: $field, value: $notInValues);
				$fieldConditions[] = "{$colRef} NOT IN (" . $field->quoteList(values: $notInValues) . ')';
			}
		}

		if (isset($value['ne']) === true) {
			$colRef = $this->rawColumnRef(field: $field, value: $value['ne']);
			$fieldConditions[] = "{$colRef} <> " . $field->quote(value: $value['ne']);
		}

		if (isset($value['isnull']) === true) {
			// No column-ref cast: a null check is about the column's
			// presence, not its type, and casting a NULL would only
			// obscure it.
			$predicate = 'IS NOT NULL';
			if ($this->isNullOperatorAsksForNull(value: $value['isnull']) === true) {
				$predicate = 'IS NULL';
			}

			$fieldConditions[] = "{$field->quotedCol} {$predicate}";
		}

		return $fieldConditions;
	}//end objectOperatorConditionsSql()

	/**
	 * The column reference of a raw-path field, text-cast when a value is not numeric.
	 *
	 * @param RawObjectFieldFilter $field The field being filtered.
	 * @param mixed                $value The value (or values) it is compared with.
	 *
	 * @return string The (optionally text-cast) column reference.
	 */
	private function rawColumnRef(RawObjectFieldFilter $field, mixed $value): string {
		return $this->buildFilterColumnRef(
			column: new TypedColumnRef(ref: $field->quotedCol, propertyType: $field->propertyType, isPostgres: $field->isPostgres),
			value: $value
		);
	}//end rawColumnRef()

	/**
	 * Build SQL condition for array-type (JSONB) property filtering
	 *
	 * Uses PostgreSQL JSONB containment operator (@>) to check if a JSON array
	 * column contains the specified value(s).
	 *
	 * @param QuotedSqlColumn $column The JSONB column and the connection that quotes values.
	 * @param mixed           $value  The filter value (single value or array of values).
	 *
	 * @return string SQL condition for the array property filter
	 */
	private function buildArrayPropertyConditionSql(QuotedSqlColumn $column, mixed $value): string {
		$columnName = $column->name;
		$connection = $column->connection;
		// Normalize value to array.
		$values = [$value];
		if (is_array($value) === true) {
			$values = $value;
		}

		if (empty($values) === true || count($values) === 1) {
			// Single value (or empty): check if JSON array contains this value.
			$singleValue = $values[0] ?? '';
			$jsonValue = $connection->quote(json_encode([$singleValue]));
			return "COALESCE({$columnName}, '[]')::jsonb @> {$jsonValue}::jsonb";
		}

		// Multiple values: check if JSON array contains ANY of the values (OR logic).
		$orParts = [];
		foreach ($values as $v) {
			$jsonValue = $connection->quote(json_encode([$v]));
			$orParts[] = "COALESCE({$columnName}, '[]')::jsonb @> {$jsonValue}::jsonb";
		}

		return '(' . implode(' OR ', $orParts) . ')';
	}//end buildArrayPropertyConditionSql()

	/**
	 * Build SQL conditions for `@self` metadata filters on the UNION path
	 *
	 * The raw-SQL multi-table path had no metadata step at all, so a `@self`
	 * filter was silently discarded and the query returned rows the caller had
	 * explicitly excluded. This mirrors applyMetadataFilters() one-for-one —
	 * same operator vocabulary, same null sentinels, same unknown-field
	 * rejection — so the two paths cannot answer the same question differently.
	 *
	 * Columns are unqualified here (each UNION arm selects from one table) and
	 * quoted through quoteIdentifier(), matching the object-filter builder.
	 *
	 * @param ConditionSqlRun $run The condition pass: query, table, connection and platform.
	 *
	 * @return string[] Array of SQL conditions
	 *
	 * @throws UnknownMetadataFieldException When a `@self` key names no metadata column.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
	 */
	private function buildMetadataFilterConditionsSql(ConditionSqlRun $run): array {
		$connection      = $run->connection;
		$isPostgres      = $run->isPostgres;
		$metadataFilters = ($run->query['@self'] ?? []);
		if (is_array($metadataFilters) === false || empty($metadataFilters) === true) {
			return [];
		}

		$conditions = [];

		foreach ($metadataFilters as $field => $value) {
			$column = $this->quoteIdentifier(
				name: $this->resolveMetadataColumn(field: (string)$field),
				isPostgres: $isPostgres
			);

			if ($value === 'IS NOT NULL') {
				$conditions[] = "{$column} IS NOT NULL";
				continue;
			}

			if ($value === 'IS NULL') {
				$conditions[] = "{$column} IS NULL";
				continue;
			}

			if (is_array($value) === true) {
				$conditions = array_merge(
					$conditions,
					$this->buildMetadataOperatorConditionsSql(
						column: new QuotedSqlColumn(name: $column, connection: $connection),
						value: $value
					)
				);
				continue;
			}

			$conditions[] = "{$column} = " . $connection->quote((string)$value);
		}//end foreach

		return $conditions;
	}//end buildMetadataFilterConditionsSql()

	/**
	 * Build the SQL for one metadata column's operator bag (or bare IN list)
	 *
	 * @param QuotedSqlColumn     $column The column and the connection that quotes values.
	 * @param array<string, mixed> $value  The operator bag.
	 *
	 * @return string[] Array of SQL conditions
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) NullFilter::conditionsFor() is the one reader of `orNull`, shared by every filter path.
	 */
	private function buildMetadataOperatorConditionsSql(QuotedSqlColumn $column, array $value): array {
		return NullFilter::conditionsFor(
			criterion: $value,
			column: $column->name,
			build: fn (array $bag): array => $this->metadataBagConditionsSql(column: $column, value: $bag)
		);
	}//end buildMetadataOperatorConditionsSql()

	/**
	 * The SQL conditions of one metadata operator bag (or bare IN list), to be ANDed
	 *
	 * @param QuotedSqlColumn     $column The column and the connection that quotes values.
	 * @param array<string, mixed> $value  The operator bag.
	 *
	 * @return string[] Array of SQL conditions
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	private function metadataBagConditionsSql(QuotedSqlColumn $column, array $value): array {
		$likeConditions = [];
		if (array_key_exists(LikeOperator::KEY, $value) === true) {
			$likeCondition = $this->likeConditionSql(column: $column, value: $value[LikeOperator::KEY]);
			if ($likeCondition !== null) {
				$likeConditions[] = $likeCondition;
			}

			unset($value[LikeOperator::KEY]);
			if ($value === []) {
				return $likeConditions;
			}
		}

		// No operator key at all: a bare list keeps its historical IN(...) meaning.
		if (empty(array_intersect(array_keys($value), self::COMPARISON_OPERATORS)) === true) {
			return [$this->metadataInConditionSql(column: $column, values: $value)];
		}

		// Three families, in the order they have always been emitted. They were
		// one method until phpmd counted 486 paths through it; each family is
		// independent of the others, so splitting them changes nothing but the
		// number of ways to read the code.
		return array_merge(
			$likeConditions,
			$this->metadataComparisonConditionsSql(column: $column, value: $value),
			$this->metadataListConditionsSql(column: $column, value: $value),
			$this->metadataNullConditionsSql(column: $column->name, value: $value)
		);
	}//end metadataBagConditionsSql()

	/**
	 * Quote every value in a bag, accepting a bare scalar as a one-item list
	 *
	 * @param mixed $values A list of values, or a single value
	 * @param object $connection Database connection for value quoting
	 *
	 * @return string[] The quoted values
	 */
	private function quoteMetadataValues(mixed $values, object $connection): array {
		if (is_array($values) === false) {
			$values = [$values];
		}

		return array_map(
			static fn (mixed $item): string => $connection->quote((string)$item),
			$values
		);
	}//end quoteMetadataValues()

	/**
	 * `IN (...)` for one bag, or the always-false condition an empty bag means
	 *
	 * @param QuotedSqlColumn $column The column and the connection that quotes values.
	 * @param mixed           $values One value or a list.
	 *
	 * @return string One SQL condition
	 */
	private function metadataInConditionSql(QuotedSqlColumn $column, mixed $values): string {
		$quoted = $this->quoteMetadataValues(values: $values, connection: $column->connection);
		if (empty($quoted) === true) {
			// An empty IN () is a syntax error and matches nothing by definition.
			return '1 = 0';
		}

		return $column->name . ' IN (' . implode(', ', $quoted) . ')';
	}//end metadataInConditionSql()

	/**
	 * The scalar comparison operators, in their emitted order
	 *
	 * @param QuotedSqlColumn     $column The column and the connection that quotes values.
	 * @param array<string, mixed> $value  The operator bag.
	 *
	 * @return string[] Array of SQL conditions
	 */
	private function metadataComparisonConditionsSql(QuotedSqlColumn $column, array $value): array {
		$simple = [
			'gte' => '>=',
			'lte' => '<=',
			'gt' => '>',
			'lt' => '<',
			'ne' => '<>',
		];

		$conditions = [];
		foreach ($simple as $operator => $sqlOperator) {
			if (isset($value[$operator]) === true) {
				$conditions[] = "{$column->name} {$sqlOperator} " . $column->connection->quote((string)$value[$operator]);
			}
		}

		return $conditions;
	}//end metadataComparisonConditionsSql()

	/**
	 * The `in` and `notIn` operators
	 *
	 * @param QuotedSqlColumn     $column The column and the connection that quotes values.
	 * @param array<string, mixed> $value  The operator bag.
	 *
	 * @return string[] Array of SQL conditions
	 */
	private function metadataListConditionsSql(QuotedSqlColumn $column, array $value): array {
		$conditions = [];
		if (isset($value['in']) === true) {
			$conditions[] = $this->metadataInConditionSql(column: $column, values: $value['in']);
		}

		if (isset($value['notIn']) === true) {
			$quoted = $this->quoteMetadataValues(values: $value['notIn'], connection: $column->connection);
			// An empty NOT IN () is a syntax error and means "exclude nothing".
			if (empty($quoted) === false) {
				$conditions[] = $column->name . ' NOT IN (' . implode(', ', $quoted) . ')';
			}
		}

		return $conditions;
	}//end metadataListConditionsSql()

	/**
	 * The `isnull` operator
	 *
	 * @param string $column Quoted column identifier
	 * @param array $value Operator bag
	 *
	 * @return string[] Array of SQL conditions
	 */
	private function metadataNullConditionsSql(string $column, array $value): array {
		if (isset($value['isnull']) === false) {
			return [];
		}

		$predicate = 'IS NOT NULL';
		if ($this->isNullOperatorAsksForNull(value: $value['isnull']) === true) {
			$predicate = 'IS NULL';
		}

		return [$column . ' ' . $predicate];
	}//end metadataNullConditionsSql()

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
	 * @param array $query The full query array
	 * @param object $connection Database connection for value quoting
	 *
	 * @return string[] Array of SQL conditions
	 */
	private function buildTmloFilterConditionsSql(array $query, object $connection): array {
		$conditions = [];
		$archiefactieFrom = null;
		$archiefactieTo = null;

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
			$quotedValue = $connection->quote((string)$value);
			$conditions[] = '_tmlo::jsonb ->> ' . $connection->quote($subField) . " = {$quotedValue}";
		}//end foreach

		// Build archiefactiedatum range condition.
		if ($archiefactieFrom !== null) {
			$conditions[] = "_tmlo::jsonb ->> 'archiefactiedatum' >= " . $connection->quote($archiefactieFrom);
		}

		if ($archiefactieTo !== null) {
			$conditions[] = "_tmlo::jsonb ->> 'archiefactiedatum' <= " . $connection->quote($archiefactieTo);
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
	private function getReservedParams(): array {
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
			'_unread',
			'_unreadFor',
			'_favourite',
			'_watching',
			'_watchingFor',
			'_recent',
			'_recentFor',
			'_recentViews',
			'_recentLens',
			'_count',
			'_includeDeleted',
			'_deleted',
			'_archived',
			DraftListLens::PARAMETER,
			'_relations_contains',
			'_multitenancy_explicit',
			'_fuzzy',
			'_empty',
			// The non-underscore tail lives in FilterParams, because the
			// filter-spelling normalisation has to exclude exactly the same
			// names (openregister#3611). Two hand-kept copies of this list
			// would drift, and the way they drift is a context parameter read
			// as a property filter, which answers `1 = 0` and says nothing.
			...FilterParams::OBJECT_CONTEXT_PARAMS,
		];
	}//end getReservedParams()

	/**
	 * Apply basic filters like deleted status
	 *
	 * @param TableFilterRun $run The filter pass: query builder, request query and table.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 */
	private function applyBasicFilters(TableFilterRun $run): void {
		$qb             = $run->qb;
		$query          = $run->query;
		$schema         = $run->schema();
		$missingColumns = $this->missingMetadataColumns(tableName: $run->table->tableName);
		$lens = new DeletedLens();
		$deleted = $lens->condition(mode: $lens->mode(query: $query), columnPrefix: 't.');
		if ($deleted !== null) {
			$qb->andWhere($qb->createFunction($deleted));
		}

		// Handle the archive filter. Exclusion is the DEFAULT rather than a
		// filter the caller has to remember, so a caller who forgets the
		// parameter gets the working set — the safe answer. Asking for both is
		// the only way to see an archived row beside an open one.
		$archivedMode = $this->resolveArchivedMode(query: $query);
		$columns = new LifecycleColumns(prefix: 't.', missing: $missingColumns);
		$archivedMissing = $columns->lacks(column: '_archived');
		if ($archivedMode === self::ARCHIVED_EXCLUDE && $archivedMissing === false) {
			$qb->andWhere($qb->expr()->isNull('t._archived'));
		}

		if ($archivedMode === self::ARCHIVED_ONLY) {
			if ($archivedMissing === true) {
				// No column, so no archived row: the archived lens is empty.
				$qb->andWhere($qb->createFunction('1=0'));
			} else {
				$qb->andWhere($qb->expr()->isNotNull('t._archived'));
			}
		}

		// Decision 201: drafts stay out of a list unless asked for, whoever
		// asks. The read RBAC keeps a draft to its owner, but an administrator
		// and an app's service listing with RBAC off pass that by, and their
		// work lists are the ones the decision is about. The caller's own
		// drafts stay in view. Raw SQL through createFunction(), spelled once
		// in DraftListLens for this path and lifecycleConditionsSql().
		$userId = $this->rbacHandler->getCurrentUserId();
		$owner = null;
		if ($userId !== null && $userId !== '') {
			$owner = (string)$qb->createNamedParameter($userId);
		}

		$drafts = $this->draftConditionSql(columns: $columns, request: new DraftListRequest(query: $query, schema: $schema, owner: $owner));
		if ($drafts !== null) {
			$qb->andWhere($qb->createFunction($drafts));
		}

	}//end applyBasicFilters()

	/**
	 * Resolve the archive lens a query asks for.
	 *
	 * `_archived` absent or false is the working set, `_archived=true` is the
	 * archived lens alone and `_archived=any` is both. Anything else reads as
	 * the working set, because an unrecognised lens must never silently widen
	 * what a list shows.
	 *
	 * ⚠️ `filter_var(..., FILTER_VALIDATE_BOOLEAN)` alone cannot do this. It
	 * maps the string `"any"` to false, which is indistinguishable from
	 * `_archived=false` — so the one parameter value that means "show me
	 * everything" would have quietly meant "hide the archive".
	 *
	 * @param array $query The search query parameters.
	 *
	 * @return string One of the ARCHIVED_* constants.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function resolveArchivedMode(array $query): string {
		if (array_key_exists('_archived', $query) === false) {
			return self::ARCHIVED_EXCLUDE;
		}

		$raw = $query['_archived'];

		if (is_string($raw) === true && strtolower(trim($raw)) === self::ARCHIVED_ANY) {
			return self::ARCHIVED_ANY;
		}

		if (filter_var($raw, FILTER_VALIDATE_BOOLEAN) === true) {
			return self::ARCHIVED_ONLY;
		}

		return self::ARCHIVED_EXCLUDE;
	}//end resolveArchivedMode()

	/**
	 * Check if a mixed value represents an explicit boolean true
	 *
	 * Handles string, integer, and boolean representations of true.
	 *
	 * @param mixed $value The value to check
	 *
	 * @return bool True if the value is explicitly true
	 */
	private function isExplicitlyTrue(mixed $value): bool {
		return $value === true
			|| $value === 'true'
			|| $value === '1'
			|| $value === 1;
	}//end isExplicitlyTrue()

	/**
	 * Resolve the multitenancy flag based on public schema access and explicit request
	 *
	 * A schema with a `public` read rule bypasses multitenancy for an anonymous
	 * caller and an admin, UNLESS the caller explicitly requests multitenancy with
	 * _multi=true. A signed-in caller keeps it: they read other organisations'
	 * rows only through rules marked `crossOrganisation: true`.
	 *
	 * @param bool $_multitenancy Current multitenancy flag
	 * @param bool $multitenancyExplicit Whether multitenancy was explicitly requested
	 * @param Schema $schema Schema to check for public access
	 *
	 * @return bool Resolved multitenancy flag
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-when-inheritfrompublic-is-false-authenticated-users-must-not-qualify-for-public-rules
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	private function resolveMultitenancyFlag(
		bool $_multitenancy,
		bool $multitenancyExplicit,
		Schema $schema,
	): bool {
		if (OrganisationContext::isActive() === true) {
			return true;
		}

		// Public schemas bypass multitenancy for anonymous callers and admins
		// UNLESS the caller explicitly set _multi=true.
		if ($_multitenancy === true && $multitenancyExplicit === false && $this->publicReadLiftsBoundary(schema: $schema) === true) {
			return false;
		}

		return $_multitenancy;
	}//end resolveMultitenancyFlag()

	/**
	 * Whether a schema's `public` read rule lifts the organisation boundary for this caller.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the schema has a public read rule and the caller is one it lifts the boundary for.
	 */
	private function publicReadLiftsBoundary(Schema $schema): bool {
		$readGroups = ($schema->getAuthorization()['read'] ?? []);

		return $this->hasPublicReadAccess(readRules: $readGroups) === true
			&& $this->rbacHandler->publicReadLiftsOrganisationFilter(schema: $schema) === true;
	}//end publicReadLiftsBoundary()

	/**
	 * Read the register id out of a search query's reserved `register` key.
	 *
	 * The facet paths build their query from a base query rather than from a
	 * Register entity, and that base query carries `register` as a reserved
	 * parameter. Reading it here means a register-level shared master data
	 * declaration is honoured by the facets as well as by the list, instead of
	 * the two disagreeing about which rows exist.
	 *
	 * @param array $query The search query.
	 *
	 * @return int|null The register id, or null when the query does not name one.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	private function registerIdFromQuery(array $query): ?int {
		$register = ($query['register'] ?? null);

		if (is_int($register) === true) {
			return $register;
		}

		if (is_string($register) === true && ctype_digit($register) === true) {
			return (int)$register;
		}

		return null;
	}//end registerIdFromQuery()

	/**
	 * Add the organisation boundary, widened by a cross-organisation branch when there is one.
	 *
	 * @param IQueryBuilder        $qb       The query being built.
	 * @param OrganisationBoundary $boundary The scope table, the org-less admission and the cross-organisation branch.
	 *
	 * @return void
	 */
	private function applyOrganisationBoundary(IQueryBuilder $qb, OrganisationBoundary $boundary): void {
		if ($boundary->crossBranch === null) {
			$this->organizationHandler->applyOrganizationFilter(
				qb: $qb,
				table: $boundary->scopeTable(),
				adminBypassEnabled: $this->organizationHandler->isAdminOverrideEnabled(),
				withOrgless: $boundary->withOrgless
			);
			return;
		}

		(new OrganisationScopeCondition())->apply(
			qb: $qb,
			scope: $this->organisationScopeFor(boundary: $boundary),
			orAlso: $boundary->crossBranch
		);
	}//end applyOrganisationBoundary()

	/**
	 * The organisation scope of one table, widened by the org-less rows when asked.
	 *
	 * @param OrganisationBoundary $boundary The register, schema and org-less admission of the table.
	 *
	 * @return array{mode: string, uuids: array<int, string>} The scope.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) admitOrganisationless() is a pure transform of the decided scope.
	 */
	private function organisationScopeFor(OrganisationBoundary $boundary): array {
		$scope = $this->organizationHandler->resolveOrganizationScope(
			adminBypassEnabled: $this->organizationHandler->isAdminOverrideEnabled(),
			registerId: $boundary->registerId,
			schemaId: $boundary->schemaId
		);
		if ($boundary->withOrgless === true) {
			$scope = MagicOrganizationHandler::admitOrganisationless(scope: $scope);
		}

		return $scope;
	}//end organisationScopeFor()

	/**
	 * The rows the caller's conditional `crossOrganisation` rules admit, to OR
	 * into the organisation filter, or null when the filter takes no such branch.
	 *
	 * @param TableFilterRun $run     The filter pass: query builder and the table read.
	 * @param ReadPosture    $posture The posture; an explicit multitenancy request keeps the branch out.
	 *
	 * @return mixed The branch, or null.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the request posture, mirrored.
	 */
	private function crossOrganisationBranch(TableFilterRun $run, ReadPosture $posture): mixed {
		$schema = $run->schema();
		if ($posture->multitenancyExplicit === true || $this->rbacHandler->hasCrossOrganisationBranch(schema: $schema) === false) {
			return null;
		}

		return $this->rbacHandler->getCrossOrganisationBranch(qb: $run->qb, schema: $schema);
	}//end crossOrganisationBranch()

	/**
	 * Apply access control filters (multitenancy and RBAC) to the query
	 *
	 * Handles the interaction between RBAC and _multitenancy:
	 * - When user has NO RBAC access: Apply multitenancy as normal (AND restriction)
	 * - When user HAS RBAC access AND _multi=true: Apply multitenancy AFTER RBAC
	 * - When user HAS RBAC access AND _multi=false: Skip multitenancy (RBAC handles access)
	 *
	 * @param TableFilterRun $run     The filter pass: query builder and the table read.
	 * @param ReadPosture    $posture The resolved RBAC and multitenancy posture.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	private function applyAccessControlFilters(TableFilterRun $run, ReadPosture $posture): void {
		$qb     = $run->qb;
		$table  = $run->table;
		$schema = $table->schema;

		// Whether the organisation boundary applies is decided in ONE place
		// (multitenancyApplies), because the string-SQL path renders the same
		// decision and the two silently disagreeing is what let the UNION path
		// return another organisation's rows. Forcing the existing filter on for
		// a grant holder is deliberate: an `_organisation` term inside the grant
		// branch would be a second definition of the tenant edge, and this
		// change exists because second definitions of a rule drift apart.
		// Cross-organisation sharing is group 7's decision to take, not a side
		// effect to inherit here.
		$applyMultitenancy = $this->multitenancyApplies(
			schema: $schema,
			_rbac: $posture->rbac,
			_multitenancy: $posture->multitenancy,
			multitenancyExplicit: $posture->multitenancyExplicit
		);

		if ($applyMultitenancy === true) {
			// The register+schema pair is handed down so the organisation
			// handler can widen by a DECLARED shared master data holder
			// (REQ-SLE-001). Each magic table is exactly one such pair, so
			// the widening reaches this table and nothing else the holder
			// owns. A pair that cannot be resolved widens by nothing.
			$this->applyOrganisationBoundary(
				qb: $qb,
				boundary: new OrganisationBoundary(
					registerId: $table->registerId,
					schemaId: $schema->getId(),
					withOrgless: $this->organisationlessRowsAdmitted(schema: $schema, posture: $posture),
					crossBranch: $this->crossOrganisationBranch(run: $run, posture: $posture)
				)
			);
		}

		// Apply RBAC filtering if enabled.
		if ($posture->rbac === true) {
			$this->rbacHandler->applyRbacFilters(
				qb: $qb,
				schema: $schema,
				action: 'read',
				tableName: $table->tableName
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
	private function isFuzzySearchEnabled(mixed $fuzzyParam): bool {
		if ($this->isExplicitlyTrue(value: $fuzzyParam) === true) {
			return $this->hasPgTrgmExtension();
		}

		return false;
	}//end isFuzzySearchEnabled()

	/**
	 * Apply metadata filters to the query
	 *
	 * Metadata lives in the magic table's underscore-prefixed columns (`_created`,
	 * `_uuid`, …), so a `@self` filter is resolved against a column rather than
	 * against the JSON document.
	 *
	 * The comparison vocabulary is deliberately IDENTICAL to the one
	 * applyObjectFilters() accepts — bare `gte|lte|gt|lt|in|notIn|ne` keys, never
	 * `$`-prefixed. `@self.created` and a schema property must not need different
	 * syntax to express the same comparison; that asymmetry is what made
	 * "created before X" inexpressible.
	 *
	 * A bare list with no operator key keeps its historical meaning (`IN (…)`),
	 * so `{"@self":{"register":[1,2]}}` is unaffected.
	 *
	 * @param IQueryBuilder $qb Query builder to modify
	 * @param array $filters Metadata filters to apply
	 *
	 * @return void
	 *
	 * @throws UnknownMetadataFieldException When a `@self` key names no metadata column.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
	 */
	private function applyMetadataFilters(IQueryBuilder $qb, array $filters): void {
		foreach ($filters as $field => $value) {
			$columnName = $this->resolveMetadataColumn(field: (string)$field);
			$columnRef = "t.{$columnName}";

			// A null check is terminal: without the `continue` the sentinel string
			// ALSO reached the equality below, so `IS NOT NULL` emitted
			// `_owner IS NOT NULL AND _owner = 'IS NOT NULL'` — zero rows on a text
			// column and a cast error on a timestamp one.
			if ($value === 'IS NOT NULL') {
				$qb->andWhere($qb->expr()->isNotNull($columnRef));
				continue;
			}

			if ($value === 'IS NULL') {
				$qb->andWhere($qb->expr()->isNull($columnRef));
				continue;
			}

			if (is_array($value) === true) {
				$this->applyMetadataOperators(column: new BoundQueryColumn(qb: $qb, ref: $columnRef), value: $value);
				continue;
			}

			$qb->andWhere($qb->expr()->eq($columnRef, $qb->createNamedParameter($value)));
		}//end foreach
	}//end applyMetadataFilters()

	/**
	 * Apply an operator bag (or a bare IN list) to one metadata column
	 *
	 * @param BoundQueryColumn     $column The column and the query builder that binds values.
	 * @param array<string, mixed> $value  The operator criterion.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) NullFilter::conditionsFor() is the one reader of `orNull`, shared by every filter path.
	 */
	private function applyMetadataOperators(BoundQueryColumn $column, array $value): void {
		$conditions = NullFilter::conditionsFor(
			criterion: $value,
			column: $column->ref,
			build: fn (array $bag): array => $this->metadataOperatorPredicates(column: $column, value: $bag)
		);
		foreach ($conditions as $condition) {
			$column->qb->andWhere($condition);
		}
	}//end applyMetadataOperators()

	/**
	 * The predicates of one metadata operator bag (or bare IN list), to be ANDed
	 *
	 * @param BoundQueryColumn     $column The column and the query builder that binds values.
	 * @param array<string, mixed> $value  The operator bag.
	 *
	 * @return list<string|\Stringable> The predicates
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) A flat sequence of independent, ANDed operator checks
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	private function metadataOperatorPredicates(BoundQueryColumn $column, array $value): array {
		$qb        = $column->qb;
		$columnRef = $column->ref;
		$predicates = [];
		if (array_key_exists(LikeOperator::KEY, $value) === true) {
			$likeCondition = $this->likeCondition(column: $column, value: $value[LikeOperator::KEY]);
			if ($likeCondition !== null) {
				$predicates[] = $likeCondition;
			}

			unset($value[LikeOperator::KEY]);
			if ($value === []) {
				return $predicates;
			}
		}

		// No operator key at all: a bare list keeps its historical IN(...) meaning.
		if (empty(array_intersect(array_keys($value), self::COMPARISON_OPERATORS)) === true) {
			$predicates[] = $qb->expr()->in($columnRef, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY));

			return $predicates;
		}

		// Multiple operators AND together, so {"gte":a,"lte":b} is a range.
		$comparisons = ['gte' => 'gte', 'lte' => 'lte', 'gt' => 'gt', 'lt' => 'lt', 'ne' => 'neq'];
		foreach ($comparisons as $operator => $method) {
			if (isset($value[$operator]) === true) {
				$predicates[] = $qb->expr()->$method($columnRef, $qb->createNamedParameter($value[$operator]));
			}
		}

		if (isset($value['in']) === true) {
			$inValues = $value['in'];
			if (is_array($inValues) === false) {
				$inValues = [$inValues];
			}

			$predicates[] = $qb->expr()->in($columnRef, $qb->createNamedParameter($inValues, IQueryBuilder::PARAM_STR_ARRAY));
		}

		if (isset($value['notIn']) === true) {
			$notInValues = $value['notIn'];
			if (is_array($notInValues) === false) {
				$notInValues = [$notInValues];
			}

			// An empty NOT IN () is a SQL syntax error and means "exclude nothing".
			if (empty($notInValues) === false) {
				$predicates[] = $qb->expr()->notIn(
					$columnRef,
					$qb->createNamedParameter($notInValues, IQueryBuilder::PARAM_STR_ARRAY)
				);
			}
		}

		if (isset($value['isnull']) === true) {
			$predicate = $qb->expr()->isNotNull($columnRef);
			if ($this->isNullOperatorAsksForNull(value: $value['isnull']) === true) {
				$predicate = $qb->expr()->isNull($columnRef);
			}

			$predicates[] = $predicate;
		}

		return $predicates;
	}//end metadataOperatorPredicates()

	/**
	 * Resolve a `@self` field name to its magic-table metadata column
	 *
	 * Two things went wrong without this. The name was concatenated raw, so the
	 * camelCase `schemaVersion` addressed a `_schemaVersion` column that does not
	 * exist (the real one is `_schema_version`) and the request died as an opaque
	 * HTTP 500. And any typo was interpolated straight into SQL rather than being
	 * named back to the caller.
	 *
	 * @param string $field Metadata field name as written in `@self`
	 *
	 * @return string The underscore-prefixed column name
	 *
	 * @throws UnknownMetadataFieldException When the field names no metadata column.
	 */
	private function resolveMetadataColumn(string $field): string {
		$columnName = '_' . $this->sanitizeColumnName(name: $field);

		if (in_array($columnName, self::METADATA_COLUMNS, true) === false) {
			throw new UnknownMetadataFieldException(
				field: $field,
				known: self::METADATA_COLUMNS
			);
		}

		return $columnName;
	}//end resolveMetadataColumn()

	/**
	 * Apply object field filters based on schema properties
	 *
	 * @param TableFilterRun       $run     The filter pass: query builder and the table whose schema types the columns.
	 * @param array<string, mixed> $filters The object-field filters.
	 *
	 * @return void
	 *
	 * @throws EncryptedFieldFilterException When a filter targets a property flagged
	 *                                       `x-openregister-encrypted: true`.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 * @SuppressWarnings(PHPMD.StaticAccess) NullFilter::conditionsFor() is the one reader of `orNull`, shared by every filter path.
	 */
	private function applyObjectFilters(TableFilterRun $run, array $filters): void {
		$qb     = $run->qb;
		$schema = $run->schema();
		$properties = $schema->getProperties();

		// Fail loud BEFORE any query work rather than silently returning zero rows:
		// an encrypted property's value is ciphertext (its column, see
		// MagicMapper::buildTableColumnsFromSchema(), is an unindexed TEXT column
		// holding the envelope), so a plaintext filter against it can never mean what
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

		[$filters, $nestedFilters] = $this->nestedPaths->split(filters: $filters, properties: $properties ?? []);
		$this->applyNestedPathFilters(run: $run, nestedFilters: $nestedFilters);

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

			$filter = new ObjectFieldFilter(
				qb: $qb,
				columnName: $this->sanitizeColumnName(name: $field),
				propertyType: ($properties[$field]['type'] ?? 'string'),
				isPostgres: $isPostgres
			);

			$conditions = NullFilter::conditionsFor(
				criterion: $value,
				column: $filter->columnRef(),
				build: fn (mixed $criterion): array => $this->objectFieldPredicates(field: $filter, value: $criterion)
			);
			foreach ($conditions as $condition) {
				$qb->andWhere($condition);
			}
		}//end foreach
	}//end applyObjectFilters()

	/**
	 * The predicates one object-field filter contributes on the QueryBuilder path
	 *
	 * `like` matches the column's text, whatever the column type, so it is
	 * applied ahead of the type-specific predicates and then taken out of the bag.
	 *
	 * @param ObjectFieldFilter $field The field being filtered
	 * @param mixed             $value The filter value, without its `orNull` modifier
	 *
	 * @return list<string|\Stringable> The field's predicates, to be ANDed
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function objectFieldPredicates(ObjectFieldFilter $field, mixed $value): array {
		if ($value === 'IS NOT NULL') {
			return [$field->qb->expr()->isNotNull($field->columnRef())];
		}

		if ($value === 'IS NULL') {
			return [$field->qb->expr()->isNull($field->columnRef())];
		}

		$predicates = [];
		if (is_array($value) === true && array_key_exists(LikeOperator::KEY, $value) === true) {
			$predicates = $this->likePredicates(field: $field, value: $value[LikeOperator::KEY]);
			unset($value[LikeOperator::KEY]);
			if ($value === []) {
				return $predicates;
			}
		}

		return array_merge($predicates, $this->typedFieldPredicates(field: $field, value: $value));
	}//end objectFieldPredicates()

	/**
	 * The `like` predicate of a field, when a term is left.
	 *
	 * @param ObjectFieldFilter $field The field being filtered
	 * @param mixed             $value The value under the `like` key
	 *
	 * @return list<string> The predicate, or none.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	private function likePredicates(ObjectFieldFilter $field, mixed $value): array {
		$likeCondition = $this->likeCondition(column: new BoundQueryColumn(qb: $field->qb, ref: $field->columnRef()), value: $value);
		if ($likeCondition === null) {
			return [];
		}

		return [$likeCondition];
	}//end likePredicates()

	/**
	 * The predicates of a field by its type: JSON array, JSON object, list, operators or one value.
	 *
	 * @param ObjectFieldFilter $field The field being filtered
	 * @param mixed             $value The filter value, without `like`
	 *
	 * @return list<string|\Stringable> The field's predicates.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FilterParams is the configured pure-reader exception.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function typedFieldPredicates(ObjectFieldFilter $field, mixed $value): array {
		if ($field->propertyType === 'array') {
			return [$this->jsonArrayCondition(field: $field, value: $value)];
		}

		if ($field->propertyType === 'object') {
			return [$this->jsonObjectCondition(field: $field, value: $value)];
		}

		// A PHP boolean compares in its string form (boolean-filter-values).
		$value = FilterParams::comparableValue(value: $value, propertyType: $field->propertyType);
		if (is_array($value) === false) {
			return [$this->comparisonPredicate(field: $field, comparison: new FieldComparison(operator: 'eq', operand: $value))];
		}

		if (empty(array_intersect(array_keys($value), self::COMPARISON_OPERATORS)) === true) {
			return [$this->listPredicate(field: $field, comparison: new FieldComparison(operator: 'in', operand: $value))];
		}

		return $this->objectOperatorPredicates(field: $field, value: $value);
	}//end typedFieldPredicates()

	/**
	 * One comparison of a field against a bound value.
	 *
	 * Numeric columns are cast to text when compared with a non-numeric (e.g.
	 * UUID) value, so PostgreSQL does not abort the query with SQLSTATE[22P02];
	 * numeric values keep the indexed numeric path.
	 *
	 * @param ObjectFieldFilter $field The field: query builder, column, type and platform.
	 * @param FieldComparison   $comparison The operator and the value compared with.
	 *
	 * @return string The predicate.
	 */
	private function comparisonPredicate(ObjectFieldFilter $field, FieldComparison $comparison): string {
		$operator = $comparison->operator;
		$value    = $comparison->operand;
		return $field->qb->expr()->$operator($this->filterColumnRef(field: $field, value: $value), $field->qb->createNamedParameter($value));
	}//end comparisonPredicate()

	/**
	 * One `in` or `notIn` of a field against a bound list.
	 *
	 * @param ObjectFieldFilter $field The field: query builder, column, type and platform.
	 * @param FieldComparison   $comparison The operator (`in` or `notIn`) and the list compared with.
	 *
	 * @return string The predicate.
	 */
	private function listPredicate(ObjectFieldFilter $field, FieldComparison $comparison): string {
		$operator = $comparison->operator;
		$values   = $comparison->operand;
		return $field->qb->expr()->$operator(
			$this->filterColumnRef(field: $field, value: $values),
			$field->qb->createNamedParameter($values, IQueryBuilder::PARAM_STR_ARRAY)
		);
	}//end listPredicate()

	/**
	 * The column reference a field is compared through, cast where the value needs it.
	 *
	 * @param ObjectFieldFilter $field The field being filtered
	 * @param mixed             $value The value it is compared with
	 *
	 * @return string The column reference.
	 */
	private function filterColumnRef(ObjectFieldFilter $field, mixed $value): string {
		return $this->buildFilterColumnRef(
			column: new TypedColumnRef(ref: $field->columnRef(), propertyType: $field->propertyType, isPostgres: $field->isPostgres),
			value: $value
		);
	}//end filterColumnRef()

	/**
	 * The predicates of an operator bag; multiple operators AND together, so {"gte":a,"lte":b} is a range.
	 *
	 * @param ObjectFieldFilter $field The field being filtered
	 * @param array             $value The operator bag
	 *
	 * @return list<string> The predicates.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function objectOperatorPredicates(ObjectFieldFilter $field, array $value): array {
		$predicates = [];
		foreach (['gte', 'lte', 'gt', 'lt'] as $operator) {
			if (isset($value[$operator]) === true) {
				$predicates[] = $this->comparisonPredicate(field: $field, comparison: new FieldComparison(operator: $operator, operand: $value[$operator]));
			}
		}

		if (isset($value['in']) === true) {
			$predicates[] = $this->listPredicate(field: $field, comparison: new FieldComparison(operator: 'in', operand: $this->asList(value: $value['in'])));
		}

		return array_merge($predicates, $this->exclusionPredicates(field: $field, value: $value));
	}//end objectOperatorPredicates()

	/**
	 * The `notIn`, `ne` and `isnull` predicates of an operator bag.
	 *
	 * An empty exclusion list excludes nothing, so it adds no clause: `NOT IN ()`
	 * is a SQL error on some engines. The null check reads the bare column,
	 * because a cast would only obscure whether the column has a value at all.
	 *
	 * @param ObjectFieldFilter $field The field being filtered
	 * @param array             $value The operator bag
	 *
	 * @return list<string> The predicates.
	 */
	private function exclusionPredicates(ObjectFieldFilter $field, array $value): array {
		$predicates = [];
		$notIn = $this->asList(value: $value['notIn'] ?? []);
		if ($notIn !== []) {
			$predicates[] = $this->listPredicate(field: $field, comparison: new FieldComparison(operator: 'notIn', operand: $notIn));
		}

		if (isset($value['ne']) === true) {
			$predicates[] = $this->comparisonPredicate(field: $field, comparison: new FieldComparison(operator: 'neq', operand: $value['ne']));
		}

		if (isset($value['isnull']) === true) {
			$predicates[] = $this->nullCheckPredicate(field: $field, flag: $value['isnull']);
		}

		return $predicates;
	}//end exclusionPredicates()

	/**
	 * The `isnull` predicate: IS NULL when the flag asks for null, IS NOT NULL otherwise.
	 *
	 * @param ObjectFieldFilter $field The field being filtered
	 * @param mixed             $flag  The value under the `isnull` key
	 *
	 * @return string The predicate.
	 */
	private function nullCheckPredicate(ObjectFieldFilter $field, mixed $flag): string {
		if ($this->isNullOperatorAsksForNull(value: $flag) === true) {
			return $field->qb->expr()->isNull($field->columnRef());
		}

		return $field->qb->expr()->isNotNull($field->columnRef());
	}//end nullCheckPredicate()

	/**
	 * A scalar as a one-element list; a list as it is.
	 *
	 * @param mixed $value The value
	 *
	 * @return array The list.
	 */
	private function asList(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [$value];
	}//end asList()

	/**
	 * Apply filter for JSON array columns using PostgreSQL jsonb operators
	 *
	 * @param ObjectFieldFilter $field The field: query builder, column, type and platform.
	 * @param mixed             $value The filter value (single value or array of values).
	 *
	 * @return string|ICompositeExpression The condition
	 */
	private function jsonArrayCondition(ObjectFieldFilter $field, mixed $value): string|ICompositeExpression {
		$qb         = $field->qb;
		$columnName = $field->columnName;
		// Normalize value to array.
		$values = [$value];
		if (is_array($value) === true) {
			$values = $value;
		}

		if (count($values) === 1) {
			// Single value: check if JSON array contains this value.
			// Use COALESCE to handle NULL values and avoid type cast issues with QueryBuilder.
			$jsonValue = json_encode([$values[0]]);
			return "COALESCE(t.{$columnName}, '[]')::jsonb @> " . $qb->createNamedParameter($jsonValue);
		}

		// Multiple values: check if JSON array contains ANY of the values (OR logic).
		$orConditions = $qb->expr()->orX();
		foreach ($values as $v) {
			$jsonValue = json_encode([$v]);
			// Use raw SQL with COALESCE to handle NULL values properly.
			$orConditions->add(
				"COALESCE(t.{$columnName}, '[]')::jsonb @> " . $qb->createNamedParameter($jsonValue)
			);
		}

		return $orConditions;
	}//end jsonArrayCondition()

	/**
	 * Filter on fields inside object properties (`dunning.currentStage=2`), on the QueryBuilder path.
	 *
	 * A path the JSON column cannot be read at (a segment that is not a plain
	 * name) matches nothing and is reported as an ignored filter, the way an
	 * unknown property is.
	 *
	 * @param TableFilterRun $run           The filter pass: query builder and the table read.
	 * @param array<int, array{root: string, path: list<string>, value: mixed}> $nestedFilters The dotted-path filters.
	 *
	 * @return void
	 *
	 * @throws EncryptedFieldFilterException When the object property is encrypted.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 * @SuppressWarnings(PHPMD.StaticAccess) NullFilter::conditionsFor() is the one reader of `orNull`, shared by every filter path.
	 */
	private function applyNestedPathFilters(TableFilterRun $run, array $nestedFilters): void {
		$qb         = $run->qb;
		$schema     = $run->schema();
		$isPostgres = $this->isPostgresPlatform();
		$properties = ($schema->getProperties() ?? []);
		foreach ($nestedFilters as $filter) {
			if (($properties[$filter['root']]['x-openregister-encrypted'] ?? false) === true) {
				throw new EncryptedFieldFilterException(property: $filter['root']);
			}

			// A filter on a path inside an object property is an oracle on that
			// property: one the caller may not read matches nothing.
			if ($this->relatedRows->mayReadProperty(schema: $schema, property: $filter['root']) === false) {
				$this->ignoredFilters[] = $filter['root'] . '.' . implode('.', $filter['path']);
				$qb->andWhere('1 = 0');
				continue;
			}

			$bind       = static fn (string $value): string => (string)$qb->createNamedParameter($value);
			$column     = new NestedPathTarget(expression: 't.' . $this->sanitizeColumnName(name: $filter['root']), bind: $bind, isPostgres: $isPostgres);
			$expression = $this->nestedPaths->expression(column: $column, path: $filter['path']);
			if ($expression === null) {
				$this->ignoredFilters[] = $filter['root'] . '.' . implode('.', $filter['path']);
				$qb->andWhere('1 = 0');
				continue;
			}

			$target     = new NestedPathTarget(expression: $expression, bind: $bind, isPostgres: $isPostgres);
			$conditions = NullFilter::conditionsFor(
				criterion: $filter['value'],
				column: $expression,
				build: fn (mixed $criterion): array => $this->nestedPaths->conditions(target: $target, value: $criterion)
			);
			foreach ($conditions as $condition) {
				$qb->andWhere($condition);
			}
		}
	}//end applyNestedPathFilters()

	/**
	 * Apply filter for object columns (related objects)
	 *
	 * Handles two storage formats:
	 * 1. JSON object (jsonb column): {"value": "uuid"} - extracts value key
	 * 2. Plain string (varchar column): "uuid" - direct comparison
	 *
	 * Uses text-based matching to work with both column types safely.
	 *
	 * @param ObjectFieldFilter $field The field: query builder, column, type and platform.
	 * @param mixed             $value The filter value (single value or array of values).
	 *
	 * @return string|ICompositeExpression The condition
	 */
	private function jsonObjectCondition(ObjectFieldFilter $field, mixed $value): string|ICompositeExpression {
		$qb         = $field->qb;
		$columnName = $field->columnName;
		$isPostgres = $field->isPostgres;
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
			$param = $qb->createNamedParameter($values[0]);
			$jsonPattern = $qb->createNamedParameter('%"value": "' . $values[0] . '"%');
			return "({$colCast} = {$param} OR {$colCast} LIKE {$jsonPattern})";
		}

		// Multiple values: check if value matches ANY of the values (OR logic).
		$orConditions = $qb->expr()->orX();
		foreach ($values as $v) {
			$param = $qb->createNamedParameter($v);
			$jsonPattern = $qb->createNamedParameter('%"value": "' . $v . '"%');
			$orConditions->add(
				"({$colCast} = {$param} OR {$colCast} LIKE {$jsonPattern})"
			);
		}

		return $orConditions;
	}//end jsonObjectCondition()

	/**
	 * Narrow a query to the objects one user has NOT seen since they last changed.
	 *
	 * Unread is the ABSENCE of a read-state row: a substantive write deletes
	 * every row for the object except its author's, so "no row" and "something
	 * has changed since you looked" are the same fact. That is what lets this be
	 * one correlated `NOT EXISTS` rather than a timestamp comparison against a
	 * column the per-schema object table does not carry.
	 *
	 * The subquery is built on a SECOND query builder but its parameter is
	 * created on the OUTER one, because only the outer builder's parameters are
	 * bound at execution. Creating it on the inner builder produces SQL with a
	 * placeholder nothing fills, which is a silent empty page, not an error.
	 *
	 * The lens is off unless `_unreadFor` named a reader, so the guard lives
	 * here rather than at the call site: `buildFilteredQuery()` already carries
	 * every other filter's branch, and one more is what pushed it over its
	 * complexity budget.
	 *
	 * @param IQueryBuilder $qb Query builder to modify.
	 * @param mixed $userId The reader whose read state is checked, or null when
	 *                      no unread lens was asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	private function applyUnreadFilter(IQueryBuilder $qb, mixed $userId): void {
		if (is_string($userId) === false || $userId === '') {
			return;
		}

		$reader = $qb->createNamedParameter($userId);

		$sub = $this->db->getQueryBuilder();
		$sub->select('rs.object_uuid')
			->from(ObjectReadStateMapper::TABLE, 'rs')
			->where($sub->expr()->eq('rs.user_id', $reader))
			->andWhere($sub->expr()->eq('rs.object_uuid', 't._uuid'));

		$qb->andWhere($qb->createFunction('NOT EXISTS (' . $sub->getSQL() . ')'));
	}//end applyUnreadFilter()

	/**
	 * Narrow a query to the objects one user has starred.
	 *
	 * The table is a parameter because the shape of the question, "which
	 * (user, object) rows exist", is not specific to stars. The recent lens
	 * used to share it; it now arrives as `_ids` from the audit trail's read
	 * history (`read-history-on-audit-trail`).
	 *
	 * The subquery is built on a SECOND query builder but its parameter is
	 * created on the OUTER one, because only the outer builder's parameters are
	 * bound at execution. Creating it on the inner builder produces SQL with a
	 * placeholder nothing fills, which is a silent empty page, not an error.
	 *
	 * The lens is off unless a uid was named, so the guard lives here rather
	 * than at the call site: `buildFilteredQuery()` already carries every other
	 * filter's branch.
	 *
	 * @param IQueryBuilder $qb     The query builder.
	 * @param mixed         $userId The user whose follows narrow the list; anything but a non-empty string leaves it alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
	 */
	private function applyPersonalLensFilter(IQueryBuilder $qb, mixed $userId): void {
		$table = WatcherMapperInterface::TABLE;
		if (is_string($userId) === false || $userId === '') {
			return;
		}

		$owner = $qb->createNamedParameter($userId);

		$sub = $this->db->getQueryBuilder();
		$sub->select('pl.object_uuid')
			->from($table, 'pl')
			->where($sub->expr()->eq('pl.user_id', $owner))
			->andWhere($sub->expr()->eq('pl.object_uuid', 't._uuid'));

		$qb->andWhere($qb->createFunction('EXISTS ('.$sub->getSQL().')'));

	}//end applyPersonalLensFilter()

	/**
	 * Order a `_recent=true` page by when this user last opened each object.
	 *
	 * The read history arrives already ordered, newest first, so the order is
	 * its position: a `CASE` over the page's uuids. That keeps the audit trail
	 * out of this query entirely, so no table name is concatenated into SQL
	 * and the table prefix cannot be forgotten (openregister#4507).
	 *
	 * Every parameter is created on the OUTER builder, because only its
	 * parameters are bound at execution.
	 *
	 * This never overrides an explicit `_order`: the caller asking for
	 * "recently opened, alphabetically" means it. It only replaces the default
	 * `t._id ASC`, which for this lens would be arbitrary and, worse, look
	 * deliberate.
	 *
	 * @param IQueryBuilder $qb          Query builder to modify.
	 * @param mixed         $recentViews Object uuid => last read, newest first, or null.
	 *
	 * @return boolean True when the recency order was applied.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
	 */
	private function applyRecencyOrder(IQueryBuilder $qb, mixed $recentViews): bool {
		if (is_array($recentViews) === false || $recentViews === []) {
			return false;
		}

		$whens = [];
		$position = 0;
		foreach (array_keys($recentViews) as $uuid) {
			$whens[] = 'WHEN '.$qb->createNamedParameter((string)$uuid).' THEN '.$position;
			$position++;
		}

		$qb->addOrderBy(
			$qb->createFunction('CASE t._uuid '.implode(' ', $whens).' ELSE '.$position.' END'),
			'ASC'
		);

		// A stable tie-break for anything the history did not place.
		$qb->addOrderBy('t._id', 'ASC');

		return true;

	}//end applyRecencyOrder()

	/**
	 * Attach `@self.viewedAt` to the objects of a `_recent` page.
	 *
	 * @param array<int, ObjectEntity> $objects     The page.
	 * @param mixed                    $recentViews Object uuid => ISO 8601 last read, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
	 */
	private function applyViewedAt(array $objects, mixed $recentViews): void {
		if (is_array($recentViews) === false || $recentViews === []) {
			return;
		}

		foreach ($objects as $object) {
			$moment = ($recentViews[(string)$object->getUuid()] ?? null);
			if (is_string($moment) === true) {
				$object->setViewedAt($moment);
			}
		}

	}//end applyViewedAt()

	/**
	 * Decide and apply the result order for one search.
	 *
	 * Three cases, in priority order: an explicit `_order` wins; then the
	 * `_recent` lens's own recency order; then the stable default on the primary
	 * key. They live in one method rather than a chain in `searchObjects()`
	 * because that method is already at its complexity budget, and because the
	 * priority is the interesting part and belongs in one place.
	 *
	 * @param IQueryBuilder $qb   Query builder to modify.
	 * @param SortRequest   $sort The requested order and what ordering it needs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
	 * @spec openspec/specs/register-i18n/spec.md#requirement-ordering-by-a-translatable-property-must-follow-the-value-a-person-sees
	 */
	private function applyResultOrder(IQueryBuilder $qb, SortRequest $sort): void {
		if (empty($sort->order) === false) {
			$this->applySorting(qb: $qb, sort: $sort);
			return;
		}

		if ($this->applyRecencyOrder(qb: $qb, recentViews: $sort->recentViews) === true) {
			return;
		}

		// BUG-DB-4: without an explicit order, LIMIT/OFFSET pagination is
		// non-deterministic (the database may return rows in any order),
		// causing duplicates/gaps across pages. Add a stable default order
		// on the monotonically increasing primary key.
		$qb->addOrderBy('t._id', 'ASC');

	}//end applyResultOrder()

	/**
	 * Apply ID-based filtering (UUID, slug, etc.)
	 *
	 * @param IQueryBuilder $qb Query builder to modify
	 * @param array $ids Array of IDs to filter by
	 *
	 * @return void
	 */
	private function applyIdFilters(IQueryBuilder $qb, array $ids): void {
		$orX = $qb->expr()->orX();
		$orX->add($qb->expr()->in('t._uuid', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
		$orX->add($qb->expr()->in('t._slug', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->andWhere($orX);
	}//end applyIdFilters()

	/**
	 * Keep the objects that reference every `_relations_contains` value.
	 *
	 * @param IQueryBuilder $qb    Query builder to modify
	 * @param array         $query The search query
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function applyRelationsContains(IQueryBuilder $qb, array $query): void {
		foreach ((array)($query['_relations_contains'] ?? []) as $uuid) {
			if (empty($uuid) === false) {
				$this->applyRelationsContainsFilter(qb: $qb, uuid: $uuid);
			}
		}
	}//end applyRelationsContains()

	/**
	 * Apply relations contains filter to find objects referencing a specific UUID.
	 *
	 * Relations can be stored as either an array (`["uuid1", "uuid2", ...]`, the
	 * legacy/common format) or an object (`{"fieldName": "uuid", ...}`, the newer
	 * format). Both dialects are handled below via a platform-conditional branch;
	 * PostgreSQL uses jsonb_typeof + `@>`, MariaDB/MySQL use JSON_SEARCH which
	 * walks both structures natively.
	 *
	 * Consumed by OpenCatalogi's public search (WOO-536 Stap 5a) — see WOO-548.
	 *
	 * @param IQueryBuilder $qb Query builder to modify
	 * @param string $uuid UUID to search for in relations
	 *
	 * @return void
	 */
	private function applyRelationsContainsFilter(IQueryBuilder $qb, string $uuid): void {
		$param = $qb->createNamedParameter($uuid);
		if ($this->isPostgresPlatform() === true) {
			$qb->andWhere(
				"(
                (jsonb_typeof(t._relations) = 'array' AND t._relations @> to_jsonb({$param}::text))
                OR
                (jsonb_typeof(t._relations) = 'object' AND EXISTS (
                    SELECT 1 FROM jsonb_each_text(t._relations) AS kv WHERE kv.value = {$param}
                ))
            )"
			);
			return;
		}

		// MariaDB / MySQL: JSON_SEARCH walks both array and object structures and
		// returns the path (or NULL) for the first match of the value at any
		// depth — semantic parallel to the PostgreSQL branch above. Available on
		// MariaDB 10.2+ / MySQL 5.7+ (both mandated by the NC platform baseline).
		$qb->andWhere("JSON_SEARCH(t._relations, 'one', {$param}) IS NOT NULL");
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
	private function extractRelationFieldFilters(array $query): array {
		$filters = [];
		foreach ($query as $key => $value) {
			if (str_starts_with($key, '_relations.') === false) {
				continue;
			}

			$field = substr($key, strlen('_relations.'));
			if ($field === '' || is_array($value) === true || $value === null) {
				continue;
			}

			$stringValue = (string)$value;
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
	 * @param IQueryBuilder      $qb    Query builder to modify.
	 * @param RelationFieldMatch $match The relation field and the referenced object id.
	 *
	 * @return void
	 */
	private function applyRelationFieldFilter(IQueryBuilder $qb, RelationFieldMatch $match): void {
		$field = $match->field;
		$value = $match->value;
		$valueParam = $qb->createNamedParameter($value);
		$exactKey = $qb->createNamedParameter($field);
		// Array-indexed relation keys are stored as `<field>.<n>` (e.g. `keywords.1`).
		$prefixParam = $qb->createNamedParameter($field . '.%');

		if ($this->isPostgresPlatform() === true) {
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
			return;
		}

		// MariaDB / MySQL fallback (WOO-548).
		//   1. Object with exact field key:  JSON_EXTRACT($.<field>) equals the value
		//   2. Object with array-indexed key: JSON_SEARCH restricted to path $.<field>.%
		//   3. Legacy array shape:            JSON_CONTAINS on the whole array
		// JSON path arg composed via CONCAT so the field-name parameter binds through.
		//
		// Divergence with the Postgres branch: PG matches a flat top-level key
		// literally named `<field>.<n>` via `kv.key LIKE '<field>.%'` (SQL LIKE
		// on the flat key string). MariaDB's `$.<field>.%` here is a JSON path
		// *navigator* — it descends into the member named `<field>` and
		// wildcard-matches its children. No writer in this repo currently
		// persists the flat dot-in-key shape (grep -rn '\\.[0-9]' lib/ against
		// _relations writers — empty), so the two branches are semantically
		// equivalent for every shape produced in practice. If a writer for the
		// flat-dot-in-key shape is ever added, this branch has to grow a
		// `JSON_SEARCH(JSON_KEYS(t._relations), 'one', {$prefixParam})`-based
		// key-scan too (or, cleaner, the writer switches to the nested-object
		// shape both branches already handle).
		$qb->andWhere(
			"(
                JSON_UNQUOTE(JSON_EXTRACT(t._relations, CONCAT('$.', {$exactKey}))) = {$valueParam}
                OR JSON_SEARCH(t._relations, 'one', {$valueParam}, NULL, CONCAT('$.', {$prefixParam})) IS NOT NULL
                OR JSON_CONTAINS(t._relations, JSON_QUOTE({$valueParam}))
            )"
		);
	}//end applyRelationFieldFilter()

	/**
	 * Build raw-SQL conditions for dotted relation-field filters (UNION path).
	 *
	 * Mirrors {@see applyRelationFieldFilter()} for the inline-quoted UNION
	 * query path used by multi-schema searches and facets.
	 *
	 * @param array<string,mixed> $query The full search query.
	 * @param object $connection Database connection for value quoting.
	 *
	 * @return string[] Array of SQL conditions (without leading AND/WHERE).
	 */
	private function buildRelationFilterConditionsSql(array $query, object $connection): array {
		$isPostgres = $this->isPostgresPlatform();
		$conditions = [];
		foreach ($this->extractRelationFieldFilters(query: $query) as $field => $value) {
			$valueQuoted = $connection->quote($value);
			$exactQuoted = $connection->quote($field);
			$prefixQuoted = $connection->quote($field . '.%');

			if ($isPostgres === true) {
				$conditions[] = "(
                (jsonb_typeof(_relations) = 'object' AND EXISTS (
                    SELECT 1 FROM jsonb_each_text(_relations) AS kv
                    WHERE kv.value = {$valueQuoted}
                      AND (kv.key = {$exactQuoted} OR kv.key LIKE {$prefixQuoted})
                ))
                OR
                (jsonb_typeof(_relations) = 'array' AND _relations @> to_jsonb({$valueQuoted}::text))
            )";
				continue;
			}

			// MariaDB / MySQL fallback — see applyRelationFieldFilter() (WOO-548).
			$conditions[] = "(
                JSON_UNQUOTE(JSON_EXTRACT(_relations, CONCAT('$.', {$exactQuoted}))) = {$valueQuoted}
                OR JSON_SEARCH(_relations, 'one', {$valueQuoted}, NULL, CONCAT('$.', {$prefixQuoted})) IS NOT NULL
                OR JSON_CONTAINS(_relations, JSON_QUOTE({$valueQuoted}))
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
	 * @param TableFilterRun $run          The filter pass; its query carries the non-empty `_search` term.
	 * @param bool           $fuzzyEnabled Whether fuzzy matching is enabled.
	 *
	 * @return void
	 */
	private function applyFullTextSearch(TableFilterRun $run, bool $fuzzyEnabled = false): void {
		$search = trim($run->query['_search']);
		$scope  = new SearchLeafScope(schema: $run->schema(), isPostgres: $this->isPostgresPlatform());
		$scope->fuzzyEnabled = $fuzzyEnabled;
		$searchRun           = new SearchTermRun(qb: $run->qb, term: $search, scope: $scope);

		// A term carrying AND/OR/NOT, brackets, a quoted phrase or a wildcard is
		// parsed and compiled. Anything else keeps the substring match below,
		// unchanged, which is what makes an operator-free term mean exactly what
		// it meant before this change.
		if ($this->termParser->needsParsing(term: $search) === true) {
			$this->applyParsedSearch(run: $searchRun);
			return;
		}

		$this->applySubstringSearch(run: $searchRun);
	}//end applyFullTextSearch()

	/**
	 * Narrow the query by a parsed boolean search term
	 *
	 * @param SearchTermRun $run The search: query builder, term and leaf scope.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function applyParsedSearch(SearchTermRun $run): void {
		$qb    = $run->qb;
		$scope = $run->scope;
		$qb->andWhere(
			$this->termCompiler->compile(
				node: $this->termParser->parse(term: $run->term),
				leafBuilder: fn (string $pattern, string $literal): string => $this->buildSearchLeafQbSql(
					qb: $qb,
					leaf: $scope->term(pattern: $pattern, literal: $literal)
				)
			)
		);
	}//end applyParsedSearch()

	/**
	 * Narrow the query by an operator-free term: a case-insensitive substring
	 * match on the text properties and the name, description and summary, plus
	 * a similarity match on the name when fuzzy matching is asked
	 *
	 * @param SearchTermRun $run The search: query builder, term and leaf scope.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function applySubstringSearch(SearchTermRun $run): void {
		$qb = $run->qb;
		$searchConditions = $qb->expr()->orX();

		// Use lowercase search for case-insensitive matching.
		$searchPattern   = $qb->createNamedParameter('%' . strtolower($run->term) . '%');
		$searchTermParam = $qb->createNamedParameter($run->term);
		$predicates      = array_merge(
			$this->propertySearchPredicates(run: $run, pattern: $searchPattern),
			$this->metadataSearchPredicates(qb: $qb, pattern: $searchPattern),
			$this->fuzzyNamePredicates(run: $run, termParam: $searchTermParam)
		);
		foreach ($predicates as $predicate) {
			$searchConditions->add($predicate);
		}

		$qb->andWhere($searchConditions);
	}//end applySubstringSearch()

	/**
	 * The substring predicates on the schema's text properties
	 *
	 * @param SearchTermRun      $run     The search: query builder, term and leaf scope.
	 * @param IParameter|string $pattern The bound `%term%` parameter.
	 *
	 * @return list<string|\Stringable> The predicates, in property order
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function propertySearchPredicates(SearchTermRun $run, IParameter|string $pattern): array {
		$qb         = $run->qb;
		$predicates = [];
		foreach ($run->scope->schema->getProperties() ?? [] as $field => $propertyConfig) {
			if ($this->isSubstringSearchable(propertyConfig: $propertyConfig) === false) {
				continue;
			}

			// Quote the column to handle reserved words (e.g., 'case', 'status').
			// Without quoting, LOWER(t.case) raises a PostgreSQL syntax error.
			$quotedCol    = $this->quoteIdentifier(name: $this->sanitizeColumnName(name: $field), isPostgres: $run->scope->isPostgres);
			$predicates[] = $qb->expr()->like($qb->createFunction("LOWER(t.{$quotedCol})"), $pattern);
		}

		return $predicates;
	}//end propertySearchPredicates()

	/**
	 * Whether the substring search scans a property: a text property that is
	 * neither encrypted nor date/time formatted
	 *
	 * An encrypted property's column holds ciphertext (see
	 * MagicMapper::buildTableColumnsFromSchema()); including it in a LIKE
	 * full-text scan would scan envelopes that can never match a plaintext
	 * search term. Date/time formatted fields are skipped because PostgreSQL
	 * LOWER() only works on text columns (LIKE only, for performance).
	 *
	 * @param mixed $propertyConfig The property definition.
	 *
	 * @return bool True when the property is scanned
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
	 */
	private function isSubstringSearchable(mixed $propertyConfig): bool {
		if (($propertyConfig['x-openregister-encrypted'] ?? false) === true) {
			return false;
		}

		return ($propertyConfig['type'] ?? '') === 'string'
			&& in_array($propertyConfig['format'] ?? '', ['date', 'date-time', 'time'], true) === false;
	}//end isSubstringSearchable()

	/**
	 * The substring predicates on the name, description and summary columns
	 *
	 * @param IQueryBuilder     $qb      The query builder.
	 * @param IParameter|string $pattern The bound `%term%` parameter.
	 *
	 * @return list<string|\Stringable> The predicates
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function metadataSearchPredicates(IQueryBuilder $qb, IParameter|string $pattern): array {
		$predicates = [];
		foreach (['_name', '_description', '_summary'] as $metadataColumn) {
			$predicates[] = $qb->expr()->like($qb->createFunction("LOWER(t.{$metadataColumn})"), $pattern);
		}

		return $predicates;
	}//end metadataSearchPredicates()

	/**
	 * The similarity predicate on the name, only when fuzzy matching is asked via `_fuzzy=true`
	 *
	 * This uses pg_trgm similarity() for typo tolerance at ~13% performance cost.
	 *
	 * @param SearchTermRun     $run       The search: query builder, term and leaf scope.
	 * @param IParameter|string $termParam The bound term parameter.
	 *
	 * @return list<string|\Stringable> The predicate, or none
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function fuzzyNamePredicates(SearchTermRun $run, IParameter|string $termParam): array {
		if ($run->scope->fuzzyEnabled === false) {
			return [];
		}

		return [$run->qb->createFunction("similarity(t._name::text, {$termParam}) > 0.1")];
	}//end fuzzyNamePredicates()

	/**
	 * Build the SQL for one literal term on the QueryBuilder path.
	 *
	 * Mirrors the column rules of the UNION path, with the `t.` alias, and
	 * emits raw SQL so the boolean compiler can wrap it in AND, OR and NOT.
	 * Values still travel as bound parameters.
	 *
	 * Every column is wrapped in COALESCE. LIKE against a NULL column is NULL,
	 * and NOT NULL is NULL rather than TRUE, so without this a record with no
	 * text at all would silently fall out of `NOT geweigerd`.
	 *
	 * @param IQueryBuilder $qb   The query builder, for parameter binding.
	 * @param SearchLeaf    $leaf The term in its scope.
	 *
	 * @return string The SQL condition, already parenthesised.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Same pure reader as buildSearchLeafSql(), and it has
	 *                                      to be the same one or the two paths could disagree.
	 */
	private function buildSearchLeafQbSql(IQueryBuilder $qb, SearchLeaf $leaf): string {
		$run        = new SearchLeafRun(qb: $qb, leaf: $leaf, patternParam: (string)$qb->createNamedParameter($leaf->pattern));
		$conditions = array_merge(
			$this->leafPropertyConditionsQbSql(run: $run),
			$this->leafMetadataConditionsQbSql(run: $run)
		);

		return '(' . implode(' OR ', $conditions) . ')';
	}//end buildSearchLeafQbSql()

	/**
	 * The conditions of one literal term on the searchable schema properties
	 *
	 * @param SearchLeafRun $run The leaf: query builder, term and its bound pattern.
	 *
	 * @return string[] The conditions, in property order
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) Same pure reader as buildSearchLeafSql(), and it has
	 *                                      to be the same one or the two paths could disagree.
	 */
	private function leafPropertyConditionsQbSql(SearchLeafRun $run): array {
		$conditions = [];
		foreach ($this->searchableProperties(scope: $run->leaf->scope) as $field => $propertyConfig) {
			$quotedCol = $this->searchColumn(scope: $run->leaf->scope, propName: $field);
			$matchType = PropertySearchProfile::matchTypeFor(property: $propertyConfig);
			if ($matchType === PropertySearchProfile::MATCH_FUZZY && $run->leaf->scope->isPostgres === true) {
				$conditions[] = $this->leafFuzzyConditionQbSql(run: $run, quotedCol: $quotedCol);
				continue;
			}

			$columnPattern = $this->leafColumnPatternQbSql(run: $run, matchType: $matchType);
			$conditions[]  = "LOWER(COALESCE(t.{$quotedCol}, '')) LIKE {$columnPattern}";
		}

		return $conditions;
	}//end leafPropertyConditionsQbSql()

	/**
	 * The similarity condition of a fuzzy property on PostgreSQL
	 *
	 * @param SearchLeafRun $run       The leaf: query builder, term and its bound pattern.
	 * @param string        $quotedCol The quoted column.
	 *
	 * @return string The condition
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function leafFuzzyConditionQbSql(SearchLeafRun $run, string $quotedCol): string {
		$fuzzyParam = $run->qb->createNamedParameter($run->leaf->literal);
		return "similarity(COALESCE(t.{$quotedCol}::text, ''), {$fuzzyParam}) > 0.1";
	}//end leafFuzzyConditionQbSql()

	/**
	 * The bound pattern a property compares with: the term's own for a
	 * full-text property, else one shaped for its match type
	 *
	 * @param SearchLeafRun $run       The leaf: query builder, term and its bound pattern.
	 * @param string        $matchType The property's match type.
	 *
	 * @return string The bound parameter
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function leafColumnPatternQbSql(SearchLeafRun $run, string $matchType): string {
		if ($matchType === PropertySearchProfile::MATCH_FULLTEXT) {
			return $run->patternParam;
		}

		return (string)$run->qb->createNamedParameter($this->patternForMatchType(matchType: $matchType, leaf: $run->leaf));
	}//end leafColumnPatternQbSql()

	/**
	 * The conditions of one literal term on the name, description and summary,
	 * plus a similarity match on the name when fuzzy matching is on
	 *
	 * @param SearchLeafRun $run The leaf: query builder, term and its bound pattern.
	 *
	 * @return string[] The conditions
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function leafMetadataConditionsQbSql(SearchLeafRun $run): array {
		$conditions = [];
		foreach (['_name', '_description', '_summary'] as $metadataColumn) {
			$conditions[] = "LOWER(COALESCE(t.{$metadataColumn}, '')) LIKE {$run->patternParam}";
		}

		if ($run->leaf->scope->fuzzyEnabled === true) {
			$literalParam = $run->qb->createNamedParameter($run->leaf->literal);
			$conditions[] = "similarity(COALESCE(t._name::text, ''), {$literalParam}) > 0.1";
		}

		return $conditions;
	}//end leafMetadataConditionsQbSql()

	/**
	 * Apply sorting to the query
	 *
	 * @param IQueryBuilder $qb   Query builder to modify
	 * @param SortRequest   $sort The requested order, its schema, search term, date columns and language chain
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md#requirement-ordering-by-a-translatable-property-must-follow-the-value-a-person-sees
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/register-i18n/spec.md#requirement-ordering-by-a-text-property-must-ignore-case
	 */
	private function applySorting(IQueryBuilder $qb, SortRequest $sort): void {
		$properties = $sort->schema->getProperties();

		foreach ($sort->order as $field => $direction) {
			$field = (string) $field;
			$direction = strtoupper($direction);
			if (in_array($direction, ['ASC', 'DESC']) === false) {
				$direction = 'ASC';
			}

			// Relevance sorting needs a search term and the pg_trgm extension;
			// without them the field is skipped, as ordering by relevance
			// without a search makes no sense.
			if ($field === '_relevance') {
				$relevance = $this->relevanceSortExpression(qb: $qb, sort: $sort);
				if ($relevance !== null) {
					$qb->addOrderBy($relevance, $direction);
				}

				continue;
			}

			if (str_starts_with($field, '@self.') === true) {
				// Metadata field sorting (e.g., @self.created → t._created,
				// @self.lastUserActivity → t._last_user_activity).
				$metadataField = '_' . $this->sanitizeColumnName(name: str_replace('@self.', '', $field));
				$qb->addOrderBy($this->metadataSortExpression(qb: $qb, column: $metadataField), $direction);
			} elseif (in_array($field, self::SORTABLE_METADATA_COLUMNS, true) === true) {
				// Direct metadata column reference (e.g., _created → t._created).
				$qb->addOrderBy($this->metadataSortExpression(qb: $qb, column: $field), $direction);
			} elseif (is_array($properties[$field] ?? null) === true
				&& ($properties[$field]['translatable'] ?? false) === true
			) {
				// A translatable property holds a language map on rows saved
				// since translations arrived and a plain string on older rows.
				// Ordering on the raw column text put every plain row before
				// every map row: two sorted runs (dossiq case types, cloud
				// check 8 October 2026). Order on the value a person sees.
				// Lower-cased, like every text order (see buildPropertySortExpression()).
				$column = $qb->getColumnName($this->sanitizeColumnName(name: $field), 't');
				$qb->addOrderBy(
					$qb->createFunction(
						$this->lowerSql(
							expression: $this->buildTranslatableSortSql(column: $column, languages: $sort->sortLanguages)
						)
					),
					$direction
				);
			} elseif (($properties[$field] ?? null) !== null) {
				// Schema property field sorting.
				$qb->addOrderBy(
					$this->buildPropertySortExpression(
						qb: $qb,
						columnName: $this->sanitizeColumnName(name: $field),
						propertyConfig: $properties[$field],
						dateTimeColumns: $sort->dateTimeColumns
					),
					$direction
				);
			}//end if
		}//end foreach
	}//end applySorting()

	/**
	 * The relevance order of a search: similarity of the name to the search term.
	 *
	 * @param IQueryBuilder $qb   Query builder the expression binds its parameter on.
	 * @param SortRequest   $sort The requested order, carrying the search term.
	 *
	 * @return IQueryFunction|null The expression, or null without a search term or pg_trgm.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-multi-field-sorting-with-metadata-and-relevance-support
	 */
	private function relevanceSortExpression(IQueryBuilder $qb, SortRequest $sort): ?IQueryFunction {
		if ($sort->searchTerm === null || $this->hasPgTrgmExtension() === false) {
			return null;
		}

		// Nextcloud's QueryBuilder.addOrderBy() accepts expressions through createFunction().
		$paramName = $qb->createNamedParameter($sort->searchTerm);
		return $qb->createFunction("similarity(t._name::text, {$paramName})");
	}//end relevanceSortExpression()

	/**
	 * The languages a translatable property sorts by, in the order rendering
	 * picks them.
	 *
	 * Mirrors TranslationHandler::resolveTranslationsForRender(): the request's
	 * accepted languages that the register offers (or the preferred language
	 * when the register lists none), then the register's languages, then its
	 * default. It reads the language service without calling
	 * resolveLanguageForRegister(), which would flag a fallback on the
	 * response for a page that may hold no translatable value at all.
	 *
	 * @param Register $register The register being searched.
	 *
	 * @return array<int, string> Language codes, first wins.
	 *
	 * @spec openspec/specs/register-i18n/spec.md#requirement-ordering-by-a-translatable-property-must-follow-the-value-a-person-sees
	 */
	private function sortLanguageChain(Register $register): array {
		$registerLanguages = array_values(
			array_filter(($register->getLanguages() ?? []), 'is_string')
		);

		$chain = [];
		if ($this->languageService !== null) {
			if ($registerLanguages === []) {
				$chain[] = $this->languageService->getPreferredLanguage();
			}

			foreach ($this->languageService->getAcceptedLanguages() as $accepted) {
				$base = strtolower(explode('-', (string) $accepted)[0]);
				foreach ([(string) $accepted, $base] as $candidate) {
					if (in_array($candidate, $registerLanguages, true) === true) {
						$chain[] = $candidate;
					}
				}
			}
		}

		$chain = array_merge($chain, $registerLanguages, [$register->getDefaultLanguage()]);

		return array_values(array_unique($chain));
	}//end sortLanguageChain()

	/**
	 * Build the ORDER BY expression for a translatable property column.
	 *
	 * A row holding a language map (`{"nl": "Woo-verzoek"}`) sorts by its value
	 * in the first language of the chain that it has, else by its first value.
	 * A row holding a plain string sorts by that string. PostgreSQL extracts
	 * with a regular expression rather than a jsonb cast, because a plain value
	 * that happens to start with a brace would make a cast fail the whole query.
	 * MySQL and MariaDB guard their JSON functions with JSON_VALID. SQLite uses
	 * its built-in JSON functions the same way.
	 *
	 * Language codes are reduced to `[a-z0-9-]` before they enter the SQL, so
	 * the expression carries no user input.
	 *
	 * @param string $column The quoted column reference.
	 * @param array<int, string> $languages Language chain, first wins.
	 *
	 * @return string The SQL expression.
	 *
	 * @spec openspec/specs/register-i18n/spec.md#requirement-ordering-by-a-translatable-property-must-follow-the-value-a-person-sees
	 */
	private function buildTranslatableSortSql(string $column, array $languages): string {
		$codes = [];
		foreach ($languages as $language) {
			$code = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $language));
			if (is_string($code) === true && $code !== '' && in_array($code, $codes, true) === false) {
				$codes[] = $code;
			}
		}

		$platform = $this->db->getDatabasePlatform()::class;

		if (stripos($platform, 'PostgreSQL') !== false) {
			// One JSON string value: quotes around anything but an unescaped quote.
			$valuePattern = '\s*:\s*"((?:[^"\\\\]|\\\\.)*)"';
			$text = "CAST({$column} AS TEXT)";
			$picks = [];
			foreach ($codes as $code) {
				$picks[] = "substring({$text} from '\"{$code}\"{$valuePattern}')";
			}

			$picks[] = "substring({$text} from '^\\{\\s*\"[^\"]*\"{$valuePattern}')";
			$picks[] = $text;

			return "CASE WHEN {$text} LIKE '{%' THEN COALESCE(".implode(', ', $picks).") ELSE {$text} END";
		}

		if (stripos($platform, 'MySQL') !== false || stripos($platform, 'MariaDB') !== false) {
			$picks = [];
			foreach ($codes as $code) {
				$picks[] = "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.\"{$code}\"'))";
			}

			$picks[] = "JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT({$column}, '$.*'), '$[0]'))";
			$picks[] = $column;

			// Nested rather than AND: JSON_TYPE() raises an error on a value that
			// is not JSON, and only CASE promises not to evaluate it then.
			return "CASE WHEN JSON_VALID({$column}) THEN CASE WHEN JSON_TYPE({$column}) = 'OBJECT'"
				." THEN COALESCE(".implode(', ', $picks).") ELSE {$column} END ELSE {$column} END";
		}

		if (stripos($platform, 'Sqlite') !== false) {
			$picks = [];
			foreach ($codes as $code) {
				$picks[] = "json_extract({$column}, '$.\"{$code}\"')";
			}

			$picks[] = "(SELECT value FROM json_each({$column}) LIMIT 1)";
			$picks[] = $column;

			return "CASE WHEN json_valid({$column}) THEN CASE WHEN json_type({$column}) = 'object'"
				." THEN COALESCE(".implode(', ', $picks).") ELSE {$column} END ELSE {$column} END";
		}

		return $column;
	}//end buildTranslatableSortSql()

	/**
	 * The ORDER BY expression for a metadata column.
	 *
	 * The text columns a person reads (name, description, summary) sort by
	 * their lower-cased value; dates, ids and owners keep the bare column.
	 *
	 * @param IQueryBuilder $qb     Query builder, used for identifier quoting.
	 * @param string        $column The metadata column, with its leading underscore.
	 *
	 * @return string|IQueryFunction The column reference or LOWER() expression.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/register-i18n/spec.md#requirement-ordering-by-a-text-property-must-ignore-case
	 */
	private function metadataSortExpression(IQueryBuilder $qb, string $column): string|IQueryFunction {
		if (in_array($column, ['_name', '_description', '_summary'], true) === true) {
			return $qb->createFunction($this->lowerSql(expression: $qb->getColumnName($column, 't')));
		}

		return "t.{$column}";
	}//end metadataSortExpression()

	/**
	 * Lower-case an SQL expression for ordering, on every supported database.
	 *
	 * PostgreSQL has no LOWER() for a json, timestamp or numeric column, and a
	 * table keeps its column type when a property later changes type, so the
	 * value is cast to text first there. MySQL/MariaDB and SQLite convert to a
	 * string themselves.
	 *
	 * @param string $expression A quoted column reference or SQL expression.
	 *
	 * @return string The lower-casing SQL expression.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/register-i18n/spec.md#requirement-ordering-by-a-text-property-must-ignore-case
	 */
	private function lowerSql(string $expression): string {
		$platform = $this->db->getDatabasePlatform();
		if (is_object($platform) === true && stripos($platform::class, 'PostgreSQL') !== false) {
			return "LOWER(CAST({$expression} AS TEXT))";
		}

		return "LOWER({$expression})";
	}//end lowerSql()

	/**
	 * Build the ORDER BY expression for one schema property column.
	 *
	 * A `date` or `date-time` property sorts as if an empty value held the
	 * object's own creation date (`_created`, which every magic table row
	 * carries). Without that fallback an empty date sorts above the newest
	 * dated object on PostgreSQL (NULLs are largest there) and below the
	 * oldest on MySQL/MariaDB (NULLs are smallest), so the same list read
	 * differently per database. A text property (a string that is not a
	 * date, and not encrypted) sorts by its lower-cased value. Every other
	 * property keeps its bare column.
	 *
	 * The fallback is only applied when the column is known to be a real date
	 * or timestamp column ($dateTimeColumns). A property that gained its date
	 * format after its table was created keeps a text column (the table sync
	 * never retypes), and PostgreSQL rejects COALESCE(text, timestamp). When
	 * the column types are unknown (null) the bare column is used.
	 *
	 * The column name passed in is already sanitised to [a-z0-9_]; it is
	 * quoted again through the query builder so a reserved word stays valid.
	 *
	 * @param IQueryBuilder $qb             Query builder, used for identifier quoting.
	 * @param string        $columnName     The sanitised property column name.
	 * @param mixed         $propertyConfig The property's schema definition.
	 * @param array<int, string>|null $dateTimeColumns Columns whose real type is a date or timestamp; null when unknown.
	 *
	 * @return string|IQueryFunction The column reference or COALESCE expression.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/register-i18n/spec.md#requirement-ordering-by-a-text-property-must-ignore-case
	 */
	private function buildPropertySortExpression(
		IQueryBuilder $qb,
		string $columnName,
		mixed $propertyConfig,
		?array $dateTimeColumns = null
	): string|IQueryFunction {
		$isText = is_array($propertyConfig) === true
			&& ($propertyConfig['type'] ?? 'string') === 'string'
			&& in_array($propertyConfig['format'] ?? null, ['date', 'date-time'], true) === false
			&& ($propertyConfig['x-openregister-encrypted'] ?? false) === false;
		if ($isText === true) {
			// Text orders without regard to case, as OpenRegister's own lists
			// do: "Leverancier accreditatie" before "Leverancier IBAN-wijziging"
			// (cloud check, 9 October 2026). LOWER() exists on PostgreSQL,
			// MySQL/MariaDB and SQLite alike.
			return $qb->createFunction($this->lowerSql(expression: $qb->getColumnName($columnName, 't')));
		}

		if ($dateTimeColumns === null
			|| in_array(strtolower($columnName), $dateTimeColumns, true) === false
			|| is_array($propertyConfig) === false
			|| ($propertyConfig['type'] ?? 'string') !== 'string'
			|| in_array($propertyConfig['format'] ?? null, ['date', 'date-time'], true) === false
			|| ($propertyConfig['x-openregister-encrypted'] ?? false) === true
		) {
			return "t.{$columnName}";
		}

		$propertyColumn = $qb->getColumnName($columnName, 't');
		$createdColumn = $qb->getColumnName('_created', 't');

		return $qb->createFunction("COALESCE({$propertyColumn}, {$createdColumn})");
	}//end buildPropertySortExpression()

	/**
	 * Execute search query and convert results to ObjectEntity objects
	 *
	 * @param IQueryBuilder $qb     The query builder to execute.
	 * @param TableSearch   $search The register, schema and table searched.
	 *
	 * @return ObjectEntity[]
	 *
	 * @throws \OCP\DB\Exception If query execution fails
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	private function executeSearchQuery(IQueryBuilder $qb, TableSearch $search): array {
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$objects = [];

		foreach ($rows as $row) {
			$objectEntity = $this->convertRowToObjectEntity(row: $row, search: $search);
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
	private function getSchemaColumnMaps(Schema $schema): array {
		$schemaId = $schema->getId();
		if ($schemaId !== null && isset($this->schemaColumnMapCache[$schemaId]) === true) {
			return $this->schemaColumnMapCache[$schemaId];
		}

		$propertyTypes = [];
		$propertyFormats = [];
		$columnToPropertyMap = [];
		$properties = $schema->getProperties();
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
			'types' => $propertyTypes,
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
	 * @param array       $row    The database row.
	 * @param TableSearch $search The register, schema and table the row came from.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	private function convertRowToObjectEntity(array $row, TableSearch $search): ?ObjectEntity {
		$register  = $search->table->register;
		$schema    = $search->table->schema;
		$tableName = $search->tableName;
		try {
			$objectEntity = new ObjectEntity();
			$columns      = $this->splitRowColumns(row: $row, schema: $schema);

			$this->applyRowMetadata(entity: $objectEntity, metadata: $columns['metadata']);
			$objectEntity->setRegister((string)$register->getId());
			$objectEntity->setSchema((string)$schema->getId());
			$objectEntity->setObject($columns['object']);

			return $objectEntity;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[MagicSearchHandler] Failed to convert row to ObjectEntity',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'tableName' => $tableName,
					'columns' => array_keys($row),
				]
			);

			return null;
		}//end try
	}//end convertRowToObjectEntity()

	/**
	 * Split a magic-table row into its metadata columns and its typed property values.
	 *
	 * A column maps back to its original property name through the schema (`e_mailadres`
	 * is `e-mailadres`), or to its camelCase form when the schema does not declare it.
	 *
	 * @param array  $row    Database row data
	 * @param Schema $schema Schema context
	 *
	 * @return array{metadata: array<string, mixed>, object: array<string, mixed>}
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	private function splitRowColumns(array $row, Schema $schema): array {
		$schemaMaps          = $this->getSchemaColumnMaps(schema: $schema);
		$propertyFormats     = ($schemaMaps['formats'] ?? []);
		$columnToPropertyMap = $schemaMaps['columns'];
		$metadata            = [];
		$object = [];

		foreach ($row as $column => $value) {
			$metadataField = $this->columnRoles->field(column: $column, columnToPropertyMap: $columnToPropertyMap);
			if ($metadataField !== null) {
				$metadata[$metadataField] = $value;
				continue;
			}

			$propertyName          = $columnToPropertyMap[$column] ?? $this->columnNameToPropertyName(columnName: $column);
			$object[$propertyName] = $this->convertRowValue(
				value: $value,
				property: [
					'type'   => ($schemaMaps['types'][$propertyName] ?? 'string'),
					'format' => ($propertyFormats[$propertyName] ?? null),
				]
			);
		}

		return ['metadata' => $metadata, 'object' => $object];
	}//end splitRowColumns()

	/**
	 * A column value as its schema property type and format read it.
	 *
	 * A `date` or `date-time` property lives in a DATETIME column, so the driver returns
	 * 'Y-m-d H:i:s'. That fails the schema's own format when the object is written back,
	 * so the value is normalised here, as MagicStatisticsHandler does. A `date-time` column
	 * carries no offset and is read back in UTC, the timezone the write path stored it in.
	 *
	 * @param mixed                                     $value    The column value
	 * @param array{type: string, format: string|null} $property The schema property type and format
	 *
	 * @return mixed
	 */
	private function convertRowValue(mixed $value, array $property): mixed {
		$format = $property['format'];
		$value  = $this->schemaTypeConverter->convertValue(value: $value, schemaType: $property['type']);
		if ($value === null || is_string($value) === false || $format === null) {
			return $value;
		}

		if ($format === 'date') {
			$normalised = $this->dateTimeNormalizer->normalize($value);
			if ($normalised === null) {
				return null;
			}

			return $normalised->format('Y-m-d');
		}

		if ($format === 'date-time') {
			return $this->dateTimeNormalizer->formatDatabaseValueForIso8601($value);
		}

		return $value;
	}//end convertRowValue()

	/**
	 * Set every metadata column the row holds a value for, in the order of {@see self::ROW_METADATA_KINDS}.
	 *
	 * @param ObjectEntity         $entity   The entity being hydrated
	 * @param array<string, mixed> $metadata The row's metadata columns, keyed without their prefix
	 *
	 * @return void
	 */
	private function applyRowMetadata(ObjectEntity $entity, array $metadata): void {
		foreach (self::ROW_METADATA_KINDS as $field => $kind) {
			if (($metadata[$field] ?? null) === null) {
				continue;
			}

			$setter = 'set'.str_replace('_', '', ucwords($field, '_'));
			foreach ($this->rowMetadataValues(kind: $kind, value: $metadata[$field]) as $setterValue) {
				$entity->$setter($setterValue);
			}
		}
	}//end applyRowMetadata()

	/**
	 * The values one metadata column sets on the entity, in the order they are set.
	 *
	 * A JSON column sets its fallback first, then the decoded array when it is one.
	 *
	 * @param string $kind  The field's kind in {@see self::ROW_METADATA_KINDS}
	 * @param mixed  $value The column value, not null
	 *
	 * @return list<mixed>
	 */
	private function rowMetadataValues(string $kind, mixed $value): array {
		switch ($kind) {
			case 'datetime':
				return [new DateTime($value)];
			case 'deleted':
				return [$this->deletedMarker(raw: $value)];
			case 'float':
				return [(float)$value];
			case 'json':
				return $this->rowJsonValues(value: $value, fallback: []);
			case 'json-or-null':
				return $this->rowJsonValues(value: $value, fallback: null);
			default:
				return [$value];
		}
	}//end rowMetadataValues()

	/**
	 * The values a JSON metadata column sets: the fallback, then the decoded array when it is one.
	 *
	 * @param mixed      $value    The column value, a JSON string or already decoded
	 * @param array|null $fallback The value set when the column holds no array
	 *
	 * @return list<array|null>
	 */
	private function rowJsonValues(mixed $value, ?array $fallback): array {
		$decoded = $value;
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
		}

		if (is_array($decoded) === true) {
			return [$fallback, $decoded];
		}

		return [$fallback];
	}//end rowJsonValues()

	/**
	 * The `_deleted` column as the marker `ObjectEntity::setDeleted()` takes.
	 *
	 * The column is JSON (deletedAt, deletedBy, purgeDate, the window); a row
	 * written before it was JSON holds a bare timestamp. Reading the JSON as a
	 * timestamp threw, and the row was dropped from every list that asked for
	 * deleted rows.
	 *
	 * @param mixed $raw The column value.
	 *
	 * @return array<string, mixed> The marker.
	 *
	 * @spec openspec/changes/object-deleted-lens/specs/object-lifecycle/spec.md
	 */
	private function deletedMarker(mixed $raw): array {
		if (is_array($raw) === true) {
			return $raw;
		}

		$decoded = json_decode((string)$raw, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return ['deleted' => (new DateTime((string)$raw))->format('c')];
	}//end deletedMarker()

	/**
	 * Whether the active database platform is PostgreSQL.
	 *
	 * Mirrors the idiom used in MagicMapper, MagicFacetHandler, SettingsService
	 * and AggregationRunner so that all platform-detection sites in the codebase
	 * agree on a single check.
	 *
	 * @return bool True when the connection's platform class name contains 'PostgreSQL'.
	 */
	private function isPostgresPlatform(): bool {
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
	private function isNumericColumnType(string $propertyType): bool {
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
	private function isNonNumericScalar(mixed $value): bool {
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
	 * @param TypedColumnRef $column The column reference, its declared type and the platform.
	 * @param mixed          $value  The filter value (or list of values).
	 *
	 * @return string The (optionally text-cast) column reference.
	 */
	private function buildFilterColumnRef(TypedColumnRef $column, mixed $value): string {
		$columnRef    = $column->ref;
		$propertyType = $column->propertyType;
		$isPostgres   = $column->isPostgres;
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
			return $columnRef . '::text';
		}

		return 'CAST(' . $columnRef . ' AS CHAR)';
	}//end buildFilterColumnRef()

	/**
	 * Quote a column or identifier name for the current database platform.
	 *
	 * Mirrors MagicMapper::quoteIdentifier(). Kept as a private helper here
	 * because MagicSearchHandler is a collaborator of MagicMapper, not a
	 * subclass, and the helper there is private.
	 *
	 * @param string $name The unquoted identifier name.
	 * @param bool $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return string The quoted identifier.
	 */
	private function quoteIdentifier(string $name, bool $isPostgres): string {
		if ($isPostgres === true) {
			return '"' . $name . '"';
		}

		return '`' . $name . '`';
	}//end quoteIdentifier()

	/**
	 * Convert snake_case column name to camelCase property name
	 *
	 * @param string $columnName Column name in snake_case
	 *
	 * @return string Property name in camelCase
	 */
	private function columnNameToPropertyName(string $columnName): string {
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
	private function hasPublicReadAccess(array $readRules): bool {
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
