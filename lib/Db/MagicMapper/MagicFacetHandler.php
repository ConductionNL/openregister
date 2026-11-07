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

use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use DateTime;
use LogicException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Support\FilterParams;
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
	 * Cache handler for UUID to name resolution
	 *
	 * @var \OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface|null
	 */
	private ?\OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface $cacheHandler = null;

	/**
	 * Distributed cache factory for persistent label caching
	 *
	 * @var ICacheFactory|null
	 */
	private ?ICacheFactory $cacheFactory = null;

	/**
	 * Distributed cache instance for facet labels
	 *
	 * @var ICache|null
	 */
	private ?ICache $distLabelCache = null;

	/**
	 * Search handler for building filtered queries (single source of truth for filters).
	 *
	 * @var MagicSearchHandler|null
	 */
	private ?MagicSearchHandler $searchHandler = null;

	/**
	 * Container for lazy resolution of CacheHandler (avoids circular DI).
	 *
	 * @var ContainerInterface|null
	 */
	private ?ContainerInterface $container = null;

	/**
	 * Whether lazy dependencies have been resolved.
	 *
	 * @var boolean
	 */
	private bool $lazyResolved = false;

	/**
	 * Constructor for MagicFacetHandler
	 *
	 * @param IDBConnection $db Database connection for queries
	 * @param LoggerInterface $logger Logger for debugging and error reporting
	 * @param \OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface|null $cacheHandler Cache handler for name resolution
	 * @param ICacheFactory|null $cacheFactory Cache factory for distributed caching
	 * @param MagicSearchHandler|null $searchHandler Search handler for shared query building
	 * @param ContainerInterface|null $container Container for lazy resolution
	 * @param IConfig|null $config Nextcloud config for reading the table prefix
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		?\OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface $cacheHandler = null,
		?ICacheFactory $cacheFactory = null,
		?MagicSearchHandler $searchHandler = null,
		?ContainerInterface $container = null,
		private readonly ?IConfig $config = null,
	) {
		$this->cacheHandler = $cacheHandler;
		$this->cacheFactory = $cacheFactory;
		$this->searchHandler = $searchHandler;
		$this->container = $container;

		// Initialize distributed cache for facet labels.
		if ($this->cacheFactory !== null) {
			try {
				$this->distLabelCache = $this->cacheFactory->createDistributed('openregister_facet_labels');
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[MagicFacetHandler] Failed to create distributed facet label cache: ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}
	}//end __construct()

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
		if ($this->config === null) {
			return 'oc_';
		}

		return (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
	}//end getTablePrefix()

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

		try {
			$this->cacheHandler = $this->container->get(\OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface::class);
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[MagicFacetHandler] CacheHandler not available: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		try {
			$cacheFactory = $this->container->get(ICacheFactory::class);
			$this->cacheFactory = $cacheFactory;
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
	public function getSimpleFacets(
		string $tableName,
		array $query,
		Register $register,
		Schema $schema,
	): array {
		$startTime = microtime(true);

		$facetConfig = $query['_facets'] ?? [];
		if (empty($facetConfig) === true) {
			return [];
		}

		$facetConfig = $this->normaliseFacetConfig(facetConfig: $facetConfig, schema: $schema);

		// Extract base query (without facet config).
		$baseQuery = $query;
		unset($baseQuery['_facets']);
		$table = new TableTermsFacet(
			tableName: $tableName,
			field: '',
			baseQuery: $baseQuery,
			isMetadata: false,
			register: $register,
			schema: $schema
		);

		$facets     = [];
		$facetTimes = [];
		if (($facetConfig['@self'] ?? null) !== null && is_array($facetConfig['@self']) === true) {
			$facets['@self'] = [];
			foreach ($facetConfig['@self'] as $field => $config) {
				$facetStart = microtime(true);
				$facet      = $this->computeTableFacet(facet: $table->forMetadataField(field: self::METADATA_PREFIX . $field), config: $config);
				if ($facet !== null) {
					$facets['@self'][$field] = $facet;
				}

				$facetTimes['@self.' . $field] = round((microtime(true) - $facetStart) * 1000, 2);
			}
		}

		foreach ($facetConfig as $field => $config) {
			// 🔴 AN EXPLICITLY REQUESTED FACET OBEYS THE READ RULE TOO. The
			// guard in expandFacetConfig() covers only the auto-discovered
			// `facetable` loop; a caller who names the property in
			// `_facets[<prop>][type]=terms` would otherwise get the column's
			// distinct values back.
			if ($field === '@self' || $this->callerMayFacet(schema: $schema, property: (string)$field) === false) {
				continue;
			}

			$facetStart = microtime(true);
			// Sanitize field name to match database column (camelCase -> snake_case).
			$facet = $this->computeTableFacet(facet: $table->forPropertyField(field: $this->sanitizeColumnName(name: $field)), config: $config);
			if ($facet !== null) {
				$facets[$field] = $facet;
			}

			if (isset($config['title']) === true) {
				$facets[$field]['title'] = $config['title'];
			}

			$facetTimes[$field] = round((microtime(true) - $facetStart) * 1000, 2);
		}//end foreach

		// Add timing metadata to facets for performance debugging.
		$facets['_metrics'] = [
			'total_ms' => round((microtime(true) - $startTime) * 1000, 2),
			'per_facet_ms' => $facetTimes,
			'label_cache' => $this->cacheStats,
		];

		return $facets;
	}//end getSimpleFacets()

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

		return $this->getDateHistogramFacet(
			tableName: $facet->tableName,
			field: $facet->field,
			interval: $config['interval'] ?? 'month',
			baseQuery: $facet->baseQuery,
			schema: $facet->schema,
			register: $facet->register
		);
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

		// A `_related`/`_referenced` filter cannot be honoured in these raw-SQL
		// arms. Refuse HERE, before the per-facet queries, whose own catch blocks
		// would turn the refusal into empty buckets with only a log line.
		$this->searchHandler?->refuseRelatedRowFilters(query: $query);

		$facetConfig = $query['_facets'] ?? [];
		if (empty($facetConfig) === true) {
			return [];
		}

		$facetConfig = $this->normaliseUnionFacetConfig(facetConfig: $facetConfig, tableConfigs: $tableConfigs);

		// Extract base query (without facet config).
		$baseQuery = $query;
		unset($baseQuery['_facets']);

		$facets     = [];
		$facetTimes = [];
		foreach ($facetConfig as $field => $config) {
			// Same read rule as the single-table path: a facet across several
			// schemas is omitted when ANY of them withholds the property from
			// this caller, because the union would hand back that schema's
			// values among the others.
			if ($field === '@self'
				|| $this->callerMayFacetOnEveryTable(tableConfigs: $tableConfigs, property: (string)$field) === false
			) {
				continue;
			}

			$facetStart = microtime(true);
			$facet      = $this->computeUnionFacet(
				facet: new TermsFacetUnion(tableConfigs: $tableConfigs, field: $this->sanitizeColumnName(name: $field), baseQuery: $baseQuery),
				config: $config
			);
			if ($facet !== null) {
				$facets[$field] = $facet;
			}

			if (isset($config['title']) === true) {
				$facets[$field]['title'] = $config['title'];
			}

			$facetTimes[$field] = round((microtime(true) - $facetStart) * 1000, 2);
		}//end foreach

		if (($facetConfig['@self'] ?? null) !== null && is_array($facetConfig['@self']) === true) {
			$facets['@self'] = [];
			foreach ($facetConfig['@self'] as $field => $config) {
				$facetStart = microtime(true);
				$facet      = $this->computeUnionFacet(
					facet: new TermsFacetUnion(tableConfigs: $tableConfigs, field: self::METADATA_PREFIX . $field, baseQuery: $baseQuery, isMetadata: true),
					config: $config
				);
				if ($facet !== null) {
					$facets['@self'][$field] = $facet;
				}

				$facetTimes['@self.' . $field] = round((microtime(true) - $facetStart) * 1000, 2);
			}
		}

		// Add timing metadata to facets for performance debugging.
		$facets['_metrics'] = [
			'total_ms' => round((microtime(true) - $startTime) * 1000, 2),
			'table_count' => count($tableConfigs),
			'per_facet_ms' => $facetTimes,
			'label_cache' => $this->cacheStats,
		];

		return $facets;
	}//end getSimpleFacetsUnion()

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
		if ($type !== 'terms' && $type !== 'date_histogram') {
			return null;
		}

		if ($facet->isMetadata === false) {
			$facet = new TermsFacetUnion(tableConfigs: $this->tablesWithColumn(facet: $facet), field: $facet->field, baseQuery: $facet->baseQuery);
		}

		if ($type === 'date_histogram') {
			return $this->getDateHistogramFacetUnion(
				tableConfigs: $facet->tableConfigs,
				field: $facet->field,
				interval: $config['interval'] ?? 'month',
				baseQuery: $facet->baseQuery
			);
		}

		if (empty($facet->tableConfigs) === true) {
			return ['type' => 'terms', 'buckets' => []];
		}

		return $this->getTermsFacetUnion(facet: $facet);
	}//end computeUnionFacet()

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
		if (empty($facet->tableConfigs) === true || $this->isSafeTermsColumn(field: $facet->field) === false) {
			return $this->emptyTermsFacet();
		}

		$sql = $this->buildTermsUnionSql(facet: $facet);
		if ($sql === null) {
			return $this->emptyTermsFacet();
		}

		try {
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
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] UNION facet query failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'field' => $facet->field,
					'error' => $e->getMessage(),
					'sql' => $sql,
				]
			);
			return $this->emptyTermsFacet();
		}//end try
	}//end getTermsFacetUnion()

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
		$prefix = $this->getTablePrefix();

		// Cast to text: tables may hold the same column with different types.
		$castField = "CAST({$facet->field} AS CHAR)";
		if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform === true) {
			$castField = "{$facet->field}::text";
		}

		$unionParts = [];
		foreach ($facet->tableConfigs as $tableConfig) {
			$conditions = $this->termsTableConditions(facet: $facet, tableConfig: $tableConfig);
			if ($conditions === null) {
				continue;
			}

			$unionParts[] = "SELECT {$castField} as facet_value, COUNT(*) as cnt FROM {$prefix}{$tableConfig['tableName']} WHERE 1=1"
				. $conditions . " GROUP BY {$castField}";
		}

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
	 * The filter conditions one table adds to its part of a terms union.
	 *
	 * @param TermsFacetUnion $facet       The tables, column and filters of the facet.
	 * @param array           $tableConfig One entry of the facet's table configurations.
	 *
	 * @return string|null The conditions as " AND ..." text, or null when the
	 *                     table lacks the column or its filters rule it out.
	 */
	private function termsTableConditions(TermsFacetUnion $facet, array $tableConfig): ?string {
		$tableName = $tableConfig['tableName'];
		if ($this->columnExists(tableName: $tableName, columnName: $facet->field) === false) {
			return null;
		}

		if ($this->searchHandler === null) {
			return '';
		}

		$conditions = '';
		$whereConditions = $this->searchHandler->buildWhereConditionsSql(
			query: $facet->baseQuery,
			schema: $tableConfig['schema'],
			registerId: ($tableConfig['register'] ?? null)?->getId(),
			tableName: $tableName
		);
		foreach ($whereConditions as $condition) {
			// '1=0' means a filter column does not exist on this schema.
			if ($condition === '1=0') {
				return null;
			}

			$conditions .= " AND {$condition}";
		}

		return $conditions;
	}//end termsTableConditions()

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

		$buckets = [];
		foreach ($normalizedBuckets as $bucket) {
			$key = $bucket['key'];
			// For metadata facets, use getFieldLabel to resolve IDs to names.
			$label = $labelMap[$key] ?? (string)$key;
			if ($facet->isMetadata === true) {
				$label = $this->getFieldLabel(field: $facet->field, value: $key);
			}

			$buckets[] = [
				'key' => $key,
				'results' => $bucket['count'],
				'label' => $label,
			];
		}

		return $buckets;
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
		if (empty($uuidsToResolve) === true || $facet->isMetadata === true
			|| $this->anyHoldsObjectReferences(tableConfigs: $facet->tableConfigs, column: $facet->field) === false
		) {
			return [];
		}

		$tableConfigs = $facet->tableConfigs;
		$firstConfig = reset($tableConfigs);
		return $this->batchResolveUuidLabels(
			uuids: $uuidsToResolve,
			field: $facet->field,
			schema: $firstConfig['schema'],
			register: $firstConfig['register']
		);
	}//end termsUnionLabelMap()

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
		if (is_string($key) === false || str_starts_with(trim($key), '[') === false) {
			return null;
		}

		$decoded = json_decode($key, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end jsonArrayKey()

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
	 * @param array $tableConfigs Array of table configurations.
	 * @param string $field The field/column name.
	 * @param string $interval Histogram interval (day, week, month, year).
	 * @param array $baseQuery Base query filters.
	 *
	 * @return array Facet result with merged buckets.
	 */
	private function getDateHistogramFacetUnion(
		array $tableConfigs,
		string $field,
		string $interval,
		array $baseQuery,
	): array {
		if (empty($tableConfigs) === true) {
			return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => []];
		}

		$unionParts = [];
		$prefix = $this->getTablePrefix();

		foreach ($tableConfigs as $tc) {
			$tableName = $tc['tableName'];
			$fullTableName = $prefix . $tableName;
			$tcSchema = $tc['schema'] ?? null;

			if ($this->columnExists(tableName: $tableName, columnName: $field) === false) {
				continue;
			}

			$selectExpr = $this->buildDateKeyExpr(field: $field, interval: $interval);
			$whereClause = "{$field} IS NOT NULL";
			$subSql = "SELECT {$selectExpr} as date_key, COUNT(*) as cnt FROM {$fullTableName} WHERE {$whereClause}";

			// Use shared method for all filter conditions (single source of truth).
			if ($this->searchHandler !== null && $tcSchema !== null) {
				$whereConditions = $this->searchHandler->buildWhereConditionsSql(
					query: $baseQuery,
					schema: $tcSchema,
					registerId: ($tc['register'] ?? null)?->getId(),
					tableName: $tableName
				);
				foreach ($whereConditions as $condition) {
					if ($condition === '1=0') {
						continue 2;
					}

					$subSql .= " AND {$condition}";
				}
			}

			$subSql .= ' GROUP BY date_key';
			$unionParts[] = $subSql;
		}//end foreach

		if (empty($unionParts) === true) {
			return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => []];
		}

		$unionSql = implode("\nUNION ALL\n", $unionParts);
		$innerSql = "(\n{$unionSql}\n) combined";
		$sql = "SELECT date_key, SUM(cnt) as doc_count FROM {$innerSql} GROUP BY date_key ORDER BY date_key ASC";

		try {
			$stmt = $this->db->prepare($sql);
			$stmt->execute();

			$buckets = [];
			while (($row = $stmt->fetch()) !== false) {
				$bucket = [
					'key' => $row['date_key'],
					'results' => (int)$row['doc_count'],
				];

				$bounds = $this->getDateBoundsForBucket(dateKey: $row['date_key'], interval: $interval);
				if ($bounds !== null) {
					$bucket['from'] = $bounds['from'];
					$bucket['to'] = $bounds['to'];
				}

				$buckets[] = $bucket;
			}

			return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => $buckets];
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] UNION date histogram failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field, 'error' => $e->getMessage()]
			);
			return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => []];
		}//end try
	}//end getDateHistogramFacetUnion()

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
			// Return all facetable metadata fields.
			// PERFORMANCE: Metadata facets are disabled by default for performance reasons.
			// Date histograms (@self.created, @self.updated) are particularly slow (~170-200ms each)
			// because they require grouping and date formatting across all tables.
			// The @self.register facet requires label resolution (~140ms).
			// These can be explicitly requested via _facets=@self.created,@self.updated,@self.register
			// if needed for specific use cases.
			$config = [
				'@self' => [
					// Disabled for performance - uncomment if needed:
					// 'register'     => ['type' => 'terms'].
					'schema' => ['type' => 'terms'],
					// 'organisation' => ['type' => 'terms'],
					// 'created'      => ['type' => 'date_histogram', 'interval' => 'month'],
					// 'updated'      => ['type' => 'date_histogram', 'interval' => 'month'],
				],
			];

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

				// Check if property is marked as facetable (boolean true or config object).
				$facetable = $property['facetable'] ?? false;
				if ($facetable === true || (is_array($facetable) === true && empty($facetable) === false)) {
					// Determine facet type based on property type and config.
					$facetType = $this->determineFacetTypeFromProperty(property: $property);
					$facetEntry = [
						'type' => $facetType,
						'title' => $property['title'] ?? null,
					];

					// Pass through options from facetable config.
					if (is_array($facetable) === true) {
						if (isset($facetable['options']) === true) {
							$facetEntry['options'] = $facetable['options'];
						}

						if ($facetType === 'date_histogram') {
							$facetEntry['interval'] = $facetable['options']['interval'] ?? 'month';
						}
					}

					$config[$propertyKey] = $facetEntry;
				}//end if
			}//end foreach

			return $config;
		}//end if

		// Treat as comma-separated field names.
		$fields = array_map('trim', explode(',', $facetConfig));
		$config = ['@self' => []];

		foreach ($fields as $field) {
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
	}//end expandFacetConfig()

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
		if (in_array($property, ExplicitReveal::explicitProperties(schema: $schema), true) === true) {
			return false;
		}

		if ($schema->hasPropertyAuthorization() === false) {
			// Nothing on this schema is governed at property level, so there is
			// no question to ask and no handler to resolve.
			return true;
		}

		if ($this->container === null) {
			$this->logger->warning(
				message: '[MagicFacetHandler] No container to resolve the property read rule; omitting the facet',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property]
			);
			return false;
		}

		try {
			$rbac = $this->container->get(PropertyRbacHandler::class);

			// The object is empty because a facet is not about one record: it
			// asks whether this property is readable AT ALL for this caller, not
			// whether it is readable on some particular row. A conditional rule
			// that depends on a record therefore does not admit the facet, which
			// is the safe direction.
			return $rbac->canReadProperty(schema: $schema, property: $property, object: []);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] Could not check the property read rule; omitting the facet',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'property' => $property,
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}
	}//end callerMayFacet()

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
		$cacheKey = md5(json_encode(value: [$facet->tableName, $facet->field, $facet->baseQuery, $facet->isMetadata]));
		if (isset($this->facetCache[$cacheKey]) === true) {
			return $this->facetCache[$cacheKey];
		}

		if ($this->columnExists(tableName: $facet->tableName, columnName: $facet->field) === false) {
			$this->logger->debug(
				message: '[MagicFacetHandler] Column does not exist for facet',
				context: ['file' => __FILE__, 'line' => __LINE__, 'tableName' => $facet->tableName, 'field' => $facet->field]
			);
			$this->facetCache[$cacheKey] = $this->emptyTermsFacet();
			return $this->facetCache[$cacheKey];
		}

		$this->facetCache[$cacheKey] = $this->countTableTerms(facet: $facet);
		return $this->facetCache[$cacheKey];
	}//end getTermsFacet()

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
		$field = $facet->field;
		$queryBuilder = $this->db->getQueryBuilder();
		$queryBuilder->selectAlias($field, 'facet_value')
			->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
			->from($facet->tableName)
			->groupBy($field);
		$this->orderFacetGroups(queryBuilder: $queryBuilder, columnRef: $field);
		$this->applyBaseFilters(queryBuilder: $queryBuilder, facet: $facet);

		if ($this->searchHandler === null) {
			return $queryBuilder;
		}

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
		$columnRef = "t.{$field}";
		$queryBuilder->selectAlias($columnRef, 'facet_value')
			->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
			->groupBy($columnRef);
		$this->orderFacetGroups(queryBuilder: $queryBuilder, columnRef: $columnRef);

		return $queryBuilder;
	}//end tableTermsQuery()

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
		$batchKeys = [];
		if ($objectReferences === true && $facet->isMetadata === false) {
			$batchKeys = array_flip($this->uuidKeys(buckets: $normalizedBuckets));
		}

		$labelMap = [];
		if (empty($batchKeys) === false) {
			$labelMap = $this->batchResolveUuidLabels(
				uuids: array_keys($batchKeys),
				field: $facet->field,
				schema: $facet->schema,
				register: $facet->register
			);
		}

		$buckets = [];
		foreach ($normalizedBuckets as $bucket) {
			$key = $bucket['key'];
			$label = (string)$key;
			if ($objectReferences === true) {
				$label = $this->resolveBucketLabel(
					key: $key,
					labelMap: $labelMap,
					batchResolved: is_string($key) === true && isset($batchKeys[$key]) === true,
					field: $facet->field
				);
			}

			$buckets[] = [
				'key' => $key,
				'results' => $bucket['count'],
				'label' => $label,
			];
		}//end foreach

		return $buckets;
	}//end labelTableTermsBuckets()

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
	 * Pick the label for one terms bucket.
	 *
	 * A key the batch lookup already asked about is never looked up again on its
	 * own: a UUID the batch could not name (a dangling reference, or an object the
	 * caller may not read) would otherwise cost a scan of every magic table per
	 * bucket.
	 *
	 * @param mixed                 $key           The bucket key.
	 * @param array<string, string> $labelMap      Labels from batchResolveUuidLabels().
	 * @param bool                  $batchResolved Whether the batch lookup already asked about this key.
	 * @param string                $field         The facet field.
	 *
	 * @return string The bucket label.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec exclude performance-only refactor of the facet label loop; output is unchanged
	 */
	private function resolveBucketLabel(
		mixed $key,
		array $labelMap,
		bool $batchResolved,
		string $field,
	): string {
		if (is_string($key) === true && isset($labelMap[$key]) === true) {
			return $labelMap[$key];
		}

		if ($batchResolved === true) {
			return substr((string)$key, 0, 8) . '...';
		}

		return $this->getFieldLabel(field: $field, value: $key);
	}//end resolveBucketLabel()

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
	 * @param string $tableName The table name.
	 * @param string $field The field/column name.
	 * @param string $interval The histogram interval (day, week, month, year).
	 * @param array $baseQuery Base query filters to apply.
	 * @param Schema|null $schema The schema for property type checking.
	 * @param Register|null $register The register the table belongs to, for the register-scoped property lookup.
	 *
	 * @return array Facet result with type, interval, and buckets.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function getDateHistogramFacet(
		string $tableName,
		string $field,
		string $interval,
		array $baseQuery,
		?Schema $schema = null,
		?Register $register = null,
	): array {
		// Check if column exists.
		if ($this->columnExists(tableName: $tableName, columnName: $field) === false) {
			return [
				'type' => 'date_histogram',
				'interval' => $interval,
				'buckets' => [],
			];
		}

		// Build the filtered query via MagicSearchHandler (single source of truth
		// for filter SQL). Both private callers (getMagicTableFacets and
		// getMagicTableFacetsUnion) always pass a non-null Schema, and the
		// constructor in MagicMapper always wires a non-null searchHandler, so
		// these are real preconditions — fail loudly if they ever drift. The
		// previously-present "manual fallback" block on this method was dead
		// code: it was unconditionally overwritten by the searchHandler branch
		// and produced unfiltered counts that no caller could reach.
		if ($this->searchHandler === null || $schema === null) {
			$msg = 'MagicFacetHandler::getDateHistogramFacet requires a wired searchHandler and a non-null schema.';
			throw new LogicException($msg);
		}

		// Same reason as the terms facet: a histogram that ignores `_related`
		// draws a shape of the unfiltered set beside a filtered list.
		$queryBuilder = $this->searchHandler->buildFilteredQuery(
			query: $baseQuery,
			schema: $schema,
			tableName: $tableName,
			registerId: $register?->getId()
		);

		// The date-key SQL expression is platform-specific (TO_CHAR on
		// PostgreSQL, DATE_FORMAT on MariaDB/MySQL); buildDateKeyExpr()
		// encapsulates the branch. buildFilteredQuery aliases the table as 't'.
		$tDateKeyExpr = $this->buildDateKeyExpr(field: "t.{$field}", interval: $interval);
		$queryBuilder->selectAlias(
			$queryBuilder->createFunction($tDateKeyExpr),
			'date_key'
		)
			->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
			->andWhere($queryBuilder->expr()->isNotNull("t.{$field}"))
			->groupBy('date_key')
			->orderBy('date_key', 'ASC');

		$result = $queryBuilder->executeQuery();
		$buckets = [];

		while (($row = $result->fetch()) !== false) {
			$bucket = [
				'key' => $row['date_key'],
				'results' => (int)$row['doc_count'],
			];

			$bounds = $this->getDateBoundsForBucket(dateKey: $row['date_key'], interval: $interval);
			if ($bounds !== null) {
				$bucket['from'] = $bounds['from'];
				$bucket['to'] = $bounds['to'];
			}

			$buckets[] = $bucket;
		}

		return [
			'type' => 'date_histogram',
			'interval' => $interval,
			'buckets' => $buckets,
		];
	}//end getDateHistogramFacet()

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
			// The table name passed may or may not include the prefix.
			// Normalize to always have the configured prefix for information_schema lookup.
			$prefix = $this->getTablePrefix();
			$fullTableName = $prefix . $tableName;
			if (str_starts_with($tableName, $prefix) === true) {
				$fullTableName = $tableName;
			}

			// PostgreSQL stores unquoted identifiers in lowercase.
			$fullTableNameLower = strtolower($fullTableName);
			$columnNameLower = strtolower($columnName);

			// OPTIMIZATION: Check in-memory cache first.
			if (isset($this->columnCache[$fullTableNameLower]) === true) {
				return isset($this->columnCache[$fullTableNameLower][$columnNameLower]);
			}

			// Load ALL columns for this table in one query (instead of one query per column).
			// table_name is compared as stored, so PostgreSQL can use the catalog index.
			$sql = 'SELECT LOWER(column_name) as col FROM information_schema.columns
                    WHERE table_name = ?';

			$stmt = $this->db->prepare($sql);
			$stmt->execute([$fullTableNameLower]);

			// Cache all columns for this table.
			$this->columnCache[$fullTableNameLower] = [];
			while (($row = $stmt->fetch()) !== false) {
				$this->columnCache[$fullTableNameLower][$row['col']] = true;
			}

			return isset($this->columnCache[$fullTableNameLower][$columnNameLower]);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] Failed to check column existence',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'tableName' => $tableName,
					'column' => $columnName,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}//end try
	}//end columnExists()

	/**
	 * Apply base query filters to the query builder.
	 *
	 * @param IQueryBuilder   $queryBuilder The query builder to modify.
	 * @param TableTermsFacet $facet        The table, schema and base query of the facet.
	 *
	 * @return void
	 */
	private function applyBaseFilters(IQueryBuilder $queryBuilder, TableTermsFacet $facet): void {
		$baseQuery = $facet->baseQuery;
		// The deleted lens, spelled once in DeletedLens so the facets
		// count the rows the list shows.
		$lens = new DeletedLens();
		$deleted = $lens->condition(mode: $lens->mode(query: $baseQuery), columnPrefix: '');
		if ($deleted !== null) {
			$queryBuilder->andWhere($queryBuilder->createFunction($deleted));
		}

		// Apply metadata filters from @self.
		if (($baseQuery['@self'] ?? null) !== null && is_array($baseQuery['@self']) === true) {
			foreach ($baseQuery['@self'] as $field => $value) {
				$columnName = self::METADATA_PREFIX . $field;

				if ($this->columnExists(tableName: $facet->tableName, columnName: $columnName) === false) {
					continue;
				}

				if (is_array($value) === true) {
					$queryBuilder->andWhere(
						$queryBuilder->expr()->in(
							$columnName,
							$queryBuilder->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)
						)
					);
					continue;
				}

				$queryBuilder->andWhere(
					$queryBuilder->expr()->eq($columnName, $queryBuilder->createNamedParameter($value))
				);
			}//end foreach
		}//end if

		// Apply object field filters (schema property filters).
		// These are filters like licentietype[]=Open source that filter on object fields.
		$this->applyObjectFieldFilters(queryBuilder: $queryBuilder, facet: $facet);

		// Apply search filter if provided.
		$search = $baseQuery['_search'] ?? null;
		if ($search !== null && trim($search) !== '') {
			$this->applySearchFilter(queryBuilder: $queryBuilder, facet: $facet);
		}
	}//end applyBaseFilters()

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
		$tableName  = $facet->tableName;
		$properties = ($facet->schema->getProperties() ?? []);

		foreach ($this->objectFieldFilterTerms(baseQuery: $facet->baseQuery) as $key => $value) {
			// This is an object field filter.
			$columnName = $this->sanitizeColumnName(name: $key);

			// Check if column exists.
			// If filter column doesn't exist, this schema can't match the filter - return 0 results.
			if ($this->columnExists(tableName: $tableName, columnName: $columnName) === false) {
				// Add an impossible condition to return 0 results.
				// This ensures facets don't count items from schemas that don't have the filtered property.
				$queryBuilder->andWhere('1 = 0');
				return;
				// No need to process further filters.
			}

			// Determine if this is an array-type property.
			$propertyType = $properties[$key]['type'] ?? 'string';

			if ($propertyType === 'array') {
				// Handle JSON array field filtering using containment operator.
				$this->applyJsonArrayFilter(
					queryBuilder: $queryBuilder,
					columnName: $columnName,
					value: $value
				);
				continue;
			}

			// A PHP boolean compares in its string form (boolean-filter-values).
			$value = FilterParams::comparableValue(value: $value, propertyType: $propertyType);

			// Handle regular field filtering.
			if (is_array($value) === true) {
				$queryBuilder->andWhere(
					$queryBuilder->expr()->in(
						$columnName,
						$queryBuilder->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)
					)
				);
				continue;
			}

			$queryBuilder->andWhere(
				$queryBuilder->expr()->eq($columnName, $queryBuilder->createNamedParameter($value))
			);
		}//end foreach
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
	 * Apply JSON array containment filter.
	 *
	 * Uses PostgreSQL's @> operator to check if JSON array contains value(s).
	 *
	 * @param IQueryBuilder $queryBuilder The query builder.
	 * @param string $columnName The column name.
	 * @param mixed $value The filter value (string or array).
	 *
	 * @return void
	 */
	private function applyJsonArrayFilter(IQueryBuilder $queryBuilder, string $columnName, mixed $value): void {
		// Normalize value to array.
		$values = [$value];
		if (is_array($value) === true) {
			$values = $value;
		}

		// Use AND logic: JSON array must contain ALL specified values.
		$columnCast = $queryBuilder->createFunction("{$columnName}::jsonb");
		foreach ($values as $v) {
			$jsonValue = json_encode([$v]);
			$paramName = $queryBuilder->createNamedParameter($jsonValue);
			$queryBuilder->andWhere("{$columnCast} @> {$paramName}");
		}
	}//end applyJsonArrayFilter()

	/**
	 * Apply search filter to query builder.
	 *
	 * @param IQueryBuilder $queryBuilder The query builder.
	 * @param string $searchTerm The search term.
	 * @param string $tableName The table name.
	 *
	 * @return void
	 */

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
		$tableName    = $facet->tableName;
		$orConditions = $queryBuilder->expr()->orX();

		// Get all text-based properties from the schema (matching MagicMapper logic).
		$searchableColumns = [];

		$properties = $facet->schema->getProperties() ?? [];
		if (is_array($properties) === true) {
			foreach ($properties as $propertyName => $propertyConfig) {
				$type = $propertyConfig['type'] ?? 'string';
				// Only search in string fields (same as MagicMapper).
				if ($type === 'string') {
					$columnName = $this->sanitizeColumnName(name: $propertyName);
					if ($this->columnExists(tableName: $tableName, columnName: $columnName) === true) {
						$searchableColumns[] = $columnName;
					}
				}
			}
		}

		// If no schema properties, fall back to metadata columns.
		if (empty($searchableColumns) === true) {
			$searchableColumns = [
				self::METADATA_PREFIX . 'name',
				self::METADATA_PREFIX . 'summary',
				self::METADATA_PREFIX . 'uuid',
			];
		}

		// Build search conditions (matching MagicMapper's ACTUAL behavior, not intended).
		// NOTE: Even though MagicMapper's applyFuzzySearch() includes trigram % operator,
		// in practice it seems to only use ILIKE. We match the actual behavior for consistency.
		$platform = $this->db->getDatabasePlatform();
		$searchPattern = '%' . $searchTerm . '%';

		foreach ($searchableColumns as $column) {
			if ($this->columnExists(tableName: $tableName, columnName: $column) === true) {
				// Use ILIKE only (matching actual behavior, not the % operator).
				// MariaDB/MySQL: Use LIKE for case-insensitive substring match.
				$searchCondition = $queryBuilder->expr()->like(
					$queryBuilder->createFunction("LOWER($column)"),
					$queryBuilder->createNamedParameter(strtolower($searchPattern))
				);
				if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform === true) {
					$searchCondition = $queryBuilder->createFunction(
						"LOWER($column) ILIKE LOWER(" . $queryBuilder->createNamedParameter($searchPattern) . ')'
					);
				}

				$orConditions->add($searchCondition);
			}
		}

		if ($orConditions->count() > 0) {
			$queryBuilder->andWhere($orConditions);
		}
	}//end applySearchFilter()

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
	 * @param array $uuids Array of UUIDs to resolve.
	 * @param string $field The field name for cache key.
	 * @param Schema $schema The current schema context.
	 * @param Register $register The current register context.
	 * @param string $tableName The magic mapper table name (optional, for cache key).
	 *
	 * @return array<string, string> Map of UUID to label.
	 *
	 * @psalm-suppress UnusedParam $tableName reserved for cache key usage in future optimization
	 */
	private function batchResolveUuidLabels(
		array $uuids,
		string $field,
		Schema $schema,
		Register $register,
		string $tableName = '',
	): array {
		if (empty($uuids) === true) {
			return [];
		}

		$lookup = new FacetLabelLookup(uuids: $uuids, field: $field);
		$lookup->cacheKey = 'facet_labels_' . $register->getId() . '_' . $schema->getId() . '_' . $field;

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
		$this->logger->debug(
			message: '[MagicFacetHandler] batchResolveUuidLabels: All labels from in-memory field cache',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'field' => $lookup->field,
				'count' => count($lookup->result),
				'time_ms' => $lookup->elapsedMs(),
			]
		);
		return true;
	}//end labelsFromFieldCache()

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
		if ($this->distLabelCache === null || isset($this->warmedFields[$lookup->cacheKey]) === true) {
			return false;
		}

		try {
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
			$this->logger->debug(
				message: '[MagicFacetHandler] batchResolveUuidLabels: All labels from distributed cache',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'field' => $lookup->field,
					'count' => count($lookup->result),
					'time_ms' => $lookup->elapsedMs(),
				]
			);
			return true;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicFacetHandler] Failed to get facet labels from distributed cache: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}//end try
	}//end labelsFromDistributedCache()

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

		foreach ($batchedLabels as $uuid => $label) {
			$this->uuidLabelCache[$uuid] = $label;
			$lookup->result[$uuid] = $label;
		}

		$this->fieldLabelCache[$lookup->cacheKey] = array_merge(
			($this->fieldLabelCache[$lookup->cacheKey] ?? []),
			$batchedLabels
		);

		$this->persistFieldLabels(cacheKey: $lookup->cacheKey);

		$this->logger->debug(
			message: '[MagicFacetHandler] batchResolveUuidLabels: Resolved via CacheHandler and cached',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'field' => $lookup->field,
				'requested' => count($lookup->uuids),
				'resolved' => count($batchedLabels),
				'time_ms' => $lookup->elapsedMs(),
			]
		);
	}//end resolveLabelsViaObjectNames()

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
		if ($this->distLabelCache === null) {
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
