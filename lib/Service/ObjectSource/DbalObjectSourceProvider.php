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
use Doctrine\DBAL\Query\QueryBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by an external SQL database.
 */
class DbalObjectSourceProvider implements ObjectSourceProvider
{
    /**
     * Hard cap on rows returned by a single findAll(), regardless of the query.
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
     * Constructor.
     *
     * @param SourceMapper          $sourceMapper      Loads the backing database source.
     * @param DbalConnectionFactory $connectionFactory Opens the read-only DBAL connection.
     * @param LoggerInterface       $logger            Secret-free diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly SourceMapper $sourceMapper,
        private readonly DbalConnectionFactory $connectionFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function getId(): string
    {
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
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function isEnabled(): bool
    {
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
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param string               $id       The object id / source key.
     * @param array<string, mixed> $config   The object-source `config` block.
     *
     * @return ObjectEntity|null The virtual object, or null when absent/denied/list-only.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
    {
        $idColumns = $this->idColumns(config: $config);
        if ($idColumns === []) {
            // List-only schema (no primary key): no stable object identity.
            return null;
        }

        $context = $this->resolveContext(config: $config, schema: $schema);
        if ($context === null) {
            return null;
        }

        [$source, $columns] = $context;

        try {
            $connection = $this->connect(source: $source);
        } catch (DbalConnectionException $e) {
            $this->logger->warning('[ObjectSource:dbal-source] connection unavailable for find: '.$e->getMessage());
            return null;
        }

        try {
            $qb = $this->baseSelect(connection: $connection, table: $this->table(config: $config), columns: $columns);
            $this->applyIdPredicate(qb: $qb, connection: $connection, idColumns: $idColumns, id: $id);
            $qb->setMaxResults(1);

            $row = $qb->executeQuery()->fetchAssociative();
        } catch (DbalException $e) {
            $this->logger->warning('[ObjectSource:dbal-source] query failed for find: '.$e->getMessage());
            throw new DbalObjectSourceException('The external database returned an error.', 502, $e);
        }

        if ($row === false) {
            return null;
        }

        return $this->toObjectEntity(register: $register, schema: $schema, row: $row, config: $config);
    }//end find()

    /**
     * {@inheritDoc}
     *
     * Applies filters/`_search`/sort as bound parameters and limit/offset in SQL.
     * A missing driver extension degrades to an empty list; a connection/query
     * error surfaces a {@see DbalObjectSourceException} (503/502), never a 500.
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/search/sort/limit/offset).
     * @param array<string, mixed> $config   The object-source `config` block.
     *
     * @return ObjectEntity[] The matching virtual objects (possibly empty).
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
    {
        $context = $this->resolveContext(config: $config, schema: $schema);
        if ($context === null) {
            return [];
        }

        [$source, $columns] = $context;

        try {
            $connection = $this->connect(source: $source);
        } catch (DbalConnectionException $e) {
            if ($this->driverMissing(source: $source) === true) {
                $this->logger->warning('[ObjectSource:dbal-source] driver unavailable — returning empty list: '.$e->getMessage());
                return [];
            }

            throw new DbalObjectSourceException('The external database is unreachable.', 503, $e);
        }

        try {
            $qb = $this->baseSelect(connection: $connection, table: $this->table(config: $config), columns: $columns);
            $this->applyFilters(qb: $qb, connection: $connection, query: $query, columns: $columns, config: $config);
            $this->applySort(qb: $qb, connection: $connection, query: $query, columns: $columns);

            $limit  = $this->limit(query: $query);
            $offset = max(0, (int) ($query['offset'] ?? 0));
            $qb->setMaxResults($limit);
            $qb->setFirstResult($offset);

            $rows = $qb->executeQuery()->fetchAllAssociative();
        } catch (DbalException $e) {
            $this->logger->warning('[ObjectSource:dbal-source] query failed for findAll: '.$e->getMessage());
            throw new DbalObjectSourceException('The external database returned an error.', 502, $e);
        }//end try

        $objects = [];
        foreach ($rows as $row) {
            $objects[] = $this->toObjectEntity(register: $register, schema: $schema, row: $row, config: $config);
        }

        return $objects;
    }//end findAll()

    /**
     * {@inheritDoc}
     *
     * Issues `SELECT COUNT(*)` with the same WHERE predicate as findAll(), so the
     * pagination dispatch can report a true total.
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/search).
     * @param array<string, mixed> $config   The object-source `config` block.
     *
     * @return int The number of matching virtual objects.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $register kept for interface parity.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
    {
        $context = $this->resolveContext(config: $config, schema: $schema);
        if ($context === null) {
            return 0;
        }

        [$source, $columns] = $context;

        try {
            $connection = $this->connect(source: $source);
        } catch (DbalConnectionException $e) {
            if ($this->driverMissing(source: $source) === true) {
                return 0;
            }

            throw new DbalObjectSourceException('The external database is unreachable.', 503, $e);
        }

        try {
            $qb = $connection->createQueryBuilder();
            $qb->select('COUNT(*)')->from($this->quote(connection: $connection, identifier: $this->table(config: $config)));
            $this->applyFilters(qb: $qb, connection: $connection, query: $query, columns: $columns, config: $config);

            $total = $qb->executeQuery()->fetchOne();
        } catch (DbalException $e) {
            $this->logger->warning('[ObjectSource:dbal-source] query failed for count: '.$e->getMessage());
            throw new DbalObjectSourceException('The external database returned an error.', 502, $e);
        }

        return (int) $total;
    }//end count()

    /**
     * Resolve the backing source and the allowlisted scalar column set.
     *
     * @param array<string, mixed> $config The object-source config block.
     * @param Schema               $schema The sourced schema.
     *
     * @return array{0: Source, 1: array<int, string>}|null [source, columns] or null when unresolvable.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function resolveContext(array $config, Schema $schema): ?array
    {
        $source = $this->resolveSource(config: $config);
        if ($source === null) {
            $this->logger->warning('[ObjectSource:dbal-source] no source resolved for schema '.(string) $schema->getSlug());
            return null;
        }

        return [$source, $this->scalarColumns(schema: $schema)];
    }//end resolveContext()

    /**
     * Load the backing database source referenced by the config `sourceId`.
     *
     * @param array<string, mixed> $config The object-source config block.
     *
     * @return Source|null The source, or null when not found / not a database source.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function resolveSource(array $config): ?Source
    {
        $sourceId = (string) ($config['sourceId'] ?? '');
        if ($sourceId === '') {
            return null;
        }

        try {
            if (ctype_digit($sourceId) === true) {
                $source = $this->sourceMapper->find(id: (int) $sourceId);
            } else {
                $matches = $this->sourceMapper->findAll(filters: ['uuid' => $sourceId]);
                $source  = ($matches[0] ?? null);
            }
        } catch (Throwable $e) {
            $this->logger->warning('[ObjectSource:dbal-source] could not load source "'.$sourceId.'": '.$e->getMessage());
            return null;
        }

        if ($source === null || (string) $source->getType() !== 'database') {
            return null;
        }

        return $source;
    }//end resolveSource()

    /**
     * Open the read-only DBAL connection for a source.
     *
     * @param Source $source The backing source.
     *
     * @return Connection The connection.
     *
     * @throws DbalConnectionException On any connection/credential error (fail closed).
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function connect(Source $source): Connection
    {
        return $this->connectionFactory->getConnection(source: $source);
    }//end connect()

    /**
     * Whether the source's configured driver extension is absent on this instance.
     *
     * @param Source $source The backing source.
     *
     * @return bool True when the driver extension is missing.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function driverMissing(Source $source): bool
    {
        $config = ($source->getAuthConfig() ?? []);
        $driver = (string) ($config['driver'] ?? '');
        return ($this->connectionFactory->isDriverAvailable(driver: $driver) === false);
    }//end driverMissing()

    /**
     * Build a `SELECT <cols> FROM <table>` query with quoted identifiers.
     *
     * @param Connection         $connection The DBAL connection.
     * @param string             $table      The backing table name.
     * @param array<int, string> $columns    The scalar columns to select.
     *
     * @return QueryBuilder The query builder.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function baseSelect(Connection $connection, string $table, array $columns): QueryBuilder
    {
        $qb = $connection->createQueryBuilder();

        $selects = [];
        foreach ($columns as $column) {
            $selects[] = $this->quote(connection: $connection, identifier: $column);
        }

        if ($selects === []) {
            $selects = ['*'];
        }

        $qb->select(...$selects)->from($this->quote(connection: $connection, identifier: $table));

        return $qb;
    }//end baseSelect()

    /**
     * Apply equality filters and a `_search` LIKE as bound parameters.
     *
     * @param QueryBuilder         $qb         The query builder (mutated).
     * @param Connection           $connection The DBAL connection.
     * @param array<string, mixed> $query      The OR query.
     * @param array<int, string>   $columns    The allowlisted scalar columns.
     * @param array<string, mixed> $config     The object-source config block.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function applyFilters(QueryBuilder $qb, Connection $connection, array $query, array $columns, array $config): void
    {
        $filterable = $this->filterableColumns(columns: $columns, config: $config);

        $filters = ($query['filters'] ?? []);
        if (is_array($filters) === true) {
            foreach ($filters as $column => $value) {
                if (is_string($column) === false || in_array($column, $filterable, true) === false) {
                    continue;
                }

                if (is_array($value) === true || is_object($value) === true) {
                    continue;
                }

                $qb->andWhere(
                    $this->quote(connection: $connection, identifier: $column).' = '.$qb->createNamedParameter($value)
                );
            }
        }

        $search = (string) ($query['_search'] ?? $query['search'] ?? '');
        if ($search !== '' && $filterable !== []) {
            $like  = '%'.$search.'%';
            $ors   = [];
            $param = $qb->createNamedParameter($like);
            foreach ($filterable as $column) {
                $ors[] = $this->quote(connection: $connection, identifier: $column).' LIKE '.$param;
            }

            $qb->andWhere('('.implode(' OR ', $ors).')');
        }
    }//end applyFilters()

    /**
     * Apply a validated sort column/direction.
     *
     * @param QueryBuilder         $qb         The query builder (mutated).
     * @param Connection           $connection The DBAL connection.
     * @param array<string, mixed> $query      The OR query.
     * @param array<int, string>   $columns    The allowlisted scalar columns.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function applySort(QueryBuilder $qb, Connection $connection, array $query, array $columns): void
    {
        $sort = ($query['sort'] ?? $query['_order'] ?? null);
        if (is_array($sort) === false || $sort === []) {
            return;
        }

        foreach ($sort as $column => $direction) {
            if (is_string($column) === false || in_array($column, $columns, true) === false) {
                continue;
            }

            $dir = (strtolower((string) $direction) === 'desc') ? 'DESC' : 'ASC';
            $qb->addOrderBy($this->quote(connection: $connection, identifier: $column), $dir);
        }
    }//end applySort()

    /**
     * Add the WHERE predicate that selects a single object by its id.
     *
     * @param QueryBuilder       $qb         The query builder (mutated).
     * @param Connection         $connection The DBAL connection.
     * @param array<int, string> $idColumns  The id column(s) (single or composite).
     * @param string             $id         The object id (composite ids are separator-joined).
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function applyIdPredicate(QueryBuilder $qb, Connection $connection, array $idColumns, string $id): void
    {
        if (count($idColumns) === 1) {
            $qb->andWhere(
                $this->quote(connection: $connection, identifier: $idColumns[0]).' = '.$qb->createNamedParameter($id)
            );
            return;
        }

        $parts = explode(DatabaseIntrospectionService::COMPOSITE_ID_SEPARATOR, $id);
        foreach ($idColumns as $index => $column) {
            $part = ($parts[$index] ?? '');
            $qb->andWhere(
                $this->quote(connection: $connection, identifier: $column).' = '.$qb->createNamedParameter($part)
            );
        }
    }//end applyIdPredicate()

    /**
     * Map a fetched row onto a non-persisted virtual ObjectEntity.
     *
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $row      The fetched row.
     * @param array<string, mixed> $config   The object-source config block.
     *
     * @return ObjectEntity The virtual object (never saved).
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function toObjectEntity(Register $register, Schema $schema, array $row, array $config): ObjectEntity
    {
        $id = $this->rowId(row: $row, config: $config);

        $data = $row;
        if ($id !== null) {
            $data['id'] = $id;
        }

        $entity = new ObjectEntity();
        if ($id !== null) {
            $entity->setUuid($id);
        }

        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($data);

        return $entity;
    }//end toObjectEntity()

    /**
     * Derive the object id from a row per the config id shape.
     *
     * @param array<string, mixed> $row    The fetched row.
     * @param array<string, mixed> $config The object-source config block.
     *
     * @return string|null The id (single value or separator-joined composite), or null when list-only.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function rowId(array $row, array $config): ?string
    {
        $idColumns = $this->idColumns(config: $config);
        if ($idColumns === []) {
            return null;
        }

        $parts = [];
        foreach ($idColumns as $column) {
            $parts[] = (string) ($row[$column] ?? '');
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
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function idColumns(array $config): array
    {
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
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function table(array $config): string
    {
        return (string) ($config['table'] ?? '');
    }//end table()

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
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function scalarColumns(Schema $schema): array
    {
        $columns = [];
        foreach ($schema->getProperties() as $name => $definition) {
            if (is_array($definition) === false) {
                continue;
            }

            $type = ($definition['type'] ?? 'string');
            if ($type === 'array' || isset($definition['items']) === true) {
                continue;
            }

            $columns[] = (string) $name;
        }

        return $columns;
    }//end scalarColumns()

    /**
     * The columns that may appear in a WHERE predicate (allowlist minus
     * non-filterable columns declared at introspection).
     *
     * @param array<int, string>   $columns The scalar columns.
     * @param array<string, mixed> $config  The object-source config block.
     *
     * @return array<int, string> The filterable columns.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function filterableColumns(array $columns, array $config): array
    {
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
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function limit(array $query): int
    {
        $limit = (int) ($query['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_RESULTS);
    }//end limit()

    /**
     * Quote a SQL identifier through the connection's platform.
     *
     * @param Connection $connection The DBAL connection.
     * @param string     $identifier The raw identifier.
     *
     * @return string The quoted identifier.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function quote(Connection $connection, string $identifier): string
    {
        return $connection->getDatabasePlatform()->quoteIdentifier($identifier);
    }//end quote()
}//end class
