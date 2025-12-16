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

namespace OCA\OpenRegister\Service\Vectorization\Strategies;

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
     * @param ObjectService   $objectService   Object service
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        ObjectService $objectService,
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->objectService   = $objectService;
        $this->settingsService = $settingsService;
        $this->logger          = $logger;

    }//end __construct()


    /**
     * Fetch objects to vectorize based on views
     *
     * @param array $options Options: views, batch_size
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[] Array of ObjectEntity objects
     *
     * @psalm-return array<int, \OCA\OpenRegister\Db\ObjectEntity>
     */
    public function fetchEntities(array $options): array
    {
        $views = $options['views'] ?? null;
        $limit = $options['batch_size'] ?? 25;

        $this->logger->debug(
                '[ObjectVectorizationStrategy] Fetching objects',
                [
                    'views' => $views,
                    'limit' => $limit,
                ]
                );

        // Get objects using ObjectService with view support.
        $result = $this->objectService->searchObjects(
            query: [
                '_limit'  => $limit,
                '_source' => 'database',
            ],
            _rbac: false,
            _multitenancy: false,
            ids: null,
            uses: null,
            views: $views
        );

        // SearchObjects can return array|int, but we need array for vectorization.
        $objects = [];
        if (is_array($result) === true) {
            $objects = $result;
        }

        $count = count($objects);

        $this->logger->debug(
                '[ObjectVectorizationStrategy] Fetched objects',
                [
                    'count' => $count,
                ]
                );

        return $objects;

    }//end fetchEntities()


    /**
     * Extract text from object by serializing it
     *
     * @param mixed $entity ObjectEntity
     *
     * @return (int|string)[][] Array with single item containing serialized object
     *
     * @psalm-return list{array{text: string, index: 0}}
     */
    public function extractVectorizationItems($entity): array
    {
        // Get object data.
        if (is_array($entity) === true) {
            $objectData = $entity;
        } else {
            $objectData = $entity->jsonSerialize();
        }

        // Get vectorization config.
        $config = $this->settingsService->getObjectSettingsOnly();

        // Serialize object to text.
        $text = $this->serializeObject(object: $objectData, config: $config);

        // Objects produce a single vectorization item.
        return [
            [
                'text'  => $text,
                'index' => 0,
            ],
        ];

    }//end extractVectorizationItems()


    /**
     * Prepare metadata for object vector
     *
     * @param mixed $entity ObjectEntity
     * @param array $item   Vectorization item
     *
     * @return ((mixed|null|string)[]|int|string)[] Metadata for storage
     *
     * @psalm-return array{
     *     entity_type: 'object',
     *     entity_id: string,
     *     chunk_index: 0,
     *     total_chunks: 1,
     *     chunk_text: string,
     *     additional_metadata: array{
     *         object_id: 'unknown'|mixed,
     *         object_title: mixed|string,
     *         title: mixed|string,
     *         name: mixed|string,
     *         description: ''|mixed,
     *         register: mixed|null,
     *         register_id: mixed|null,
     *         schema: mixed|null,
     *         schema_id: mixed|null,
     *         uuid: mixed|null,
     *         uri: mixed|null
     *     }
     * }
     */
    public function prepareVectorMetadata($entity, array $item): array
    {
        if (is_array($entity) === true) {
            $objectData = $entity;
        } else {
            $objectData = $entity->jsonSerialize();
        }

        if (($objectData['id'] ?? null) !== null) {
            $objectId = $objectData['id'];
        } else {
            $objectId = 'unknown';
        }

        // DEBUG: Log what we're receiving.
        $this->logger->debug(
                '[ObjectVectorizationStrategy] Preparing metadata',
                [
                    'object_id'       => $objectId,
                    'has_@self'       => isset($objectData['@self']) === true,
                    '@self_keys'      => $this->extractSelfKeys($objectData),
                    'register_direct' => $objectData['_register'] ?? $objectData['register'] ?? 'none',
                    'register_@self'  => $objectData['@self']['register'] ?? 'none',
                ]
                );

        // Extract title/name - check multiple possible fields.
        $title = $objectData['title'] ?? $objectData['name'] ?? $objectData['_name'] ?? $objectData['summary'];
        if ($title === null) {
            $title = $this->extractFirstStringField($objectData);
        }

        if ($title === null) {
            $title = 'Object #'.$objectId;
        }

        // Extract description - check common variants.
        $description = $objectData['description'] ?? $objectData['_description'] ?? $objectData['Beschrijving'];
        if ($description === null) {
            $description = $objectData['beschrijving'] ?? $objectData['summary'] ?? $objectData['_summary'] ?? '';
        }

        // Extract @self keys for logging.
        $this->extractSelfKeys($objectData);

        return [
            'entity_type'         => 'object',
            'entity_id'           => (string) $objectId,
            'chunk_index'         => 0,
            'total_chunks'        => 1,
            'chunk_text'          => substr($item['text'], 0, 500),
        // Preview.
            'additional_metadata' => [
                'object_id'    => $objectId,
                'object_title' => $title,
        // ADDED for display.
                'title'        => $title,
        // ADDED for backward compatibility.
                'name'         => $title,
        // ADDED for alternative lookup.
                'description'  => $description,
        // ADDED for context.
                // Check both direct fields and @self metadata.
                'register'     => $objectData['_register'] ?? $objectData['register'] ?? $objectData['@self']['register'] ?? null,
                'register_id'  => $objectData['_register'] ?? $objectData['register'] ?? $objectData['@self']['register'] ?? null,
                'schema'       => $objectData['_schema'] ?? $objectData['schema'] ?? $objectData['@self']['schema'] ?? null,
                'schema_id'    => $objectData['_schema'] ?? $objectData['schema'] ?? $objectData['@self']['schema'] ?? null,
                'uuid'         => $objectData['uuid'] ?? $objectData['_uuid'] ?? $objectData['@self']['id'] ?? null,
                'uri'          => $objectData['uri'] ?? $objectData['_uri'] ?? $objectData['@self']['uri'] ?? null,
            ],
        ];

    }//end prepareVectorMetadata()


    /**
     * Extract @self keys from object data
     *
     * @param array $objectData Object data
     *
     * @return array<string> Array of @self keys
     */
    private function extractSelfKeys(array $objectData): array
    {
        if (isset($objectData['@self']) === false || is_array($objectData['@self']) === false) {
            return [];
        }

        return array_keys($objectData['@self']);

    }//end extractSelfKeys()


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
        // Skip metadata fields (prefixed with _ or @).
        // Look for short, meaningful strings (< 100 chars).
        foreach ($objectData as $key => $value) {
            // Skip metadata and system fields.
            if (str_starts_with($key, '_') === true || str_starts_with($key, '@') === true) {
                continue;
            }

            // Skip known non-title fields.
            $skipFields = ['id', 'uuid', 'description', 'Beschrijving', 'beschrijving', 'content', 'text'];
            if (in_array(strtolower($key), array_map('strtolower', $skipFields), true) === true) {
                continue;
            }

            // Check if it's a short string (likely a title/identifier).
            if (is_string($value) === true && strlen($value) > 0 && strlen($value) < 100) {
                return $value;
            }
        }

        return null;

    }//end extractFirstStringField()


    /**
     * Get object ID as identifier
     *
     * @param mixed $entity ObjectEntity
     *
     * @return string|int Object ID
     */
    public function getEntityIdentifier($entity)
    {
        if (is_array($entity) === true) {
            $objectData = $entity;
        } else {
            $objectData = $entity->jsonSerialize();
        }

        if (($objectData['id'] ?? null) !== null) {
            return $objectData['id'];
        }

        return 'unknown';

    }//end getEntityIdentifier()


    /**
     * Serialize object to text for vectorization
     *
     * @param array $object Object data
     * @param array $config Vectorization configuration
     *
     * @return false|string Serialized text
     */
    private function serializeObject(array $object, array $config): string|false
    {
        // TODO: Implement configurable serialization.
        // For now, just JSON encode with pretty print for readability.
        $includeMetadata  = $config['includeMetadata'] ?? true;
        $includeRelations = $config['includeRelations'] ?? true;
        $maxNestingDepth  = $config['maxNestingDepth'] ?? 10;

        $this->logger->debug(
                '[ObjectVectorizationStrategy] Serializing object',
                [
                    'objectId'         => $object['id'] ?? 'unknown',
                    'includeMetadata'  => $includeMetadata,
                    'includeRelations' => $includeRelations,
                    'maxNestingDepth'  => $maxNestingDepth,
                ]
                );

        // Simple JSON serialization.
        // Future enhancement: smart serialization based on schema.
        return json_encode($object, JSON_PRETTY_PRINT);

    }//end serializeObject()


}//end class
