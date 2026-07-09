<?php

/**
 * DatabaseIntrospectionService — turns a live DBAL connection for a `type:
 * database` Source into an OpenRegister Register plus one Schema per table/view.
 *
 * Uses DBAL's `AbstractSchemaManager` so dialect differences (MySQL / PostgreSQL
 * / SQLite) are normalised by the platform layer (design D3). Column types map
 * through {@see SqlTypeMapper}; a `NOT NULL` column that is neither the primary
 * key nor database-defaulted becomes `required`; single-column foreign keys map
 * onto the canonical relation dialect (`$ref` + `objectConfiguration.handling:
 * related-object`, plus an `inversedBy` reverse side) so `_extend` resolves them
 * unchanged (design D6). Each produced schema carries the
 * `x-openregister-object-source` annotation `{provider: 'dbal-source', config:
 * {sourceId, table, idColumn}}`. Re-introspection UPDATES the existing register
 * and schemas (matched by source id / table name) rather than duplicating them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
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

namespace OCA\OpenRegister\Service\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Introspects an external SQL database into an OpenRegister register + schemas.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Introspection inherently walks
 *   tables, views, columns, keys and relations in one cohesive pass; splitting it
 *   would scatter the D3-D7 design decisions across artificial helper classes.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Depends on the DBAL schema value
 *   objects plus the OR mapper/diff seams the design names explicitly.
 */
class DatabaseIntrospectionService
{
    /**
     * The object-source provider id every introspected schema is bound to.
     *
     * @var string
     */
    public const PROVIDER_ID = 'dbal-source';

    /**
     * Separator joining the parts of a synthesised composite-primary-key id.
     *
     * @var string
     */
    public const COMPOSITE_ID_SEPARATOR = '::';

    /**
     * Constructor.
     *
     * @param DbalConnectionFactory $connectionFactory Opens the DBAL connection for a source.
     * @param SqlTypeMapper         $typeMapper        SQL column → JSON-Schema mapping.
     * @param RegisterMapper        $registerMapper    Register persistence.
     * @param SchemaMapper          $schemaMapper      Schema persistence.
     * @param SchemaDiffService     $diffService       Classifies structural drift between runs.
     * @param LoggerInterface       $logger            Secret-free diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly DbalConnectionFactory $connectionFactory,
        private readonly SqlTypeMapper $typeMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly SchemaDiffService $diffService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the pure introspection blueprint for a source (no persistence).
     *
     * Returns a deterministic structure — `register` metadata plus a
     * table-name-ordered `schemas` list — suitable for golden-file assertions
     * and as the input to {@see introspect()}.
     *
     * @param Source $source The `type: database` source to introspect.
     *
     * @return array{register: array<string, mixed>, schemas: array<int, array<string, mixed>>}
     *
     * @throws DbalConnectionException When the source cannot be connected.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function buildBlueprint(Source $source): array
    {
        $sourceId      = $this->sourceId(source: $source);
        $connection    = $this->connectionFactory->getConnection(source: $source);
        $schemaManager = $connection->createSchemaManager();

        $tableNames = array_values(
            array_filter(
                $schemaManager->listTableNames(),
                fn (string $name): bool => $this->isSystemObject(name: $name) === false
            )
        );
        sort($tableNames);

        $viewNames = [];
        foreach ($schemaManager->listViews() as $view) {
            if ($this->isSystemObject(name: $view->getName()) === true) {
                continue;
            }

            $short = $this->shortName(name: $view->getName());
            if (in_array($short, $viewNames, true) === false) {
                $viewNames[] = $short;
            }
        }

        sort($viewNames);

        // First pass: introspect every table/view into a working record so the
        // second pass can resolve foreign-key targets and add inverse sides.
        $tables = [];
        foreach ($tableNames as $tableName) {
            $tables[$tableName] = $this->introspectTable(
                schemaManager: $schemaManager,
                sourceId: $sourceId,
                tableName: $tableName,
                isView: false
            );
        }

        foreach ($viewNames as $viewName) {
            if (isset($tables[$viewName]) === true) {
                continue;
            }

            $record = $this->introspectTable(
                schemaManager: $schemaManager,
                sourceId: $sourceId,
                tableName: $viewName,
                isView: true
            );

            // DBAL 3.x schema managers list columns for TABLES only; derive a
            // view's properties from a single sample row instead (portable
            // across mysql/pgsql/sqlite). An empty view yields no properties
            // but is still served list-only via SELECT *.
            if ($record['properties'] === []) {
                $record['properties'] = $this->viewColumnsFromSample(connection: $connection, viewName: $viewName);
            }

            $tables[$viewName] = $record;
        }//end foreach

        $slugByTable = [];
        foreach ($tables as $tableName => $record) {
            $slugByTable[$tableName] = $record['slug'];
        }

        // Second pass: map single-column foreign keys onto the relation dialect
        // and add the inverse side on the referenced schema.
        $this->applyForeignKeys(schemaManager: $schemaManager, tables: $tables, slugByTable: $slugByTable);

        $authConfig = ($source->getAuthConfig() ?? []);
        $writable   = (($authConfig['writable'] ?? false) === true);

        $schemas = [];
        foreach ($tables as $record) {
            $schemas[] = $this->toSchemaDefinition(record: $record, writable: $writable);
        }

        return [
            'register' => [
                'title'       => (string) ($source->getTitle() ?? ('Database '.$sourceId)),
                'slug'        => $this->slugify(value: 'db-'.$sourceId),
                'source'      => $sourceId,
                'description' => 'Virtual register introspected from database source '.$sourceId.'.',
            ],
            'schemas'  => $schemas,
        ];
    }//end buildBlueprint()

    /**
     * Introspect a source and persist the register + schemas idempotently.
     *
     * A second run over the same source updates the existing register and
     * schemas (matched by source id / table name) and logs structural drift via
     * {@see SchemaDiffService}; it never creates duplicates.
     *
     * @param Source $source The `type: database` source to introspect.
     *
     * @return array{register: int, created: array<int, string>, updated: array<int, string>, drift: array<int, array<string, mixed>>}
     *
     * @throws DbalConnectionException When the source cannot be connected.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function introspect(Source $source): array
    {
        $blueprint = $this->buildBlueprint(source: $source);
        $sourceId  = $this->sourceId(source: $source);

        $register        = $this->upsertRegister(definition: $blueprint['register'], sourceId: $sourceId);
        $existingSchemas = $this->existingSchemasByTable(register: $register);

        $created   = [];
        $updated   = [];
        $drift     = [];
        $schemaIds = [];

        foreach ($blueprint['schemas'] as $definition) {
            $table = $definition['configuration']['x-openregister-object-source']['config']['table'];

            if (isset($existingSchemas[$table]) === true) {
                $existing  = $existingSchemas[$table];
                $changeSet = $this->diffService->diff(
                    old: ['properties' => $existing->getProperties(), 'required' => $existing->getRequired()],
                    new: ['properties' => $definition['properties'], 'required' => ($definition['required'] ?? [])]
                );

                if ($changeSet->hasChanges() === true) {
                    $drift[] = ['table' => $table, 'changes' => $changeSet->getChanges()];
                    $this->logger->info(
                        sprintf('[DbalIntrospection] drift on table "%s": %d change(s)', $table, count($changeSet->getChanges()))
                    );
                }

                $saved       = $this->schemaMapper->updateFromArray(id: (int) $existing->getId(), object: $definition);
                $updated[]   = $table;
                $schemaIds[] = (int) $saved->getId();
                continue;
            }//end if

            $saved       = $this->schemaMapper->createFromArray(object: $definition);
            $created[]   = $table;
            $schemaIds[] = (int) $saved->getId();
        }//end foreach

        // Keep the register's schema-id list in sync with what we just wrote.
        $register->setSchemas(array_values(array_unique($schemaIds)));
        $this->registerMapper->update($register);

        return [
            'register' => (int) $register->getId(),
            'created'  => $created,
            'updated'  => $updated,
            'drift'    => $drift,
        ];
    }//end introspect()

    /**
     * Introspect one table/view into a working record.
     *
     * @param AbstractSchemaManager $schemaManager The schema manager.
     * @param string                $sourceId      The owning source id.
     * @param string                $tableName     The table/view name.
     * @param bool                  $isView        Whether this is a view.
     *
     * @return array<string, mixed> The working table record.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function introspectTable(AbstractSchemaManager $schemaManager, string $sourceId, string $tableName, bool $isView): array
    {
        $columns    = $schemaManager->listTableColumns($tableName);
        $primaryKey = $this->primaryKeyColumns(schemaManager: $schemaManager, tableName: $tableName, isView: $isView);

        $properties    = [];
        $required      = [];
        $nonFilterable = [];

        foreach ($columns as $column) {
            $name     = $column->getName();
            $fragment = $this->typeMapper->mapColumn(column: $column);

            if (($fragment['x-filterable'] ?? true) === false) {
                $nonFilterable[] = $name;
            }

            unset($fragment['x-filterable']);
            $properties[$name] = $fragment;

            if ($this->isRequired(column: $column, primaryKey: $primaryKey) === true) {
                $required[] = $name;
            }
        }

        [$idColumn, $idColumns] = $this->resolveIdentity(primaryKey: $primaryKey, tableName: $tableName);

        return [
            'table'         => $tableName,
            'slug'          => $this->slugify(value: $tableName),
            'title'         => $tableName,
            'isView'        => $isView,
            'idColumn'      => $idColumn,
            'idColumns'     => $idColumns,
            'properties'    => $properties,
            'required'      => $required,
            'nonFilterable' => $nonFilterable,
            'sourceId'      => $sourceId,
        ];
    }//end introspectTable()

    /**
     * Derive a view's properties from a single sample row.
     *
     * DBAL 3.x schema managers only introspect columns of real tables, so a
     * view's shape is read from `SELECT * … LIMIT 1` and typed from the PHP
     * value types. An empty view yields no properties (list-only via SELECT *).
     *
     * @param Connection $connection The open connection.
     * @param string     $viewName   The view name.
     *
     * @return array<string, array<string, mixed>> Property name → JSON-Schema fragment.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function viewColumnsFromSample(Connection $connection, string $viewName): array
    {
        try {
            $qb = $connection->createQueryBuilder();
            $qb->select('*')
                ->from($connection->getDatabasePlatform()->quoteIdentifier($viewName))
                ->setMaxResults(1);
            $row = $qb->executeQuery()->fetchAssociative();
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('[DbalIntrospection] could not sample view "%s": %s', $viewName, $e->getMessage())
            );
            return [];
        }

        if (is_array($row) === false) {
            return [];
        }

        $properties = [];
        foreach ($row as $column => $value) {
            $properties[(string) $column] = match (gettype($value)) {
                'integer' => ['type' => 'integer'],
                'double'  => ['type' => 'number'],
                'boolean' => ['type' => 'boolean'],
                default   => ['type' => 'string'],
            };
        }

        return $properties;
    }//end viewColumnsFromSample()

    /**
     * Resolve the ordered primary-key column names for a table.
     *
     * @param AbstractSchemaManager $schemaManager The schema manager.
     * @param string                $tableName     The table name.
     * @param bool                  $isView        Whether this is a view (never has a PK).
     *
     * @return array<int, string> The PK column names in order (possibly empty).
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function primaryKeyColumns(AbstractSchemaManager $schemaManager, string $tableName, bool $isView): array
    {
        if ($isView === true) {
            return [];
        }

        try {
            $table = $schemaManager->introspectTable($tableName);
        } catch (Throwable $e) {
            return [];
        }

        return $this->tablePrimaryKey(table: $table);
    }//end primaryKeyColumns()

    /**
     * Extract the ordered primary-key column list from a DBAL Table.
     *
     * @param Table $table The introspected table.
     *
     * @return array<int, string> The PK column names in order (possibly empty).
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function tablePrimaryKey(Table $table): array
    {
        $pk = $table->getPrimaryKey();
        if ($pk === null) {
            return [];
        }

        return array_values($pk->getColumns());
    }//end tablePrimaryKey()

    /**
     * Decide the id shape from the primary key (single / composite / none).
     *
     * @param array<int, string> $primaryKey The ordered PK column names.
     * @param string             $tableName  The table name (for logging).
     *
     * @return array{0: string|null, 1: array<int, string>|null} [idColumn, idColumns].
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function resolveIdentity(array $primaryKey, string $tableName): array
    {
        if (count($primaryKey) === 1) {
            return [$primaryKey[0], null];
        }

        if (count($primaryKey) > 1) {
            $this->logger->info(
                sprintf('[DbalIntrospection] table "%s" has a composite primary key (%s) — using a joined id', $tableName, implode(', ', $primaryKey))
            );
            return [null, array_values($primaryKey)];
        }

        $this->logger->info(
            sprintf('[DbalIntrospection] table "%s" has no primary key — schema is read-list-only', $tableName)
        );
        return [null, null];
    }//end resolveIdentity()

    /**
     * Whether a column becomes a `required` property.
     *
     * `NOT NULL` columns are required EXCEPT the primary key and any column that
     * carries a database default (both are server-populated, so their absence in
     * a read projection is valid — design D5).
     *
     * @param Column             $column     The introspected column.
     * @param array<int, string> $primaryKey The PK column names.
     *
     * @return bool True when the column is required.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function isRequired(Column $column, array $primaryKey): bool
    {
        if ($column->getNotnull() === false) {
            return false;
        }

        if (in_array($column->getName(), $primaryKey, true) === true) {
            return false;
        }

        return ($column->getDefault() === null);
    }//end isRequired()

    /**
     * Map single-column foreign keys onto the relation dialect and add inverses.
     *
     * @param AbstractSchemaManager               $schemaManager The schema manager.
     * @param array<string, array<string, mixed>> $tables        The working records, keyed by table name (mutated).
     * @param array<string, string>               $slugByTable   Table name → schema slug.
     *
     * @return void
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function applyForeignKeys(AbstractSchemaManager $schemaManager, array &$tables, array $slugByTable): void
    {
        foreach ($tables as $tableName => $record) {
            if ($record['isView'] === true) {
                continue;
            }

            $foreignKeys = $this->foreignKeysFor(schemaManager: $schemaManager, tableName: $tableName);
            foreach ($foreignKeys as $fk) {
                $localColumns = array_values($fk->getLocalColumns());
                if (count($localColumns) !== 1) {
                    $this->logger->info(
                        sprintf('[DbalIntrospection] skipping multi-column foreign key on "%s" (v1 maps single-column FKs only)', $tableName)
                    );
                    continue;
                }

                $localColumn  = $localColumns[0];
                $foreignTable = $this->shortName(name: $fk->getForeignTableName());
                if (isset($slugByTable[$foreignTable]) === false) {
                    continue;
                }

                $targetSlug = $slugByTable[$foreignTable];

                // Owning side: keep the raw key but type it as a relation.
                // A `format` is carried over only when the column's own type
                // mapping produced one (e.g. GUID → uuid) — external primary
                // keys are commonly plain integers/strings with no JSON-Schema
                // format, and relation resolution keys on $ref + handling.
                $relationProperty = [
                    'type'                => 'string',
                    '$ref'                => $targetSlug,
                    'objectConfiguration' => ['handling' => 'related-object'],
                ];

                $existingFormat = ($tables[$tableName]['properties'][$localColumn]['format'] ?? null);
                if (is_string($existingFormat) === true && $existingFormat !== '') {
                    $relationProperty['format'] = $existingFormat;
                }

                $tables[$tableName]['properties'][$localColumn] = $relationProperty;

                // Inverse side on the referenced schema.
                $inverseName = $tableName.'_via_'.$localColumn;
                $tables[$foreignTable]['properties'][$inverseName] = [
                    'type'                => 'array',
                    'items'               => [
                        '$ref'       => $slugByTable[$tableName],
                        'inversedBy' => $localColumn,
                    ],
                    'objectConfiguration' => ['handling' => 'related-object'],
                ];
            }//end foreach
        }//end foreach
    }//end applyForeignKeys()

    /**
     * List the foreign keys for a table, tolerating platforms/views that error.
     *
     * @param AbstractSchemaManager $schemaManager The schema manager.
     * @param string                $tableName     The table name.
     *
     * @return array<int, ForeignKeyConstraint> The foreign-key constraints (possibly empty).
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function foreignKeysFor(AbstractSchemaManager $schemaManager, string $tableName): array
    {
        try {
            return array_values($schemaManager->listTableForeignKeys($tableName));
        } catch (Throwable $e) {
            return [];
        }
    }//end foreignKeysFor()

    /**
     * Serialise a working record into a Schema definition array.
     *
     * @param array<string, mixed> $record The working table record.
     *
     * @return array<string, mixed> The schema definition for the mapper.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function toSchemaDefinition(array $record, bool $writable=false): array
    {
        $config = [
            'sourceId' => $record['sourceId'],
            'table'    => $record['table'],
            'idColumn' => $record['idColumn'],
        ];

        if ($record['idColumns'] !== null) {
            $config['idColumns'] = $record['idColumns'];
        }

        if (empty($record['nonFilterable']) === false) {
            $config['nonFilterable'] = $record['nonFilterable'];
        }

        if ($record['isView'] === true) {
            $config['isView'] = true;
        }

        // Opt-in writes (design D2): tables of a writable source get
        // `readOnly: false`; views are read-only ALWAYS. The write dispatch
        // additionally re-verifies the Source's writable flag live, so a stale
        // annotation can never authorize a write on its own.
        $readOnly = true;
        if ($writable === true && $record['isView'] !== true) {
            $readOnly = false;
        }

        return [
            'title'         => $record['title'],
            'slug'          => $record['slug'],
            'properties'    => $record['properties'],
            'required'      => $record['required'],
            'configuration' => [
                'x-openregister-object-source' => [
                    'provider' => self::PROVIDER_ID,
                    'readOnly' => $readOnly,
                    'config'   => $config,
                ],
            ],
        ];
    }//end toSchemaDefinition()

    /**
     * Find or create the register for a source and return it.
     *
     * @param array<string, mixed> $definition The register blueprint.
     * @param string               $sourceId   The owning source id.
     *
     * @return Register The persisted register.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function upsertRegister(array $definition, string $sourceId): Register
    {
        $existing = $this->registerMapper->findAll(filters: ['source' => $sourceId]);
        if (empty($existing) === false) {
            return $existing[0];
        }

        return $this->registerMapper->createFromArray(object: $definition);
    }//end upsertRegister()

    /**
     * Index a register's existing schemas by their bound source table name.
     *
     * @param Register $register The register whose schemas to load.
     *
     * @return array<string, Schema> Table name → schema.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function existingSchemasByTable(Register $register): array
    {
        $ids = $register->getSchemas();
        if (empty($ids) === true) {
            return [];
        }

        $byTable = [];
        foreach ($this->schemaMapper->findMultiple(ids: $ids) as $schema) {
            $source = $schema->getObjectSource();
            $table  = ($source['config']['table'] ?? null);
            if (is_string($table) === true && $table !== '') {
                $byTable[$table] = $schema;
            }
        }

        return $byTable;
    }//end existingSchemasByTable()

    /**
     * Resolve the source id used as the register `source` and config `sourceId`.
     *
     * @param Source $source The database source.
     *
     * @return string The stable source identifier.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function sourceId(Source $source): string
    {
        $uuid = $source->getUuid();
        if ($uuid !== null && $uuid !== '') {
            return (string) $uuid;
        }

        return (string) $source->getId();
    }//end sourceId()

    /**
     * Reduce a possibly-qualified identifier (`schema.table`) to its short name.
     *
     * @param string $name The identifier.
     *
     * @return string The unqualified name.
     *
     * @spec exclude Private identifier helper; no behavioural contract.
     */
    private function shortName(string $name): string
    {
        $pos = strrpos($name, '.');
        if ($pos === false) {
            return $name;
        }

        return substr($name, ($pos + 1));
    }//end shortName()

    /**
     * Whether a table/view name belongs to a database system catalog.
     *
     * DBAL's schema manager can surface engine-internal objects — on
     * PostgreSQL `listViews()` returns `pg_catalog.*` and
     * `information_schema.*` views (observed live: `pg_user_mappings`
     * appears in BOTH namespaces, colliding on one slug); MySQL exposes
     * `mysql`/`performance_schema`/`sys`, SQLite reserves the `sqlite_`
     * prefix. None of these are user data — a virtual register must never
     * contain them.
     *
     * @param string $name The (possibly namespace-qualified) object name.
     *
     * @return bool True when the object is engine-internal.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function isSystemObject(string $name): bool
    {
        $lower = strtolower($name);
        $short = strtolower($this->shortName(name: $name));

        $systemNamespaces = [
            'pg_catalog.',
            'information_schema.',
            'pg_toast.',
            'mysql.',
            'performance_schema.',
            'sys.',
        ];
        foreach ($systemNamespaces as $prefix) {
            if (str_starts_with($lower, $prefix) === true) {
                return true;
            }
        }

        // Unqualified engine-internal names: SQLite reserved tables and
        // PostgreSQL catalog objects surfaced without their namespace.
        return str_starts_with($short, 'sqlite_') === true
            || str_starts_with($short, 'pg_') === true;
    }//end isSystemObject()

    /**
     * Build a lowercase hyphenated slug from an arbitrary identifier.
     *
     * @param string $value The raw value.
     *
     * @return string The slug.
     *
     * @spec exclude Private slug helper; no behavioural contract.
     */
    private function slugify(string $value): string
    {
        $slug = strtolower($value);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }//end slugify()
}//end class
