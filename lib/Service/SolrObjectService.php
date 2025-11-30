<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use Psr\Log\LoggerInterface;

/**
 * SOLR Object Service
 *
 * Handles object-specific SOLR operations including indexing, searching,
 * and managing objects in the objectCollection.
 *
 * This service focuses exclusively on ObjectEntity operations and delegates
 * core SOLR infrastructure tasks to GuzzleSolrService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
class SolrObjectService
{


    /**
     * Constructor
     *
     * @param GuzzleSolrService $guzzleSolrService Core SOLR operations service
     * @param SettingsService   $settingsService   Settings management service
     * @param SchemaMapper      $schemaMapper      Schema data mapper
     * @param RegisterMapper    $registerMapper    Register data mapper
     * @param LoggerInterface   $logger            PSR-3 logger
     */
    public function __construct(
        private readonly GuzzleSolrService $guzzleSolrService,
        private readonly SettingsService $settingsService,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Get the collection name for object operations
     *
     * @return string|null The objectCollection name, or null if not configured
     */
    private function getObjectCollection(): ?string
    {
        $solrSettings = $this->settingsService->getSolrSettingsOnly();
        return $solrSettings['objectCollection'] ?? null;

    }//end getObjectCollection()


    /**
     * Convert an ObjectEntity to searchable text for embedding generation
     *
     * This method extracts all meaningful text from an object including:
     * - Title/name fields
     * - Description fields
     * - All string values in the object data
     * - Related schema and register information
     *
     * The text is structured to provide context for AI embeddings while
     * remaining human-readable.
     *
     * @param ObjectEntity $object The object to convert
     *
     * @return string The concatenated text representation
     */
    public function convertObjectToText(ObjectEntity $object): string
    {
        $textParts = [];

        // Step 1: Add object UUID and version for context.
        $textParts[] = "Object ID: ".$object->getUuid();
        if ($object->getVersion() !== null) {
            $textParts[] = "Version: ".$object->getVersion();
        }

        // Step 2: Get and add schema information.
        try {
            if ($object->getSchema() !== null) {
                $schema      = $this->schemaMapper->find($object->getSchema());
                $textParts[] = "Type: ".($schema->getTitle() ?? $schema->getName() ?? 'Unknown');
                if ($schema->getDescription() !== null && $schema->getDescription() !== '') {
                    $textParts[] = "Schema Description: ".$schema->getDescription();
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                    'Could not load schema for object',
                    [
                        'object_id' => $object->getId(),
                        'schema_id' => $object->getSchema(),
                    ]
                    );
        }

        // Step 3: Get and add register information.
        try {
            if ($object->getRegister() !== null) {
                $register    = $this->registerMapper->find($object->getRegister());
                $textParts[] = "Register: ".($register->getTitle() ?? $register->getName() ?? 'Unknown');
                if ($register->getDescription() !== null && $register->getDescription() !== '') {
                    $textParts[] = "Register Description: ".$register->getDescription();
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                    'Could not load register for object',
                    [
                        'object_id'   => $object->getId(),
                        'register_id' => $object->getRegister(),
                    ]
                    );
        }

        // Step 4: Extract text from object data.
        $objectData = $object->getObject();
        if (is_array($objectData) === true) {
            $extractedText = $this->extractTextFromArray($objectData);
            if (empty($extractedText) === false) {
                $textParts[] = "Content: ".$extractedText;
            }
        }

        // Step 5: Add metadata.
        if ($object->getOrganization() !== null && $object->getOrganization() !== '') {
            $textParts[] = "Organization: ".$object->getOrganization();
        }

        // Join all parts with newlines for readability.
        $fullText = implode("\n", $textParts);

        // Log the conversion for debugging.
        $this->logger->debug(
                'Converted object to text for embedding',
                [
                    'object_id'   => $object->getId(),
                    'text_length' => strlen($fullText),
                    'parts_count' => count($textParts),
                ]
                );

        return $fullText;

    }//end convertObjectToText()


    /**
     * Recursively extract text from nested arrays/objects
     *
     * This method traverses the object data structure and extracts all
     * string values, building a coherent text representation.
     *
     * @param array  $data   The data to extract text from
     * @param string $prefix Optional prefix for nested keys (for context)
     * @param int    $depth  Current recursion depth (limit to prevent infinite loops)
     *
     * @return string Extracted text with field context
     */
    private function extractTextFromArray(array $data, string $prefix='', int $depth=0): string
    {
        // Prevent excessive recursion.
        if ($depth > 10) {
            return '';
        }

        $textParts = [];

        foreach ($data as $key => $value) {
            // Build context path (e.g., "address.street").
            if ($prefix !== null && $prefix !== '') {
                $contextKey = "{$prefix}.{$key}";
            } else {
                $contextKey = (string) $key;
            }

            // Handle different value types.
            if (is_string($value) === true && trim($value) !== '' && trim($value) !== null) {
                // Add field name for context, then value.
                $textParts[] = "{$contextKey}: {$value}";
            } else if (is_numeric($value) === true) {
                // Include numeric values with context.
                $textParts[] = "{$contextKey}: {$value}";
            } else if (is_bool($value) === true) {
                // Include boolean values.
                if ($value === true) {
                    $boolStr = 'true';
                } else {
                    $boolStr = 'false';
                }

                $textParts[] = "{$contextKey}: {$boolStr}";
            } else if (is_array($value) === true && empty($value) === false) {
                // Recursively process nested arrays.
                $nestedText = $this->extractTextFromArray(data: $value, prefix: $contextKey, depth: $depth + 1);
                if (empty($nestedText) === false) {
                    $textParts[] = $nestedText;
                }
            }//end if
        }//end foreach

        return implode("\n", $textParts);

    }//end extractTextFromArray()


    /**
     * Convert multiple objects to text for batch processing
     *
     * @param array $objects Array of ObjectEntity instances
     *
     * @return (int|null|string)[][] Array of ['object_id' => int, 'text' => string] arrays
     *
     * @psalm-return list<array{object_id: int, text: string, uuid: null|string}>
     */
    public function convertObjectsToText(array $objects): array
    {
        $results = [];

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                $this->logger->warning(
                        'Skipping non-ObjectEntity in batch conversion',
                        [
                            'type' => get_class($object),
                        ]
                        );
                continue;
            }

            try {
                $text      = $this->convertObjectToText($object);
                $results[] = [
                    'object_id' => $object->getId(),
                    'uuid'      => $object->getUuid(),
                    'text'      => $text,
                ];
            } catch (\Exception $e) {
                $this->logger->error(
                        'Failed to convert object to text',
                        [
                            'object_id' => $object->getId(),
                            'error'     => $e->getMessage(),
                        ]
                        );
            }
        }//end foreach

        $this->logger->info(
                'Batch converted objects to text',
                [
                    'total_objects' => count($objects),
                    'successful'    => count($results),
                    'failed'        => count($objects) - count($results),
                ]
                );

        return $results;

    }//end convertObjectsToText()




    /**
     * Search objects in SOLR
     *
     * @param array $query     Search query parameters
     * @param bool  $rbac      Apply RBAC filters
     * @param bool  $multi     Apply multi-tenancy filters
     * @param bool  $published Filter for published objects only
     * @param bool  $deleted   Include deleted objects
     *
     * @return array Paginated search results
     *
     * @throws \Exception If objectCollection is not configured
     */
    public function searchObjects(array $query=[], bool $rbac=true, bool $multi=true, bool $published=false, bool $deleted=false): array
    {
        $collection = $this->getObjectCollection();

        if ($collection === null) {
            throw new \Exception('objectCollection not configured in SOLR settings');
        }

        $this->logger->debug(
                'Searching objects in objectCollection',
                [
                    'collection' => $collection,
                    'query'      => $query,
                    'rbac'       => $rbac,
                    'multi'      => $multi,
                    'published'  => $published,
                    'deleted'    => $deleted,
                ]
                );

        // Delegate to GuzzleSolrService - will be refactored in later phases.
        return $this->guzzleSolrService->searchObjectsPaginated(query: $query, rbac: $rbac, multi: $multi, published: $published, deleted: $deleted);

    }//end searchObjects()







    /**
     * Commit changes to SOLR for object collection
     *
     * @return bool True if commit succeeded
     *
     * @throws \Exception If objectCollection is not configured
     */
    public function commit(): bool
    {
        $collection = $this->getObjectCollection();

        if ($collection === null) {
            throw new \Exception('objectCollection not configured in SOLR settings');
        }

        // TODO: Move commit logic to use collection parameter.
        return $this->guzzleSolrService->commit();

    }//end commit()


    /**
     * Generate vector embedding for a single object and store it
     *
     * This method converts the object to text, generates an AI embedding,
     * and stores it in the vector database for semantic search.
     *
     * @param ObjectEntity $object   The object to vectorize
     * @param string|null  $provider Optional embedding provider override
     *
     * @return array Result with vector_id and embedding info
     *
     * @throws \Exception If vectorization fails
     */
    public function vectorizeObject(ObjectEntity $object, ?string $provider=null): array
    {
        $startTime = microtime(true);

        // Step 1: Convert object to text.
        $text = $this->convertObjectToText($object);

        if (trim($text) === '' || trim($text) === null) {
            throw new \Exception("Object {$object->getId()} has no text content to vectorize");
        }

        // Step 2: Generate embedding using VectorEmbeddingService.
        // The service is already registered in the DI container from Application.php.
        $vectorService = \OC::$server->get(VectorEmbeddingService::class);

        $embedding = $vectorService->generateEmbedding(text: $text, provider: $provider);

        if ($embedding === null || empty($embedding['embedding']) === true) {
            throw new \Exception("Failed to generate embedding for object {$object->getId()}");
        }

        // Step 3: Store vector in database.
        $vectorId = $vectorService->storeVector(
            entityType: 'object',
            entityId: (string) $object->getId(),
            embedding: $embedding['embedding'],
            model: $embedding['model'],
            dimensions: $embedding['dimensions'],
            chunkIndex: 0,
            totalChunks: 1,
            chunkText: $text,
            metadata: [
                'uuid'         => $object->getUuid(),
                'schema_id'    => $object->getSchema(),
                'register_id'  => $object->getRegister(),
                'version'      => $object->getVersion(),
                'organization' => $object->getOrganization(),
            ]
        );

        $duration = (microtime(true) - $startTime) * 1000;
        // Milliseconds.
        $this->logger->info(
                'Object vectorized successfully',
                [
                    'object_id'   => $object->getId(),
                    'uuid'        => $object->getUuid(),
                    'vector_id'   => $vectorId,
                    'model'       => $embedding['model'],
                    'dimensions'  => $embedding['dimensions'],
                    'text_length' => strlen($text),
                    'duration_ms' => round($duration, 2),
                ]
                );

        return [
            'success'     => true,
            'object_id'   => $object->getId(),
            'uuid'        => $object->getUuid(),
            'vector_id'   => $vectorId,
            'model'       => $embedding['model'],
            'dimensions'  => $embedding['dimensions'],
            'text_length' => strlen($text),
            'duration_ms' => round($duration, 2),
        ];

    }//end vectorizeObject()


    /**
     * Vectorize multiple objects in batch
     *
     * This method efficiently processes multiple objects by using batch
     * embedding generation when possible.
     *
     * @param array       $objects  Array of ObjectEntity instances
     * @param string|null $provider Optional embedding provider override
     *
     * @return array Results with success count and details
     */
    public function vectorizeObjects(array $objects, ?string $provider=null): array
    {
        $startTime  = microtime(true);
        $results    = [];
        $successful = 0;
        $failed     = 0;

        $this->logger->info(
                'Starting batch object vectorization',
                [
                    'total_objects' => count($objects),
                    'provider'      => $this->getProviderOrDefault($provider),
                ]
                );

        // Step 1: Convert all objects to text.
        $textData = $this->convertObjectsToText($objects);

        if ($textData === []) {
            return [
                'success'    => false,
                'error'      => 'No objects could be converted to text',
                'total'      => count($objects),
                'successful' => 0,
                'failed'     => count($objects),
                'results'    => [],
            ];
        }

        // Step 2: Generate embeddings in batch (more efficient).
        $vectorService = \OC::$server->get(VectorEmbeddingService::class);

        $texts      = array_column(array: $textData, column_key: 'text');
        $embeddings = $vectorService->generateBatchEmbeddings(texts: $texts, provider: $provider);

        // Step 3: Store vectors for each object.
        foreach ($textData as $index => $item) {
            $embedding = $embeddings[$index] ?? null;

            if ($embedding === null || $embedding['embedding'] === null) {
                $failed++;
                $results[] = [
                    'success'   => false,
                    'object_id' => $item['object_id'],
                    'uuid'      => $item['uuid'],
                    'error'     => $embedding['error'] ?? 'Unknown error',
                ];
                continue;
            }

            try {
                // Find the corresponding object.
                $object = null;
                foreach ($objects as $obj) {
                    if ($obj->getId() === $item['object_id']) {
                        $object = $obj;
                        break;
                    }
                }

                if ($object === null) {
                    throw new \Exception("Could not find object {$item['object_id']}");
                }

                // Store vector.
                $vectorId = $vectorService->storeVector(
                    entityType: 'object',
                    entityId: (string) $object->getId(),
                    embedding: $embedding['embedding'],
                    model: $embedding['model'],
                    dimensions: $embedding['dimensions'],
                    chunkIndex: 0,
                    totalChunks: 1,
                    chunkText: $item['text'],
                    metadata: [
                        'uuid'         => $object->getUuid(),
                        'schema_id'    => $object->getSchema(),
                        'register_id'  => $object->getRegister(),
                        'version'      => $object->getVersion(),
                        'organization' => $object->getOrganization(),
                    ]
                );

                $successful++;
                $results[] = [
                    'success'    => true,
                    'object_id'  => $item['object_id'],
                    'uuid'       => $item['uuid'],
                    'vector_id'  => $vectorId,
                    'model'      => $embedding['model'],
                    'dimensions' => $embedding['dimensions'],
                ];
            } catch (\Exception $e) {
                $failed++;
                $results[] = [
                    'success'   => false,
                    'object_id' => $item['object_id'],
                    'uuid'      => $item['uuid'],
                    'error'     => $e->getMessage(),
                ];

                $this->logger->error(
                        'Failed to vectorize object',
                        [
                            'object_id' => $item['object_id'],
                            'error'     => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $duration = (microtime(true) - $startTime) * 1000;

        $this->logger->info(
                'Batch object vectorization complete',
                [
                    'total'              => count($objects),
                    'successful'         => $successful,
                    'failed'             => $failed,
                    'duration_ms'        => round($duration, 2),
                    'objects_per_second' => $this->calculateObjectsPerSecond(durationSeconds: $duration / 1000, processedObjects: $successful),
                ]
                );

        return [
            'success'            => $failed === 0,
            'total'              => count($objects),
            'successful'         => $successful,
            'failed'             => $failed,
            'duration_ms'        => round($duration, 2),
            'objects_per_second' => $this->calculateObjectsPerSecond(durationSeconds: $duration / 1000, processedObjects: $successful),
            'results'            => $results,
        ];

    }//end vectorizeObjects()


    /**
     * Get provider or return default value.
     *
     * @param string|null $provider Optional provider name.
     *
     * @return string Provider name or 'default' if not provided.
     */
    private function getProviderOrDefault(?string $provider): string
    {
        return $provider ?? 'default';

    }//end getProviderOrDefault()


}//end class
