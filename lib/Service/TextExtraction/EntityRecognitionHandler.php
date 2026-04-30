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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Entity recognition integrates multiple extraction strategies
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Multiple detection strategies require per-strategy methods
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
        return $this->storeDetectedEntities(
            detectedEntities: $detectedEntities,
            chunk: $chunk,
            text: $text,
            method: $method,
            contextWindow: $contextWindow
        );
    }//end extractFromChunk()

    /**
     * Store detected entities and create chunk-entity relations.
     *
     * Iterates over detected entities, finds or creates each entity record,
     * creates a relation linking the entity to the chunk, and collects results.
     *
     * @param array  $detectedEntities Array of detected entity data from detection methods.
     * @param Chunk  $chunk            The chunk the entities were extracted from.
     * @param string $text             The full text content of the chunk.
     * @param string $method           The detection method used.
     * @param int    $contextWindow    Characters of context to extract around each entity.
     *
     * @return array{entities_found: int, relations_created: int, entities: array} Storage results.
     */
    private function storeDetectedEntities(
        array $detectedEntities,
        Chunk $chunk,
        string $text,
        string $method,
        int $contextWindow
    ): array {
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

                    // Populate disambiguating columns so DSAR /
                    // retention-enforcement composition can resolve the
                    // owning object deterministically across magic-tables.
                    // The int `object_id` alone collides because magic-table
                    // sequences are scoped per-table.
                    $this->populateObjectContextOnRelation(
                        relation: $relation,
                        objectId: $chunk->getSourceId()
                    );
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
    }//end storeDetectedEntities()

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
        $patterns = $this->getRegexPatterns();

        foreach ($patterns as $patternDef) {
            // Skip entity types not requested.
            if ($entityTypes !== null && in_array($patternDef['type'], $entityTypes, true) === false) {
                continue;
            }

            if (preg_match_all($patternDef['pattern'], $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as $match) {
                    $entities[] = [
                        'type'           => $patternDef['type'],
                        'value'          => $match[0],
                        'category'       => $patternDef['category'],
                        'position_start' => $match[1],
                        'position_end'   => $match[1] + strlen($match[0]),
                        'confidence'     => $patternDef['confidence'],
                    ];
                }
            }
        }//end foreach

        // Filter by confidence threshold.
        return array_filter(
            $entities,
            fn($e) => $e['confidence'] >= $confidenceThreshold
        );
    }//end detectWithRegex()

    /**
     * Get regex pattern definitions for entity detection.
     *
     * Returns an array of pattern definitions, each containing the entity type,
     * regex pattern, category, and confidence level.
     *
     * @return array<array{type: string, pattern: string, category: string, confidence: float}> Pattern definitions.
     */
    private function getRegexPatterns(): array
    {
        return [
            [
                'type'       => self::ENTITY_TYPE_EMAIL,
                'pattern'    => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
                'category'   => self::CATEGORY_PERSONAL_DATA,
                'confidence' => 0.9,
            ],
            [
                'type'       => self::ENTITY_TYPE_PHONE,
                'pattern'    => '/\+?[1-9]\d{1,14}|\+?31\s?[0-9]{9}|\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/',
                'category'   => self::CATEGORY_PERSONAL_DATA,
                'confidence' => 0.7,
            ],
            [
                'type'       => self::ENTITY_TYPE_IBAN,
                'pattern'    => '/[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]?){0,16}/',
                'category'   => self::CATEGORY_SENSITIVE_PII,
                'confidence' => 0.8,
            ],
        ];
    }//end getRegexPatterns()

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
                return $this->detectWithRegex(
                    text: $text,
                    entityTypes: $entityTypes,
                    confidenceThreshold: $confidenceThreshold
                );
            }

            // Build request body.
            $requestBody = $this->buildAnalyzeRequestBody(text: $text, language: 'en', entityTypes: $entityTypes);

            // Make HTTP request and parse response.
            $apiResults = $this->postAnalyzeRequest(
                url: $presidioEndpoint.'/analyze',
                requestBody: $requestBody,
                serviceName: 'Presidio'
            );

            if ($apiResults === null) {
                return $this->detectWithRegex(
                    text: $text,
                    entityTypes: $entityTypes,
                    confidenceThreshold: $confidenceThreshold
                );
            }

            $this->logger->debug(
                message: '[EntityRecognitionHandler] Presidio found '.count($apiResults).' entities',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Convert results to our format.
            return $this->convertApiResultsToEntities(
                apiResults: $apiResults,
                text: $text,
                confidenceThreshold: $confidenceThreshold,
                method: self::METHOD_PRESIDIO,
                defaultConfidence: 0
            );
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
            $fileSettings = $this->settingsService->getFileSettingsOnly();
            $anonEndpoint = $fileSettings['openAnonymiserApiEndpoint'] ?? '';

            if (empty($anonEndpoint) === true) {
                $this->logger->warning(
                    message: '[EntityRecognitionHandler] OpenAnonymiser endpoint not configured, falling back to regex',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return $this->detectWithRegex(
                    text: $text,
                    entityTypes: $entityTypes,
                    confidenceThreshold: $confidenceThreshold
                );
            }

            // Build request body.
            $requestBody = $this->buildAnalyzeRequestBody(text: $text, language: 'nl', entityTypes: $entityTypes);

            // Make HTTP request and parse response.
            $responseData = $this->postAnalyzeRequest(
                url: $anonEndpoint.'/api/v1/analyze',
                requestBody: $requestBody,
                serviceName: 'OpenAnonymiser'
            );

            if ($responseData === null) {
                return $this->detectWithRegex(
                    text: $text,
                    entityTypes: $entityTypes,
                    confidenceThreshold: $confidenceThreshold
                );
            }

            // OpenAnonymiser wraps results in {"pii_entities": [...]}.
            // If the response is a flat array of entities, use it directly.
            $anonymiserResults = $responseData;
            if (isset($responseData['pii_entities']) === true) {
                $anonymiserResults = $responseData['pii_entities'];
            }

            $this->logger->debug(
                message: '[EntityRecognitionHandler] OpenAnonymiser found '.count($anonymiserResults).' entities',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Convert results to our format.
            // OpenAnonymiser may return null score for NLP-detected entities (e.g. PERSON).
            // Treat null as high confidence (0.85) since these are spaCy NER detections.
            return $this->convertApiResultsToEntities(
                apiResults: $anonymiserResults,
                text: $text,
                confidenceThreshold: $confidenceThreshold,
                method: self::METHOD_OPENANONYMISER,
                defaultConfidence: 0.85
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[EntityRecognitionHandler] OpenAnonymiser detection failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return $this->detectWithRegex(text: $text, entityTypes: $entityTypes, confidenceThreshold: $confidenceThreshold);
        }//end try
    }//end detectWithOpenAnonymiser()

    /**
     * Build the request body for an analyze API call.
     *
     * Constructs the JSON request payload with text, language, and optional entity type filters.
     *
     * @param string     $text        Text to analyze.
     * @param string     $language    Language code (e.g. 'en', 'nl').
     * @param array|null $entityTypes Entity types to detect (null = all).
     *
     * @return array The request body array ready for JSON encoding.
     */
    private function buildAnalyzeRequestBody(string $text, string $language, ?array $entityTypes): array
    {
        $requestBody = [
            'text'     => $text,
            'language' => $language,
        ];

        // Add entity types filter if specified.
        if ($entityTypes !== null && empty($entityTypes) === false) {
            $presidioEntities = $this->mapToPresidioEntityTypes(entityTypes: $entityTypes);
            if (empty($presidioEntities) === false) {
                $requestBody['entities'] = $presidioEntities;
            }
        }

        return $requestBody;
    }//end buildAnalyzeRequestBody()

    /**
     * Post an analyze request to an external entity detection service.
     *
     * Handles the curl POST request, error checking, and JSON response parsing.
     * Returns null on any failure (connection error, non-200, invalid JSON).
     *
     * @param string $url         The full URL to POST to.
     * @param array  $requestBody The request body to JSON-encode.
     * @param string $serviceName Human-readable service name for log messages.
     *
     * @return array|null Parsed JSON response array, or null on failure.
     */
    private function postAnalyzeRequest(string $url, array $requestBody, string $serviceName): ?array
    {
        $ch = curl_init($url);
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
                message: "[EntityRecognitionHandler] {$serviceName} connection error: ".$curlError,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        if ($httpCode !== 200) {
            $this->logger->error(
                message: "[EntityRecognitionHandler] {$serviceName} returned HTTP ".$httpCode,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        if (is_string($response) === false) {
            $this->logger->error(
                message: "[EntityRecognitionHandler] {$serviceName} returned non-string response",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            $this->logger->error(
                message: "[EntityRecognitionHandler] Failed to parse {$serviceName} response",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        return $decoded;
    }//end postAnalyzeRequest()

    /**
     * Convert API entity detection results to our internal entity format.
     *
     * Handles both Presidio-style and OpenAnonymiser-style result arrays, extracting
     * entity type, value, position, and confidence for each detected entity.
     *
     * @param array  $apiResults          Array of entity detection results from the API.
     * @param string $text                The original text that was analyzed.
     * @param float  $confidenceThreshold Minimum confidence to include.
     * @param string $method              Detection method constant (e.g. METHOD_PRESIDIO).
     * @param float  $defaultConfidence   Default confidence when score is missing.
     *
     * @return array Array of entities in our internal format.
     */
    private function convertApiResultsToEntities(
        array $apiResults,
        string $text,
        float $confidenceThreshold,
        string $method,
        float $defaultConfidence
    ): array {
        $entities = [];

        foreach ($apiResults as $result) {
            $score = $result['score'] ?? $defaultConfidence;

            // Skip low confidence results.
            if ($score < $confidenceThreshold) {
                continue;
            }

            $start = $result['start'] ?? 0;
            $end   = $result['end'] ?? 0;
            // Use 'text' field if available (OpenAnonymiser), otherwise extract from source text.
            $value = $result['text'] ?? substr($text, $start, ($end - $start));

            $entityType = $this->mapFromPresidioEntityType(presidioType: $result['entity_type'] ?? 'UNKNOWN');

            $entities[] = [
                'type'           => $entityType,
                'value'          => $value,
                'category'       => $this->getCategoryForType(type: $entityType),
                'position_start' => $start,
                'position_end'   => $end,
                'confidence'     => $score,
                'method'         => $method,
            ];
        }//end foreach

        return $entities;
    }//end convertApiResultsToEntities()

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

    /**
     * Populate the disambiguating object-context columns on a relation.
     *
     * Magic-table id sequences are scoped per-table, so the same
     * `object_id` int can collide across tables. Storing the owning
     * register slug + schema slug + object uuid makes downstream
     * lookups (DSAR composition, retention enforcement) deterministic.
     *
     * Best-effort: on any failure (object not found, mapper throws,
     * etc.) the legacy `object_id` is still set and the new columns
     * stay null — preserves current behaviour.
     *
     * @param EntityRelation $relation Relation being persisted.
     * @param int            $objectId Magic-table object id.
     *
     * @return void
     */
    private function populateObjectContextOnRelation(EntityRelation $relation, int $objectId): void
    {
        try {
            $objectMapper = \OC::$server->get(\OCA\OpenRegister\Db\MagicMapper::class);
            $object       = $objectMapper->find(
                $objectId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            // Best-effort enrichment; leave the new columns null.
            return;
        }

        $relation->setRegisterId((string) $object->getRegister());
        $relation->setSchemaId((string) $object->getSchema());
        $relation->setObjectUuid((string) $object->getUuid());

    }//end populateObjectContextOnRelation()
}//end class
