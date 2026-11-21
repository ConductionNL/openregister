<?php

/**
 * MagicMapper Statistics Handler
 *
 * This handler provides statistics and chart data capabilities for dynamic
 * schema-based tables. It implements aggregation functionality including
 * object counts, register/schema chart data, and grouped statistics
 * optimized for schema-specific table structures.
 *
 * KEY RESPONSIBILITIES:
 * - Object statistics across all magic tables (total, size, deleted, locked)
 * - Per-schema grouped statistics for batch operations
 * - Register chart data aggregation (labels + series)
 * - Schema chart data aggregation (labels + series)
 * - Register/schema pair discovery from database table names
 * - Row-to-ObjectEntity conversion for magic table rows
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
 * @since 2.0.0 Initial implementation for MagicMapper statistics capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;

/**
 * Statistics and chart data handler for MagicMapper dynamic tables
 *
 * This class provides statistics aggregation and chart data generation
 * for schema-specific dynamic tables, offering register/schema-level
 * counting and visualization data.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * Statistics methods from MagicMapper retain inherent query complexity.
 */
class MagicStatisticsHandler {
	use MagicColumnNameTrait;

	/**
	 * Per-request memo of the magic-table list from information_schema.
	 *
	 * `getStatistics()` is called once per register on a registers-list page and
	 * each call re-ran the information_schema catalog scan; caching it for the
	 * request collapses O(registers) scans to one (consolidate-request-scoped-cache).
	 * Null until first populated; only successful scans are cached.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $magicTablesCache = null;

	/**
	 * Registers already fetched this request, keyed by id.
	 *
	 * @var array<int, Register>
	 */
	private array $registerCache = [];

	/**
	 * Schemas already fetched this request, keyed by id.
	 *
	 * @var array<int, Schema>
	 */
	private array $schemaCache = [];

	/**
	 * Metadata column prefix used in magic mapper tables
	 *
	 * @var string
	 */
	private const METADATA_PREFIX = '_';

	/**
	 * Metadata fields already noted as skipped in this process, by field.
	 *
	 * @var array<string, true>
	 */
	private static array $skippedFields = [];

	/**
	 * Tells metadata columns from underscore-named property columns.
	 *
	 * @var MagicMetadataColumn
	 */
	private MagicMetadataColumn $columnRoles;

	/**
	 * Callable for counting objects in a register-schema table.
	 *
	 * Set via setCountCallback() after construction to avoid circular dependency.
	 *
	 * @var callable|null
	 */
	private $countCallback = null;

	/**
	 * Constructor for MagicStatisticsHandler
	 *
	 * @param MagicTableSql                $sql                 The connection used for table discovery, the table prefix and the logger
	 * @param SchemaCatalogInterface       $catalog             The register and schema mappers for lookups
	 * @param DateTimeNormalizerInterface  $dateTimeNormalizer  Normaliser for user-supplied datetime values
	 * @param SchemaTypeConverterInterface $schemaTypeConverter Schema-driven type converter for row values
	 */
	public function __construct(
		private readonly MagicTableSql $sql,
		private readonly SchemaCatalogInterface $catalog,
		private readonly DateTimeNormalizerInterface $dateTimeNormalizer,
		private readonly SchemaTypeConverterInterface $schemaTypeConverter,
	) {
		$this->columnRoles = new MagicMetadataColumn();
	}//end __construct()

	/**
	 * Set the callback for counting objects in a register-schema table.
	 *
	 * This uses setter injection to avoid circular constructor dependency
	 * (MagicMapper → handler → MagicMapper).
	 *
	 * @param callable $callback Callback accepting (array $query, Register $register, Schema $schema): int
	 *
	 * @return void
	 */
	public function setCountCallback(callable $callback): void {
		$this->countCallback = $callback;
	}//end setCountCallback()

	/**
	 * Forget the memoised magic-table list.
	 *
	 * The magicTablesCache memo answers "which magic tables exist" and nothing used to
	 * clear it. MagicMapper invalidates its own two table memos the moment it
	 * creates a table; this third memo of the same fact was left behind, so a
	 * register whose table was created after the first statistics call in the
	 * same request counted zero objects while its table sat there holding them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
	 */
	public function forgetMagicTableList(): void {
		$this->magicTablesCache = null;
	}//end forgetMagicTableList()

	/**
	 * Find a Register, reusing the entity within this request.
	 *
	 * Statistics walk every register/schema pair, and a caller that wants stats for N
	 * registers calls getStatistics() N times — so the SAME register and schema rows were
	 * re-fetched from the database over and over. On a dev instance with 76 registers and
	 * 1,231 schemas that was ~2,500 redundant SELECTs to render one list.
	 *
	 * Registers and schemas do not change mid-request, so hold on to them.
	 *
	 * @param int $registerId The register id.
	 *
	 * @return Register The register entity.
	 *
	 * @spec exclude request-scoped identity cache; no behaviour change
	 */
	private function findRegisterCached(int $registerId): Register {
		if (isset($this->registerCache[$registerId]) === false) {
			$this->registerCache[$registerId] = $this->catalog->registers()->find(
				$registerId,
				_multitenancy: false,
				_rbac: false
			);
		}

		return $this->registerCache[$registerId];
	}//end findRegisterCached()

	/**
	 * Find a Schema, reusing the entity within this request.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return Schema The schema entity.
	 *
	 * @see self::findRegisterCached() for why this cache exists.
	 *
	 * @spec exclude request-scoped identity cache; no behaviour change
	 */
	private function findSchemaCached(int $schemaId): Schema {
		if (isset($this->schemaCache[$schemaId]) === false) {
			$this->schemaCache[$schemaId] = $this->catalog->schemas()->find(
				$schemaId,
				_multitenancy: false,
				_rbac: false
			);
		}

		return $this->schemaCache[$schemaId];
	}//end findSchemaCached()

	/**
	 * Count all objects in a register-schema table via the injected callback.
	 *
	 * The callback is always asked with an empty query.
	 *
	 * @param Register $register Register context
	 * @param Schema   $schema   Schema context
	 *
	 * @return int Object count
	 */
	private function countObjectsInRegisterSchemaTable(Register $register, Schema $schema): int {
		if ($this->countCallback === null) {
			$this->sql->logger->warning(
				message: '[MagicStatisticsHandler] Count callback not set, returning 0',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return 0;
		}

		return ($this->countCallback)([], $register, $schema);
	}//end countObjectsInRegisterSchemaTable()

	/**
	 * Get all magic mapper table names from the database.
	 *
	 * Queries information_schema to discover all tables matching the
	 * openregister_table_* naming pattern.
	 *
	 * @return string[] Array of table names (without oc_ prefix)
	 *
	 * @psalm-return list<string>
	 */
	private function getAllMagicMapperTables(): array {
		if ($this->magicTablesCache !== null) {
			return $this->magicTablesCache;
		}

		try {
			$this->magicTablesCache = $this->scanMagicMapperTables();
		} catch (Exception $e) {
			$this->sql->logger->error(
				message: '[MagicStatisticsHandler] Failed to get magic mapper tables',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}

		return $this->magicTablesCache;
	}//end getAllMagicMapperTables()

	/**
	 * Read the magic mapper table names from information_schema.
	 *
	 * @return string[] Table names without the table prefix
	 *
	 * @psalm-return list<string>
	 *
	 * @throws Exception When the catalog cannot be read.
	 */
	private function scanMagicMapperTables(): array {
		$stmt = $this->sql->db->prepare($this->magicTablesSql());
		$stmt->execute();
		$tables = [];

		while (($row = $stmt->fetch()) !== false) {
			// The query builder adds the table prefix itself.
			$tables[] = (string)strstr($row['table_name'], 'openregister_table_');
		}

		return $tables;
	}//end scanMagicMapperTables()

	/**
	 * The information_schema query listing magic mapper tables on this platform.
	 *
	 * @return string The SQL.
	 */
	private function magicTablesSql(): string {
		$platform = $this->sql->db->getDatabasePlatform();
		if (stripos($platform::class, 'PostgreSQL') !== false) {
			return "SELECT table_name FROM information_schema.tables
                        WHERE table_schema = current_schema()
                        AND table_name LIKE '*PREFIX*openregister_table_%'";
		}

		return "SELECT table_name FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                    AND table_name LIKE '*PREFIX*openregister_table_%'";
	}//end magicTablesSql()

	/**
	 * Get all register/schema pairs that have magic tables in the database.
	 *
	 * Discovers magic tables from information_schema and extracts register/schema IDs
	 * from table names (oc_openregister_table_{registerId}_{schemaId}).
	 *
	 * @return array<array{registerId: int, schemaId: int}> Array of register/schema ID pairs
	 */
	public function getAllRegisterSchemaPairs(): array {
		$tables = $this->getAllMagicMapperTables();
		$pairs = [];

		foreach ($tables as $tableName) {
			// Table names are like "openregister_table_{registerId}_{schemaId}" (prefix already stripped).
			if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName, $matches) === 1) {
				$pairs[] = [
					'registerId' => (int)$matches[1],
					'schemaId' => (int)$matches[2],
				];
			}
		}

		return $pairs;
	}//end getAllRegisterSchemaPairs()

	/**
	 * Get statistics for objects across all magic tables.
	 *
	 * @param StatisticsScope $scope The registers and schemas to cover and the pairs to leave out; every table when omitted.
	 *
	 * @return int[] Statistics data
	 *
	 * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int}
	 *
	 */
	public function getStatistics(StatisticsScope $scope=new StatisticsScope()): array {
		$wanted = ['register' => $scope->registerId, 'schema' => $scope->schemaId];
		return $this->pairStatistics(filter: new StatisticsPairFilter(wanted: $wanted, exclude: $scope->exclude));
	}//end getStatistics()

	/**
	 * The object count over every register-schema pair the filter keeps.
	 *
	 * @param StatisticsPairFilter $filter Which pairs count.
	 *
	 * @return array Statistics array with keys: total, size, invalid, deleted, locked.
	 */
	private function pairStatistics(StatisticsPairFilter $filter): array {
		$total = 0;
		foreach ($this->getAllRegisterSchemaPairs() as $pair) {
			$ids = [(int)$pair['registerId'], (int)$pair['schemaId']];
			if ($filter->matches(ids: $ids) === true) {
				$total += $this->cachedPairCount(ids: $ids);
			}
		}

		return ['total' => $total, 'size' => 0, 'invalid' => 0, 'deleted' => 0, 'locked' => 0];
	}//end pairStatistics()

	/**
	 * Count one pair's objects, reading its register and schema through the request cache.
	 *
	 * @param array{0: int, 1: int} $ids The register id and the schema id.
	 *
	 * @return int The count; 0 when a read or the count fails (the table is skipped).
	 */
	private function cachedPairCount(array $ids): int {
		try {
			$register = $this->findRegisterCached(registerId: $ids[0]);
			$schema = $this->findSchemaCached(schemaId: $ids[1]);

			return $this->countObjectsInRegisterSchemaTable(register: $register, schema: $schema);
		} catch (Exception $e) {
			// Skip tables that can't be queried.
			return 0;
		}
	}//end cachedPairCount()

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
		if (empty($schemaIds) === true) {
			return [];
		}

		$statsMap = $this->emptySchemaStats(schemaIds: $schemaIds);

		// Iterate all register-schema pairs and accumulate counts per schema.
		foreach ($this->getAllRegisterSchemaPairs() as $pair) {
			$entry = $this->schemaPairCount(pair: $pair, schemaIds: $schemaIds);
			if ($entry !== null) {
				$statsMap[$entry['schemaId']]['total'] += $entry['count'];
			}
		}

		return $statsMap;
	}//end getStatisticsGroupedBySchema()

	/**
	 * Every requested schema with empty stats.
	 *
	 * @param int[] $schemaIds The requested schema ids.
	 *
	 * @return array<int, array{total: int, size: int}> Map of schemaId => zero statistics.
	 */
	private function emptySchemaStats(array $schemaIds): array {
		$statsMap = [];
		foreach ($schemaIds as $schemaId) {
			$statsMap[$schemaId] = ['total' => 0, 'size' => 0];
		}

		return $statsMap;
	}//end emptySchemaStats()

	/**
	 * Count a pair whose schema is one of the requested ones.
	 *
	 * @param array{registerId: int, schemaId: int} $pair      The register/schema pair.
	 * @param int[]                                 $schemaIds The requested schema ids, compared strictly.
	 *
	 * @return array{registerId: int, schemaId: int, register: Register, schema: Schema, count: int}|null Null for
	 *     another schema, or when a read or the count fails.
	 */
	private function schemaPairCount(array $pair, array $schemaIds): ?array {
		$ids = [(int)$pair['registerId'], (int)$pair['schemaId']];
		if (in_array($ids[1], $schemaIds, true) === false) {
			return null;
		}

		return $this->countedPair(ids: $ids);
	}//end schemaPairCount()

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
		$counts = [];
		foreach ($this->countedPairs(filter: new RegisterSchemaFilter(registerId: $registerId, schemaId: $schemaId)) as $pair) {
			$name = $pair['register']->getTitle() ?? 'Register ' . $pair['registerId'];
			$counts[$name] = ($counts[$name] ?? 0) + $pair['count'];
		}

		return $this->chartOf(counts: $counts);
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
		$counts = [];
		foreach ($this->countedPairs(filter: new RegisterSchemaFilter(registerId: $registerId, schemaId: $schemaId)) as $pair) {
			$name = $pair['schema']->getTitle() ?? 'Schema ' . $pair['schemaId'];
			$counts[$name] = ($counts[$name] ?? 0) + $pair['count'];
		}

		return $this->chartOf(counts: $counts);
	}//end getSchemaChartData()

	/**
	 * The register-schema pairs that pass the filters, each with its object count.
	 *
	 * A pair whose register or schema cannot be read, or whose count fails, is left out.
	 *
	 * @param RegisterSchemaFilter $filter The pairs to cover.
	 *
	 * @return array<int, array{registerId: int, schemaId: int, register: Register, schema: Schema, count: int}>
	 */
	private function countedPairs(RegisterSchemaFilter $filter): array {
		$counted = [];
		foreach ($this->getAllRegisterSchemaPairs() as $pair) {
			$ids = [(int)$pair['registerId'], (int)$pair['schemaId']];
			$entry = null;
			if ($filter->matches(ids: $ids) === true) {
				$entry = $this->countedPair(ids: $ids);
			}

			if ($entry !== null) {
				$counted[] = $entry;
			}
		}

		return $counted;
	}//end countedPairs()

	/**
	 * Read one pair's register and schema, bypassing RBAC and multitenancy, and count its objects.
	 *
	 * @param array{0: int, 1: int} $ids The register id and the schema id.
	 *
	 * @return array{registerId: int, schemaId: int, register: Register, schema: Schema, count: int}|null Null when a read or the count fails.
	 */
	private function countedPair(array $ids): ?array {
		try {
			$register = $this->catalog->registers()->find($ids[0], _multitenancy: false, _rbac: false);
			$schema = $this->catalog->schemas()->find($ids[1], _multitenancy: false, _rbac: false);
			$count = $this->countObjectsInRegisterSchemaTable(register: $register, schema: $schema);
		} catch (Exception $e) {
			return null;
		}

		return ['registerId' => $ids[0], 'schemaId' => $ids[1], 'register' => $register, 'schema' => $schema, 'count' => $count];
	}//end countedPair()

	/**
	 * Chart data from counts by label, in label order of first appearance.
	 *
	 * @param array<string, int> $counts Count per label.
	 *
	 * @return array{labels: array<int, int|string>, series: array<int, int>}
	 */
	private function chartOf(array $counts): array {
		return ['labels' => array_keys($counts), 'series' => array_values($counts)];
	}//end chartOf()

	/**
	 * Convert a row of a magic table to an ObjectEntity; a row that fails is logged and skipped.
	 *
	 * Public so the mapper can convert rows for its readers and for event dispatching.
	 *
	 * @param array          $row   Database row data.
	 * @param RegisterSchema $table The register and schema the table belongs to.
	 *
	 * @return ObjectEntity|null ObjectEntity or null if conversion fails.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	public function toObjectEntity(array $row, RegisterSchema $table): ?ObjectEntity {
		try {
			$objectEntity = $this->entityInContext(register: $table->register, schema: $table->schema);

			[$metadata, $objectData] = $this->splitRow(row: $row, propertyMap: $this->propertyColumnMap(schema: $table->schema));

			$this->applyMetadata(objectEntity: $objectEntity, metadata: $metadata);
			$objectEntity->setObject($objectData);
			$this->applyIdentity(objectEntity: $objectEntity, metadata: $metadata);

			return $objectEntity;
		} catch (Exception $e) {
			$this->logConversionFailure(exception: $e, row: $row);
			return null;
		}
	}//end toObjectEntity()

	/**
	 * Log a row that could not be converted to an ObjectEntity.
	 *
	 * @param Exception $exception Why the conversion failed
	 * @param array     $row       The database row
	 *
	 * @return void
	 */
	private function logConversionFailure(Exception $exception, array $row): void {
		$this->sql->logger->error(
			message: '[MagicStatisticsHandler] Failed to convert row to ObjectEntity',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $exception->getMessage(),
				'uuid' => $row[self::METADATA_PREFIX . 'uuid'] ?? 'unknown',
			]
		);
	}//end logConversionFailure()

	/**
	 * A new entity in the register and schema context the row was read in.
	 *
	 * @param Register $register Register context
	 * @param Schema   $schema   Schema context
	 *
	 * @return ObjectEntity The entity with its register and schema set.
	 */
	private function entityInContext(Register $register, Schema $schema): ObjectEntity {
		$objectEntity = new ObjectEntity();
		$objectEntity->setRegister((string)$register->getId());
		$objectEntity->setSchema((string)$schema->getId());

		return $objectEntity;
	}//end entityInContext()

	/**
	 * Explicitly set ID and UUID so they are never null.
	 *
	 * Audit trails, rendering and API responses depend on them.
	 *
	 * @param ObjectEntity $objectEntity The entity being built
	 * @param array        $metadata     The metadata, keyed by field without prefix
	 *
	 * @return void
	 */
	private function applyIdentity(ObjectEntity $objectEntity, array $metadata): void {
		if (isset($metadata['id']) === true && is_numeric($metadata['id']) === true) {
			$objectEntity->setId((int)$metadata['id']);
		}

		if (isset($metadata['uuid']) === true) {
			$objectEntity->setUuid($metadata['uuid']);
		}
	}//end applyIdentity()

	/**
	 * The column-to-property mapping and the property types and formats of a schema.
	 *
	 * The mapping restores original property names (e.g. 'e-mailadres') from
	 * their sanitized column names (e.g. 'e_mailadres').
	 *
	 * @param Schema $schema The schema of the table.
	 *
	 * @return array{columns: array<string, string>, types: array<string, string>, formats: array<string, string>} The maps.
	 */
	private function propertyColumnMap(Schema $schema): array {
		$map = ['columns' => [], 'types' => [], 'formats' => []];
		foreach ($schema->getProperties() ?? [] as $propertyName => $propertyDef) {
			$map['columns'][$this->sanitizeColumnName(name: $propertyName)] = $propertyName;
			$map['types'][$propertyName] = $propertyDef['type'] ?? 'string';
			if (isset($propertyDef['format']) === true) {
				$map['formats'][$propertyName] = $propertyDef['format'];
			}
		}

		return $map;
	}//end propertyColumnMap()

	/**
	 * Split a row into its metadata fields and its property values.
	 *
	 * @param array $row         Database row
	 * @param array $propertyMap The maps from propertyColumnMap()
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The metadata, keyed by field without prefix, and the object data.
	 */
	private function splitRow(array $row, array $propertyMap): array {
		[$metadata, $objectData] = [[], []];
		foreach ($row as $columnName => $value) {
			$metadataField = $this->columnRoles->field(column: $columnName, columnToPropertyMap: $propertyMap['columns']);
			if ($metadataField !== null) {
				$metadata[$metadataField] = $this->metadataValue(field: $metadataField, value: $value);
				continue;
			}

			$property = $this->propertyEntry(cell: [$columnName, $value], propertyMap: $propertyMap);
			if ($property !== null) {
				$objectData[$property[0]] = $property[1];
			}
		}

		return [$metadata, $objectData];
	}//end splitRow()

	/**
	 * The property name and value a non-metadata column holds.
	 *
	 * @param array{0: string, 1: mixed} $cell        The column name and its value.
	 * @param array                      $propertyMap The maps from propertyColumnMap()
	 *
	 * @return array{0: string, 1: mixed}|null The property name and value; null for a UNION placeholder.
	 */
	private function propertyEntry(array $cell, array $propertyMap): ?array {
		[$columnName, $value] = $cell;
		// In UNION queries, NULL placeholders exist for other schemas' columns.
		if ($value === null && isset($propertyMap['columns'][$columnName]) === false) {
			return null;
		}

		// Falls back to camelCase conversion when the schema does not map the column.
		$propertyName = $propertyMap['columns'][$columnName] ?? $this->columnNameToPropertyName(columnName: $columnName);
		$definition = [
			'type' => $propertyMap['types'][$propertyName] ?? 'string',
			'format' => $propertyMap['formats'][$propertyName] ?? null,
		];

		return [$propertyName, $this->propertyValue(value: $value, definition: $definition)];
	}//end propertyEntry()

	/**
	 * A metadata column value as its ObjectEntity attribute takes it.
	 *
	 * @param string $field The metadata field without its prefix
	 * @param mixed  $value The column value
	 *
	 * @return mixed A DateTime for date fields, decoded JSON for JSON fields, else the value.
	 *
	 * @throws Exception When a date field does not parse.
	 */
	private function metadataValue(string $field, mixed $value): mixed {
		if ($value === null) {
			return null;
		}

		if (in_array($field, ['created', 'updated', 'last_user_activity', 'expires'], true) === true) {
			return new DateTime($value);
		}

		$jsonFields = ['files', 'relations', 'locked', 'authorization', 'validation', 'deleted', 'archived', 'frozen', 'geo', 'retention', 'groups'];
		if (in_array($field, $jsonFields, true) === true) {
			return json_decode($value, true);
		}

		return $value;
	}//end metadataValue()

	/**
	 * A property column value as its JSON-Schema type and format read it.
	 *
	 * Type coercion runs first (see SchemaTypeConverter and
	 * openspec/specs/schema-driven-read-coercion/spec.md); format
	 * normalisation follows, since the converter does not handle formats.
	 * An empty or whitespace-only date normalises to null rather than to
	 * the current timestamp.
	 *
	 * @param mixed $value      The column value
	 * @param array $definition The property's 'type' and 'format' (null when it has none)
	 *
	 * @return mixed The value.
	 */
	private function propertyValue(mixed $value, array $definition): mixed {
		$value = $this->schemaTypeConverter->convertValue(value: $value, schemaType: $definition['type']);
		if ($value === null || is_string($value) === false) {
			return $value;
		}

		if ($definition['format'] === 'date') {
			$normalised = $this->dateTimeNormalizer->normalize($value);
			return $normalised?->format('Y-m-d');
		}

		if ($definition['format'] === 'date-time') {
			// Offset-less DATETIME column value: interpret it in the
			// timezone the write path stored it in (WOO-567).
			return $this->dateTimeNormalizer->formatDatabaseValueForIso8601($value);
		}

		return $value;
	}//end propertyValue()

	/**
	 * Set the metadata fields on an entity.
	 *
	 * Entity setters are magic (__call), so is_callable() is true for any
	 * name; ObjectEntity is asked whether it has the attribute instead.
	 *
	 * @param ObjectEntity $objectEntity The entity being built
	 * @param array        $metadata     The metadata, keyed by field without prefix
	 *
	 * @return void
	 */
	private function applyMetadata(ObjectEntity $objectEntity, array $metadata): void {
		foreach ($metadata as $field => $value) {
			if ($value === null) {
				$this->warnIfIdentityNull(field: $field);
				continue;
			}

			$method = $this->metadataSetter(field: $field);
			if ($method !== null) {
				$objectEntity->$method($value);
			}
		}
	}//end applyMetadata()

	/**
	 * Warn when an identity field is null.
	 *
	 * Owner can be null for public objects; id and uuid never should be.
	 *
	 * @param string $field The metadata field without its prefix.
	 *
	 * @return void
	 */
	private function warnIfIdentityNull(string $field): void {
		if ($field === 'uuid' || $field === 'id') {
			$this->sql->logger->warning(
				message: '[MagicStatisticsHandler] Critical metadata field is null',
				context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field]
			);
		}
	}//end warnIfIdentityNull()

	/**
	 * The ObjectEntity setter for a metadata field.
	 *
	 * @param string $field The metadata field without its prefix.
	 *
	 * @return string|null The setter name; null (noted once) when ObjectEntity has no such attribute.
	 */
	private function metadataSetter(string $field): ?string {
		$attribute = $this->columnRoles->attribute(field: $field);
		if ($attribute === null) {
			$this->skipUnknownMetadataField(field: $field);
			return null;
		}

		return 'set' . ucfirst($attribute);
	}//end metadataSetter()

	/**
	 * Note, once per field and process, a metadata column ObjectEntity has no attribute for.
	 *
	 * Such a column is skipped, not an error: the row is still read.
	 *
	 * @param string $field The metadata field without its prefix.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	private function skipUnknownMetadataField(string $field): void {
		if (isset(self::$skippedFields[$field]) === true) {
			return;
		}

		self::$skippedFields[$field] = true;
		$this->sql->logger->debug(
			message: '[MagicStatisticsHandler] Skipped a metadata column ObjectEntity has no attribute for',
			context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field]
		);
	}//end skipUnknownMetadataField()

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
	 * @param string $columnName The snake_case column name
	 *
	 * @return string The camelCase property name
	 */
	private function columnNameToPropertyName(string $columnName): string {
		// Convert snake_case to camelCase.
		return lcfirst(str_replace('_', '', ucwords($columnName, '_')));
	}//end columnNameToPropertyName()
}//end class
