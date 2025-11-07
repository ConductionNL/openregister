<?php
/**
 * Object Vectorization Strategy
 *
 * Strategy for vectorizing OpenRegister objects.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Vectorization;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * ObjectVectorizationStrategy
 *
 * Handles object-specific vectorization logic.
 *
 * OPTIONS:
 * - views: array|null - View IDs to filter (null = all views)
 * - batch_size: int - Number of objects per batch
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 */
class ObjectVectorizationStrategy implements VectorizationStrategyInterface
{
    /**
     * Object service
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param ObjectService   $objectService    Object service
     * @param SettingsService $settingsService  Settings service
     * @param LoggerInterface $logger           Logger
     */
    public function __construct(
        ObjectService $objectService,
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->objectService = $objectService;
        $this->settingsService = $settingsService;
        $this->logger = $logger;
    }

    /**
     * Fetch objects to vectorize based on views
     *
     * @param array $options Options: views, batch_size
     *
     * @return array Array of ObjectEntity objects
     */
    public function fetchEntities(array $options): array
    {
        $views = $options['views'] ?? null;
        $limit = $options['batch_size'] ?? 25;

        $this->logger->debug('[ObjectVectorizationStrategy] Fetching objects', [
            'views' => $views,
            'limit' => $limit,
        ]);

        // Get objects using ObjectService with view support
        $objects = $this->objectService->searchObjects(
            query: [
                '_limit' => $limit,
                '_source' => 'database',
            ],
            rbac: false,
            multi: false,
            ids: null,
            uses: null,
            views: $views
        );

        // searchObjects returns array of ObjectEntity objects directly
        $this->logger->debug('[ObjectVectorizationStrategy] Fetched objects', [
            'count' => is_array($objects) ? count($objects) : 0,
        ]);

        return $objects;
    }

    /**
     * Extract text from object by serializing it
     *
     * @param mixed $entity ObjectEntity
     *
     * @return array Array with single item containing serialized object
     */
    public function extractVectorizationItems($entity): array
    {
        // Get object data
        $objectData = is_array($entity) ? $entity : $entity->jsonSerialize();

        // Get vectorization config
        $config = $this->settingsService->getObjectSettingsOnly();

        // Serialize object to text
        $text = $this->serializeObject($objectData, $config);

        // Objects produce a single vectorization item
        return [
            [
                'text' => $text,
                'index' => 0,
            ],
        ];
    }

    /**
     * Prepare metadata for object vector
     *
     * @param mixed $entity ObjectEntity
     * @param array $item   Vectorization item
     *
     * @return array Metadata for storage
     */
    public function prepareVectorMetadata($entity, array $item): array
    {
        $objectData = is_array($entity) ? $entity : $entity->jsonSerialize();
        $objectId = $objectData['id'] ?? 'unknown';

        // Extract title/name - check multiple possible fields
        $title = $objectData['title'] 
            ?? $objectData['name'] 
            ?? $objectData['_name'] 
            ?? $objectData['summary'] 
            ?? $this->extractFirstStringField($objectData) // Try to find ANY string field
            ?? 'Object #' . $objectId;

        // Extract description - check common variants
        $description = $objectData['description'] 
            ?? $objectData['_description'] 
            ?? $objectData['Beschrijving']  // Dutch variant
            ?? $objectData['beschrijving']  // Lowercase variant
            ?? $objectData['summary'] 
            ?? $objectData['_summary'] 
            ?? '';

        return [
            'entity_type' => 'object',
            'entity_id' => (string) $objectId,
            'chunk_index' => 0,
            'total_chunks' => 1,
            'chunk_text' => substr($item['text'], 0, 500), // Preview
            'additional_metadata' => [
                'object_id' => $objectId,
                'object_title' => $title,          // ADDED for display
                'title' => $title,                 // ADDED for backward compatibility
                'name' => $title,                  // ADDED for alternative lookup
                'description' => $description,      // ADDED for context
                'register' => $objectData['_register'] ?? null,
                'register_id' => $objectData['_register'] ?? null,
                'schema' => $objectData['_schema'] ?? null,
                'schema_id' => $objectData['_schema'] ?? null,
                'uuid' => $objectData['uuid'] ?? $objectData['_uuid'] ?? null,
                'uri' => $objectData['uri'] ?? $objectData['_uri'] ?? null,
            ],
        ];
    }

    /**
     * Extract the first suitable string field from object data
     *
     * This is a fallback when standard title/name fields don't exist.
     * Looks for short, meaningful string values that could serve as identifiers.
     *
     * @param array $objectData Object data
     *
     * @return string|null First suitable string field value
     */
    private function extractFirstStringField(array $objectData): ?string
    {
        // Skip metadata fields (prefixed with _ or @)
        // Look for short, meaningful strings (< 100 chars)
        foreach ($objectData as $key => $value) {
            // Skip metadata and system fields
            if (str_starts_with($key, '_') || str_starts_with($key, '@')) {
                continue;
            }
            
            // Skip known non-title fields
            $skipFields = ['id', 'uuid', 'description', 'Beschrijving', 'beschrijving', 'content', 'text'];
            if (in_array(strtolower($key), array_map('strtolower', $skipFields))) {
                continue;
            }
            
            // Check if it's a short string (likely a title/identifier)
            if (is_string($value) && strlen($value) > 0 && strlen($value) < 100) {
                return $value;
            }
        }
        
        return null;
    }

    /**
     * Get object ID as identifier
     *
     * @param mixed $entity ObjectEntity
     *
     * @return string|int Object ID
     */
    public function getEntityIdentifier($entity)
    {
        $objectData = is_array($entity) ? $entity : $entity->jsonSerialize();
        return $objectData['id'] ?? 'unknown';
    }

    /**
     * Serialize object to text for vectorization
     *
     * @param array $object Object data
     * @param array $config Vectorization configuration
     *
     * @return string Serialized text
     */
    private function serializeObject(array $object, array $config): string
    {
        // TODO: Implement configurable serialization
        // For now, just JSON encode with pretty print for readability
        $includeMetadata = $config['includeMetadata'] ?? true;
        $includeRelations = $config['includeRelations'] ?? true;
        $maxNestingDepth = $config['maxNestingDepth'] ?? 10;

        $this->logger->debug('[ObjectVectorizationStrategy] Serializing object', [
            'objectId' => $object['id'] ?? 'unknown',
            'includeMetadata' => $includeMetadata,
            'includeRelations' => $includeRelations,
            'maxNestingDepth' => $maxNestingDepth,
        ]);

        // Simple JSON serialization
        // Future enhancement: smart serialization based on schema
        return json_encode($object, JSON_PRETTY_PRINT);
    }
}

