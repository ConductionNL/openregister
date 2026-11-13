<?php

/**
 * EntityRecognitionHandler
 *
 * Handler for extracting named entities (persons, organizations, locations, etc.)
 * from text chunks for GDPR compliance and data classification.
 * This handler is invoked after chunks are created to detect and store entities.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use DateTime;
use Exception;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Exception\AnalyzeRequestRejectedException;
use OCA\OpenRegister\Exception\TextDisclosureNotRecordedException;
use OCA\OpenRegister\Service\Anonymisation\AnonymiserRequestInterface;
use OCA\OpenRegister\Service\Anonymisation\BackendMethodsInterface;
use OCA\OpenRegister\Service\Anonymisation\TextDisclosureSource;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionBackends;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlPatternSet;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
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
 *
 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
 */
class EntityRecognitionHandler {
	/**
	 * Entity type constants.
	 */
	public const ENTITY_TYPE_PERSON = 'PERSON';
	public const ENTITY_TYPE_ORGANIZATION = 'ORGANIZATION';
	public const ENTITY_TYPE_LOCATION = 'LOCATION';
	public const ENTITY_TYPE_EMAIL = 'EMAIL';
	public const ENTITY_TYPE_PHONE = 'PHONE';
	public const ENTITY_TYPE_ADDRESS = 'ADDRESS';
	public const ENTITY_TYPE_DATE = 'DATE';
	public const ENTITY_TYPE_IBAN = 'IBAN';
	public const ENTITY_TYPE_SSN = 'SSN';
	public const ENTITY_TYPE_IP_ADDRESS = 'IP_ADDRESS';

	/**
	 * Our entity types in anonymiq's (OpenAnonymiser's) own vocabulary.
	 *
	 * Anonymiq validates `entities` against its SUPPORTED_PII_ENTITIES_TO_ANONYMIZE
	 * (anonymiq `src/api/config.py`) and answers 422 to any other name, so the
	 * Presidio names (`EMAIL_ADDRESS`, `IBAN_CODE`, `US_SSN`) must not be sent
	 * to it (or#4115). A type it does not know is left out.
	 *
	 * @var array<string, string>
	 */
	private const OPENANONYMISER_ENTITY_NAMES = [
		self::ENTITY_TYPE_PERSON => 'PERSON',
		self::ENTITY_TYPE_LOCATION => 'LOCATION',
		self::ENTITY_TYPE_PHONE => 'PHONE_NUMBER',
		self::ENTITY_TYPE_EMAIL => 'EMAIL',
		self::ENTITY_TYPE_ORGANIZATION => 'ORGANIZATION',
		self::ENTITY_TYPE_IBAN => 'IBAN',
		self::ENTITY_TYPE_DATE => 'DATE_TIME',
		self::ENTITY_TYPE_ADDRESS => 'ADDRESS',
	];

	/**
	 * Detection method constants.
	 */
	public const METHOD_REGEX = 'regex';
	public const METHOD_PRESIDIO = 'presidio';
	public const METHOD_OPENANONYMISER = 'openanonymiser';
	public const METHOD_LLM = 'llm';
	public const METHOD_HYBRID = 'hybrid';
	public const METHOD_MANUAL = 'manual';

	/**
	 * Category constants.
	 */
	public const CATEGORY_PERSONAL_DATA = 'personal_data';
	public const CATEGORY_SENSITIVE_PII = 'sensitive_pii';
	public const CATEGORY_BUSINESS_DATA = 'business_data';
	public const CATEGORY_CONTEXTUAL_DATA = 'contextual_data';
	public const CATEGORY_TEMPORAL_DATA = 'temporal_data';

	/**
	 * The result of a chunk without text or without detected entities.
	 *
	 * @var array{entities_found: int, relations_created: int, entities: array}
	 */
	private const NO_ENTITIES = [
		'entities_found' => 0,
		'relations_created' => 0,
		'entities' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param EntityRecognitionStores   $stores   Chunk, entity and relation stores plus the DB connection.
	 * @param EntityRecognitionBackends $backends Settings, anonymisation backend and the lazy app container.
	 * @param LoggerInterface           $logger   Logger.
	 */
	public function __construct(
		private readonly EntityRecognitionStores $stores,
		private readonly EntityRecognitionBackends $backends,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Process chunks for a source and extract entities.
	 *
	 * This method is called after chunks are created to detect and store entities.
	 *
	 * @param string $sourceType Source type identifier (file, object, etc.).
	 * @param int $sourceId Source identifier.
	 * @param array $options Processing options:
	 *                       - method: 'regex', 'presidio', 'llm', 'hybrid' (default: 'hybrid').
	 *                       - entity_types: array of entity types to detect (default: all).
	 *                       - confidence_threshold: minimum confidence (default: 0.5).
	 *                       - context_window: characters around entity (default: 50).
	 *
	 * @return int[]
	 *
	 * @throws Exception When processing fails.
	 *
	 * @psalm-return array{chunks_processed: int<0, max>, entities_found: int<0, max>, relations_created: int<0, max>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
	 */
	public function processSourceChunks(string $sourceType, int $sourceId, array $options = []): array {
		return $this->extractEntities(request: new EntityExtractionRequest(sourceType: $sourceType, sourceId: $sourceId, options: $options));
	}//end processSourceChunks()

	/**
	 * Extract the entities of every content chunk of one source.
	 *
	 * @param EntityExtractionRequest $request The source and the processing options.
	 *
	 * @return int[]
	 *
	 * @throws Exception When processing fails.
	 *
	 * @psalm-return array{chunks_processed: int<0, max>, entities_found: int<0, max>, relations_created: int<0, max>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
	 */
	public function extractEntities(EntityExtractionRequest $request): array {
		$this->logExtractionStart(request: $request);

		$run = new EntityExtractionRun(request: $request);
		foreach ($this->contentChunks(request: $request) as $chunk) {
			$run->chunk = $chunk;
			$this->extractCounted(run: $run);
		}

		$this->logExtractionComplete(run: $run);

		return $run->totals();
	}//end extractEntities()

	/**
	 * Log the start of a source's entity extraction.
	 *
	 * @param EntityExtractionRequest $request The source and the processing options.
	 *
	 * @return void
	 */
	private function logExtractionStart(EntityExtractionRequest $request): void {
		$this->logger->info(
			message: '[EntityRecognitionHandler] Processing chunks for entity extraction',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'source_type' => $request->sourceType,
				'source_id' => $request->sourceId,
			]
		);
	}//end logExtractionStart()

	/**
	 * The source's chunks, without the metadata chunks (chunk_index = -1).
	 *
	 * @param EntityExtractionRequest $request The source and the processing options.
	 *
	 * @return Chunk[]
	 */
	private function contentChunks(EntityExtractionRequest $request): array {
		$chunks = $this->stores->chunkMapper->findBySource(sourceType: $request->sourceType, sourceId: $request->sourceId);

		return array_filter(
			$chunks,
			fn ($chunk) => $chunk->getChunkIndex() !== -1
		);
	}//end contentChunks()

	/**
	 * Extract the run's current chunk and add its results to the totals; a failure is logged and skipped.
	 *
	 * @param EntityExtractionRun $run The extraction run, holding the chunk in hand.
	 *
	 * @return void
	 */
	private function extractCounted(EntityExtractionRun $run): void {
		try {
			$run->record(result: $this->extractFromChunk(chunk: $run->chunk, options: $run->request->options));
		} catch (Exception $e) {
			$this->logChunkFailure(run: $run, error: $e);
		}
	}//end extractCounted()

	/**
	 * Log a chunk whose extraction failed.
	 *
	 * @param EntityExtractionRun $run   The extraction run, holding the failed chunk.
	 * @param Exception           $error The failure.
	 *
	 * @return void
	 */
	private function logChunkFailure(EntityExtractionRun $run, Exception $error): void {
		$this->logger->error(
			message: '[EntityRecognitionHandler] Failed to process chunk',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'chunk_id' => $run->chunk->getId(),
				'source_type' => $run->request->sourceType,
				'source_id' => $run->request->sourceId,
				'error' => $error->getMessage(),
			]
		);
	}//end logChunkFailure()

	/**
	 * Log the end of a source's entity extraction with its totals.
	 *
	 * @param EntityExtractionRun $run The finished extraction run.
	 *
	 * @return void
	 */
	private function logExtractionComplete(EntityExtractionRun $run): void {
		$this->logger->info(
			message: '[EntityRecognitionHandler] Source processing complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'source_type' => $run->request->sourceType,
				'source_id' => $run->request->sourceId,
				'chunks_processed' => $run->chunksProcessed,
				'entities_found' => $run->entitiesFound,
				'relations_created' => $run->relationsCreated,
			]
		);
	}//end logExtractionComplete()

	/**
	 * Extract entities from a text chunk.
	 *
	 * @param Chunk $chunk Chunk to process.
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
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
	 */
	public function extractFromChunk(Chunk $chunk, array $options = []): array {
		$this->logChunkStart(chunk: $chunk);

		$text = $chunk->getTextContent();
		if (empty($text) === true || trim($text) === '') {
			return self::NO_ENTITIES;
		}

		return $this->detectAndStore(scan: $this->chunkScan(chunk: $chunk, options: $options), options: $options);
	}//end extractFromChunk()

	/**
	 * The scan of a chunk with text: the chunk, its text, the detection method and the context window.
	 *
	 * @param Chunk $chunk   The chunk, known to hold text.
	 * @param array $options Processing options: method, context_window.
	 *
	 * @return ChunkEntityScan
	 */
	private function chunkScan(Chunk $chunk, array $options): ChunkEntityScan {
		return new ChunkEntityScan(
			chunk: $chunk,
			text: $chunk->getTextContent(),
			method: $options['method'] ?? self::METHOD_HYBRID,
			contextWindow: (int)($options['context_window'] ?? 50)
		);
	}//end chunkScan()

	/**
	 * Log the start of one chunk's extraction.
	 *
	 * @param Chunk $chunk The chunk.
	 *
	 * @return void
	 */
	private function logChunkStart(Chunk $chunk): void {
		$this->logger->debug(
			message: '[EntityRecognitionHandler] Extracting entities from chunk',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'chunk_id' => $chunk->getId(),
				'source_type' => $chunk->getSourceType(),
				'source_id' => $chunk->getSourceId(),
			]
		);
	}//end logChunkStart()

	/**
	 * Detect the entities in a chunk's text and store them against the chunk.
	 *
	 * @param ChunkEntityScan $scan    The chunk, its text, the method and the context window.
	 * @param array           $options Processing options: entity_types, confidence_threshold.
	 *
	 * @return array Extraction results with entities_found, relations_created, and entities list.
	 *
	 * @throws Exception When detection fails.
	 */
	private function detectAndStore(ChunkEntityScan $scan, array $options): array {
		$detectedEntities = $this->detectEntities(
			query: new EntityDetectionQuery(
				text: $scan->text,
				entityTypes: $options['entity_types'] ?? null,
				confidenceThreshold: (float)($options['confidence_threshold'] ?? 0.5),
				source: new TextDisclosureSource(sourceType: (string)$scan->chunk->getSourceType(), sourceId: (int)$scan->chunk->getSourceId())
			),
			method: $scan->method
		);

		if (empty($detectedEntities) === true) {
			return self::NO_ENTITIES;
		}

		return $this->storeDetectedEntities(detectedEntities: $detectedEntities, scan: $scan);
	}//end detectAndStore()

	/**
	 * Store detected entities and create chunk-entity relations.
	 *
	 * Iterates over detected entities, finds or creates each entity record,
	 * creates a relation linking the entity to the chunk, and collects results.
	 *
	 * @param array           $detectedEntities Array of detected entity data from detection methods.
	 * @param ChunkEntityScan $scan             The chunk, its text, the method and the context window.
	 *
	 * @return array{entities_found: int, relations_created: int, entities: array} Storage results.
	 */
	private function storeDetectedEntities(array $detectedEntities, ChunkEntityScan $scan): array {
		$entitiesFound = 0;
		$relationsCreated = 0;
		$storedEntities = [];

		foreach ($detectedEntities as $detected) {
			try {
				$storedEntities[] = $this->storeDetectedEntity(detected: $detected, scan: $scan);
				$entitiesFound++;
				$relationsCreated++;
			} catch (Exception $e) {
				$this->logger->error(
					message: '[EntityRecognitionHandler] Failed to store entity',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'chunk_id' => $scan->chunk->getId(),
						'type' => $detected['type'] ?? 'unknown',
						'value' => substr($detected['value'] ?? '', 0, 50),
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return [
			'entities_found' => $entitiesFound,
			'relations_created' => $relationsCreated,
			'entities' => $storedEntities,
		];
	}//end storeDetectedEntities()

	/**
	 * Store one detected entity and the relation linking it to the chunk.
	 *
	 * @param array           $detected One detected entity.
	 * @param ChunkEntityScan $scan     The chunk, its text, the method and the context window.
	 *
	 * @return array{type: mixed, value: mixed, confidence: mixed} The stored entity summary.
	 *
	 * @throws Exception When the entity or the relation cannot be stored.
	 */
	private function storeDetectedEntity(array $detected, ChunkEntityScan $scan): array {
		$chunk = $scan->chunk;

		// Find or create entity.
		$entity = $this->findOrCreateEntity(detected: $detected);

		// Create entity relation.
		$relation = new EntityRelation();
		$relation->setEntityId($entity->getId());
		$relation->setChunkId($chunk->getId());
		$relation->setPositionStart($detected['position_start']);
		$relation->setPositionEnd($detected['position_end']);
		$relation->setConfidence($detected['confidence']);
		$relation->setDetectionMethod($scan->method);
		$context = $this->extractContext(detected: $detected, scan: $scan);
		$relation->setContext($this->sanitizeUtf8(value: $context));
		$relation->setCreatedAt(new DateTime());

		$this->setRelationSource(relation: $relation, chunk: $chunk);

		$this->stores->entityRelationMapper->insert($relation);

		return [
			'type' => $detected['type'],
			'value' => $detected['value'],
			'confidence' => $detected['confidence'],
		];
	}//end storeDetectedEntity()

	/**
	 * Set the source references on a relation from the chunk's source type.
	 *
	 * @param EntityRelation $relation The relation being built.
	 * @param Chunk          $chunk    The chunk the entity was found in.
	 *
	 * @return void
	 */
	private function setRelationSource(EntityRelation $relation, Chunk $chunk): void {
		if ($chunk->getSourceType() === 'file') {
			$relation->setFileId($chunk->getSourceId());
			return;
		}

		if ($chunk->getSourceType() === 'object') {
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
	}//end setRelationSource()

	/**
	 * Detect entities in text using specified method.
	 *
	 * @param EntityDetectionQuery $query  Text, entity filter (null = all) and minimum confidence.
	 * @param string               $method Detection method.
	 *
	 * @return array Detected entities with type, value, category, position, and confidence.
	 */
	private function detectEntities(EntityDetectionQuery $query, string $method): array {
		$method = $this->resolveMethod(method: $method);

		return match ($method) {
			self::METHOD_REGEX => $this->detectWithRegex(query: $query),
			self::METHOD_PRESIDIO => $this->detectWithPresidio(query: $query),
			self::METHOD_OPENANONYMISER => $this->detectWithOpenAnonymiser(query: $query),
			self::METHOD_LLM => $this->detectWithLLM(query: $query),
			self::METHOD_HYBRID => $this->detectWithHybrid(query: $query),
			default => throw new Exception("Unknown detection method: {$method}")
		};//end match
	}//end detectEntities()

	/**
	 * Resolve the requested method to a concrete, runnable method.
	 *
	 * Guards against the removed `auto` sentinel (which legacy installs may still
	 * have stored) and any unknown/empty value by deferring to the single source
	 * of truth — `AnonymisationBackendService::getState()->effectiveMethod` — which
	 * applies the availability/precedence rules. Concrete known methods pass through.
	 *
	 * @param string $method The requested method.
	 *
	 * @return string A concrete method from BackendMethodsInterface::METHODS.
	 */
	private function resolveMethod(string $method): string {
		if (in_array($method, BackendMethodsInterface::METHODS, true) === true) {
			return $method;
		}

		try {
			return $this->backends->anonymisationBackendService->getState()->effectiveMethod;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[EntityRecognitionHandler] Could not resolve method "' . $method . '", falling back to regex: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return self::METHOD_REGEX;
		}
	}//end resolveMethod()

	/**
	 * Detect entities using regex patterns.
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return array Detected entities with type, value, category, position, and confidence.
	 */
	private function detectWithRegex(EntityDetectionQuery $query): array {
		$entities = $this->matchRegexPatterns(query: $query);

		// Country-specific identifiers come from a jurisdiction pattern set,
		// not from the generic patterns above (or#4104). The Dutch set finds
		// a BSN only when it passes the elfproef, and reports it as SSN, the
		// type the risk service rates very high.
		if ($query->wants(type: self::ENTITY_TYPE_SSN) === true) {
			$entities = $this->withoutPhoneOverlapping(
				entities: array_merge($entities, (new NlPatternSet())->detect(text: $query->text))
			);
		}

		// Filter by confidence threshold.
		$confidenceThreshold = $query->confidenceThreshold;
		return array_filter(
			$entities,
			fn ($e) => $e['confidence'] >= $confidenceThreshold
		);
	}//end detectWithRegex()

	/**
	 * Match the generic regex patterns the query asks for.
	 *
	 * @param EntityDetectionQuery $query Text and entity filter.
	 *
	 * @return array Matched entities, unfiltered by confidence.
	 */
	private function matchRegexPatterns(EntityDetectionQuery $query): array {
		$entities = [];
		foreach ($this->getRegexPatterns() as $patternDef) {
			// Skip entity types not requested.
			if ($query->wants(type: $patternDef['type']) === false) {
				continue;
			}

			$entities = array_merge($entities, $this->matchRegexPattern(patternDef: $patternDef, text: $query->text));
		}

		return $entities;
	}//end matchRegexPatterns()

	/**
	 * Match one regex pattern definition against the text.
	 *
	 * @param array  $patternDef Pattern definition (type, pattern, category, confidence).
	 * @param string $text       Text to analyse.
	 *
	 * @return array The entities the pattern matched.
	 */
	private function matchRegexPattern(array $patternDef, string $text): array {
		$entities = [];
		if (preg_match_all($patternDef['pattern'], $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
			foreach ($matches[0] as $match) {
				$entities[] = [
					'type' => $patternDef['type'],
					'value' => $match[0],
					'category' => $patternDef['category'],
					'position_start' => $match[1],
					'position_end' => $match[1] + strlen($match[0]),
					'confidence' => $patternDef['confidence'],
				];
			}
		}

		return $entities;
	}//end matchRegexPattern()

	/**
	 * Drop PHONE matches that overlap a BSN.
	 *
	 * The phone pattern matches any run of digits, so a BSN was also, or
	 * instead, labelled PHONE, which rates the file medium where a BSN rates
	 * it very high. A span proved to be a BSN by the elfproef is not a phone
	 * number.
	 *
	 * @param array $entities The detected entities.
	 *
	 * @return array The entities without a PHONE that overlaps an SSN span.
	 */
	private function withoutPhoneOverlapping(array $entities): array {
		$bsnSpans = self::bsnSpans(entities: $entities);

		if ($bsnSpans === []) {
			return $entities;
		}

		return array_values(
			array_filter(
				$entities,
				static fn (array $entity): bool => $entity['type'] !== self::ENTITY_TYPE_PHONE
					|| self::overlapsAnySpan(entity: $entity, spans: $bsnSpans) === false
			)
		);
	}//end withoutPhoneOverlapping()

	/**
	 * The [start, end] spans of the SSN entities.
	 *
	 * @param array $entities The detected entities.
	 *
	 * @return array<int, array{0: mixed, 1: mixed}> The SSN spans.
	 */
	private static function bsnSpans(array $entities): array {
		$bsnSpans = [];
		foreach ($entities as $entity) {
			if ($entity['type'] === self::ENTITY_TYPE_SSN) {
				$bsnSpans[] = [$entity['position_start'], $entity['position_end']];
			}
		}

		return $bsnSpans;
	}//end bsnSpans()

	/**
	 * Whether an entity overlaps any of the given spans.
	 *
	 * @param array $entity The entity.
	 * @param array $spans  The [start, end] spans.
	 *
	 * @return bool True when the entity overlaps a span.
	 */
	private static function overlapsAnySpan(array $entity, array $spans): bool {
		foreach ($spans as [$start, $end]) {
			if ($entity['position_start'] < $end && $entity['position_end'] > $start) {
				return true;
			}
		}

		return false;
	}//end overlapsAnySpan()

	/**
	 * Get regex pattern definitions for entity detection.
	 *
	 * Returns an array of pattern definitions, each containing the entity type,
	 * regex pattern, category, and confidence level.
	 *
	 * @return array<array{type: string, pattern: string, category: string, confidence: float}> Pattern definitions.
	 */
	private function getRegexPatterns(): array {
		return [
			[
				'type' => self::ENTITY_TYPE_EMAIL,
				'pattern' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
				'category' => self::CATEGORY_PERSONAL_DATA,
				'confidence' => 0.9,
			],
			[
				'type' => self::ENTITY_TYPE_PHONE,
				'pattern' => '/\+?[1-9]\d{1,14}|\+?31\s?[0-9]{9}|\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/',
				'category' => self::CATEGORY_PERSONAL_DATA,
				'confidence' => 0.7,
			],
			[
				'type' => self::ENTITY_TYPE_IBAN,
				'pattern' => '/[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]?){0,16}/',
				'category' => self::CATEGORY_SENSITIVE_PII,
				'confidence' => 0.8,
			],
		];
	}//end getRegexPatterns()

	/**
	 * Detect entities using Presidio service.
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return array Detected entities with type, value, category, position, and confidence.
	 *
	 * @throws TextDisclosureNotRecordedException When the disclosure record cannot be written.
	 */
	private function detectWithPresidio(EntityDetectionQuery $query): array {
		try {
			return $this->runPresidio(query: $query);
		} catch (TextDisclosureNotRecordedException $e) {
			// The text was not sent; the regex fallback would hide that.
			throw $e;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[EntityRecognitionHandler] Presidio detection failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $this->detectWithRegex(query: $query);
		}//end try
	}//end detectWithPresidio()

	/**
	 * Ask Presidio for entities, falling back to regex when it is not configured or not reachable.
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return array Detected entities.
	 *
	 * @throws Exception When settings or the request fail.
	 */
	private function runPresidio(EntityDetectionQuery $query): array {
		$presidioEndpoint = $this->presidioEndpoint();
		if ($presidioEndpoint === null) {
			return $this->detectWithRegex(query: $query);
		}

		$request = $this->presidioRequest(query: $query);
		$apiResults = $this->postToPresidio(request: $request, endpoint: $presidioEndpoint);
		if ($apiResults === null) {
			return $this->detectWithRegex(query: $query);
		}

		return $this->presidioEntities(apiResults: $apiResults, request: $request);
	}//end runPresidio()

	/**
	 * Convert Presidio's results to our format.
	 *
	 * @param array          $apiResults Presidio's results.
	 * @param AnalyzeRequest $request    The analyze request.
	 *
	 * @return array Detected entities.
	 */
	private function presidioEntities(array $apiResults, AnalyzeRequest $request): array {
		$this->logger->debug(
			message: '[EntityRecognitionHandler] Presidio found ' . count($apiResults) . ' entities',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		// Convert results to our format.
		return $this->convertApiResultsToEntities(apiResults: $apiResults, request: $request);
	}//end presidioEntities()

	/**
	 * The configured Presidio endpoint, or null (with a warning) when none is configured.
	 *
	 * @return string|null The endpoint without a trailing slash, or null.
	 *
	 * @throws Exception When the settings cannot be read.
	 */
	private function presidioEndpoint(): ?string {
		$fileSettings = $this->backends->settingsService->getFileSettingsOnly();
		// Strip a trailing slash to avoid a double-slash "//analyze" URL
		// (some servers 404, silently sending the run to the regex fallback).
		$presidioEndpoint = $this->endpointSetting(fileSettings: $fileSettings, key: 'presidioApiEndpoint');

		if (empty($presidioEndpoint) === true) {
			$this->logger->warning(
				message: '[EntityRecognitionHandler] Presidio endpoint not configured, falling back to regex',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return $presidioEndpoint;
	}//end presidioEndpoint()

	/**
	 * A configured endpoint setting, trimmed and without a trailing slash.
	 *
	 * @param array  $fileSettings The stored file settings.
	 * @param string $key          The setting holding the endpoint.
	 *
	 * @return string The endpoint, or '' when none is configured.
	 */
	private function endpointSetting(array $fileSettings, string $key): string {
		return rtrim(trim((string)($fileSettings[$key] ?? '')), '/');
	}//end endpointSetting()

	/**
	 * The analyze request for Presidio: English, Presidio's entity names, no default confidence.
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return AnalyzeRequest
	 */
	private function presidioRequest(EntityDetectionQuery $query): AnalyzeRequest {
		return new AnalyzeRequest(
			query: $query,
			dialect: new AnalyzeDialect(
				language: 'en',
				nameMap: null,
				method: self::METHOD_PRESIDIO,
				defaultConfidence: 0
			)
		);
	}//end presidioRequest()

	/**
	 * Post an analyze request to Presidio's /analyze route.
	 *
	 * @param AnalyzeRequest $request  The analyze request.
	 * @param string         $endpoint The Presidio endpoint.
	 *
	 * @return array|null The results, or null on failure.
	 *
	 * @throws AnalyzeRequestRejectedException When Presidio answers with a request error.
	 */
	private function postToPresidio(AnalyzeRequest $request, string $endpoint): ?array {
		// Make HTTP request and parse response.
		return $this->postAnalyzeRequest(
			call: new AnalyzeCall(
				url: $endpoint . '/analyze',
				requestBody: $this->buildAnalyzeRequestBody(request: $request),
				serviceName: 'Presidio',
				source: $request->query->source
			)
		);
	}//end postToPresidio()

	/**
	 * Detect entities using OpenAnonymiser service.
	 *
	 * OpenAnonymiser is a Dutch-focused PII detection service with an API similar
	 * to Presidio but with key differences: endpoint at /api/v1/analyze, response
	 * wrapped in {"pii_entities": [...]}, includes "text" field per entity, and
	 * defaults to Dutch language.
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return array Detected entities with type, value, category, position, and confidence.
	 *
	 * @throws AnalyzeRequestRejectedException When OpenAnonymiser refuses the request.
	 * @throws TextDisclosureNotRecordedException When the disclosure record cannot be written.
	 */
	private function detectWithOpenAnonymiser(EntityDetectionQuery $query): array {
		try {
			return $this->runOpenAnonymiser(query: $query);
		} catch (AnalyzeRequestRejectedException $e) {
			// Reached and refused: the request is wrong, not the network, and
			// the regex fallback would hide that behind e-mail/phone/IBAN only.
			$this->logger->error(
				message: '[EntityRecognitionHandler] ' . $e->getMessage() . ' (a request error; the regex detector is not used for it)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'status' => $e->getStatus()]
			);
			throw $e;
		} catch (TextDisclosureNotRecordedException $e) {
			// The text was not sent; the regex fallback would hide that.
			throw $e;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[EntityRecognitionHandler] OpenAnonymiser detection failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $this->detectWithRegex(query: $query);
		}//end try
	}//end detectWithOpenAnonymiser()

	/**
	 * Ask OpenAnonymiser for entities, falling back to regex when it cannot be reached.
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return array Detected entities.
	 *
	 * @throws AnalyzeRequestRejectedException When OpenAnonymiser refuses the request.
	 * @throws Exception                       When settings or the request fail.
	 */
	private function runOpenAnonymiser(EntityDetectionQuery $query): array {
		// Get OpenAnonymiser settings.
		$fileSettings = $this->backends->settingsService->getFileSettingsOnly();
		$request = $this->openAnonymiserRequest(query: $query);

		// Build request body (shared by both transports), in anonymiq's
		// own entity names: it rejects Presidio's (or#4115).
		$requestBody = $this->buildAnalyzeRequestBody(request: $request);

		// A filter anonymiq knows none of asks it for nothing. Sending no
		// `entities` list would ask it for EVERY type instead.
		if ($query->hasEntityFilter() === true && isset($requestBody['entities']) === false) {
			return [];
		}

		$responseData = $this->sendOpenAnonymiserRequest(
			requestBody: $requestBody,
			transport: $this->openAnonymiserTransport(fileSettings: $fileSettings),
			source: $query->source
		);

		return $this->openAnonymiserEntities(responseData: $responseData, request: $request);
	}//end runOpenAnonymiser()

	/**
	 * The analyze request for OpenAnonymiser: Dutch, anonymiq's entity names, 0.85 for a missing score.
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return AnalyzeRequest
	 */
	private function openAnonymiserRequest(EntityDetectionQuery $query): AnalyzeRequest {
		return new AnalyzeRequest(
			query: $query,
			dialect: new AnalyzeDialect(
				language: 'nl',
				nameMap: self::OPENANONYMISER_ENTITY_NAMES,
				method: self::METHOD_OPENANONYMISER,
				defaultConfidence: 0.85
			)
		);
	}//end openAnonymiserRequest()

	/**
	 * The configured OpenAnonymiser transport: endpoint, internal or external, and credentials.
	 *
	 * @param array $fileSettings The stored file settings.
	 *
	 * @return OpenAnonymiserTransport
	 *
	 * @throws Exception When the password cannot be read.
	 */
	private function openAnonymiserTransport(array $fileSettings): OpenAnonymiserTransport {
		return new OpenAnonymiserTransport(
			// Strip a trailing slash: a configured endpoint like
			// "https://host/" would otherwise build "https://host//api/v1/analyze"
			// (double slash), which some servers 404 — sending the run silently
			// to the regex fallback.
			endpoint: $this->endpointSetting(fileSettings: $fileSettings, key: 'openAnonymiserApiEndpoint'),
			// Source: 'internal' (AppAPI ExApp, default) or 'external' (operator-entered URL).
			useExternal: (($fileSettings['openAnonymiserSource'] ?? 'internal') === 'external'),
			authHeaders: $this->openAnonymiserAuthHeaders(fileSettings: $fileSettings)
		);
	}//end openAnonymiserTransport()

	/**
	 * Convert OpenAnonymiser's answer, falling back to regex when it could not be reached.
	 *
	 * @param array|null     $responseData The response data, or null when OpenAnonymiser could not be reached.
	 * @param AnalyzeRequest $request      The analyze request.
	 *
	 * @return array Detected entities.
	 */
	private function openAnonymiserEntities(?array $responseData, AnalyzeRequest $request): array {
		if ($responseData === null) {
			$this->logger->warning(
				message: '[EntityRecognitionHandler] OpenAnonymiser unreachable, falling back to regex',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $this->detectWithRegex(query: $request->query);
		}

		// OpenAnonymiser wraps results in {"pii_entities": [...]}.
		// If the response is a flat array of entities, use it directly.
		$anonymiserResults = $responseData['pii_entities'] ?? $responseData;

		$this->logger->debug(
			message: '[EntityRecognitionHandler] OpenAnonymiser found ' . count($anonymiserResults) . ' entities',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		// Convert results to our format.
		// OpenAnonymiser may return null score for NLP-detected entities (e.g. PERSON).
		// Treat null as high confidence (0.85) since these are spaCy NER detections.
		return $this->convertApiResultsToEntities(apiResults: $anonymiserResults, request: $request);
	}//end openAnonymiserEntities()

	/**
	 * Send an analyze request to OpenAnonymiser over the configured transport.
	 *
	 * Internal calls the ExApp through AppAPI and falls back to a configured
	 * external endpoint when the ExApp is unreachable; external posts to the
	 * operator-entered URL. A 4xx is thrown by either transport (or#4115).
	 *
	 * @param array                     $requestBody The analyze request body.
	 * @param OpenAnonymiserTransport   $transport   Endpoint, transport choice and credentials.
	 * @param TextDisclosureSource|null $source      The document the text belongs to.
	 *
	 * @return array|null The response data, or null when OpenAnonymiser could not be reached.
	 *
	 * @throws AnalyzeRequestRejectedException When OpenAnonymiser refuses the request.
	 */
	private function sendOpenAnonymiserRequest(array $requestBody, OpenAnonymiserTransport $transport, ?TextDisclosureSource $source=null): ?array {
		$responseData = null;
		if ($transport->useExternal === false) {
			$responseData = $this->backends->anonymisationBackendService->requestOpenAnonymiser(
				call: new \OCA\OpenRegister\Service\Anonymisation\AnonymiserCall(route: '/api/v1/analyze', params: $requestBody, method: 'POST')
			);
		}

		if ($responseData !== null) {
			return $responseData;
		}

		if ($transport->endpoint === '') {
			$this->warnExternalEndpointMissing(transport: $transport);
			return null;
		}

		return $this->postAnalyzeRequest(
			call: new AnalyzeCall(
				url: $transport->endpoint.'/api/v1/analyze',
				requestBody: $requestBody,
				serviceName: 'OpenAnonymiser',
				extraHeaders: $transport->authHeaders,
				source: $source
			)
		);
	}//end sendOpenAnonymiserRequest()

	/**
	 * Warn when the operator chose the external endpoint but configured none.
	 *
	 * @param OpenAnonymiserTransport $transport The configured transport.
	 *
	 * @return void
	 */
	private function warnExternalEndpointMissing(OpenAnonymiserTransport $transport): void {
		if ($transport->useExternal === true) {
			$this->logger->warning(
				message: '[EntityRecognitionHandler] OpenAnonymiser external endpoint not configured, falling back to regex',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end warnExternalEndpointMissing()

	/**
	 * The HTTP Basic header for an external OpenAnonymiser, when configured.
	 *
	 * The anonymiq service can require HTTP Basic on its text routes. With no user name
	 * configured nothing is sent, which is the behaviour before credentials
	 * existed. The header is never logged.
	 *
	 * @param array $fileSettings The stored file settings.
	 *
	 * @return array<int, string> The header lines; empty when no user name is configured.
	 *
	 * @spec exclude credential plumbing for the external OpenAnonymiser transport
	 */
	private function openAnonymiserAuthHeaders(array $fileSettings): array {
		$username = trim((string) ($fileSettings['openAnonymiserUsername'] ?? ''));
		if ($username === '') {
			return [];
		}

		$password = $this->backends->settingsService->getOpenAnonymiserPassword();

		return ['Authorization: Basic '.base64_encode($username.':'.$password)];
	}//end openAnonymiserAuthHeaders()

	/**
	 * Build the request body for an analyze API call.
	 *
	 * Constructs the JSON request payload with text, language, and optional entity type filters.
	 *
	 * @param AnalyzeRequest $request Text and entity filter (null = all), language and the backend's entity names.
	 *
	 * @return array The request body array ready for JSON encoding.
	 */
	private function buildAnalyzeRequestBody(AnalyzeRequest $request): array {
		$query   = $request->query;
		$dialect = $request->dialect;
		$requestBody = [
			'text' => $query->text,
			'language' => $dialect->language,
		];

		// Add entity types filter if specified.
		if ($query->hasEntityFilter() === false) {
			return $requestBody;
		}

		$backendEntities = $this->backendEntityNames(entityTypes: $query->entityTypes, nameMap: $dialect->nameMap);
		if (empty($backendEntities) === false) {
			$requestBody['entities'] = $backendEntities;
		}

		return $requestBody;
	}//end buildAnalyzeRequestBody()

	/**
	 * Translate our entity types into the backend's names.
	 *
	 * @param array                      $entityTypes Our entity types.
	 * @param array<string, string>|null $nameMap     Our type to the backend's name; null uses Presidio's names.
	 *
	 * @return array The backend's entity names; types it does not know are dropped.
	 */
	private function backendEntityNames(array $entityTypes, ?array $nameMap): array {
		if ($nameMap === null) {
			return $this->mapToPresidioEntityTypes(entityTypes: $entityTypes);
		}

		return array_values(
			array_filter(
				array_map(static fn ($type) => ($nameMap[$type] ?? null), $entityTypes),
				static fn ($name) => $name !== null
			)
		);
	}//end backendEntityNames()

	/**
	 * Post an analyze request to an external entity detection service.
	 *
	 * Goes through Nextcloud's HTTP client, so the instance's outbound rules
	 * apply: an endpoint on a local or private address is refused unless the
	 * administrator set `allow_local_remote_servers`.
	 * Returns null on any failure (connection error, non-200, invalid JSON).
	 *
	 * @param AnalyzeCall $call URL, request body, service name and extra headers.
	 *
	 * @return array|null Parsed JSON response array, or null on failure.
	 *
	 * @throws AnalyzeRequestRejectedException    When the service answers with a request error.
	 * @throws TextDisclosureNotRecordedException When the disclosure record cannot be written; nothing is sent.
	 */
	private function postAnalyzeRequest(AnalyzeCall $call): ?array {
		$serviceName = $call->serviceName;
		$clientService = $this->backends->clientService;
		if ($clientService === null) {
			$this->logConnectionError(serviceName: $serviceName, error: 'no HTTP client available');
			return null;
		}

		// The body is recorded as disclosed before it is sent; when the record
		// cannot be written this throws and nothing is sent.
		$body = (string)json_encode($call->requestBody);
		$this->recordDisclosure(call: $call, bytes: strlen($body));

		try {
			$response = $clientService->newClient()->post(
				$call->url,
				[
					'body' => $body,
					'headers' => $this->analyzeHeaders(call: $call),
					'timeout' => 30,
					'http_errors' => false,
				]
			);
		} catch (Exception $e) {
			$this->logConnectionError(serviceName: $serviceName, error: $e->getMessage());
			return null;
		}

		return $this->acceptedAnalyzeResponse(
			serviceName: $serviceName,
			response: new AnalyzeResponse(status: $response->getStatusCode(), body: $response->getBody())
		);
	}//end postAnalyzeRequest()

	/**
	 * Record that a call is about to send a document's text to an external service.
	 *
	 * A call without a known document is recorded under source type `unknown`, never skipped.
	 *
	 * @param AnalyzeCall $call  The call about to be made.
	 * @param int         $bytes The number of bytes it sends.
	 *
	 * @return void
	 *
	 * @throws TextDisclosureNotRecordedException When no recorder is wired or the record cannot be written.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
	 */
	private function recordDisclosure(AnalyzeCall $call, int $bytes): void {
		$recorder = $this->backends->disclosureRecorder;
		if ($recorder === null) {
			throw new TextDisclosureNotRecordedException(service: $call->serviceName);
		}

		$recorder->record(
			source: $call->source ?? new TextDisclosureSource(sourceType: 'unknown', sourceId: 0),
			service: $call->serviceName,
			url: $call->url,
			bytes: $bytes
		);
	}//end recordDisclosure()

	/**
	 * Log that the service could not be reached.
	 *
	 * @param string $serviceName Human-readable service name.
	 * @param string $error       Why the request did not get an answer.
	 *
	 * @return void
	 */
	private function logConnectionError(string $serviceName, string $error): void {
		$this->logger->error(
			message: "[EntityRecognitionHandler] {$serviceName} connection error: " . $error,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logConnectionError()

	/**
	 * The JSON content headers followed by the call's extra headers, by header name.
	 *
	 * @param AnalyzeCall $call The call, with its extra headers as `Name: value` lines.
	 *
	 * @return array<string, string>
	 */
	private function analyzeHeaders(AnalyzeCall $call): array {
		$headers = [
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
		];

		foreach ($call->extraHeaders as $line) {
			$parts = explode(':', (string)$line, 2);
			if (count($parts) === 2) {
				$headers[trim($parts[0])] = trim($parts[1]);
			}
		}

		return $headers;
	}//end analyzeHeaders()

	/**
	 * The decoded body of an answered analyze request; a request error throws, any other non-200 is null.
	 *
	 * @param string          $serviceName Human-readable service name.
	 * @param AnalyzeResponse $response    The HTTP status and the raw response body.
	 *
	 * @return array|null The decoded array, or null on failure.
	 *
	 * @throws AnalyzeRequestRejectedException When the service answers with a request error.
	 */
	private function acceptedAnalyzeResponse(string $serviceName, AnalyzeResponse $response): ?array {
		$this->refuseRequestError(serviceName: $serviceName, response: $response);

		if ($response->status !== 200) {
			$this->logger->error(
				message: "[EntityRecognitionHandler] {$serviceName} returned HTTP " . $response->status,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return $this->decodeAnalyzeResponse(serviceName: $serviceName, response: $response);
	}//end acceptedAnalyzeResponse()

	/**
	 * Throw when the service answered with a request (4xx) error.
	 *
	 * @param string          $serviceName Human-readable service name.
	 * @param AnalyzeResponse $response    The HTTP status and the raw response body.
	 *
	 * @return void
	 *
	 * @throws AnalyzeRequestRejectedException When the status is a request error.
	 */
	private function refuseRequestError(string $serviceName, AnalyzeResponse $response): void {
		$httpCode = $response->status;
		if (is_int($httpCode) === false || AnalyzeRequestRejectedException::isRequestError(status: $httpCode) === false) {
			return;
		}

		$detail = '';
		if (is_string($response->body) === true) {
			$detail = $response->body;
		}

		throw new AnalyzeRequestRejectedException(service: $serviceName, status: $httpCode, detail: $detail);
	}//end refuseRequestError()

	/**
	 * Decode a 200 analyze response body.
	 *
	 * @param string          $serviceName Human-readable service name for log messages.
	 * @param AnalyzeResponse $response    The raw response body.
	 *
	 * @return array|null The decoded array, or null when the body is not a JSON array.
	 */
	private function decodeAnalyzeResponse(string $serviceName, AnalyzeResponse $response): ?array {
		$body = $response->body;
		if (is_string($body) === false) {
			$this->logger->error(
				message: "[EntityRecognitionHandler] {$serviceName} returned non-string response",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		$decoded = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			$this->logger->error(
				message: "[EntityRecognitionHandler] Failed to parse {$serviceName} response",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return $decoded;
	}//end decodeAnalyzeResponse()

	/**
	 * Convert API entity detection results to our internal entity format.
	 *
	 * Handles both Presidio-style and OpenAnonymiser-style result arrays, extracting
	 * entity type, value, position, and confidence for each detected entity.
	 *
	 * @param array          $apiResults Array of entity detection results from the API.
	 * @param AnalyzeRequest $request    The analysed text, the minimum confidence to include, the detection
	 *                                   method and the default confidence for a missing score.
	 *
	 * @return array Array of entities in our internal format.
	 */
	private function convertApiResultsToEntities(array $apiResults, AnalyzeRequest $request): array {
		$query    = $request->query;
		$dialect  = $request->dialect;
		$entities = [];

		foreach ($apiResults as $result) {
			$score = $result['score'] ?? $dialect->defaultConfidence;

			// Skip low confidence results.
			if ($score < $query->confidenceThreshold) {
				continue;
			}

			$start = $result['start'] ?? 0;
			$end = $result['end'] ?? 0;
			// Use 'text' field if available (OpenAnonymiser), otherwise extract from source text.
			$value = $result['text'] ?? substr($query->text, $start, ($end - $start));

			$entityType = $this->mapFromPresidioEntityType(presidioType: $result['entity_type'] ?? 'UNKNOWN');

			$entities[] = [
				'type' => $entityType,
				'value' => $value,
				'category' => self::getCategoryForType(type: $entityType),
				'position_start' => $start,
				'position_end' => $end,
				'confidence' => $score,
				'method' => $dialect->method,
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
	private function mapToPresidioEntityTypes(array $entityTypes): array {
		$mapping = [
			self::ENTITY_TYPE_PERSON => 'PERSON',
			self::ENTITY_TYPE_ORGANIZATION => 'ORGANIZATION',
			self::ENTITY_TYPE_LOCATION => 'LOCATION',
			self::ENTITY_TYPE_EMAIL => 'EMAIL_ADDRESS',
			self::ENTITY_TYPE_PHONE => 'PHONE_NUMBER',
			self::ENTITY_TYPE_DATE => 'DATE_TIME',
			self::ENTITY_TYPE_IBAN => 'IBAN_CODE',
			self::ENTITY_TYPE_SSN => 'US_SSN',
			self::ENTITY_TYPE_IP_ADDRESS => 'IP_ADDRESS',
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
	private function mapFromPresidioEntityType(string $presidioType): string {
		$mapping = [
			'PERSON' => self::ENTITY_TYPE_PERSON,
			'ORGANIZATION' => self::ENTITY_TYPE_ORGANIZATION,
			'LOCATION' => self::ENTITY_TYPE_LOCATION,
			'EMAIL_ADDRESS' => self::ENTITY_TYPE_EMAIL,
			'PHONE_NUMBER' => self::ENTITY_TYPE_PHONE,
			'DATE_TIME' => self::ENTITY_TYPE_DATE,
			'IBAN_CODE' => self::ENTITY_TYPE_IBAN,
			'US_SSN' => self::ENTITY_TYPE_SSN,
			'IP_ADDRESS' => self::ENTITY_TYPE_IP_ADDRESS,
			'CREDIT_CARD' => 'CREDIT_CARD',
			'CRYPTO' => 'CRYPTO',
			'URL' => 'URL',
			'NRP' => 'NRP',
		];

		return $mapping[$presidioType] ?? $presidioType;
	}//end mapFromPresidioEntityType()

	/**
	 * Detect entities using LLM.
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return array Detected entities with type, value, category, position, and confidence.
	 */
	private function detectWithLLM(EntityDetectionQuery $query): array {
		// TODO: Implement LLM-based entity extraction.
		// For now, fall back to regex.
		$this->logger->debug(
			message: '[EntityRecognitionHandler] LLM extraction not yet implemented, using regex fallback',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $this->detectWithRegex(query: $query);
	}//end detectWithLLM()

	/**
	 * Detect entities using hybrid approach (combines multiple methods).
	 *
	 * @param EntityDetectionQuery $query Text, entity filter and minimum confidence.
	 *
	 * @return array Detected entities with type, value, category, position, and confidence.
	 */
	private function detectWithHybrid(EntityDetectionQuery $query): array {
		// Start with regex for fast detection.
		$regexEntities = $this->detectWithRegex(query: $query);

		// TODO: Add Presidio validation for higher confidence.
		// TODO: Add LLM validation for ambiguous cases.
		return $regexEntities;
	}//end detectWithHybrid()

	/**
	 * Find or create an entity.
	 *
	 * @param array $detected One detected entity: its type, value and (optional) category.
	 *
	 * @return GdprEntity Entity instance.
	 *
	 * @throws Exception When entity creation fails.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
	 */
	private function findOrCreateEntity(array $detected): GdprEntity {
		$type = $detected['type'];
		// Scrub the value to valid UTF-8: text extracted from some PDFs
		// contains ill-formed byte sequences (e.g. a stray 0xC3 lead byte)
		// that PostgreSQL (UTF8) rejects with SQLSTATE[22021], aborting the
		// insert and losing the entity.
		$value    = $this->sanitizeUtf8(value: trim((string)($detected['value'] ?? '')));
		$category = ($detected['category'] ?? self::getCategoryForType(type: $type));

		// Try to find existing entity by value and type.
		try {
			$qb = $this->stores->db->getQueryBuilder();
			$qb->select('*')
				->from('openregister_entities')
				->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
				->andWhere($qb->expr()->eq('value', $qb->createNamedParameter($value)))
				->setMaxResults(1);

			/*
			 * @psalm-suppress InaccessibleMethod - findEntities is accessible via inheritance.
			 */

			$existingEntities = $this->stores->entityMapper->findEntitiesPublic($qb);
			if (empty($existingEntities) === false) {
				$existing = $existingEntities[0];
				// Update timestamp.
				$existing->setUpdatedAt(new DateTime());
				$this->stores->entityMapper->update($existing);
				return $existing;
			}

			throw new DoesNotExistException('Entity not found');
		} catch (DoesNotExistException $e) {
			// Entity doesn't exist, create new one.
			$entity = new GdprEntity();

			$entity->setUuid((string)Uuid::v4());
			$entity->setType($type);
			$entity->setValue($value);
			$entity->setCategory($category);
			$entity->setDetectedAt(new DateTime());
			$entity->setUpdatedAt(new DateTime());

			return $this->stores->entityMapper->insert($entity);
		}//end try
	}//end findOrCreateEntity()

	/**
	 * Get category for entity type.
	 *
	 * Lifted to `public static` so flows that produce GdprEntity rows
	 * outside the detector pipeline (notably the manual-entity endpoint
	 * in `ManualEntityService`) can derive the same category mapping
	 * without taking on this handler's DI graph. The `oc_openregister_entities.category`
	 * column is NOT NULL with no default — every insert path MUST set it,
	 * and consistency with detector-produced rows is desirable so the
	 * catalogue stays homogeneous.
	 *
	 * @param string $type Entity type tag (e.g. `PERSON`, `IBAN`).
	 *
	 * @return string One of the `CATEGORY_*` constants on this class.
	 */
	public static function getCategoryForType(string $type): string {
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
	 * @param array           $detected One detected entity: its start and end position.
	 * @param ChunkEntityScan $scan     The full text and the context window size.
	 *
	 * @return string Context string.
	 */
	private function extractContext(array $detected, ChunkEntityScan $scan): string {
		$start = max(0, $detected['position_start'] - $scan->contextWindow);
		$end = min(strlen($scan->text), $detected['position_end'] + $scan->contextWindow);

		return substr($scan->text, $start, $end - $start);
	}//end extractContext()

	/**
	 * Scrub a string to well-formed UTF-8 for safe PostgreSQL storage.
	 *
	 * Text extracted from some PDFs contains ill-formed UTF-8 byte sequences.
	 * PostgreSQL's UTF8 encoding rejects these on insert (SQLSTATE[22021]),
	 * which would abort the entity-relation insert and silently drop the
	 * entity — leaving residual PII in the anonymised output. Replacing the
	 * invalid bytes here keeps storage (and the downstream substitution map)
	 * intact. Operates on already-substringed context, so stored integer
	 * offsets are unaffected.
	 *
	 * @param string $value The (possibly ill-formed) input string.
	 *
	 * @return string A valid-UTF-8 string.
	 */
	private function sanitizeUtf8(string $value): string {
		if ($value === '') {
			return $value;
		}

		if (function_exists('mb_scrub') === true) {
			// The mb_scrub() call returns string, so no is_string() re-check.
			return mb_scrub($value, 'UTF-8');
		}

		$converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

		if ($converted === false) {
			return $value;
		}

		return $converted;
	}//end sanitizeUtf8()

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
	 * @param int $objectId Magic-table object id.
	 *
	 * @return void
	 */
	private function populateObjectContextOnRelation(EntityRelation $relation, int $objectId): void {
		// Resolved lazily through the injected app container (the mapper's own
		// graph reaches back into this service's collaborators), never through
		// `\OC::$server`: outside a booted Nextcloud that autowires an unbounded
		// cycle and eats all memory (19 GB on 2026-09-08).
		if ($this->backends->container === null) {
			return;
		}

		try {
			$objectMapper = $this->backends->container->get(\OCA\OpenRegister\Db\MagicMapperInterface::class);
			$object = $objectMapper->find(
				$objectId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			// Best-effort enrichment; leave the new columns null.
			return;
		}

		$relation->setRegisterId((string)$object->getRegister());
		$relation->setSchemaId((string)$object->getSchema());
		$relation->setObjectUuid((string)$object->getUuid());

	}//end populateObjectContextOnRelation()
}//end class
