<?php
/**
 * TextExtractionService
 *
 * This service handles all text extraction logic for files in the system.
 * It consolidates extraction workflows, file tracking, and re-extraction detection.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use JsonException;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Service\TextExtraction\ObjectHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

// Document parsing libraries.
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;

/**
 * TextExtractionService
 *
 * Handles text extraction from files with intelligent re-extraction detection.
 * Includes chunking logic for better document processing.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class TextExtractionService
{
    /**
     * Default chunk size in characters
     *
     * @var int
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Default overlap size in characters
     *
     * @var int
     */
    private const DEFAULT_CHUNK_OVERLAP = 200;

    /**
     * Maximum chunks per file (safety limit)
     *
     * @var int
     */
    private const MAX_CHUNKS_PER_FILE = 1000;

    /**
     * Minimum chunk size in characters
     *
     * @var int
     */
    private const MIN_CHUNK_SIZE = 100;

    /**
     * Recursive character splitting strategy
     *
     * @var string
     */
    private const RECURSIVE_CHARACTER = 'RECURSIVE_CHARACTER';

    /**
     * Fixed size splitting strategy
     *
     * @var string
     */
    private const FIXED_SIZE = 'FIXED_SIZE';


    /**
     * Constructor
     *
     * @param FileMapper               $fileMapper               Mapper for Nextcloud files
     * @param ChunkMapper              $chunkMapper              Mapper for chunks
     * @param IRootFolder              $rootFolder               Nextcloud root folder
     * @param IDBConnection            $db                       Database connection
     * @param LoggerInterface          $logger                   Logger
     * @param ObjectEntityMapper       $objectEntityMapper       Mapper for object entities
     * @param SchemaMapper             $schemaMapper             Mapper for schemas
     * @param RegisterMapper           $registerMapper           Mapper for registers
     * @param EntityRecognitionHandler $entityRecognitionHandler Handler for entity recognition
     * @param GdprEntityMapper         $entityMapper             Mapper for GDPR entities
     * @param EntityRelationMapper     $entityRelationMapper     Mapper for entity relations
     */
    public function __construct(
        private readonly FileMapper $fileMapper,
        private readonly ChunkMapper $chunkMapper,
        private readonly IRootFolder $rootFolder,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly EntityRecognitionHandler $entityRecognitionHandler,
        private readonly GdprEntityMapper $entityMapper,
        private readonly EntityRelationMapper $entityRelationMapper
    ) {

    }//end __construct()


    /**
     * Extract text from a file by Nextcloud file ID
     *
     * This method:
     * 1. Looks up file in Nextcloud's oc_filecache
     * 2. Checks if re-extraction is needed (file modified since last extraction)
     * 3. Performs extraction if needed
     *
     * @param int  $fileId         Nextcloud file ID from oc_filecache
     * @param bool $forceReExtract Force re-extraction even if file hasn't changed
     *
     * @return void
     *
     * @throws NotFoundException If file doesn't exist in Nextcloud
     * @throws Exception If extraction fails
     */
    public function extractFile(int $fileId, bool $forceReExtract=false): void
    {
        $this->logger->info('[TextExtractionService] Starting file extraction', ['fileId' => $fileId]);

        $ncFile = $this->fileMapper->getFile($fileId);
        if ($ncFile === null) {
            throw new NotFoundException("File with ID {$fileId} not found in Nextcloud");
        }

        $sourceTimestamp = (int) ($ncFile['mtime'] ?? time());

        // Check if chunks are up-to-date.
        $isUpToDate = $this->isSourceUpToDate(
            sourceId: $fileId,
            sourceType: 'file',
            sourceTimestamp: $sourceTimestamp,
            forceReExtract: $forceReExtract
        );
        if ($forceReExtract === false && $isUpToDate === true) {
            // File is up-to-date and all chunks are still valid.
            $this->logger->info('[TextExtractionService] File already processed and up-to-date', ['fileId' => $fileId]);
            return;
        }

        // Extract and sanitize the source text payload (includes language metadata).
        $payload = $this->extractSourceText(sourceType: 'file', sourceId: $fileId, sourceMeta: $ncFile);
        $chunks  = $this->textToChunks(
                payload: $payload,
                options: [
                    'chunk_size'    => self::DEFAULT_CHUNK_SIZE,
                    'chunk_overlap' => self::DEFAULT_CHUNK_OVERLAP,
                    'strategy'      => self::RECURSIVE_CHARACTER,
                ]
                );

        // Persist textual chunks and include the metadata chunk at the end.
        $this->persistChunksForSource(
            sourceType: 'file',
            sourceId: $fileId,
            chunks: $chunks,
            owner: $payload['owner'] ?? null,
            organisation: $payload['organisation'] ?? null,
            sourceTimestamp: $sourceTimestamp,
            payload: $payload
        );

        // Extract entities from chunks.
        try {
            $entityResult = $this->entityRecognitionHandler->processSourceChunks(
                sourceType: 'file',
                sourceId: $fileId,
                options: [
                    'method'               => 'hybrid',
                    'confidence_threshold' => 0.5,
                ]
            );

            $this->logger->info(
                    '[TextExtractionService] Entity extraction complete',
                    [
                        'fileId'            => $fileId,
                        'entities_found'    => $entityResult['entities_found'],
                        'relations_created' => $entityResult['relations_created'],
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    '[TextExtractionService] Entity extraction failed',
                    [
                        'fileId' => $fileId,
                        'error'  => $e->getMessage(),
                    ]
                    );
        }//end try

        $this->logger->info(
                '[TextExtractionService] File extraction complete',
                [
                    'fileId'     => $fileId,
                    'chunkCount' => count($chunks) + 1,
                ]
                );

    }//end extractFile()


    /**
     * Extract text from an object by object ID
     *
     * This method:
     * 1. Looks up object in the database
     * 2. Checks if re-extraction is needed (object modified since last extraction)
     * 3. Performs extraction if needed using ObjectHandler
     *
     * @param int  $objectId       Object ID
     * @param bool $forceReExtract Force re-extraction even if object hasn't changed
     *
     * @return void
     *
     * @throws DoesNotExistException If object doesn't exist
     * @throws Exception If extraction fails
     */
    public function extractObject(int $objectId, bool $forceReExtract=false): void
    {
        $this->logger->info('[TextExtractionService] Starting object extraction', ['objectId' => $objectId]);

        // Get object to check timestamp.
        $object          = $this->objectEntityMapper->find($objectId);
        $sourceTimestamp = $object->getUpdated()?->getTimestamp() ?? time();

        // Check if chunks are up-to-date.
        $isUpToDate = $this->isSourceUpToDate(
            sourceId: $objectId,
            sourceType: 'object',
            sourceTimestamp: $sourceTimestamp,
            forceReExtract: $forceReExtract
        );
        if ($forceReExtract === false && $isUpToDate === true) {
            // Object is up-to-date and all chunks are still valid.
            $this->logger->info('[TextExtractionService] Object already processed and up-to-date', ['objectId' => $objectId]);
            return;
        }

        // Create ObjectHandler and extract text.
        $objectHandler = new ObjectHandler(
            $this->objectEntityMapper,
            $this->chunkMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->logger
        );

        // Get object metadata.
        $sourceMeta = $objectHandler->getSourceMetadata($objectId);

        // Extract text using ObjectHandler.
        $extractedData = $objectHandler->extractText(sourceId: $objectId, sourceMeta: $sourceMeta, force: $forceReExtract);
        $cleanText     = $this->sanitizeText($extractedData['text']);

        if ($cleanText === '') {
            throw new Exception('Text extraction resulted in an empty payload for object.');
        }

        // Collect lightweight language metadata to enrich chunk storage.
        $languageSignals = $this->detectLanguageSignals($cleanText);

        $payload = [
            'source_type'         => 'object',
            'source_id'           => $objectId,
            'text'                => $cleanText,
            'length'              => strlen($cleanText),
            'checksum'            => hash('sha256', $cleanText),
            'method'              => 'object_extraction',
            'owner'               => $extractedData['owner'] ?? null,
            'organisation'        => $extractedData['organisation'] ?? null,
            'language'            => $languageSignals['language'],
            'language_level'      => $languageSignals['language_level'],
            'language_confidence' => $languageSignals['language_confidence'],
            'detection_method'    => $languageSignals['detection_method'],
            'metadata'            => $extractedData['metadata'] ?? [],
        ];

        $chunks = $this->textToChunks(
            payload: $payload,
            options: [
                'source_type' => $payload['source_type'],
                'source_id'   => $payload['source_id'],
            ]
        );

        // Persist chunks to database.
        $this->persistChunksForSource(
            sourceType: 'object',
            sourceId: $objectId,
            chunks: $chunks,
            owner: $payload['owner'],
            organisation: $payload['organisation'],
            sourceTimestamp: $sourceTimestamp,
            payload: $payload
        );

        // Extract entities from chunks.
        try {
            $entityResult = $this->entityRecognitionHandler->processSourceChunks(
                sourceType: 'object',
                sourceId: $objectId,
                options: [
                    'method'               => 'hybrid',
                    'confidence_threshold' => 0.5,
                ]
            );

            $this->logger->info(
                    '[TextExtractionService] Entity extraction complete',
                    [
                        'objectId'          => $objectId,
                        'entities_found'    => $entityResult['entities_found'],
                        'relations_created' => $entityResult['relations_created'],
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    '[TextExtractionService] Entity extraction failed',
                    [
                        'objectId' => $objectId,
                        'error'    => $e->getMessage(),
                    ]
                    );
        }//end try

        $this->logger->info(
                '[TextExtractionService] Object extraction completed',
                [
                    'objectId'   => $objectId,
                    'chunkCount' => count($chunks) + 1,
                ]
                );

    }//end extractObject()


    /**
     * Determine whether the latest chunks already reflect the current source state.
     *
     * Checks if chunks exist and if their checksum matches the current source checksum.
     *
     * @param int    $sourceId        Identifier of the source (file/object/etc).
     * @param string $sourceType      Source type key.
     * @param int    $sourceTimestamp Source modification timestamp.
     * @param bool   $forceReExtract  Force flag coming from the caller.
     *
     * @phpstan-param non-empty-string $sourceType
     * @psalm-param   non-empty-string $sourceType
     *
     * @return bool
     */
    private function isSourceUpToDate(int $sourceId, string $sourceType, int $sourceTimestamp, bool $forceReExtract): bool
    {
        if ($forceReExtract === true) {
            // Caller explicitly asked to ignore cached data.
            return false;
        }

        // Look at the newest chunk timestamp for this source.
        $latestChunkTimestamp = $this->chunkMapper->getLatestUpdatedTimestamp(sourceType: $sourceType, sourceId: $sourceId);

        if ($latestChunkTimestamp === null) {
            return false;
        }

        return $latestChunkTimestamp >= $sourceTimestamp;

    }//end isSourceUpToDate()


    /**
     * Extract and sanitize text for a given source.
     *
     * @param string               $sourceType Source type identifier.
     * @param int                  $sourceId   Source identifier.
     * @param array<string, mixed> $sourceMeta Raw metadata from the source system.
     *
     * @phpstan-param non-empty-string $sourceType
     * @psalm-param   non-empty-string $sourceType
     *
     * @return array{
     *     source_type: string,
     *     source_id: int,
     *     text: string,
     *     length: int,
     *     checksum: string,
     *     method: string,
     *     owner: string|null,
     *     organisation: string|null,
     *     language: string|null,
     *     language_level: string|null,
     *     language_confidence: float|null,
     *     detection_method: string|null,
     *     metadata: array<string, mixed>
     * }
     *
     * @throws Exception When the text cannot be extracted.
     */
    private function extractSourceText(string $sourceType, int $sourceId, array $sourceMeta): array
    {
        $rawText = $this->performTextExtraction(fileId: $sourceId, ncFile: $sourceMeta);
        if ($rawText === null) {
            throw new Exception('Text extraction returned no result for source.');
        }

        $cleanText = $this->sanitizeText($rawText);
        if ($cleanText === '') {
            throw new Exception('Text extraction resulted in an empty payload.');
        }

        // Collect lightweight language metadata to enrich chunk storage.
        $languageSignals = $this->detectLanguageSignals($cleanText);

        return [
            'source_type'         => $sourceType,
            'source_id'           => $sourceId,
            'text'                => $cleanText,
            'length'              => strlen($cleanText),
            'checksum'            => hash('sha256', $cleanText),
        // Stable checksum to detect text mutations.
            'method'              => 'llphant',
            'owner'               => $sourceMeta['owner'] ?? null,
            'organisation'        => $sourceMeta['organisation'] ?? null,
            'language'            => $languageSignals['language'],
            'language_level'      => $languageSignals['language_level'],
            'language_confidence' => $languageSignals['language_confidence'],
            'detection_method'    => $languageSignals['detection_method'],
            'metadata'            => [
                'file_path' => $sourceMeta['path'] ?? null,
                'file_name' => $sourceMeta['name'] ?? null,
                'mime_type' => $sourceMeta['mimetype'] ?? null,
                'file_size' => $sourceMeta['size'] ?? null,
            ],
        ];

    }//end extractSourceText()


    /**
     * Lightweight placeholder for language detection.
     *
     * @param string $text Input text.
     *
     * @return (float|null|string)[]
     *
     * @psalm-return array{language: 'en'|'nl'|null, language_level: null, language_confidence: float|null, detection_method: string}
     */
    private function detectLanguageSignals(string $text): array
    {
        $language   = null;
        $confidence = null;

        // Extremely naive heuristic as a placeholder until dedicated detection is plugged in.
        if (preg_match('/\b(de|het|een)\b/i', $text) === 1) {
            $language   = 'nl';
            $confidence = 0.35;
        } else if (preg_match('/\b(the|and|of)\b/i', $text) === 1) {
            $language   = 'en';
            $confidence = 0.35;
        }

        return [
            'language'            => $language,
            'language_level'      => null,
            'language_confidence' => $confidence,
            'detection_method'    => $this->getDetectionMethod($language),
        ];

    }//end detectLanguageSignals()


    /**
     * Convert a text payload into chunk DTOs ready for persistence.
     *
     * @param array<string, mixed> $payload Sanitized payload coming from extractSourceText().
     * @param array<string, mixed> $options Chunking options.
     *
     * @return (array|int|mixed|null)[][]
     *
     * @psalm-return list<array{
     *     checksum: mixed|null,
     *     chunk_index: int<0, max>,
     *     detection_method: mixed|null,
     *     end_offset: int<0, max>|mixed,
     *     language: mixed|null,
     *     language_confidence: mixed|null,
     *     language_level: mixed|null,
     *     overlap_size: int,
     *     position_reference: array{end?: 0|mixed, path?: mixed|null, start?: 0|mixed, type: 'property-path'|'text-range'},
     *     start_offset: 0|mixed,
     *     text_content: mixed
     * }>
     */
    private function textToChunks(array $payload, array $options=[]): array
    {
        $chunkSize    = (int) ($options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE);
        $chunkOverlap = (int) ($options['chunk_overlap'] ?? self::DEFAULT_CHUNK_OVERLAP);
        $strategy     = (string) ($options['strategy'] ?? self::RECURSIVE_CHARACTER);

        // Generate the low-level chunks.
        $rawChunks = $this->chunkDocument(
                text: $payload['text'],
                options: [
                    'chunk_size'    => $chunkSize,
                    'chunk_overlap' => $chunkOverlap,
                    'strategy'      => $strategy,
                ]
                );

        $mappedChunks = [];

        foreach (array_values($rawChunks) as $index => $chunk) {
            // Translate chunk metadata to a persistence-friendly structure.
            $mappedChunks[] = [
                'chunk_index'         => $index,
                'text_content'        => $chunk['text'],
                'start_offset'        => $chunk['start_offset'] ?? 0,
                'end_offset'          => $chunk['end_offset'] ?? strlen($chunk['text']),
                'language'            => $payload['language'] ?? null,
                'language_level'      => $payload['language_level'] ?? null,
                'language_confidence' => $payload['language_confidence'] ?? null,
                'detection_method'    => $payload['detection_method'] ?? null,
                'overlap_size'        => $chunkOverlap,
                'position_reference'  => $this->buildPositionReference(sourceType: $payload['source_type'], chunk: $chunk),
                'checksum'            => $payload['checksum'] ?? null,
            ];
        }

        return $mappedChunks;

    }//end textToChunks()


    /**
     * Build a structured position reference for traceability.
     *
     * @param string              $sourceType Source type identifier.
     * @param array<string,mixed> $chunk      Chunk metadata from chunkDocument.
     *
     * @phpstan-param non-empty-string $sourceType
     *
     * @psalm-param non-empty-string $sourceType
     *
     * @return (int|mixed|null|string)[]
     *
     * @psalm-return array{type: 'property-path'|'text-range', start?: 0|mixed, end?: 0|mixed, path?: mixed|null}
     */
    private function buildPositionReference(string $sourceType, array $chunk): array
    {
        if ($sourceType === 'object') {
            return [
                'type' => 'property-path',
                'path' => $chunk['property_path'] ?? null,
            ];
        }

        return [
            'type'  => 'text-range',
            'start' => $chunk['start_offset'] ?? 0,
            'end'   => $chunk['end_offset'] ?? 0,
        ];

    }//end buildPositionReference()


    /**
     * Persist textual chunks for a source.
     *
     * @param string                         $sourceType      Source type identifier.
     * @param int                            $sourceId        Source identifier.
     * @param array<int,array<string,mixed>> $chunks          Chunk payloads.
     * @param string|null                    $owner           Owner identifier.
     * @param string|null                    $organisation    Organisation identifier.
     * @param int                            $sourceTimestamp Source modification timestamp.
     * @param array<string,mixed>            $payload         Extraction payload for metadata chunk creation.
     *
     * @phpstan-param non-empty-string $sourceType
     * @psalm-param   non-empty-string $sourceType
     *
     * @return void
     */
    private function persistChunksForSource(
        string $sourceType,
        int $sourceId,
        array $chunks,
        ?string $owner,
        ?string $organisation,
        int $sourceTimestamp,
        array $payload
    ): void {
        $this->db->beginTransaction();

        try {
            // Remove all existing chunks for this source to avoid stale data.
            $this->chunkMapper->deleteBySource(sourceType: $sourceType, sourceId: $sourceId);

            foreach ($chunks as $chunkData) {
                $chunkEntity = $this->hydrateChunkEntity(
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    chunkData: $chunkData,
                    owner: $owner,
                    organisation: $organisation,
                    sourceTimestamp: $sourceTimestamp
                );

                $this->chunkMapper->insert($chunkEntity);
            }

            $this->persistMetadataChunk(sourceType: $sourceType, sourceId: $sourceId, payload: $payload, sourceTimestamp: $sourceTimestamp);

            $this->db->commit();
        } catch (Throwable $throwable) {
            $this->db->rollBack();
            throw $throwable;
        }//end try

    }//end persistChunksForSource()


    /**
     * Create a Chunk entity from an array payload.
     *
     * @param string              $sourceType      Source type identifier.
     * @param int                 $sourceId        Source identifier.
     * @param array<string,mixed> $chunkData       Chunk payload.
     * @param string|null         $owner           Owner identifier.
     * @param string|null         $organisation    Organisation identifier.
     * @param int                 $sourceTimestamp Source modification timestamp.
     *
     * @phpstan-param non-empty-string $sourceType
     * @psalm-param   non-empty-string $sourceType
     *
     * @return Chunk
     */
    private function hydrateChunkEntity(
        string $sourceType,
        int $sourceId,
        array $chunkData,
        ?string $owner,
        ?string $organisation,
        int $sourceTimestamp
    ): Chunk {
        $chunk = new Chunk();
        $chunk->setUuid(Uuid::v4()->toRfc4122());
        $chunk->setSourceType($sourceType);
        $chunk->setSourceId($sourceId);
        $chunk->setChunkIndex((int) ($chunkData['chunk_index'] ?? 0));
        $chunk->setTextContent((string) $chunkData['text_content']);
        $chunk->setStartOffset((int) ($chunkData['start_offset'] ?? 0));
        $chunk->setEndOffset((int) ($chunkData['end_offset'] ?? strlen($chunkData['text_content'])));
        $chunk->setPositionReference($chunkData['position_reference'] ?? null);
        $chunk->setLanguage($chunkData['language'] ?? null);
        $chunk->setLanguageLevel($chunkData['language_level'] ?? null);
        $chunk->setLanguageConfidence($chunkData['language_confidence'] ?? null);
        $chunk->setDetectionMethod($chunkData['detection_method'] ?? null);
        $chunk->setIndexed(false);
        $chunk->setVectorized(false);
        $chunk->setEmbeddingProvider($chunkData['embedding_provider'] ?? null);
        $chunk->setOverlapSize((int) ($chunkData['overlap_size'] ?? 0));
        $chunk->setOwner($owner);
        $chunk->setOrganisation($organisation);
        $chunk->setChecksum($chunkData['checksum'] ?? null);

        $createdAt = (new DateTime())->setTimestamp($sourceTimestamp);
        $chunk->setCreatedAt($createdAt);
        $chunk->setUpdatedAt(new DateTime());

        return $chunk;

    }//end hydrateChunkEntity()


    /**
     * Persist the metadata chunk that stores provenance details.
     *
     * @param string              $sourceType      Source type identifier.
     * @param int                 $sourceId        Source identifier.
     * @param array<string,mixed> $payload         Extraction payload.
     * @param int                 $sourceTimestamp Source modification timestamp.
     *
     * @phpstan-param non-empty-string $sourceType
     * @psalm-param   non-empty-string $sourceType
     *
     * @return void
     */
    private function persistMetadataChunk(string $sourceType, int $sourceId, array $payload, int $sourceTimestamp): void
    {
        try {
            $metadataText = json_encode(
                $this->summarizeMetadataPayload($payload),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
            );
        } catch (JsonException $exception) {
            $this->logger->warning(
                    '[TextExtractionService] Failed to encode metadata chunk payload',
                    [
                        'sourceType' => $sourceType,
                        'sourceId'   => $sourceId,
                        'error'      => $exception->getMessage(),
                    ]
                    );
            $metadataText = 'metadata_encoding_failed';
        }

        $chunkData = [
            'chunk_index'         => -1,
            'text_content'        => $metadataText,
            'start_offset'        => 0,
            'end_offset'          => strlen($metadataText),
            'language'            => null,
            'language_level'      => null,
            'language_confidence' => null,
            'detection_method'    => null,
            'overlap_size'        => 0,
            'position_reference'  => [
                'type' => 'metadata',
            ],
            'checksum'            => $payload['checksum'] ?? null,
        ];

        $chunkEntity = $this->hydrateChunkEntity(
            sourceType: $sourceType,
            sourceId: $sourceId,
            chunkData: $chunkData,
            owner: $payload['owner'] ?? null,
            organisation: $payload['organisation'] ?? null,
            sourceTimestamp: $sourceTimestamp
        );

        $this->chunkMapper->insert($chunkEntity);

    }//end persistMetadataChunk()


    /**
     * Prepare metadata content for the metadata chunk.
     *
     * @param array<string,mixed> $payload Extraction payload.
     *
     * @return (array|mixed|null)[]
     *
     * @psalm-return array{
     *     source_type: mixed|null,
     *     source_id: mixed|null,
     *     chunk_checksum: mixed|null,
     *     text_length: mixed|null,
     *     language: mixed|null,
     *     language_level: mixed|null,
     organisation: mixed|null,
     *     owner: mixed|null,
     *     file_metadata: array<never, never>|mixed
     * }
     */
    private function summarizeMetadataPayload(array $payload): array
    {
        return [
            'source_type'    => $payload['source_type'] ?? null,
            'source_id'      => $payload['source_id'] ?? null,
            'chunk_checksum' => $payload['checksum'] ?? null,
            'text_length'    => $payload['length'] ?? null,
            'language'       => $payload['language'] ?? null,
            'language_level' => $payload['language_level'] ?? null,
            'organisation'   => $payload['organisation'] ?? null,
            'owner'          => $payload['owner'] ?? null,
            'file_metadata'  => $payload['metadata'] ?? [],
        ];

    }//end summarizeMetadataPayload()


    /**
     * Perform actual text extraction from a file
     *
     * This method handles the actual text extraction from files based on their type.
     * Currently supports simple text-based files. Will be extended to support
     * PDF, DOCX, and other formats via LLPhant or Dolphin extractors.
     *
     * @param int   $fileId Nextcloud file ID
     * @param array $ncFile Nextcloud file metadata
     *
     * @return string|null Extracted text content, or null if extraction not possible
     *
     * @throws Exception If file cannot be read
     */
    private function performTextExtraction(int $fileId, array $ncFile): ?string
    {
        $mimeType = $ncFile['mimetype'] ?? '';
        $filePath = $ncFile['path'] ?? '';

        $this->logger->debug(
                '[TextExtractionService] Attempting extraction',
                [
                    'fileId'   => $fileId,
                    'mimeType' => $mimeType,
                    'filePath' => $filePath,
                ]
                );

        // Get the file node from Nextcloud.
        try {
            // Get file by ID using Nextcloud's file system.
            $nodes = $this->rootFolder->getById($fileId);

            if (empty($nodes) === true) {
                throw new Exception("File not found in Nextcloud file system");
            }

            $file = $nodes[0];

            if ($file instanceof \OCP\Files\File === false) {
                throw new Exception("Node is not a file");
            }

            // Extract text based on mime type.
            // Text-based files that can be read directly.
            $textMimeTypes = [
                'text/plain',
                'text/markdown',
                'text/html',
                'text/xml',
                'application/json',
                'application/xml',
                'text/csv',
                'text/x-yaml',
                'text/yaml',
                'application/x-yaml',
            ];

            if (in_array($mimeType, $textMimeTypes) === true || strpos($mimeType, 'text/') === 0) {
                // Read text file directly.
                $extractedText = $file->getContent();

                $this->logger->debug(
                        '[TextExtractionService] Text file extracted',
                        [
                            'fileId' => $fileId,
                            'length' => strlen($extractedText),
                        ]
                        );
            } else if ($mimeType === 'application/pdf') {
                // Extract text from PDF using Smalot PdfParser.
                $extractedText = $this->extractPdf($file);
            } else if ($this->isWordDocument($mimeType) === true) {
                // Extract text from DOCX/DOC using PhpWord.
                $extractedText = $this->extractWord($file);
            } else if ($this->isSpreadsheet($mimeType) === true) {
                // Extract text from XLSX/XLS using PhpSpreadsheet.
                $extractedText = $this->extractSpreadsheet($file);
            } else {
                // Unsupported file type.
                $this->logger->info(
                        '[TextExtractionService] Unsupported file type',
                        [
                            'fileId'   => $fileId,
                            'mimeType' => $mimeType,
                        ]
                        );

                return null;
            }//end if

            return $extractedText;
        } catch (Exception $e) {
            $this->logger->error(
                    '[TextExtractionService] Failed to read file',
                    [
                        'fileId' => $fileId,
                        'error'  => $e->getMessage(),
                    ]
                    );

            throw $e;
        }//end try

    }//end performTextExtraction()


    /**
     * Discover files in Nextcloud that aren't tracked in the extraction system yet
     *
     * This finds files in oc_filecache that don't have chunks yet.
     * Files are automatically extracted when discovered.
     *
     * @param int $limit Maximum number of files to discover
     *
     * @return (int|string)[] Statistics about discovery: {discovered, failed, total}
     *
     * @psalm-return array{discovered: int<0, max>, failed: int<0, max>, total: int<0, max>, error?: string}
     */
    public function discoverUntrackedFiles(int $limit=100): array
    {
        $this->logger->info('[TextExtractionService] Discovering untracked files', ['limit' => $limit]);

        try {
            // Get untracked files from Nextcloud (files without chunks).
            $untrackedFiles = $this->fileMapper->findUntrackedFiles($limit);
            $discovered     = 0;
            $failed         = 0;

            foreach ($untrackedFiles as $ncFile) {
                try {
                    // Extract file directly - chunks will be created.
                    $this->extractFile(fileId: $ncFile['fileid'], forceReExtract: false);
                    $discovered++;

                    $this->logger->debug(
                            '[TextExtractionService] Discovered and extracted untracked file',
                            [
                                'fileId' => $ncFile['fileid'],
                                'path'   => $ncFile['path'] ?? 'unknown',
                            ]
                            );
                } catch (Exception $e) {
                    $failed++;
                    $this->logger->error(
                            '[TextExtractionService] Failed to extract file',
                            [
                                'fileId' => $ncFile['fileid'] ?? 'unknown',
                                'error'  => $e->getMessage(),
                            ]
                            );
                }//end try
            }//end foreach

            $this->logger->info(
                    '[TextExtractionService] Discovery complete',
                    [
                        'discovered' => $discovered,
                        'failed'     => $failed,
                    ]
                    );

            return [
                'discovered' => $discovered,
                'failed'     => $failed,
                'total'      => count($untrackedFiles),
            ];
        } catch (Exception $e) {
            $this->logger->error('[TextExtractionService] Discovery failed', ['error' => $e->getMessage()]);
            return [
                'discovered' => 0,
                'failed'     => 0,
                'total'      => 0,
                'error'      => $e->getMessage(),
            ];
        }//end try

    }//end discoverUntrackedFiles()


    /**
     * Extract text from files that don't have chunks yet
     *
     * This processes files that haven't been extracted yet.
     * Use discoverUntrackedFiles() first to discover new files.
     *
     * @param int $limit Maximum number of files to process
     *
     * @return int[] Statistics about the extraction process: {processed, failed, total}
     *
     * @psalm-return array{processed: int<0, max>, failed: int<0, max>, total: int<0, max>}
     */
    public function extractPendingFiles(int $limit=100): array
    {
        $this->logger->info('[TextExtractionService] Extracting files without chunks', ['limit' => $limit]);

        // Get files without chunks.
        $untrackedFiles = $this->fileMapper->findUntrackedFiles($limit);

        $this->logger->info(
                '[TextExtractionService] Found files without chunks',
                [
                    'count' => count($untrackedFiles),
                    'limit' => $limit,
                ]
                );

        $processed = 0;
        $failed    = 0;

        foreach ($untrackedFiles as $ncFile) {
            try {
                $this->logger->debug(
                        '[TextExtractionService] Processing file',
                        [
                            'fileId'   => $ncFile['fileid'],
                            'fileName' => $ncFile['name'] ?? 'unknown',
                        ]
                        );

                // Trigger extraction for this file.
                $this->extractFile(fileId: $ncFile['fileid'], forceReExtract: false);
                $processed++;
            } catch (Exception $e) {
                $failed++;
                $this->logger->error(
                        '[TextExtractionService] Failed to extract file',
                        [
                            'fileId' => $ncFile['fileid'] ?? 'unknown',
                            'error'  => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $this->logger->info(
                '[TextExtractionService] Extraction complete',
                [
                    'processed'    => $processed,
                    'failed'       => $failed,
                    'foundPending' => count($untrackedFiles),
                ]
                );

        return [
            'processed' => $processed,
            'failed'    => $failed,
            'total'     => count($untrackedFiles),
        ];

    }//end extractPendingFiles()


    /**
     * Retry file extractions by forcing re-extraction
     *
     * @param int $limit Maximum number of files to retry
     *
     * @return int[] Statistics about the retry process
     *
     * @psalm-return array{retried: int<0, max>, failed: int<0, max>, total: int<0, max>}
     */
    public function retryFailedExtractions(int $limit=50): array
    {
        $this->logger->info('[TextExtractionService] Retrying extractions', ['limit' => $limit]);

        // Get files without chunks or with old chunks.
        $untrackedFiles = $this->fileMapper->findUntrackedFiles($limit);
        $retried        = 0;
        $failed         = 0;

        foreach ($untrackedFiles as $ncFile) {
            try {
                $this->extractFile(fileId: $ncFile['fileid'], forceReExtract: true);
                $retried++;
            } catch (Exception $e) {
                $failed++;
                $this->logger->error(
                        '[TextExtractionService] Retry failed for file',
                        [
                            'fileId' => $ncFile['fileid'] ?? 'unknown',
                            'error'  => $e->getMessage(),
                        ]
                        );
            }
        }

        return [
            'retried' => $retried,
            'failed'  => $failed,
            'total'   => count($untrackedFiles),
        ];

    }//end retryFailedExtractions()


    /**
     * Get extraction statistics
     *
     * @return (int|mixed)[] Statistics about file extraction
     *
     * @psalm-return array{
     *     totalFiles: int,
     *     untrackedFiles: int,
     *     totalChunks: int,
     *     totalObjects: int,
     *     totalEntities: int
     * }
     */
    public function getStats(): array
    {
        $untrackedCount = $this->fileMapper->countUntrackedFiles();
        $chunkCount     = $this->getTableCountSafe('openregister_chunks');
        $objectCount    = $this->getTableCountSafe('openregister_objects');
        $entityCount    = $this->getTableCountSafe('openregister_entities');

        return [
            'totalFiles'     => $untrackedCount + $chunkCount,
            'untrackedFiles' => $untrackedCount,
            'totalChunks'    => $chunkCount,
            'totalObjects'   => $objectCount,
            'totalEntities'  => $entityCount,
        ];

    }//end getStats()


    /**
     * Safely count rows in a table (returns zero if table is missing)
     *
     * @param string $tableName Table name without prefix
     *
     * @return int
     */
    private function getTableCountSafe(string $tableName): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
                ->from($tableName);

            $result = $qb->executeQuery();
            $count  = (int) $result->fetchOne();
            $result->closeCursor();

            return $count;
        } catch (Throwable $e) {
            $this->logger->debug(
                    '[TextExtractionService] Unable to count table',
                    [
                        'table' => $tableName,
                        'error' => $e->getMessage(),
                    ]
                    );

            return 0;
        }//end try

    }//end getTableCountSafe()


    /**
     * Sanitize extracted text for safe database storage
     *
     * Removes or replaces problematic characters that can cause database issues:
     * - NULL bytes
     * - Invalid UTF-8 sequences
     * - Control characters
     * - Non-printable characters
     *
     * @param string $text Raw extracted text
     *
     * @return string Cleaned text safe for database storage
     */
    private function sanitizeText(string $text): string
    {
        // Remove NULL bytes.
        $text = str_replace("\0", '', $text);

        // Convert to UTF-8 if it isn't already.
        if (mb_check_encoding($text, 'UTF-8') === false) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Remove invalid UTF-8 sequences.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Replace problematic characters that MySQL/MariaDB can't handle.
        // These include characters outside the Basic Multilingual Plane (BMP).
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Replace 4-byte UTF-8 characters (emoji, etc.) with a space if using utf8mb3.
        // This prevents "Incorrect string value" errors.
        $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', ' ', $text);

        // Normalize whitespace.
        $text = preg_replace('/\s+/u', ' ', $text);

        // Trim.
        return trim($text);

    }//end sanitizeText()


    /**
     * Extract text from PDF file using Smalot PdfParser
     *
     * @param \OCP\Files\File $file Nextcloud file object
     *
     * @return null|string Extracted text content
     *
     * @throws Exception If PDF parsing fails
     */
    private function extractPdf(\OCP\Files\File $file): string|null
    {
        // Check if PdfParser library is available.
        if (class_exists('Smalot\PdfParser\Parser') === false) {
            $this->logger->warning(
                    '[TextExtractionService] PDF parser library not available',
                    [
                        'fileId' => $file->getId(),
                    ]
                    );
            throw new Exception("PDF parser library (smalot/pdfparser) is not installed. Run: composer require smalot/pdfparser");
        }

        try {
            $this->logger->debug(
                    '[TextExtractionService] Extracting PDF',
                    [
                        'fileId' => $file->getId(),
                        'name'   => $file->getName(),
                    ]
                    );

            // Get file content.
            $content = $file->getContent();

            // Create temporary file for PdfParser (it requires a file path).
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            fwrite($tempFile, $content);

            // Parse PDF.
            $parser = new PdfParser();
            $pdf    = $parser->parseFile($tempPath);

            // Extract text.
            $text = $pdf->getText();

            // Clean up.
            fclose($tempFile);

            if ($text === '') {
                $this->logger->warning(
                        '[TextExtractionService] PDF extraction returned empty text',
                        [
                            'fileId' => $file->getId(),
                        ]
                        );
                return null;
            }

            $this->logger->debug(
                    '[TextExtractionService] PDF extracted successfully',
                    [
                        'fileId' => $file->getId(),
                        'length' => strlen($text),
                    ]
                    );

            return $text;
        } catch (Exception $e) {
            $this->logger->error(
                    '[TextExtractionService] PDF extraction failed',
                    [
                        'fileId' => $file->getId(),
                        'error'  => $e->getMessage(),
                    ]
                    );
            throw new Exception("PDF extraction failed: ".$e->getMessage());
        }//end try

    }//end extractPdf()


    /**
     * Extract text from Word document (DOCX/DOC) using PhpWord
     *
     * @param \OCP\Files\File $file Nextcloud file object
     *
     * @return string|null Extracted text content
     *
     * @throws Exception If Word parsing fails
     */
    private function extractWord(\OCP\Files\File $file): ?string
    {
        // Check if PhpWord library is available.
        if (class_exists('PhpOffice\PhpWord\IOFactory') === false) {
            $this->logger->warning(
                    '[TextExtractionService] PhpWord library not available',
                    [
                        'fileId' => $file->getId(),
                    ]
                    );
            throw new Exception("PhpWord library (phpoffice/phpword) is not installed. Run: composer require phpoffice/phpword");
        }

        try {
            $this->logger->debug(
                    '[TextExtractionService] Extracting Word document',
                    [
                        'fileId' => $file->getId(),
                        'name'   => $file->getName(),
                    ]
                    );

            // Get file content.
            $content = $file->getContent();

            // Create temporary file for PhpWord.
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            fwrite($tempFile, $content);

            // Load Word document.
            $phpWord = WordIOFactory::load($tempPath);

            // Extract text from all sections.
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText') === true) {
                        $text .= $element->getText()."\n";
                    } else if (method_exists($element, 'getElements') === true) {
                        // Handle nested elements (tables, etc.).
                        foreach ($element->getElements() as $childElement) {
                            if (method_exists($childElement, 'getText') === true) {
                                $text .= $childElement->getText()." ";
                            }
                        }

                        $text .= "\n";
                    }
                }
            }

            // Clean up.
            fclose($tempFile);

            if (trim($text) === '' || trim($text) === null) {
                $this->logger->warning(
                        '[TextExtractionService] Word extraction returned empty text',
                        [
                            'fileId' => $file->getId(),
                        ]
                        );
                return null;
            }

            $this->logger->debug(
                    '[TextExtractionService] Word document extracted successfully',
                    [
                        'fileId' => $file->getId(),
                        'length' => strlen($text),
                    ]
                    );

            return $text;
        } catch (Exception $e) {
            $this->logger->error(
                    '[TextExtractionService] Word extraction failed',
                    [
                        'fileId' => $file->getId(),
                        'error'  => $e->getMessage(),
                    ]
                    );
            throw new Exception("Word extraction failed: ".$e->getMessage());
        }//end try

    }//end extractWord()


    /**
     * Extract text from spreadsheet (XLSX/XLS) using PhpSpreadsheet
     *
     * @param \OCP\Files\File $file Nextcloud file object
     *
     * @return string|null Extracted text content
     *
     * @throws Exception If spreadsheet parsing fails
     */
    private function extractSpreadsheet(\OCP\Files\File $file): ?string
    {
        // PhpSpreadsheet should already be installed (in composer.json).
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory') === false) {
            $this->logger->warning(
                    '[TextExtractionService] PhpSpreadsheet library not available',
                    [
                        'fileId' => $file->getId(),
                    ]
                    );
            throw new Exception("PhpSpreadsheet library (phpoffice/phpspreadsheet) is not installed. Run: composer require phpoffice/phpspreadsheet");
        }

        try {
            $this->logger->debug(
                    '[TextExtractionService] Extracting spreadsheet',
                    [
                        'fileId' => $file->getId(),
                        'name'   => $file->getName(),
                    ]
                    );

            // Get file content.
            $content = $file->getContent();

            // Create temporary file for PhpSpreadsheet.
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            fwrite($tempFile, $content);

            // Load spreadsheet.
            $spreadsheet = SpreadsheetIOFactory::load($tempPath);

            // Extract text from all sheets.
            $text = '';
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $text .= "Sheet: ".$sheet->getTitle()."\n";

                $highestRow    = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();

                // Iterate through rows and columns.
                for ($row = 1; $row <= $highestRow; $row++) {
                    $rowData = [];
                    // @psalm-suppress StringIncrement - Excel column increment is intentional
                    for ($col = 'A'; $col !== $highestColumn; $col++) {
                        $value = $sheet->getCell($col.$row)->getValue();
                        if ($value !== null && $value !== '') {
                            $rowData[] = $value;
                        }
                    }

                    // Add last column.
                    $value = $sheet->getCell($highestColumn.$row)->getValue();
                    if ($value !== null && $value !== '') {
                        $rowData[] = $value;
                    }

                    if (empty($rowData) === false) {
                        $text .= implode("\t", $rowData)."\n";
                    }
                }

                $text .= "\n";
            }//end foreach

            // Clean up.
            fclose($tempFile);

            if (trim($text) === '' || trim($text) === null) {
                $this->logger->warning(
                        '[TextExtractionService] Spreadsheet extraction returned empty text',
                        [
                            'fileId' => $file->getId(),
                        ]
                        );
                return null;
            }

            $this->logger->debug(
                    '[TextExtractionService] Spreadsheet extracted successfully',
                    [
                        'fileId' => $file->getId(),
                        'length' => strlen($text),
                    ]
                    );

            return $text;
        } catch (Exception $e) {
            $this->logger->error(
                    '[TextExtractionService] Spreadsheet extraction failed',
                    [
                        'fileId' => $file->getId(),
                        'error'  => $e->getMessage(),
                    ]
                    );
            throw new Exception("Spreadsheet extraction failed: ".$e->getMessage());
        }//end try

    }//end extractSpreadsheet()


    /**
     * Chunk a document into smaller pieces for processing
     *
     * This method splits text into manageable chunks with optional overlap.
     * Supports multiple chunking strategies.
     *
     * @param string $text    The text to chunk
     * @param array  $options Chunking options (chunk_size, chunk_overlap, strategy)
     *
     * @return array Array of text chunks
     */
    public function chunkDocument(string $text, array $options=[]): array
    {
        $chunkSize    = $options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE;
        $chunkOverlap = $options['chunk_overlap'] ?? self::DEFAULT_CHUNK_OVERLAP;
        $strategy     = $options['strategy'] ?? self::RECURSIVE_CHARACTER;

        $this->logger->debug(
                '[TextExtractionService] Chunking document',
                [
                    'text_length'   => strlen($text),
                    'chunk_size'    => $chunkSize,
                    'chunk_overlap' => $chunkOverlap,
                    'strategy'      => $strategy,
                ]
                );

        $startTime = microtime(true);

        // Clean the text first.
        $text = $this->cleanText($text);

        // Choose chunking strategy.
        $chunks = match ($strategy) {
            self::FIXED_SIZE => $this->chunkFixedSize(text: $text, chunkSize: $chunkSize, chunkOverlap: $chunkOverlap),
            self::RECURSIVE_CHARACTER => $this->chunkRecursive(text: $text, chunkSize: $chunkSize, chunkOverlap: $chunkOverlap),
            default => $this->chunkRecursive(text: $text, chunkSize: $chunkSize, chunkOverlap: $chunkOverlap)
        };

        // Respect max chunks limit.
        if (count($chunks) > self::MAX_CHUNKS_PER_FILE) {
            $this->logger->warning(
                    '[TextExtractionService] File exceeds max chunks, truncating',
                    [
                        'chunks' => count($chunks),
                        'max'    => self::MAX_CHUNKS_PER_FILE,
                    ]
                    );
            $chunks = array_slice($chunks, 0, self::MAX_CHUNKS_PER_FILE);
        }

        $chunkingTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                '[TextExtractionService] Document chunked successfully',
                [
                    'chunk_count'      => count($chunks),
                    'chunking_time_ms' => $chunkingTime,
                    'avg_chunk_size'   => $this->calculateAvgChunkSize($chunks),
                ]
                );

        return $chunks;

    }//end chunkDocument()


    /**
     * Clean text by removing excessive whitespace and normalizing
     *
     * @param string $text Text to clean
     *
     * @return string Cleaned text
     */
    private function cleanText(string $text): string
    {
        // Remove null bytes.
        $text = str_replace("\0", '', $text);

        // Normalize line endings.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove excessive whitespace but preserve paragraph breaks.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);

    }//end cleanText()


    /**
     * Chunk text using fixed size with overlap
     *
     * @param string $text         Text to chunk
     * @param int    $chunkSize    Target chunk size
     * @param int    $chunkOverlap Overlap size
     *
     * @return (int|string)[][] Array of chunk objects with text, start_offset, end_offset
     *
     * @psalm-return array<int<0, max>, array{text: string, start_offset: int<0, max>, end_offset: int<0, max>}>
     */
    private function chunkFixedSize(string $text, int $chunkSize, int $chunkOverlap): array
    {
        if (strlen($text) <= $chunkSize) {
            return [
                [
                    'text'         => $text,
                    'start_offset' => 0,
                    'end_offset'   => strlen($text),
                ],
            ];
        }

        $chunks = [];
        $offset = 0;

        while ($offset < strlen($text)) {
            // Extract chunk.
            $chunk = substr($text, $offset, $chunkSize);

            // Try to break at word boundary if not at end.
            if ($offset + $chunkSize < strlen($text)) {
                $lastSpace = strrpos($chunk, ' ');
                if ($lastSpace !== false && $lastSpace > $chunkSize * 0.8) {
                    $chunk = substr($chunk, 0, $lastSpace);
                }
            }

            $chunkLength = strlen($chunk);

            if (strlen(trim($chunk)) >= self::MIN_CHUNK_SIZE) {
                $chunks[] = [
                    'text'         => trim($chunk),
                    'start_offset' => $offset,
                    'end_offset'   => $offset + $chunkLength,
                ];
            }

            $offset += $chunkLength - $chunkOverlap;

            // Prevent infinite loop.
            if ($offset <= 0) {
                $offset = $chunkLength;
            }
        }//end while

        return array_filter(
            $chunks,
            function ($c) {
                $trimmed = trim($c['text']);
                return $trimmed !== '' && $trimmed !== null;
            }
        );

    }//end chunkFixedSize()


    /**
     * Chunk text recursively by trying different separators
     *
     * This method tries to split by:
     * 1. Double newlines (paragraphs)
     * 2. Single newlines (lines)
     * 3. Sentence endings (. ! ?)
     * 4. Clauses (; ,)
     * 5. Words (spaces)
     *
     * @param string $text         Text to chunk
     * @param int    $chunkSize    Target chunk size
     * @param int    $chunkOverlap Overlap size
     *
     * @return array Array of chunk objects with text, start_offset, end_offset
     */
    private function chunkRecursive(string $text, int $chunkSize, int $chunkOverlap): array
    {
        // If text is already small enough, return it.
        if (strlen($text) <= $chunkSize) {
            return [
                [
                    'text'         => $text,
                    'start_offset' => 0,
                    'end_offset'   => strlen($text),
                ],
            ];
        }

        // Define separators in order of preference.
        $separators = [
            "\n\n",
        // Paragraphs.
            "\n",
        // Lines.
            ". ",
        // Sentences.
            "! ",
            "? ",
            "; ",
            ", ",
        // Clauses.
            " ",
        // Words.
        ];

        return $this->recursiveSplit(text: $text, separators: $separators, chunkSize: $chunkSize, chunkOverlap: $chunkOverlap);

    }//end chunkRecursive()


    /**
     * Recursively split text using different separators
     *
     * @param string $text         Text to split
     * @param array  $separators   Array of separators to try
     * @param int    $chunkSize    Target chunk size
     * @param int    $chunkOverlap Overlap size
     *
     * @return (int|mixed|string)[][] Array of chunk objects with text, start_offset, end_offset
     *
     * @psalm-return array<int<0, max>, array{text: mixed|string, start_offset: int|mixed, end_offset: int|mixed}>
     */
    private function recursiveSplit(string $text, array $separators, int $chunkSize, int $chunkOverlap): array
    {
        // If text is small enough, return it.
        if (strlen($text) <= $chunkSize) {
            return [
                [
                    'text'         => $text,
                    'start_offset' => 0,
                    'end_offset'   => strlen($text),
                ],
            ];
        }

        // If no separators left, use fixed size chunking.
        if ($separators === []) {
            return $this->chunkFixedSize(text: $text, chunkSize: $chunkSize, chunkOverlap: $chunkOverlap);
        }

        // Try splitting with current separator.
        $separator = array_shift($separators);
        $splits    = explode($separator, $text);

        // Rebuild chunks.
        $chunks        = [];
        $currentChunk  = '';
        $currentOffset = 0;

        foreach ($splits as $split) {
            if ($currentChunk === '') {
                $testChunk = $split;
            } else {
                $testChunk = $currentChunk.$separator.$split;
            }

            if (strlen($testChunk) <= $chunkSize) {
                // Can add to current chunk.
                $currentChunk = $testChunk;
            } else {
                // Current chunk is full.
                if ($currentChunk !== '') {
                    $chunkLength = strlen($currentChunk);

                    if (strlen(trim($currentChunk)) >= self::MIN_CHUNK_SIZE) {
                        $chunks[] = [
                            'text'         => trim($currentChunk),
                            'start_offset' => $currentOffset,
                            'end_offset'   => $currentOffset + $chunkLength,
                        ];
                    }

                    $currentOffset += $chunkLength;

                    // Add overlap from end of previous chunk.
                    if ($chunkOverlap > 0 && strlen($currentChunk) > $chunkOverlap) {
                        $overlapText    = substr($currentChunk, -$chunkOverlap);
                        $currentChunk   = $overlapText.$separator.$split;
                        $currentOffset -= $chunkOverlap;
                    } else {
                        $currentChunk = $split;
                    }
                } else {
                    // Single split is too large, need to split it further.
                    if (strlen($split) > $chunkSize) {
                        $subChunks = $this->recursiveSplit(text: $split, separators: $separators, chunkSize: $chunkSize, chunkOverlap: $chunkOverlap);

                        // Adjust offsets.
                        foreach ($subChunks as $subChunk) {
                            $chunks[] = [
                                'text'         => $subChunk['text'],
                                'start_offset' => $currentOffset + $subChunk['start_offset'],
                                'end_offset'   => $currentOffset + $subChunk['end_offset'],
                            ];
                        }

                        $currentOffset += strlen($split);
                        $currentChunk   = '';
                    } else {
                        $currentChunk = $split;
                    }
                }//end if
            }//end if
        }//end foreach

        // Don't forget the last chunk.
        if ($currentChunk !== '' && strlen(trim($currentChunk)) >= self::MIN_CHUNK_SIZE) {
            $chunks[] = [
                'text'         => trim($currentChunk),
                'start_offset' => $currentOffset,
                'end_offset'   => $currentOffset + strlen($currentChunk),
            ];
        }

        return array_filter(
            $chunks,
            function ($c) {
                $trimmed = trim($c['text']);
                return $trimmed !== '' && $trimmed !== null;
            }
        );

    }//end recursiveSplit()


    /**
     * Check if MIME type is a Word document
     *
     * @param string $mimeType MIME type to check
     *
     * @return bool True if Word document
     */
    private function isWordDocument(string $mimeType): bool
    {
        $wordTypes = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
        ];

        return in_array($mimeType, $wordTypes, true) === true;

    }//end isWordDocument()


    /**
     * Check if MIME type is a spreadsheet
     *
     * @param string $mimeType MIME type to check
     *
     * @return bool True if spreadsheet
     */
    private function isSpreadsheet(string $mimeType): bool
    {
        $spreadsheetTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
        ];

        return in_array($mimeType, $spreadsheetTypes, true) === true;

    }//end isSpreadsheet()


    /**
     * Get detection method name based on language
     *
     * @param string|null $language Detected language code
     *
     * @return string Detection method name
     *
     * @psalm-return 'heuristic'|'none'
     */
    private function getDetectionMethod(?string $language): string
    {
        if ($language === null) {
            return 'none';
        }

        return 'heuristic';

    }//end getDetectionMethod()


    /**
     * Calculate average chunk size from chunks array
     *
     * @param array $chunks Array of chunk arrays with 'text' key
     *
     * @return float Average chunk size in characters
     */
    private function calculateAvgChunkSize(array $chunks): float
    {
        if ($chunks === []) {
            return 0.0;
        }

        $totalSize = 0;
        foreach ($chunks as $chunk) {
            // Extract text from chunk.
            if (is_array($chunk) === true && (($chunk['text'] ?? null) !== null)) {
                $text = $chunk['text'];
            } else if (is_string($chunk) === true) {
                $text = $chunk;
            } else {
                $text = '';
            }

            $totalSize += strlen($text);
        }

        return round($totalSize / count($chunks), 2);

    }//end calculateAvgChunkSize()


}//end class
