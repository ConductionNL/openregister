<?php

/**
 * MagicMapper Facet Handler
 *
 * This handler provides advanced faceting and aggregation capabilities for dynamic
 * schema-based tables. It implements sophisticated faceting functionality including
 * terms facets, date histograms, range facets, and statistical aggregations
 * optimized for schema-specific table structures.
 *
 * KEY RESPONSIBILITIES:
 * - Terms faceting for categorical data in dynamic tables
 * - Date histogram faceting for temporal data analysis
 * - Range faceting for numerical data analysis
 * - Statistical aggregations (min, max, avg, sum, count)
 * - Schema-aware faceting with automatic field discovery
 * - Optimized facet queries for performance
 *
 * FACETING CAPABILITIES:
 * - Metadata facets (register, schema, owner, organization, etc.)
 * - Schema property facets based on JSON schema definitions
 * - Combined faceting with complex filtering
 * - Cardinality estimation for facet optimization
 * - Multi-level aggregations and drill-down support
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
 * @since 2.0.0 Initial implementation for MagicMapper faceting capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\SchemaProperty;
use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks;
use DateTime;
use LogicException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Faceting and aggregation handler for MagicMapper dynamic tables
 *
 * This class provides comprehensive faceting functionality for dynamically created
 * schema-based tables, offering better performance than generic table faceting
 * due to schema-specific optimizations.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Facet computation requires schema, register, and query dependencies
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)          Each facet type is split into small single-purpose steps
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class MagicFacetHandler {
	use MagicColumnNameTrait;

	/**
	 * Reserved query parameters that are never object field filters.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED_FILTER_PARAMS = [
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
		'_includeDeleted',
		'_deleted',
		'_empty',
		'@self',
	];

	/**
	 * Maximum number of buckets to return per facet.
	 * Set to high value to effectively disable limit (was 50).
	 */
	private const MAX_FACET_BUCKETS = 10000;

	/**
	 * Suffixes that turn a year or day bucket key into its from/to dates.
	 */
	private const DATE_KEY_SUFFIXES = [
		'year' => ['-01-01', '-12-31'],
		'day' => ['', ''],
	];

	/**
	 * PostgreSQL TO_CHAR pattern of each date histogram interval.
	 */
	private const PG_DATE_FORMATS = [
		'day' => 'YYYY-MM-DD',
		'week' => 'IYYY-IW',
		'month' => 'YYYY-MM',
		'year' => 'YYYY',
		'quarter' => 'YYYY-"Q"Q',
	];

	/**
	 * MariaDB/MySQL DATE_FORMAT pattern of each date histogram interval but quarter.
	 *
	 * Week is ISO year + ISO week, matching PostgreSQL IYYY-IW.
	 */
	private const MYSQL_DATE_FORMATS = [
		'day' => '%Y-%m-%d',
		'week' => '%x-%v',
		'year' => '%Y',
		'month' => '%Y-%m',
	];

	/**
	 * Metadata column prefix used in MagicMapper tables.
	 */
	private const METADATA_PREFIX = '_';

	/**
	 * TTL for facet label cache (24 hours).
	 * Labels rarely change, so long TTL is appropriate.
	 */
	private const FACET_LABEL_CACHE_TTL = 86400;

	/**
	 * In-memory cache for facet results within a single request.
	 *
	 * @var array
	 */
	private array $facetCache = [];

	/**
	 * In-memory cache for UUID to label mappings (batch-resolved).
	 *
	 * @var array
	 */
	private array $uuidLabelCache = [];

	/**
	 * In-memory cache for field-level label maps (persistent across searches).
	 * Structure: ['tableName:fieldName' => ['uuid1' => 'label1', ...]]
	 *
	 * @var array
	 */
	private array $fieldLabelCache = [];

	/**
	 * Tracks which fields have been warmed in the distributed cache.
	 *
	 * @var array
	 */
	private array $warmedFields = [];

	/**
	 * Cache statistics for performance debugging.
	 * Tracks hits/misses for facet label resolution.
	 *
	 * @var array
	 */
	private array $cacheStats = [
		'field_cache_hits' => 0,
		'distributed_cache_hits' => 0,
		'cache_handler_calls' => 0,
		'total_uuids_resolved' => 0,
	];

	/**
	 * In-memory cache for column existence checks.
	 * Structure: ['tableName' => ['column1' => true, 'column2' => true, ...]]
	 * This avoids repeated information_schema queries which add up quickly.
	 *
	 * @var array
	 */
	private array $columnCache = [];

	/**
	 * Distributed cache instance for facet labels
	 *
	 * @var ICache|null
	 */
	private ?ICache $distLabelCache = null;

	/**
	 * Whether the distributed label cache has been opened (or its opening tried).
	 *
	 * @var boolean
	 */
	private bool $labelCacheOpened = false;

	/**
	 * Whether lazy dependencies have been resolved.
	 *
	 * @var boolean
	 */
	private bool $lazyResolved = false;

	/**
	 * The object name cache, resolved from the container on first use.
	 *
	 * @var \OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface|null
	 */
	private ?\OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface $cacheHandler = null;

	/**
	 * The cache factory, resolved from the container on first use.
	 *
	 * @var ICacheFactory|null
	 */
	private ?ICacheFactory $cacheFactory = null;

	/**
	 * The table prefix, read from the system config on first use.
	 *
	 * @var string|null
	 */
	private ?string $tablePrefix = null;

	/**
	 * Constructor for MagicFacetHandler
	 *
	 * The object name cache, the cache factory and the system config are
	 * resolved from the container on first use, which keeps MagicMapper →
	 * CacheHandler → RegisterMapper → MagicMapper from becoming a cycle.
	 *
	 * @param IDBConnection           $db            Database connection for queries
	 * @param LoggerInterface         $logger        Logger for debugging and error reporting
	 * @param MagicSearchHandler|null $searchHandler Search handler for shared query building
	 * @param ContainerInterface|null $container     Container for lazy resolution
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly ?MagicSearchHandler $searchHandler = null,
		private readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()

	/**
	 * The distributed facet label cache, opened on first use.
	 *
	 * @return ICache|null The cache, or null when there is none.
	 */
	private function labelCache(): ?ICache {
		if ($this->labelCacheOpened === false && $this->cacheFactory !== null) {
			$this->labelCacheOpened = true;
			$this->distLabelCache = $this->createLabelCache(cacheFactory: $this->cacheFactory);
		}

		return $this->distLabelCache;
	}//end labelCache()

	/**
	 * Create the distributed facet label cache; a failure is logged.
	 *
	 * @param ICacheFactory $cacheFactory The cache factory.
	 *
	 * @return ICache|null The cache, or null when it could not be created.
	 */
	private function createLabelCache(ICacheFactory $cacheFactory): ?ICache {
		try {
			return $cacheFactory->createDistributed('openregister_facet_labels');
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] Failed to create distributed facet label cache: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end createLabelCache()

	/**
	 * Get the configured database table prefix.
	 *
	 * Uses the injected Nextcloud config so raw-SQL paths work on installs
	 * with a custom `dbtableprefix`, falling back to `oc_` when config is
	 * unavailable (BUG-DB-3).
	 *
	 * @return string The configured table prefix (e.g. `oc_`).
	 */
	private function getTablePrefix(): string {
		if ($this->tablePrefix === null) {
			$this->tablePrefix = $this->readTablePrefix();
		}

		return $this->tablePrefix;
	}//end getTablePrefix()

	/**
	 * Read `dbtableprefix` from the system config in the container; `oc_` when there is none.
	 *
	 * @return string The configured table prefix.
	 */
	private function readTablePrefix(): string {
		if ($this->container === null || $this->container->has(IConfig::class) === false) {
			return 'oc_';
		}

		return (string)$this->container->get(IConfig::class)->getSystemValue('dbtableprefix', 'oc_');
	}//end readTablePrefix()

	/**
	 * Lazily resolve CacheHandler and ICacheFactory from container.
	 *
	 * Called on first use to avoid circular DI during app bootstrap:
	 * MagicMapper → CacheHandler → RegisterMapper → MagicMapper.
	 *
	 * @return void
	 */
	private function resolveLazyDependencies(): void {
		if ($this->lazyResolved === true || $this->container === null) {
			return;
		}

		$this->lazyResolved = true;
		$this->resolveNameCache();
		$this->resolveCacheFactory();
	}//end resolveLazyDependencies()

	/**
	 * Lazily resolve the object name cache from the container; a failure is logged.
	 *
	 * @return void
	 */
	private function resolveNameCache(): void {
		try {
			$this->cacheHandler = $this->container->get(\OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface::class);
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[MagicFacetHandler] CacheHandler not available: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end resolveNameCache()

	/**
	 * Lazily resolve the cache factory and the distributed label cache; a failure is logged.
	 *
	 * @return void
	 */
	private function resolveCacheFactory(): void {
		try {
			$cacheFactory = $this->container->get(ICacheFactory::class);
			$this->cacheFactory = $cacheFactory;
			$this->labelCacheOpened = true;
			$this->distLabelCache = $cacheFactory->createDistributed('openregister_facet_labels');
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[MagicFacetHandler] ICacheFactory not available: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end resolveLazyDependencies()

	/**
	 * Get simple facets for a magic mapper table.
	 *
	 * This method provides faceting capabilities for dynamically created
	 * schema-based tables, optimized for column-based storage.
	 *
	 * @param string $tableName The magic mapper table name (without oc_ prefix).
	 * @param array $query The search query array containing filters and facet configuration.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 *
	 * @return array Facet results with buckets.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	public function getSimpleFacets(string $tableName, array $query, Register $register, Schema $schema): array {
		$table = new TermsFacetTable(tableName: $tableName, baseQuery: $this->withoutFacets(query: $query), register: $register, schema: $schema);
		return $this->tableFacets(table: $table, facetConfig: $query['_facets'] ?? []);
	}//end getSimpleFacets()

	/**
	 * The facets of one magic table, with timing metadata.
	 *
	 * @param TermsFacetTable $table       The table and its filters, without the facet configuration.
	 * @param mixed           $facetConfig The facet configuration of the query.
	 *
	 * @return array Facet results with buckets; empty when no facet is asked for.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function tableFacets(TermsFacetTable $table, mixed $facetConfig): array {
		$startTime = microtime(true);
		if (empty($facetConfig) === true) {
			return [];
		}

		$run = new FacetResults(startTime: $startTime, config: $this->normaliseFacetConfig(facetConfig: $facetConfig, schema: $table->schema));
		$this->addTableSelfFacets(table: $table, run: $run);
		$this->addTablePropertyFacets(table: $table, run: $run);

		// Add timing metadata to facets for performance debugging.
		return $run->withMetrics(metrics: [], labels: $this->cacheStats);
	}//end tableFacets()

	/**
	 * The base query of a facet request: the query without its facet configuration.
	 *
	 * @param array $query The search query.
	 *
	 * @return array The query without '_facets'.
	 */
	private function withoutFacets(array $query): array {
		unset($query['_facets']);
		return $query;
	}//end withoutFacets()

	/**
	 * Compute the metadata (@self) facets of one table.
	 *
	 * @param TermsFacetTable $table The table and filters, without a field.
	 * @param FacetResults    $run   The facet configuration and the results so far.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function addTableSelfFacets(TermsFacetTable $table, FacetResults $run): void {
		$run->addSelfFacets(
			compute: fn (int|string $field, mixed $config): ?array => $this->computeTableFacet(
				facet: $table->forMetadataField(field: self::METADATA_PREFIX . $field),
				config: $config
			)
		);
	}//end addTableSelfFacets()

	/**
	 * Compute the object field facets of one table that the caller may read.
	 *
	 * @param TermsFacetTable $table The table and filters, without a field.
	 * @param FacetResults    $run   The facet configuration and the results so far.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function addTablePropertyFacets(TermsFacetTable $table, FacetResults $run): void {
		foreach ($run->config as $field => $config) {
			// 🔴 AN EXPLICITLY REQUESTED FACET OBEYS THE READ RULE TOO. The
			// guard in expandFacetConfig() covers only the auto-discovered
			// `facetable` loop; a caller who names the property in
			// `_facets[<prop>][type]=terms` would otherwise get the column's
			// distinct values back.
			if ($field === '@self' || $this->callerMayFacet(schema: $table->schema, property: (string)$field) === false) {
				continue;
			}

			$facetStart = microtime(true);
			// Sanitize field name to match database column (camelCase -> snake_case).
			$facet = $this->computeTableFacet(facet: $table->forPropertyField(field: $this->sanitizeColumnName(name: $field)), config: $config);
			$run->putField(field: $field, computed: ['facet' => $facet, 'config' => $config, 'start' => $facetStart]);
		}
	}//end addTablePropertyFacets()

	/**
	 * Expand a facet configuration given as a string or a list of names.
	 *
	 * `_facets=extend` arrives as a string, `_facets[]=a&_facets[]=b` as a list;
	 * both expand to per-field configuration from the schema.
	 *
	 * @param mixed  $facetConfig The facet configuration from the query.
	 * @param Schema $schema      The schema context.
	 *
	 * @return array The facet configuration keyed by field.
	 */
	private function normaliseFacetConfig(mixed $facetConfig, Schema $schema): array {
		if (is_string($facetConfig) === true) {
			$facetConfig = $this->expandFacetConfig(facetConfig: $facetConfig, schema: $schema);
		}

		if (is_array($facetConfig) === true && array_is_list($facetConfig) === true) {
			$facetConfig = $this->expandFacetConfig(
				facetConfig: implode(separator: ',', array: $facetConfig),
				schema: $schema
			);
		}

		return $facetConfig;
	}//end normaliseFacetConfig()

	/**
	 * Compute one single-table facet of the configured type.
	 *
	 * @param TableTermsFacet $facet  The table, column and filters of the facet.
	 * @param mixed           $config The facet configuration (type, interval).
	 *
	 * @return array|null The facet, or null for a type that is not supported.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function computeTableFacet(TableTermsFacet $facet, mixed $config): ?array {
		$type = $config['type'] ?? 'terms';
		if ($type === 'terms') {
			return $this->getTermsFacet(facet: $facet);
		}

		if ($type !== 'date_histogram') {
			return null;
		}

		return $this->getDateHistogramFacet(facet: $facet, interval: $config['interval'] ?? 'month');
	}//end computeTableFacet()

	/**
	 * Get facets using UNION ALL across multiple tables for better performance.
	 *
	 * This method executes ONE query per facet field using UNION ALL to combine
	 * results from multiple tables, instead of running separate queries per table
	 * sequentially. Benchmarks show 2-2.5x speedup for large datasets.
	 *
	 * @param array $tableConfigs Array of ['tableName' => string, 'register' => Register, 'schema' => Schema].
	 * @param array $query The search query with filters and facet config.
	 *
	 * @return array Merged facet results across all tables.
	 *
	 * @throws \OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException When the query carries `_related` or `_referenced`.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	public function getSimpleFacetsUnion(array $tableConfigs, array $query): array {
		$startTime = microtime(true);

		if (empty($tableConfigs) === true) {
			return [];
		}

		$facetConfig = $this->unionFacetConfig(tableConfigs: $tableConfigs, query: $query);
		if ($facetConfig === null) {
			return [];
		}

		$run   = new FacetResults(startTime: $startTime, config: $facetConfig);
		$union = new TermsFacetUnion(tableConfigs: $tableConfigs, field: '', baseQuery: $this->withoutFacets(query: $query));

		$this->addUnionPropertyFacets(union: $union, run: $run);
		$this->addUnionSelfFacets(union: $union, run: $run);

		// Add timing metadata to facets for performance debugging.
		return $run->withMetrics(metrics: ['table_count' => count($tableConfigs)], labels: $this->cacheStats);
	}//end getSimpleFacetsUnion()

	/**
	 * The facet configuration of a union, after refusing filters it cannot honour.
	 *
	 * @param array $tableConfigs The tables of the union.
	 * @param array $query        The search query with filters and facet config.
	 *
	 * @return array|null The facet configuration keyed by field, or null when none was asked for.
	 *
	 * @throws \OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException When the query carries `_related` or `_referenced`.
	 */
	private function unionFacetConfig(array $tableConfigs, array $query): ?array {
		// A `_related`/`_referenced` filter cannot be honoured in these raw-SQL
		// arms. Refuse HERE, before the per-facet queries, whose own catch blocks
		// would turn the refusal into empty buckets with only a log line.
		$this->searchHandler?->refuseRelatedRowFilters(query: $query);

		$facetConfig = $query['_facets'] ?? [];
		if (empty($facetConfig) === true) {
			return null;
		}

		return $this->normaliseUnionFacetConfig(facetConfig: $facetConfig, tableConfigs: $tableConfigs);
	}//end unionFacetConfig()

	/**
	 * Compute the object field facets of a union that the caller may read on every table.
	 *
	 * @param TermsFacetUnion $union The tables and filters, without a field.
	 * @param FacetResults    $run   The facet configuration and the results so far.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function addUnionPropertyFacets(TermsFacetUnion $union, FacetResults $run): void {
		foreach ($run->config as $field => $config) {
			// Same read rule as the single-table path: a facet across several
			// schemas is omitted when ANY of them withholds the property from
			// this caller, because the union would hand back that schema's
			// values among the others.
			if ($field === '@self'
				|| $this->callerMayFacetOnEveryTable(tableConfigs: $union->tableConfigs, property: (string)$field) === false
			) {
				continue;
			}

			$facetStart = microtime(true);
			$facet      = $this->computeUnionFacet(
				facet: new TermsFacetUnion(tableConfigs: $union->tableConfigs, field: $this->sanitizeColumnName(name: $field), baseQuery: $union->baseQuery),
				config: $config
			);
			$run->putField(field: $field, computed: ['facet' => $facet, 'config' => $config, 'start' => $facetStart]);
		}
	}//end addUnionPropertyFacets()

	/**
	 * Compute the metadata (@self) facets of a union.
	 *
	 * @param TermsFacetUnion $union The tables and filters, without a field.
	 * @param FacetResults    $run   The facet configuration and the results so far.
	 *
	 * @return void
	 */
	private function addUnionSelfFacets(TermsFacetUnion $union, FacetResults $run): void {
		$run->addSelfFacets(
			compute: fn (int|string $field, mixed $config): ?array => $this->computeUnionFacet(
				facet: new TermsFacetUnion(
					tableConfigs: $union->tableConfigs,
					field: self::METADATA_PREFIX . $field,
					baseQuery: $union->baseQuery,
					isMetadata: true
				),
				config: $config
			)
		);
	}//end addUnionSelfFacets()

	/**
	 * Expand a union facet configuration given as a string or a list of names.
	 *
	 * The expansion merges the facetable fields of ALL schemas, not just the first.
	 *
	 * @param mixed $facetConfig  The facet configuration from the query.
	 * @param array $tableConfigs The tables of the union.
	 *
	 * @return array The facet configuration keyed by field.
	 */
	private function normaliseUnionFacetConfig(mixed $facetConfig, array $tableConfigs): array {
		if (is_string($facetConfig) === true) {
			$facetConfig = $this->expandFacetConfigFromAllSchemas(facetConfigString: $facetConfig, tableConfigs: $tableConfigs);
		}

		if (is_array($facetConfig) === true && array_is_list($facetConfig) === true) {
			$facetConfig = $this->expandFacetConfigFromAllSchemas(
				facetConfigString: implode(separator: ',', array: $facetConfig),
				tableConfigs: $tableConfigs
			);
		}

		return $facetConfig;
	}//end normaliseUnionFacetConfig()

	/**
	 * Compute one union facet of the configured type.
	 *
	 * A property facet runs only over the tables that have its column; a metadata
	 * facet runs over every table.
	 *
	 * @param TermsFacetUnion $facet  The tables, column and filters of the facet.
	 * @param mixed           $config The facet configuration (type, interval).
	 *
	 * @return array|null The facet, or null for a type that is not supported.
	 */
	private function computeUnionFacet(TermsFacetUnion $facet, mixed $config): ?array {
		$type = $config['type'] ?? 'terms';
		if (in_array($type, ['terms', 'date_histogram'], true) === false) {
			return null;
		}

		if ($facet->isMetadata === false) {
			$facet = new TermsFacetUnion(tableConfigs: $this->tablesWithColumn(facet: $facet), field: $facet->field, baseQuery: $facet->baseQuery);
		}

		if ($type === 'date_histogram') {
			return $this->unionDateHistogram(facet: $facet, config: $config);
		}

		return $this->unionTerms(facet: $facet);
	}//end computeUnionFacet()

	/**
	 * The date histogram of a union facet.
	 *
	 * @param TermsFacetUnion $facet  The tables, column and filters of the facet.
	 * @param mixed           $config The facet configuration (interval).
	 *
	 * @return array The date histogram facet.
	 */
	private function unionDateHistogram(TermsFacetUnion $facet, mixed $config): array {
		return $this->getDateHistogramFacetUnion(
			histogram: new DateHistogramUnion(union: $facet, interval: $config['interval'] ?? 'month')
		);
	}//end unionDateHistogram()

	/**
	 * The terms facet of a union facet; without tables, a facet without buckets or missing count.
	 *
	 * @param TermsFacetUnion $facet The tables, column and filters of the facet.
	 *
	 * @return array The terms facet.
	 */
	private function unionTerms(TermsFacetUnion $facet): array {
		if (empty($facet->tableConfigs) === true) {
			return ['type' => 'terms', 'buckets' => []];
		}

		return $this->getTermsFacetUnion(facet: $facet);
	}//end unionTerms()

	/**
	 * The tables of a union facet that have its column.
	 *
	 * @param TermsFacetUnion $facet The union facet.
	 *
	 * @return array The table configurations with the column.
	 */
	private function tablesWithColumn(TermsFacetUnion $facet): array {
		$tables = [];
		foreach ($facet->tableConfigs as $tableConfig) {
			if ($this->columnExists(tableName: $tableConfig['tableName'], columnName: $facet->field) === true) {
				$tables[] = $tableConfig;
			}
		}

		return $tables;
	}//end tablesWithColumn()

	/**
	 * Get terms facet using UNION ALL across multiple tables.
	 *
	 * This method uses a simple GROUP BY approach and then post-processes
	 * array values in PHP. This is more reliable than trying to detect
	 * array fields at SQL level and use jsonb_array_elements_text().
	 *
	 * @param TermsFacetUnion $facet The tables, column and filters of the facet.
	 *
	 * @return array Facet result with merged buckets.
	 */
	private function getTermsFacetUnion(TermsFacetUnion $facet): array {
		$sql = $this->termsUnionSql(facet: $facet);
		if ($sql === null) {
			return $this->emptyTermsFacet();
		}

		try {
			return $this->runTermsUnion(facet: $facet, sql: $sql);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] UNION facet query failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $facet->field, 'error' => $e->getMessage(), 'sql' => $sql]
			);
			return $this->emptyTermsFacet();
		}
	}//end getTermsFacetUnion()

	/**
	 * The UNION ALL statement of a terms facet over safe, existing columns.
	 *
	 * @param TermsFacetUnion $facet The tables, column and filters of the facet.
	 *
	 * @return string|null The statement, or null without tables, for an unsafe column or when no table takes part.
	 */
	private function termsUnionSql(TermsFacetUnion $facet): ?string {
		if (empty($facet->tableConfigs) === true || $this->isSafeTermsColumn(field: $facet->field) === false) {
			return null;
		}

		return $this->buildTermsUnionSql(facet: $facet);
	}//end termsUnionSql()

	/**
	 * Run a terms union and label its buckets.
	 *
	 * @param TermsFacetUnion $facet The tables, column and filters of the facet.
	 * @param string          $sql   The union statement.
	 *
	 * @return array Facet result with merged buckets.
	 */
	private function runTermsUnion(TermsFacetUnion $facet, string $sql): array {
		[$rawBuckets, $missingCount] = $this->fetchTermsRows(sql: $sql);

		// PHP POST-PROCESSING: Normalize array values.
		// This splits JSON array values like '["uuid1", "uuid2"]' into individual values
		// and merges their counts. Much more reliable than SQL-based array detection.
		$normalizedBuckets = $this->normalizeArrayFacetBuckets(rawBuckets: $rawBuckets);

		return [
			'type' => 'terms',
			'buckets' => $this->labelTermsBuckets(facet: $facet, normalizedBuckets: $normalizedBuckets),
			'missing' => ['results' => $missingCount],
		];
	}//end runTermsUnion()

	/**
	 * A terms facet without buckets.
	 *
	 * @return array The empty facet.
	 */
	private function emptyTermsFacet(): array {
		return ['type' => 'terms', 'buckets' => [], 'missing' => ['results' => 0]];
	}//end emptyTermsFacet()

	/**
	 * Whether a terms-facet column may be interpolated into raw SQL.
	 *
	 * The column is interpolated raw (CAST/WHERE/GROUP BY), and for @self
	 * metadata facets it is built straight from request facet keys, so it must
	 * be a plain identifier that equals its own sanitized form.
	 *
	 * @param string $field The column name.
	 *
	 * @return bool False, with a warning logged, for an unsafe name.
	 */
	private function isSafeTermsColumn(string $field): bool {
		$safeField = $this->sanitizeColumnName(name: $field);
		if ($safeField !== $field || preg_match('/^[a-z_][a-z0-9_]*$/', $field) !== 1) {
			$this->logger->warning(
				message: '[MagicFacetHandler] Rejected unsafe terms-facet field name',
				context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field]
			);
			return false;
		}

		return true;
	}//end isSafeTermsColumn()

	/**
	 * The UNION ALL statement of a terms facet, grouped per value.
	 *
	 * Array values come back as JSON strings like '["uuid1", "uuid2"]' and are
	 * split in PHP afterwards. The NULL group is kept: it is the missing-value
	 * bucket, counted in the same query and access scope as every value bucket
	 * (ADR-009), and pinned first so the bucket cap can never truncate it.
	 *
	 * @param TermsFacetUnion $facet The tables, column and filters of the facet.
	 *
	 * @return string|null The statement, or null when no table takes part.
	 */
	private function buildTermsUnionSql(TermsFacetUnion $facet): ?string {
		$unionParts = $this->termsUnionParts(facet: $facet, castField: $this->castToText(field: $facet->field));
		if (empty($unionParts) === true) {
			return null;
		}

		$unionSql = implode("\nUNION ALL\n", $unionParts);
		return "SELECT facet_value, SUM(cnt) as doc_count FROM (\n" . $unionSql . "\n) combined"
			. ' GROUP BY facet_value'
			. ' ORDER BY CASE WHEN facet_value IS NULL THEN 0 ELSE 1 END ASC, doc_count DESC'
			. ' LIMIT ' . self::MAX_FACET_BUCKETS;
	}//end buildTermsUnionSql()

	/**
	 * A column cast to text: tables may hold the same column with different types.
	 *
	 * @param string $field The column name.
	 *
	 * @return string The cast expression of the platform.
	 */
	private function castToText(string $field): string {
		if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform === true) {
			return "{$field}::text";
		}

		return "CAST({$field} AS CHAR)";
	}//end castToText()

	/**
	 * The per-table parts of a terms union, grouped per value.
	 *
	 * @param TermsFacetUnion $facet     The tables, column and filters of the facet.
	 * @param string          $castField The column cast to text.
	 *
	 * @return array<int, string> One SELECT per table that takes part.
	 */
	private function termsUnionParts(TermsFacetUnion $facet, string $castField): array {
		$prefix     = $this->getTablePrefix();
		$unionParts = [];
		foreach ($facet->tableConfigs as $tableConfig) {
			$conditions = $this->termsTableConditions(facet: $facet, tableConfig: $tableConfig);
			if ($conditions !== null) {
				$unionParts[] = "SELECT {$castField} as facet_value, COUNT(*) as cnt FROM {$prefix}{$tableConfig['tableName']} WHERE 1=1"
					. $conditions . " GROUP BY {$castField}";
			}
		}

		return $unionParts;
	}//end termsUnionParts()

	/**
	 * The filter conditions one table adds to its part of a terms union.
	 *
	 * @param TermsFacetUnion $facet       The tables, column and filters of the facet.
	 * @param array           $tableConfig One entry of the facet's table configurations.
	 *
	 * @return string|null The conditions as " AND ..." text, or null when the
	 *                     table lacks the column or its filters rule it out.
	 */
	private function termsTableConditions(TermsFacetUnion $facet, array $tableConfig): ?string {
		if ($this->columnExists(tableName: $tableConfig['tableName'], columnName: $facet->field) === false) {
			return null;
		}

		if ($this->searchHandler === null) {
			return '';
		}

		return $this->tableWhereSql(baseQuery: $facet->baseQuery, tableConfig: $tableConfig);
	}//end termsTableConditions()

	/**
	 * The search handler's filter conditions of one union table, as " AND ..." text.
	 *
	 * The conditions come from MagicSearchHandler, the single source of truth
	 * for filters, including the RBAC and organisation scope.
	 *
	 * @param array $baseQuery   The search filters, without the facet configuration.
	 * @param array $tableConfig One table configuration, with a schema.
	 *
	 * @return string|null The conditions, or null when the filters rule the table out.
	 */
	private function tableWhereSql(array $baseQuery, array $tableConfig): ?string {
		$whereConditions = $this->searchHandler->buildWhereConditionsSql(
			query: $baseQuery,
			schema: $tableConfig['schema'],
			registerId: ($tableConfig['register'] ?? null)?->getId(),
			tableName: $tableConfig['tableName']
		);

		return $this->conditionsSql(whereConditions: $whereConditions);
	}//end tableWhereSql()

	/**
	 * Filter conditions as " AND ..." text.
	 *
	 * @param array<int, string> $whereConditions The conditions.
	 *
	 * @return string|null The text, or null when a condition is '1=0'.
	 */
	private function conditionsSql(array $whereConditions): ?string {
		$conditions = '';
		foreach ($whereConditions as $condition) {
			// '1=0' means a filter column does not exist on this schema.
			if ($condition === '1=0') {
				return null;
			}

			$conditions .= " AND {$condition}";
		}

		return $conditions;
	}//end conditionsSql()

	/**
	 * Run a terms union and split off the NULL group.
	 *
	 * @param string $sql The union statement.
	 *
	 * @return array{0: array<int, array{key: mixed, count: int}>, 1: int} The value buckets and the missing count.
	 */
	private function fetchTermsRows(string $sql): array {
		$stmt = $this->db->prepare($sql);
		$stmt->execute();

		return $this->splitTermsRows(rows: $stmt);
	}//end fetchTermsRows()

	/**
	 * Read facet rows and split off the NULL group.
	 *
	 * @param IResult|IPreparedStatement $rows The executed facet query.
	 *
	 * @return array{0: array<int, array{key: mixed, count: int}>, 1: int} The value buckets and the missing count.
	 */
	private function splitTermsRows(IResult|IPreparedStatement $rows): array {
		$rawBuckets = [];
		$missingCount = 0;
		while (($row = $rows->fetch()) !== false) {
			if ($row['facet_value'] === null) {
				$missingCount = (int)$row['doc_count'];
				continue;
			}

			$rawBuckets[] = [
				'key' => $row['facet_value'],
				'count' => (int)$row['doc_count'],
			];
		}

		return [$rawBuckets, $missingCount];
	}//end splitTermsRows()

	/**
	 * The labelled buckets of a terms union.
	 *
	 * @param TermsFacetUnion $facet             The tables, column and filters of the facet.
	 * @param array           $normalizedBuckets Buckets of ['key' => value, 'count' => int].
	 *
	 * @return array<int, array{key: mixed, results: int, label: string}> The buckets.
	 */
	private function labelTermsBuckets(TermsFacetUnion $facet, array $normalizedBuckets): array {
		$labelMap = $this->termsUnionLabelMap(facet: $facet, normalizedBuckets: $normalizedBuckets);

		// For metadata facets, use getFieldLabel to resolve IDs to names.
		return $this->labelledBuckets(
			buckets: $normalizedBuckets,
			labelOf: fn (mixed $key): string => match ($facet->isMetadata) {
				true => $this->getFieldLabel(field: $facet->field, value: $key),
				default => $labelMap[$key] ?? (string)$key,
			}
		);
	}//end labelTermsBuckets()

	/**
	 * Object names for the UUID keys of a terms union, looked up in one batch.
	 *
	 * Runs after normalization, so only individual UUIDs are resolved. Metadata
	 * facets and columns no table holds object references in get none.
	 *
	 * @param TermsFacetUnion $facet             The tables, column and filters of the facet.
	 * @param array           $normalizedBuckets Buckets of ['key' => value, 'count' => int].
	 *
	 * @return array<string, string> Labels keyed by UUID.
	 */
	private function termsUnionLabelMap(TermsFacetUnion $facet, array $normalizedBuckets): array {
		$uuidsToResolve = $this->uuidKeys(buckets: $normalizedBuckets);
		if ($this->wantsUnionLabels(facet: $facet, uuids: $uuidsToResolve) === false) {
			return [];
		}

		$tableConfigs = $facet->tableConfigs;
		$firstConfig = reset($tableConfigs);
		return $this->batchResolveUuidLabels(
			lookup: new FacetLabelLookup(uuids: $uuidsToResolve, field: $facet->field),
			table: new RegisterSchema(register: $firstConfig['register'], schema: $firstConfig['schema'])
		);
	}//end termsUnionLabelMap()

	/**
	 * Whether the UUID keys of a terms union are looked up as object names.
	 *
	 * @param TermsFacetUnion    $facet The tables, column and filters of the facet.
	 * @param array<int, string> $uuids The UUID keys.
	 *
	 * @return bool False without keys, for a metadata facet, or when no table holds object references.
	 */
	private function wantsUnionLabels(TermsFacetUnion $facet, array $uuids): bool {
		return empty($uuids) === false && $facet->isMetadata === false
			&& $this->anyHoldsObjectReferences(tableConfigs: $facet->tableConfigs, column: $facet->field) === true;
	}//end wantsUnionLabels()

	/**
	 * Normalize facet buckets by splitting JSON array values into individual values.
	 *
	 * This is the PHP-based approach to handling array facets. Instead of complex
	 * SQL with jsonb_array_elements_text(), we:
	 * 1. Get raw facet values (arrays come as JSON strings like '["uuid1", "uuid2"]')
	 * 2. Detect array values by checking if they start with '['
	 * 3. Decode arrays and distribute counts to individual values
	 * 4. Merge counts for values that appear both individually and in arrays
	 *
	 * This approach is more reliable because:
	 * - No need for complex array field detection at SQL level
	 * - Works regardless of how the schema defines the field
	 * - Clear, testable PHP logic
	 *
	 * @param array $rawBuckets Array of ['key' => value, 'count' => int] from database.
	 *
	 * @return array Normalized buckets with array values split into individuals.
	 */
	private function normalizeArrayFacetBuckets(array $rawBuckets): array {
		// Map to accumulate counts: value => count.
		$valueCounts = [];
		foreach ($rawBuckets as $bucket) {
			foreach ($this->bucketKeyValues(key: $bucket['key']) as $value) {
				$valueCounts[$value] = ($valueCounts[$value] ?? 0) + $bucket['count'];
			}
		}

		return $this->countedBuckets(valueCounts: $valueCounts);
	}//end normalizeArrayFacetBuckets()

	/**
	 * The facet values one raw bucket key counts for.
	 *
	 * Null and empty keys count for nothing, a JSON array counts for each of its
	 * non-empty elements and any other key counts for its cleaned value.
	 *
	 * @param mixed $key The raw bucket key.
	 *
	 * @return array<int, string> The values the bucket's count goes to.
	 */
	private function bucketKeyValues(mixed $key): array {
		if ($key === null || $key === '' || $key === 'null') {
			return [];
		}

		$elements = $this->jsonArrayKey(key: $key);
		if ($elements !== null) {
			return $this->nonEmptyElements(elements: $elements);
		}

		// Clean up JSON-encoded single values (e.g., "\"value\"" -> "value").
		return [(string)$this->cleanJsonValue(value: $key)];
	}//end bucketKeyValues()

	/**
	 * The decoded elements of a bucket key that is a JSON array.
	 *
	 * @param mixed $key The raw bucket key.
	 *
	 * @return array|null The decoded array, or null when the key is no JSON array.
	 */
	private function jsonArrayKey(mixed $key): ?array {
		if ($this->looksLikeJsonArray(key: $key) === false) {
			return null;
		}

		$decoded = json_decode($key, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end jsonArrayKey()

	/**
	 * Whether a bucket key is a string that opens with '['.
	 *
	 * @param mixed $key The raw bucket key.
	 *
	 * @return bool Whether the key may be a JSON array.
	 */
	private function looksLikeJsonArray(mixed $key): bool {
		return is_string($key) === true && str_starts_with(trim($key), '[') === true;
	}//end looksLikeJsonArray()

	/**
	 * The elements of a decoded JSON array that are neither null nor empty, as strings.
	 *
	 * @param array $elements The decoded elements.
	 *
	 * @return array<int, string> The non-empty elements.
	 */
	private function nonEmptyElements(array $elements): array {
		$values = [];
		foreach ($elements as $element) {
			if ($element !== null && $element !== '') {
				$values[] = (string)$element;
			}
		}

		return $values;
	}//end nonEmptyElements()

	/**
	 * Buckets for accumulated value counts, highest count first and capped.
	 *
	 * @param array<array-key, int> $valueCounts Counts by value.
	 *
	 * @return array<int, array{key: array-key, count: int}> The buckets.
	 */
	private function countedBuckets(array $valueCounts): array {
		$buckets = [];
		foreach ($valueCounts as $key => $count) {
			$buckets[] = [
				'key' => $key,
				'count' => $count,
			];
		}

		// Sort by count descending (highest first).
		usort($buckets, fn ($a, $b) => $b['count'] <=> $a['count']);

		return array_slice($buckets, 0, self::MAX_FACET_BUCKETS);
	}//end countedBuckets()

	/**
	 * Get date histogram facet using UNION ALL across multiple tables.
	 *
	 * @param DateHistogramUnion $histogram The tables, column, interval (day, week, month, year) and filters.
	 *
	 * @return array Facet result with merged buckets.
	 */
	private function getDateHistogramFacetUnion(DateHistogramUnion $histogram): array {
		if (empty($histogram->union->tableConfigs) === true) {
			return $this->emptyDateHistogram(interval: $histogram->interval);
		}

		$unionParts = $this->dateUnionParts(histogram: $histogram);
		if (empty($unionParts) === true) {
			return $this->emptyDateHistogram(interval: $histogram->interval);
		}

		$unionSql = implode("\nUNION ALL\n", $unionParts);
		$sql      = "SELECT date_key, SUM(cnt) as doc_count FROM (\n{$unionSql}\n) combined GROUP BY date_key ORDER BY date_key ASC";

		return $this->runDateUnion(histogram: $histogram, sql: $sql);
	}//end getDateHistogramFacetUnion()

	/**
	 * The per-table parts of a date histogram union, grouped per date key.
	 *
	 * @param DateHistogramUnion $histogram The tables, column, interval and filters.
	 *
	 * @return array<int, string> One SELECT per table that takes part.
	 */
	private function dateUnionParts(DateHistogramUnion $histogram): array {
		$unionParts = [];
		foreach ($histogram->union->tableConfigs as $tableConfig) {
			$part = $this->dateUnionPart(histogram: $histogram, tableConfig: $tableConfig);
			if ($part !== null) {
				$unionParts[] = $part;
			}
		}

		return $unionParts;
	}//end dateUnionParts()

	/**
	 * The part of one table in a date histogram union.
	 *
	 * @param DateHistogramUnion $histogram   The tables, column, interval and filters.
	 * @param array              $tableConfig One entry of the table configurations.
	 *
	 * @return string|null The SELECT, or null when the table lacks the column or its filters rule it out.
	 */
	private function dateUnionPart(DateHistogramUnion $histogram, array $tableConfig): ?string {
		$tableName = $tableConfig['tableName'];
		$field     = $histogram->union->field;
		if ($this->columnExists(tableName: $tableName, columnName: $field) === false) {
			return null;
		}

		$selectExpr = $this->buildDateKeyExpr(field: $field, interval: $histogram->interval);
		$fullTableName = $this->getTablePrefix() . $tableName;
		$subSql = "SELECT {$selectExpr} as date_key, COUNT(*) as cnt FROM {$fullTableName} WHERE {$field} IS NOT NULL";

		$conditions = $this->dateTableConditions(histogram: $histogram, tableConfig: $tableConfig);
		if ($conditions === null) {
			return null;
		}

		return $subSql . $conditions . ' GROUP BY date_key';
	}//end dateUnionPart()

	/**
	 * The filter conditions one table adds to its part of a date histogram union.
	 *
	 * Uses the shared method for all filter conditions (single source of truth);
	 * without a search handler or a schema the table is not filtered.
	 *
	 * @param DateHistogramUnion $histogram   The tables, column, interval and filters.
	 * @param array              $tableConfig One entry of the table configurations.
	 *
	 * @return string|null The conditions as " AND ..." text, or null when the filters rule the table out.
	 */
	private function dateTableConditions(DateHistogramUnion $histogram, array $tableConfig): ?string {
		if ($this->searchHandler === null || ($tableConfig['schema'] ?? null) === null) {
			return '';
		}

		return $this->tableWhereSql(baseQuery: $histogram->union->baseQuery, tableConfig: $tableConfig);
	}//end dateTableConditions()

	/**
	 * Run a date histogram union; a failure is logged and yields no buckets.
	 *
	 * @param DateHistogramUnion $histogram The tables, column, interval and filters.
	 * @param string             $sql       The union statement.
	 *
	 * @return array The date histogram facet.
	 */
	private function runDateUnion(DateHistogramUnion $histogram, string $sql): array {
		try {
			$stmt = $this->db->prepare($sql);
			$stmt->execute();

			return $this->dateHistogram(interval: $histogram->interval, buckets: $this->dateBuckets(rows: $stmt, interval: $histogram->interval));
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] UNION date histogram failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $histogram->union->field, 'error' => $e->getMessage()]
			);
			return $this->emptyDateHistogram(interval: $histogram->interval);
		}
	}//end runDateUnion()

	/**
	 * A date histogram facet.
	 *
	 * @param string $interval The histogram interval.
	 * @param array  $buckets  The buckets.
	 *
	 * @return array The facet.
	 */
	private function dateHistogram(string $interval, array $buckets): array {
		return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => $buckets];
	}//end dateHistogram()

	/**
	 * A date histogram facet without buckets.
	 *
	 * @param string $interval The histogram interval.
	 *
	 * @return array The facet.
	 */
	private function emptyDateHistogram(string $interval): array {
		return $this->dateHistogram(interval: $interval, buckets: []);
	}//end emptyDateHistogram()

	/**
	 * Read date histogram rows into buckets with their from/to dates.
	 *
	 * @param IResult|IPreparedStatement $rows     The executed histogram query.
	 * @param string                     $interval The histogram interval.
	 *
	 * @return array<int, array> The buckets.
	 */
	private function dateBuckets(IResult|IPreparedStatement $rows, string $interval): array {
		$buckets = [];
		while (($row = $rows->fetch()) !== false) {
			$buckets[] = $this->dateBucket(row: $row, interval: $interval);
		}

		return $buckets;
	}//end dateBuckets()

	/**
	 * One date histogram bucket with its from/to dates, when the key has them.
	 *
	 * @param array  $row      A row of date_key and doc_count.
	 * @param string $interval The histogram interval.
	 *
	 * @return array The bucket.
	 */
	private function dateBucket(array $row, string $interval): array {
		$bucket = [
			'key' => $row['date_key'],
			'results' => (int)$row['doc_count'],
		];

		$bounds = $this->getDateBoundsForBucket(dateKey: $row['date_key'], interval: $interval);
		if ($bounds !== null) {
			$bucket['from'] = $bounds['from'];
			$bucket['to'] = $bounds['to'];
		}

		return $bucket;
	}//end dateBucket()

	/**
	 * Expand facet config string to full configuration.
	 *
	 * Handles special values like "extend" which should return all facetable fields.
	 *
	 * @param string $facetConfig The facet config string (e.g., "extend").
	 * @param Schema $schema The schema for field discovery.
	 *
	 * @return array Expanded facet configuration.
	 */
	private function expandFacetConfig(string $facetConfig, Schema $schema): array {
		if ($facetConfig === 'extend') {
			return $this->extendFacetConfig(schema: $schema);
		}

		// Treat as comma-separated field names.
		return $this->namedFacetConfig(facetConfig: $facetConfig);
	}//end expandFacetConfig()

	/**
	 * The facet configuration of `_facets=extend`: the schema facet and every
	 * facetable property the caller may read.
	 *
	 * @param Schema $schema The schema for field discovery.
	 *
	 * @return array Expanded facet configuration.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function extendFacetConfig(Schema $schema): array {
		// Return all facetable metadata fields.
		// PERFORMANCE: Metadata facets are disabled by default for performance reasons.
		// Date histograms (@self.created, @self.updated) are particularly slow (~170-200ms each)
		// because they require grouping and date formatting across all tables.
		// The @self.register facet requires label resolution (~140ms).
		// These can be explicitly requested via _facets=@self.created,@self.updated,@self.register
		// if needed for specific use cases. Disabled for performance: register, organisation
		// (terms) and created, updated (date_histogram, month).
		$config = ['@self' => ['schema' => ['type' => 'terms']]];

		// RUNTIME COMPUTATION: Always compute facets from property-level `facetable: true` settings.
		// This is the single source of truth - no pre-computed facets needed.
		// Benefits:
		// - No sync issues between property settings and pre-computed facets
		// - Changes to facetable take effect immediately (after cache expires)
		// - Simpler mental model - just set facetable: true on properties
		// Performance: Comparable or better than pre-computed (~73ms vs ~97ms in benchmarks).
		$properties = $schema->getProperties() ?? [];
		foreach ($properties as $propertyKey => $property) {
			// 🔴 A FACET IS A READ OF THE COLUMN, SO IT OBEYS THE READ RULE.
			// This loop offered every `facetable` property to every caller
			// who could see the rows, and a facet over a governed column
			// hands back its DISTINCT VALUES. The rows were protected and
			// the value list was not: for a property scoped to one team,
			// everybody else could read the set of answers without ever
			// being allowed to read one.
			//
			// It is the quiet kind: the response looks like an ordinary
			// facet, and the property never appears in any object body, so
			// nothing on screen suggests a leak.
			if ($this->callerMayFacet(schema: $schema, property: (string)$propertyKey) === false) {
				continue;
			}

			$facetEntry = $this->propertyFacetEntry(property: $property);
			if ($facetEntry !== null) {
				$config[$propertyKey] = $facetEntry;
			}
		}//end foreach

		return $config;
	}//end extendFacetConfig()

	/**
	 * The facet configuration of a property marked facetable.
	 *
	 * @param mixed $property The property definition from the schema.
	 *
	 * @return array|null The facet entry, or null when the property is not facetable.
	 */
	private function propertyFacetEntry(mixed $property): ?array {
		// Check if property is marked as facetable (boolean true or config object).
		$facetable = $property['facetable'] ?? false;
		if ($this->isFacetable(facetable: $facetable) === false) {
			return null;
		}

		// Determine facet type based on property type and config.
		$facetEntry = [
			'type' => $this->determineFacetTypeFromProperty(property: $property),
			'title' => $property['title'] ?? null,
		];

		// Pass through options from facetable config.
		if (is_array($facetable) === true) {
			$facetEntry = $this->withFacetableOptions(facetEntry: $facetEntry, facetable: $facetable);
		}

		return $facetEntry;
	}//end propertyFacetEntry()

	/**
	 * Whether a property's facetable setting turns the facet on.
	 *
	 * @param mixed $facetable The facetable setting: true or a non-empty config object.
	 *
	 * @return bool Whether the property is facetable.
	 */
	private function isFacetable(mixed $facetable): bool {
		return $facetable === true || (is_array($facetable) === true && empty($facetable) === false);
	}//end isFacetable()

	/**
	 * A facet entry with the options and interval of a facetable config object.
	 *
	 * @param array $facetEntry The facet entry with its type and title.
	 * @param array $facetable  The facetable config object.
	 *
	 * @return array The facet entry.
	 */
	private function withFacetableOptions(array $facetEntry, array $facetable): array {
		if (isset($facetable['options']) === true) {
			$facetEntry['options'] = $facetable['options'];
		}

		if ($facetEntry['type'] === 'date_histogram') {
			$facetEntry['interval'] = $facetable['options']['interval'] ?? 'month';
		}

		return $facetEntry;
	}//end withFacetableOptions()

	/**
	 * The facet configuration of comma-separated field names.
	 *
	 * Known metadata fields become @self facets, any other name a terms facet.
	 *
	 * @param string $facetConfig The comma-separated field names.
	 *
	 * @return array The facet configuration.
	 */
	private function namedFacetConfig(string $facetConfig): array {
		$config = ['@self' => []];
		foreach (array_map('trim', explode(',', $facetConfig)) as $field) {
			if (in_array($field, ['register', 'schema', 'organisation', 'owner'], true) === true) {
				$config['@self'][$field] = ['type' => 'terms'];
				continue;
			}

			if (in_array($field, ['created', 'updated'], true) === true) {
				$config['@self'][$field] = ['type' => 'date_histogram', 'interval' => 'month'];
				continue;
			}

			$config[$field] = ['type' => 'terms'];
		}

		return $config;
	}//end namedFacetConfig()

	/**
	 * Expand facet config from ALL schemas in a multi-schema search.
	 *
	 * This method merges facet configurations from all schemas to ensure
	 * that facetable fields from every schema are included in the result.
	 * This fixes the bug where only the first schema's facets were used.
	 *
	 * @param string $facetConfigString The facet config string (e.g., "extend").
	 * @param array $tableConfigs Array of table configurations with schemas.
	 *
	 * @return array Merged facet configuration from all schemas.
	 */
	private function expandFacetConfigFromAllSchemas(string $facetConfigString, array $tableConfigs): array {
		$mergedConfig = [
			'@self' => [],
		];

		foreach ($tableConfigs as $tc) {
			$schema = $tc['schema'] ?? null;
			if ($schema !== null) {
				$schemaConfig = $this->expandFacetConfig(facetConfig: $facetConfigString, schema: $schema);
				$mergedConfig = $this->mergeSchemaFacetConfig(merged: $mergedConfig, schemaConfig: $schemaConfig);
			}
		}

		return $mergedConfig;
	}//end expandFacetConfigFromAllSchemas()

	/**
	 * Merge one schema's facet configuration into the configuration merged so far.
	 *
	 * Metadata (@self) facets and object field facets already present are kept;
	 * an object field facet without a title takes the title of a later schema.
	 *
	 * @param array $merged       The configuration merged so far.
	 * @param array $schemaConfig The facet configuration of one schema.
	 *
	 * @return array The merged configuration.
	 */
	private function mergeSchemaFacetConfig(array $merged, array $schemaConfig): array {
		$selfConfig = $schemaConfig['@self'] ?? null;
		if (is_array($selfConfig) === true) {
			$merged['@self'] += $selfConfig;
		}

		unset($schemaConfig['@self']);
		foreach ($schemaConfig as $field => $config) {
			$merged[$field] = $this->mergeFieldFacet(existing: $merged[$field] ?? null, config: $config);
		}

		return $merged;
	}//end mergeSchemaFacetConfig()

	/**
	 * Merge one schema's facet of an object field into the facet merged so far.
	 *
	 * @param array|null $existing The facet merged so far, null when there is none.
	 * @param array      $config   The schema's facet of the field.
	 *
	 * @return array The merged facet.
	 */
	private function mergeFieldFacet(?array $existing, array $config): array {
		if ($existing === null) {
			return $config;
		}

		// Use the title from a schema that has it.
		if (isset($config['title']) === true && isset($existing['title']) === false) {
			$existing['title'] = $config['title'];
		}

		return $existing;
	}//end mergeFieldFacet()

	/**
	 * Whether the caller may be offered a facet over this property.
	 *
	 * A facet groups a column and returns its distinct values with counts, which
	 * is a read of that column for everybody it is offered to. So the question
	 * is the read question, and it is answered by the ONE thing that already
	 * answers it: `PropertyRbacHandler`. Asking it here rather than
	 * reimplementing the rule is the whole point; a second evaluator of "may
	 * this person see this field" disagrees with the first within a week, and
	 * the wider one is the one that discloses.
	 *
	 * FAILS CLOSED. When the handler cannot be resolved the property is left
	 * out, because the alternative is offering a facet whose access nobody
	 * checked.
	 *
	 * @param Schema $schema   The schema the property belongs to.
	 * @param string $property The property name.
	 *
	 * @return bool Whether the facet may be offered.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	private function callerMayFacet(Schema $schema, string $property): bool {
		// An explicit-reveal value is shown only through the logged reveal; its
		// distinct values in a facet would list it unmasked, for anyone.
		if (in_array($property, ExplicitRevealMasks::explicitProperties(schema: $schema), true) === true) {
			return false;
		}

		if ($schema->hasPropertyAuthorization() === false) {
			// Nothing on this schema is governed at property level, so there is
			// no question to ask and no handler to resolve.
			return true;
		}

		if ($this->container === null) {
			return $this->omitWithoutReadRule(property: $property);
		}

		return $this->readRuleAdmits(schema: $schema, property: $property);
	}//end callerMayFacet()

	/**
	 * Omit a governed facet when there is no container to resolve the read rule; logged.
	 *
	 * @param string $property The property name.
	 *
	 * @return bool Always false: the facet is omitted.
	 */
	private function omitWithoutReadRule(string $property): bool {
		$this->logger->warning(
			message: '[MagicFacetHandler] No container to resolve the property read rule; omitting the facet',
			context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property]
		);
		return false;
	}//end omitWithoutReadRule()

	/**
	 * Ask PropertyRbacHandler whether the caller may read the property at all.
	 *
	 * FAILS CLOSED: a rule that cannot be resolved or throws omits the facet.
	 *
	 * @param Schema $schema   The schema the property belongs to.
	 * @param string $property The property name.
	 *
	 * @return bool Whether the read rule admits the caller.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function readRuleAdmits(Schema $schema, string $property): bool {
		try {
			$rbac = $this->container->get(PropertyRbacHandlerInterface::class);

			// The object is empty because a facet is not about one record: it
			// asks whether this property is readable AT ALL for this caller, not
			// whether it is readable on some particular row. A conditional rule
			// that depends on a record therefore does not admit the facet, which
			// is the safe direction.
			return $rbac->canReadProperty(property: new SchemaProperty(schema: $schema, name: $property), request: new PropertyAccessRequest(object: []));
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] Could not check the property read rule; omitting the facet',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end readRuleAdmits()

	/**
	 * Whether the caller may facet a property on every table of a union.
	 *
	 * @param array  $tableConfigs Entries of ['tableName' => string, 'register' => Register, 'schema' => Schema].
	 * @param string $property     The property being faceted.
	 *
	 * @return bool False when any table's schema withholds the property.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function callerMayFacetOnEveryTable(array $tableConfigs, string $property): bool {
		foreach ($tableConfigs as $tableConfig) {
			$schema = ($tableConfig['schema'] ?? null);
			if ($schema instanceof Schema && $this->callerMayFacet(schema: $schema, property: $property) === false) {
				return false;
			}
		}

		return true;
	}//end callerMayFacetOnEveryTable()

	/**
	 * Determine facet type based on property definition.
	 *
	 * @param array $property The property definition from the schema.
	 *
	 * @return string The appropriate facet type (terms, date_histogram, range).
	 */
	private function determineFacetTypeFromProperty(array $property): string {
		// Check explicit type override from facetable config object.
		$configType = $this->configuredFacetType(property: $property);
		if ($configType !== null) {
			return $configType;
		}

		// Date/datetime fields use date histogram.
		if (in_array($property['format'] ?? '', ['date', 'date-time', 'datetime'], true) === true) {
			return 'date_histogram';
		}

		return $this->numericFacetType(property: $property);
	}//end determineFacetTypeFromProperty()

	/**
	 * The facet type a property's facetable config object sets explicitly.
	 *
	 * @param array $property The property definition from the schema.
	 *
	 * @return string|null The configured type (terms, date_histogram, date_range), or null.
	 */
	private function configuredFacetType(array $property): ?string {
		$facetable = $property['facetable'] ?? false;
		if (is_array($facetable) === true
			&& in_array($facetable['type'] ?? null, ['terms', 'date_histogram', 'date_range'], true) === true
		) {
			return $facetable['type'];
		}

		return null;
	}//end configuredFacetType()

	/**
	 * The facet type of a property that sets none and is not a date.
	 *
	 * Numeric fields could use range, but terms is more common for faceting:
	 * range is used only when specifically configured through facet_type.
	 *
	 * @param array $property The property definition from the schema.
	 *
	 * @return string The configured facet_type of a numeric property, else terms.
	 */
	private function numericFacetType(array $property): string {
		$type = $property['type'] ?? 'string';
		if (($type === 'integer' || $type === 'number') && isset($property['facet_type']) === true) {
			return $property['facet_type'];
		}

		// Default to terms facet for categorical data.
		return 'terms';
	}//end numericFacetType()

	/**
	 * Get terms facet for a field in a magic mapper table.
	 *
	 * Returns unique values and their counts for categorical fields, cached per
	 * table, field, filters and metadata flag for the rest of the request.
	 *
	 * @param TableTermsFacet $facet The table, column and filters of the facet.
	 *
	 * @return array Facet result with type and buckets.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function getTermsFacet(TableTermsFacet $facet): array {
		$cacheKey = hash(algo: 'xxh128', data: json_encode(value: [$facet->tableName, $facet->field, $facet->baseQuery, $facet->isMetadata]));
		if (isset($this->facetCache[$cacheKey]) === false) {
			$this->facetCache[$cacheKey] = $this->computeTermsFacet(facet: $facet);
		}

		return $this->facetCache[$cacheKey];
	}//end getTermsFacet()

	/**
	 * A single-table terms facet; without the column, a facet without buckets.
	 *
	 * @param TableTermsFacet $facet The table, column and filters of the facet.
	 *
	 * @return array Facet result with type and buckets.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function computeTermsFacet(TableTermsFacet $facet): array {
		if ($this->columnExists(tableName: $facet->tableName, columnName: $facet->field) === false) {
			$this->logger->debug(
				message: '[MagicFacetHandler] Column does not exist for facet',
				context: ['file' => __FILE__, 'line' => __LINE__, 'tableName' => $facet->tableName, 'field' => $facet->field]
			);
			return $this->emptyTermsFacet();
		}

		return $this->countTableTerms(facet: $facet);
	}//end computeTermsFacet()

	/**
	 * Run a single-table terms facet and label its buckets.
	 *
	 * Array values are split in PHP afterwards. The NULL group is kept: it is
	 * the missing-value bucket, counted by the same query and inside the same
	 * access scope as every value bucket (ADR-009).
	 *
	 * @param TableTermsFacet $facet The table, column and filters of the facet.
	 *
	 * @return array Facet result with type, buckets and missing count.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function countTableTerms(TableTermsFacet $facet): array {
		[$rawBuckets, $missingCount] = $this->splitTermsRows(rows: $this->tableTermsQuery(facet: $facet)->executeQuery());

		// Split JSON array values into individual values and merge their counts.
		$normalizedBuckets = $this->normalizeArrayFacetBuckets(rawBuckets: $rawBuckets);

		return [
			'type' => 'terms',
			'buckets' => $this->labelTableTermsBuckets(facet: $facet, normalizedBuckets: $normalizedBuckets),
			'missing' => ['results' => $missingCount],
		];
	}//end countTableTerms()

	/**
	 * The grouped query of a single-table terms facet.
	 *
	 * With a search handler the facet groups the handler's filtered query, the
	 * single source of truth for filters; otherwise the query built here, with
	 * the base filters applied, is used.
	 *
	 * @param TableTermsFacet $facet The table, column and filters of the facet.
	 *
	 * @return IQueryBuilder The query, grouped per value.
	 */
	private function tableTermsQuery(TableTermsFacet $facet): IQueryBuilder {
		$queryBuilder = $this->unscopedTermsQuery(facet: $facet);
		if ($this->searchHandler === null) {
			return $queryBuilder;
		}

		return $this->scopedTermsQuery(facet: $facet);
	}//end tableTermsQuery()

	/**
	 * The grouped terms query built here, with the base filters applied.
	 *
	 * @param TableTermsFacet $facet The table, column and filters of the facet.
	 *
	 * @return IQueryBuilder The query, grouped per value.
	 */
	private function unscopedTermsQuery(TableTermsFacet $facet): IQueryBuilder {
		$field = $facet->field;
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->selectAlias($field, 'facet_value')
			->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
			->from($facet->tableName)
			->groupBy($field);
		$this->orderFacetGroups(queryBuilder: $queryBuilder, columnRef: $field);
		$this->applyBaseFilters(queryBuilder: $queryBuilder, facet: $facet);

		return $queryBuilder;
	}//end unscopedTermsQuery()

	/**
	 * The search handler's filtered query of the facet, grouped per value.
	 *
	 * @param TableTermsFacet $facet The table, column and filters of the facet.
	 *
	 * @return IQueryBuilder The query, grouped per value.
	 */
	private function scopedTermsQuery(TableTermsFacet $facet): IQueryBuilder {
		// 🔴 THE REGISTER IS PASSED SO `_related` NARROWS THE COUNTS TOO.
		// A facet count that ignores a filter the list honours is worse than
		// no count: the user filters cases down to the ones carrying a
		// property, and the facet beside the result still describes every
		// case in the register. Nothing looks broken, the numbers are just
		// answers to a different question.
		$queryBuilder = $this->searchHandler->buildFilteredQuery(
			query: $facet->baseQuery,
			schema: $facet->schema,
			tableName: $facet->tableName,
			registerId: $facet->register->getId()
		);
		$columnRef = "t.{$facet->field}";
		$queryBuilder->selectAlias($columnRef, 'facet_value')
			->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
			->groupBy($columnRef);
		$this->orderFacetGroups(queryBuilder: $queryBuilder, columnRef: $columnRef);

		return $queryBuilder;
	}//end scopedTermsQuery()

	/**
	 * The labelled buckets of a single-table terms facet.
	 *
	 * @param TableTermsFacet $facet             The table, column and filters of the facet.
	 * @param array           $normalizedBuckets Buckets of ['key' => value, 'count' => int].
	 *
	 * @return array<int, array{key: mixed, results: int, label: string}> The buckets.
	 */
	private function labelTableTermsBuckets(TableTermsFacet $facet, array $normalizedBuckets): array {
		$objectReferences = $facet->isMetadata === true
			|| $this->holdsObjectReferences(schema: $facet->schema, column: $facet->field) === true;
		if ($objectReferences === false) {
			return $this->labelledBuckets(buckets: $normalizedBuckets, labelOf: static fn (mixed $key): string => (string)$key);
		}

		return $this->referenceTermsBuckets(facet: $facet, normalizedBuckets: $normalizedBuckets);
	}//end labelTableTermsBuckets()

	/**
	 * The labelled buckets of a single-table terms facet whose values can name objects.
	 *
	 * The UUID keys of an object field are looked up in one batch first.
	 *
	 * @param TableTermsFacet $facet             The table, column and filters of the facet.
	 * @param array           $normalizedBuckets Buckets of ['key' => value, 'count' => int].
	 *
	 * @return array<int, array{key: mixed, results: int, label: string}> The buckets.
	 */
	private function referenceTermsBuckets(TableTermsFacet $facet, array $normalizedBuckets): array {
		$batchKeys = $this->tableBatchKeys(facet: $facet, normalizedBuckets: $normalizedBuckets);
		$labelMap  = $this->tableLabelMap(facet: $facet, batchKeys: $batchKeys);
		return $this->labelledBuckets(
			buckets: $normalizedBuckets,
			labelOf: function (mixed $key) use ($labelMap, $batchKeys, $facet): string {
				if (is_string($key) === true && isset($labelMap[$key]) === true) {
					return $labelMap[$key];
				}

				if (is_string($key) === true && isset($batchKeys[$key]) === true) {
					return $this->unnamedReferenceLabel(key: $key);
				}

				return $this->getFieldLabel(field: $facet->field, value: $key);
			}
		);
	}//end referenceTermsBuckets()

	/**
	 * The UUID keys of an object field facet, to look up in one batch; none for a metadata facet.
	 *
	 * @param TableTermsFacet $facet             The table, column and filters of the facet.
	 * @param array           $normalizedBuckets Buckets of ['key' => value, 'count' => int].
	 *
	 * @return array<string, int> The UUID keys, as array keys.
	 */
	private function tableBatchKeys(TableTermsFacet $facet, array $normalizedBuckets): array {
		if ($facet->isMetadata === true) {
			return [];
		}

		return array_flip($this->uuidKeys(buckets: $normalizedBuckets));
	}//end tableBatchKeys()

	/**
	 * Object names for the batch keys of a single-table terms facet.
	 *
	 * @param TableTermsFacet       $facet     The table, column and filters of the facet.
	 * @param array<string, int>    $batchKeys The UUID keys to look up, as array keys.
	 *
	 * @return array<string, string> Labels keyed by UUID.
	 */
	private function tableLabelMap(TableTermsFacet $facet, array $batchKeys): array {
		if (empty($batchKeys) === true) {
			return [];
		}

		return $this->batchResolveUuidLabels(
			lookup: new FacetLabelLookup(uuids: array_keys($batchKeys), field: $facet->field),
			table: new RegisterSchema(register: $facet->register, schema: $facet->schema)
		);
	}//end tableLabelMap()

	/**
	 * Buckets of key, count and label.
	 *
	 * @param array    $buckets Buckets of ['key' => value, 'count' => int].
	 * @param callable $labelOf Gives the label of a bucket key.
	 *
	 * @return array<int, array{key: mixed, results: int, label: string}> The buckets.
	 */
	private function labelledBuckets(array $buckets, callable $labelOf): array {
		$labelled = [];
		foreach ($buckets as $bucket) {
			$labelled[] = ['key' => $bucket['key'], 'results' => $bucket['count'], 'label' => $labelOf($bucket['key'])];
		}

		return $labelled;
	}//end labelledBuckets()

	/**
	 * The bucket keys that are UUIDs, in bucket order.
	 *
	 * @param array $buckets Buckets of ['key' => value, 'count' => int].
	 *
	 * @return array<int, string> The UUID keys.
	 */
	private function uuidKeys(array $buckets): array {
		$uuids = [];
		foreach ($buckets as $bucket) {
			$key = $bucket['key'];
			if (is_string($key) === true
				&& UuidFormat::isCanonical(value: $key) === true
			) {
				$uuids[] = $key;
			}
		}

		return $uuids;
	}//end uuidKeys()

	/**
	 * Whether the values of a faceted column can be OpenRegister object references.
	 *
	 * A property that declares a `referenceType` holds the id of an entity an
	 * integration manages (a Nextcloud group, a contact, a calendar), never an
	 * object, so its values are not looked up as object names. Such an id can
	 * still look like a UUID, and a name lookup for a UUID no object carries
	 * scans every magic table.
	 *
	 * @param Schema $schema The schema the column belongs to.
	 * @param string $column The column name as the facet queries it.
	 *
	 * @return bool False when the property declares a referenceType.
	 *
	 * @spec exclude facet label performance guard; object-reference labels unchanged, a UUID-shaped integration id is labelled in full
	 */
	private function holdsObjectReferences(Schema $schema, string $column): bool {
		foreach ($schema->getProperties() ?? [] as $name => $property) {
			if ($this->sanitizeColumnName(name: (string)$name) !== $column || is_array($property) === false) {
				continue;
			}

			$referenceType = $property['referenceType'] ?? null;
			return is_string($referenceType) === false || $referenceType === '';
		}

		return true;
	}//end holdsObjectReferences()

	/**
	 * Whether any table of a union facet can hold object references in a column.
	 *
	 * Two schemas in one union can share a column name with a different meaning,
	 * so the name lookup is skipped only when every schema declares a
	 * referenceType for it; one schema holding object references keeps it.
	 *
	 * @param array<int, array{schema: Schema}> $tableConfigs The union's table configurations.
	 * @param string                            $column       The column name as the facet queries it.
	 *
	 * @return bool False only when every schema declares a referenceType for the column.
	 *
	 * @spec exclude performance guard on facet label resolution, the multi-schema form of holdsObjectReferences()
	 */
	private function anyHoldsObjectReferences(array $tableConfigs, string $column): bool {
		foreach ($tableConfigs as $config) {
			if ($this->holdsObjectReferences(schema: $config['schema'], column: $column) === true) {
				return true;
			}
		}

		return false;
	}//end anyHoldsObjectReferences()

	/**
	 * The label of a key the batch lookup already asked about and could not name.
	 *
	 * Such a key is never looked up again on its own: a UUID the batch could not
	 * name (a dangling reference, or an object the caller may not read) would
	 * otherwise cost a scan of every magic table per bucket.
	 *
	 * @param string $key The bucket key.
	 *
	 * @return string The shortened key.
	 *
	 * @spec exclude performance-only refactor of the facet label loop; output is unchanged
	 */
	private function unnamedReferenceLabel(string $key): string {
		return substr($key, 0, 8) . '...';
	}//end unnamedReferenceLabel()

	/**
	 * Order facet groups so the NULL group is always the first row.
	 *
	 * Buckets are capped at MAX_FACET_BUCKETS. Ordering by count alone would let
	 * a small NULL group fall off the end of a wide facet, and a missing bucket
	 * that silently reads zero is worse than no missing bucket at all. Pinning
	 * it first costs the value buckets nothing: they keep their count order
	 * among themselves.
	 *
	 * @param IQueryBuilder $queryBuilder The query builder to order.
	 * @param string        $columnRef    The faceted column, qualified as the query uses it.
	 *
	 * @return void
	 */
	private function orderFacetGroups(IQueryBuilder $queryBuilder, string $columnRef): void {
		$queryBuilder->orderBy(
			$queryBuilder->createFunction("CASE WHEN {$columnRef} IS NULL THEN 0 ELSE 1 END"),
			'ASC'
		)
			->addOrderBy('doc_count', 'DESC')
			->setMaxResults(self::MAX_FACET_BUCKETS);
	}//end orderFacetGroups()

	/**
	 * Clean up JSON-encoded values.
	 *
	 * Removes JSON encoding artifacts from single values.
	 *
	 * @param mixed $value The value to clean.
	 *
	 * @return mixed The cleaned value.
	 */
	private function cleanJsonValue(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		// Invalid JSON decodes to null, which is neither scalar nor array.
		$decoded = json_decode($value, true);
		return match (true) {
			is_scalar($decoded) === true => $decoded,
			is_array($decoded) === true && count($decoded) === 1 => reset($decoded),
			default => $value,
		};
	}//end cleanJsonValue()

	/**
	 * Get date histogram facet for a field.
	 *
	 * Returns time-based buckets with counts for date fields.
	 *
	 * @param TableTermsFacet $facet    The table, column, schema, register and filters of the facet.
	 * @param string          $interval The histogram interval (day, week, month, year).
	 *
	 * @return array Facet result with type, interval, and buckets.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 * @throws LogicException When no search handler is wired.
	 */
	private function getDateHistogramFacet(TableTermsFacet $facet, string $interval): array {
		// Check if column exists.
		if ($this->columnExists(tableName: $facet->tableName, columnName: $facet->field) === false) {
			return $this->emptyDateHistogram(interval: $interval);
		}

		// The filtered query comes from MagicSearchHandler (single source of
		// truth for filter SQL). The constructor in MagicMapper always wires a
		// non-null searchHandler, so this is a real precondition — fail loudly
		// if it ever drifts. The facet always carries a schema.
		if ($this->searchHandler === null) {
			$msg = 'MagicFacetHandler::getDateHistogramFacet requires a wired searchHandler and a non-null schema.';
			throw new LogicException($msg);
		}

		$result = $this->dateHistogramQuery(facet: $facet, interval: $interval)->executeQuery();
		return $this->dateHistogram(interval: $interval, buckets: $this->dateBuckets(rows: $result, interval: $interval));
	}//end getDateHistogramFacet()

	/**
	 * The scoped, grouped query of a single-table date histogram.
	 *
	 * @param TableTermsFacet $facet    The table, column and filters of the facet.
	 * @param string          $interval The histogram interval.
	 *
	 * @return IQueryBuilder The query, grouped per date key.
	 */
	private function dateHistogramQuery(TableTermsFacet $facet, string $interval): IQueryBuilder {
		// Same reason as the terms facet: a histogram that ignores `_related`
		// draws a shape of the unfiltered set beside a filtered list.
		$queryBuilder = $this->searchHandler->buildFilteredQuery(
			query: $facet->baseQuery,
			schema: $facet->schema,
			tableName: $facet->tableName,
			registerId: $facet->register->getId()
		);

		// The date-key SQL expression is platform-specific; buildFilteredQuery aliases the table as 't'.
		$tDateKeyExpr = $this->buildDateKeyExpr(field: "t.{$facet->field}", interval: $interval);
		return $queryBuilder->selectAlias($queryBuilder->createFunction($tDateKeyExpr), 'date_key')
			->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
			->andWhere($queryBuilder->expr()->isNotNull("t.{$facet->field}"))
			->groupBy('date_key')
			->orderBy('date_key', 'ASC');
	}//end dateHistogramQuery()

	/**
	 * Calculate from/to date bounds for a histogram bucket.
	 *
	 * @param string $dateKey The bucket key (e.g. "2025", "2025-02").
	 * @param string $interval The histogram interval.
	 *
	 * @return array{from: string, to: string}|null The bounds or null.
	 */
	private function getDateBoundsForBucket(string $dateKey, string $interval): ?array {
		if (isset(self::DATE_KEY_SUFFIXES[$interval]) === true) {
			[$fromSuffix, $toSuffix] = self::DATE_KEY_SUFFIXES[$interval];
			return ['from' => $dateKey . $fromSuffix, 'to' => $dateKey . $toSuffix];
		}

		return match ($interval) {
			'quarter' => $this->quarterBounds(dateKey: $dateKey),
			'month' => $this->monthBounds(dateKey: $dateKey),
			'week' => $this->isoWeekBounds(dateKey: $dateKey),
			default => null,
		};
	}//end getDateBoundsForBucket()

	/**
	 * From/to dates of a quarter bucket keyed `<year>-Q<quarter>`.
	 *
	 * @param string $dateKey The bucket key (e.g. "2025-Q2").
	 *
	 * @return array{from: string, to: string}|null The bounds, or null for another key shape.
	 */
	private function quarterBounds(string $dateKey): ?array {
		if (preg_match('/^(\d{4})-Q(\d)$/', $dateKey, $matches) !== 1) {
			return null;
		}

		$year = (int)$matches[1];
		$startMonth = (((int)$matches[2] - 1) * 3) + 1;
		$endMonth = $startMonth + 2;
		if (checkdate($endMonth, 1, $year) === false) {
			throw new \ValueError('Invalid date');
		}

		return [
			'from' => sprintf('%04d-%02d-01', $year, $startMonth),
			'to' => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))->format('Y-m-t'),
		];
	}//end quarterBounds()

	/**
	 * From/to dates of a month bucket keyed `<year>-<month>`.
	 *
	 * @param string $dateKey The bucket key (e.g. "2025-02").
	 *
	 * @return array{from: string, to: string}|null The bounds, or null when the key is no date.
	 */
	private function monthBounds(string $dateKey): ?array {
		$timestamp = strtotime($dateKey . '-01');
		if ($timestamp === false) {
			return null;
		}

		return [
			'from' => date('Y-m-01', $timestamp),
			'to' => date('Y-m-t', $timestamp),
		];
	}//end monthBounds()

	/**
	 * Monday/Sunday dates of an ISO 8601 week bucket keyed `<iso-year>-<iso-week>`.
	 *
	 * PHP's strtotime() parses a key like `2025-12` as "December 2025", not
	 * "ISO week 12 of 2025", so the bounds come from DateTime::setISODate().
	 * Matches the implementation in MariaDbFacetHandler and MetaDataFacetHandler.
	 *
	 * @param string $dateKey The bucket key (e.g. "2025-12").
	 *
	 * @return array{from: string, to: string}|null The bounds, or null for another key shape.
	 */
	private function isoWeekBounds(string $dateKey): ?array {
		if (preg_match('/^(\d{4})-(\d{1,2})$/', $dateKey, $matches) !== 1) {
			return null;
		}

		$date = new DateTime();
		$date->setISODate((int)$matches[1], (int)$matches[2], 1);
		$from = $date->format('Y-m-d');
		$date->setISODate((int)$matches[1], (int)$matches[2], 7);
		return [
			'from' => $from,
			'to' => $date->format('Y-m-d'),
		];
	}//end isoWeekBounds()

	/**
	 * Check if a column exists in the table.
	 *
	 * PERFORMANCE OPTIMIZATION: Uses in-memory cache to avoid repeated
	 * information_schema queries. Loads ALL columns for a table on first
	 * access (one query), then subsequent checks are instant array lookups.
	 *
	 * @param string $tableName The table name.
	 * @param string $columnName The column name.
	 *
	 * @return bool True if the column exists.
	 */
	private function columnExists(string $tableName, string $columnName): bool {
		try {
			// PostgreSQL stores unquoted identifiers in lowercase.
			$fullTableNameLower = strtolower($this->prefixedTableName(tableName: $tableName));

			// OPTIMIZATION: Check in-memory cache first.
			if (isset($this->columnCache[$fullTableNameLower]) === false) {
				$this->loadColumns(fullTableNameLower: $fullTableNameLower);
			}

			return isset($this->columnCache[$fullTableNameLower][strtolower($columnName)]);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] Failed to check column existence',
				context: ['file' => __FILE__, 'line' => __LINE__, 'tableName' => $tableName, 'column' => $columnName, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end columnExists()

	/**
	 * A table name with the configured prefix.
	 *
	 * The table name passed may or may not include the prefix; it is
	 * normalized to always have it for the information_schema lookup.
	 *
	 * @param string $tableName The table name.
	 *
	 * @return string The prefixed table name.
	 */
	private function prefixedTableName(string $tableName): string {
		$prefix = $this->getTablePrefix();
		if (str_starts_with($tableName, $prefix) === true) {
			return $tableName;
		}

		return $prefix . $tableName;
	}//end prefixedTableName()

	/**
	 * Load and cache ALL columns of a table in one query (instead of one query per column).
	 *
	 * @param string $fullTableNameLower The prefixed table name, lowercased.
	 *
	 * @return void
	 */
	private function loadColumns(string $fullTableNameLower): void {
		// The table_name is compared as stored, so PostgreSQL can use the catalog index.
		$sql = 'SELECT LOWER(column_name) as col FROM information_schema.columns
                    WHERE table_name = ?';

		$stmt = $this->db->prepare($sql);
		$stmt->execute([$fullTableNameLower]);

		// Cache all columns for this table.
		$this->columnCache[$fullTableNameLower] = [];
		while (($row = $stmt->fetch()) !== false) {
			$this->columnCache[$fullTableNameLower][$row['col']] = true;
		}
	}//end loadColumns()

	/**
	 * Apply base query filters to the query builder.
	 *
	 * @param IQueryBuilder   $queryBuilder The query builder to modify.
	 * @param TableTermsFacet $facet        The table, schema and base query of the facet.
	 *
	 * @return void
	 */
	private function applyBaseFilters(IQueryBuilder $queryBuilder, TableTermsFacet $facet): void {
		$this->applyDeletedLens(queryBuilder: $queryBuilder, baseQuery: $facet->baseQuery);

		// Apply metadata filters from @self.
		$this->applySelfFilters(queryBuilder: $queryBuilder, facet: $facet);

		// Apply object field filters (schema property filters).
		// These are filters like licentietype[]=Open source that filter on object fields.
		$this->applyObjectFieldFilters(queryBuilder: $queryBuilder, facet: $facet);

		// Apply search filter if provided.
		$search = $facet->baseQuery['_search'] ?? null;
		if ($search !== null && trim($search) !== '') {
			$this->applySearchFilter(queryBuilder: $queryBuilder, facet: $facet);
		}
	}//end applyBaseFilters()

	/**
	 * Apply the deleted lens, spelled once in DeletedLens so the facets count
	 * the rows the list shows.
	 *
	 * @param IQueryBuilder $queryBuilder The query builder to modify.
	 * @param array         $baseQuery    The base query.
	 *
	 * @return void
	 */
	private function applyDeletedLens(IQueryBuilder $queryBuilder, array $baseQuery): void {
		$lens = new DeletedLens();
		$deleted = $lens->condition(mode: $lens->mode(query: $baseQuery), columnPrefix: '');
		if ($deleted !== null) {
			$queryBuilder->andWhere($queryBuilder->createFunction($deleted));
		}
	}//end applyDeletedLens()

	/**
	 * Apply the metadata (@self) filters of the base query on the columns the table has.
	 *
	 * @param IQueryBuilder   $queryBuilder The query builder to modify.
	 * @param TableTermsFacet $facet        The table and base query of the facet.
	 *
	 * @return void
	 */
	private function applySelfFilters(IQueryBuilder $queryBuilder, TableTermsFacet $facet): void {
		$selfFilters = $facet->baseQuery['@self'] ?? null;
		if (is_array($selfFilters) === false) {
			return;
		}

		foreach ($selfFilters as $field => $value) {
			$columnName = self::METADATA_PREFIX . $field;
			if ($this->columnExists(tableName: $facet->tableName, columnName: $columnName) === true) {
				(new FacetColumnFilter(queryBuilder: $queryBuilder, columnName: $columnName))->equals(value: $value);
			}
		}
	}//end applySelfFilters()

	/**
	 * Apply object field filters to the query builder.
	 *
	 * Handles filters on schema properties like licentietype[]=Open source.
	 * Properly handles both regular string fields and JSON array fields.
	 *
	 * @param IQueryBuilder   $queryBuilder The query builder.
	 * @param TableTermsFacet $facet        The table, schema and base query of the facet.
	 *
	 * @return void
	 */
	private function applyObjectFieldFilters(IQueryBuilder $queryBuilder, TableTermsFacet $facet): void {
		$properties = ($facet->schema->getProperties() ?? []);

		foreach ($this->objectFieldFilterTerms(baseQuery: $facet->baseQuery) as $key => $value) {
			// This is an object field filter.
			$columnName = $this->sanitizeColumnName(name: $key);

			// If filter column doesn't exist, this schema can't match the filter: an
			// impossible condition returns 0 results, so facets don't count items
			// from schemas that don't have the filtered property. No need to process
			// further filters.
			if ($this->columnExists(tableName: $facet->tableName, columnName: $columnName) === false) {
				$queryBuilder->andWhere('1 = 0');
				return;
			}

			$filter = new FacetColumnFilter(queryBuilder: $queryBuilder, columnName: $columnName);
			$filter->matchesProperty(value: $value, propertyType: $properties[$key]['type'] ?? 'string');
		}
	}//end applyObjectFieldFilters()

	/**
	 * The query terms that filter on an object field: no reserved or underscore-prefixed parameter.
	 *
	 * @param array $baseQuery The base query.
	 *
	 * @return array The object field terms, key => value, in query order.
	 */
	private function objectFieldFilterTerms(array $baseQuery): array {
		$terms = [];
		foreach ($baseQuery as $key => $value) {
			if (in_array($key, self::RESERVED_FILTER_PARAMS, true) === true || str_starts_with($key, '_') === true) {
				continue;
			}

			$terms[$key] = $value;
		}

		return $terms;
	}//end objectFieldFilterTerms()

	/**
	 * Apply search filter to query builder using same logic as MagicMapper.
	 *
	 * This ensures facet counts match the main search results by using identical
	 * search logic: searches all string properties in the schema using ILIKE and
	 * trigram similarity (for PostgreSQL).
	 *
	 * Searches for the trimmed `_search` term of the facet's base query.
	 *
	 * @param IQueryBuilder   $queryBuilder The query builder.
	 * @param TableTermsFacet $facet        The table, schema and base query of the facet.
	 *
	 * @return void
	 */
	private function applySearchFilter(IQueryBuilder $queryBuilder, TableTermsFacet $facet): void {
		$searchTerm   = trim($facet->baseQuery['_search']);
		$orConditions = $queryBuilder->expr()->orX();

		$searchableColumns = $this->searchableColumns(facet: $facet);

		// Build search conditions (matching MagicMapper's ACTUAL behavior, not intended).
		// NOTE: Even though MagicMapper's applyFuzzySearch() includes trigram % operator,
		// in practice it seems to only use ILIKE. We match the actual behavior for consistency.
		$platform = $this->db->getDatabasePlatform();

		foreach ($searchableColumns as $column) {
			if ($this->columnExists(tableName: $facet->tableName, columnName: $column) === true) {
				$filter = new FacetColumnFilter(queryBuilder: $queryBuilder, columnName: $column);
				$orConditions->add($filter->searchCondition(pattern: '%' . $searchTerm . '%', platform: $platform));
			}
		}

		if ($orConditions->count() > 0) {
			$queryBuilder->andWhere($orConditions);
		}
	}//end applySearchFilter()

	/**
	 * The columns a facet search looks in: the string properties of the schema
	 * the table has (matching MagicMapper logic), else the metadata name,
	 * summary and uuid.
	 *
	 * @param TableTermsFacet $facet The table and schema of the facet.
	 *
	 * @return array<int, string> The column names.
	 */
	private function searchableColumns(TableTermsFacet $facet): array {
		$searchableColumns = $this->stringPropertyColumns(facet: $facet);

		// If no schema properties, fall back to metadata columns.
		if (empty($searchableColumns) === true) {
			$searchableColumns = [
				self::METADATA_PREFIX . 'name',
				self::METADATA_PREFIX . 'summary',
				self::METADATA_PREFIX . 'uuid',
			];
		}

		return $searchableColumns;
	}//end searchableColumns()

	/**
	 * The columns of the schema's string properties that the table has.
	 *
	 * @param TableTermsFacet $facet The table and schema of the facet.
	 *
	 * @return array<int, string> The column names.
	 */
	private function stringPropertyColumns(TableTermsFacet $facet): array {
		$columns = [];
		foreach (($facet->schema->getProperties() ?? []) as $propertyName => $propertyConfig) {
			// Only search in string fields (same as MagicMapper).
			if (($propertyConfig['type'] ?? 'string') !== 'string') {
				continue;
			}

			$columnName = $this->sanitizeColumnName(name: $propertyName);
			if ($this->columnExists(tableName: $facet->tableName, columnName: $columnName) === true) {
				$columns[] = $columnName;
			}
		}

		return $columns;
	}//end stringPropertyColumns()

	/**
	 * Get date format string for histogram interval.
	 *
	 * Uses PostgreSQL TO_CHAR format patterns.
	 *
	 * @param string $interval The interval (day, week, month, year).
	 *
	 * @return string PostgreSQL TO_CHAR date format string.
	 */
	private function getDateFormatForInterval(string $interval): string {
		return self::PG_DATE_FORMATS[$interval] ?? 'YYYY-MM';
	}//end getDateFormatForInterval()

	/**
	 * Build the platform-specific SQL expression that produces the `date_key`
	 * string for a date_histogram bucket.
	 *
	 * On PostgreSQL this wraps the field in `TO_CHAR(..., '<pg-pattern>')`.
	 * On MariaDB/MySQL it uses `DATE_FORMAT(..., '<my-pattern>')`, with a
	 * `CONCAT(YEAR(), '-Q', QUARTER())` special case for the `quarter`
	 * interval (MariaDB's DATE_FORMAT has no quarter specifier and its
	 * Oracle-compat TO_CHAR does not accept quoted literals).
	 *
	 * Bucket keys produced on both platforms MUST match for the same input
	 * date — e.g. week uses ISO year + ISO week on both (`IYYY-IW` on PG,
	 * `%x-%v` on MariaDB).
	 *
	 * @param string $field The qualified SQL column expression (e.g. `publicatiedatum` or `t.publicatiedatum`).
	 * @param string $interval The histogram interval (day, week, month, quarter, year).
	 *
	 * @return string The full SQL expression that evaluates to the bucket key string.
	 */
	private function buildDateKeyExpr(string $field, string $interval): string {
		if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			$pgFormat = $this->getDateFormatForInterval(interval: $interval);
			return "TO_CHAR({$field}, '{$pgFormat}')";
		}

		// MariaDB / MySQL: quarter needs a CONCAT because DATE_FORMAT has no
		// quarter specifier and quoted literals are not portable.
		if ($interval === 'quarter') {
			return "CONCAT(YEAR({$field}), '-Q', QUARTER({$field}))";
		}

		$myFormat = self::MYSQL_DATE_FORMATS[$interval] ?? '%Y-%m';
		return "DATE_FORMAT({$field}, '{$myFormat}')";
	}//end buildDateKeyExpr()

	/**
	 * Batch resolve UUID labels with field-level caching optimization.
	 *
	 * PERFORMANCE OPTIMIZATION:
	 * Instead of resolving labels per search query, we cache ALL labels for a field
	 * in distributed cache with a long TTL. This means:
	 * - First request for a field: loads all labels (may be slow)
	 * - Subsequent requests: instant lookup from cache
	 * - Labels rarely change, so long TTL (24h) is safe
	 *
	 * @param FacetLabelLookup $lookup The UUIDs to resolve and the field they belong to.
	 * @param RegisterSchema   $table  The register and schema the field belongs to.
	 *
	 * @return array<string, string> Map of UUID to label.
	 */
	private function batchResolveUuidLabels(FacetLabelLookup $lookup, RegisterSchema $table): array {
		if (empty($lookup->uuids) === true) {
			return [];
		}

		$lookup->cacheKey = 'facet_labels_' . $table->register->getId() . '_' . $table->schema->getId() . '_' . $lookup->field;

		if ($this->labelsFromFieldCache(lookup: $lookup) === true
			|| $this->labelsFromDistributedCache(lookup: $lookup) === true
		) {
			return $lookup->result;
		}

		$this->resolveLabelsViaObjectNames(lookup: $lookup);

		return $lookup->result;
	}//end batchResolveUuidLabels()

	/**
	 * Answer a label lookup from the in-memory field cache, when it holds
	 * every uuid; a partial hit leaves the rest for the next source.
	 *
	 * @param FacetLabelLookup $lookup The lookup.
	 *
	 * @return bool Whether every uuid was answered.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function labelsFromFieldCache(FacetLabelLookup $lookup): bool {
		if (isset($this->fieldLabelCache[$lookup->cacheKey]) === false
			|| $lookup->take(labels: $this->fieldLabelCache[$lookup->cacheKey]) === false
		) {
			return false;
		}

		$this->cacheStats['field_cache_hits']++;
		$this->cacheStats['total_uuids_resolved'] += count($lookup->result);
		$this->logLabelsAnswered(message: '[MagicFacetHandler] batchResolveUuidLabels: All labels from in-memory field cache', lookup: $lookup);
		return true;
	}//end labelsFromFieldCache()

	/**
	 * Log that a cache answered every uuid of a label lookup.
	 *
	 * @param string           $message The log message.
	 * @param FacetLabelLookup $lookup  The lookup.
	 *
	 * @return void
	 */
	private function logLabelsAnswered(string $message, FacetLabelLookup $lookup): void {
		$this->logger->debug(
			message: $message,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'field' => $lookup->field,
				'count' => count($lookup->result),
				'time_ms' => $lookup->elapsedMs(),
			]
		);
	}//end logLabelsAnswered()

	/**
	 * Warm the field cache from the distributed cache, once per field, and
	 * answer the lookup from it when it holds every uuid.
	 *
	 * @param FacetLabelLookup $lookup The lookup.
	 *
	 * @return bool Whether every uuid was answered.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function labelsFromDistributedCache(FacetLabelLookup $lookup): bool {
		if ($this->labelCache() === null || isset($this->warmedFields[$lookup->cacheKey]) === true) {
			return false;
		}

		try {
			return $this->takeDistributedLabels(lookup: $lookup);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] Failed to get facet labels from distributed cache: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}
	}//end labelsFromDistributedCache()

	/**
	 * Warm the field cache from the distributed cache and answer the lookup from it.
	 *
	 * @param FacetLabelLookup $lookup The lookup.
	 *
	 * @return bool Whether every uuid was answered.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function takeDistributedLabels(FacetLabelLookup $lookup): bool {
		$distributedLabels = $this->distLabelCache->get($lookup->cacheKey);
		if (is_array($distributedLabels) === false) {
			return false;
		}

		$this->fieldLabelCache[$lookup->cacheKey] = $distributedLabels;
		$this->warmedFields[$lookup->cacheKey] = true;
		if ($lookup->take(labels: $distributedLabels) === false) {
			return false;
		}

		$this->cacheStats['distributed_cache_hits']++;
		$this->cacheStats['total_uuids_resolved'] += count($lookup->result);
		$this->logLabelsAnswered(message: '[MagicFacetHandler] batchResolveUuidLabels: All labels from distributed cache', lookup: $lookup);
		return true;
	}//end takeDistributedLabels()

	/**
	 * Resolve the uuids no cache answered through the object name cache, and
	 * keep the result in the field cache and the distributed cache (24h TTL:
	 * labels rarely change).
	 *
	 * @param FacetLabelLookup $lookup The lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function resolveLabelsViaObjectNames(FacetLabelLookup $lookup): void {
		$this->resolveLazyDependencies();
		if ($this->cacheHandler === null || empty($lookup->uncached) === true) {
			return;
		}

		$this->cacheStats['cache_handler_calls']++;
		$batchedLabels = $this->cacheHandler->getMultipleObjectNames($lookup->uncached);
		$this->cacheStats['total_uuids_resolved'] += count($batchedLabels);

		$this->keepResolvedLabels(lookup: $lookup, batchedLabels: $batchedLabels);
		$this->logLabelsResolved(lookup: $lookup, resolved: count($batchedLabels));
	}//end resolveLabelsViaObjectNames()

	/**
	 * Log that the object name cache resolved labels of a lookup.
	 *
	 * @param FacetLabelLookup $lookup   The lookup.
	 * @param int              $resolved How many labels were resolved.
	 *
	 * @return void
	 */
	private function logLabelsResolved(FacetLabelLookup $lookup, int $resolved): void {
		$this->logger->debug(
			message: '[MagicFacetHandler] batchResolveUuidLabels: Resolved via CacheHandler and cached',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'field' => $lookup->field,
				'requested' => count($lookup->uuids),
				'resolved' => $resolved,
				'time_ms' => $lookup->elapsedMs(),
			]
		);
	}//end logLabelsResolved()

	/**
	 * Keep resolved labels in the lookup, the uuid and field caches and the distributed cache.
	 *
	 * @param FacetLabelLookup      $lookup        The lookup.
	 * @param array<string, string> $batchedLabels Labels keyed by uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function keepResolvedLabels(FacetLabelLookup $lookup, array $batchedLabels): void {
		foreach ($batchedLabels as $uuid => $label) {
			$this->uuidLabelCache[$uuid] = $label;
			$lookup->result[$uuid] = $label;
		}

		$this->fieldLabelCache[$lookup->cacheKey] = array_merge(
			($this->fieldLabelCache[$lookup->cacheKey] ?? []),
			$batchedLabels
		);

		$this->persistFieldLabels(cacheKey: $lookup->cacheKey);
	}//end keepResolvedLabels()

	/**
	 * Store a field's labels in the distributed cache; a failure is logged.
	 *
	 * @param string $cacheKey The field's cache key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function persistFieldLabels(string $cacheKey): void {
		if ($this->labelCache() === null) {
			return;
		}

		try {
			$this->distLabelCache->set($cacheKey, $this->fieldLabelCache[$cacheKey], self::FACET_LABEL_CACHE_TTL);
			$this->warmedFields[$cacheKey] = true;
		} catch (\Exception $e) {
			$eMsg = $e->getMessage();
			$this->logger->warning(
				message: "[MagicFacetHandler] Failed to persist facet labels to distributed cache: {$eMsg}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end persistFieldLabels()

	/**
	 * Get human-readable label for a field value.
	 *
	 * @param string $field The field name.
	 * @param mixed  $value The field value.
	 *
	 * @return string Human-readable label.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function getFieldLabel(string $field, mixed $value): string {
		$this->resolveLazyDependencies();

		return (new MagicFacetFieldLabel(db: $this->db, names: $this->cacheHandler))->labelOf(field: $field, value: $value);
	}//end getFieldLabel()
}//end class
