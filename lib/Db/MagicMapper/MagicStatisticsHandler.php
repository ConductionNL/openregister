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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Statistics methods extracted from MagicMapper retain inherent query complexity
 */
class MagicStatisticsHandler
{

    /**
     * Metadata column prefix used in magic mapper tables
     *
     * @var string
     */
    private const METADATA_PREFIX = '_';

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
     * @param IDBConnection   $db             Database connection for table discovery
     * @param LoggerInterface $logger         Logger for debugging and error reporting
     * @param RegisterMapper  $registerMapper Mapper for register lookups
     * @param SchemaMapper    $schemaMapper   Mapper for schema lookups
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper
    ) {
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
    public function setCountCallback(callable $callback): void
    {
        $this->countCallback = $callback;
    }//end setCountCallback()

    /**
     * Count objects in a register-schema table via the injected callback.
     *
     * @param array    $query    Query filters
     * @param Register $register Register context
     * @param Schema   $schema   Schema context
     *
     * @return int Object count
     */
    private function countObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): int
    {
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
    private function getAllMagicMapperTables(): array
    {
        try {
            $platform   = $this->db->getDatabasePlatform();
            $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

            if ($isPostgres === true) {
                $sql = "SELECT table_name FROM information_schema.tables
                        WHERE table_schema = current_schema()
                        AND table_name LIKE 'oc_openregister_table_%'";
            } else {
                $sql = "SELECT table_name FROM information_schema.tables
                        WHERE table_schema = DATABASE()
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
    public function getAllRegisterSchemaPairs(): array
    {
        $tables = $this->getAllMagicMapperTables();
        $pairs  = [];

        foreach ($tables as $tableName) {
            // Table names are like "openregister_table_{registerId}_{schemaId}" (prefix already stripped).
            if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName, $matches) === 1) {
                $pairs[] = [
                    'registerId' => (int) $matches[1],
                    'schemaId'   => (int) $matches[2],
                ];
            }
        }

        return $pairs;
    }//end getAllRegisterSchemaPairs()

    /**
     * Get statistics for objects across all magic tables.
     *
     * @param int|array|null $registerId Register ID filter
     * @param int|array|null $schemaId   Schema ID filter
     * @param array          $exclude    Exclusions
     *
     * @return int[] Statistics data
     *
     * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function getStatistics(
        int|array|null $registerId=null,
        int|array|null $schemaId=null,
        array $exclude=[]
    ): array {
        $total   = 0;
        $deleted = 0;
        $locked  = 0;

        $allPairs = $this->getAllRegisterSchemaPairs();

        foreach ($allPairs as $pair) {
            $pairRegisterId = (int) $pair['registerId'];
            $pairSchemaId   = (int) $pair['schemaId'];

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
                if (isset($ex['register'], $ex['schema'])
                    && (int) $ex['register'] === $pairRegisterId
                    && (int) $ex['schema'] === $pairSchemaId
                ) {
                    $excluded = true;
                    break;
                }
            }

            if ($excluded === true) {
                continue;
            }

            try {
                $register = $this->registerMapper->find($pairRegisterId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find($pairSchemaId, _multitenancy: false, _rbac: false);

                $count = $this->countObjectsInRegisterSchemaTable(
                    query: [],
                    register: $register,
                    schema: $schema
                );
                $total += $count;
            } catch (\Exception $e) {
                // Skip tables that can't be queried.
            }
        }

        return [
            'total'   => $total,
            'size'    => 0,
            'invalid' => 0,
            'deleted' => $deleted,
            'locked'  => $locked,
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
    public function getStatisticsGroupedBySchema(array $schemaIds): array
    {
        $emptyStats = [
            'total' => 0,
            'size'  => 0,
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
            $pairSchemaId = (int) $pair['schemaId'];

            if (in_array($pairSchemaId, $schemaIds, true) === false) {
                continue;
            }

            try {
                $register = $this->registerMapper->find((int) $pair['registerId'], _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find($pairSchemaId, _multitenancy: false, _rbac: false);

                $count = $this->countObjectsInRegisterSchemaTable(
                    query: [],
                    register: $register,
                    schema: $schema
                );

                $statsMap[$pairSchemaId]['total'] += $count;
            } catch (\Exception $e) {
                // Skip tables that can't be queried.
            }
        }

        return $statsMap;
    }//end getStatisticsGroupedBySchema()

    /**
     * Get register chart data.
     *
     * @param int|null $registerId Register ID filter
     * @param int|null $schemaId   Schema ID filter
     *
     * @return (int|mixed|string)[][] Chart data
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
     */
    public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        $labels = [];
        $series = [];

        $allPairs = $this->getAllRegisterSchemaPairs();
        $registerCounts = [];

        foreach ($allPairs as $pair) {
            $pairRegisterId = (int) $pair['registerId'];
            $pairSchemaId   = (int) $pair['schemaId'];

            if ($registerId !== null && $pairRegisterId !== $registerId) {
                continue;
            }

            if ($schemaId !== null && $pairSchemaId !== $schemaId) {
                continue;
            }

            try {
                $register = $this->registerMapper->find($pairRegisterId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find($pairSchemaId, _multitenancy: false, _rbac: false);

                $count = $this->countObjectsInRegisterSchemaTable(
                    query: [],
                    register: $register,
                    schema: $schema
                );

                $regName = $register->getTitle() ?? 'Register '.$pairRegisterId;
                if (isset($registerCounts[$regName]) === false) {
                    $registerCounts[$regName] = 0;
                }

                $registerCounts[$regName] += $count;
            } catch (\Exception $e) {
                // Skip.
            }
        }

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
     * @param int|null $schemaId   Schema ID filter
     *
     * @return (int|mixed|string)[][] Chart data
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
     */
    public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        $labels = [];
        $series = [];

        $allPairs = $this->getAllRegisterSchemaPairs();
        $schemaCounts = [];

        foreach ($allPairs as $pair) {
            $pairRegisterId = (int) $pair['registerId'];
            $pairSchemaId   = (int) $pair['schemaId'];

            if ($registerId !== null && $pairRegisterId !== $registerId) {
                continue;
            }

            if ($schemaId !== null && $pairSchemaId !== $schemaId) {
                continue;
            }

            try {
                $register = $this->registerMapper->find($pairRegisterId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find($pairSchemaId, _multitenancy: false, _rbac: false);

                $count = $this->countObjectsInRegisterSchemaTable(
                    query: [],
                    register: $register,
                    schema: $schema
                );

                $schName = $schema->getTitle() ?? 'Schema '.$pairSchemaId;
                if (isset($schemaCounts[$schName]) === false) {
                    $schemaCounts[$schName] = 0;
                }

                $schemaCounts[$schName] += $count;
            } catch (\Exception $e) {
                // Skip.
            }
        }

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
     * @param array    $row       Database row
     * @param Register $_register Register context
     * @param Schema   $_schema   Schema context
     *
     * @return ObjectEntity|null Converted entity or null on failure
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Row to entity conversion requires many field mappings
     * @SuppressWarnings(PHPMD.NPathComplexity)       Row to entity conversion requires many field mappings
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complete field mapping requires comprehensive handling
     */
    public function convertRowToObjectEntity(array $row, Register $_register, Schema $_schema): ?ObjectEntity
    {
        try {
            $objectEntity = new ObjectEntity();

            // Set register and schema from parameters (these are the context we're in).
            $objectEntity->setRegister((string) $_register->getId());
            $objectEntity->setSchema((string) $_schema->getId());

            // Build column-to-property mapping and property types from schema.
            // This allows us to restore original property names (e.g., 'e-mailadres').
            // from their sanitized column names (e.g., 'e_mailadres').
            // Also builds property type map for type conversion.
            $columnToPropertyMap = [];
            $propertyTypes       = [];
            $propertyFormats     = [];
            $properties          = $_schema->getProperties() ?? [];
            foreach ($properties as $propertyName => $propertyDef) {
                $columnName = $this->sanitizeColumnName(name: $propertyName);
                $columnToPropertyMap[$columnName] = $propertyName;
                $propertyTypes[$propertyName]     = $propertyDef['type'] ?? 'string';
                if (isset($propertyDef['format']) === true) {
                    $propertyFormats[$propertyName] = $propertyDef['format'];
                }
            }

            // Extract metadata fields (remove prefix).
            $metadata   = [];
            $objectData = [];

            foreach ($row as $columnName => $value) {
                if (str_starts_with($columnName, self::METADATA_PREFIX) === true) {
                    // This is a metadata field.
                    $metadataField = substr($columnName, strlen(self::METADATA_PREFIX));

                    // Handle datetime fields.
                    if (in_array(
                            $metadataField,
                            [
                                'created',
                                'updated',
                                'expires',
                            ],
                            true
                        ) === true
                        && ($value !== null) === true
                    ) {
                        $value = new DateTime($value);
                    }

                    // Handle JSON fields.
                    if (in_array(
                            $metadataField,
                            [
                                'files',
                                'relations',
                                'locked',
                                'authorization',
                                'validation',
                                'deleted',
                                'geo',
                                'retention',
                                'groups',
                            ],
                            true
                        ) === true
                        && ($value !== null) === true
                    ) {
                        $value = json_decode($value, true);
                    }

                    $metadata[$metadataField] = $value;
                    continue;
                }//end if

                // This is a schema property.
                // Skip NULL values for properties not in this schema's definition.
                // In UNION queries, NULL placeholders exist for other schemas' columns.
                if ($value === null && isset($columnToPropertyMap[$columnName]) === false) {
                    continue;
                }

                // Map column name back to original property name using schema mapping.
                // Falls back to camelCase conversion if not found in mapping.
                $mappedName   = $columnToPropertyMap[$columnName] ?? null;
                $propertyName = $mappedName ?? $this->columnNameToPropertyName(columnName: $columnName);

                // Apply type conversion based on schema type.
                // This ensures values match the expected schema type (e.g., numeric strings stay as strings).
                $schemaType = $propertyTypes[$propertyName] ?? 'string';
                if ($schemaType === 'string' && (is_int($value) === true || is_float($value) === true)) {
                    // Schema expects string but database returned numeric - cast to string.
                    $value = (string) $value;
                }

                // Format date/datetime values based on schema format.
                $propertyFormat = $propertyFormats[$propertyName] ?? null;
                if ($value !== null && is_string($value) === true && $propertyFormat !== null) {
                    if ($propertyFormat === 'date') {
                        // Schema expects date-only (Y-m-d), strip time component.
                        try {
                            $value = (new DateTime($value))->format('Y-m-d');
                        } catch (\Exception $e) {
                            // Keep original value if parsing fails.
                        }
                    } else if ($propertyFormat === 'date-time') {
                        // Schema expects full ISO 8601 datetime.
                        try {
                            $value = (new DateTime($value))->format('c');
                        } catch (\Exception $e) {
                            // Keep original value if parsing fails.
                        }
                    }
                }

                // Decode JSON values if they're JSON strings.
                $objectData[$propertyName] = $value;
                if (is_string($value) === true && $this->isJsonString(string: $value) === true) {
                    $decodedValue = json_decode($value, true);
                    if ($decodedValue !== null) {
                        $objectData[$propertyName] = $decodedValue;
                    }
                }
            }//end foreach

            // Set metadata fields on ObjectEntity.
            foreach ($metadata as $field => $value) {
                if ($value === null) {
                    // Log when critical metadata field is null (owner can be null for public objects).
                    if ($field === 'uuid' || $field === 'id') {
                        $this->logger->warning(
                            message: '[MagicStatisticsHandler] Critical metadata field is null',
                            context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field]
                        );
                    }

                    continue;
                }

                $method = 'set'.ucfirst($field);
                // Use is_callable() instead of method_exists() to support magic methods.
                // Entity base class uses __call() for property setters.
                if (is_callable([$objectEntity, $method]) === false) {
                    $this->logger->warning(
                        message: '[MagicStatisticsHandler] Method is not callable for metadata field',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field, 'method' => $method]
                    );
                    continue;
                }

                $objectEntity->$method($value);
                // Debug critical fields.
                if (in_array($field, ['id', 'uuid', 'owner'], true) === true) {
                    $this->logger->debug(
                        message: '[MagicStatisticsHandler] Set critical metadata field',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $field, 'value' => $value]
                    );
                }
            }//end foreach

            // Verify entity state after setting metadata.
            $this->logger->debug(
                message: '[MagicStatisticsHandler] Entity state after metadata',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'entityId'    => $objectEntity->getId(),
                    'entityUuid'  => $objectEntity->getUuid(),
                    'entityOwner' => $objectEntity->getOwner(),
                ]
            );
            // End foreach.
            // Set object data.
            $objectEntity->setObject($objectData);

            // CRITICAL FIX: Explicitly set ID and UUID to ensure they are never null.
            // These are essential for audit trails, rendering, and API responses.
            if (isset($metadata['id']) === true && $metadata['id'] !== null) {
                $idValue = $metadata['id'];
                if (is_numeric($idValue) === true) {
                    $objectEntity->setId((int) $idValue);
                }
            }

            if (isset($metadata['uuid']) === true && $metadata['uuid'] !== null) {
                $objectEntity->setUuid($metadata['uuid']);
            }

            // Debug logging.
            $this->logger->debug(
                message: '[MagicStatisticsHandler] Successfully converted row to ObjectEntity',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'uuid'           => $metadata['uuid'] ?? 'unknown',
                    'register'       => $metadata['register'] ?? 'missing',
                    'schema'         => $metadata['schema'] ?? 'missing',
                    'objectDataKeys' => array_keys($objectData),
                    'metadataCount'  => count($metadata),
                ]
            );

            return $objectEntity;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicStatisticsHandler] Failed to convert row to ObjectEntity',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'uuid'  => $row[self::METADATA_PREFIX.'uuid'] ?? 'unknown',
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
        // Convert camelCase to snake_case.
        // Insert underscore before uppercase letters, then lowercase everything.
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower($name);

        // Replace any remaining invalid characters with underscore.
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);

        // Ensure it starts with a letter or underscore.
        if (preg_match('/^[a-z_]/', $name) === false) {
            $name = 'col_'.$name;
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
    private function columnNameToPropertyName(string $columnName): string
    {
        // Convert snake_case to camelCase.
        return lcfirst(str_replace('_', '', ucwords($columnName, '_')));
    }//end columnNameToPropertyName()

    /**
     * Check if a string is valid JSON
     *
     * @param string $string The string to check
     *
     * @return bool True if the string is valid JSON
     */
    private function isJsonString(string $string): bool
    {
        // Decode JSON to check for errors via json_last_error().
        // Note: We only care about json_last_error(), not the decoded value.
        $decoded = json_decode($string);
        unset($decoded);
        return json_last_error() === JSON_ERROR_NONE;
    }//end isJsonString()
}//end class
