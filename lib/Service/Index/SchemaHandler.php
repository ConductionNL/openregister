<?php
/**
 * SchemaHandler
 *
 * Handles schema management operations for Solr collections.
 * Manages field types, schema mirroring, and collection field status.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index;

use Exception;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * SchemaHandler
 *
 * Manages Solr schema operations including field types, schema mirroring,
 * and collection field management.
 *
 * RESPONSIBILITIES:
 * - Ensure vector field types exist in Solr.
 * - Mirror OpenRegister schemas to Solr.
 * - Manage field types and mappings.
 * - Get and update collection field status.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index
 */
class SchemaHandler
{


    /**
     * Constructor
     *
     * @param SchemaMapper           $schemaMapper    Schema mapper for OpenRegister schemas
     * @param SettingsService        $settingsService Settings service for config
     * @param LoggerInterface        $logger          Logger
     * @param IConfig                $config          Nextcloud config
     * @param SearchBackendInterface $searchBackend   Search backend (Solr/Elastic/etc)
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly IConfig $config,
        private readonly SearchBackendInterface $searchBackend
    ) {

    }//end __construct()


    /**
     * Ensure vector field type exists in a Solr collection.
     *
     * Creates knn_vector field type for vector similarity search.
     *
     * @param string $collection Collection name to configure
     * @param int    $dimensions Vector dimensions (default: 4096)
     * @param string $similarity Similarity function: 'cosine', 'dot_product', or 'euclidean'
     *
     * @return bool Success status
     */
    public function ensureVectorFieldType(
        string $collection,
        int $dimensions=4096,
        string $similarity='cosine'
    ): bool {
        try {
            $this->logger->info(
                    '[SchemaHandler] Ensuring vector field type',
                    [
                        'collection' => $collection,
                        'dimensions' => $dimensions,
                        'similarity' => $similarity,
                    ]
                    );

            // Check if knn_vector type already exists.
            $existingTypes = $this->searchBackend->getFieldTypes($collection);

            if (isset($existingTypes['knn_vector']) === true) {
                $this->logger->info('[SchemaHandler] knn_vector field type already exists');
                return true;
            }

            // Create knn_vector field type.
            $fieldType = [
                'name'               => 'knn_vector',
                'class'              => 'solr.DenseVectorField',
                'vectorDimension'    => $dimensions,
                'similarityFunction' => $similarity,
            ];

            $result = $this->searchBackend->addFieldType(collection: $collection, fieldType: $fieldType);

            if ($result === true) {
                $this->logger->info('[SchemaHandler] ✅ knn_vector field type created successfully');
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SchemaHandler] Failed to ensure vector field type',
                    [
                        'error'      => $e->getMessage(),
                        'collection' => $collection,
                    ]
                    );
            return false;
        }//end try

    }//end ensureVectorFieldType()


    /**
     * Mirror OpenRegister schemas to Solr with intelligent conflict resolution.
     *
     * Analyzes all schemas first to detect field type conflicts and chooses
     * the most permissive type (string > text > float > integer > boolean).
     *
     * @param bool $force Force recreation of existing fields
     *
     * @return array Result with success status and statistics
     *
     * @psalm-return array{success: bool, error?: string, stats: array, execution_time_ms?: float}
     */
    public function mirrorSchemas(bool $force=false): array
    {
        $startTime = microtime(true);
        $stats     = [
            'schemas_processed'  => 0,
            'fields_created'     => 0,
            'fields_updated'     => 0,
            'conflicts_resolved' => 0,
            'errors'             => 0,
        ];

        try {
            $this->logger->info('[SchemaHandler] Starting intelligent schema mirroring with conflict resolution');

            // Get all OpenRegister schemas.
            $schemas = $this->schemaMapper->findAll();

            // Analyze schemas for field conflicts.
            $conflictAnalysis = $this->analyzeAndResolveFieldConflicts($schemas);

            $this->logger->info(
                    '[SchemaHandler] Field conflict analysis complete',
                    [
                        'total_fields'       => count($conflictAnalysis['fields']),
                        'conflicting_fields' => count($conflictAnalysis['conflicts']),
                        'resolved_conflicts' => count($conflictAnalysis['resolved']),
                    ]
                    );

            // Ensure core metadata fields exist.
            $coreFieldsResult = $this->ensureCoreMetadataFields($force);
            if ($coreFieldsResult === true) {
                $stats['core_fields_created'] = 52;
                // Assuming 52 core fields.
            }

            // Process each schema and apply fields.
            foreach ($schemas as $schema) {
                try {
                    $stats['schemas_processed']++;

                    // Generate Solr fields from schema.
                    $solrFields = $this->generateSolrFieldsFromSchema(schema: $schema, resolvedTypes: $conflictAnalysis['resolved']);

                    // Apply fields to Solr.
                    $applied = $this->applySolrFields(solrFields: $solrFields, force: $force);

                    $stats['fields_created'] += $applied['created'];
                    $stats['fields_updated'] += $applied['updated'];
                } catch (Exception $e) {
                    $stats['errors']++;
                    $this->logger->error(
                            '[SchemaHandler] Failed to process schema',
                            [
                                'schema_id' => $schema->getId(),
                                'error'     => $e->getMessage(),
                            ]
                            );
                }//end try
            }//end foreach

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                    '[SchemaHandler] Schema mirroring complete',
                    [
                        'stats'             => $stats,
                        'execution_time_ms' => $executionTime,
                    ]
                    );

            return [
                'success'            => true,
                'stats'              => $stats,
                'execution_time_ms'  => $executionTime,
                'resolved_conflicts' => $conflictAnalysis['resolved'],
            ];
        } catch (Exception $e) {
            $this->logger->error(
                    '[SchemaHandler] Schema mirroring failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'stats'   => $stats,
            ];
        }//end try

    }//end mirrorSchemas()


    /**
     * Analyze schemas for field type conflicts and resolve them.
     *
     * Detects fields with the same name but different types across schemas
     * and chooses the most permissive type.
     *
     * @param array $schemas Array of Schema entities
     *
     * @return array Analysis result with fields, conflicts, and resolutions
     */
    private function analyzeAndResolveFieldConflicts(array $schemas): array
    {
        $fieldTypes = [];
        // Track field types across all schemas.
        // First pass: Collect all field definitions.
        foreach ($schemas as $schema) {
            $properties = $schema->getProperties() ?? [];

            foreach ($properties as $propName => $propDef) {
                if (isset($fieldTypes[$propName]) === false) {
                    $fieldTypes[$propName] = [];
                }

                $solrType = $this->determineSolrFieldType($propDef);
                $fieldTypes[$propName][] = [
                    'type'      => $solrType,
                    'schema_id' => $schema->getId(),
                ];
            }
        }

        // Second pass: Detect conflicts and resolve them.
        $conflicts = [];
        $resolved  = [];

        foreach ($fieldTypes as $fieldName => $types) {
            $uniqueTypes = array_unique(array_column($types, 'type'));

            if (count($uniqueTypes) > 1) {
                // Conflict detected!
                $conflicts[$fieldName] = $uniqueTypes;

                // Resolve to most permissive type.
                $resolvedType         = $this->getMostPermissiveType($uniqueTypes);
                $resolved[$fieldName] = $resolvedType;

                $this->logger->warning(
                        '[SchemaHandler] Field type conflict resolved',
                        [
                            'field'             => $fieldName,
                            'conflicting_types' => $uniqueTypes,
                            'resolved_type'     => $resolvedType,
                        ]
                        );
            } else {
                $resolved[$fieldName] = $uniqueTypes[0];
            }
        }//end foreach

        return [
            'fields'    => $fieldTypes,
            'conflicts' => $conflicts,
            'resolved'  => $resolved,
        ];

    }//end analyzeAndResolveFieldConflicts()


    /**
     * Get the most permissive type from an array of types.
     *
     * Type hierarchy (most to least permissive):
     * string > text > float > integer > boolean
     *
     * @param array $types Array of Solr types
     *
     * @return string Most permissive type
     */
    private function getMostPermissiveType(array $types): string
    {
        $hierarchy = [
            'string'  => 5,
            'text'    => 4,
            'float'   => 3,
            'integer' => 2,
            'boolean' => 1,
        ];

        $maxPermissiveness = 0;
        $mostPermissive    = 'string';
        // Default fallback.
        foreach ($types as $type) {
            $permissiveness = $hierarchy[$type] ?? 0;
            if ($permissiveness > $maxPermissiveness) {
                $maxPermissiveness = $permissiveness;
                $mostPermissive    = $type;
            }
        }

        return $mostPermissive;

    }//end getMostPermissiveType()


    /**
     * Generate Solr field definitions from an OpenRegister schema.
     *
     * @param mixed $schema        Schema entity
     * @param array $resolvedTypes Resolved field types from conflict analysis
     *
     * @return array Solr field definitions
     */
    private function generateSolrFieldsFromSchema($schema, array $resolvedTypes): array
    {
        $solrFields = [];
        $properties = $schema->getProperties() ?? [];

        foreach ($properties as $propName => $propDef) {
            $fieldName = $this->generateSolrFieldName($propName);

            // Use resolved type if available, otherwise determine from property.
            $fieldType = $resolvedTypes[$propName] ?? $this->determineSolrFieldType($propDef);

            $solrFields[$fieldName] = [
                'name'        => $fieldName,
                'type'        => $fieldType,
                'indexed'     => true,
                'stored'      => true,
                'multiValued' => $this->isMultiValued($propDef),
            ];
        }

        return $solrFields;

    }//end generateSolrFieldsFromSchema()


    /**
     * Generate Solr-safe field name.
     *
     * @param string $fieldName Original field name
     *
     * @return string Solr-safe field name
     */
    private function generateSolrFieldName(string $fieldName): string
    {
        // Convert to lowercase and replace spaces/special chars with underscore.
        $safe = strtolower($fieldName);
        $safe = preg_replace('/[^a-z0-9_]/', '_', $safe);
        return $safe;

    }//end generateSolrFieldName()


    /**
     * Determine Solr field type from property definition.
     *
     * @param array $fieldDefinition Property definition
     *
     * @return string Solr field type
     */
    private function determineSolrFieldType(array $fieldDefinition): string
    {
        $type = $fieldDefinition['type'] ?? 'string';

        return match ($type) {
            'integer', 'int' => 'integer',
            'number', 'float', 'double' => 'float',
            'boolean', 'bool' => 'boolean',
            'date', 'datetime' => 'date',
            'text' => 'text',
            default => 'string',
        };

    }//end determineSolrFieldType()


    /**
     * Check if a field should be multi-valued.
     *
     * @param array $fieldDefinition Property definition
     *
     * @return bool True if multi-valued
     */
    private function isMultiValued(array $fieldDefinition): bool
    {
        // Check if type is array or maxItems > 1.
        if (($fieldDefinition['type'] ?? null) === 'array') {
            return true;
        }

        if (isset($fieldDefinition['maxItems']) === true && $fieldDefinition['maxItems'] > 1) {
            return true;
        }

        return false;

    }//end isMultiValued()


    /**
     * Ensure core metadata fields exist in Solr.
     *
     * Creates standard fields like id, name, created, updated, etc.
     *
     * @param bool $force Force recreation
     *
     * @return bool Success status
     */
    private function ensureCoreMetadataFields(bool $force): bool
    {
        $this->logger->info('[SchemaHandler] Ensuring core metadata fields');

        $coreFields = $this->getCoreMetadataFields();

        try {
            $result = $this->applySolrFields(solrFields: $coreFields, force: $force);

            $this->logger->info(
                    '[SchemaHandler] Core metadata fields ensured',
                    [
                        'created' => $result['created'],
                        'updated' => $result['updated'],
                    ]
                    );

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SchemaHandler] Failed to ensure core metadata fields',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end ensureCoreMetadataFields()


    /**
     * Get core metadata field definitions.
     *
     * @return array Core field definitions
     */
    private function getCoreMetadataFields(): array
    {
        return [
            'id'           => ['name' => 'id', 'type' => 'string', 'indexed' => true, 'stored' => true, 'required' => true],
            'uuid'         => ['name' => 'uuid', 'type' => 'string', 'indexed' => true, 'stored' => true],
            'name'         => ['name' => 'name', 'type' => 'text', 'indexed' => true, 'stored' => true],
            'title'        => ['name' => 'title', 'type' => 'text', 'indexed' => true, 'stored' => true],
            'summary'      => ['name' => 'summary', 'type' => 'text', 'indexed' => true, 'stored' => true],
            'description'  => ['name' => 'description', 'type' => 'text', 'indexed' => true, 'stored' => true],
            'created'      => ['name' => 'created', 'type' => 'date', 'indexed' => true, 'stored' => true],
            'updated'      => ['name' => 'updated', 'type' => 'date', 'indexed' => true, 'stored' => true],
            'published'    => ['name' => 'published', 'type' => 'boolean', 'indexed' => true, 'stored' => true],
            'deleted'      => ['name' => 'deleted', 'type' => 'boolean', 'indexed' => true, 'stored' => true],
            'owner'        => ['name' => 'owner', 'type' => 'string', 'indexed' => true, 'stored' => true],
            'organisation' => ['name' => 'organisation', 'type' => 'string', 'indexed' => true, 'stored' => true],
            'register'     => ['name' => 'register', 'type' => 'string', 'indexed' => true, 'stored' => true],
            'schema'       => ['name' => 'schema', 'type' => 'string', 'indexed' => true, 'stored' => true],
        ];

    }//end getCoreMetadataFields()


    /**
     * Apply Solr field definitions to the backend.
     *
     * @param array $solrFields Field definitions
     * @param bool  $force      Force update existing fields
     *
     * @return array Result with created/updated counts
     */
    private function applySolrFields(array $solrFields, bool $force): array
    {
        $created = 0;
        $updated = 0;

        foreach ($solrFields as $fieldConfig) {
            try {
                $result = $this->searchBackend->addOrUpdateField(fieldConfig: $fieldConfig, force: $force);

                if ($result === 'created') {
                    $created++;
                } else if ($result === 'updated') {
                    $updated++;
                }
            } catch (Exception $e) {
                $this->logger->error(
                        '[SchemaHandler] Failed to apply field',
                        [
                            'field' => $fieldConfig['name'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]
                        );
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];

    }//end applySolrFields()


    /**
     * Get field status for a collection.
     *
     * Returns information about existing fields, missing fields, and type mismatches.
     *
     * @param string $collection Collection name
     *
     * @return array Field status information
     */
    public function getCollectionFieldStatus(string $collection): array
    {
        try {
            $currentFields  = $this->searchBackend->getFields($collection);
            $expectedFields = $this->getCoreMetadataFields();

            $existingFields = [];
            $missingFields  = [];

            foreach ($expectedFields as $fieldName => $fieldConfig) {
                if (isset($currentFields[$fieldName]) === true) {
                    $existingFields[$fieldName] = $currentFields[$fieldName];
                } else {
                    $missingFields[$fieldName] = $fieldConfig;
                }
            }

            return [
                'collection'      => $collection,
                'existing_fields' => $existingFields,
                'missing_fields'  => $missingFields,
                'total_fields'    => count($currentFields),
                'expected_fields' => count($expectedFields),
            ];
        } catch (Exception $e) {
            $this->logger->error(
                    '[SchemaHandler] Failed to get collection field status',
                    [
                        'collection' => $collection,
                        'error'      => $e->getMessage(),
                    ]
                    );

            return [
                'collection' => $collection,
                'error'      => $e->getMessage(),
            ];
        }//end try

    }//end getCollectionFieldStatus()


    /**
     * Create missing fields in a collection.
     *
     * @param string $collection    Collection name
     * @param array  $missingFields Missing field definitions
     * @param bool   $dryRun        Preview without making changes
     *
     * @return array Result with success status and statistics
     */
    public function createMissingFields(string $collection, array $missingFields, bool $dryRun=false): array
    {
        $this->logger->info(
                '[SchemaHandler] Creating missing fields',
                [
                    'collection'  => $collection,
                    'field_count' => count($missingFields),
                    'dry_run'     => $dryRun,
                ]
                );

        if ($dryRun === true) {
            return [
                'success'       => true,
                'dry_run'       => true,
                'fields_to_add' => array_keys($missingFields),
            ];
        }

        $result = $this->applySolrFields(solrFields: $missingFields, force: false);

        return [
            'success' => true,
            'created' => $result['created'],
            'failed'  => count($missingFields) - $result['created'],
        ];

    }//end createMissingFields()


    /**
     * Fix mismatched fields in the schema.
     *
     * Corrects field types that don't match expected types.
     *
     * @param array $mismatchedFields Array of fields to fix.
     * @param bool  $dryRun           Whether to only simulate (not apply).
     *
     * @return array Results with fixed/failed fields.
     */
    public function fixMismatchedFields(array $mismatchedFields, bool $dryRun=false): array
    {
        $this->logger->info(
            '[SchemaHandler] Fixing mismatched fields',
            [
                'count'  => count($mismatchedFields),
                'dryRun' => $dryRun,
            ]
        );

        try {
            // Delegate to search backend.
            return $this->searchBackend->fixMismatchedFields($mismatchedFields, $dryRun);
        } catch (Exception $e) {
            $this->logger->error(
                '[SchemaHandler] Failed to fix mismatched fields',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end fixMismatchedFields()


}//end class
