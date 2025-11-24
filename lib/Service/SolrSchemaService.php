<?php

declare(strict_types=1);

/*
 * SolrSchemaService - Multi-Tenant, Multi-App Schema Mirroring
 *
 * This service handles automatic mirroring of OpenRegister schemas to SOLR
 * with full multi-tenant and multi-app support. Each tenant collection gets
 * its own schema definition, with app-specific field prefixing to prevent
 * conflicts between different Nextcloud apps using the same SOLR instance.
 *
 * **Multi-App Architecture**:
 * - OpenRegister fields: `or_naam_s`, `or_beschrijving_t`
 * - Calendar fields: `cal_title_s`, `cal_start_dt`
 * - Other apps: `{app_prefix}_{field}_{type}`
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    OpenRegister Team
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * SOLR Schema Service for multi-tenant schema mirroring
 *
 * Automatically mirrors OpenRegister schemas to SOLR collections with:
 * - Tenant-specific schema isolation
 * - Organization-aware field mapping
 * - Dynamic field type detection
 * - Schema change synchronization
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    OpenRegister Team
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenRegister/OpenRegister
 * @version   1.0.0
 * @copyright 2024 OpenRegister
 */
class SolrSchemaService
{
    /**
     * App prefix for OpenRegister fields (prevents conflicts with other Nextcloud apps)
     *
     * @var string
     */
    private const APP_PREFIX = 'or';

    /**
     * Reserved SOLR field names (cannot be prefixed)
     *
     * These are SOLR system fields that should not be modified or prefixed.
     *
     * @var array<string>
     */
    private const RESERVED_FIELDS = [
        'id',
        'uuid',
        'self_tenant',
        '_text_',
        '_version_',
        '_root_',
        '_nest_path_',
        '_embedding_',
        '_embedding_model_',
        '_embedding_dim_',
        '_confidence_',
        '_classification_',
    ];

    /**
     * Core metadata fields for OBJECT collection
     *
     * Field configuration strategy:
     * - JSON storage fields (self_object, self_authorization, etc.): stored=true, indexed=false, docValues=false
     * - Facetable fields: stored=true, indexed=true, docValues=true (enables fast sorting/faceting)
     * - Non-facetable fields: stored=true, indexed=true, docValues=false (saves storage space)
     * - Field type determination: text→text_general, string→string (facetable property only controls docValues)
     * - AI/ML fields (_embedding_, _confidence_, etc.): stored=true, indexed=true, docValues=false (vector operations)
     * - AI metadata fields (_embedding_model_, _embedding_dim_): stored=true, indexed=true/false, docValues=true/false
     *
     * @var array<string, string> Field name => SOLR field type
     */
    private const CORE_METADATA_FIELDS = [
        // Primary identifier (required by SOLR)
        'id'                  => 'string',

        // Object metadata
        'self_object_id'      => 'pint',
        'self_uuid'           => 'string',
        'self_tenant'         => 'string',

        // Register metadata
        'self_register'       => 'pint',
        'self_register_id'    => 'pint',
        'self_register_uuid'  => 'string',
        'self_register_slug'  => 'string',

        // Schema metadata
        'self_schema'         => 'pint',
        'self_schema_id'      => 'pint',
        'self_schema_uuid'    => 'string',
        'self_schema_slug'    => 'string',

        // Other core fields
        'self_organisation'   => 'string',
        'self_owner'          => 'string',
        'self_application'    => 'string',
        'self_name'           => 'string',
        'self_description'    => 'text_general',
        'self_summary'        => 'text_general',
        'self_image'          => 'string',
        'self_slug'           => 'string',
        'self_uri'            => 'string',
        'self_version'        => 'string',
        'self_size'           => 'plong',
        'self_folder'         => 'string',
        'self_locked'         => 'boolean',
        'self_schema_version' => 'string',

        // Sortable string variants (for ordering on text fields)
        // These are single-valued, non-tokenized copies used for sorting/faceting
        'self_name_s'         => 'string',
        'self_description_s'  => 'string',
        'self_summary_s'      => 'string',
        'self_slug_s'         => 'string',

        // Timestamps
        'self_created'        => 'pdate',
        'self_updated'        => 'pdate',
        'self_published'      => 'pdate',
        'self_depublished'    => 'pdate',

        // Complex fields
        'self_object'         => 'string',
    // JSON storage - not indexed, only for reconstruction
        'self_relations'      => 'string',
    // Multi-valued UUIDs (multiValued=true)
        'self_files'          => 'string',
    // Multi-valued file references (multiValued=true)
        'self_authorization'  => 'string',
    // JSON storage - not indexed, only for reconstruction
        'self_deleted'        => 'string',
    // JSON storage - not indexed, only for reconstruction
        // AI/ML vector metadata fields
        // Note: Actual vector data is stored in oc_openregister_vectors table for efficiency
        // These fields track vectorization status for hybrid search coordination
        'vector_indexed'      => 'boolean',
    // Whether this object has vector embeddings
        'vector_model'        => 'string',
    // Model used for embeddings (e.g., "text-embedding-3-small")
        'vector_dimensions'   => 'pint',
    // Number of dimensions (e.g., 768, 1536)
        'vector_chunk_count'  => 'pint',
    // Number of chunks for this object
        'vector_updated'      => 'pdate',
    // When vectors were last generated
        'self_validation'     => 'string',
    // JSON storage - not indexed, only for reconstruction
        'self_groups'         => 'string',
    // JSON storage - not indexed, only for reconstruction
        // SOLR system fields that need explicit definition
        '_text_'              => 'text_general',
    // Catch-all full-text search field
        // AI/ML fields for future semantic search and classification features
        '_embedding_'         => 'pfloat',
    // Vector embeddings for semantic search (multiValued=true)
        '_embedding_model_'   => 'string',
    // Model identifier (e.g., 'openai-ada-002', 'sentence-transformers')
        '_embedding_dim_'     => 'pint',
    // Embedding dimension count for validation
        '_confidence_'        => 'pfloat',
    // ML confidence scores (0.0-1.0)
        '_classification_'    => 'string',
    // Auto-classification results (multiValued=true)
    ];

    /**
     * File metadata fields for FILE collection
     *
     * These fields are used for storing and searching file chunks with metadata.
     * Supports full-text search, faceting, and vector semantic search.
     *
     * @var array<string, string> Field name => SOLR field type
     */
    private const FILE_METADATA_FIELDS = [
        // Primary identifier (required by SOLR)
        'id'                  => 'string',

        // Nextcloud file metadata
        'file_id'             => 'plong',
    // Nextcloud file ID from oc_filecache
        'file_path'           => 'string',
    // Full path in Nextcloud
        'file_name'           => 'string',
    // File name with extension
        'file_basename'       => 'string',
    // Name without extension (for faceting)
        'file_extension'      => 'string',
    // File extension (pdf, docx, txt)
        'file_mime_type'      => 'string',
    // MIME type (application/pdf, text/plain)
        'file_size'           => 'plong',
    // File size in bytes
        'file_owner'          => 'string',
    // Nextcloud user who owns the file
        'file_created'        => 'pdate',
    // File creation timestamp
        'file_modified'       => 'pdate',
    // File modification timestamp
        'file_storage'        => 'pint',
    // Storage ID from Nextcloud
        'file_parent'         => 'plong',
    // Parent folder ID
        'file_checksum'       => 'string',
    // File checksum/hash for deduplication
        // File classification and tags
        'file_labels'         => 'string',
    // User-defined labels (multiValued=true)
        'file_tags'           => 'string',
    // Auto-generated tags (multiValued=true)
        'file_categories'     => 'string',
    // Categories (multiValued=true)
        'file_language'       => 'string',
    // Detected language (en, nl, de, etc.)
        // Chunking information
        'chunk_index'         => 'pint',
    // Chunk number (0-based)
        'chunk_total'         => 'pint',
    // Total number of chunks for this file
        'chunk_text'          => 'text_general',
    // The actual chunk text content
        'chunk_length'        => 'pint',
    // Length of this chunk in characters
        'chunk_start_offset'  => 'plong',
    // Start position in original file
        'chunk_end_offset'    => 'plong',
    // End position in original file
        'chunk_page_number'   => 'pint',
    // Page number (for PDFs, docs)
        // Full-text search fields
        'text_content'        => 'text_general',
    // Full extracted text for search
        'text_preview'        => 'string',
    // Short preview/summary
        'text_title'          => 'text_general',
    // Extracted title (from metadata or first heading)
        'text_author'         => 'string',
    // Extracted author from metadata
        'text_subject'        => 'string',
    // Document subject/topic
        // OCR and extraction metadata
        'ocr_performed'       => 'boolean',
    // Whether OCR was performed
        'ocr_confidence'      => 'pfloat',
    // OCR confidence score (0.0-1.0)
        'extraction_method'   => 'string',
    // Method used (text_extract, ocr, api)
        'extraction_date'     => 'pdate',
    // When text was extracted
        // Vector embedding metadata
        'vector_indexed'      => 'boolean',
    // Whether this chunk has vector embeddings
        'vector_model'        => 'string',
    // Model used for embeddings
        'vector_dimensions'   => 'pint',
    // Number of dimensions
        'vector_updated'      => 'pdate',
    // When vectors were last generated
        // Relationships and context
        'related_object_id'   => 'string',
    // Related object UUID (if attached to object)
        'related_object_type' => 'string',
    // Object type/schema
        'shared_with'         => 'string',
    // Users/groups with access (multiValued=true)
        'access_level'        => 'string',
    // Access level (public, shared, private)
        // Processing status
        'processing_status'   => 'string',
    // Status (pending, processed, failed, skipped)
        'processing_error'    => 'string',
    // Error message if processing failed
        'processing_date'     => 'pdate',
    // When processing completed
        // AI/ML fields
        '_text_'              => 'text_general',
    // Catch-all full-text search field
        '_embedding_'         => 'knn_vector',
    // Vector embeddings (dense vector for KNN search)
        '_embedding_model_'   => 'string',
    // Model identifier
        '_embedding_dim_'     => 'pint',
    // Embedding dimension count
        '_confidence_'        => 'pfloat',
    // ML confidence scores
        '_classification_'    => 'string',
    // Auto-classification results (multiValued=true)
    ];

    /**
     * Field type mappings from OpenRegister to SOLR
     *
     * @var array<string, string>
     */
    private array $fieldTypeMappings = [
        'string'  => '_s',
    // String, facetable
        'text'    => '_t',
    // Text, searchable
        'integer' => '_i',
    // Integer
        'number'  => '_f',
    // Float
        'boolean' => '_b',
    // Boolean
        'date'    => '_dt',
    // Date/DateTime
        'array'   => '_ss',
    // String array
        'object'  => '_json',
    // JSON object (if supported)
    ];


    /**
     * Constructor
     *
     * @param SchemaMapper      $schemaMapper    OpenRegister schema mapper
     * @param GuzzleSolrService $solrService     SOLR service for schema operations
     * @param SettingsService   $settingsService Settings service for tenant info
     * @param LoggerInterface   $logger          Logger for operations
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly GuzzleSolrService $solrService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly IConfig $config
    ) {

    }//end __construct()


    /**
     * Ensure knn_vector field type exists in Solr for dense vector search
     *
     * This method adds the knn_vector field type to the Solr schema if it doesn't exist.
     * Required for Solr 9+ dense vector search functionality.
     *
     * @param string $collection Collection name to configure
     * @param int    $dimensions Vector dimensions (default: 4096 for mistral:7b)
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
            $settings  = $this->settingsService->getSettings();
            $solrUrl   = $this->solrService->buildSolrBaseUrl();
            $schemaUrl = "{$solrUrl}/{$collection}/schema";

            // Check if knn_vector type already exists
            $checkUrl = "{$schemaUrl}/fieldtypes/knn_vector";

            $requestOptions = [
                'timeout' => 30,
                'headers' => ['Accept' => 'application/json'],
            ];

            // Add authentication
            $username = $settings['solr']['username'] ?? null;
            $password = $settings['solr']['password'] ?? null;
            if ($username && $password) {
                $requestOptions['auth'] = [$username, $password];
            }

            try {
                $response = $this->solrService->getHttpClient()->get($checkUrl, $requestOptions);
                $data     = json_decode((string) $response->getBody(), true);

                if (isset($data['fieldType'])) {
                    $this->logger->info(
                            'knn_vector field type already exists',
                            [
                                'collection' => $collection,
                            ]
                            );
                    return true;
                    // Already exists
                }
            } catch (\Exception $e) {
                // Field type doesn't exist, continue to create it
                $this->logger->debug(
                        'knn_vector field type not found, creating',
                        [
                            'collection' => $collection,
                        ]
                        );
            }//end try

            // Add knn_vector field type
            $payload = [
                'add-field-type' => [
                    'name'               => 'knn_vector',
                    'class'              => 'solr.DenseVectorField',
                    'vectorDimension'    => $dimensions,
                    'similarityFunction' => $similarity,
                ],
            ];

            $requestOptions['body'] = json_encode($payload);
            $requestOptions['headers']['Content-Type'] = 'application/json';

            $response     = $this->solrService->getHttpClient()->post($schemaUrl, $requestOptions);
            $responseData = json_decode((string) $response->getBody(), true);

            if (($responseData['responseHeader']['status'] ?? -1) === 0) {
                $this->logger->info(
                        '✅ knn_vector field type created successfully',
                        [
                            'collection' => $collection,
                            'dimensions' => $dimensions,
                            'similarity' => $similarity,
                        ]
                        );
                return true;
            }

            $this->logger->error(
                    'Failed to create knn_vector field type',
                    [
                        'response' => $responseData,
                    ]
                    );
            return false;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Exception creating knn_vector field type',
                    [
                        'error'      => $e->getMessage(),
                        'collection' => $collection,
                    ]
                    );
            return false;
        }//end try

    }//end ensureVectorFieldType()


    /**
     * Mirror all OpenRegister schemas to SOLR for current tenant with intelligent conflict resolution
     *
     * **SMART CONFLICT RESOLUTION**: Analyzes all schemas first to detect field type conflicts
     * and chooses the most permissive type (string > text > float > integer > boolean).
     * This prevents errors like "versie" being integer in one schema and string in another.
     *
     * @param  bool $force Force recreation of existing fields
     * @return array Result with success status and statistics
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
            // Generate tenant information
            $tenantId       = $this->generateTenantId();
            $organisationId = null;
            // For now, process all schemas regardless of organization
            $this->logger->info(
                    '🔄 Starting intelligent schema mirroring with conflict resolution',
                    [
                        'app'             => 'openregister',
                        'tenant_id'       => $tenantId,
                        'organisation_id' => $organisationId,
                    ]
                    );

            // Ensure tenant collection exists
            if (!$this->solrService->ensureTenantCollection()) {
                throw new \Exception('Failed to ensure tenant collection exists');
            }

            // Get all schemas (process all schemas regardless of organization for conflict resolution)
            $schemas = $this->schemaMapper->findAll();

            // STEP 1: Analyze all schemas to detect field conflicts and resolve them
            $resolvedFields = $this->analyzeAndResolveFieldConflicts($schemas);
            $stats['conflicts_resolved'] = $resolvedFields['conflicts_resolved'];

            // STEP 2: Ensure core metadata fields exist in SOLR schema
            $this->ensureCoreMetadataFields($force);
            $stats['core_fields_created'] = count(self::CORE_METADATA_FIELDS);

            // STEP 3: Apply resolved field definitions to SOLR
            if (!empty($resolvedFields['fields'])) {
                $this->applySolrFields($resolvedFields['fields'], $force);
                $stats['fields_created'] = count($resolvedFields['fields']);
            }

            $stats['schemas_processed'] = count($schemas);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                    '✅ Intelligent schema mirroring completed',
                    [
                        'app'                => 'openregister',
                        'stats'              => $stats,
                        'execution_time_ms'  => $executionTime,
                        'resolved_conflicts' => $resolvedFields['conflict_details'] ?? [],
                    ]
                    );

            return [
                'success'            => true,
                'stats'              => $stats,
                'execution_time_ms'  => $executionTime,
                'resolved_conflicts' => $resolvedFields['conflict_details'] ?? [],
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Schema mirroring failed',
                    [
                        'app'   => 'openregister',
                        'error' => $e->getMessage(),
                        'stats' => $stats,
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
     * Analyze all schemas and resolve field type conflicts with intelligent type selection
     *
     * **CONFLICT RESOLUTION STRATEGY**:
     * When the same field exists in multiple schemas with different types, we choose the
     * most permissive type that can accommodate all values:
     *
     * **Type Priority (Most → Least Permissive)**:
     * 1. `string` - Can store any value (numbers, text, booleans as strings)
     * 2. `text` - Can store any text content with full-text search
     * 3. `float` - Can store integers and decimals
     * 4. `integer` - Can only store whole numbers
     * 5. `boolean` - Can only store true/false
     *
     * **Example**: Field `versie` appears as:
     * - Schema 132: `versie` = "string" (values: "onbekend", "v2.0")
     * - Schema 67: `versie` = "integer" (values: 123, 456)
     * - **Resolution**: `versie` = "string" (can store both text and numbers)
     *
     * @param  array $schemas Array of Schema entities to analyze
     * @return array Resolved field definitions with conflict details
     */
    private function analyzeAndResolveFieldConflicts(array $schemas): array
    {
        $fieldDefinitions = [];
        // [fieldName => [type => count, schemas => [schema_ids]]]
        $resolvedFields    = [];
        $conflictDetails   = [];
        $conflictsResolved = 0;

        $this->logger->info(
                '🔍 Analyzing field conflicts across schemas',
                [
                    'total_schemas' => count($schemas),
                ]
                );

        // STEP 1: Collect all field definitions from all schemas
        foreach ($schemas as $schema) {
            $schemaId         = $schema->getId();
            $schemaTitle      = $schema->getTitle();
            $schemaProperties = $schema->getProperties();

            if (empty($schemaProperties)) {
                continue;
            }

            // $schemaProperties is already an array from getProperties()
            $properties = $schemaProperties;
            if (!is_array($properties)) {
                $this->logger->warning(
                        'Invalid schema properties',
                        [
                            'schema_id'       => $schemaId,
                            'schema_title'    => $schemaTitle,
                            'properties_type' => gettype($properties),
                        ]
                        );
                continue;
            }

            // Collect field definitions
            foreach ($properties as $fieldName => $fieldDefinition) {
                $fieldType = $fieldDefinition['type'] ?? 'string';

                // Skip reserved fields and metadata fields
                // Cast fieldName to string to handle numeric keys
                $fieldNameStr = (string) $fieldName;
                if (in_array($fieldNameStr, self::RESERVED_FIELDS) || str_starts_with($fieldNameStr, 'self_')) {
                    continue;
                }

                // Initialize field tracking
                if (!isset($fieldDefinitions[$fieldNameStr])) {
                    $fieldDefinitions[$fieldNameStr] = [
                        'types'       => [],
                        'schemas'     => [],
                        'definitions' => [],
                    ];
                }

                // Track this field type and schema
                if (!isset($fieldDefinitions[$fieldNameStr]['types'][$fieldType])) {
                    $fieldDefinitions[$fieldNameStr]['types'][$fieldType] = 0;
                }

                $fieldDefinitions[$fieldNameStr]['types'][$fieldType]++;
                $fieldDefinitions[$fieldNameStr]['schemas'][]     = ['id' => $schemaId, 'title' => $schemaTitle];
                $fieldDefinitions[$fieldNameStr]['definitions'][] = $fieldDefinition;
            }//end foreach
        }//end foreach

        // STEP 2: Resolve conflicts by choosing most permissive type
        foreach ($fieldDefinitions as $fieldName => $fieldInfo) {
            // Cast fieldName to string to handle numeric keys
            $fieldNameStr = (string) $fieldName;

            $types = array_keys($fieldInfo['types']);

            if (count($types) > 1) {
                // CONFLICT DETECTED - resolve with most permissive type
                $resolvedType      = $this->getMostPermissiveType($types);
                $conflictDetails[] = [
                    'field'             => $fieldNameStr,
                    'conflicting_types' => $fieldInfo['types'],
                    'resolved_type'     => $resolvedType,
                    'schemas'           => $fieldInfo['schemas'],
                ];
                $conflictsResolved++;

                $this->logger->info(
                        '🔧 Field conflict resolved',
                        [
                            'field'             => $fieldNameStr,
                            'conflicting_types' => $types,
                            'resolved_type'     => $resolvedType,
                            'affected_schemas'  => count($fieldInfo['schemas']),
                        ]
                        );
            } else {
                // No conflict - use the single type
                $resolvedType = $types[0];
            }//end if

            // Create SOLR field definition with resolved type
            $solrFieldName = $this->generateSolrFieldName($fieldNameStr, $fieldInfo['definitions'][0]);
            $solrFieldType = $this->determineSolrFieldType(['type' => $resolvedType] + $fieldInfo['definitions'][0]);

            if ($solrFieldName && $solrFieldType) {
                // Apply most permissive settings by checking ALL definitions
                // If ANY schema has facetable=true, the field should support faceting
                // If ANY schema is multi-valued, the field should be multi-valued
                $isFacetable   = false;
                $isMultiValued = false;

                foreach ($fieldInfo['definitions'] as $definition) {
                    if (($definition['facetable'] ?? false) === true) {
                        $isFacetable = true;
                    }

                    if ($this->isMultiValued($definition)) {
                        $isMultiValued = true;
                    }
                }

                $resolvedFields[$solrFieldName] = [
                    'type'        => $solrFieldType,
                    'stored'      => true,
                    'indexed'     => true,
                    'multiValued' => $isMultiValued,
                    'docValues'   => $isFacetable,
                // docValues enabled when ANY schema needs faceting
                    'facetable'   => $isFacetable,
                ];

                $this->logger->debug(
                        'Field definition resolved',
                        [
                            'field'               => $solrFieldName,
                            'type'                => $solrFieldType,
                            'multiValued'         => $isMultiValued,
                            'facetable'           => $isFacetable,
                            'definitions_checked' => count($fieldInfo['definitions']),
                        ]
                        );
            }//end if
        }//end foreach

        $this->logger->info(
                '✅ Field conflict analysis completed',
                [
                    'total_fields'       => count($fieldDefinitions),
                    'conflicts_detected' => $conflictsResolved,
                    'resolved_fields'    => count($resolvedFields),
                ]
                );

        return [
            'fields'             => $resolvedFields,
            'conflicts_resolved' => $conflictsResolved,
            'conflict_details'   => $conflictDetails,
        ];

    }//end analyzeAndResolveFieldConflicts()


    /**
     * Determine the most permissive type from a list of conflicting types
     *
     * **Type Permissiveness Hierarchy** (most permissive first):
     * 1. `string` - Universal container (can hold any value as text)
     * 2. `text` - Text with full-text search capabilities
     * 3. `float`/`double`/`number` - Can hold integers and decimals
     * 4. `integer`/`int` - Can only hold whole numbers
     * 5. `boolean` - Can only hold true/false values
     *
     * @param  array $types List of conflicting field types
     * @return string Most permissive type that can accommodate all values
     */
    private function getMostPermissiveType(array $types): string
    {
        // Define type hierarchy from most to least permissive
        $typeHierarchy = [
            'string'  => 100,
            'text'    => 90,
            'float'   => 80,
            'double'  => 80,
            'number'  => 80,
            'integer' => 70,
            'int'     => 70,
            'boolean' => 60,
            'bool'    => 60,
        ];

        $maxPermissiveness  = 0;
        $mostPermissiveType = 'string';
        // Default fallback
        foreach ($types as $type) {
            $permissiveness = $typeHierarchy[strtolower($type)] ?? 50;
            // Unknown types get low priority
            if ($permissiveness > $maxPermissiveness) {
                $maxPermissiveness  = $permissiveness;
                $mostPermissiveType = $type;
            }
        }

        return $mostPermissiveType;

    }//end getMostPermissiveType()


    /**
     * Generate tenant ID for multi-tenant SOLR collections
     *
     * Uses the same logic as GuzzleSolrService for consistency.
     *
     * @return string Tenant identifier for SOLR collection naming
     */
    private function generateTenantId(): string
    {
        $instanceId    = $this->config->getSystemValue('instanceid', 'default');
        $overwriteHost = $this->config->getSystemValue('overwrite.cli.url', '');

        if (!empty($overwriteHost)) {
            return 'nc_'.hash('crc32', $overwriteHost);
        }

        return 'nc_'.substr($instanceId, 0, 8);

    }//end generateTenantId()


    /**
     * Mirror a single OpenRegister schema to SOLR
     *
     * @param  \OCA\OpenRegister\Db\Schema $schema OpenRegister schema entity
     * @param  bool                        $force  Force update existing fields
     * @return array Field mapping results
     */
    private function mirrorSingleSchema($schema, bool $force=false): array
    {
        $schemaProperties = $schema->getProperties();
        if (empty($schemaProperties)) {
            return ['fields' => 0, 'message' => 'No properties to mirror'];
        }

        $properties = json_decode($schemaProperties, true);
        if (!is_array($properties)) {
            return ['fields' => 0, 'message' => 'Invalid schema properties JSON'];
        }

        $fieldsCreated = 0;
        $solrFields    = [];

        // Convert OpenRegister properties to SOLR fields
        foreach ($properties as $fieldName => $fieldDefinition) {
            $solrFieldName = $this->generateSolrFieldName($fieldName, $fieldDefinition);
            $solrFieldType = $this->determineSolrFieldType($fieldDefinition);

            if ($solrFieldName && $solrFieldType) {
                $isFacetable = $fieldDefinition['facetable'] ?? true;

                // **FILE TYPE HANDLING**: File fields should not be indexed to avoid size limits
                $type        = $fieldDefinition['type'] ?? 'string';
                $format      = $fieldDefinition['format'] ?? '';
                $isFileField = ($type === 'file' || $format === 'file' || $format === 'binary' ||
                              in_array($format, ['data-url', 'base64', 'image', 'document']));

                $solrFields[$solrFieldName] = [
                    'type'        => $solrFieldType,
                    'stored'      => true,
                    'indexed'     => !$isFileField,
                // File fields are stored but not indexed
                    'multiValued' => $this->isMultiValued($fieldDefinition),
                    'docValues'   => $isFacetable && !$isFileField,
                // File fields can't have docValues
                    'facetable'   => $isFacetable && !$isFileField,
                // File fields can't be faceted
                ];
                $fieldsCreated++;
            }//end if
        }//end foreach

        // Apply fields to SOLR collection
        if (!empty($solrFields)) {
            $this->applySolrFields($solrFields, $force);
        }

        $this->logger->debug(
                'Schema mirrored',
                [
                    'app'              => 'openregister',
                    'schema_id'        => $schema->getId(),
                    'schema_title'     => $schema->getTitle(),
                    'fields_processed' => $fieldsCreated,
                    'solr_fields'      => array_keys($solrFields),
                ]
                );

        return [
            'fields'      => $fieldsCreated,
            'solr_fields' => $solrFields,
        ];

    }//end mirrorSingleSchema()


    /**
     * Generate SOLR field name with consistent self_ prefix (no suffixes needed)
     *
     * **Updated Field Naming Convention**:
     * - Object data fields: Direct mapping (e.g., `naam`, `beschrijving`)
     * - Metadata fields: `self_` prefix (e.g., `self_name`, `self_description`)
     * - Reserved fields: `id`, `uuid`, `self_tenant` (no additional prefix)
     *
     * @param  string $fieldName       OpenRegister field name
     * @param  array  $fieldDefinition Field definition from schema
     * @return string SOLR field name with consistent naming
     */
    private function generateSolrFieldName(string $fieldName, array $fieldDefinition): string
    {
        // Don't prefix reserved fields
        if (in_array($fieldName, self::RESERVED_FIELDS)) {
            return $fieldName;
        }

        // Clean field name (SOLR field names have restrictions)
        $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName);

        // Use direct field names for object data (no prefixes or suffixes needed with explicit schema)
        return $cleanName;

    }//end generateSolrFieldName()


    /**
     * Determine SOLR field type from OpenRegister field definition
     *
     * **Field Type Mapping Rules**:
     * - `text` type → always `text_general` (for full-text search)
     * - `string` type → always `string` (for exact matching, IDs, codes)
     * - `facetable` property → controls `docValues` only, NOT field type
     * - `docValues` → `true` if facetable, `false` if not facetable
     *
     * **Why this matters**:
     * - `text_general`: Analyzed for full-text search (descriptions, content)
     * - `string`: Exact matching for IDs, codes, names, faceting
     * - `docValues`: Enables fast sorting/faceting but uses more storage
     *
     * @param  array $fieldDefinition OpenRegister field definition
     * @return string SOLR field type
     */
    private function determineSolrFieldType(array $fieldDefinition): string
    {
        $type   = $fieldDefinition['type'] ?? 'string';
        $format = $fieldDefinition['format'] ?? '';

        // **FILE TYPE HANDLING**: File fields should use text_general for large content
        if ($type === 'file' || $format === 'file' || $format === 'binary'
            || in_array($format, ['data-url', 'base64', 'image', 'document'])
        ) {
            return 'text_general';
            // Large text type for file content
        }

        // Map OpenRegister types to SOLR types
        // Type determination should be based on data semantics, not facetability
        return match ($type) {
            'string' => 'string',
            // Exact values, IDs, codes, etc.
            'text' => 'text_general',
            // Full-text searchable content
            'integer', 'int' => 'pint',
            'number', 'float', 'double' => 'pfloat',
            'boolean', 'bool' => 'boolean',
            'date', 'datetime' => 'pdate',
            'array' => 'string',
            // Multi-valued string (type=string, multiValued=true)
            'file' => 'text_general',
            // File content (large text)
            default => 'string'
        };

    }//end determineSolrFieldType()


    /**
     * Check if field should be multi-valued based strictly on schema property type
     *
     * Only fields with type 'array' in the OpenRegister schema should be multi-valued in SOLR.
     * This prevents issues where string fields incorrectly become multi-valued due to
     * different SOLR configurations between environments.
     *
     * @param  array $fieldDefinition Field definition from OpenRegister schema
     * @return bool True if field should be multi-valued
     */
    private function isMultiValued(array $fieldDefinition): bool
    {
        // STRICT: Only array type should be multi-valued
        return ($fieldDefinition['type'] ?? '') === 'array';

    }//end isMultiValued()


    /**
     * Determine if a core metadata field should be multi-valued
     *
     * Only specific core fields that legitimately store multiple values should be multi-valued.
     * This prevents accidental multi-value configuration for single-value fields.
     *
     * @param  string $fieldName Core field name
     * @param  string $fieldType SOLR field type
     * @return bool True if field should be multi-valued
     */
    private function isCoreFieldMultiValued(string $fieldName, string $fieldType): bool
    {
        // Only these core fields are legitimately multi-valued
        $multiValuedCoreFields = [
            'self_relations',
        // Array of UUID references
            'self_files',
        // Array of file references
            '_classification_',
        // Auto-classification results (multi-valued strings)
        ];

        // NOTE: _embedding_ is NOT multi-valued! It's a single knn_vector (dense vector)
        // The vector itself contains an array of floats, but that's internal to the type
        return in_array($fieldName, $multiValuedCoreFields);

    }//end isCoreFieldMultiValued()


    /**
     * Determine if a core metadata field should be indexed
     *
     * Some core fields like JSON storage fields should be stored but not indexed
     * since they're only used for reconstruction, not searching.
     *
     * @param  string $fieldName Core field name
     * @return bool True if field should be indexed
     */
    private function shouldCoreFieldBeIndexed(string $fieldName): bool
    {
        // Fields that should NOT be indexed (stored only for reconstruction)
        $nonIndexedFields = [
            'self_object',
        // JSON blob for object reconstruction
            'self_authorization',
        // JSON blob for permissions
            'self_deleted',
        // JSON blob for deletion metadata
            'self_validation',
        // JSON blob for validation results
            'self_groups',
        // JSON blob for group assignments
            '_embedding_dim_',
        // Dimension count - stored for validation, not searched
        ];

        return !in_array($fieldName, $nonIndexedFields);

    }//end shouldCoreFieldBeIndexed()


    /**
     * Determine if a core metadata field should have docValues enabled
     *
     * docValues enable fast sorting, faceting, grouping, and function queries.
     * They should be enabled for fields that are used for:
     * - Sorting (e.g., name, created, updated dates)
     * - Faceting (e.g., owner, organisation, schema, register)
     * - Grouping operations
     *
     * JSON storage fields should have docValues=false to save storage space.
     *
     * @param  string $fieldName Core field name
     * @return bool True if field should have docValues enabled
     */
    private function shouldCoreFieldHaveDocValues(string $fieldName): bool
    {
        // Fields that should have docValues enabled for sorting/faceting/grouping
        $docValuesFields = [
            // Sortable fields
            'self_name',
        // Sort by name
            'self_created',
        // Sort by creation date
            'self_updated',
        // Sort by update date
            'self_published',
        // Sort by publication date
            // Facetable fields
            'self_owner',
        // Facet by owner
            'self_organisation',
        // Facet by organisation
            'self_application',
        // Facet by application
            'self_schema',
        // Facet by schema ID
            'self_schema_id',
        // Facet by schema ID
            'self_register',
        // Facet by register ID
            'self_register_id',
        // Facet by register ID
            // UUID fields for exact matching and grouping
            'self_uuid',
        // Exact UUID matching
            'self_schema_uuid',
        // Schema UUID matching
            'self_register_uuid',
        // Register UUID matching
            // Slug fields for URL-friendly lookups
            'self_slug',
        // URL slug lookup
            'self_schema_slug',
        // Schema slug lookup
            'self_register_slug',
        // Register slug lookup
            // Other metadata that might be used for filtering
            'self_object_id',
        // Object ID filtering
            'self_tenant',
        // Tenant filtering
            'self_version',
        // Version filtering
            'self_size',
        // Size-based sorting/filtering
            'self_locked',
        // Locked status filtering
        ];

        // Special handling for system fields
        if ($fieldName === '_text_') {
            return false;
            // Full-text search fields don't need docValues
        }

        // AI/ML fields configuration
        if (in_array($fieldName, ['_embedding_', '_confidence_', '_classification_'])) {
            return false;
            // Vector and classification fields don't need docValues for sorting
        }

        if (in_array($fieldName, ['_embedding_model_', '_embedding_dim_', 'vector_indexed', 'vector_model', 'vector_dimensions'])) {
            return true;
            // Metadata fields that might be used for filtering/faceting
        }

        return in_array($fieldName, $docValuesFields);

    }//end shouldCoreFieldHaveDocValues()


    /**
     * Determine if a file metadata field should be multi-valued
     *
     * @param  string $fieldName File field name
     * @param  string $fieldType Field type
     * @return bool True if field should be multi-valued
     */
    private function isFileFieldMultiValued(string $fieldName, string $fieldType): bool
    {
        // Multi-valued file fields
        $multiValuedFileFields = [
            'file_labels',
        // User-defined labels
            'file_tags',
        // Auto-generated tags
            'file_categories',
        // Categories
            'shared_with',
        // Users/groups with access
            '_embedding_',
        // Vector embeddings (multi-valued floats)
            '_classification_',
        // Auto-classification results
        ];

        return in_array($fieldName, $multiValuedFileFields);

    }//end isFileFieldMultiValued()


    /**
     * Determine if a file metadata field should be indexed
     *
     * @param  string $fieldName File field name
     * @return bool True if field should be indexed
     */
    private function shouldFileFieldBeIndexed(string $fieldName): bool
    {
        // Fields that should NOT be indexed (stored only for metadata/reconstruction)
        $nonIndexedFields = [
            'file_checksum',
        // Only for deduplication, not searching
            'processing_error',
        // Only for debugging, not searching
            '_embedding_dim_',
        // Dimension count - stored for validation, not searched
        ];

        return !in_array($fieldName, $nonIndexedFields);

    }//end shouldFileFieldBeIndexed()


    /**
     * Determine if a file metadata field should have docValues enabled
     *
     * @param  string $fieldName File field name
     * @return bool True if field should have docValues enabled
     */
    private function shouldFileFieldHaveDocValues(string $fieldName): bool
    {
        // Fields that should have docValues enabled for sorting/faceting/grouping
        $docValuesFields = [
            // Sortable fields
            'file_name',
        // Sort by name
            'file_size',
        // Sort by size
            'file_created',
        // Sort by creation date
            'file_modified',
        // Sort by modification date
            'chunk_index',
        // Sort chunks by index
            'vector_updated',
        // Sort by vector update date
            'processing_date',
        // Sort by processing date
            // Facetable fields
            'file_extension',
        // Facet by file type
            'file_mime_type',
        // Facet by MIME type
            'file_owner',
        // Facet by owner
            'file_labels',
        // Facet by labels
            'file_tags',
        // Facet by tags
            'file_categories',
        // Facet by categories
            'file_language',
        // Facet by language
            'processing_status',
        // Facet by status
            'access_level',
        // Facet by access level
            'extraction_method',
        // Facet by extraction method
            // Vector metadata
            'vector_indexed',
        // Filter by vectorization status
            'vector_model',
        // Filter by model
            'vector_dimensions',
        // Filter by dimensions
            // Relationship fields
            'file_id',
        // Nextcloud file ID lookup
            'related_object_id',
        // Related object lookup
            'related_object_type',
        // Related object type lookup
            // OCR fields
            'ocr_performed',
        // Filter by OCR status
        ];

        // Special handling for system fields
        if (in_array($fieldName, ['_text_', 'chunk_text', 'text_content'])) {
            return false;
            // Full-text search fields don't need docValues
        }

        // AI/ML fields configuration
        if (in_array($fieldName, ['_embedding_', '_confidence_', '_classification_'])) {
            return false;
            // Vector and classification fields don't need docValues
        }

        return in_array($fieldName, $docValuesFields);

    }//end shouldFileFieldHaveDocValues()


    /**
     * Ensure core metadata fields exist in SOLR schema
     *
     * These are the essential fields needed for object indexing including
     * register and schema metadata (UUID, slug, etc.)
     *
     * @param  bool $force Force update existing fields
     * @return bool Success status
     */
    private function ensureCoreMetadataFields(bool $force=false): bool
    {
        $this->logger->info(
                '🔧 Ensuring core metadata fields in SOLR schema',
                [
                    'field_count' => count(self::CORE_METADATA_FIELDS),
                    'force'       => $force,
                ]
                );

        // STEP 1: Ensure knn_vector field type exists (required for _embedding_ field)
        try {
            $settings         = $this->settingsService->getSettings();
            $objectCollection = $settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? null;
            $fileCollection   = $settings['solr']['fileCollection'] ?? null;

            if ($objectCollection) {
                $this->ensureVectorFieldType($objectCollection, 4096, 'cosine');
            }

            if ($fileCollection) {
                $this->ensureVectorFieldType($fileCollection, 4096, 'cosine');
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to ensure knn_vector field type',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
        }

        // STEP 2: Ensure core metadata fields
        $successCount = 0;
        foreach (self::CORE_METADATA_FIELDS as $fieldName => $fieldType) {
            try {
                $fieldConfig = [
                    'type'        => $fieldType,
                    'stored'      => true,
                    'indexed'     => $this->shouldCoreFieldBeIndexed($fieldName),
                    'multiValued' => $this->isCoreFieldMultiValued($fieldName, $fieldType),
                    'docValues'   => $this->shouldCoreFieldHaveDocValues($fieldName),
                ];

                if ($this->addOrUpdateSolrField($fieldName, $fieldConfig, $force)) {
                    $successCount++;
                    $this->logger->debug(
                            '✅ Core metadata field ensured',
                            [
                                'field' => $fieldName,
                                'type'  => $fieldType,
                            ]
                            );
                }
            } catch (\Exception $e) {
                $this->logger->error(
                        '❌ Failed to ensure core metadata field',
                        [
                            'field' => $fieldName,
                            'error' => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $this->logger->info(
                'Core metadata fields processing completed',
                [
                    'successful' => $successCount,
                    'total'      => count(self::CORE_METADATA_FIELDS),
                ]
                );

        return $successCount === count(self::CORE_METADATA_FIELDS);

    }//end ensureCoreMetadataFields()


    /**
     * Ensure file metadata fields exist in file collection
     *
     * @param  bool $force Force update existing fields
     * @return bool Success status
     */
    private function ensureFileMetadataFields(bool $force=false): bool
    {
        $this->logger->info(
                '🔧 Ensuring file metadata fields in SOLR schema',
                [
                    'field_count' => count(self::FILE_METADATA_FIELDS),
                    'force'       => $force,
                ]
                );

        $successCount = 0;
        foreach (self::FILE_METADATA_FIELDS as $fieldName => $fieldType) {
            try {
                $fieldConfig = [
                    'type'        => $fieldType,
                    'stored'      => true,
                    'indexed'     => $this->shouldFileFieldBeIndexed($fieldName),
                    'multiValued' => $this->isFileFieldMultiValued($fieldName, $fieldType),
                    'docValues'   => $this->shouldFileFieldHaveDocValues($fieldName),
                ];

                if ($this->addOrUpdateSolrField($fieldName, $fieldConfig, $force)) {
                    $successCount++;
                    $this->logger->debug(
                            '✅ File metadata field ensured',
                            [
                                'field' => $fieldName,
                                'type'  => $fieldType,
                            ]
                            );
                }
            } catch (\Exception $e) {
                $this->logger->error(
                        '❌ Failed to ensure file metadata field',
                        [
                            'field' => $fieldName,
                            'error' => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $this->logger->info(
                'File metadata fields processing completed',
                [
                    'successful' => $successCount,
                    'total'      => count(self::FILE_METADATA_FIELDS),
                ]
                );

        return $successCount === count(self::FILE_METADATA_FIELDS);

    }//end ensureFileMetadataFields()


    /**
     * Get missing and extra fields in object collection
     *
     * @return array{missing: array<string, array>, extra: array<string>, expected: array<string>, current: array<string>, status: 'complete'|'incomplete', collection: string}
     */
    public function getObjectCollectionFieldStatus(): array
    {
        // Get object collection from settings
        $settings         = $this->settingsService->getSettings();
        $objectCollection = $settings['solr']['objectCollection'] ?? null;
        if (!$objectCollection) {
            // Fall back to default collection if object collection not configured
            $objectCollection = $settings['solr']['collection'] ?? 'openregister';
        }

        // Get current fields from SOLR for object collection
        $current = $this->getCurrentCollectionFields($objectCollection);

        // Expected fields with their config
        $expected      = self::CORE_METADATA_FIELDS;
        $expectedNames = array_keys($expected);

        // Find missing fields (expected but not in SOLR)
        $missingNames = array_diff($expectedNames, $current);
        $missing      = [];
        foreach ($missingNames as $fieldName) {
            $fieldType = $expected[$fieldName];

            // Determine if field should be multi-valued (fields ending in _ss, _is, etc.)
            $multiValued = str_ends_with($fieldName, '_ss') ||
                          str_ends_with($fieldName, '_is') ||
                          str_ends_with($fieldName, '_ls') ||
                          str_ends_with($fieldName, '_ts') ||
                          str_ends_with($fieldName, '_ds') ||
                          str_ends_with($fieldName, '_bs');

            // Determine if field should have docValues (for sorting/faceting)
            // String fields and numeric fields typically need docValues for faceting
            $docValues = in_array($fieldType, ['string', 'pint', 'plong', 'pfloat', 'pdouble', 'pdate']) &&
                        !str_starts_with($fieldName, 'self_object') &&
            // JSON storage fields don't need docValues
                        !str_starts_with($fieldName, 'self_schema') &&
                        !str_starts_with($fieldName, 'self_register') &&
                        !str_ends_with($fieldName, '_json');

            $missing[$fieldName] = [
                'type'        => $fieldType,
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => $multiValued,
                'docValues'   => $docValues,
            ];
        }//end foreach

        // Find extra fields (in SOLR but not expected)
        $extra = array_diff($current, $expectedNames);

        return [
            'missing'    => $missing,
            'extra'      => array_values($extra),
            'expected'   => $expectedNames,
            'current'    => $current,
            'status'     => empty($missing) ? 'complete' : 'incomplete',
            'collection' => $objectCollection,
        ];

    }//end getObjectCollectionFieldStatus()


    /**
     * Get missing and extra fields in file collection
     *
     * @return array{missing: array<string, array>, extra: array<string>, expected: array<string>, current: array<string>, status: 'complete'|'incomplete', collection: string}
     */
    public function getFileCollectionFieldStatus(): array
    {
        // Get file collection from settings
        $settings       = $this->settingsService->getSettings();
        $fileCollection = $settings['solr']['fileCollection'] ?? null;
        if (!$fileCollection) {
            // File collection might not be configured yet
            $fileCollection = 'openregister_files';
        }

        // Get current fields from SOLR for file collection
        $current = $this->getCurrentCollectionFields($fileCollection);

        // Expected fields with their config
        $expected      = self::FILE_METADATA_FIELDS;
        $expectedNames = array_keys($expected);

        // Find missing fields (expected but not in SOLR)
        $missingNames = array_diff($expectedNames, $current);
        $missing      = [];
        foreach ($missingNames as $fieldName) {
            $fieldType = $expected[$fieldName];

            // Determine if field should be multi-valued (fields ending in _ss, _is, etc.)
            $multiValued = str_ends_with($fieldName, '_ss') ||
                          str_ends_with($fieldName, '_is') ||
                          str_ends_with($fieldName, '_ls') ||
                          str_ends_with($fieldName, '_ts') ||
                          str_ends_with($fieldName, '_ds') ||
                          str_ends_with($fieldName, '_bs');

            // Determine if field should have docValues (for sorting/faceting)
            // String fields and numeric fields typically need docValues for faceting
            $docValues = in_array($fieldType, ['string', 'pint', 'plong', 'pfloat', 'pdouble', 'pdate']) &&
                        !str_ends_with($fieldName, '_text') &&
            // Full-text fields don't need docValues
                        !str_ends_with($fieldName, '_content') &&
                        !str_ends_with($fieldName, '_json');

            $missing[$fieldName] = [
                'type'        => $fieldType,
                'stored'      => true,
                'indexed'     => true,
                'multiValued' => $multiValued,
                'docValues'   => $docValues,
            ];
        }//end foreach

        // Find extra fields (in SOLR but not expected)
        $extra = array_diff($current, $expectedNames);

        return [
            'missing'    => $missing,
            'extra'      => array_values($extra),
            'expected'   => $expectedNames,
            'current'    => $current,
            'status'     => empty($missing) ? 'complete' : 'incomplete',
            'collection' => $fileCollection,
        ];

    }//end getFileCollectionFieldStatus()


    /**
     * Get current field names from a specific SOLR collection
     *
     * @param string $collectionName Collection to query
     *
     * @return array<string> Field names
     */
    private function getCurrentCollectionFields(string $collectionName): array
    {
        try {
            // Build schema API URL for specific collection
            $schemaUrl = $this->solrService->buildSolrBaseUrl()."/{$collectionName}/schema";

            // Prepare request options
            $solrConfig     = $this->settingsService->getSettings()['solr'] ?? [];
            $requestOptions = [
                'timeout' => $solrConfig['timeout'] ?? 30,
                'headers' => ['Accept' => 'application/json'],
            ];

            // Add authentication if configured
            if (!empty($solrConfig['username']) && !empty($solrConfig['password'])) {
                $requestOptions['auth'] = [
                    $solrConfig['username'],
                    $solrConfig['password'],
                ];
            }

            // Make the schema request
            $httpClient   = \OC::$server->get(\OCP\Http\Client\IClientService::class)->newClient();
            $response     = $httpClient->get($schemaUrl, $requestOptions);
            $responseBody = $response->getBody();
            $schemaData   = json_decode($responseBody, true);

            if (!$schemaData || !isset($schemaData['schema']['fields'])) {
                $this->logger->warning(
                        'No fields data returned from SOLR',
                        [
                            'collection' => $collectionName,
                            'response'   => substr($responseBody, 0, 500),
                // Log first 500 chars for debugging
                        ]
                        );
                return [];
            }

            // Extract field names
            $fieldNames = [];
            foreach ($schemaData['schema']['fields'] as $field) {
                if (isset($field['name'])) {
                    $fieldNames[] = $field['name'];
                }
            }

            return $fieldNames;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get current collection fields',
                    [
                        'collection' => $collectionName,
                        'error'      => $e->getMessage(),
                    ]
                    );
            return [];
        }//end try

    }//end getCurrentCollectionFields()


    /**
     * Apply SOLR fields to the tenant collection using Schema API
     *
     * @param  array $solrFields SOLR field definitions
     * @param  bool  $force      Force update existing fields
     * @return bool Success status
     */
    private function applySolrFields(array $solrFields, bool $force=false): bool
    {
        $this->logger->info(
                '🔧 Applying SOLR fields via Schema API',
                [
                    'app'         => 'openregister',
                    'field_count' => count($solrFields),
                    'fields'      => array_keys($solrFields),
                    'force'       => $force,
                ]
                );

        $successCount = 0;
        foreach ($solrFields as $fieldName => $fieldConfig) {
            try {
                if ($this->addOrUpdateSolrField($fieldName, $fieldConfig, $force)) {
                    $successCount++;
                    $this->logger->info(
                            '✅ Applied SOLR field',
                            [
                                'field' => $fieldName,
                                'type'  => $fieldConfig['type'],
                            ]
                            );
                    // DEBUG: Special logging for versie field
                    if ($fieldName === 'versie') {
                        $this->logger->debug('=== VERSIE FIELD CREATED ===');
                        $this->logger->debug('Field: '.$fieldName);
                        $this->logger->debug('Type: '.$fieldConfig['type']);
                        $this->logger->debug('Config: '.json_encode($fieldConfig));
                        $this->logger->debug('=== END VERSIE DEBUG ===');
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error(
                        '❌ Failed to apply SOLR field',
                        [
                            'field' => $fieldName,
                            'error' => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $this->logger->info(
                'Schema field application completed',
                [
                    'successful' => $successCount,
                    'total'      => count($solrFields),
                ]
                );

        return $successCount === count($solrFields);

    }//end applySolrFields()


    /**
     * Add or update a single SOLR field using Schema API
     *
     * @param  string $fieldName   Field name
     * @param  array  $fieldConfig Field configuration
     * @param  bool   $force       Force update existing fields
     * @return bool Success status
     */
    private function addOrUpdateSolrField(string $fieldName, array $fieldConfig, bool $force=false): bool
    {
        // Get SOLR settings
        $solrConfig         = $this->settingsService->getSolrSettings();
        $baseCollectionName = $solrConfig['core'] ?? 'openregister';

        // Build SOLR URL - handle Kubernetes service names properly
        $host = $solrConfig['host'] ?? 'localhost';
        $port = $solrConfig['port'] ?? null;

        // Normalize port - convert string '0' to null, handle empty strings
        if ($port === '0' || $port === '' || $port === null) {
            $port = null;
        } else {
            $port = (int) $port;
            if ($port === 0) {
                $port = null;
            }
        }

        // Check if it's a Kubernetes service name (contains .svc.cluster.local)
        if (strpos($host, '.svc.cluster.local') !== false) {
            // Kubernetes service - don't append port, it's handled by the service
            $url = sprintf(
                    '%s://%s%s/%s/schema',
                $solrConfig['scheme'] ?? 'http',
                $host,
                $solrConfig['path'] ?? '/solr',
                $baseCollectionName
            );
        } else {
            // Regular hostname - only append port if explicitly provided and not 0/null
            if ($port !== null && $port > 0) {
                $url = sprintf(
                        '%s://%s:%d%s/%s/schema',
                    $solrConfig['scheme'] ?? 'http',
                    $host,
                    $port,
                    $solrConfig['path'] ?? '/solr',
                    $baseCollectionName
                );
            } else {
                // No port provided - let the service handle it
                $url = sprintf(
                        '%s://%s%s/%s/schema',
                    $solrConfig['scheme'] ?? 'http',
                    $host,
                    $solrConfig['path'] ?? '/solr',
                    $baseCollectionName
                );
            }
        }//end if

        // Try to add field first
        $payload = [
            'add-field' => array_merge(['name' => $fieldName], $fieldConfig),
        ];

        if ($this->makeSolrSchemaRequest($url, $payload)) {
            return true;
        }

        // If add failed and force is enabled, try to replace
        if ($force) {
            $payload = [
                'replace-field' => array_merge(['name' => $fieldName], $fieldConfig),
            ];
            return $this->makeSolrSchemaRequest($url, $payload);
        }

        return false;

    }//end addOrUpdateSolrField()


    /**
     * Make HTTP request to SOLR Schema API
     *
     * @param  string $url     SOLR schema endpoint URL
     * @param  array  $payload Request payload
     * @return bool Success status
     */
    private function makeSolrSchemaRequest(string $url, array $payload): bool
    {
        $context = stream_context_create(
                [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => 'Content-Type: application/json',
                        'content' => json_encode($payload),
                        'timeout' => 30,
                    ],
                ]
                );

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return ($data['responseHeader']['status'] ?? -1) === 0;

    }//end makeSolrSchemaRequest()


    /**
     * Get schema mirroring statistics
     *
     * @return array Statistics about current schema state
     */
    public function getSchemaStats(): array
    {
        try {
            $tenantId       = $this->settingsService->getTenantId();
            $organisationId = $this->settingsService->getOrganisationId();

            // Get schema counts
            $schemaCount = $this->schemaMapper->findAll(null, null, [$organisationId]);

            return [
                'success'              => true,
                'tenant_id'            => $tenantId,
                'organisation_id'      => $organisationId,
                'openregister_schemas' => count($schemaCount),
                'solr_collection'      => $this->solrService->getTenantCollectionName(),
                'last_sync'            => null,
            // TODO: Track last sync time
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end getSchemaStats()


    /**
     * Create missing fields in a specific collection
     *
     * @param string $collectionType Type of collection ('objects' or 'files')
     * @param array  $missingFields  Array of missing field configurations
     * @param bool   $dryRun         If true, only simulate field creation
     *
     * @return array Creation result with statistics
     */
    public function createMissingFields(string $collectionType, array $missingFields, bool $dryRun=false): array
    {
        $this->logger->info(
                'Creating missing fields for collection',
                [
                    'collection_type' => $collectionType,
                    'field_count'     => count($missingFields),
                    'dry_run'         => $dryRun,
                ]
                );

        $startTime = microtime(true);
        $created   = [];
        $errors    = [];

        // Get the appropriate collection name
        $settings   = $this->settingsService->getSettings();
        $collection = $collectionType === 'files' ? ($settings['solr']['fileCollection'] ?? null) : ($settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? 'openregister');

        if (!$collection) {
            return [
                'success'       => false,
                'message'       => "No collection configured for type: {$collectionType}",
                'created_count' => 0,
                'error_count'   => 1,
            ];
        }

        foreach ($missingFields as $fieldName => $fieldConfig) {
            try {
                if ($dryRun) {
                    $created[] = $fieldName;
                    continue;
                }

                // Add field to SOLR using the schema API
                $result = $this->addFieldToCollection(
                    $collection,
                    $fieldName,
                    $fieldConfig
                );

                if ($result) {
                    $created[] = $fieldName;
                    $this->logger->debug(
                            'Created field in SOLR',
                            [
                                'field'      => $fieldName,
                                'collection' => $collection,
                            ]
                            );
                } else {
                    $errors[$fieldName] = 'Failed to create field';
                }
            } catch (\Exception $e) {
                $errors[$fieldName] = $e->getMessage();
                $this->logger->error(
                        'Failed to create field',
                        [
                            'field'      => $fieldName,
                            'collection' => $collection,
                            'error'      => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'success'           => empty($errors),
            'message'           => sprintf(
                '%s: %d created, %d errors',
                $dryRun ? 'Dry run' : 'Created fields',
                count($created),
                count($errors)
            ),
            'collection'        => $collection,
            'collection_type'   => $collectionType,
            'created'           => $created,
            'created_count'     => count($created),
            'errors'            => $errors,
            'error_count'       => count($errors),
            'execution_time_ms' => $executionTime,
            'dry_run'           => $dryRun,
        ];

    }//end createMissingFields()


    /**
     * Add a field to a SOLR collection using the Schema API
     *
     * @param string $collection  Collection name
     * @param string $fieldName   Field name
     * @param array  $fieldConfig Field configuration
     *
     * @return bool True if successful
     */
    private function addFieldToCollection(string $collection, string $fieldName, array $fieldConfig): bool
    {
        $settings  = $this->settingsService->getSettings();
        $solrUrl   = $this->solrService->buildSolrBaseUrl();
        $schemaUrl = "{$solrUrl}/{$collection}/schema";

        // Prepare field definition
        $fieldDef = [
            'name'    => $fieldName,
            'type'    => $fieldConfig['type'],
            'stored'  => $fieldConfig['stored'] ?? true,
            'indexed' => $fieldConfig['indexed'] ?? true,
        ];

        // Add multiValued if specified
        if (isset($fieldConfig['multiValued'])) {
            $fieldDef['multiValued'] = $fieldConfig['multiValued'];
        }

        // Add docValues if specified
        if (isset($fieldConfig['docValues'])) {
            $fieldDef['docValues'] = $fieldConfig['docValues'];
        }

        $payload = ['add-field' => $fieldDef];

        // Prepare request options
        $requestOptions = [
            'body'    => json_encode($payload),
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ];

        // Add authentication if configured
        $username = $settings['solr']['username'] ?? null;
        $password = $settings['solr']['password'] ?? null;
        if ($username && $password) {
            $requestOptions['auth'] = [$username, $password];
        }

        try {
            // Get HTTP client from server
            $httpClient   = \OC::$server->get(\OCP\Http\Client\IClientService::class)->newClient();
            $response     = $httpClient->post($schemaUrl, $requestOptions);
            $responseBody = $response->getBody();
            $data         = json_decode($responseBody, true);

            return ($data['responseHeader']['status'] ?? -1) === 0;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to add field to collection',
                    [
                        'collection' => $collection,
                        'field'      => $fieldName,
                        'error'      => $e->getMessage(),
                    ]
                    );
            return false;
        }

    }//end addFieldToCollection()


}//end class
