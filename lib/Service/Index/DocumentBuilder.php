<?php

declare(strict_types=1);

/**
 * DocumentBuilder
 *
 * Handles building Solr documents from ObjectEntity instances.
 * Extracted from GuzzleSolrService to separate document creation logic.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Exception;
use DateTime;

/**
 * DocumentBuilder for creating Solr documents
 *
 * This class contains all the logic for converting ObjectEntity instances
 * into Solr-compatible document structures, including field mapping,
 * value conversion, and relation flattening.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class DocumentBuilder
{
    /**
     * Application prefix for multi-app field naming.
     *
     * @var string
     */
    private const APP_PREFIX = 'or';

    /**
     * Schema mapper for schema operations.
     *
     * @var SchemaMapper|null
     */
    private readonly ?SchemaMapper $schemaMapper;

    /**
     * Register mapper for register operations.
     *
     * @var RegisterMapper|null
     */
    private readonly ?RegisterMapper $registerMapper;

    /**
     * Logger for operation tracking.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * DocumentBuilder constructor
     *
     * @param LoggerInterface     $logger         Logger
     * @param SchemaMapper|null   $schemaMapper   Schema mapper
     * @param RegisterMapper|null $registerMapper Register mapper
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        ?SchemaMapper $schemaMapper = null,
        ?RegisterMapper $registerMapper = null
    ) {
        $this->logger = $logger;
        $this->schemaMapper = $schemaMapper;
        $this->registerMapper = $registerMapper;
    }


public function createDocument(ObjectEntity $object, array $solrFieldTypes=[]): array
{
    // **SCHEMA-AWARE MAPPING REQUIRED**: Validate schema availability first.
    if ($this->schemaMapper === null) {
        $objectId = $object->getId();
        $schemaId = $object->getSchema();
        throw new RuntimeException(
            'Schema mapper is not available. Cannot create SOLR document without schema validation. Object ID: '.($objectId ?? 'unknown').', Schema ID: '.($schemaId ?? 'unknown')
        );
    }

    // Get the schema for this object.
    $schema = $this->schemaMapper->find($object->getSchema());

    if (!($schema instanceof Schema)) {
        throw new RuntimeException(
            'Schema not found for object. Cannot create SOLR document without valid schema. ' .
            'Object ID: ' . ($object->getId() ?? 'unknown') . ', Schema ID: ' . ($object->getSchema() ?? 'unknown')
        );
    }

    if (($schema instanceof Schema) === false) {
        $objectId     = $object->getId();
        $schemaId     = $object->getSchema();
        $errorMessage = 'Schema not found for object. Cannot create SOLR document without valid schema. Object ID: '.($objectId ?? 'unknown').', Schema ID: '.($schemaId ?? 'unknown');
        throw new RuntimeException($errorMessage);
    }

    // Check if schema is searchable - skip indexing if not.
    if ($schema->getSearchable() === false) {
        $this->logger->debug(
                'Skipping SOLR indexing for non-searchable schema',
                [
                    'object_id'    => $object->getId(),
                    'schema_id'    => $object->getSchema(),
                    'schema_slug'  => $schema->getSlug(),
                    'schema_title' => $schema->getTitle(),
                ]
                );
        $objectId = $object->getId();

        if ($schema->getTitle() !== null && $schema->getTitle() !== '') {
            $schemaName = $schema->getTitle();
        } else {
            $schemaName = $schema->getSlug();
        }//end if

        $errorMessage = 'Schema is not searchable. Objects of this schema are excluded from SOLR indexing. Object ID: '.($objectId ?? 'unknown').', Schema: '.($schemaName ?? 'unknown');
        throw new RuntimeException($errorMessage);
    }//end if

    // Get the register for this object (if registerMapper is available).
    $register = null;
    if ($this->registerMapper !== null) {
        try {
            $register = $this->registerMapper->find($object->getRegister());
        } catch (Exception $e) {
            $this->logger->warning(
                    'Failed to fetch register for object',
                    [
                        'object_id'   => $object->getId(),
                        'register_id' => $object->getRegister(),
                        'error'       => $e->getMessage(),
                    ]
                    );
        }
    }

    // **USE CONSOLIDATED MAPPING**: Create schema-aware document directly.
    try {
        $document = $this->createSchemaAwareDocument($object, $schema, $register, $solrFieldTypes);

        // Document created successfully using schema-aware mapping.

        $this->logger->debug('Created SOLR document using schema-aware mapping', [
        'object_id' => $object->getId(),
            'schema_id' => $object->getSchema(),
            'mapped_fields' => count($document)
        ]);

        return $document;

    } catch (Exception $e) {
        // **NO FALLBACK**: Throw error to prevent schemaless documents.
        $this->logger->error('Schema-aware mapping failed and no fallback allowed', [
            'object_id' => $object->getId(),
            'schema_id' => $object->getSchema(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new RuntimeException(
            'Schema-aware mapping failed for object. Schemaless fallback is disabled to prevent inconsistent documents. ' .
            'Object ID: ' . ($object->getId() ?? 'unknown') . ', Schema ID: ' . ($object->getSchema() ?? 'unknown') . '. ' .
            'Original error: ' . $e->getMessage(),
            0,
            $e
        );
    }
}//end createDocument()
private function createSchemaAwareDocument(ObjectEntity $object, Schema $schema, $register=null, array $solrFieldTypes=[]): array
{
    // **FIX**: Get the actual object business data, not entity metadata.
    $objectData = $object->getObject();
    // This contains the schema fields like 'naam', 'website', 'type'.
    $schemaProperties = $schema->getProperties();

    // ========================================================================
    // **WORKAROUND/HACK**: Enrich object data from relations
    // ========================================================================
    // PROBLEM: Some array fields (like 'standaarden') are stored ONLY in the relations.
    // table as dot-notation entries (e.g., "standaarden.0", "standaarden.1") instead of.
    // being included in the object JSON body. This causes them to be missing from SOLR.
    // This is a data storage issue that should be fixed at the source (ObjectService/Mapper),.
    // but as a workaround, we reconstruct these arrays from relations to ensure they get indexed.
    // TODO: Investigate why some array fields are stored only as relations and fix the root cause.
    // in the object save logic so arrays are consistently stored in the object body.
    // ========================================================================.
    $relations = $object->getRelations();
    if (is_array($relations) === true && empty($relations) === false) {
        $extractedArrays = $this->extractArraysFromRelations($relations);
        foreach ($extractedArrays as $fieldName => $arrayValues) {
            // Only enrich if the field is empty or doesn't exist in object data.
            $fieldExists       = isset($objectData[$fieldName]);
            $fieldIsEmptyArray = $fieldExists === true && is_array($objectData[$fieldName]) === true && empty($objectData[$fieldName]) === true;
            if ($fieldExists === false || $fieldIsEmptyArray === true) {
                $objectData[$fieldName] = $arrayValues;
                $this->logger->debug(
                        '[WORKAROUND] Enriched object data from relations',
                        [
                            'field'       => $fieldName,
                            'values'      => $arrayValues,
                            'value_count' => count($arrayValues),
                            'reason'      => 'Array data missing from object body but found in relations',
                        ]
                        );
            }
        }
    }

    // Determine self_image value (must not exceed SOLR field length limit).
    if ($object->getImage() !== null && strlen($object->getImage()) <= 32766) {
        $selfImage = $object->getImage();
    } else {
        $selfImage = null;
    }

    // Determine document ID.
    if ($object->getUuid() !== null) {
        $documentId = $object->getUuid();
    } else {
        $documentId = (string)$object->getId();
    }

    // Determine values for core object fields using explicit comparisons.
    $selfName = $object->getName() !== null && $object->getName() !== '' ? $object->getName() : null;
    $selfDescription = $object->getDescription() !== null && $object->getDescription() !== '' ? $object->getDescription() : null;
    $selfSummary = $object->getSummary() !== null && $object->getSummary() !== '' ? $object->getSummary() : null;
    $selfSlug = $object->getSlug() !== null && $object->getSlug() !== '' ? $object->getSlug() : null;
    $selfUri = $object->getUri() !== null && $object->getUri() !== '' ? $object->getUri() : null;
    $selfVersion = $object->getVersion() !== null && $object->getVersion() !== '' ? $object->getVersion() : null;
    $selfSize = $object->getSize() !== null && $object->getSize() !== 0 ? $object->getSize() : null;
    $selfFolder = $object->getFolder() !== null && $object->getFolder() !== '' ? $object->getFolder() : null;

    // Base SOLR document with core identifiers and metadata fields using self_ prefix.
    $document = [
        // Core identifiers (always present) - no prefix for SOLR system fields.
        'id' => $documentId,

        // Metadata fields with self_ prefix (consistent with legacy mapping).
        'self_uuid' => $object->getUuid(),

        // Context fields - resolve to integer IDs.
        'self_register' => $this->resolveRegisterToId($object->getRegister(), $register),
        'self_register_id' => $this->resolveRegisterToId($object->getRegister(), $register),
        'self_register_uuid' => $register?->getUuid(),
        'self_register_slug' => $register?->getSlug(),

        'self_schema' => $this->resolveSchemaToId($object->getSchema(), $schema),
        'self_schema_id' => $this->resolveSchemaToId($object->getSchema(), $schema),
        'self_schema_uuid' => $schema->getUuid(),
        'self_schema_slug' => $schema->getSlug(),
        'self_schema_version' => $object->getSchemaVersion(),

        // Ownership and metadata.
        'self_owner' => $object->getOwner(),
        'self_organisation' => $object->getOrganisation(),
        'self_application' => $object->getApplication(),

        // Core object fields (text fields for search).
        'self_name' => $selfName,
        'self_description' => $selfDescription,
        'self_summary' => $selfSummary,
        'self_image' => $selfImage,
        'self_slug' => $selfSlug,
        'self_uri' => $selfUri,
        'self_version' => $selfVersion,
        'self_size' => $selfSize,
        'self_folder' => $selfFolder,

        // Sortable string variants (for ordering, not tokenized).
        // These are single-valued string fields that Solr can sort on.
        'self_name_s' => $selfName,
        'self_description_s' => $selfDescription,
        'self_summary_s' => $selfSummary,
        'self_slug_s' => $selfSlug,

        // Timestamps.
        'self_created' => $object->getCreated()?->format('Y-m-d\\TH:i:s\\Z'),
        'self_updated' => $object->getUpdated()?->format('Y-m-d\\TH:i:s\\Z'),
        'self_published' => $object->getPublished()?->format('Y-m-d\\TH:i:s\\Z'),
        'self_depublished' => $object->getDepublished()?->format('Y-m-d\\TH:i:s\\Z'),

        // **NEW**: UUID relation fields with proper types - flatten to avoid SOLR issues.
        'self_relations' => $this->flattenRelationsForSolr($object->getRelations()),
        'self_files' => $this->flattenFilesForSolr($object->getFiles()),

        // **COMPLETE OBJECT STORAGE**: Store entire object as JSON for exact reconstruction.
        'self_object' => json_encode($object->jsonSerialize() !== null ? $object->jsonSerialize() : [])
    ];

    // **SCHEMA-AWARE FIELD MAPPING**: Map object data based on schema properties.

    if (is_array($schemaProperties) === true && is_array($objectData) === true) {
        // **DEBUG**.: Log what we're mapping.
        $this->logger->debug('Schema-aware mapping', [
            'object_id' => $object->getId(),
            'schema_properties' => array_keys($schemaProperties),
            'object_data_keys' => array_keys($objectData)
        ]);

        foreach ($schemaProperties as $fieldName => $fieldDefinition) {
            if (isset($objectData[$fieldName]) === false) {
                continue;
            }

            $fieldValue = $objectData[$fieldName];
            $fieldType = $fieldDefinition['type'] ?? 'string';

            // **TRUNCATE LARGE VALUES**: Respect SOLR's 32,766 byte limit for indexed fields.
            if ($this->shouldTruncateField($fieldName, $fieldDefinition) === true) {
                $fieldValue = $this->truncateFieldValue($fieldValue, $fieldName);
            }

            // **HANDLE ARRAYS**: Process arrays by inspecting actual content.
            if (is_array($fieldValue) === true) {
                $this->logger->debug('Processing array field', [
                    'field' => $fieldName,
                    'array_size' => count($fieldValue),
                    'field_type' => $fieldType,
                    'schema_item_type' => $fieldDefinition['items']['type'] ?? 'unknown'
                ]);

                // Extract indexable values from the array (ignores schema definition).
                $extractedValues = $this->extractIndexableArrayValues($fieldValue, $fieldName);

                if (empty($extractedValues) === false) {
                    $solrFieldName = $this->mapFieldToSolrType($fieldName, 'array', $extractedValues);
                    if ($solrFieldName !== null && $this->validateFieldForSolr($solrFieldName, $extractedValues, $solrFieldTypes) === true) {
                        $document[$solrFieldName] = $extractedValues;
                        $this->logger->debug(
                                'Indexed array field (content-based extraction)',
                                [
                                    'field'             => $fieldName,
                                    'solr_field'        => $solrFieldName,
                                    'extracted_values'  => $extractedValues,
                                    'extraction_method' => 'content-based',
                                ]
                                );
                    }
                } else {
                    $this->logger->debug('Skipped array field - no indexable values found', [
                        'field' => $fieldName,
                        'array_size' => count($fieldValue)
                    ]);
                }
                continue; // Skip to next field after processing array.
            }

            // **FILTER OBJECTS**: Skip standalone objects (not arrays).
            if (is_object($fieldValue) === true) {
                $this->logger->debug('Skipping object field value', [
                    'field' => $fieldName,
                    'type' => gettype($fieldValue),
                    'reason' => 'Standalone objects are not suitable for SOLR field indexing'
                ]);
                continue;
            }//end if

            // **FILTER OBJECTS**: Skip standalone objects (not arrays)..
            if (is_object($fieldValue) === true) {
                $this->logger->debug(
                        'Skipping object field value',
                        [
                            'field'  => $fieldName,
                            'type'   => gettype($fieldValue),
                            'reason' => 'Standalone objects are not suitable for SOLR field indexing',
                        ]
                        );
                continue;
            }

            // **FILTER NON-SCALAR VALUES**: Only index scalar values (string, int, float, bool, null).
            if (!is_scalar($fieldValue) && $fieldValue !== null) {
                $this->logger->debug('Skipping non-scalar field value', [
                    'field' => $fieldName,
                    'type' => gettype($fieldValue),
                    'reason' => 'Only scalar values can be indexed in SOLR'
                ]);
                continue;
            }

            // **HOTFIX**: Temporarily skip 'versie' field to prevent NumberFormatException.
            // TODO: Fix versie field type conflict - it's defined as integer in SOLR but contains decimal strings like '9.1'.
            if ($fieldName === 'versie') {
                $this->logger->debug(
                        'HOTFIX: Skipped versie field to prevent type mismatch',
                        [
                            'field'  => $fieldName,
                            'value'  => $fieldValue,
                            'reason' => 'Temporary fix for integer/decimal type conflict',
                        ]
                        );
                continue;
            }

                    // Map field based on schema type to appropriate SOLR field name.
                    $solrFieldName = $this->mapFieldToSolrType($fieldName, $fieldType, $fieldValue);

                    if ($solrFieldName !== null) {
                        $convertedValue = $this->convertValueForSolr($fieldValue, $fieldType);

                        // **FIELD VALIDATION**: Check if field exists in SOLR and type is compatible.
                        if ($convertedValue !== null && $this->validateFieldForSolr($solrFieldName, $convertedValue, $solrFieldTypes) === true) {
                            $document[$solrFieldName] = $convertedValue;
                    $this->logger->debug('Mapped field', [
                        'original' => $fieldName,
                        'solr_field' => $solrFieldName,
                        'original_value' => $fieldValue,
                        'converted_value' => $convertedValue,
                        'value_type' => gettype($convertedValue)
                    ]);
                } else {
                    $this->logger->debug(
                            'Skipped field with null converted value',
                            [
                                'original'       => $fieldName,
                                'solr_field'     => $solrFieldName,
                                'original_value' => $fieldValue,
                                'field_type'     => $fieldType,
                            ]
                            );
                }//end if
            }//end if
        }//end foreach
    } else {
        // **DEBUG**.: Log when schema mapping fails.
        $this->logger->warning('Schema-aware mapping skipped', [
            'object_id' => $object->getId(),
            'schema_properties_type' => gettype($schemaProperties),
            'object_data_type' => gettype($objectData),
            'schema_properties_empty' => empty($schemaProperties),
            'object_data_empty' => empty($objectData)
        ]);
    }

    // Remove null values, but keep published/depublished fields and empty arrays for multi-valued fields.
    return array_filter($document, function($value, $key) {
        // Always keep published/depublished fields even if null for proper Solr filtering.
        if (in_array($key, ['self_published', 'self_depublished']) === true) {
            return true;
        }
        // Keep empty arrays for multi-valued fields like self_relations, self_files.
        if (is_array($value) === true && in_array($key, ['self_relations', 'self_files']) === true) {
            return true;
        }
        return $value !== null && $value !== '';
    }, ARRAY_FILTER_USE_BOTH);
}//end createSchemaAwareDocument()
private function createLegacyDocument(ObjectEntity $object): array
