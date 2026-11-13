<?php

/**
 * TextExtractionService
 *
 * This service handles all text extraction logic for files in the system.
 * It consolidates extraction workflows, file tracking, and re-extraction detection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 *
 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use Exception;
use JsonException;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\TextExtraction\SourceText;
use OCA\OpenRegister\Files\Service\TextExtraction\ChunkSource;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\ObjectHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionAnalysis;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionObjects;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionStorage;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionSources;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Service\File\OcrWords;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Symfony\Component\Uid\Uuid;
use Throwable;

// Document parsing libraries.

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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Text extraction requires comprehensive document parsing methods.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex multi-format document extraction logic.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Requires multiple document parsing libraries and mapper types for multi-format extraction.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    Individual extraction methods for PDF/DOCX/XLSX/EML each require format-specific logic.
 * @SuppressWarnings(PHPMD.TooManyMethods)           One private extraction method per supported MIME group
 * (PDF/DOCX/XLSX/EML/text/object/file) plus chunking strategies; splitting into sub-services would
 * break the encapsulated extraction workflow.
 */
class TextExtractionService implements TextExtractionServiceInterface {
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
	 * Maximum number of findUntrackedFiles() windows a single extractPendingFiles()
	 * call will walk while stepping over files that keep failing (WOO-576). Caps
	 * the work done by one cron tick when a large block of files is unreadable.
	 *
	 * @var int
	 */
	private const MAX_PENDING_WINDOWS = 10;

	/**
	 * Minimum chunk size in characters
	 *
	 * @var int
	 */
	private const MIN_CHUNK_SIZE = TextSplitPass::MIN_CHUNK_SIZE;

	/**
	 * Shortest shared run read as chunk overlap when text is stitched back.
	 *
	 * @var integer
	 */
	private const MIN_OVERLAP_MATCH = 16;

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
	 * @param TextExtractionSources  $sources     The file stores and the object mappers.
	 * @param ChunkMapper            $chunkMapper The chunk store, passed on to ObjectHandler.
	 * @param TextExtractionAnalysis $analysis    Entity recognition, settings, risk level and logging.
	 * @param TextExtractionReaders  $readers     The document readers.
	 */
	public function __construct(
		private readonly TextExtractionSources $sources,
		private readonly ChunkMapper $chunkMapper,
		private readonly TextExtractionAnalysis $analysis,
		private readonly TextExtractionReaders $readers,
	) {
	}//end __construct()

	/**
	 * Extract text from a file, chunk it and detect entities, unless its chunks are up to date.
	 *
	 * @param int                   $fileId  The Nextcloud file id.
	 * @param FileExtractionOptions $options Whether to ignore up-to-date chunks and which entity types to detect.
	 *
	 * @throws NotFoundException If the file does not exist.
	 * @throws Exception If the extraction fails.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function extractFile(int $fileId, FileExtractionOptions $options=new FileExtractionOptions()): void {
		$this->analysis->logger->debug(
			message: '[TextExtractionService] Starting file extraction',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId]
		);

		$ncFile = $this->sources->storage->fileMapper->getFile($fileId);
		if ($ncFile === null) {
			throw new NotFoundException("File with ID {$fileId} not found in Nextcloud");
		}

		$sourceTimestamp = (int)($ncFile['mtime'] ?? time());

		// Check if chunks are up-to-date.
		$isUpToDate = $this->isSourceUpToDate(
			stamp: new SourceStamp(sourceType: 'file', sourceId: $fileId, timestamp: $sourceTimestamp),
			forceReExtract: $options->forceReExtract
		);
		if ($options->forceReExtract === false && $isUpToDate === true) {
			// File is up-to-date and all chunks are still valid.
			$this->analysis->logger->debug(
				message: '[TextExtractionService] File already processed and up-to-date',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId]
			);
			return;
		}

		// Extract and sanitize the source text payload (includes language metadata).
		$payload = $this->extractSourceText(
			source: new ExtractionSource(sourceType: 'file', sourceId: $fileId, sourceMeta: $ncFile, method: 'llphant')
		);
		$this->indexFilePayload(text: new SourceText(payload: $payload, sourceTimestamp: $sourceTimestamp), entityTypes: $options->entityTypes);
	}//end extractFile()

	/**
	 * Index text a caller already extracted as the content of a file.
	 *
	 * @param ProvidedTextRequest $request The file, the text, the entity types to detect and the method to record.
	 *
	 * @throws NotFoundException If the file does not exist.
	 * @throws \InvalidArgumentException If the OCR words are not valid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public function extractFromProvidedText(ProvidedTextRequest $request): void {
		$ncFile = $this->sources->storage->fileMapper->getFile($request->fileId);
		if ($ncFile === null) {
			throw new NotFoundException("File with ID {$request->fileId} not found in Nextcloud");
		}

		$payload = $this->payloadFromText(
			source: new ExtractionSource(sourceType: 'file', sourceId: $request->fileId, sourceMeta: $ncFile, method: $request->method),
			rawText: $request->text
		);
		if ($request->words !== null) {
			// Kept on the metadata chunk, beside the text, for the image seam to locate entities by.
			$payload['ocr_words'] = OcrWords::normalise(words: $request->words);
		}

		$text = new SourceText(payload: $payload, sourceTimestamp: (int)($ncFile['mtime'] ?? time()));
		$this->indexFilePayload(text: $text, entityTypes: $request->entityTypes);
	}//end extractFromProvidedText()

	/**
	 * Chunk, store and run entity recognition over a file's text payload
	 *
	 * @param SourceText $text        The file's text payload and mtime.
	 * @param array|null $entityTypes Entity types to detect, or null for all.
	 *
	 * @return void
	 */
	private function indexFilePayload(SourceText $text, ?array $entityTypes): void {
		$fileId = $text->payload['source_id'];
		$chunks = $this->textToChunks(
			payload: $text->payload,
			options: [
				'chunk_size' => self::DEFAULT_CHUNK_SIZE, 'chunk_overlap' => self::DEFAULT_CHUNK_OVERLAP, 'strategy' => self::RECURSIVE_CHARACTER,
			]
		);

		// Persist textual chunks and include the metadata chunk at the end.
		$this->persistChunksForSource(text: $text, chunks: $chunks);

		if ($this->extractFileEntities(fileId: $fileId, entityTypes: $entityTypes) === false) {
			return;
		}

		$this->analysis->logger->debug(
			message: '[TextExtractionService] File extraction complete',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'chunkCount' => count($chunks) + 1]
		);
	}//end indexFilePayload()

	/**
	 * Run entity recognition over a file's stored chunks when it is enabled, then update the
	 * file's risk level. A failure is logged, not thrown.
	 *
	 * @param int        $fileId      The file id.
	 * @param array|null $entityTypes Entity types to detect, or null for all.
	 *
	 * @return bool False when entity recognition is disabled.
	 */
	private function extractFileEntities(int $fileId, ?array $entityTypes): bool {
		// Extract entities from chunks.
		try {
			// Get entity recognition settings.
			$fileSettings = $this->analysis->settingsService->getFileSettingsOnly();
			$entityMethod = $fileSettings['entityRecognitionMethod'] ?? 'hybrid';
			$entityEnabled = $fileSettings['entityRecognitionEnabled'] ?? false;

			if ($entityEnabled === false) {
				$this->analysis->logger->debug(
					message: '[TextExtractionService] Entity recognition disabled, skipping',
					context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId]
				);
				return false;
			}

			$entityOptions = ['method' => $entityMethod, 'confidence_threshold' => 0.5];
			if ($entityTypes !== null) {
				$entityOptions['entity_types'] = array_values($entityTypes);
			}

			$entityResult = $this->analysis->entityHandler->processSourceChunks(
				sourceType: 'file',
				sourceId: $fileId,
				options: $entityOptions
			);

			$this->analysis->logger->debug(
				message: '[TextExtractionService] Entity extraction complete',
				context: [
					'file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'entities_found' => $entityResult['entities_found'],
					'relations_created' => $entityResult['relations_created'],
				]
			);

			// Compute and persist risk level based on detected entities.
			$this->updateFileRiskLevel(fileId: $fileId);
		} catch (Exception $e) {
			$this->analysis->logger->error(
				message: '[TextExtractionService] Entity extraction failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'error' => $e->getMessage()]
			);
		}//end try

		return true;
	}//end extractFileEntities()

	/**
	 * Compute and store a file's risk level from its detected entities; a failure is logged as a warning.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return void
	 */
	private function updateFileRiskLevel(int $fileId): void {
		try {
			$riskLevel = $this->analysis->riskLevelService->updateRiskLevel(fileId: $fileId);
			// Info: a risk level changing is a compliance-relevant state
			// change on the document, not a step in extracting it.
			$this->analysis->logger->info(
				'[TextExtractionService] Risk level updated',
				['fileId' => $fileId, 'riskLevel' => $riskLevel]
			);
		} catch (Exception $e) {
			$this->analysis->logger->warning(
				'[TextExtractionService] Failed to update risk level',
				['fileId' => $fileId, 'error' => $e->getMessage()]
			);
		}
	}//end updateFileRiskLevel()

	/**
	 * The text extracted from a file, read back from its stored chunks.
	 *
	 * Extraction keeps no copy of the whole text, only the chunks, so the text
	 * is stitched back together here. Chunks overlap by design, and the
	 * recursive chunker's offsets do not count the separators it drops, so
	 * the overlap is removed by content rather than by offset: each chunk
	 * contributes what follows the longest stretch its start shares with the
	 * end of the text so far. Whitespace a chunk was trimmed of at a boundary
	 * comes back as a single newline. The metadata chunk is not text and is
	 * left out.
	 *
	 * @param int $fileId Nextcloud file ID.
	 *
	 * @return string|null The extracted text, or null when the file has no text chunks.
	 *
	 * @spec openspec/specs/api-test-coverage/spec.md
	 */
	public function getExtractedText(int $fileId): ?string {
		$text = null;
		foreach ($this->chunkMapper->findBySource(sourceType: 'file', sourceId: $fileId) as $chunk) {
			if ($this->isContentChunk(chunk: $chunk) === true) {
				$text = $this->joinChunk(text: $text, content: $chunk->getTextContent());
			}
		}

		return $text;
	}//end getExtractedText()

	/**
	 * Whether a stored chunk holds file content, not metadata.
	 *
	 * @param Chunk $chunk The chunk.
	 *
	 * @return bool True for a content chunk.
	 */
	private function isContentChunk(Chunk $chunk): bool {
		$position = ($chunk->getPositionReference() ?? []);

		return ($chunk->getChunkIndex() >= 0 && ($position['type'] ?? null) !== 'metadata');
	}//end isContentChunk()

	/**
	 * Add a chunk's content to the text so far, without repeating the overlap they share.
	 *
	 * @param string|null $text    The text so far.
	 * @param string      $content The chunk's content.
	 *
	 * @return string The joined text.
	 */
	private function joinChunk(?string $text, string $content): string {
		if ($text === null) {
			return $content;
		}

		$shared = $this->sharedOverlapLength(before: $text, after: $content);
		if ($shared === 0) {
			return $text . "\n" . $content;
		}

		return $text . substr($content, $shared);
	}//end joinChunk()

	/**
	 * How many leading bytes of the next chunk repeat the end of the text so far.
	 *
	 * A match shorter than {@see self::MIN_OVERLAP_MATCH} is treated as no
	 * overlap: a handful of shared characters is a coincidence, and dropping
	 * them would cut real text.
	 *
	 * @param string $before The text so far.
	 * @param string $after  The next chunk.
	 *
	 * @return int The overlap length in bytes, 0 when there is none.
	 */
	private function sharedOverlapLength(string $before, string $after): int {
		$longest = min(strlen($before), strlen($after));
		for ($length = $longest; $length >= self::MIN_OVERLAP_MATCH; $length--) {
			if (substr($before, -$length) === substr($after, 0, $length)) {
				return $length;
			}
		}

		return 0;
	}//end sharedOverlapLength()

	/**
	 * Extract text from an object by object ID
	 *
	 * This method:
	 * 1. Looks up object in the database
	 * 2. Checks if re-extraction is needed (object modified since last extraction)
	 * 3. Performs extraction if needed using ObjectHandler
	 *
	 * @param int $objectId Object ID
	 * @param bool $forceReExtract Force re-extraction even if object hasn't changed
	 *
	 * @return void
	 *
	 * @throws Exception If extraction fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flag needed for force re-extraction behavior
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function extractObject(int $objectId, bool $forceReExtract = false): void {
		$this->analysis->logger->debug(
			message: '[TextExtractionService] Starting object extraction',
			context: ['file' => __FILE__, 'line' => __LINE__, 'objectId' => $objectId]
		);

		$object = $this->findObjectToExtract(objectId: $objectId);
		if ($object === null) {
			return;
		}

		$sourceTimestamp = $object->getUpdated()?->getTimestamp() ?? time();
		$isUpToDate = $this->isSourceUpToDate(
			stamp: new SourceStamp(sourceType: 'object', sourceId: $objectId, timestamp: $sourceTimestamp),
			forceReExtract: $forceReExtract
		);
		if ($forceReExtract === false && $isUpToDate === true) {
			$this->analysis->logger->debug(
				message: '[TextExtractionService] Object already processed and up-to-date',
				context: ['file' => __FILE__, 'line' => __LINE__, 'objectId' => $objectId]
			);
			return;
		}

		$objectHandler = $this->newObjectHandler();
		$sourceMeta = $objectHandler->getSourceMetadata($objectId);
		$extractedData = $objectHandler->extractText(sourceId: $objectId, sourceMeta: $sourceMeta);
		$payload = $this->objectPayload(objectId: $objectId, extractedData: $extractedData);
		$this->indexObjectPayload(text: new SourceText(payload: $payload, sourceTimestamp: $sourceTimestamp));
	}//end extractObject()

	/**
	 * Load the object to extract, or null when it was deleted after the job was queued.
	 *
	 * @param int $objectId Object ID.
	 *
	 * @return ObjectEntity|null The object, or null when it no longer exists.
	 */
	private function findObjectToExtract(int $objectId): ?ObjectEntity {
		try {
			return $this->sources->objects->objectEntityMapper->find($objectId);
		} catch (DoesNotExistException $e) {
			// Info, deliberately: this explains why queued work did NOT happen
			// — the object was deleted between queueing and extraction. Silence
			// there looks like the job never ran. TextExtractionServiceDeepTest
			// asserts the level, so the intent is recorded rather than assumed.
			$this->analysis->logger->info(
				message: '[TextExtractionService] Object no longer exists, skipping extraction',
				context: ['file' => __FILE__, 'line' => __LINE__, 'objectId' => $objectId]
			);
			return null;
		}
	}//end findObjectToExtract()

	/**
	 * A text handler for objects, over this service's mappers.
	 *
	 * @return ObjectHandler The handler.
	 */
	private function newObjectHandler(): ObjectHandler {
		return new ObjectHandler(
			objects: $this->sources->objects,
			chunkMapper: $this->chunkMapper,
			logger: $this->analysis->logger
		);
	}//end newObjectHandler()

	/**
	 * Build the chunk payload for an object's extracted text: sanitized, with language metadata.
	 *
	 * @param int   $objectId      Object ID.
	 * @param array $extractedData ObjectHandler's extraction result.
	 *
	 * @return array The payload.
	 *
	 * @throws Exception If the sanitized text is empty.
	 */
	private function objectPayload(int $objectId, array $extractedData): array {
		$cleanText = $this->sanitizeText(text: $extractedData['text']);
		if ($cleanText === '') {
			throw new Exception('Text extraction resulted in an empty payload for object.');
		}

		$languageSignals = $this->detectLanguageSignals(text: $cleanText);

		return [
			'source_type' => 'object', 'source_id' => $objectId, 'text' => $cleanText, 'length' => strlen($cleanText),
			'checksum' => hash('sha256', $cleanText), 'method' => 'object_extraction', 'owner' => $extractedData['owner'] ?? null,
			'organisation' => $extractedData['organisation'] ?? null, 'language' => $languageSignals['language'],
			'language_level' => $languageSignals['language_level'], 'language_confidence' => $languageSignals['language_confidence'],
			'detection_method' => $languageSignals['detection_method'], 'metadata' => $extractedData['metadata'] ?? [],
		];
	}//end objectPayload()

	/**
	 * Chunk and store an object's payload, then run entity recognition over the chunks.
	 *
	 * @param SourceText $text The object's text payload and modification time.
	 *
	 * @return void
	 */
	private function indexObjectPayload(SourceText $text): void {
		$objectId = $text->payload['source_id'];
		$chunks = $this->textToChunks(
			payload: $text->payload,
			options: ['source_type' => $text->payload['source_type'], 'source_id' => $objectId]
		);

		$this->persistChunksForSource(text: $text, chunks: $chunks);

		$this->extractObjectEntities(objectId: $objectId);

		$this->analysis->logger->debug(
			message: '[TextExtractionService] Object extraction completed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'objectId' => $objectId, 'chunkCount' => count($chunks) + 1]
		);
	}//end indexObjectPayload()

	/**
	 * Run entity recognition over an object's stored chunks; a failure is logged, not thrown.
	 *
	 * @param int $objectId Object ID.
	 *
	 * @return void
	 */
	private function extractObjectEntities(int $objectId): void {
		try {
			$entityResult = $this->analysis->entityHandler->processSourceChunks(
				sourceType: 'object',
				sourceId: $objectId,
				options: ['method' => 'hybrid', 'confidence_threshold' => 0.5]
			);

			$this->analysis->logger->debug(
				message: '[TextExtractionService] Entity extraction complete',
				context: [
					'file' => __FILE__, 'line' => __LINE__, 'objectId' => $objectId, 'entities_found' => $entityResult['entities_found'],
					'relations_created' => $entityResult['relations_created'],
				]
			);
		} catch (Exception $e) {
			$this->analysis->logger->error(
				message: '[TextExtractionService] Entity extraction failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'objectId' => $objectId, 'error' => $e->getMessage()]
			);
		}
	}//end extractObjectEntities()

	/**
	 * Determine whether the latest chunks already reflect the current source state.
	 *
	 * True when the newest chunk stored for the source is not older than the
	 * source, and the caller did not force a re-extraction.
	 *
	 * @param SourceStamp $stamp          The source and the time it last changed.
	 * @param bool        $forceReExtract Force flag coming from the caller.
	 *
	 * @return bool
	 */
	private function isSourceUpToDate(SourceStamp $stamp, bool $forceReExtract): bool {
		if ($forceReExtract === true) {
			// Caller explicitly asked to ignore cached data.
			return false;
		}

		// Look at the newest chunk timestamp for this source.
		$latestChunkTimestamp = $this->chunkMapper->getLatestUpdatedTimestamp(sourceType: $stamp->sourceType, sourceId: $stamp->sourceId);
		if ($latestChunkTimestamp === null) {
			return false;
		}

		return $latestChunkTimestamp >= $stamp->timestamp;
	}//end isSourceUpToDate()

	/**
	 * Extract and sanitize text for a given source.
	 *
	 * @param ExtractionSource $source Source type, id, raw metadata and extraction method.
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
	private function extractSourceText(ExtractionSource $source): array {
		$rawText = $this->performTextExtraction(fileId: $source->sourceId, ncFile: $source->sourceMeta);
		if ($rawText === null) {
			throw new Exception('Text extraction returned no result for source.');
		}

		return $this->payloadFromText(source: $source, rawText: $rawText);
	}//end extractSourceText()

	/**
	 * Build the source payload from text, however it was obtained
	 *
	 * @param ExtractionSource $source  Source type, id, metadata (file row) and how the text was obtained.
	 * @param string           $rawText The text, before sanitising.
	 *
	 * @return array The payload.
	 *
	 * @throws Exception When the sanitised text is empty.
	 */
	private function payloadFromText(ExtractionSource $source, string $rawText): array {
		$sourceMeta = $source->sourceMeta;
		$cleanText = $this->sanitizeText(text: $rawText);
		if ($cleanText === '') {
			throw new Exception('Text extraction resulted in an empty payload.');
		}

		// Collect lightweight language metadata to enrich chunk storage.
		$languageSignals = $this->detectLanguageSignals(text: $cleanText);

		return [
			'source_type' => $source->sourceType,
			'source_id' => $source->sourceId,
			'text' => $cleanText,
			'length' => strlen($cleanText),
			'checksum' => hash('sha256', $cleanText),
			// Stable checksum to detect text mutations.
			'method' => $source->method,
			'owner' => $sourceMeta['owner'] ?? null,
			'organisation' => $sourceMeta['organisation'] ?? null,
			'language' => $languageSignals['language'],
			'language_level' => $languageSignals['language_level'],
			'language_confidence' => $languageSignals['language_confidence'],
			'detection_method' => $languageSignals['detection_method'],
			'metadata' => [
				'file_path' => $sourceMeta['path'] ?? null, 'file_name' => $sourceMeta['name'] ?? null,
				'mime_type' => $sourceMeta['mimetype'] ?? null, 'file_size' => $sourceMeta['size'] ?? null,
			],
		];
	}//end payloadFromText()

	/**
	 * Lightweight placeholder for language detection.
	 *
	 * @param string $text Input text.
	 *
	 * @return array Language detection result with confidence.
	 */
	private function detectLanguageSignals(string $text): array {
		$language = null;
		$confidence = null;

		// Extremely naive heuristic as a placeholder until dedicated detection is plugged in.
		if (preg_match('/\b(de|het|een)\b/i', $text) === 1) {
			$language = 'nl';
			$confidence = 0.35;
		} elseif (preg_match('/\b(the|and|of)\b/i', $text) === 1) {
			$language = 'en';
			$confidence = 0.35;
		}

		return [
			'language' => $language, 'language_level' => null, 'language_confidence' => $confidence,
			'detection_method' => $this->getDetectionMethod(language: $language),
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
	private function textToChunks(array $payload, array $options = []): array {
		$chunkSize = (int)($options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE);
		$chunkOverlap = (int)($options['chunk_overlap'] ?? self::DEFAULT_CHUNK_OVERLAP);
		$strategy = (string)($options['strategy'] ?? self::RECURSIVE_CHARACTER);

		// Generate the low-level chunks.
		$rawChunks = $this->chunkDocument(
			text: $payload['text'],
			options: ['chunk_size' => $chunkSize, 'chunk_overlap' => $chunkOverlap, 'strategy' => $strategy]
		);

		$mappedChunks = [];

		foreach (array_values($rawChunks) as $index => $chunk) {
			// Translate chunk metadata to a persistence-friendly structure.
			$mappedChunks[] = [
				'chunk_index' => $index, 'text_content' => $chunk['text'], 'start_offset' => $chunk['start_offset'] ?? 0,
				'end_offset' => $chunk['end_offset'] ?? strlen($chunk['text']), 'language' => $payload['language'] ?? null,
				'language_level' => $payload['language_level'] ?? null, 'language_confidence' => $payload['language_confidence'] ?? null,
				'detection_method' => $payload['detection_method'] ?? null, 'overlap_size' => $chunkOverlap,
				'position_reference' => $this->buildPositionReference(sourceType: $payload['source_type'], chunk: $chunk),
				'checksum' => $payload['checksum'] ?? null,
			];
		}

		return $mappedChunks;
	}//end textToChunks()

	/**
	 * Build a structured position reference for traceability.
	 *
	 * @param string $sourceType Source type identifier.
	 * @param array<string,mixed> $chunk Chunk metadata from chunkDocument.
	 *
	 * @phpstan-param non-empty-string $sourceType
	 *
	 * @psalm-param non-empty-string $sourceType
	 *
	 * @return (int|mixed|null|string)[]
	 *
	 * @psalm-return array{type: 'property-path'|'text-range', start?: 0|mixed, end?: 0|mixed, path?: mixed|null}
	 */
	private function buildPositionReference(string $sourceType, array $chunk): array {
		if ($sourceType === 'object') {
			return ['type' => 'property-path', 'path' => $chunk['property_path'] ?? null];
		}

		return ['type' => 'text-range', 'start' => $chunk['start_offset'] ?? 0, 'end' => $chunk['end_offset'] ?? 0];
	}//end buildPositionReference()

	/**
	 * Persist textual chunks for a source.
	 *
	 * @param SourceText                     $text   The source's text payload (also the metadata chunk's) and timestamp.
	 * @param array<int,array<string,mixed>> $chunks Chunk payloads.
	 *
	 * @return void
	 */
	private function persistChunksForSource(SourceText $text, array $chunks): void {
		$source = $text->chunkSource();
		$this->sources->storage->db->beginTransaction();

		try {
			// Remove all existing chunks for this source to avoid stale data.
			$this->chunkMapper->deleteBySource(sourceType: $source->sourceType, sourceId: $source->sourceId);

			foreach ($chunks as $chunkData) {
				$chunkEntity = $this->hydrateChunkEntity(source: $source, chunkData: $chunkData);

				$this->chunkMapper->insert($chunkEntity);
			}

			$this->persistMetadataChunk(source: $source, payload: $text->payload);

			$this->sources->storage->db->commit();
		} catch (Throwable $throwable) {
			$this->sources->storage->db->rollBack();
			throw $throwable;
		}//end try
	}//end persistChunksForSource()

	/**
	 * Create a Chunk entity from an array payload.
	 *
	 * @param ChunkSource $source The source the chunk belongs to.
	 * @param array<string,mixed> $chunkData Chunk payload.
	 *
	 * @return Chunk
	 */
	private function hydrateChunkEntity(ChunkSource $source, array $chunkData): Chunk {
		$chunk = new Chunk();
		$chunk->setUuid(Uuid::v4()->toRfc4122());
		$chunk->setSourceType($source->sourceType);
		$chunk->setSourceId($source->sourceId);
		$chunk->setChunkIndex((int)($chunkData['chunk_index'] ?? 0));
		$chunk->setTextContent((string)$chunkData['text_content']);
		$chunk->setStartOffset((int)($chunkData['start_offset'] ?? 0));
		$chunk->setEndOffset((int)($chunkData['end_offset'] ?? strlen($chunkData['text_content'])));
		$chunk->setPositionReference($chunkData['position_reference'] ?? null);
		$chunk->setLanguage($chunkData['language'] ?? null);
		$chunk->setLanguageLevel($chunkData['language_level'] ?? null);
		$chunk->setLanguageConfidence($chunkData['language_confidence'] ?? null);
		$chunk->setDetectionMethod($chunkData['detection_method'] ?? null);
		$chunk->setIndexed(false);
		$chunk->setVectorized(false);
		$chunk->setEmbeddingProvider($chunkData['embedding_provider'] ?? null);
		$chunk->setOverlapSize((int)($chunkData['overlap_size'] ?? 0));
		$chunk->setOwner($source->owner);
		$chunk->setOrganisation($source->organisation);
		$chunk->setChecksum($chunkData['checksum'] ?? null);

		$createdAt = (new DateTime())->setTimestamp($source->sourceTimestamp);
		$chunk->setCreatedAt($createdAt);
		$chunk->setUpdatedAt(new DateTime());

		return $chunk;
	}//end hydrateChunkEntity()

	/**
	 * Persist the metadata chunk that stores provenance details.
	 *
	 * @param ChunkSource         $source  The source, owned as the payload says.
	 * @param array<string,mixed> $payload Extraction payload.
	 *
	 * @return void
	 */
	private function persistMetadataChunk(ChunkSource $source, array $payload): void {
		try {
			$metadataText = json_encode(
				$this->summarizeMetadataPayload(payload: $payload),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
			);
		} catch (JsonException $exception) {
			$this->analysis->logger->warning(
				message: '[TextExtractionService] Failed to encode metadata chunk payload',
				context: [
					'file' => __FILE__, 'line' => __LINE__, 'sourceType' => $source->sourceType, 'sourceId' => $source->sourceId,
					'error' => $exception->getMessage(),
				]
			);
			$metadataText = 'metadata_encoding_failed';
		}

		$chunkData = [
			'chunk_index' => -1, 'text_content' => $metadataText, 'start_offset' => 0, 'end_offset' => strlen($metadataText), 'language' => null,
			'language_level' => null, 'language_confidence' => null, 'detection_method' => null, 'overlap_size' => 0,
			'position_reference' => $this->metadataPositionReference(payload: $payload), 'checksum' => $payload['checksum'] ?? null,
		];

		$chunkEntity = $this->hydrateChunkEntity(source: $source, chunkData: $chunkData);

		$this->chunkMapper->insert($chunkEntity);
	}//end persistMetadataChunk()

	/**
	 * The metadata chunk's position reference, with the OCR words when the provider gave them.
	 *
	 * @param array<string,mixed> $payload Extraction payload.
	 *
	 * @return array<string,mixed> The position reference.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	private function metadataPositionReference(array $payload): array {
		$reference = ['type' => 'metadata'];
		if (isset($payload['ocr_words']) === true) {
			$reference['ocrWords'] = $payload['ocr_words'];
		}

		return $reference;
	}//end metadataPositionReference()

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
	 * organisation: mixed|null,
	 *     owner: mixed|null,
	 *     file_metadata: array<never, never>|mixed
	 * }
	 */
	private function summarizeMetadataPayload(array $payload): array {
		return [
			'source_type' => $payload['source_type'] ?? null,
			'source_id' => $payload['source_id'] ?? null,
			'chunk_checksum' => $payload['checksum'] ?? null,
			'text_length' => $payload['length'] ?? null,
			'language' => $payload['language'] ?? null,
			'language_level' => $payload['language_level'] ?? null,
			'organisation' => $payload['organisation'] ?? null,
			'owner' => $payload['owner'] ?? null,
			// How the text was obtained: `llphant` when this service read the
			// file, or what the caller named, such as `ocr` (#2033).
			'extraction_method' => $payload['method'] ?? null,
			'file_metadata' => $payload['metadata'] ?? [],
		];
	}//end summarizeMetadataPayload()

	/**
	 * Perform actual text extraction from a file
	 *
	 * This method handles the actual text extraction from files based on their type.
	 * Currently supports simple text-based files. Will be extended to support
	 * PDF, DOCX, and other formats via LLPhant or Dolphin extractors.
	 *
	 * @param int $fileId Nextcloud file ID
	 * @param array $ncFile Nextcloud file metadata
	 *
	 * @return string|null Extracted text content, or null if extraction not possible
	 *
	 * @throws Exception If file cannot be read
	 *
	 */
	private function performTextExtraction(int $fileId, array $ncFile): ?string {
		$mimeType = $ncFile['mimetype'] ?? '';
		$context = ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'mimeType' => $mimeType];
		$this->analysis->logger->debug(message: '[TextExtractionService] Attempting extraction', context: $context + ['filePath' => $ncFile['path'] ?? '']);

		try {
			// Resolve the node with an explicit filesystem context; see resolveFileNode().
			// Deliberately not $ncFile['owner']: getFile() falls back to the whole
			// storage id when it is not a user home, so object storage, group folders
			// and external storages arrive here as "object::user:bob" or "local::/mnt".
			$file = $this->resolveFileNode(fileId: $fileId, owner: $this->homeStorageOwner(storageId: $ncFile['storage_id'] ?? null));
			$text = $this->textOfType(file: $file, mimeType: $mimeType);
		} catch (Exception $e) {
			$this->analysis->logger->error(message: '[TextExtractionService] Failed to read file', context: $context + ['error' => $e->getMessage()]);
			throw $e;
		}

		if ($text === null) {
			$this->analysis->logger->debug(message: '[TextExtractionService] Unsupported file type', context: $context);
		}

		return $text;
	}//end performTextExtraction()

	/**
	 * The text of a file, read the way its mime type asks.
	 *
	 * @param \OCP\Files\File $file     The file.
	 * @param string          $mimeType Its mime type.
	 *
	 * @return string|null The text, or null when the type has no reader or the reader finds none.
	 */
	private function textOfType(\OCP\Files\File $file, string $mimeType): ?string {
		return match (true) {
			$this->isPlainText(mimeType: $mimeType) => $file->getContent(),
			$mimeType === 'application/pdf' => $this->extractPdf(file: $file),
			$this->isWordDocument(mimeType: $mimeType) => $this->extractWord(file: $file),
			$this->isSpreadsheet(mimeType: $mimeType) => $this->extractSpreadsheet(file: $file),
			$mimeType === 'message/rfc822' => $this->extractEml(file: $file),
			default => null,
		};
	}//end textOfType()

	/**
	 * Whether a file of this mime type can be read directly as text.
	 *
	 * @param string $mimeType The mime type.
	 *
	 * @return bool True for a text-based type.
	 */
	private function isPlainText(string $mimeType): bool {
		$textTypes = ['application/json', 'application/xml', 'application/x-yaml'];

		return (str_starts_with($mimeType, 'text/') === true || in_array($mimeType, $textTypes, true) === true);
	}//end isPlainText()

	/**
	 * Return the user id a storage id names, but only for a user home storage.
	 *
	 * `FileMapper::getFile()` exposes an `owner` field that falls back to the whole
	 * storage id when it does not start with `home::`, because that field is also a
	 * display value. Passing that fallback to `resolveFileNode()` would hand
	 * `getUserFolder()` a string that can never be a user id — "object::user:bob" on
	 * an instance with primary object storage, "local::/mnt/..." for external
	 * storage, or a group folder id. `getUserFolder()` then throws
	 * NotPermittedException ("Backends provided no user object"), which is caught
	 * and logged at warning level for every file on every run before falling
	 * through to the plain lookup it would have used anyway.
	 *
	 * Returning null for those storages skips the attempt that cannot succeed and
	 * keeps the log free of a warning per file per run.
	 *
	 * @param string|null $storageId Storage id from the filecache row.
	 *
	 * @return string|null The user id, or null when the storage is not a user home.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	private function homeStorageOwner(?string $storageId): ?string {
		$owner = substr((string)$storageId, 6);
		if ($owner === '' || str_starts_with((string)$storageId, 'home::') === false) {
			return null;
		}

		return $owner;
	}//end homeStorageOwner()

	/**
	 * Resolve a file node, setting up the owner's filesystem first.
	 *
	 * `IRootFolder::getById()` only sees mounts that are already set up. In a
	 * background job or a cron run there is no logged-in user, so no user mounts
	 * exist and the lookup returns an empty array; in request context it only
	 * sees the mounts of the *calling* user, so a file owned by somebody else is
	 * invisible too. Both cases surface as "File not found in Nextcloud file
	 * system" and leave the source without chunks (WOO-576).
	 *
	 * Nextcloud 34 added a fallback in `Root::getByIdInPath()` that loads mounts
	 * from the mount cache and, lacking a filesystem user, takes "the user from
	 * the first mount info" — which is why this never reproduced on 34 or newer.
	 * On Nextcloud 33 and below there is no such fallback, so the context has to
	 * be established here.
	 *
	 * `getUserFolder()` sets up the user's filesystem as a side effect, which is
	 * exactly what is missing. The plain `getById()` remains as a fallback so a
	 * file whose owner cannot be determined (an unusual storage id, a group
	 * folder) behaves as before rather than regressing.
	 *
	 * @param int         $fileId Nextcloud file ID.
	 * @param string|null $owner  Owner user id, or null when the file does not live
	 *                            on a user home storage. See homeStorageOwner().
	 *
	 * @return \OCP\Files\File The resolved file node.
	 *
	 * @throws Exception When the file cannot be found, or is not a file.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	private function resolveFileNode(int $fileId, ?string $owner): \OCP\Files\File {
		$nodes = $this->nodesInOwnerFolder(fileId: $fileId, owner: $owner);
		if (empty($nodes) === true) {
			// No owner, or the owner's folder did not hold the file.
			$nodes = $this->sources->storage->rootFolder->getById($fileId);
		}

		if (empty($nodes) === true) {
			throw new Exception('File not found in Nextcloud file system');
		}

		$file = reset($nodes);
		if ($file instanceof \OCP\Files\File === false) {
			throw new Exception('Node is not a file');
		}

		return $file;
	}//end resolveFileNode()

	/**
	 * The nodes with this id in the owner's folder, setting up the owner's mounts on the way.
	 *
	 * @param int         $fileId Nextcloud file ID.
	 * @param string|null $owner  Owner user id, or null when there is none.
	 *
	 * @return array<int, \OCP\Files\Node> The nodes; none without an owner, or when the owner cannot be set up.
	 */
	private function nodesInOwnerFolder(int $fileId, ?string $owner): array {
		if ($owner === null || $owner === '') {
			return [];
		}

		try {
			// Sets up the user's mounts as a side effect: the whole point.
			return $this->sources->storage->rootFolder->getUserFolder($owner)->getById($fileId);
		} catch (Throwable $e) {
			// An unknown or disabled user must not abort the extraction; the root lookup follows.
			$this->analysis->logger->warning(
				message: '[TextExtractionService] Could not set up filesystem for owner',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'owner' => $owner, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end nodesInOwnerFolder()

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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function discoverUntrackedFiles(int $limit = 100): array {
		$this->analysis->logger->debug(
			message: '[TextExtractionService] Discovering untracked files',
			context: ['file' => __FILE__, 'line' => __LINE__, 'limit' => $limit]
		);

		try {
			// Get untracked files from Nextcloud (files without chunks).
			return $this->discoverFiles(untrackedFiles: $this->sources->storage->fileMapper->findUntrackedFiles($limit));
		} catch (Exception $e) {
			$this->analysis->logger->error(
				message: '[TextExtractionService] Discovery failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return ['discovered' => 0, 'failed' => 0, 'total' => 0, 'error' => $e->getMessage()];
		}//end try
	}//end discoverUntrackedFiles()

	/**
	 * Extract each untracked file, counting the ones discovered and the ones that failed.
	 *
	 * @param array $untrackedFiles The untracked file rows (fileid, path).
	 *
	 * @return array{discovered: int, failed: int, total: int} Statistics about discovery.
	 */
	private function discoverFiles(array $untrackedFiles): array {
		$discovered = 0;
		$failed     = 0;
		foreach ($untrackedFiles as $ncFile) {
			if ($this->discoverFile(ncFile: $ncFile) === false) {
				$failed++;
				continue;
			}

			$discovered++;
		}

		$this->analysis->logger->debug(
			message: '[TextExtractionService] Discovery complete',
			context: ['file' => __FILE__, 'line' => __LINE__, 'discovered' => $discovered, 'failed' => $failed]
		);
		return ['discovered' => $discovered, 'failed' => $failed, 'total' => count($untrackedFiles)];
	}//end discoverFiles()

	/**
	 * Extract one untracked file, logging it once it is discovered.
	 *
	 * @param array $ncFile The file row (fileid, path).
	 *
	 * @return bool True when the file was extracted.
	 */
	private function discoverFile(array $ncFile): bool {
		// Extract file directly - chunks will be created.
		if ($this->extractListedFile(ncFile: $ncFile, force: false) === false) {
			return false;
		}

		$this->analysis->logger->debug(
			message: '[TextExtractionService] Discovered and extracted untracked file',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $ncFile['fileid'], 'path' => $ncFile['path'] ?? 'unknown']
		);
		return true;
	}//end discoverFile()

	/**
	 * Extract one listed file, logging the failure instead of throwing it: a
	 * forced re-extraction is a retry, any other a first extraction.
	 *
	 * @param array $ncFile The file row (fileid).
	 * @param bool  $force  Whether to re-extract a file that already has chunks (a retry).
	 *
	 * @return bool True when the file was extracted.
	 */
	private function extractListedFile(array $ncFile, bool $force): bool {
		$failure = '[TextExtractionService] Failed to extract file';
		if ($force === true) {
			$failure = '[TextExtractionService] Retry failed for file';
		}

		try {
			$this->extractFile(fileId: $ncFile['fileid'], options: new FileExtractionOptions(forceReExtract: $force));
			return true;
		} catch (Exception $e) {
			$this->analysis->logger->error(
				message: $failure,
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $ncFile['fileid'] ?? 'unknown', 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end extractListedFile()

	/**
	 * Extract text from files that don't have chunks yet
	 *
	 * This processes files that haven't been extracted yet.
	 * Use discoverUntrackedFiles() first to discover new files.
	 *
	 * @param int $limit Maximum number of files to process
	 *
	 * @return int[] Statistics about the extraction process: {processed, failed, total, truncated}
	 *
	 * @psalm-return array{processed: int<0, max>, failed: int<0, max>, total: int<0, max>, truncated: bool}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function extractPendingFiles(int $limit = 100): array {
		$this->analysis->logger->debug(
			message: '[TextExtractionService] Extracting files without chunks',
			context: ['file' => __FILE__, 'line' => __LINE__, 'limit' => $limit]
		);

		$walk = new PendingExtractionWalk(limit: $limit);
		while ($walk->hasBudget() === true && $walk->windows < self::MAX_PENDING_WINDOWS) {
			if ($this->extractPendingWindow(walk: $walk) === false) {
				break;
			}
		}

		$this->analysis->logger->debug(
			message: '[TextExtractionService] Extraction complete',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'processed' => $walk->processed, 'failed' => $walk->failed,
				'foundPending' => $walk->lastWindowSize,
			]
		);

		return $walk->result(maxWindows: self::MAX_PENDING_WINDOWS);
	}//end extractPendingFiles()

	/**
	 * Fetch the next window of pending files and extract them within the walk's budget.
	 *
	 * A file that fails keeps matching findUntrackedFiles(): nothing records the failure,
	 * and the query orders by fileid ASC with a fixed window. So permanently unreadable
	 * files with low fileids would sit at the head of every window forever and the backfill
	 * would never reach the files behind them. Successful files drop out of the query by
	 * themselves once they have chunks, so stepping the offset past the failures of this
	 * window is enough to move on.
	 *
	 * @param PendingExtractionWalk $walk The walk.
	 *
	 * @return bool Whether another window may hold pending files.
	 */
	private function extractPendingWindow(PendingExtractionWalk $walk): bool {
		$untrackedFiles = $this->sources->storage->fileMapper->findUntrackedFiles(limit: $walk->limit, offset: $walk->offset);
		$walk->windows++;
		$walk->lastWindowSize = count($untrackedFiles);

		if (empty($untrackedFiles) === true) {
			return false;
		}

		$this->analysis->logger->debug(
			message: '[TextExtractionService] Found files without chunks',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'count' => count($untrackedFiles), 'limit' => $walk->limit, 'offset' => $walk->offset,
				'window' => $walk->windows,
			]
		);

		$walk->seen += count($untrackedFiles);
		$walk->failedInWindow = 0;
		foreach ($untrackedFiles as $ncFile) {
			if ($walk->hasBudget() === false) {
				break;
			}

			$this->extractPendingFile(ncFile: $ncFile, walk: $walk);
		}

		// A shorter window than asked for is the end of the pool.
		if (count($untrackedFiles) < $walk->limit) {
			return false;
		}

		// Step over exactly the files that will still be at the head next time.
		$walk->offset += $walk->failedInWindow;
		return true;
	}//end extractPendingWindow()

	/**
	 * Extract one pending file and count the outcome on the walk.
	 *
	 * A row without a usable fileid is skipped. `fc.fileid` is a NOT NULL primary key, so
	 * this guard should not fire.
	 *
	 * @param array                 $ncFile The file row.
	 * @param PendingExtractionWalk $walk   The walk.
	 *
	 * @return void
	 */
	private function extractPendingFile(array $ncFile, PendingExtractionWalk $walk): void {
		$fileId = (int) ($ncFile['fileid'] ?? 0);
		if ($fileId === 0) {
			return;
		}

		try {
			$this->analysis->logger->debug(
				message: '[TextExtractionService] Processing file',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $ncFile['fileid'], 'fileName' => $ncFile['name'] ?? 'unknown']
			);
			$this->extractFile(fileId: $fileId);
			$walk->processed++;
		} catch (Exception $e) {
			$walk->failed++;
			$walk->failedInWindow++;
			$this->analysis->logger->error(
				message: '[TextExtractionService] Failed to extract file',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $ncFile['fileid'] ?? 'unknown', 'error' => $e->getMessage()]
			);
		}//end try
	}//end extractPendingFile()

	/**
	 * Retry file extractions by forcing re-extraction
	 *
	 * @param int $limit Maximum number of files to retry
	 *
	 * @return int[] Statistics about the retry process
	 *
	 * @psalm-return array{retried: int<0, max>, failed: int<0, max>, total: int<0, max>}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function retryFailedExtractions(int $limit = 50): array {
		$this->analysis->logger->debug(
			message: '[TextExtractionService] Retrying extractions',
			context: ['file' => __FILE__, 'line' => __LINE__, 'limit' => $limit]
		);

		// Get files without chunks or with old chunks.
		$untrackedFiles = $this->sources->storage->fileMapper->findUntrackedFiles($limit);
		$retried        = 0;
		foreach ($untrackedFiles as $ncFile) {
			if ($this->extractListedFile(ncFile: $ncFile, force: true) === true) {
				$retried++;
			}
		}

		return ['retried' => $retried, 'failed' => count($untrackedFiles) - $retried, 'total' => count($untrackedFiles)];
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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getStats(): array {
		$untrackedCount = $this->sources->storage->fileMapper->countUntrackedFiles();
		$chunkCount = $this->getTableCountSafe(tableName: 'openregister_chunks');
		$entityCount = $this->getTableCountSafe(tableName: 'openregister_entities');

		return [
			'totalFiles' => $untrackedCount + $chunkCount, 'untrackedFiles' => $untrackedCount, 'totalChunks' => $chunkCount, 'totalObjects' => 0,
			'totalEntities' => $entityCount,
		];
	}//end getStats()

	/**
	 * Safely count rows in a table (returns zero if table is missing)
	 *
	 * @param string $tableName Table name without prefix
	 *
	 * @return int
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $qb is a well-known NC/Doctrine idiom for a QueryBuilder instance.
	 */
	private function getTableCountSafe(string $tableName): int {
		try {
			$qb = $this->sources->storage->db->getQueryBuilder();
			$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
				->from($tableName);

			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();

			return $count;
		} catch (Throwable $e) {
			$this->analysis->logger->debug(
				message: '[TextExtractionService] Unable to count table',
				context: ['file' => __FILE__, 'line' => __LINE__, 'table' => $tableName, 'error' => $e->getMessage()]
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
	private function sanitizeText(string $text): string {
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
	private function extractPdf(\OCP\Files\File $file): ?string {
		// Delegated to the dedicated PdfExtractor handler
		// (extract-god-class-services) — behaviour unchanged.
		return $this->readers->pdfExtractor->extract(file: $file);
	}//end extractPdf()

	/**
	 * Extract text from a Word-family document (DOCX/ODT) through WordExtractor
	 *
	 * Reads the body, tables (nested too), headers, footers and notes. Legacy
	 * binary .doc and per-document failures give null rather than throwing,
	 * so a single unreadable file does not abort a batch.
	 *
	 * @param \OCP\Files\File $file Nextcloud file object
	 *
	 * @return string|null Extracted text content, or null on empty/failed extraction
	 */
	private function extractWord(\OCP\Files\File $file): ?string {
		// Delegated to the dedicated WordExtractor handler
		// (extract-god-class-services) — behaviour unchanged.
		return $this->readers->wordExtractor->extract(file: $file);
	}//end extractWord()

	/**
	 * Extract text from spreadsheet (XLSX/XLS) using PhpSpreadsheet
	 *
	 * @param \OCP\Files\File $file Nextcloud file object
	 *
	 * @return string|null Extracted text content
	 *
	 * @throws Exception If spreadsheet parsing fails
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          IOFactory::load is standard PhpSpreadsheet pattern
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex multi-sheet and cell iteration
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive spreadsheet extraction logic
	 */
	private function extractSpreadsheet(\OCP\Files\File $file): ?string {
		// Delegated to the dedicated SpreadsheetExtractor handler
		// (extract-god-class-services) — behaviour unchanged.
		return $this->readers->spreadsheetExtractor->extract(file: $file);
	}//end extractSpreadsheet()

	/**
	 * Extract flat plain-text from an EML (`message/rfc822`) file.
	 *
	 * Follows the existing extraction-failure pattern — returns null on
	 * irrecoverable parse error, with a PII-sanitised log entry. Per
	 * the `text-extraction-eml` change.
	 *
	 * @param \OCP\Files\File $file The EML file.
	 *
	 * @return string|null Flat plain-text, or null when the file cannot be parsed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) EmlParser::sanitisePiiForLogging is a stateless utility;
	 * making it non-static would not improve testability or DI.
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	private function extractEml(\OCP\Files\File $file): ?string {
		try {
			$structure = $this->readers->emlParser->parse(file: $file);
			return $this->readers->emlParser->flatten(structure: $structure);
		} catch (\OCA\OpenRegister\Exception\EmlParseException $e) {
			$sanitised = \OCA\OpenRegister\Files\Service\TextExtraction\EmlParser::sanitisePiiForLogging(message: $e->getMessage());
			$this->analysis->logger->error(
				message: '[TextExtractionService] EML parse failed: ' . $sanitised,
				context: [
					'file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'mimeType' => 'message/rfc822', 'exception' => get_class($e),
				]
			);
			return null;
		}//end try
	}//end extractEml()

	/**
	 * Public structured-parse entry point for EML files.
	 *
	 * Used by cross-app consumers (e.g. DocuDesk's `eml-pdf-assembly`)
	 * that need access to headers + body + attachments as structured
	 * data rather than as flat plain-text. MUST throw `EmlParseException`
	 * on irrecoverable malformed input — consumers drive their fallback
	 * paths via exception propagation; see the `text-extraction-eml`
	 * spec ("`parseEmlStructured` MUST throw a typed exception").
	 *
	 * @param \OCP\Files\File $file The EML file.
	 *
	 * @return \OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure
	 *
	 * @throws \OCA\OpenRegister\Exception\EmlParseException
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	public function parseEmlStructured(\OCP\Files\File $file): \OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure {
		return $this->readers->emlParser->parse(file: $file);
	}//end parseEmlStructured()

	/**
	 * Chunk a document into smaller pieces for processing
	 *
	 * This method splits text into manageable chunks with optional overlap.
	 * Supports multiple chunking strategies.
	 *
	 * @param string $text The text to chunk
	 * @param array $options Chunking options (chunk_size, chunk_overlap, strategy)
	 *
	 * @return (int|mixed|string)[][] Array of text chunks
	 *
	 * @psalm-return array<int<0, max>, array{text: mixed|string, start_offset: int|mixed, end_offset: int|mixed}>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function chunkDocument(string $text, array $options = []): array {
		$chunkSize    = $options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE;
		$chunkOverlap = $options['chunk_overlap'] ?? self::DEFAULT_CHUNK_OVERLAP;
		$strategy     = $options['strategy'] ?? self::RECURSIVE_CHARACTER;

		$this->analysis->logger->debug(
			message: '[TextExtractionService] Chunking document',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'text_length' => strlen($text), 'chunk_size' => $chunkSize, 'chunk_overlap' => $chunkOverlap,
				'strategy' => $strategy,
			]
		);

		$startTime = microtime(true);

		// Clean the text first, then split it with the chosen strategy and respect the max chunks limit.
		$chunks = $this->splitText(
			text: $this->cleanText(text: $text),
			plan: new ChunkingPlan(strategy: $strategy, sizing: new ChunkSizing(size: $chunkSize, overlap: $chunkOverlap))
		);
		$chunks = $this->capChunks(chunks: $chunks);

		$this->logChunked(chunks: $chunks, startTime: $startTime);
		return $chunks;
	}//end chunkDocument()

	/**
	 * Split cleaned text with a chunking strategy; any strategy but FIXED_SIZE splits recursively.
	 *
	 * Empty text has no chunks.
	 *
	 * @param string       $text The cleaned text.
	 * @param ChunkingPlan $plan The chunking strategy and the target chunk sizing.
	 *
	 * @return array The chunks.
	 */
	private function splitText(string $text, ChunkingPlan $plan): array {
		if ($text === '') {
			return [];
		}

		if ($plan->strategy === self::FIXED_SIZE) {
			return $this->chunkFixedSize(text: $text, sizing: $plan->sizing);
		}

		return $this->chunkRecursive(text: $text, sizing: $plan->sizing);
	}//end splitText()

	/**
	 * Keep at most MAX_CHUNKS_PER_FILE chunks, warning when a file has more.
	 *
	 * @param array $chunks The chunks.
	 *
	 * @return array The chunks, truncated to the limit.
	 */
	private function capChunks(array $chunks): array {
		if (count($chunks) <= self::MAX_CHUNKS_PER_FILE) {
			return $chunks;
		}

		$this->analysis->logger->warning(
			message: '[TextExtractionService] File exceeds max chunks, truncating',
			context: ['file' => __FILE__, 'line' => __LINE__, 'chunks' => count($chunks), 'max' => self::MAX_CHUNKS_PER_FILE]
		);
		return array_slice($chunks, 0, self::MAX_CHUNKS_PER_FILE);
	}//end capChunks()

	/**
	 * Log how many chunks a document gave and how long chunking took.
	 *
	 * @param array $chunks    The chunks.
	 * @param float $startTime When chunking started (microtime).
	 *
	 * @return void
	 */
	private function logChunked(array $chunks, float $startTime): void {
		$chunkingTime = round((microtime(true) - $startTime) * 1000, 2);

		$this->analysis->logger->debug(
			message: '[TextExtractionService] Document chunked successfully',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'chunk_count' => count($chunks), 'chunking_time_ms' => $chunkingTime,
				'avg_chunk_size' => $this->calculateAvgChunkSize(chunks: $chunks),
			]
		);
	}//end logChunked()

	/**
	 * Clean text by removing excessive whitespace and normalizing
	 *
	 * @param string $text Text to clean
	 *
	 * @return string Cleaned text
	 */
	private function cleanText(string $text): string {
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
	 * @param string      $text   Text to chunk
	 * @param ChunkSizing $sizing Target chunk size and overlap size
	 *
	 * @return (int|string)[][] Array of chunk objects with text, start_offset, end_offset
	 *
	 * @psalm-return array<int<0, max>, array{text: string, start_offset: int<0, max>, end_offset: int<0, max>}>
	 */
	private function chunkFixedSize(string $text, ChunkSizing $sizing): array {
		$chunkSize = $sizing->size;
		$chunkOverlap = $sizing->overlap;
		if (strlen($text) <= $chunkSize) {
			return $this->wholeTextChunk(text: $text);
		}

		$chunks = [];
		$offset = 0;

		while ($offset < strlen($text)) {
			$chunk = $this->fixedWindow(text: substr($text, $offset, ($chunkSize + 1)), chunkSize: $chunkSize);
			if (strlen(trim($chunk)) >= self::MIN_CHUNK_SIZE) {
				$chunks[] = $this->trimmedChunk(chunk: $chunk, offset: $offset);
			}

			// If chunk cannot advance past overlap (e.g. final sliver of text),
			// stop: the remainder is already captured above or too small to emit.
			// Prevents an infinite loop on a tail no longer than $chunkOverlap.
			$advance = (strlen($chunk) - $chunkOverlap);
			if ($advance < 1) {
				break;
			}

			$offset += $advance;
		}//end while

		return $chunks;
	}//end chunkFixedSize()

	/**
	 * The one chunk of a text that fits a chunk.
	 *
	 * @param string $text The text.
	 *
	 * @return list<array{text: string, start_offset: int, end_offset: int}> The chunk.
	 */
	private function wholeTextChunk(string $text): array {
		return [['text' => $text, 'start_offset' => 0, 'end_offset' => strlen($text)]];
	}//end wholeTextChunk()

	/**
	 * The next fixed-size window, broken at a word boundary when one sits near its end.
	 *
	 * @param string $text      The text from the window's start, one character past the window when more follows.
	 * @param int    $chunkSize Target chunk size.
	 *
	 * @return string The window.
	 */
	private function fixedWindow(string $text, int $chunkSize): string {
		if (strlen($text) <= $chunkSize) {
			return $text;
		}

		$chunk = substr($text, 0, $chunkSize);
		$lastSpace = strrpos($chunk, ' ');
		if ($lastSpace !== false && $lastSpace > $chunkSize * 0.8) {
			return substr($chunk, 0, $lastSpace);
		}

		return $chunk;
	}//end fixedWindow()

	/**
	 * A chunk with its offsets aligned to the trimmed text that is stored.
	 *
	 * Trimming drops leading whitespace, so the first character of `text_content`
	 * sits that many bytes after the window start. Persisting the raw offset would
	 * make every regex offset computed against `text_content` (and added to
	 * start_offset by ChunkTextMatcher) wrong by that amount, and two overlapping
	 * chunks would derive DIFFERENT absolute positions for the same occurrence,
	 * defeating absolute-position dedup (design D2).
	 *
	 * @param string $chunk  The window.
	 * @param int    $offset Its absolute start.
	 *
	 * @return array{text: string, start_offset: int, end_offset: int} The chunk.
	 */
	private function trimmedChunk(string $chunk, int $offset): array {
		$trimmed = trim($chunk);
		$start = ($offset + strlen($chunk) - strlen(ltrim($chunk)));

		return ['text' => $trimmed, 'start_offset' => $start, 'end_offset' => ($start + strlen($trimmed))];
	}//end trimmedChunk()

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
	 * @param string      $text   Text to chunk
	 * @param ChunkSizing $sizing Target chunk size and overlap size
	 *
	 * @return (int|mixed|string)[][] Array of chunk objects with text, start_offset, end_offset
	 *
	 * @psalm-return array<int<0, max>, array{text: mixed|string, start_offset: int|mixed, end_offset: int|mixed}>
	 */
	private function chunkRecursive(string $text, ChunkSizing $sizing): array {
		// If text is already small enough, return it.
		if (strlen($text) <= $sizing->size) {
			return $this->wholeTextChunk(text: $text);
		}

		// Define separators in order of preference.
		$separators = [
			"\n\n",
			// Paragraphs.
			"\n",
			// Lines.
			'. ',
			// Sentences.
			'! ',
			'? ',
			'; ',
			', ',
			// Clauses.
			' ',
			// Words.
		];

		return $this->recursiveSplit(text: $text, level: new SplitLevel(separators: $separators, sizing: $sizing));
	}//end chunkRecursive()

	/**
	 * Recursively split text using different separators
	 *
	 * @param string     $text  Text to split
	 * @param SplitLevel $level The separators still to try and the target chunk sizing
	 *
	 * @return (int|mixed|string)[][] Array of chunk objects with text, start_offset, end_offset
	 *
	 * @psalm-return array<int<0, max>, array{text: mixed|string, start_offset: int|mixed, end_offset: int|mixed}>
	 *
	 */
	private function recursiveSplit(string $text, SplitLevel $level): array {
		if (strlen($text) <= $level->sizing->size) {
			return $this->wholeTextChunk(text: $text);
		}

		if ($level->separators === []) {
			return $this->chunkFixedSize(text: $text, sizing: $level->sizing);
		}

		$separator = $level->separators[0];
		$pass = new TextSplitPass(separator: $separator, chunkSize: $level->sizing->size, chunkOverlap: $level->sizing->overlap);
		foreach (explode($separator, $text) as $piece) {
			if ($this->placePiece(pass: $pass, piece: $piece) === false) {
				// A single piece larger than a chunk is split on the next separator.
				$pass->addSplitPiece(piece: $piece, subChunks: $this->recursiveSplit(text: $piece, level: $level->deeper()));
			}
		}

		return $pass->chunks();
	}//end recursiveSplit()

	/**
	 * Place one piece in a split pass: appended, or closing the current chunk.
	 *
	 * @param TextSplitPass $pass  The pass over one separator.
	 * @param string        $piece The piece.
	 *
	 * @return bool False when the piece is larger than a chunk and must be split on the next separator.
	 */
	private function placePiece(TextSplitPass $pass, string $piece): bool {
		if ($pass->append(piece: $piece) === true) {
			return true;
		}

		if ($pass->hasCurrent() === true) {
			$pass->closeWith(piece: $piece);
			return true;
		}

		return false;
	}//end placePiece()

	/**
	 * Check if MIME type is a Word document
	 *
	 * @param string $mimeType MIME type to check
	 *
	 * @return bool True if Word document
	 */
	private function isWordDocument(string $mimeType): bool {
		$wordTypes = [
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/msword',
			'application/vnd.oasis.opendocument.text',
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
	private function isSpreadsheet(string $mimeType): bool {
		$spreadsheetTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];

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
	private function getDetectionMethod(?string $language): string {
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
	 *
	 */
	private function calculateAvgChunkSize(array $chunks): float {
		if ($chunks === []) {
			return 0.0;
		}

		$totalSize = 0;
		foreach ($chunks as $chunk) {
			$totalSize += strlen($this->chunkText(chunk: $chunk));
		}

		return round($totalSize / count($chunks), 2);
	}//end calculateAvgChunkSize()

	/**
	 * The text of a chunk, which is a row with a `text` key or a plain string.
	 *
	 * @param mixed $chunk The chunk.
	 *
	 * @return string The text; empty for anything else.
	 */
	private function chunkText(mixed $chunk): string {
		if (is_string($chunk) === true) {
			return $chunk;
		}

		if (is_array($chunk) === true) {
			return ($chunk['text'] ?? '');
		}

		return '';
	}//end chunkText()
}//end class
