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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

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
	 * @param IDBConnection $db Database connection for table discovery
	 * @param LoggerInterface $logger Logger for debugging and error reporting
	 * @param RegisterMapper $registerMapper Mapper for register lookups
	 * @param SchemaMapper $schemaMapper Mapper for schema lookups
	 * @param DateTimeNormalizer $dateTimeNormalizer Normaliser for user-supplied datetime values
	 * @param SchemaTypeConverter $schemaTypeConverter Schema-driven type converter for row values
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly DateTimeNormalizer $dateTimeNormalizer,
		private readonly SchemaTypeConverter $schemaTypeConverter,
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
			$this->registerCache[$registerId] = $this->registerMapper->find(
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
			$this->schemaCache[$schemaId] = $this->schemaMapper->find(
				$schemaId,
				_multitenancy: false,
				_rbac: false
			);
		}

		return $this->schemaCache[$schemaId];
	}//end findSchemaCached()

	/**
	 * Count objects in a register-schema table via the injected callback.
	 *
	 * @param array $query Query filters
	 * @param Register $register Register context
	 * @param Schema $schema Schema context
	 *
	 * @return int Object count
	 */
	private function countObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): int {
		if ($this->countCallback === null) {
			$this->logger->warning(
				message: '[MagicStatisticsHandler] Count callback not set, returning 0',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return 0;
		}

		return ($this->countCallback)($query, $register, $schema);
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
			$platform = $this->db->getDatabasePlatform();
			$isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

			$sql = "SELECT table_name FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                    AND table_name LIKE 'oc_openregister_table_%'";
			if ($isPostgres === true) {
				$sql = "SELECT table_name FROM information_schema.tables
                        WHERE table_schema = current_schema()
                        AND table_name LIKE 'oc_openregister_table_%'";
			}

			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$tables = [];

			while (($row = $stmt->fetch()) !== false) {
				// Remove the 'oc_' prefix to get the table name for query builder.
				$tableName = $row['table_name'];
				if (str_starts_with($tableName, 'oc_') === true) {
					$tableName = substr($tableName, 3);
				}

				$tables[] = $tableName;
			}

			$this->magicTablesCache = $tables;

			return $tables;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicStatisticsHandler] Failed to get magic mapper tables',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end getAllMagicMapperTables()

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
	 * @param int|array|null $registerId Register ID filter
	 * @param int|array|null $schemaId Schema ID filter
	 * @param array $exclude Exclusions
	 *
	 * @return int[] Statistics data
	 *
	 * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function getStatistics(
		int|array|null $registerId = null,
		int|array|null $schemaId = null,
		array $exclude = [],
	): array {
		$total = 0;
		$deleted = 0;
		$locked = 0;

		$allPairs = $this->getAllRegisterSchemaPairs();

		foreach ($allPairs as $pair) {
			$pairRegisterId = (int)$pair['registerId'];
			$pairSchemaId = (int)$pair['schemaId'];

			// Apply register filter.
			if ($registerId !== null) {
				if (is_array($registerId) === true) {
					if (in_array($pairRegisterId, $registerId, true) === false) {
						continue;
					}
				} elseif ($pairRegisterId !== $registerId) {
					continue;
				}
			}

			// Apply schema filter.
			if ($schemaId !== null) {
				if (is_array($schemaId) === true) {
					if (in_array($pairSchemaId, $schemaId, true) === false) {
						continue;
					}
				} elseif ($pairSchemaId !== $schemaId) {
					continue;
				}
			}

			// Apply exclusion filter.
			$excluded = false;
			foreach ($exclude as $ex) {
				if (isset($ex['register'], $ex['schema']) === true
					&& (int)$ex['register'] === $pairRegisterId
					&& (int)$ex['schema'] === $pairSchemaId
				) {
					$excluded = true;
					break;
				}
			}

			if ($excluded === true) {
				continue;
			}

			try {
				$register = $this->findRegisterCached(registerId: $pairRegisterId);
				$schema = $this->findSchemaCached(schemaId: $pairSchemaId);

				$count = $this->countObjectsInRegisterSchemaTable(
					query: [],
					register: $register,
					schema: $schema
				);
				$total += $count;
			} catch (\Exception $e) {
				// Skip tables that can't be queried.
			}
		}//end foreach

		return [
			'total' => $total,
			'size' => 0,
			'invalid' => 0,
			'deleted' => $deleted,
			'locked' => $locked,
		];
	}//end getStatistics()

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
		$emptyStats = [
			'total' => 0,
			'size' => 0,
		];

		if (empty($schemaIds) === true) {
			return [];
		}

		// Initialize all schemas with empty stats.
		$statsMap = [];
		foreach ($schemaIds as $schemaId) {
			$statsMap[$schemaId] = $emptyStats;
		}

		// Iterate all register-schema pairs and accumulate counts per schema.
		$allPairs = $this->getAllRegisterSchemaPairs();

		foreach ($allPairs as $pair) {
			$pairSchemaId = (int)$pair['schemaId'];

			if (in_array($pairSchemaId, $schemaIds, true) === false) {
				continue;
			}

			try {
				$register = $this->registerMapper->find((int)$pair['registerId'], _multitenancy: false, _rbac: false);
				$schema = $this->schemaMapper->find($pairSchemaId, _multitenancy: false, _rbac: false);

				$count = $this->countObjectsInRegisterSchemaTable(
					query: [],
					register: $register,
					schema: $schema
				);

				$statsMap[$pairSchemaId]['total'] += $count;
			} catch (\Exception $e) {
				// Skip tables that can't be queried.
			}
		}//end foreach

		return $statsMap;
	}//end getStatisticsGroupedBySchema()

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
		$labels = [];
		$series = [];

		$allPairs = $this->getAllRegisterSchemaPairs();
		$registerCounts = [];

		foreach ($allPairs as $pair) {
			$pairRegisterId = (int)$pair['registerId'];
			$pairSchemaId = (int)$pair['schemaId'];

			if ($registerId !== null && $pairRegisterId !== $registerId) {
				continue;
			}

			if ($schemaId !== null && $pairSchemaId !== $schemaId) {
				continue;
			}

			try {
				$register = $this->registerMapper->find($pairRegisterId, _multitenancy: false, _rbac: false);
				$schema = $this->schemaMapper->find($pairSchemaId, _multitenancy: false, _rbac: false);

				$count = $this->countObjectsInRegisterSchemaTable(
					query: [],
					register: $register,
					schema: $schema
				);

				$regName = $register->getTitle() ?? 'Register ' . $pairRegisterId;
				if (isset($registerCounts[$regName]) === false) {
					$registerCounts[$regName] = 0;
				}

				$registerCounts[$regName] += $count;
			} catch (\Exception $e) {
				// Skip.
			}
		}//end foreach

		foreach ($registerCounts as $name => $count) {
			$labels[] = $name;
			$series[] = $count;
		}

		return ['labels' => $labels, 'series' => $series];
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
		$labels = [];
		$series = [];

		$allPairs = $this->getAllRegisterSchemaPairs();
		$schemaCounts = [];

		foreach ($allPairs as $pair) {
			$pairRegisterId = (int)$pair['registerId'];
			$pairSchemaId = (int)$pair['schemaId'];

			if ($registerId !== null && $pairRegisterId !== $registerId) {
				continue;
			}

			if ($schemaId !== null && $pairSchemaId !== $schemaId) {
				continue;
			}

			try {
				$register = $this->registerMapper->find($pairRegisterId, _multitenancy: false, _rbac: false);
				$schema = $this->schemaMapper->find($pairSchemaId, _multitenancy: false, _rbac: false);

				$count = $this->countObjectsInRegisterSchemaTable(
					query: [],
					register: $register,
					schema: $schema
				);

				$schName = $schema->getTitle() ?? 'Schema ' . $pairSchemaId;
				if (isset($schemaCounts[$schName]) === false) {
					$schemaCounts[$schName] = 0;
				}

				$schemaCounts[$schName] += $count;
			} catch (\Exception $e) {
				// Skip.
			}
		}//end foreach

		foreach ($schemaCounts as $name => $count) {
			$labels[] = $name;
			$series[] = $count;
		}

		return ['labels' => $labels, 'series' => $series];
	}//end getSchemaChartData()

	/**
	 * Convert a database row from a magic mapper table to an ObjectEntity.
	 *
	 * This method is public to allow bulk handlers to convert rows for event dispatching.
	 *
	 * @param array $row Database row
	 * @param Register $_register Register context
	 * @param Schema $_schema Schema context
	 *
	 * @return ObjectEntity|null Converted entity or null on failure
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	public function convertRowToObjectEntity(array $row, Register $_register, Schema $_schema): ?ObjectEntity {
		try {
			$objectEntity = new ObjectEntity();

			// Set register and schema from parameters (these are the context we're in).
			$objectEntity->setRegister((string)$_register->getId());
			$objectEntity->setSchema((string)$_schema->getId());

			[$metadata, $objectData] = $this->splitRow(row: $row, propertyMap: $this->propertyColumnMap(schema: $_schema));

			$this->applyMetadata(objectEntity: $objectEntity, metadata: $metadata);
			$objectEntity->setObject($objectData);

			// Explicitly set ID and UUID so they are never null: audit trails,
			// rendering and API responses depend on them.
			if (isset($metadata['id']) === true && is_numeric($metadata['id']) === true) {
				$objectEntity->setId((int)$metadata['id']);
			}

			if (isset($metadata['uuid']) === true) {
				$objectEntity->setUuid($metadata['uuid']);
			}

			return $objectEntity;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicStatisticsHandler] Failed to convert row to ObjectEntity',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'uuid' => $row[self::METADATA_PREFIX . 'uuid'] ?? 'unknown',
				]
			);

			return null;
		}//end try
	}//end convertRowToObjectEntity()

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
		$metadata = [];
		$objectData = [];
		foreach ($row as $columnName => $value) {
			$metadataField = $this->columnRoles->field(column: $columnName, columnToPropertyMap: $propertyMap['columns']);
			if ($metadataField !== null) {
				$metadata[$metadataField] = $this->metadataValue(field: $metadataField, value: $value);
				continue;
			}

			// In UNION queries, NULL placeholders exist for other schemas' columns.
			if ($value === null && isset($propertyMap['columns'][$columnName]) === false) {
				continue;
			}

			// Falls back to camelCase conversion when the schema does not map the column.
			$propertyName = $propertyMap['columns'][$columnName] ?? $this->columnNameToPropertyName(columnName: $columnName);
			$objectData[$propertyName] = $this->propertyValue(
				value: $value,
				definition: [
					'type' => $propertyMap['types'][$propertyName] ?? 'string',
					'format' => $propertyMap['formats'][$propertyName] ?? null,
				]
			);
		}

		return [$metadata, $objectData];
	}//end splitRow()

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
				// Owner can be null for public objects; id and uuid never should be.
				if ($field === 'uuid' || $field === 'id') {
					$this->logger->warning(
						message: '[MagicStatisticsHandler] Critical metadata field is null',
						context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field]
					);
				}

				continue;
			}

			$attribute = $this->columnRoles->attribute(field: $field);
			if ($attribute === null) {
				$this->skipUnknownMetadataField(field: $field);
				continue;
			}

			$method = 'set' . ucfirst($attribute);
			$objectEntity->$method($value);
		}//end foreach
	}//end applyMetadata()

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
		$this->logger->debug(
			message: '[MagicStatisticsHandler] Skipped a metadata column ObjectEntity has no attribute for',
			context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field]
		);
	}//end skipUnknownMetadataField()

	/**
	 * Sanitize column name for safe database usage
	 *
	 * @param string $name Column name to sanitize
	 *
	 * @return string Sanitized column name
	 */
	private function sanitizeColumnName(string $name): string {
		// Convert camelCase to snake_case.
		// Insert underscore before uppercase letters, then lowercase everything.
		$name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
		$name = strtolower($name);

		// Replace any remaining invalid characters with underscore.
		$name = preg_replace('/[^a-z0-9_]/', '_', $name);

		// Ensure it starts with a letter or underscore.
		if (preg_match('/^[a-z_]/', $name) === false) {
			$name = 'col_' . $name;
		}

		// Remove consecutive underscores.
		$name = preg_replace('/_+/', '_', $name);

		// Remove trailing underscores.
		$name = rtrim($name, '_');

		return $name;
	}//end sanitizeColumnName()

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
