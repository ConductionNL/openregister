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
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\GuzzleSolrService;
use Psr\Log\LoggerInterface;

/**
 * DocumentBuilder for creating Solr documents
 *
 * PRAGMATIC APPROACH: This class initially delegates to GuzzleSolrService
 * for backward compatibility, then we'll migrate methods incrementally.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class DocumentBuilder
{
    /**
     * Guzzle Solr service for document creation (temporary delegation).
     *
     * @var GuzzleSolrService
     */
    private readonly GuzzleSolrService $guzzleSolrService;

    /**
     * Logger for operation tracking.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * DocumentBuilder constructor
     *
     * @param GuzzleSolrService   $guzzleSolrService The backend implementation
     * @param LoggerInterface     $logger            Logger
     * @param SchemaMapper|null   $schemaMapper      Schema mapper (unused for now)
     * @param RegisterMapper|null $registerMapper    Register mapper (unused for now)
     *
     * @return void
     */
    public function __construct(
        GuzzleSolrService $guzzleSolrService,
        LoggerInterface $logger,
        ?SchemaMapper $schemaMapper = null,
        ?RegisterMapper $registerMapper = null
    ) {
        $this->guzzleSolrService = $guzzleSolrService;
        $this->logger = $logger;
        
        // Store for future use when we fully migrate
        // Currently we delegate to GuzzleSolrService
    }


    /**
     * Create a Solr document from an ObjectEntity
     *
     * PRAGMATIC: Initially delegates to GuzzleSolrService.createSolrDocument()
     * This allows us to have the handler structure in place while maintaining
     * full backward compatibility.
     *
     * TODO: Extract the actual implementation from GuzzleSolrService incrementally
     *
     * @param ObjectEntity $object         The object to convert
     * @param array        $solrFieldTypes Available Solr field types
     *
     * @return array The Solr document
     * @throws \RuntimeException If schema is not available or mapping fails
     */
    public function createDocument(
        ObjectEntity $object,
        array $solrFieldTypes = []
    ): array {
        $this->logger->debug('DocumentBuilder: Delegating to GuzzleSolrService (temporary)', [
            'object_id' => $object->getId(),
            'method' => 'createDocument'
        ]);

        // Delegate to GuzzleSolrService for now
        return $this->guzzleSolrService->createSolrDocument($object, $solrFieldTypes);
    }


    // ========================================================================
    // EXTRACTED METHODS - Migrated from GuzzleSolrService
    // ========================================================================

    /**
     * Flatten relations array for SOLR - extract all values from relations key-value pairs
     *
     * MIGRATED from GuzzleSolrService - now maintained here.
     *
     * @param mixed $relations Relations data from ObjectEntity (e.g., {"modules.0":"uuid", "other.1":"value"})
     *
     * @return string[] Simple array of strings for SOLR multi-valued field (e.g., ["uuid", "value"])
     *
     * @psalm-return list{0?: string,...}
     */
    public function flattenRelationsForSolr($relations): array
    {
        // **DEBUG**: Log what we're processing.
        $this->logger->debug('Processing relations for SOLR', [
            'relations_type'  => gettype($relations),
            'relations_value' => $relations,
            'is_empty'        => empty($relations),
        ]);

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

            $this->logger->debug('Flattened relations result', [
                'input_count'  => count($relations),
                'output_count' => count($values),
                'values'       => $values,
            ]);

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
     * MIGRATED from GuzzleSolrService - now maintained here.
     *
     * @param mixed $files Files data from ObjectEntity
     *
     * @return array Simple array of strings for SOLR multi-valued field
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
                } elseif (is_array($file) === true && (($file['id'] ?? null) !== null)) {
                    $flattened[] = (string) $file['id'];
                } elseif (is_array($file) === true && (($file['uuid'] ?? null) !== null)) {
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
     * MIGRATED from GuzzleSolrService - now maintained here.
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
     * MIGRATED from GuzzleSolrService - now maintained here.
     *
     * WORKAROUND/HACK FOR MISSING DATA: This method reconstructs arrays from relations
     * because some array fields (e.g., 'standaarden') are stored ONLY as dot-notation
     * relation entries ("standaarden.0", "standaarden.1") instead of in the object body.
     *
     * @param array $relations The relations array from ObjectEntity
     *
     * @return array Associative array of field names to their array values
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
                if (!isset($arrays[$fieldName])) {
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
            }
        }

        // Sort each array by index and re-index to sequential keys.
        foreach ($arrays as $fieldName => &$arrayValues) {
            ksort($arrayValues);
            // Re-index to sequential numeric keys (0, 1, 2, ...).
            $arrayValues = array_values($arrayValues);
        }

        $this->logger->debug('Extracted arrays from relations', [
            'field_count'   => count($arrays),
            'fields'        => array_keys($arrays),
            'total_values'  => array_sum(array_map('count', $arrays)),
        ]);

        return $arrays;

    }//end extractArraysFromRelations()


    /**
     * Extract indexable values from an array for SOLR indexing
     *
     * MIGRATED from GuzzleSolrService - now maintained here.
     *
     * This method intelligently handles mixed arrays by inspecting the actual content
     * rather than relying on schema definitions, which may not match runtime data.
     *
     * @param array  $arrayValue The array to extract values from
     * @param string $fieldName  Field name for logging
     *
     * @return string[] Array of indexable string values
     */
    public function extractIndexableArrayValues(array $arrayValue, string $fieldName): array
    {
        $extractedValues = [];

        foreach ($arrayValue as $item) {
            if (is_string($item) === true) {
                // Direct string value - use as-is.
                $extractedValues[] = $item;
            } elseif (is_array($item) === true) {
                // Object/array - try to extract ID/UUID.
                $idValue = $this->extractIdFromObject($item);
                if ($idValue !== null) {
                    $extractedValues[] = $idValue;
                }
            } elseif (is_scalar($item) === true) {
                // Other scalar values (int, float, bool) - convert to string.
                $extractedValues[] = (string) $item;
            }

            // Skip null values and complex objects.
        }

        $this->logger->debug('Extracted indexable array values', [
            'field'            => $fieldName,
            'original_count'   => count($arrayValue),
            'extracted_count'  => count($extractedValues),
            'extracted_values' => $extractedValues,
        ]);

        return $extractedValues;

    }//end extractIndexableArrayValues()


}//end class
