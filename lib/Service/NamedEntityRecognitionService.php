<?php

/**
 * Named Entity Recognition Service
 *
 * Service for extracting named entities (persons, organizations, locations, etc.)
 * from text chunks for GDPR compliance and data classification.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Named Entity Recognition Service.
 *
 * Extracts named entities from text chunks using multiple detection methods:
 * - Local regex patterns (fast, privacy-friendly)
 * - External services (Presidio, etc.)
 * - LLM-based extraction (context-aware, accurate)
 * - Hybrid approach (combines multiple methods)
 */
class NamedEntityRecognitionService
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
    public const METHOD_REGEX    = 'regex';
    public const METHOD_PRESIDIO = 'presidio';
    public const METHOD_LLM      = 'llm';
    public const METHOD_HYBRID   = 'hybrid';
    public const METHOD_MANUAL   = 'manual';

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
     * @param GdprEntityMapper     $entityMapper         Entity mapper.
     * @param EntityRelationMapper $entityRelationMapper Entity relation mapper.
     * @param ChunkMapper          $chunkMapper          Chunk mapper.
     * @param SettingsService      $settingsService      Settings service.
     * @param LoggerInterface      $logger               Logger.
     */
    public function __construct(
        private readonly GdprEntityMapper $entityMapper,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly ChunkMapper $chunkMapper,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Extract entities from a text chunk.
     *
     * @param Chunk $chunk   Chunk to process.
     * @param array $options Processing options:
     *                       - method: 'regex', 'presidio', 'llm', 'hybrid' (default: 'hybrid')
     *                       - entity_types: array of entity types to detect (default: all)
     *                       - confidence_threshold: minimum confidence (default: 0.5)
     *                       - context_window: characters around entity (default: 50)
     *
     * @return array{
     *     entities_found: int,
     *     relations_created: int,
     *     entities: array<int, array{type: string, value: string, confidence: float}>
     * }
     *
     * @throws Exception When extraction fails.
     */
    public function extractFromChunk(Chunk $chunk, array $options=[]): array
    {
        $this->logger->info(
                message: '[NamedEntityRecognitionService] Extracting entities from chunk',
                context: [
                    'chunk_id'    => $chunk->getId(),
                    'source_type' => $chunk->getSourceType(),
                    'source_id'   => $chunk->getSourceId(),
                ]
                );

        $method      = $options['method'] ?? 'hybrid';
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
        $detectedEntities = $this->detectEntities($text, $method, $entityTypes, $confidenceThreshold);

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
                    $detected['type'],
                    $detected['value'],
                    $detected['category'] ?? $this->getCategoryForType($detected['type'])
                );

                // Create entity relation.
                $relation = new EntityRelation();
                $relation->setEntityId($entity->getId());
                $relation->setChunkId($chunk->getId());
                $relation->setPositionStart($detected['position_start']);
                $relation->setPositionEnd($detected['position_end']);
                $relation->setConfidence($detected['confidence']);
                $relation->setDetectionMethod($method);
                $relation->setContext($this->extractContext($text, $detected['position_start'], $detected['position_end'], $contextWindow));
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
                        message: '[NamedEntityRecognitionService] Failed to store entity',
                        context: [
                            'chunk_id' => $chunk->getId(),
                            'type'     => $detected['type'] ?? 'unknown',
                            'value'    => substr($detected['value'] ?? '', 0, 50),
                            'error'    => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $this->logger->info(
                message: '[NamedEntityRecognitionService] Entity extraction complete',
                context: [
                    'chunk_id'          => $chunk->getId(),
                    'entities_found'    => $entitiesFound,
                    'relations_created' => $relationsCreated,
                ]
                );

        return [
            'entities_found'    => $entitiesFound,
            'relations_created' => $relationsCreated,
            'entities'          => $storedEntities,
        ];

    }//end extractFromChunk()


    /**
     * Extract entities from multiple chunks in batch.
     *
     * @param array<int, Chunk> $chunks  Chunks to process.
     * @param array             $options Processing options.
     *
     * @return array{
     *     total_chunks: int,
     *     processed: int,
     *     failed: int,
     *     total_entities: int,
     *     total_relations: int,
     *     errors: array<int, string>
     * }
     */
    public function extractFromChunks(array $chunks, array $options=[]): array
    {
        $this->logger->info(
                message: '[NamedEntityRecognitionService] Batch extracting entities',
                context: [
                    'chunk_count' => count($chunks),
                ]
                );

        $processed      = 0;
        $failed         = 0;
        $totalEntities  = 0;
        $totalRelations = 0;
        $errors         = [];

        foreach ($chunks as $chunk) {
            try {
                $result = $this->extractFromChunk($chunk, $options);

                $processed++;
                $totalEntities  += $result['entities_found'];
                $totalRelations += $result['relations_created'];
            } catch (Exception $e) {
                $failed++;
                $errors[$chunk->getId()] = $e->getMessage();

                $this->logger->error(
                        message: '[NamedEntityRecognitionService] Failed to process chunk',
                        context: [
                            'chunk_id' => $chunk->getId(),
                            'error'    => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        return [
            'total_chunks'    => count($chunks),
            'processed'       => $processed,
            'failed'          => $failed,
            'total_entities'  => $totalEntities,
            'total_relations' => $totalRelations,
            'errors'          => $errors,
        ];

    }//end extractFromChunks()


    /**
     * Detect entities in text using specified method.
     *
     * @param string     $text                Text to analyze.
     * @param string     $method              Detection method.
     * @param array|null $entityTypes         Entity types to detect (null = all).
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array<int, array{
     *     type: string,
     *     value: string,
     *     category: string,
     *     position_start: int,
     *     position_end: int,
     *     confidence: float
     * }>
     */
    private function detectEntities(string $text, string $method, ?array $entityTypes, float $confidenceThreshold): array
    {
        return match ($method) {
            self::METHOD_REGEX => $this->detectWithRegex($text, $entityTypes, $confidenceThreshold),
            self::METHOD_PRESIDIO => $this->detectWithPresidio($text, $entityTypes, $confidenceThreshold),
            self::METHOD_LLM => $this->detectWithLLM($text, $entityTypes, $confidenceThreshold),
            self::METHOD_HYBRID => $this->detectWithHybrid($text, $entityTypes, $confidenceThreshold),
            default => throw new Exception("Unknown detection method: {$method}")
        };

    }//end detectEntities()


    /**
     * Detect entities using regex patterns.
     *
     * @param string     $text                Text to analyze.
     * @param array|null $entityTypes         Entity types to detect.
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array<int, array{type: string, value: string, category: string, position_start: int, position_end: int, confidence: float}>
     */
    private function detectWithRegex(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        $entities = [];

        // Email detection.
        if ($entityTypes === null || in_array(self::ENTITY_TYPE_EMAIL, $entityTypes, true) === true) {
            if (preg_match_all('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $text, $matches, PREG_OFFSET_CAPTURE) === true) {
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
     * @return array<int, array{type: string, value: string, category: string, position_start: int, position_end: int, confidence: float}>
     */
    private function detectWithPresidio(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        // TODO: Implement Presidio integration.
        // For now, fall back to regex.
        $this->logger->debug(message: '[NamedEntityRecognitionService] Presidio not yet implemented, using regex fallback');

        return $this->detectWithRegex($text, $entityTypes, $confidenceThreshold);

    }//end detectWithPresidio()


    /**
     * Detect entities using LLM.
     *
     * @param string     $text                Text to analyze.
     * @param array|null $entityTypes         Entity types to detect.
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array<int, array{type: string, value: string, category: string, position_start: int, position_end: int, confidence: float}>
     */
    private function detectWithLLM(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        // TODO: Implement LLM-based entity extraction.
        // For now, fall back to regex.
        $this->logger->debug(message: '[NamedEntityRecognitionService] LLM extraction not yet implemented, using regex fallback');

        return $this->detectWithRegex($text, $entityTypes, $confidenceThreshold);

    }//end detectWithLLM()


    /**
     * Detect entities using hybrid approach (combines multiple methods).
     *
     * @param string     $text                Text to analyze.
     * @param array|null $entityTypes         Entity types to detect.
     * @param float      $confidenceThreshold Minimum confidence.
     *
     * @return array<int, array{type: string, value: string, category: string, position_start: int, position_end: int, confidence: float}>
     */
    private function detectWithHybrid(string $text, ?array $entityTypes, float $confidenceThreshold): array
    {
        // Start with regex for fast detection.
        $regexEntities = $this->detectWithRegex($text, $entityTypes, $confidenceThreshold);

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
     */
    private function findOrCreateEntity(string $type, string $value, string $category): GdprEntity
    {
        // Try to find existing entity by value and type.
        try {
            $qb = $this->entityMapper->getQueryBuilder();
            $qb->select('*')
                ->from('openregister_entities')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
                ->andWhere($qb->expr()->eq('value', $qb->createNamedParameter($value)))
                ->setMaxResults(1);

            $existing = $this->entityMapper->findEntity($qb);

            // Update timestamp.
            $existing->setUpdatedAt(new DateTime());
            $this->entityMapper->update($existing);

            return $existing;
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
            self::ENTITY_TYPE_PERSON, self::ENTITY_TYPE_EMAIL, self::ENTITY_TYPE_PHONE, self::ENTITY_TYPE_ADDRESS => self::CATEGORY_PERSONAL_DATA,
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
