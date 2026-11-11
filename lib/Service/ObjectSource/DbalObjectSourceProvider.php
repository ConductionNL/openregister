<?php

/**
 * DbalObjectSourceProvider — serves a virtual schema's objects live from an
 * external SQL database over Doctrine DBAL (read-only).
 *
 * The schema's `x-openregister-object-source.config` block names the owning
 * `sourceId`, the backing `table`, and the id shape (`idColumn` / `idColumns` /
 * null). This provider translates an OpenRegister query (filters, `_search`,
 * sort, limit/offset) into a PARAMETERISED DBAL {@see \Doctrine\DBAL\Query\QueryBuilder}
 * — every value is a bound parameter and every identifier is quoted through the
 * platform — pushes limit/offset into SQL, and exposes a real `count()` via
 * `SELECT COUNT(*)` with the same predicate (design D4). Only columns that were
 * introspected onto the schema (minus relation/inverse and non-filterable
 * columns) may appear in a predicate — a per-source allowlist. It is strictly
 * read-only; writes to a bound schema are rejected upstream (SaveObject/DeleteObject).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Support\QueryLimit;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Read-only object-source provider backed by an external SQL database.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The provider owns the whole
 *   OR-query → parameterised-SQL translation (filters, search, sort, paging, id
 *   shapes, allowlisting, error semantics) as one cohesive design-D4 unit.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Bridges the DBAL query stack
 *   and the OR entity/mapper seams the design names explicitly.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
class DbalObjectSourceProvider implements WritableObjectSourceProvider {
	/**
	 * Hard cap on rows returned by a single findAll() for a NUMERIC limit.
	 *
	 * An explicit unlimited (`limit=false`) bypasses it; an oversized number
	 * does not. See {@see \OCA\OpenRegister\Support\QueryLimit}.
	 *
	 * @var int
	 */
	private const MAX_RESULTS = 1000;

	/**
	 * Default page size when the query does not request a limit.
	 *
	 * @var int
	 */
	private const DEFAULT_LIMIT = 200;

	/**
	 * Alias for the derived table wrapping a query-backed schema's definition
	 * (`FROM (<query>) <alias>`). Distinctive to avoid colliding with any table
	 * alias that appears inside the query definition itself.
	 *
	 * @var string
	 */
	private const DERIVED_ALIAS = 'or_src';

	/**
	 * Runs the pushed-down aggregations once the source and connection are resolved.
	 *
	 * @var DbalAggregator
	 */
	private readonly DbalAggregator $aggregator;

	/**
	 * Constructor.
	 *
	 * @param SourceMapperInterface $sourceMapper Loads the backing database source.
	 * @param DbalConnectionFactory $connectionFactory Opens the read-only DBAL connection.
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SourceMapperInterface $sourceMapper,
		private readonly DbalConnectionFactory $connectionFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->aggregator = new DbalAggregator(logger: $logger);
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function getId(): string {
		return DatabaseIntrospectionService::PROVIDER_ID;
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * Enabled when Doctrine DBAL is present and at least one supported driver
	 * extension is loaded. Per-source driver availability is re-checked at query
	 * time so a schema bound to an absent driver degrades to an empty list.
	 *
	 * @return bool True when the provider can serve at least one driver.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function isEnabled(): bool {
		if (class_exists(\Doctrine\DBAL\DriverManager::class) === false) {
			return false;
		}

		foreach (DbalConnectionFactory::SUPPORTED_DRIVERS as $driver) {
			if ($this->connectionFactory->isDriverAvailable(driver: $driver) === true) {
				return true;
			}
		}

		return false;
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * Returns null when the object is absent, the schema is list-only (no id), or
	 * the driver extension is missing — indistinguishably (no enumeration oracle).
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string $id The object id / source key.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied/list-only.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function find(SourcedTable $source, string $id): ?ObjectEntity {
		return $this->findObject(ref: $this->ref(source: $source, id: $id));
	}//end find()

	/**
	 * The DBAL reference of a sourced table, optionally addressing one object.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string|null  $id     The object id, or null for the whole table.
	 *
	 * @return DbalObjectRef The reference.
	 */
	private function ref(SourcedTable $source, ?string $id=null): DbalObjectRef {
		return new DbalObjectRef(register: $source->table->register, schema: $source->table->schema, config: $source->config, id: $id);
	}//end ref()

	/**
	 * Read the one object a reference addresses.
	 *
	 * @param DbalObjectRef $ref The object reference, with its id.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/unreachable/list-only.
	 */
	private function findObject(DbalObjectRef $ref): ?ObjectEntity {
		$idColumns = $this->idColumns(config: $ref->config);
		if ($idColumns === []) {
			// List-only schema (no primary key): no stable object identity.
			return null;
		}

		$target = $this->openForFind(ref: $ref);
		if ($target === null) {
			return null;
		}

		$row = $this->findRow(target: $target);
		if ($row === false) {
			return null;
		}

		return $this->toObjectEntity(ref: $ref, row: $row);
	}//end findObject()

	/**
	 * Resolve the source and connect for a single-object read.
	 *
	 * @param DbalObjectRef $ref The object reference.
	 *
	 * @return DbalTarget|null The opened table, or null when unresolvable/unreachable.
	 */
	private function openForFind(DbalObjectRef $ref): ?DbalTarget {
		$context = $this->resolveContext(config: $ref->config, schema: $ref->schema);
		if ($context === null) {
			return null;
		}

		[$source, $columns] = $context;

		$connection = $this->connectForFind(source: $source);
		if ($connection === null) {
			return null;
		}

		return new DbalTarget(connection: $connection, ref: $ref, columns: $columns);
	}//end openForFind()

	/**
	 * Connect for a single-object read; an unreachable database finds nothing.
	 *
	 * @param Source $source The backing database source.
	 *
	 * @return Connection|null The connection, or null when it cannot be opened.
	 */
	private function connectForFind(Source $source): ?Connection {
		try {
			return $this->connect(source: $source);
		} catch (DbalConnectionException $e) {
			$this->logger->warning('[ObjectSource:dbal-source] connection unavailable for find: ' . $e->getMessage());
			return null;
		}
	}//end connectForFind()

	/**
	 * Read the one row whose key matches the reference's object id.
	 *
	 * @param DbalTarget $target The opened table; its reference carries the id
	 *                           (composite ids are separator-joined).
	 *
	 * @return array<string, mixed>|false The row, or false when none matches.
	 *
	 * @throws DbalObjectSourceException When the query fails (502).
	 */
	private function findRow(DbalTarget $target): array|false {
		try {
			$qb = $this->baseSelect(target: $target);
			$pairs = self::idPairs(idColumns: $this->idColumns(config: $target->ref->config), id: (string)$target->ref->id);
			(new DbalStatement(qb: $qb, connection: $target->connection))->andEquals(pairs: $pairs);
			$qb->setMaxResults(1);

			return $qb->executeQuery()->fetchAssociative();
		} catch (DbalException $e) {
			$this->logger->warning('[ObjectSource:dbal-source] query failed for find: ' . $e->getMessage());
			throw new DbalObjectSourceException('The external database returned an error.', 502, $e);
		}
	}//end findRow()

	/**
	 * {@inheritDoc}
	 *
	 * Applies filters/`_search`/sort as bound parameters and limit/offset in SQL.
	 * A missing driver extension degrades to an empty list; a connection/query
	 * error surfaces a {@see DbalObjectSourceException} (503/502), never a 500.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $query Query (filters/search/sort/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects (possibly empty).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function findAll(SourcedTable $source, array $query = []): array {
		return $this->listObjects(ref: $this->ref(source: $source), query: $query);
	}//end findAll()

	/**
	 * Read one page of the objects a reference's schema projects.
	 *
	 * @param DbalObjectRef        $ref   The object reference.
	 * @param array<string, mixed> $query Query (filters/search/sort/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects (possibly empty).
	 */
	private function listObjects(DbalObjectRef $ref, array $query): array {
		$context = $this->resolveContext(config: $ref->config, schema: $ref->schema);
		if ($context === null) {
			return [];
		}

		[$source, $columns] = $context;

		$connection = $this->connectForList(source: $source);
		if ($connection === null) {
			return [];
		}

		$rows = $this->listRows(target: new DbalTarget(connection: $connection, ref: $ref, columns: $columns), query: $query);

		$objects = [];
		foreach ($rows as $row) {
			$objects[] = $this->toObjectEntity(ref: $ref, row: $row);
		}

		return $objects;
	}//end listObjects()

	/**
	 * Connect for a list read; a missing driver extension lists nothing.
	 *
	 * @param Source $source The backing database source.
	 *
	 * @return Connection|null The connection, or null when the driver is missing.
	 *
	 * @throws DbalObjectSourceException When the external database is unreachable (503).
	 */
	private function connectForList(Source $source): ?Connection {
		try {
			return $this->connect(source: $source);
		} catch (DbalConnectionException $e) {
			if ($this->driverMissing(source: $source) === true) {
				$this->logger->warning('[ObjectSource:dbal-source] driver unavailable — returning empty list: ' . $e->getMessage());
				return null;
			}

			throw new DbalObjectSourceException('The external database is unreachable.', 503, $e);
		}
	}//end connectForList()

	/**
	 * Read one page of filtered, sorted rows.
	 *
	 * @param DbalTarget           $target The opened table.
	 * @param array<string, mixed> $query  Query (filters/search/sort/limit/offset).
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws DbalObjectSourceException When the query fails (502).
	 */
	private function listRows(DbalTarget $target, array $query): array {
		try {
			$qb = $this->baseSelect(target: $target);
			$clauses = $this->listClauses(target: $target, qb: $qb);
			$clauses->filter(query: $query);
			$clauses->sort(query: $query, columns: $target->columns);

			$limit = $this->limit(query: $query);
			$offset = max(0, (int)($query['offset'] ?? 0));
			$qb->setMaxResults($limit);
			$qb->setFirstResult($offset);

			return $qb->executeQuery()->fetchAllAssociative();
		} catch (DbalException $e) {
			$this->logger->warning('[ObjectSource:dbal-source] query failed for findAll: ' . $e->getMessage());
			throw new DbalObjectSourceException('The external database returned an error.', 502, $e);
		}//end try
	}//end listRows()

	/**
	 * The filter, search and sort clauses of one statement on an opened table.
	 *
	 * @param DbalTarget   $target The opened table.
	 * @param QueryBuilder $qb     The statement's query builder.
	 *
	 * @return DbalListClauses
	 */
	private function listClauses(DbalTarget $target, QueryBuilder $qb): DbalListClauses {
		return new DbalListClauses(
			statement: new DbalStatement(qb: $qb, connection: $target->connection),
			filterable: $this->filterableColumns(columns: $target->columns, config: $target->ref->config)
		);
	}//end listClauses()

	/**
	 * {@inheritDoc}
	 *
	 * Issues `SELECT COUNT(*)` with the same WHERE predicate as findAll(), so the
	 * pagination dispatch can report a true total.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $query Query (filters/search).
	 *
	 * @return int The number of matching virtual objects.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function count(SourcedTable $source, array $query = []): int {
		return $this->countObjects(ref: $this->ref(source: $source), query: $query);
	}//end count()

	/**
	 * Count the objects a reference's schema projects; a missing driver counts nothing.
	 *
	 * @param DbalObjectRef        $ref   The object reference.
	 * @param array<string, mixed> $query Query (filters/search).
	 *
	 * @return int The number of matching virtual objects.
	 *
	 * @throws DbalObjectSourceException On an unreachable DB (503) or query error (502).
	 */
	private function countObjects(DbalObjectRef $ref, array $query): int {
		$context = $this->resolveContext(config: $ref->config, schema: $ref->schema);
		if ($context === null) {
			return 0;
		}

		[$source, $columns] = $context;

		$connection = $this->connectUnlessDriverMissing(source: $source);
		if ($connection === null) {
			return 0;
		}

		return $this->countRows(target: new DbalTarget(connection: $connection, ref: $ref, columns: $columns), query: $query);
	}//end countObjects()

	/**
	 * `SELECT COUNT(*)` with the list's WHERE predicate.
	 *
	 * @param DbalTarget           $target The opened table.
	 * @param array<string, mixed> $query  Query (filters/search).
	 *
	 * @return int The number of matching rows.
	 *
	 * @throws DbalObjectSourceException When the query fails (502).
	 */
	private function countRows(DbalTarget $target, array $query): int {
		try {
			$qb = $target->connection->createQueryBuilder();
			$qb->select('COUNT(*)')->from($this->fromExpression(connection: $target->connection, config: $target->ref->config));
			$this->listClauses(target: $target, qb: $qb)->filter(query: $query);

			$total = $qb->executeQuery()->fetchOne();
		} catch (DbalException $e) {
			$this->logger->warning('[ObjectSource:dbal-source] query failed for count: ' . $e->getMessage());
			throw new DbalObjectSourceException('The external database returned an error.', 502, $e);
		}

		return (int)$total;
	}//end countRows()

	/**
	 * Compute an aggregation live against the external table.
	 *
	 * The aggregation-endpoint counterpart to {@see count()} / {@see findAll()}:
	 * dashboard KPI / stats-block + chart widgets hit
	 * `/api/objects/aggregations/{register}/{schema}/value|timeseries|grouped`,
	 * which {@see \OCA\OpenRegister\Service\Aggregation\AggregationRunner} routes
	 * here when the schema is `dbal-source`-backed, so the numbers are computed
	 * in the external database rather than over the (empty for a virtual
	 * register) magic table.
	 *
	 * Returns `{value: ...}` for a scalar metric, `{groups: [{key, value}, ...]}`
	 * for a categorical `groupBy` or a `dateBucket` series, or `null` to signal
	 * the caller to fall back (unsupported metric / field / platform / gap).
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param string $metric count/sum/avg/min/max.
	 * @param string|null $field Metric field (required for sum/avg/min/max).
	 * @param array<string, mixed> $filter Filter map ({field: scalar} or {field: {op: value}}).
	 * @param array<string, mixed>|null $groupBy Optional categorical group spec ({field}).
	 * @param array<string, mixed>|null $dateBucket Optional date-bucket spec ({field, start, end, gap}).
	 * @param array<string, mixed> $config The object-source `config` block.
	 *
	 * @return array{value: int|float|null}|array{groups: array<int, array{key: mixed, value: int|float|null}>}|null
	 *
	 * @throws DbalObjectSourceException On an unreachable DB (503) or query error (502).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function aggregate(
		Register $register, Schema $schema, string $metric, ?string $field, array $filter, ?array $groupBy, ?array $dateBucket, array $config=[]
	): ?array {
		$shape   = new DbalAggregationShape(groupBy: $groupBy, dateBucket: $dateBucket);
		$request = new DbalMetricRequest(metric: $metric, field: $field, filter: $filter, shape: $shape);
		return $this->aggregateMetric(ref: new DbalObjectRef(register: $register, schema: $schema, config: $config), request: $request);
	}//end aggregate()

	/**
	 * Compute one metric live against the external table a reference's schema projects.
	 *
	 * @param DbalObjectRef     $ref     The register, schema and dbal-source configuration.
	 * @param DbalMetricRequest $request The metric, field, filter and result shape.
	 *
	 * @return array{value: int|float|null}|array{groups: array<int, array{key: mixed, value: int|float|null}>}|null
	 *
	 * @throws DbalObjectSourceException On an unreachable DB (503) or query error (502).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function aggregateMetric(DbalObjectRef $ref, DbalMetricRequest $request): ?array {
		if (in_array($request->metric, ['count', 'sum', 'avg', 'min', 'max'], true) === false) {
			return null;
		}

		$context = $this->resolveContext(config: $ref->config, schema: $ref->schema);
		if ($context === null) {
			return null;
		}

		$aggregation = new DbalAggregation(metric: $request->metric, field: $request->field, filter: $request->filter, columns: $context[1]);
		$connection  = $this->aggregationConnection(source: $context[0], aggregation: $aggregation);
		if ($connection === null) {
			return null;
		}

		$table = $this->fromExpression(connection: $connection, config: $ref->config);

		$query = $this->aggregator->query(from: new DbalFromClause(connection: $connection, table: $table), aggregation: $aggregation);

		return $this->aggregator->run(query: $query, shape: $request->shape);
	}//end aggregateMetric()

	/**
	 * The connection an aggregation runs on, once its value field is known to be a column.
	 *
	 * The value field is checked before connecting, so a misconfigured field
	 * answers null without touching the external database.
	 *
	 * @param Source          $source      The backing database source.
	 * @param DbalAggregation $aggregation The metric, field, filter and columns.
	 *
	 * @return Connection|null Null when the field is not a column or the driver is missing.
	 *
	 * @throws DbalObjectSourceException When the external database is unreachable.
	 */
	private function aggregationConnection(Source $source, DbalAggregation $aggregation): ?Connection {
		if ($aggregation->lacksValueField() === true) {
			return null;
		}

		return $this->connectUnlessDriverMissing(source: $source);
	}//end aggregationConnection()

	/**
	 * Open the connection an aggregation runs on, or null when the source's driver is not installed.
	 *
	 * @param Source $source The backing source.
	 *
	 * @return Connection|null The connection, or null so the caller falls back.
	 *
	 * @throws DbalObjectSourceException When the database is unreachable (503).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function connectUnlessDriverMissing(Source $source): ?Connection {
		try {
			return $this->connect(source: $source);
		} catch (DbalConnectionException $e) {
			if ($this->driverMissing(source: $source) === true) {
				return null;
			}

			throw new DbalObjectSourceException('The external database is unreachable.', 503, $e);
		}
	}//end connectUnlessDriverMissing()

	/**
	 * Resolve the backing source and the allowlisted scalar column set.
	 *
	 * @param array<string, mixed> $config The object-source config block.
	 * @param Schema $schema The sourced schema.
	 *
	 * @return array{0: Source, 1: array<int, string>}|null [source, columns] or null when unresolvable.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function resolveContext(array $config, Schema $schema): ?array {
		$source = $this->resolveSource(config: $config);
		if ($source === null) {
			$this->logger->warning('[ObjectSource:dbal-source] no source resolved for schema ' . (string)$schema->getSlug());
			return null;
		}

		return [$source, $this->scalarColumns(schema: $schema)];
	}//end resolveContext()

	/**
	 * Load the backing database source referenced by the config `sourceId`.
	 *
	 * A `Source` referenced this way is a SYSTEM capability lookup, not a
	 * tenant-owned read: `sourceId` names shared infrastructure config (a
	 * database connection) that a schema's own `x-openregister-object-source`
	 * declares, and the schema's read RBAC is already enforced by
	 * `ObjectService::paginateObjectSource()` before this provider ever runs.
	 * Resolving the Source row itself via `SourceMapperInterface::findForSystem()`
	 * deliberately skips the caller's active-organisation filter — scoping the
	 * Source row by the reader's org adds no isolation (the caller never sees
	 * the Source, only the objects it serves for a schema they were already
	 * cleared to read) and previously broke every dbal-backed schema whose
	 * Source lived in a different organisation, most visibly under
	 * `saasMode: true` (openregister#2089).
	 *
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return Source|null The source, or null when not found / not a database source.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 * @spec openspec/changes/dbal-source-resolution-system-context/specs/dbal-source-resolution-system-context/spec.md
	 */
	private function resolveSource(array $config): ?Source {
		$sourceId = (string)($config['sourceId'] ?? '');
		if ($sourceId === '') {
			return null;
		}

		try {
			$source = $this->sourceMapper->findForSystem(sourceId: $sourceId);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:dbal-source] could not load source "' . $sourceId . '": ' . $e->getMessage());
			return null;
		}

		if (self::isDatabaseSource(source: $source) === false) {
			return null;
		}

		return $source;
	}//end resolveSource()

	/**
	 * Whether a loaded source is a database source.
	 *
	 * @param Source|null $source The loaded source.
	 *
	 * @return bool
	 */
	private static function isDatabaseSource(?Source $source): bool {
		return $source !== null && (string)$source->getType() === 'database';
	}//end isDatabaseSource()

	/**
	 * Open the read-only DBAL connection for a source.
	 *
	 * @param Source $source The backing source.
	 *
	 * @return Connection The connection.
	 *
	 * @throws DbalConnectionException On any connection/credential error (fail closed).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function connect(Source $source): Connection {
		return $this->connectionFactory->getConnection(source: $source);
	}//end connect()

	/**
	 * Whether the source's configured driver extension is absent on this instance.
	 *
	 * @param Source $source The backing source.
	 *
	 * @return bool True when the driver extension is missing.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function driverMissing(Source $source): bool {
		$config = ($source->getAuthConfig() ?? []);
		$driver = (string)($config['driver'] ?? '');
		return ($this->connectionFactory->isDriverAvailable(driver: $driver) === false);
	}//end driverMissing()

	/**
	 * Build a `SELECT <cols> FROM <source>` query with quoted identifiers.
	 *
	 * The FROM source is a quoted base table, or a `(<query>) src` derived table
	 * for a query-backed schema (see {@see fromExpression()}).
	 *
	 * @param DbalTarget $target The opened table; its scalar columns are selected.
	 *
	 * @return QueryBuilder The query builder.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function baseSelect(DbalTarget $target): QueryBuilder {
		$connection = $target->connection;
		$qb = $connection->createQueryBuilder();

		$selects = [];
		foreach ($target->columns as $column) {
			$selects[] = $this->quote(connection: $connection, identifier: $column);
		}

		if ($selects === []) {
			$selects = ['*'];
		}

		$qb->select(...$selects)->from($this->fromExpression(connection: $connection, config: $target->ref->config));

		return $qb;
	}//end baseSelect()

	/**
	 * The column and value pairs that select a single object by its id.
	 *
	 * A composite id is split on the separator; a missing part matches the empty string.
	 *
	 * @param array<int, string> $idColumns The id column(s) (single or composite).
	 * @param string             $id        The object id (composite ids are separator-joined).
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs, in column order.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private static function idPairs(array $idColumns, string $id): array {
		if (count($idColumns) === 1) {
			return [[$idColumns[0], $id]];
		}

		$parts = explode(DatabaseIntrospectionService::COMPOSITE_ID_SEPARATOR, $id);
		$pairs = [];
		foreach ($idColumns as $index => $column) {
			$pairs[] = [$column, ($parts[$index] ?? '')];
		}

		return $pairs;
	}//end idPairs()

	/**
	 * Map a fetched row onto a non-persisted virtual ObjectEntity.
	 *
	 * @param DbalObjectRef        $ref The register, schema and config the row belongs to.
	 * @param array<string, mixed> $row The fetched row.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function toObjectEntity(DbalObjectRef $ref, array $row): ObjectEntity {
		$id = $this->rowId(row: $row, config: $ref->config);

		$data = $row;
		if ($id !== null) {
			$data['id'] = $id;
		}

		$entity = new ObjectEntity();
		if ($id !== null) {
			$entity->setUuid($id);
		}

		$entity->setRegister((string)$ref->register->getId());
		$entity->setSchema((string)$ref->schema->getId());
		$entity->setObject($data);

		return $entity;
	}//end toObjectEntity()

	/**
	 * Derive the object id from a row per the config id shape.
	 *
	 * @param array<string, mixed> $row The fetched row.
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return string|null The id (single value or separator-joined composite), or null when list-only.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function rowId(array $row, array $config): ?string {
		$idColumns = $this->idColumns(config: $config);
		if ($idColumns === []) {
			return null;
		}

		$parts = [];
		foreach ($idColumns as $column) {
			$parts[] = (string)($row[$column] ?? '');
		}

		return implode(DatabaseIntrospectionService::COMPOSITE_ID_SEPARATOR, $parts);
	}//end rowId()

	/**
	 * The ordered id column list from config (single, composite, or empty).
	 *
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return array<int, string> The id columns (empty for a list-only schema).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function idColumns(array $config): array {
		$idColumn = ($config['idColumn'] ?? null);
		if (is_string($idColumn) === true && $idColumn !== '') {
			return [$idColumn];
		}

		$idColumns = ($config['idColumns'] ?? null);
		if (is_array($idColumns) === true && $idColumns !== []) {
			return array_values(array_map('strval', $idColumns));
		}

		return [];
	}//end idColumns()

	/**
	 * The backing table name from config.
	 *
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return string The table name.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function table(array $config): string {
		return (string)($config['table'] ?? '');
	}//end table()

	/**
	 * Whether the schema is backed by a query definition (a virtual "view of
	 * tables" authored in OpenRegister config) rather than a single base table.
	 *
	 * When `config.query` holds a non-empty SELECT, the provider reads from it
	 * as a derived table — `FROM (<query>) src` — so a join/projection over the
	 * source's tables is exposed as a flat, read-only schema. Filters, sort,
	 * pagination and aggregation push down onto the derived table exactly as for
	 * a base table.
	 *
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return bool True when a query definition is present.
	 */
	private function isQueryBacked(array $config): bool {
		$query = ($config['query'] ?? null);
		return (is_string($query) === true && trim($query) !== '');
	}//end isQueryBacked()

	/**
	 * The FROM expression for a read query: a quoted base-table identifier, or a
	 * `(<query>) src` derived table when the schema is query-backed.
	 *
	 * The query definition is emitted verbatim as a sub-select (DBAL's
	 * QueryBuilder::from() does not quote a raw expression), so it MUST be
	 * trusted config authored by an administrator — never request input. It is
	 * read-only: writes are rejected in {@see refuseReadOnlySchema()}.
	 *
	 * @param Connection $connection The DBAL connection.
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return string The FROM expression (quoted table, or aliased sub-select).
	 */
	private function fromExpression(Connection $connection, array $config): string {
		if ($this->isQueryBacked(config: $config) === true) {
			return '(' . trim((string)$config['query']) . ') ' . self::DERIVED_ALIAS;
		}

		return $this->quote(connection: $connection, identifier: $this->table(config: $config));
	}//end fromExpression()

	/**
	 * The scalar (non-relation) columns of a schema — the query allowlist.
	 *
	 * Relation and inverse properties (arrays, or object properties carrying a
	 * `$ref`) are not real columns and are excluded from SELECT/WHERE.
	 *
	 * @param Schema $schema The sourced schema.
	 *
	 * @return array<int, string> The scalar column names.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function scalarColumns(Schema $schema): array {
		$columns = [];
		foreach ($schema->getProperties() as $name => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$type = ($definition['type'] ?? 'string');
			if ($type === 'array' || isset($definition['items']) === true) {
				continue;
			}

			$columns[] = (string)$name;
		}

		return $columns;
	}//end scalarColumns()

	/**
	 * The columns that may appear in a WHERE predicate (allowlist minus
	 * non-filterable columns declared at introspection).
	 *
	 * @param array<int, string> $columns The scalar columns.
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return array<int, string> The filterable columns.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function filterableColumns(array $columns, array $config): array {
		$nonFilterable = ($config['nonFilterable'] ?? []);
		if (is_array($nonFilterable) === false || $nonFilterable === []) {
			return $columns;
		}

		return array_values(array_diff($columns, $nonFilterable));
	}//end filterableColumns()

	/**
	 * The effective row limit for a findAll(), clamped to the hard cap.
	 *
	 * @param array<string, mixed> $query The OR query.
	 *
	 * @return int The clamped limit.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function limit(array $query): ?int {
		// An ABSENT limit still takes this provider's default. That is the one
		// place the default belongs: these queries run against a FOREIGN
		// database over which this app has no operational control, so a caller
		// that says nothing gets a bounded page rather than that database's
		// entire table.
		if (array_key_exists('limit', $query) === false) {
			return self::DEFAULT_LIMIT;
		}

		// An EXPLICIT unlimited is honoured, and is no longer rewritten into
		// the default. This is the only way past the bound, and it has to be
		// asked for by name.
		$limit = QueryLimit::normalise(limit: $query['limit']);
		if ($limit === null) {
			return null;
		}

		// A NUMERIC limit is still capped. The cap is a deliberate protection
		// (objects-crud: "List page size is bounded by a hard maximum") and it
		// matters more here than anywhere else: this provider queries a FOREIGN
		// database, so an accidental `limit=1000000` spends someone else's
		// resources.
		return min($limit, self::MAX_RESULTS);
	}//end limit()

	/**
	 * Quote a SQL identifier through the connection's platform.
	 *
	 * @param Connection $connection The DBAL connection.
	 * @param string $identifier The raw identifier.
	 *
	 * @return string The quoted identifier.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function quote(Connection $connection, string $identifier): string {
		return $connection->getDatabasePlatform()->quoteIdentifier($identifier);
	}//end quote()

	/**
	 * {@inheritDoc}
	 *
	 * Executes a parameterized INSERT restricted to introspected scalar columns.
	 * The generated single-column primary key is retrieved via `RETURNING` on
	 * PostgreSQL and `lastInsertId()` elsewhere; the created row is re-read so
	 * the response reflects database-applied defaults. No-PK tables accept
	 * inserts (append-only) and return the payload as the entity body.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $data The validated object data.
	 *
	 * @return ObjectEntity The created virtual object.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function insert(SourcedTable $source, array $data): ObjectEntity {
		return $this->insertObject(ref: $this->ref(source: $source), data: $data);
	}//end insert()

	/**
	 * Insert one row for a reference and answer the created object.
	 *
	 * @param DbalObjectRef        $ref  The object reference (no id: the insert makes one).
	 * @param array<string, mixed> $data The validated object data.
	 *
	 * @return ObjectEntity The created virtual object.
	 *
	 * @throws \RuntimeException When the source is not writable (read-only rejection).
	 * @throws DbalWriteException On a refused payload or a driver error.
	 */
	private function insertObject(DbalObjectRef $ref, array $data): ObjectEntity {
		$target = $this->openForWrite(ref: $ref);

		$values = $this->writeValues(data: $data, columns: $target->columns);

		try {
			$newId = $this->executeInsert(target: $target, values: $values);
		} catch (DbalException $e) {
			throw $this->translateWriteError(exception: $e);
		}

		if ($newId !== null) {
			$created = $this->findObject(ref: $ref->withId(id: $newId));
			if ($created !== null) {
				return $created;
			}
		}

		// No-PK (append-only) tables — or a re-read miss — echo the written values.
		return $this->toObjectEntity(ref: $ref, row: $values);
	}//end insertObject()

	/**
	 * {@inheritDoc}
	 *
	 * Executes a parameterized UPDATE whose predicate is the (possibly
	 * composite) primary key reconstructed from the object id. Primary-key
	 * columns cannot be changed (400). Zero affected rows surfaces as the same
	 * 404 an absent native object produces.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string $id The object id (external key).
	 * @param array<string, mixed> $data The validated object data.
	 *
	 * @return ObjectEntity The updated virtual object as re-read.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no external row matches the id.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function update(SourcedTable $source, string $id, array $data): ObjectEntity {
		return $this->updateObject(ref: $this->ref(source: $source, id: $id), data: $data);
	}//end update()

	/**
	 * Update the row a reference addresses and answer it as re-read.
	 *
	 * @param DbalObjectRef        $ref  The object reference, with its id.
	 * @param array<string, mixed> $data The validated object data.
	 *
	 * @return ObjectEntity The updated virtual object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no external row matches the id.
	 */
	private function updateObject(DbalObjectRef $ref, array $data): ObjectEntity {
		$id     = (string)$ref->id;
		$target = $this->openForRowWrite(ref: $ref);

		$values   = $this->updateValues(data: $data, target: $target);
		$affected = $this->executeUpdate(target: $target, values: $values);

		if ($affected === 0) {
			throw new DoesNotExistException('No external row matches id ' . $id);
		}

		$updated = $this->findObject(ref: $ref);
		if ($updated !== null) {
			return $updated;
		}

		return $this->toObjectEntity(ref: $ref, row: array_merge($values, ['id' => $id]));
	}//end updateObject()

	/**
	 * The column values an update writes: the payload minus the key columns, never empty.
	 *
	 * @param array<string, mixed> $data   The validated object data.
	 * @param DbalTarget           $target The opened table (scalar columns and key columns).
	 *
	 * @return array<string, mixed> The column => value map to write.
	 *
	 * @throws DbalWriteException When the payload names a non-column property or nothing writable (400).
	 */
	private function updateValues(array $data, DbalTarget $target): array {
		$values = $this->writeValues(data: $data, columns: $target->columns);

		// Primary-key columns are the row's identity — never updatable.
		foreach ($this->idColumns(config: $target->ref->config) as $idColumn) {
			unset($values[$idColumn]);
		}

		if ($values === []) {
			throw new DbalWriteException('The update contains no writable columns.', 400);
		}

		return $values;
	}//end updateValues()

	/**
	 * Execute the key-predicated UPDATE and return the affected row count.
	 *
	 * @param DbalTarget           $target The opened table; its reference carries the id.
	 * @param array<string, mixed> $values The column => value map.
	 *
	 * @return int The affected row count.
	 *
	 * @throws DbalWriteException On a malformed composite id or a driver error.
	 */
	private function executeUpdate(DbalTarget $target, array $values): int {
		$connection = $target->connection;
		$config     = $target->ref->config;
		try {
			$qb = $connection->createQueryBuilder()->update($this->quote(connection: $connection, identifier: $this->table(config: $config)));
			foreach ($values as $column => $value) {
				$qb->set($this->quote(connection: $connection, identifier: $column), $qb->createNamedParameter($value));
			}

			$this->whereRowId(qb: $qb, target: $target);
			return (int)$qb->executeStatement();
		} catch (DbalException $e) {
			throw $this->translateWriteError(exception: $e);
		}
	}//end executeUpdate()

	/**
	 * Restrict a write statement to the row its target's reference addresses.
	 *
	 * @param QueryBuilder $qb     The UPDATE or DELETE builder (mutated).
	 * @param DbalTarget   $target The opened table; its reference carries the id.
	 *
	 * @return void
	 *
	 * @throws DbalWriteException When the id does not match the composite key shape (400).
	 */
	private function whereRowId(QueryBuilder $qb, DbalTarget $target): void {
		$pairs = self::writeIdPairs(idColumns: $this->idColumns(config: $target->ref->config), id: (string)$target->ref->id);
		(new DbalStatement(qb: $qb, connection: $target->connection))->andEquals(pairs: $pairs);
	}//end whereRowId()

	/**
	 * {@inheritDoc}
	 *
	 * Executes a parameterized DELETE (hard delete — the external system has no
	 * OpenRegister soft-delete) with the primary-key predicate.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string $id The object id (external key).
	 *
	 * @return bool True when a row was deleted; false when no row matched.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function remove(SourcedTable $source, string $id): bool {
		return $this->removeObject(ref: $this->ref(source: $source, id: $id));
	}//end remove()

	/**
	 * Delete the row a reference addresses.
	 *
	 * @param DbalObjectRef $ref The object reference, with its id.
	 *
	 * @return bool True when a row was deleted; false when no row matched.
	 */
	private function removeObject(DbalObjectRef $ref): bool {
		$target     = $this->openForRowWrite(ref: $ref);
		$connection = $target->connection;

		try {
			$qb = $connection->createQueryBuilder()->delete($this->quote(connection: $connection, identifier: $this->table(config: $ref->config)));
			$this->whereRowId(qb: $qb, target: $target);
			$affected = (int)$qb->executeStatement();
		} catch (DbalException $e) {
			throw $this->translateWriteError(exception: $e);
		}

		return ($affected > 0);
	}//end removeObject()

	/**
	 * Open a table for an insert (design D2, fail closed).
	 *
	 * @param DbalObjectRef $ref The object reference.
	 *
	 * @return DbalTarget The writable table.
	 *
	 * @throws \RuntimeException When the schema or source is read-only.
	 * @throws DbalObjectSourceException When the external database is unreachable.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function openForWrite(DbalObjectRef $ref): DbalTarget {
		$this->refuseReadOnlySchema(ref: $ref);

		return $this->openWritable(ref: $ref);
	}//end openForWrite()

	/**
	 * Open a table for an id-addressed write (update/delete), design D2, fail closed.
	 *
	 * The read-only refusal comes first, then the keyless one, then the
	 * source checks: a keyless view is refused as read-only.
	 *
	 * @param DbalObjectRef $ref The object reference, with its id.
	 *
	 * @return DbalTarget The writable table.
	 *
	 * @throws \RuntimeException When the schema or source is read-only.
	 * @throws DbalWriteException When the table has no primary key (400).
	 * @throws DbalObjectSourceException When the external database is unreachable.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function openForRowWrite(DbalObjectRef $ref): DbalTarget {
		$this->refuseReadOnlySchema(ref: $ref);

		if ($this->idColumns(config: $ref->config) === []) {
			throw new DbalWriteException('This external table has no primary key; rows cannot be updated or deleted.', 400);
		}

		return $this->openWritable(ref: $ref);
	}//end openForRowWrite()

	/**
	 * Refuse a write on a view or a query-backed schema.
	 *
	 * @param DbalObjectRef $ref The object reference.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the schema is a view or query-backed.
	 */
	private function refuseReadOnlySchema(DbalObjectRef $ref): void {
		if (($ref->config['isView'] ?? false) === true || $this->isQueryBacked(config: $ref->config) === true) {
			throw new RuntimeException($this->readOnlyError(schema: $ref->schema));
		}
	}//end refuseReadOnlySchema()

	/**
	 * Resolve and verify the backing source AT WRITE TIME, then connect.
	 *
	 * The source must exist, be a database source, and currently have
	 * `authConfig.writable === true` — a stale schema annotation alone can
	 * never authorize a write.
	 *
	 * @param DbalObjectRef $ref The object reference.
	 *
	 * @return DbalTarget The writable table.
	 *
	 * @throws \RuntimeException When the source is not writable (read-only rejection).
	 * @throws DbalObjectSourceException When the external database is unreachable.
	 */
	private function openWritable(DbalObjectRef $ref): DbalTarget {
		$source = $this->resolveSource(config: $ref->config);
		if ($source === null) {
			// Fail closed: an unresolvable source can never authorize a write.
			throw new RuntimeException($this->readOnlyError(schema: $ref->schema));
		}

		$authConfig = ($source->getAuthConfig() ?? []);
		if (($authConfig['writable'] ?? false) !== true) {
			throw new RuntimeException($this->readOnlyError(schema: $ref->schema));
		}

		try {
			$connection = $this->connect(source: $source);
		} catch (DbalConnectionException $e) {
			throw new DbalObjectSourceException('The external database is unreachable.', 503, $e);
		}

		return new DbalTarget(connection: $connection, ref: $ref, columns: $this->scalarColumns(schema: $ref->schema));
	}//end openWritable()

	/**
	 * The read-only rejection message for a schema.
	 *
	 * @param Schema $schema The sourced schema.
	 *
	 * @return string
	 */
	private function readOnlyError(Schema $schema): string {
		return sprintf(
			'Schema "%s" is a read-only projection of object-source provider "%s"; writes are not allowed.',
			(string)$schema->getSlug(),
			$this->getId()
		);
	}//end readOnlyError()

	/**
	 * Restrict a write payload to introspected scalar columns.
	 *
	 * The synthetic `id` key and `@self` metadata are stripped; any remaining
	 * property that is not an introspected column is rejected — never silently
	 * dropped (consistent with validation strictness).
	 *
	 * @param array<string, mixed> $data The validated object data.
	 * @param array<int, string> $columns The scalar column allowlist.
	 *
	 * @return array<string, mixed> The column => value map to write.
	 *
	 * @throws DbalWriteException When the payload names a non-column property (400).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function writeValues(array $data, array $columns): array {
		unset($data['id'], $data['@self']);

		$values = [];
		foreach ($data as $property => $value) {
			$property = (string)$property;
			if (in_array($property, $columns, true) === false) {
				throw new DbalWriteException(
					sprintf('Property "%s" is not a column of the external table.', $property),
					400
				);
			}

			if ($value !== null && is_scalar($value) === false) {
				throw new DbalWriteException(
					sprintf('Property "%s" must be a scalar value.', $property),
					400
				);
			}

			$values[$property] = $value;
		}

		return $values;
	}//end writeValues()

	/**
	 * Execute the INSERT and return the new object id (or null for no-PK tables).
	 *
	 * @param DbalTarget           $target The opened table.
	 * @param array<string, mixed> $values The column => value map.
	 *
	 * @return string|null The new object id, or null when the table has no primary key.
	 *
	 * @throws DbalException On any driver error (translated by the caller).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function executeInsert(DbalTarget $target, array $values): ?string {
		$idColumns = $this->idColumns(config: $target->ref->config);

		// Composite key: every part must be supplied; the id is derived from the payload.
		if (count($idColumns) > 1) {
			return $this->insertComposite(target: $target, values: $values);
		}

		if (($idColumns[0] ?? null) === null) {
			$this->insertRow(target: $target, values: $values);
			return null;
		}

		return $this->insertKeyed(target: $target, values: $values);
	}//end executeInsert()

	/**
	 * Insert into a composite-key table; every key part must be in the payload.
	 *
	 * @param DbalTarget           $target The opened table.
	 * @param array<string, mixed> $values The column => value map.
	 *
	 * @return string The separator-joined object id.
	 *
	 * @throws DbalWriteException When a key part is missing (400).
	 * @throws DbalException On any driver error (translated by the caller).
	 */
	private function insertComposite(DbalTarget $target, array $values): string {
		$parts = [];
		foreach ($this->idColumns(config: $target->ref->config) as $idColumn) {
			if (isset($values[$idColumn]) === false) {
				throw new DbalWriteException(
					sprintf('Composite-key inserts must supply every key column; "%s" is missing.', $idColumn),
					400
				);
			}

			$parts[] = (string)$values[$idColumn];
		}

		$this->insertRow(target: $target, values: $values);
		return implode(DatabaseIntrospectionService::COMPOSITE_ID_SEPARATOR, $parts);
	}//end insertComposite()

	/**
	 * Insert into a single-key table and return the key, supplied or generated.
	 *
	 * @param DbalTarget           $target The opened single-key table.
	 * @param array<string, mixed> $values The column => value map.
	 *
	 * @return string The new object id.
	 *
	 * @throws DbalException On any driver error (translated by the caller).
	 */
	private function insertKeyed(DbalTarget $target, array $values): string {
		$idColumn = $this->idColumns(config: $target->ref->config)[0];

		// Caller-supplied primary key wins (no generation round-trip needed).
		if (isset($values[$idColumn]) === true) {
			$this->insertRow(target: $target, values: $values);
			return (string)$values[$idColumn];
		}

		// PostgreSQL: RETURNING is the only reliable generated-key retrieval.
		if ($target->connection->getDatabasePlatform() instanceof PostgreSQLPlatform === true) {
			return $this->insertReturning(target: $target, values: $values);
		}

		$this->insertRow(target: $target, values: $values);
		return (string)$target->connection->lastInsertId();
	}//end insertKeyed()

	/**
	 * Insert with `RETURNING` the generated key (PostgreSQL).
	 *
	 * @param DbalTarget           $target The opened single-key table.
	 * @param array<string, mixed> $values The column => value map.
	 *
	 * @return string The generated key.
	 *
	 * @throws DbalException On any driver error (translated by the caller).
	 */
	private function insertReturning(DbalTarget $target, array $values): string {
		$connection = $target->connection;
		$columnsSql = [];
		$params = [];
		foreach ($values as $column => $value) {
			$columnsSql[] = $this->quote(connection: $connection, identifier: $column);
			$params[] = $value;
		}

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
			$this->quote(connection: $connection, identifier: $this->table(config: $target->ref->config)),
			implode(', ', $columnsSql),
			implode(', ', array_fill(0, count($params), '?')),
			$this->quote(connection: $connection, identifier: $this->idColumns(config: $target->ref->config)[0])
		);

		$newId = $connection->fetchOne($sql, $params);
		return (string)$newId;
	}//end insertReturning()

	/**
	 * Insert one row with platform-quoted table and column names.
	 *
	 * @param DbalTarget           $target The opened table.
	 * @param array<string, mixed> $values The column => value map.
	 *
	 * @return void
	 *
	 * @throws DbalException On any driver error (translated by the caller).
	 */
	private function insertRow(DbalTarget $target, array $values): void {
		$connection = $target->connection;
		$connection->insert(
			$this->quote(connection: $connection, identifier: $this->table(config: $target->ref->config)),
			$this->quoteColumns(connection: $connection, values: $values)
		);
	}//end insertRow()

	/**
	 * Quote the column keys of a value map for Connection::insert().
	 *
	 * @param Connection $connection The DBAL connection.
	 * @param array<string, mixed> $values The column => value map.
	 *
	 * @return array<string, mixed> The map with platform-quoted column keys.
	 *
	 * @spec exclude Private quoting helper; behaviour covered by the insert/update tests.
	 */
	private function quoteColumns(Connection $connection, array $values): array {
		$quoted = [];
		foreach ($values as $column => $value) {
			$quoted[$this->quote(connection: $connection, identifier: (string)$column)] = $value;
		}

		return $quoted;
	}//end quoteColumns()

	/**
	 * The id pairs of a write, refusing a composite id whose part count differs from the key's.
	 *
	 * @param array<int, string> $idColumns The id column(s) (single or composite).
	 * @param string             $id        The object id (composite ids are separator-joined).
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs, in column order.
	 *
	 * @throws DbalWriteException When a composite id does not match the key's shape.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private static function writeIdPairs(array $idColumns, string $id): array {
		$parts = explode(DatabaseIntrospectionService::COMPOSITE_ID_SEPARATOR, $id);
		if (count($idColumns) !== 1 && count($parts) !== count($idColumns)) {
			throw new DbalWriteException('The object id does not match the table\'s composite key shape.', 400);
		}

		return self::idPairs(idColumns: $idColumns, id: $id);
	}//end writeIdPairs()

	/**
	 * Translate a DBAL write error into a sanitized, status-carrying exception.
	 *
	 * @param DbalException $exception The driver exception.
	 *
	 * @return DbalWriteException The sanitized exception (never contains SQL or secrets).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function translateWriteError(DbalException $exception): DbalWriteException {
		// Doctrine's typed constraint exceptions are platform-independent
		// (SQLite reports the generic SQLSTATE 23000 for every constraint
		// class); prefer them over raw SQLSTATE inspection. The generic
		// constraint class comes last: the others extend it.
		foreach (self::constraintErrors() as [$class, $message, $status]) {
			if ($exception instanceof $class === true) {
				return new DbalWriteException($message, $status, $exception);
			}
		}

		$sqlState = '';
		if ($exception instanceof DriverException === true) {
			$sqlState = (string)($exception->getSQLState() ?? '');
		}

		return DbalWriteException::fromSqlState(sqlState: $sqlState, previous: $exception);
	}//end translateWriteError()

	/**
	 * Typed constraint errors and the sanitized message and status each maps to.
	 *
	 * Checked in order: the generic constraint class is last because the others extend it.
	 *
	 * @return array<int, array{0: class-string, 1: string, 2: int}>
	 */
	private static function constraintErrors(): array {
		return [
			[\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class, 'A unique constraint on the external table was violated.', 409],
			[\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class, 'A foreign-key constraint on the external table was violated.', 409],
			[\Doctrine\DBAL\Exception\NotNullConstraintViolationException::class, 'A required (not-null) column on the external table was not provided.', 422],
			[\Doctrine\DBAL\Exception\ConstraintViolationException::class, 'A constraint on the external table was violated.', 422],
		];
	}//end constraintErrors()
}//end class
