<?php

/**
 * DocumentBuilder
 *
 * Handles building Solr documents from ObjectEntity instances.
 * Extracted from SolrBackend to separate document creation logic.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use Psr\Log\LoggerInterface;

/**
 * DocumentBuilder for creating Solr documents
 *
 * Handles conversion of ObjectEntity instances to Solr documents.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class DocumentBuilder
{

    /**
     * Logger for operation tracking.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Schema mapper for resolving schemas.
     *
     * @var SchemaMapper|null
     */
    private readonly ?SchemaMapper $schemaMapper;

    /**
     * Register mapper for resolving registers.
     *
     * @var RegisterMapper|null
     */
    private readonly ?RegisterMapper $registerMapper;

    /**
     * DocumentBuilder constructor
     *
     * @param SolrBackend         $solrBackend    The backend implementation
     * @param LoggerInterface     $logger         Logger
     * @param SchemaMapper|null   $schemaMapper   Schema mapper (unused for now)
     * @param RegisterMapper|null $registerMapper Register mapper (unused for now)
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        ?SchemaMapper $schemaMapper=null,
        ?RegisterMapper $registerMapper=null
    ) {
        $this->logger         = $logger;
        $this->schemaMapper   = $schemaMapper;
        $this->registerMapper = $registerMapper;
    }//end __construct()

    /**
     * Create a Solr document from an ObjectEntity
     *
     * Builds a basic Solr document from the object's data.
     * This is a simplified implementation to break circular dependencies.
     *
     * @param ObjectEntity $object         The object to convert
     * @param array        $solrFieldTypes Available Solr field types (unused for now)
     *
     * @return (false|int|mixed|null|string)[] The Solr document
     *
     * @psalm-return array{_text: false|string,...}
     */
    public function createDocument(
        ObjectEntity $object,
        array $solrFieldTypes=[]
    ): array {
        $this->logger->debug(
            'DocumentBuilder: Creating basic Solr document',
            [
                'object_id' => $object->getId(),
            ]
        );

        // Build basic Solr document from object.
        $doc = [
            'id'        => (string) $object->getUuid(),
            'object_id' => $object->getId(),
            'uuid'      => $object->getUuid(),
            'schema'    => $object->getSchema(),
            'register'  => $object->getRegister(),
            'created'   => $object->getCreated()?->format('Y-m-d\TH:i:s\Z'),
            'updated'   => $object->getUpdated()?->format('Y-m-d\TH:i:s\Z'),
        ];

        // Add object data.
        $objectData = $object->getObject();
        if (is_array($objectData) === true) {
            foreach ($objectData as $key => $value) {
                // Skip null values.
                if ($value === null) {
                    continue;
                }

                // Convert value to Solr-compatible format.
                $doc[$key] = $this->convertValueForSolr(value: $value, fieldType: 'auto');
            }
        }

        // Add searchable text field.
        $doc['_text'] = json_encode($objectData);

        return $doc;
    }//end createDocument()

    // ========================================================================
    // EXTRACTED METHODS - Migrated from SolrBackend
    // ========================================================================

    /**
     * Flatten relations array for SOLR - extract all values from relations key-value pairs
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param mixed $relations Relations data from ObjectEntity (e.g., {"modules.0":"uuid", "other.1":"value"})
     *
     * @return string[]
     *
     * @psalm-return list<string>
     */
    public function flattenRelationsForSolr($relations): array
    {
        // **DEBUG**: Log what we're processing.
        $this->logger->debug(
            'Processing relations for SOLR',
            [
                'relations_type'  => gettype($relations),
                'relations_value' => $relations,
                'is_empty'        => empty($relations),
            ]
        );

        if (empty($relations) === true) {
            return [];
        }

        if (is_array($relations) === true) {
            $values = [];
            foreach ($relations as $key => $value) {
                // **FIXED**: Extract ALL values from relations array, not just UUIDs.
                // Relations are stored as {"modules.0":"value"} - we want all the values.
                if (is_string($value) === true || is_numeric($value) === true) {
                    $values[] = (string) $value;
                    $this->logger->debug(
                        'Found value in relations',
                        [
                            'key'   => $key,
                            'value' => $value,
                            'type'  => gettype($value),
                        ]
                    );
                }

                // Skip arrays, objects, null values, etc.
            }

            $this->logger->debug(
                'Flattened relations result',
                [
                    'input_count'  => count($relations),
                    'output_count' => count($values),
                    'values'       => $values,
                ]
            );

            return $values;
        }//end if

        // Single value - convert to string.
        if (is_string($relations) === true || is_numeric($relations) === true) {
            return [(string) $relations];
        }

        return [];
    }//end flattenRelationsForSolr()

    /**
     * Flatten files array for SOLR to prevent document multiplication
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param mixed $files Files data from ObjectEntity
     *
     * @return (mixed|string)[] Simple array of strings for SOLR multi-valued field
     *
     * @psalm-return list<mixed|string>
     */
    public function flattenFilesForSolr($files): array
    {
        if (empty($files) === true) {
            return [];
        }

        if (is_array($files) === true) {
            $flattened = [];
            foreach ($files as $file) {
                if (is_string($file) === true) {
                    $flattened[] = $file;
                } else if (is_array($file) === true && (($file['id'] ?? null) !== null)) {
                    $flattened[] = (string) $file['id'];
                } else if (is_array($file) === true && (($file['uuid'] ?? null) !== null)) {
                    $flattened[] = $file['uuid'];
                }
            }

            return $flattened;
        }

        if (is_string($files) === true) {
            return [$files];
        }

        return [];
    }//end flattenFilesForSolr()

    /**
     * Extract ID/UUID from an object/array
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param array $object Object/array to extract ID from
     *
     * @return string|null Extracted ID or null if not found
     */
    public function extractIdFromObject(array $object): ?string
    {
        // Try common ID field names in order of preference.
        $idFields = ['id', 'uuid', 'identifier', 'key', 'value'];

        foreach ($idFields as $field) {
            if (($object[$field] ?? null) !== null && is_string($object[$field]) === true) {
                return $object[$field];
            }
        }

        // If no ID field found, return null.
        return null;
    }//end extractIdFromObject()

    /**
     * Extract array fields from dot-notation relations
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * WORKAROUND/HACK FOR MISSING DATA: This method reconstructs arrays from relations
     * because some array fields (e.g., 'standaarden') are stored ONLY as dot-notation
     * relation entries ("standaarden.0", "standaarden.1") instead of in the object body.
     *
     * @param array $relations The relations array from ObjectEntity
     *
     * @return array[] Associative array of field names to their array values
     *
     * @psalm-return array<string, array<int, mixed>>
     */
    public function extractArraysFromRelations(array $relations): array
    {
        $arrays = [];

        // Group relations by their base field name (before the dot).
        foreach ($relations as $relationKey => $relationValue) {
            // Check if this is a dot-notation array relation (e.g., "standaarden.0").
            if (str_contains($relationKey, '.') === true) {
                $parts     = explode('.', $relationKey, 2);
                $fieldName = $parts[0];
                $index     = $parts[1];

                // Initialize array if not exists.
                if (isset($arrays[$fieldName]) === false) {
                    $arrays[$fieldName] = [];
                }

                // Add value at the specified index (or skip if index is not numeric).
                if (is_numeric($index) === true) {
                    $arrays[$fieldName][(int) $index] = $relationValue;
                } else {
                    // Non-numeric index - this is a nested object property, not an array element.
                    $this->logger->debug(
                        'Skipping non-numeric array index in relations',
                        [
                            'relation_key' => $relationKey,
                            'field_name'   => $fieldName,
                            'index'        => $index,
                        ]
                    );
                }
            }//end if
        }//end foreach

        // Sort each array by index and re-index to sequential keys.
        foreach ($arrays as $fieldName => &$arrayValues) {
            ksort($arrayValues);
            // Re-index to sequential numeric keys (0, 1, 2, ...).
            $arrayValues = array_values($arrayValues);
        }

        $this->logger->debug(
            'Extracted arrays from relations',
            [
                'field_count'  => count($arrays),
                'fields'       => array_keys($arrays),
                'total_values' => array_sum(array_map('count', $arrays)),
            ]
        );

        return $arrays;
    }//end extractArraysFromRelations()

    /**
     * Extract indexable values from an array for SOLR indexing
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * This method intelligently handles mixed arrays by inspecting the actual content
     * rather than relying on schema definitions, which may not match runtime data.
     *
     * @param array  $arrayValue The array to extract values from
     * @param string $fieldName  Field name for logging
     *
     * @return string[] Array of indexable string values
     *
     * @psalm-return list<string>
     */
    public function extractIndexableArrayValues(array $arrayValue, string $fieldName): array
    {
        $extractedValues = [];

        foreach ($arrayValue as $item) {
            if (is_string($item) === true) {
                // Direct string value - use as-is.
                $extractedValues[] = $item;
            } else if (is_array($item) === true) {
                // Object/array - try to extract ID/UUID.
                $idValue = $this->extractIdFromObject($item);
                if ($idValue !== null) {
                    $extractedValues[] = $idValue;
                }
            } else if (is_scalar($item) === true) {
                // Other scalar values (int, float, bool) - convert to string.
                $extractedValues[] = (string) $item;
            }

            // Skip null values and complex objects.
        }

        $this->logger->debug(
            'Extracted indexable array values',
            [
                'field'            => $fieldName,
                'original_count'   => count($arrayValue),
                'extracted_count'  => count($extractedValues),
                'extracted_values' => $extractedValues,
            ]
        );

        return $extractedValues;
    }//end extractIndexableArrayValues()

    /**
     * Map field name and type to appropriate SOLR field name
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param string $fieldName   Original field name
     * @param string $_fieldType  Schema field type (unused)
     * @param mixed  $_fieldValue Field value for context (unused)
     *
     * @return string|null SOLR field name or null if should be skipped
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function mapFieldToSolrType(string $fieldName, string $_fieldType, $_fieldValue): ?string
    {
        // Avoid conflicts with core SOLR fields and self_ metadata fields.
        if (in_array($fieldName, ['id', 'tenant_id', '_version_']) === true || str_starts_with($fieldName, 'self_') === true) {
            return null;
        }

        // **CLEAN FIELD NAMES**: Return field name as-is since we define proper types in SOLR setup.
        return $fieldName;
    }//end mapFieldToSolrType()

    /**
     * Convert value to appropriate format for SOLR
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param mixed  $value     Field value
     * @param string $fieldType Schema field type
     *
     * @return mixed Converted value for SOLR
     */
    public function convertValueForSolr($value, string $fieldType)
    {
        if ($value === null) {
            return null;
        }

        switch (strtolower($fieldType)) {
            case 'integer':
            case 'int':
                // **SAFE NUMERIC CONVERSION**: Handle non-numeric strings gracefully.
                if (is_numeric($value) === true) {
                    return (int) $value;
                }

                // Skip non-numeric values for integer fields.
                $this->logger->debug(
                    'Skipping non-numeric value for integer field',
                    [
                        'value'      => $value,
                        'field_type' => $fieldType,
                    ]
                );
                return null;

            case 'float':
            case 'double':
            case 'number':
                // **SAFE NUMERIC CONVERSION**: Handle non-numeric strings gracefully.
                if (is_numeric($value) === true) {
                    return (float) $value;
                }

                // Skip non-numeric values for float fields.
                $this->logger->debug(
                    'Skipping non-numeric value for float field',
                    [
                        'value'      => $value,
                        'field_type' => $fieldType,
                    ]
                );
                return null;

            case 'boolean':
            case 'bool':
                return (bool) $value;

            case 'date':
            case 'datetime':
                if ($value instanceof \DateTime) {
                    return $value->format('Y-m-d\\TH:i:s\\Z');
                }

                if (is_string($value) === true) {
                    $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
                    if ($date !== false) {
                        return $date->format('Y-m-d\\TH:i:s\\Z');
                    }

                    return $value;
                }
                return $value;

            case 'array':
                if (is_array($value) === true) {
                    return $value;
                }
                return [$value];

            default:
                return (string) $value;
        }//end switch
    }//end convertValueForSolr()

    /**
     * Truncate field value to respect SOLR's byte limit
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param mixed  $value     Field value
     * @param string $fieldName Field name for logging
     *
     * @return mixed Truncated value or original if within limits
     */
    public function truncateFieldValue($value, string $fieldName=''): mixed
    {
        // Only truncate string values.
        if (is_string($value) === false) {
            return $value;
        }

        // SOLR's byte limit for indexed string fields.
        $maxBytes = 32766;

        // Check if value exceeds byte limit (UTF-8 safe).
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        // **TRUNCATE SAFELY**: Ensure we don't break UTF-8 characters.
        $truncated = mb_strcut($value, 0, $maxBytes - 100, 'UTF-8');
        // Leave buffer for safety.
        // Add truncation indicator.
        $truncated .= '...[TRUNCATED]';

        // Log truncation for monitoring.
        $this->logger->info(
            'Field value truncated for SOLR indexing',
            [
                'field'            => $fieldName,
                'original_bytes'   => strlen($value),
                'truncated_bytes'  => strlen($truncated),
                'truncation_point' => $maxBytes - 100,
            ]
        );

        return $truncated;
    }//end truncateFieldValue()

    /**
     * Check if a field should be truncated based on schema definition
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param string $fieldName       Field name
     * @param array  $fieldDefinition Schema field definition (if available)
     *
     * @return bool True if field should be truncated
     */
    public function shouldTruncateField(string $fieldName, array $fieldDefinition=[]): bool
    {
        $type   = $fieldDefinition['type'] ?? '';
        $format = $fieldDefinition['format'] ?? '';

        // File fields should always be truncated.
        if ($type === 'file' || $format === 'file' || $format === 'binary'
            || in_array($format, ['data-url', 'base64', 'image', 'document']) === true
        ) {
            return true;
        }

        // Fields that commonly contain large content.
        $largeContentFields = ['logo', 'image', 'icon', 'thumbnail', 'content', 'body', 'description'];
        if (in_array(strtolower($fieldName), $largeContentFields) === true) {
            return true;
        }

        // Base64 data URLs (common pattern).
        if (is_string($fieldName) === true && str_contains(strtolower($fieldName), 'base64') === true) {
            return true;
        }

        return false;
    }//end shouldTruncateField()

    /**
     * Validate field for SOLR indexing
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param string $fieldName      Field name
     * @param mixed  $fieldValue     Field value
     * @param array  $solrFieldTypes Available SOLR field types
     *
     * @return bool True if field is safe to index
     */
    public function validateFieldForSolr(string $fieldName, $fieldValue, array $solrFieldTypes): bool
    {
        // If no field types provided, allow all (fallback to original behavior).
        if (empty($solrFieldTypes) === true) {
            return true;
        }

        // If field doesn't exist in SOLR, it will be auto-created (allow).
        if (isset($solrFieldTypes[$fieldName]) === false) {
            $this->logger->debug(
                'Field not in SOLR schema, will be auto-created',
                [
                    'field' => $fieldName,
                    'value' => $fieldValue,
                    'type'  => gettype($fieldValue),
                ]
            );
            return true;
        }

        $solrFieldType = $solrFieldTypes[$fieldName];

        // **CRITICAL VALIDATION**: Check for type compatibility.
            $isCompatible = $this->isValueCompatibleWithSolrType(value: $fieldValue, solrFieldType: $solrFieldType);

        if ($isCompatible === false) {
            $this->logger->warning(
                '🛡️ Field validation prevented type mismatch',
                [
                    'field'           => $fieldName,
                    'value'           => $fieldValue,
                    'value_type'      => gettype($fieldValue),
                    'solr_field_type' => $solrFieldType,
                    'action'          => 'SKIPPED',
                ]
            );
            return false;
        }

        $this->logger->debug(
            '✅ Field validation passed',
            [
                'field'     => $fieldName,
                'value'     => $fieldValue,
                'solr_type' => $solrFieldType,
            ]
        );

        return true;
    }//end validateFieldForSolr()

    /**
     * Check if a value is compatible with a SOLR field type
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param mixed  $value         The value to check
     * @param string $solrFieldType The SOLR field type
     *
     * @return bool True if compatible
     */
    public function isValueCompatibleWithSolrType($value, string $solrFieldType): bool
    {
        // Handle null values (generally allowed).
        if ($value === null) {
            return true;
        }

        // **FIXED**: Handle arrays for multi-valued fields.
        if (is_array($value) === true) {
            // Empty arrays are always allowed for multi-valued fields.
            if (empty($value) === true) {
                return true;
            }

            // Check each element in the array against the base field type.
            foreach ($value as $element) {
                if ($this->isValueCompatibleWithSolrType(value: $element, solrFieldType: $solrFieldType) === false) {
                    return false;
                }
            }

            return true;
        }

        return match ($solrFieldType) {
            // Numeric types - only allow numeric values.
            'pint', 'plong', 'plongs', 'pfloat', 'pdouble' => is_numeric($value),

            // String types - allow anything (can be converted to string).
            'string', 'text_general', 'text_en' => true,

            // Boolean types - allow boolean or boolean-like values.
            'boolean' => is_bool($value) || in_array(strtolower((string) $value), ['true', 'false', '1', '0']),

            // Date types - allow date strings or objects.
            'pdate', 'pdates' => is_string($value) || ($value instanceof \DateTime),

            // Default: allow for unknown types.
            default => true,
        };
    }//end isValueCompatibleWithSolrType()

    // ========================================================================
    // RESOLVER METHODS - ID Resolution
    // ========================================================================

    /**
     * Resolve register value to integer ID
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param mixed                              $registerValue The register value
     * @param \OCA\OpenRegister\Db\Register|null $register      Pre-loaded register entity
     *
     * @return int The resolved register ID
     */
    public function resolveRegisterToId($registerValue, ?\OCA\OpenRegister\Db\Register $register=null): int
    {
        if (empty($registerValue) === true) {
            return 0;
        }

        // If it's already a numeric ID, return it as integer.
        if (is_numeric($registerValue) === true) {
            return (int) $registerValue;
        }

        // If we have a pre-loaded register entity, use its ID.
        if ($register !== null) {
            return $register->getId() ?? 0;
        }

        // Try to resolve by slug/name using RegisterMapper.
        if ($this->registerMapper !== null) {
            try {
                $resolvedRegister = $this->registerMapper->find($registerValue);
                return $resolvedRegister->getId() ?? 0;
            } catch (Exception $e) {
                $this->logger->warning(
                    'Failed to resolve register value to ID',
                    [
                        'registerValue' => $registerValue,
                        'error'         => $e->getMessage(),
                    ]
                );
            }
        }

        // Fallback: return 0 for unresolvable values.
        $this->logger->warning(
            'Could not resolve register to integer ID',
            [
                'registerValue' => $registerValue,
                'type'          => gettype($registerValue),
            ]
        );
        return 0;
    }//end resolveRegisterToId()

    /**
     * Resolve schema value to integer ID
     *
     * MIGRATED from SolrBackend - now maintained here.
     *
     * @param mixed                            $schemaValue The schema value
     * @param \OCA\OpenRegister\Db\Schema|null $schema      Pre-loaded schema entity
     *
     * @return int The resolved schema ID
     */
    public function resolveSchemaToId($schemaValue, ?\OCA\OpenRegister\Db\Schema $schema=null): int
    {
        if (empty($schemaValue) === true) {
            return 0;
        }

        // If it's already a numeric ID, return it as integer.
        if (is_numeric($schemaValue) === true) {
            return (int) $schemaValue;
        }

        // If we have a pre-loaded schema entity, use its ID.
        if ($schema !== null) {
            return $schema->getId() ?? 0;
        }

        // Try to resolve by slug/name using SchemaMapper.
        if ($this->schemaMapper !== null) {
            try {
                $resolvedSchema = $this->schemaMapper->find($schemaValue);
                return $resolvedSchema->getId() ?? 0;
            } catch (Exception $e) {
                $this->logger->warning(
                    'Failed to resolve schema value to ID',
                    [
                        'schemaValue' => $schemaValue,
                        'error'       => $e->getMessage(),
                    ]
                );
            }
        }

        // Fallback: return 0 for unresolvable values.
        $this->logger->warning(
            'Could not resolve schema to integer ID',
            [
                'schemaValue' => $schemaValue,
                'type'        => gettype($schemaValue),
            ]
        );
        return 0;
    }//end resolveSchemaToId()
}//end class
