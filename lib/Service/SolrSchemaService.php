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
        'id', 'uuid', 'tenant_id', '_text_', '_version_'
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
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Mirror all OpenRegister schemas to SOLR for current tenant
     *
     * This method analyzes the tenant's OpenRegister schemas and creates
     * corresponding SOLR field definitions in the tenant's collection.
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
            'errors' => 0
        ];

        try {
            // Get tenant information
            $tenantId = $this->settingsService->getTenantId();
            $organisationId = $this->settingsService->getOrganisationId();
            
            $this->logger->info('🔄 Starting schema mirroring', [
                'app' => 'openregister',
                'tenant_id' => $tenantId,
                'organisation_id' => $organisationId
            ]);

            // Ensure tenant collection exists
            if (!$this->solrService->ensureTenantCollection()) {
                throw new \Exception('Failed to ensure tenant collection exists');
            }

            // Get all schemas for this organization
            $schemas = $this->schemaMapper->findAll(null, null, [$organisationId]);
            
            foreach ($schemas as $schema) {
                try {
                    $this->mirrorSingleSchema($schema, $force);
                    $stats['schemas_processed']++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to mirror schema', [
                        'app' => 'openregister',
                        'schema_id' => $schema->getId(),
                        'error' => $e->getMessage()
                    ]);
                    $stats['errors']++;
                }
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->info('✅ Schema mirroring completed', [
                'app' => 'openregister',
                'stats' => $stats,
                'execution_time_ms' => $executionTime
            ]);

            return [
                'success' => true,
                'stats' => $stats,
                'execution_time_ms' => $executionTime
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
     * Generate SOLR field name with app prefix and appropriate suffix
     *
     * **Multi-App Field Naming Convention**:
     * - OpenRegister: `or_naam_s`, `or_beschrijving_t`
     * - Other apps: `{prefix}_{field}_{type}`
     * - Reserved fields: `id`, `uuid`, `tenant_id` (no prefix)
     *
     * @param string $fieldName       OpenRegister field name
     * @param array  $fieldDefinition Field definition from schema
     * @return string SOLR field name with app prefix and suffix
     */
    private function generateSolrFieldName(string $fieldName, array $fieldDefinition): string
    {
        // Don't prefix reserved fields
        if (in_array($fieldName, self::RESERVED_FIELDS)) {
            return $fieldName;
        }

        $type = $fieldDefinition['type'] ?? 'string';
        $suffix = $this->fieldTypeMappings[$type] ?? '_s';
        
        // Clean field name (SOLR field names have restrictions)
        $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName);
        
        // Apply multi-app naming: {app_prefix}_{field}_{type_suffix}
        return self::APP_PREFIX . '_' . $cleanName . $suffix;
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
     * Apply SOLR fields to the tenant collection
     *
     * @param array $solrFields SOLR field definitions
     * @param bool  $force      Force update existing fields
     * @return bool Success status
     */
    private function applySolrFields(array $solrFields, bool $force = false): bool
    {
        // This would use SOLR Schema API to add fields
        // For now, we'll log what would be done
        
        $this->logger->info('🔧 Would apply SOLR fields', [
            'app' => 'openregister',
            'field_count' => count($solrFields),
            'fields' => array_keys($solrFields),
            'force' => $force
        ]);

        // TODO: Implement actual SOLR Schema API calls
        // POST /solr/{collection}/schema
        // {
        //   "add-field": {
        //     "name": "naam_s",
        //     "type": "string", 
        //     "stored": true,
        //     "indexed": true
        //   }
        // }

        return true;
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
