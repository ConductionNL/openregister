<?php

/**
 * EntityRecognitionHandler
 *
 * Handler for extracting named entities (persons, organizations, locations, etc.)
 * from text chunks for GDPR compliance and data classification.
 * This handler is invoked after chunks are created to detect and store entities.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\TextExtraction;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Entity Recognition Handler.
 *
 * Extracts named entities from text chunks using multiple detection methods:
 * - Local regex patterns (fast, privacy-friendly).
 * - External services (Presidio, etc.).
 * - LLM-based extraction (context-aware, accurate).
 * - Hybrid approach (combines multiple methods).
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\TextExtraction
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class EntityRecognitionHandler
{
    /**
     * Entity type constants.
     */
    public const ENTITY_TYPE_PERSON       = 'PERSON';
    public const ENTITY_TYPE_ORGANIZATION = 'ORGANIZATION';
    public const ENTITY_TYPE_LOCATION     = 'LOCATION';
    public const ENTITY_TYPE_EMAIL        = 'EMAIL';
    public const ENTITY_TYPE_PHONE        = 'PHONE';
    public const ENTITY_TYPE_ADDRESS      = 'ADDRESS';
    public const ENTITY_TYPE_DATE         = 'DATE';
    public const ENTITY_TYPE_IBAN         = 'IBAN';
    public const ENTITY_TYPE_SSN          = 'SSN';
    public const ENTITY_TYPE_IP_ADDRESS   = 'IP_ADDRESS';

    /**
     * Detection method constants.
     */
    public const METHOD_REGEX          = 'regex';
    public const METHOD_PRESIDIO       = 'presidio';
    public const METHOD_OPENANONYMISER = 'openanonymiser';
    public const METHOD_LLM            = 'llm';
    public const METHOD_HYBRID         = 'hybrid';
    public const METHOD_MANUAL         = 'manual';

    /**
     * Category constants.
     */
    public const CATEGORY_PERSONAL_DATA   = 'personal_data';
    public const CATEGORY_SENSITIVE_PII   = 'sensitive_pii';
    public const CATEGORY_BUSINESS_DATA   = 'business_data';
    public const CATEGORY_CONTEXTUAL_DATA = 'contextual_data';
    public const CATEGORY_TEMPORAL_DATA   = 'temporal_data';

    /**
     * Constructor.
     *
     * @param ChunkMapper          $chunkMapper          Chunk mapper.
     * @param GdprEntityMapper     $entityMapper         Entity mapper.
     * @param EntityRelationMapper $entityRelationMapper Entity relation mapper.
     * @param IDBConnection        $db                   Database connection.
     * @param LoggerInterface      $logger               Logger.
     * @param SettingsService      $settingsService      Settings service.
     */
    public function __construct(
        private readonly ChunkMapper $chunkMapper,
        private readonly GdprEntityMapper $entityMapper,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService
    ) {
    }//end __construct()

    /**
     * Process chunks for a source and extract entities.
     *
     * This method is called after chunks are created to detect and store entities.
     *
     * @param string $sourceType Source type identifier (file, object, etc.).
     * @param int    $sourceId   Source identifier.
     * @param array  $options    Processing options:
     *                           - method: 'regex', 'presidio', 'llm', 'hybrid' (default: 'hybrid').
     *                           - entity_types: array of entity types to detect (default: all).
     *                           - confidence_threshold: minimum confidence (default: 0.5).
     *                           - context_window: characters around entity (default: 50).
     *
     * @return int[]
     *
     * @throws Exception When processing fails.
     *
     * @psalm-return array{chunks_processed: int<0, max>, entities_found: int<0, max>, relations_created: int<0, max>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Chunk processing requires multiple condition checks
     */
    public function processSourceChunks(string $sourceType, int $sourceId, array $options=[]): array
    {
        $this->logger->info(
            message: '[EntityRecognitionHandler] Processing chunks for entity extraction',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
            ]
        );

        // Get all chunks for this source (excluding metadata chunks).
        $chunks = $this->chunkMapper->findBySource(sourceType: $sourceType, sourceId: $sourceId);

        // Filter out metadata chunks (chunk_index = -1).
        $chunks = array_filter(
            $chunks,
            fn($chunk) => $chunk->getChunkIndex() !== -1
        );

        $chunksProcessed = 0;
        $totalEntities   = 0;
        $totalRelations  = 0;

        foreach ($chunks as $chunk) {
            try {
                $result = $this->extractFromChunk(chunk: $chunk, options: $options);
                $chunksProcessed++;
                $totalEntities  += $result['entities_found'];
                $totalRelations += $result['relations_created'];
            } catch (Exception $e) {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] Failed to process chunk',
                    context: [
                        'file'        => __FILE__,
                        'line'        => __LINE__,
                        'chunk_id'    => $chunk->getId(),
                        'source_type' => $sourceType,
                        'source_id'   => $sourceId,
                        'error'       => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        $this->logger->info(
            message: '[EntityRecognitionHandler] Source processing complete',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
                'source_type'       => $sourceType,
                'source_id'         => $sourceId,
                'chunks_processed'  => $chunksProcessed,
                'entities_found'    => $totalEntities,
                'relations_created' => $totalRelations,
            ]
        );

        return [
            'chunks_processed'  => $chunksProcessed,
            'entities_found'    => $totalEntities,
            'relations_created' => $totalRelations,
        ];
    }//end processSourceChunks()

    /**
     * Extract entities from a text chunk.
     *
     * @param Chunk $chunk   Chunk to process.
     * @param array $options Processing options:
     *                       - method: 'regex', 'presidio', 'llm', 'hybrid' (default: 'hybrid').
     *                       - entity_types: array of entity types to detect (default: all).
     *                       - confidence_threshold: minimum confidence (default: 0.5).
     *                       - context_window: characters around entity (default: 50).
     *
     * @return array Extraction results with entities_found, relations_created, and entities list.
     *
     * @throws Exception When extraction fails.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Entity extraction requires multiple condition checks
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple entity detection paths with error handling
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive entity extraction with logging
     */
    public function extractFromChunk(Chunk $chunk, array $options=[]): array
    {
        $this->logger->debug(
            message: '[EntityRecognitionHandler] Extracting entities from chunk',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'chunk_id'    => $chunk->getId(),
                'source_type' => $chunk->getSourceType(),
                'source_id'   => $chunk->getSourceId(),
            ]
        );

        $method      = $options['method'] ?? self::METHOD_HYBRID;
        $entityTypes = $options['entity_types'] ?? null;
        $confidenceThreshold = (float) ($options['confidence_threshold'] ?? 0.5);
        $contextWindow       = (int) ($options['context_window'] ?? 50);

        $text = $chunk->getTextContent();

        if (empty($text) === true || trim($text) === '') {
            return [
                'entities_found'    => 0,
                'relations_created' => 0,
                'entities'          => [],
            ];
        }

        // Extract entities using selected method.
        $detectedEntities = $this->detectEntities(
            text: $text,
            method: $method,
            entityTypes: $entityTypes,
            confidenceThreshold: $confidenceThreshold
        );

        if (empty($detectedEntities) === true) {
            return [
                'entities_found'    => 0,
                'relations_created' => 0,
                'entities'          => [],
            ];
        }

        // Store entities and create relations.
        $entitiesFound    = 0;
        $relationsCreated = 0;
        $storedEntities   = [];

        foreach ($detectedEntities as $detected) {
            try {
                // Find or create entity.
                $entity = $this->findOrCreateEntity(
                    type: $detected['type'],
                    value: $detected['value'],
                    category: $detected['category'] ?? $this->getCategoryForType(type: $detected['type'])
                );

                // Create entity relation.
                $relation = new EntityRelation();
                $relation->setEntityId($entity->getId());
                $relation->setChunkId($chunk->getId());
                $relation->setPositionStart($detected['position_start']);
                $relation->setPositionEnd($detected['position_end']);
                $relation->setConfidence($detected['confidence']);
                $relation->setDetectionMethod($method);
                $context = $this->extractContext(
                    text: $text,
                    positionStart: $detected['position_start'],
                    positionEnd: $detected['position_end'],
                    window: $contextWindow
                );
                $relation->setContext($context);
                $relation->setCreatedAt(new DateTime());

                // Set source references based on chunk source type.
                if ($chunk->getSourceType() === 'file') {
                    $relation->setFileId($chunk->getSourceId());
                } else if ($chunk->getSourceType() === 'object') {
                    $relation->setObjectId($chunk->getSourceId());
                }

                $this->entityRelationMapper->insert($relation);

                $entitiesFound++;
                $relationsCreated++;
                $storedEntities[] = [
                    'type'       => $detected['type'],
                    'value'      => $detected['value'],
                    'confidence' => $detected['confidence'],
                ];
            } catch (Exception $e) {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] Failed to store entity',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'chunk_id' => $chunk->getId(),
                        'type'     => $detected['type'] ?? 'unknown',
                        'value'    => substr($detected['value'] ?? '', 0, 50),
                        'error'    => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        return [
            'entities_found'    => $entitiesFound,
            'relations_created' => $relationsCreated,
            'entities'          => $storedEntities,
        ];
    }//end extractFromChunk()

    /**
     * Detect entities in text using specified method.
     *
     * @param string     $text                Text to analyze.
     * @param string     $method              Detection method.
     * @param array|null $entityTypes         Entity types to detect (null = all).
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array Detected entities with type, value, category, position, and confidence.
     */
    private function detectEntities(string $text, string $method, ?array $entityTypes, float $confidenceThreshold): array
    {
        return match ($method) {
            self::METHOD_REGEX => $this->detectWithRegex(
                text: $text,
                entityTypes: $entityTypes,
                confidenceThreshold: $confidenceThreshold
            ),
            self::METHOD_PRESIDIO => $this->detectWithPresidio(
                text: $text,
                entityTypes: $entityTypes,
                confidenceThreshold: $confidenceThreshold
            ),
            self::METHOD_OPENANONYMISER => $this->detectWithOpenAnonymiser(
                text: $text,
                entityTypes: $entityTypes,
                confidenceThreshold: $confidenceThreshold
            ),
            self::METHOD_LLM => $this->detectWithLLM(
                text: $text,
                entityTypes: $entityTypes,
                confidenceThreshold: $confidenceThreshold
            ),
            self::METHOD_HYBRID => $this->detectWithHybrid(
                text: $text,
                entityTypes: $entityTypes,
                confidenceThreshold: $confidenceThreshold
            ),
            default => throw new Exception("Unknown detection method: {$method}")
        };//end match
    }//end detectEntities()

    /**
     * Detect entities using regex patterns.
     *
     * @param string     $text                Text to analyze.
     * @param array|null $entityTypes         Entity types to detect.
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array Detected entities with type, value, category, position, and confidence.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple entity type patterns require separate conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple regex pattern matching paths
     */
    private function detectWithRegex(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        $entities = [];

        // Email detection.
        if ($entityTypes === null || in_array(self::ENTITY_TYPE_EMAIL, $entityTypes, true) === true) {
            $pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === true) {
                foreach ($matches[0] as $match) {
                    $entities[] = [
                        'type'           => self::ENTITY_TYPE_EMAIL,
                        'value'          => $match[0],
                        'category'       => self::CATEGORY_PERSONAL_DATA,
                        'position_start' => $match[1],
                        'position_end'   => $match[1] + strlen($match[0]),
                        'confidence'     => 0.9,
                    ];
                }
            }
        }

        // Phone detection (international format).
        if ($entityTypes === null || in_array(self::ENTITY_TYPE_PHONE, $entityTypes, true) === true) {
            $phonePattern = '/\+?[1-9]\d{1,14}|\+?31\s?[0-9]{9}|\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/';
            if (preg_match_all($phonePattern, $text, $matches, PREG_OFFSET_CAPTURE) === true) {
                foreach ($matches[0] as $match) {
                    $entities[] = [
                        'type'           => self::ENTITY_TYPE_PHONE,
                        'value'          => $match[0],
                        'category'       => self::CATEGORY_PERSONAL_DATA,
                        'position_start' => $match[1],
                        'position_end'   => $match[1] + strlen($match[0]),
                        'confidence'     => 0.7,
                    ];
                }
            }
        }

        // IBAN detection.
        if ($entityTypes === null || in_array(self::ENTITY_TYPE_IBAN, $entityTypes, true) === true) {
            $ibanPattern = '/[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]?){0,16}/';
            if (preg_match_all($ibanPattern, $text, $matches, PREG_OFFSET_CAPTURE) === true) {
                foreach ($matches[0] as $match) {
                    $entities[] = [
                        'type'           => self::ENTITY_TYPE_IBAN,
                        'value'          => $match[0],
                        'category'       => self::CATEGORY_SENSITIVE_PII,
                        'position_start' => $match[1],
                        'position_end'   => $match[1] + strlen($match[0]),
                        'confidence'     => 0.8,
                    ];
                }
            }
        }

        // Filter by confidence threshold.
        return array_filter(
            $entities,
            fn($e) => $e['confidence'] >= $confidenceThreshold
        );
    }//end detectWithRegex()

    /**
     * Detect entities using Presidio service.
     *
     * @param string     $text                Text to analyze.
     * @param array|null $entityTypes         Entity types to detect.
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array Detected entities with type, value, category, position, and confidence.
     */
    private function detectWithPresidio(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        try {
            // Get Presidio settings.
            $fileSettings     = $this->settingsService->getFileSettingsOnly();
            $presidioEndpoint = $fileSettings['presidioApiEndpoint'] ?? '';

            if (empty($presidioEndpoint) === true) {
                $this->logger->warning(
                    message: '[EntityRecognitionHandler] Presidio endpoint not configured, falling back to regex',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            // Build request body.
            $requestBody = [
                'text'     => $text,
                'language' => 'en',
            ];

            // Add entity types filter if specified.
            if ($entityTypes !== null && empty($entityTypes) === false) {
                // Map our entity types to Presidio entity types.
                $presidioEntities = $this->mapToPresidioEntityTypes(entityTypes: $entityTypes);
                if (empty($presidioEntities) === false) {
                    $requestBody['entities'] = $presidioEntities;
                }
            }

            // Make HTTP request to Presidio.
            $ch = curl_init($presidioEndpoint.'/analyze');
            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($requestBody),
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    CURLOPT_TIMEOUT        => 30,
                ]
            );

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] Presidio connection error: '.$curlError,
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            if ($httpCode !== 200) {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] Presidio returned HTTP '.$httpCode,
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            if (is_string($response) === false) {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] Presidio returned non-string response',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            $presidioResults = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE || is_array($presidioResults) === false) {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] Failed to parse Presidio response',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            $this->logger->debug(
                message: '[EntityRecognitionHandler] Presidio found '.count($presidioResults).' entities',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Convert Presidio results to our format.
            $entities = [];
            foreach ($presidioResults as $result) {
                $score = $result['score'] ?? 0;

                // Skip low confidence results.
                if ($score < $confidenceThreshold) {
                    continue;
                }

                $start = $result['start'] ?? 0;
                $end   = $result['end'] ?? 0;
                $value = substr($text, $start, ($end - $start));

                $entityType = $this->mapFromPresidioEntityType(presidioType: $result['entity_type'] ?? 'UNKNOWN');

                $entities[] = [
                    'type'           => $entityType,
                    'value'          => $value,
                    'category'       => $this->getCategoryForType(type: $entityType),
                    'position_start' => $start,
                    'position_end'   => $end,
                    'confidence'     => $score,
                    'method'         => self::METHOD_PRESIDIO,
                ];
            }//end foreach

            return $entities;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[EntityRecognitionHandler] Presidio detection failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
        }//end try
    }//end detectWithPresidio()

    /**
     * Detect entities using OpenAnonymiser service.
     *
     * OpenAnonymiser is a Dutch-focused PII detection service with an API similar
     * to Presidio but with key differences: endpoint at /api/v1/analyze, response
     * wrapped in {"pii_entities": [...]}, includes "text" field per entity, and
     * defaults to Dutch language.
     *
     * @param string     $text                Text to analyze.
     * @param array|null $entityTypes         Entity types to detect.
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array Detected entities with type, value, category, position, and confidence.
     */
    private function detectWithOpenAnonymiser(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        try {
            // Get OpenAnonymiser settings.
            $fileSettings           = $this->settingsService->getFileSettingsOnly();
            $openAnonymiserEndpoint = $fileSettings['openAnonymiserApiEndpoint'] ?? '';

            if (empty($openAnonymiserEndpoint) === true) {
                $this->logger->warning(
                    message: '[EntityRecognitionHandler] OpenAnonymiser endpoint not configured, falling back to regex',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            // Build request body.
            $requestBody = [
                'text'     => $text,
                'language' => 'nl',
            ];

            // Add entity types filter if specified.
            if ($entityTypes !== null && empty($entityTypes) === false) {
                $presidioEntities = $this->mapToPresidioEntityTypes(entityTypes: $entityTypes);
                if (empty($presidioEntities) === false) {
                    $requestBody['entities'] = $presidioEntities;
                }
            }

            // Make HTTP request to OpenAnonymiser.
            $ch = curl_init($openAnonymiserEndpoint.'/api/v1/analyze');
            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($requestBody),
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    CURLOPT_TIMEOUT        => 30,
                ]
            );

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] OpenAnonymiser connection error: '.$curlError,
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            if ($httpCode !== 200) {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] OpenAnonymiser returned HTTP '.$httpCode,
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            if (is_string($response) === false) {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] OpenAnonymiser returned non-string response',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            $responseData = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE || is_array($responseData) === false) {
                $this->logger->error(
                    message: '[EntityRecognitionHandler] Failed to parse OpenAnonymiser response',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
            }

            // OpenAnonymiser wraps results in {"pii_entities": [...]}.
            $anonymiserResults = $responseData['pii_entities'] ?? [];

            $this->logger->debug(
                message: '[EntityRecognitionHandler] OpenAnonymiser found '.count($anonymiserResults).' entities',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Convert OpenAnonymiser results to our format.
            $entities = [];
            foreach ($anonymiserResults as $result) {
                // OpenAnonymiser may return null score for NLP-detected entities (e.g. PERSON).
                // Treat null as high confidence since these are spaCy NER detections.
                $score = $result['score'] ?? 0.85;

                // Skip low confidence results.
                if ($score < $confidenceThreshold) {
                    continue;
                }

                $start = $result['start'] ?? 0;
                $end   = $result['end'] ?? 0;
                // OpenAnonymiser includes the text directly.
                $value = $result['text'] ?? substr($text, $start, ($end - $start));

                $entityType = $this->mapFromPresidioEntityType(presidioType: $result['entity_type'] ?? 'UNKNOWN');

                $entities[] = [
                    'type'           => $entityType,
                    'value'          => $value,
                    'category'       => $this->getCategoryForType(type: $entityType),
                    'position_start' => $start,
                    'position_end'   => $end,
                    'confidence'     => $score,
                    'method'         => self::METHOD_OPENANONYMISER,
                ];
            }//end foreach

            return $entities;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[EntityRecognitionHandler] OpenAnonymiser detection failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
        }//end try
    }//end detectWithOpenAnonymiser()

    /**
     * Map our entity types to Presidio entity types.
     *
     * @param array $entityTypes Our entity types.
     *
     * @return array Presidio entity types.
     */
    private function mapToPresidioEntityTypes(array $entityTypes): array
    {
        $mapping = [
            self::ENTITY_TYPE_PERSON       => 'PERSON',
            self::ENTITY_TYPE_ORGANIZATION => 'ORGANIZATION',
            self::ENTITY_TYPE_LOCATION     => 'LOCATION',
            self::ENTITY_TYPE_EMAIL        => 'EMAIL_ADDRESS',
            self::ENTITY_TYPE_PHONE        => 'PHONE_NUMBER',
            self::ENTITY_TYPE_DATE         => 'DATE_TIME',
            self::ENTITY_TYPE_IBAN         => 'IBAN_CODE',
            self::ENTITY_TYPE_SSN          => 'US_SSN',
            self::ENTITY_TYPE_IP_ADDRESS   => 'IP_ADDRESS',
        ];

        $presidioTypes = [];
        foreach ($entityTypes as $type) {
            if (isset($mapping[$type]) === true) {
                $presidioTypes[] = $mapping[$type];
            }
        }

        return $presidioTypes;
    }//end mapToPresidioEntityTypes()

    /**
     * Map Presidio entity type to our entity type.
     *
     * @param string $presidioType Presidio entity type.
     *
     * @return string Our entity type.
     */
    private function mapFromPresidioEntityType(string $presidioType): string
    {
        $mapping = [
            'PERSON'        => self::ENTITY_TYPE_PERSON,
            'ORGANIZATION'  => self::ENTITY_TYPE_ORGANIZATION,
            'LOCATION'      => self::ENTITY_TYPE_LOCATION,
            'EMAIL_ADDRESS' => self::ENTITY_TYPE_EMAIL,
            'PHONE_NUMBER'  => self::ENTITY_TYPE_PHONE,
            'DATE_TIME'     => self::ENTITY_TYPE_DATE,
            'IBAN_CODE'     => self::ENTITY_TYPE_IBAN,
            'US_SSN'        => self::ENTITY_TYPE_SSN,
            'IP_ADDRESS'    => self::ENTITY_TYPE_IP_ADDRESS,
            'CREDIT_CARD'   => 'CREDIT_CARD',
            'CRYPTO'        => 'CRYPTO',
            'URL'           => 'URL',
            'NRP'           => 'NRP',
        ];

        return $mapping[$presidioType] ?? $presidioType;
    }//end mapFromPresidioEntityType()

    /**
     * Detect entities using LLM.
     *
     * @param string     $text                Text to analyze.
     * @param array|null $entityTypes         Entity types to detect.
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array Detected entities with type, value, category, position, and confidence.
     */
    private function detectWithLLM(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        // TODO: Implement LLM-based entity extraction.
        // For now, fall back to regex.
        $this->logger->debug(
            message: '[EntityRecognitionHandler] LLM extraction not yet implemented, using regex fallback',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
    }//end detectWithLLM()

    /**
     * Detect entities using hybrid approach (combines multiple methods).
     *
     * @param string     $text                Text to analyze.
     * @param array|null $entityTypes         Entity types to detect.
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array Detected entities with type, value, category, position, and confidence.
     */
    private function detectWithHybrid(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        // Start with regex for fast detection.
        $regexEntities = $this->detectWithRegex(
            text: $text,
            entityTypes: $entityTypes,
            confidenceThreshold: $confidenceThreshold
        );

        // TODO: Add Presidio validation for higher confidence.
        // TODO: Add LLM validation for ambiguous cases.
        return $regexEntities;
    }//end detectWithHybrid()

    /**
     * Find or create an entity.
     *
     * @param string $type     Entity type.
     * @param string $value    Entity value.
     * @param string $category Entity category.
     *
     * @return GdprEntity Entity instance.
     *
     * @throws Exception When entity creation fails.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
     */
    private function findOrCreateEntity(string $type, string $value, string $category): GdprEntity
    {
        // Try to find existing entity by value and type.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('openregister_entities')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
                ->andWhere($qb->expr()->eq('value', $qb->createNamedParameter($value)))
                ->setMaxResults(1);

            /*
             * @psalm-suppress InaccessibleMethod - findEntities is accessible via inheritance.
             */

            $existingEntities = $this->entityMapper->findEntitiesPublic($qb);
            if (empty($existingEntities) === false) {
                $existing = $existingEntities[0];
                // Update timestamp.
                $existing->setUpdatedAt(new DateTime());
                $this->entityMapper->update($existing);
                return $existing;
            }

            throw new DoesNotExistException('Entity not found');
        } catch (DoesNotExistException $e) {
            // Entity doesn't exist, create new one.
            $entity = new GdprEntity();

            $entity->setUuid((string) Uuid::v4());
            $entity->setType($type);
            $entity->setValue($value);
            $entity->setCategory($category);
            $entity->setDetectedAt(new DateTime());
            $entity->setUpdatedAt(new DateTime());

            return $this->entityMapper->insert($entity);
        }//end try
    }//end findOrCreateEntity()

    /**
     * Get category for entity type.
     *
     * @param string $type Entity type.
     *
     * @return string Category.
     */
    private function getCategoryForType(string $type): string
    {
        return match ($type) {
            self::ENTITY_TYPE_PERSON,
            self::ENTITY_TYPE_EMAIL,
            self::ENTITY_TYPE_PHONE,
            self::ENTITY_TYPE_ADDRESS => self::CATEGORY_PERSONAL_DATA,
            self::ENTITY_TYPE_IBAN, self::ENTITY_TYPE_SSN => self::CATEGORY_SENSITIVE_PII,
            self::ENTITY_TYPE_ORGANIZATION => self::CATEGORY_BUSINESS_DATA,
            self::ENTITY_TYPE_LOCATION => self::CATEGORY_CONTEXTUAL_DATA,
            self::ENTITY_TYPE_DATE => self::CATEGORY_TEMPORAL_DATA,
            default => self::CATEGORY_CONTEXTUAL_DATA
        };
    }//end getCategoryForType()

    /**
     * Extract context around entity position.
     *
     * @param string $text          Full text.
     * @param int    $positionStart Start position.
     * @param int    $positionEnd   End position.
     * @param int    $window        Context window size.
     *
     * @return string Context string.
     */
    private function extractContext(string $text, int $positionStart, int $positionEnd, int $window): string
    {
        $start = max(0, $positionStart - $window);
        $end   = min(strlen($text), $positionEnd + $window);

        return substr($text, $start, $end - $start);
    }//end extractContext()
}//end class
