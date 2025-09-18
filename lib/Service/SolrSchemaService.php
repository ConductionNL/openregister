<?php

declare(strict_types=1);

/**
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
 * @category Service  
 * @package  OCA\OpenRegister\Service
 * @author   OpenRegister Team
 * @copyright 2024 OpenRegister
 * @license  AGPL-3.0-or-later
 * @version  1.0.0
 * @link     https://github.com/OpenRegister/OpenRegister
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
     * @var array<string>
     */
    private const RESERVED_FIELDS = [
        'id', 'uuid', 'self_tenant', '_text_', '_version_'
    ];

    /**
     * Core metadata fields that should be defined in SOLR schema
     *
     * @var array<string, string> Field name => SOLR field type
     */
    private const CORE_METADATA_FIELDS = [
        // Object metadata
        'self_object_id' => 'pint',
        'self_uuid' => 'string',
        'self_tenant' => 'string',
        
        // Register metadata
        'self_register' => 'pint',
        'self_register_id' => 'pint',
        'self_register_uuid' => 'string',
        'self_register_slug' => 'string',
        
        // Schema metadata
        'self_schema' => 'pint',
        'self_schema_id' => 'pint', 
        'self_schema_uuid' => 'string',
        'self_schema_slug' => 'string',
        
        // Other core fields
        'self_organisation' => 'string',
        'self_owner' => 'string',
        'self_application' => 'string',
        'self_name' => 'string',
        'self_description' => 'text_general',
        'self_summary' => 'text_general',
        'self_image' => 'string',
        'self_slug' => 'string',
        'self_uri' => 'string',
        'self_version' => 'string',
        'self_size' => 'plong',
        'self_folder' => 'string',
        'self_locked' => 'boolean',
        'self_schema_version' => 'string',
        
        // Timestamps
        'self_created' => 'pdate',
        'self_updated' => 'pdate',
        'self_published' => 'pdate',
        'self_depublished' => 'pdate',
        
        // Complex fields
        'self_object' => 'text_general', // JSON storage
        'self_relations' => 'strings',   // Multi-valued UUIDs
        'self_files' => 'strings',       // Multi-valued file references
        'self_authorization' => 'text_general', // JSON
        'self_deleted' => 'text_general',       // JSON
        'self_validation' => 'text_general',    // JSON
        'self_groups' => 'text_general'         // JSON
    ];

    /**
     * Field type mappings from OpenRegister to SOLR
     *
     * @var array<string, string>
     */
    private array $fieldTypeMappings = [
        'string' => '_s',      // String, facetable
        'text' => '_t',        // Text, searchable  
        'integer' => '_i',     // Integer
        'number' => '_f',      // Float
        'boolean' => '_b',     // Boolean
        'date' => '_dt',       // Date/DateTime
        'array' => '_ss',      // String array
        'object' => '_json',   // JSON object (if supported)
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
    }

    /**
     * Mirror all OpenRegister schemas to SOLR for current tenant with intelligent conflict resolution
     *
     * **SMART CONFLICT RESOLUTION**: Analyzes all schemas first to detect field type conflicts
     * and chooses the most permissive type (string > text > float > integer > boolean).
     * This prevents errors like "versie" being integer in one schema and string in another.
     *
     * @param bool $force Force recreation of existing fields
     * @return array Result with success status and statistics
     */
    public function mirrorSchemas(bool $force = false): array
    {
        $startTime = microtime(true);
        $stats = [
            'schemas_processed' => 0,
            'fields_created' => 0,
            'fields_updated' => 0,
            'conflicts_resolved' => 0,
            'errors' => 0
        ];

        try {
            // Generate tenant information
            $tenantId = $this->generateTenantId();
            $organisationId = null; // For now, process all schemas regardless of organization
            
            $this->logger->info('🔄 Starting intelligent schema mirroring with conflict resolution', [
                'app' => 'openregister',
                'tenant_id' => $tenantId,
                'organisation_id' => $organisationId
            ]);

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
            
            $this->logger->info('✅ Intelligent schema mirroring completed', [
                'app' => 'openregister',
                'stats' => $stats,
                'execution_time_ms' => $executionTime,
                'resolved_conflicts' => $resolvedFields['conflict_details'] ?? []
            ]);

            return [
                'success' => true,
                'stats' => $stats,
                'execution_time_ms' => $executionTime,
                'resolved_conflicts' => $resolvedFields['conflict_details'] ?? []
            ];

        } catch (\Exception $e) {
            $this->logger->error('Schema mirroring failed', [
                'app' => 'openregister',
                'error' => $e->getMessage(),
                'stats' => $stats
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $stats
            ];
        }
    }

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
     * @param array $schemas Array of Schema entities to analyze
     * @return array Resolved field definitions with conflict details
     */
    private function analyzeAndResolveFieldConflicts(array $schemas): array
    {
        $fieldDefinitions = []; // [fieldName => [type => count, schemas => [schema_ids]]]
        $resolvedFields = [];
        $conflictDetails = [];
        $conflictsResolved = 0;

        $this->logger->info('🔍 Analyzing field conflicts across schemas', [
            'total_schemas' => count($schemas)
        ]);

        // STEP 1: Collect all field definitions from all schemas
        foreach ($schemas as $schema) {
            $schemaId = $schema->getId();
            $schemaTitle = $schema->getTitle();
            $schemaProperties = $schema->getProperties();
            
            if (empty($schemaProperties)) {
                continue;
            }

            // $schemaProperties is already an array from getProperties()
            $properties = $schemaProperties;
            if (!is_array($properties)) {
                $this->logger->warning('Invalid schema properties', [
                    'schema_id' => $schemaId,
                    'schema_title' => $schemaTitle,
                    'properties_type' => gettype($properties)
                ]);
                continue;
            }

            // Collect field definitions
            foreach ($properties as $fieldName => $fieldDefinition) {
                $fieldType = $fieldDefinition['type'] ?? 'string';
                
                // Skip reserved fields and metadata fields
                if (in_array($fieldName, self::RESERVED_FIELDS) || str_starts_with($fieldName, 'self_')) {
                    continue;
                }

                // Initialize field tracking
                if (!isset($fieldDefinitions[$fieldName])) {
                    $fieldDefinitions[$fieldName] = [
                        'types' => [],
                        'schemas' => [],
                        'definitions' => []
                    ];
                }

                // Track this field type and schema
                if (!isset($fieldDefinitions[$fieldName]['types'][$fieldType])) {
                    $fieldDefinitions[$fieldName]['types'][$fieldType] = 0;
                }
                $fieldDefinitions[$fieldName]['types'][$fieldType]++;
                $fieldDefinitions[$fieldName]['schemas'][] = ['id' => $schemaId, 'title' => $schemaTitle];
                $fieldDefinitions[$fieldName]['definitions'][] = $fieldDefinition;
            }
        }

        // STEP 2: Resolve conflicts by choosing most permissive type
        foreach ($fieldDefinitions as $fieldName => $fieldInfo) {
            $types = array_keys($fieldInfo['types']);
            
            if (count($types) > 1) {
                // CONFLICT DETECTED - resolve with most permissive type
                $resolvedType = $this->getMostPermissiveType($types);
                $conflictDetails[] = [
                    'field' => $fieldName,
                    'conflicting_types' => $fieldInfo['types'],
                    'resolved_type' => $resolvedType,
                    'schemas' => $fieldInfo['schemas']
                ];
                $conflictsResolved++;
                
                $this->logger->info('🔧 Field conflict resolved', [
                    'field' => $fieldName,
                    'conflicting_types' => $types,
                    'resolved_type' => $resolvedType,
                    'affected_schemas' => count($fieldInfo['schemas'])
                ]);
            } else {
                // No conflict - use the single type
                $resolvedType = $types[0];
            }

            // Create SOLR field definition with resolved type
            $solrFieldName = $this->generateSolrFieldName($fieldName, $fieldInfo['definitions'][0]);
            $solrFieldType = $this->determineSolrFieldType(['type' => $resolvedType] + $fieldInfo['definitions'][0]);
            
            if ($solrFieldName && $solrFieldType) {
                $resolvedFields[$solrFieldName] = [
                    'type' => $solrFieldType,
                    'stored' => true,
                    'indexed' => true,
                    'multiValued' => $this->isMultiValued($fieldInfo['definitions'][0]),
                    'facetable' => $fieldInfo['definitions'][0]['facetable'] ?? true
                ];
            }
        }

        $this->logger->info('✅ Field conflict analysis completed', [
            'total_fields' => count($fieldDefinitions),
            'conflicts_detected' => $conflictsResolved,
            'resolved_fields' => count($resolvedFields)
        ]);

        return [
            'fields' => $resolvedFields,
            'conflicts_resolved' => $conflictsResolved,
            'conflict_details' => $conflictDetails
        ];
    }

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
     * @param array $types List of conflicting field types
     * @return string Most permissive type that can accommodate all values
     */
    private function getMostPermissiveType(array $types): string
    {
        // Define type hierarchy from most to least permissive
        $typeHierarchy = [
            'string' => 100,
            'text' => 90,
            'float' => 80,
            'double' => 80,
            'number' => 80,
            'integer' => 70,
            'int' => 70,
            'boolean' => 60,
            'bool' => 60
        ];

        $maxPermissiveness = 0;
        $mostPermissiveType = 'string'; // Default fallback

        foreach ($types as $type) {
            $permissiveness = $typeHierarchy[strtolower($type)] ?? 50; // Unknown types get low priority
            if ($permissiveness > $maxPermissiveness) {
                $maxPermissiveness = $permissiveness;
                $mostPermissiveType = $type;
            }
        }

        return $mostPermissiveType;
    }

    /**
     * Generate tenant ID for multi-tenant SOLR collections
     *
     * Uses the same logic as GuzzleSolrService for consistency.
     *
     * @return string Tenant identifier for SOLR collection naming
     */
    private function generateTenantId(): string
    {
        $instanceId = $this->config->getSystemValue('instanceid', 'default');
        $overwriteHost = $this->config->getSystemValue('overwrite.cli.url', '');
        
        if (!empty($overwriteHost)) {
            return 'nc_' . hash('crc32', $overwriteHost);
        }
        
        return 'nc_' . substr($instanceId, 0, 8);
    }

    /**
     * Mirror a single OpenRegister schema to SOLR
     *
     * @param \OCA\OpenRegister\Db\Schema $schema OpenRegister schema entity
     * @param bool                        $force  Force update existing fields
     * @return array Field mapping results
     */
    private function mirrorSingleSchema($schema, bool $force = false): array
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
        $solrFields = [];

        // Convert OpenRegister properties to SOLR fields
        foreach ($properties as $fieldName => $fieldDefinition) {
            $solrFieldName = $this->generateSolrFieldName($fieldName, $fieldDefinition);
            $solrFieldType = $this->determineSolrFieldType($fieldDefinition);
            
            if ($solrFieldName && $solrFieldType) {
                $solrFields[$solrFieldName] = [
                    'type' => $solrFieldType,
                    'stored' => true,
                    'indexed' => true,
                    'multiValued' => $this->isMultiValued($fieldDefinition),
                    'facetable' => $fieldDefinition['facetable'] ?? true
                ];
                $fieldsCreated++;
            }
        }

        // Apply fields to SOLR collection
        if (!empty($solrFields)) {
            $this->applySolrFields($solrFields, $force);
        }

        $this->logger->debug('Schema mirrored', [
            'app' => 'openregister',
            'schema_id' => $schema->getId(),
            'schema_title' => $schema->getTitle(),
            'fields_processed' => $fieldsCreated,
            'solr_fields' => array_keys($solrFields)
        ]);

        return [
            'fields' => $fieldsCreated,
            'solr_fields' => $solrFields
        ];
    }

    /**
     * Generate SOLR field name with consistent self_ prefix (no suffixes needed)
     *
     * **Updated Field Naming Convention**:
     * - Object data fields: Direct mapping (e.g., `naam`, `beschrijving`)
     * - Metadata fields: `self_` prefix (e.g., `self_name`, `self_description`)
     * - Reserved fields: `id`, `uuid`, `self_tenant` (no additional prefix)
     *
     * @param string $fieldName       OpenRegister field name
     * @param array  $fieldDefinition Field definition from schema
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
    }

    /**
     * Determine SOLR field type from OpenRegister field definition
     *
     * @param array $fieldDefinition OpenRegister field definition
     * @return string SOLR field type
     */
    private function determineSolrFieldType(array $fieldDefinition): string
    {
        $type = $fieldDefinition['type'] ?? 'string';
        
        // Map OpenRegister types to SOLR types
        return match ($type) {
            'string' => $fieldDefinition['facetable'] ?? true ? 'string' : 'text_general',
            'text' => 'text_general',
            'integer', 'int' => 'pint',
            'number', 'float', 'double' => 'pfloat', 
            'boolean', 'bool' => 'boolean',
            'date', 'datetime' => 'pdate',
            'array' => 'strings', // Multi-valued string
            default => 'string'
        };
    }

    /**
     * Check if field should be multi-valued
     *
     * @param array $fieldDefinition Field definition
     * @return bool True if multi-valued
     */
    private function isMultiValued(array $fieldDefinition): bool
    {
        return ($fieldDefinition['type'] ?? '') === 'array' || 
               ($fieldDefinition['multiValued'] ?? false);
    }

    /**
     * Ensure core metadata fields exist in SOLR schema
     *
     * These are the essential fields needed for object indexing including
     * register and schema metadata (UUID, slug, etc.)
     *
     * @param bool $force Force update existing fields
     * @return bool Success status
     */
    private function ensureCoreMetadataFields(bool $force = false): bool
    {
        $this->logger->info('🔧 Ensuring core metadata fields in SOLR schema', [
            'field_count' => count(self::CORE_METADATA_FIELDS),
            'force' => $force
        ]);

        $successCount = 0;
        foreach (self::CORE_METADATA_FIELDS as $fieldName => $fieldType) {
            try {
                $fieldConfig = [
                    'type' => $fieldType,
                    'stored' => true,
                    'indexed' => true,
                    'multiValued' => in_array($fieldType, ['strings']) // Multi-valued for array fields
                ];

                if ($this->addOrUpdateSolrField($fieldName, $fieldConfig, $force)) {
                    $successCount++;
                    $this->logger->debug('✅ Core metadata field ensured', [
                        'field' => $fieldName,
                        'type' => $fieldType
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('❌ Failed to ensure core metadata field', [
                    'field' => $fieldName,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Core metadata fields processing completed', [
            'successful' => $successCount,
            'total' => count(self::CORE_METADATA_FIELDS)
        ]);

        return $successCount === count(self::CORE_METADATA_FIELDS);
    }

    /**
     * Apply SOLR fields to the tenant collection using Schema API
     *
     * @param array $solrFields SOLR field definitions
     * @param bool  $force      Force update existing fields
     * @return bool Success status
     */
    private function applySolrFields(array $solrFields, bool $force = false): bool
    {
        $this->logger->info('🔧 Applying SOLR fields via Schema API', [
            'app' => 'openregister',
            'field_count' => count($solrFields),
            'fields' => array_keys($solrFields),
            'force' => $force
        ]);

        $successCount = 0;
        foreach ($solrFields as $fieldName => $fieldConfig) {
            try {
                if ($this->addOrUpdateSolrField($fieldName, $fieldConfig, $force)) {
                    $successCount++;
                    $this->logger->info('✅ Applied SOLR field', [
                        'field' => $fieldName,
                        'type' => $fieldConfig['type']
                    ]);
                    // DEBUG: Special logging for versie field
                    if ($fieldName === 'versie') {
                        $this->logger->debug('=== VERSIE FIELD CREATED ===');
                        $this->logger->debug('Field: ' . $fieldName);
                        $this->logger->debug('Type: ' . $fieldConfig['type']);
                        $this->logger->debug('Config: ' . json_encode($fieldConfig));
                        $this->logger->debug('=== END VERSIE DEBUG ===');
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('❌ Failed to apply SOLR field', [
                    'field' => $fieldName,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Schema field application completed', [
            'successful' => $successCount,
            'total' => count($solrFields)
        ]);

        return $successCount === count($solrFields);
    }

    /**
     * Add or update a single SOLR field using Schema API
     *
     * @param string $fieldName   Field name
     * @param array  $fieldConfig Field configuration
     * @param bool   $force       Force update existing fields
     * @return bool Success status
     */
    private function addOrUpdateSolrField(string $fieldName, array $fieldConfig, bool $force = false): bool
    {
        // Get SOLR settings
        $solrConfig = $this->settingsService->getSolrSettings();
        $baseCollectionName = $solrConfig['core'] ?? 'openregister';
        
        // Build SOLR URL - handle Kubernetes service names properly
        $host = $solrConfig['host'] ?? 'localhost';
        $port = $solrConfig['port'] ?? null;
        
        // Normalize port - convert string '0' to null, handle empty strings
        if ($port === '0' || $port === '' || $port === null) {
            $port = null;
        } else {
            $port = (int)$port;
            if ($port === 0) {
                $port = null;
            }
        }
        
        // Check if it's a Kubernetes service name (contains .svc.cluster.local)
        if (strpos($host, '.svc.cluster.local') !== false) {
            // Kubernetes service - don't append port, it's handled by the service
            $url = sprintf('%s://%s%s/%s/schema',
                $solrConfig['scheme'] ?? 'http',
                $host,
                $solrConfig['path'] ?? '/solr',
                $baseCollectionName
            );
        } else {
            // Regular hostname - only append port if explicitly provided and not 0/null
            if ($port !== null && $port > 0) {
                $url = sprintf('%s://%s:%d%s/%s/schema',
                    $solrConfig['scheme'] ?? 'http',
                    $host,
                    $port,
                    $solrConfig['path'] ?? '/solr',
                    $baseCollectionName
                );
            } else {
                // No port provided - let the service handle it
                $url = sprintf('%s://%s%s/%s/schema',
                    $solrConfig['scheme'] ?? 'http',
                    $host,
                    $solrConfig['path'] ?? '/solr',
                    $baseCollectionName
                );
            }
        }

        // Try to add field first
        $payload = [
            'add-field' => array_merge(['name' => $fieldName], $fieldConfig)
        ];

        if ($this->makeSolrSchemaRequest($url, $payload)) {
            return true;
        }

        // If add failed and force is enabled, try to replace
        if ($force) {
            $payload = [
                'replace-field' => array_merge(['name' => $fieldName], $fieldConfig)
            ];
            return $this->makeSolrSchemaRequest($url, $payload);
        }

        return false;
    }

    /**
     * Make HTTP request to SOLR Schema API
     *
     * @param string $url     SOLR schema endpoint URL
     * @param array  $payload Request payload
     * @return bool Success status
     */
    private function makeSolrSchemaRequest(string $url, array $payload): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($payload),
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return ($data['responseHeader']['status'] ?? -1) === 0;
    }

    /**
     * Get schema mirroring statistics
     *
     * @return array Statistics about current schema state
     */
    public function getSchemaStats(): array
    {
        try {
            $tenantId = $this->settingsService->getTenantId();
            $organisationId = $this->settingsService->getOrganisationId();
            
            // Get schema counts
            $schemaCount = $this->schemaMapper->findAll(null, null, [$organisationId]);
            
            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'organisation_id' => $organisationId,
                'openregister_schemas' => count($schemaCount),
                'solr_collection' => $this->solrService->getTenantCollectionName(),
                'last_sync' => null // TODO: Track last sync time
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
