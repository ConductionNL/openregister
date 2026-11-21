<?php

/**
 * OpenRegister ManualEntityService.
 *
 * Orchestrates the operator-supplied manual-entity flow specced in
 * `manual-entity-anonymisation`:
 *
 *   - lookup-or-create the catalogue entry for (value, type),
 *   - chunk-aware string-match the file's extracted text,
 *   - insert one EntityRelation row per occurrence,
 *   - write audit-trail rows,
 *   - atomic per call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Db\DetectionMethod;
use OCA\OpenRegister\Files\Db\EntityMention;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Exception\ChunkMatcherException;
use OCA\OpenRegister\Exception\ManualEntityException;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCP\Files\File as NcFile;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Single point of truth for the file-scoped manual-entity write path.
 *
 * Public surface is one method: `addManualEntity`. Tests cover happy /
 * reuse / idempotent / zero-match / file-not-extracted / RBAC-denied
 * paths against this surface.
 */
class ManualEntityService {
	/**
	 * Fallback chunk overlap (chars) used only when a file's persisted
	 * chunks carry no `overlap_size` (legacy rows written before the field
	 * existed). The effective overlap is normally read per-file from the
	 * chunk rows so it tracks whatever `chunk_overlap` the extractor used;
	 * see {@see TextExtractionService::DEFAULT_CHUNK_OVERLAP} for the
	 * matching default. Needles longer than the effective overlap cannot be
	 * reliably matched per-chunk and are rejected by `ChunkTextMatcher`.
	 */
	private const FALLBACK_CHUNK_OVERLAP_CHARS = 200;

	/**
	 * Source-type tag used by `ChunkMapper::findBySource` for file-
	 * scoped chunks. Mirrors the convention already in use across
	 * `TextExtractionService`, `FileSidebarService`, etc.
	 */
	private const CHUNK_SOURCE_TYPE = 'file';

	/**
	 * Catalogue mapper.
	 *
	 * @var GdprEntityMapperInterface
	 */
	private readonly GdprEntityMapperInterface $gdprEntityMapper;

	/**
	 * Relation mapper.
	 *
	 * @var EntityRelationMapperInterface
	 */
	private readonly EntityRelationMapperInterface $entityRelationMapper;

	/**
	 * Chunk reader for the target file.
	 *
	 * @var ChunkSourceReaderInterface
	 */
	private readonly ChunkSourceReaderInterface $chunkMapper;

	/**
	 * Chunk-aware string-match utility.
	 *
	 * @var ChunkTextMatcher
	 */
	private readonly ChunkTextMatcher $matcher;

	/**
	 * Audit-trail persistence.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Nextcloud root folder (for file-write access check).
	 *
	 * @var IRootFolder
	 */
	private readonly IRootFolder $rootFolder;

	/**
	 * Connection for explicit transaction control.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Structured log sink for write-access failures and forensic context on rollback paths.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ManualEntityCatalogue $catalogue The entity catalogue, relation and chunk mappers and the chunk text matcher
	 * @param ManualEntityRecording $recording The audit trail mapper, the root folder and the database connection
	 * @param LoggerInterface       $logger    Structured log sink for write-access failures and forensic context on rollback paths.
	 */
	public function __construct(
		ManualEntityCatalogue $catalogue,
		ManualEntityRecording $recording,
		LoggerInterface $logger,
	) {
		$this->gdprEntityMapper = $catalogue->gdprEntityMapper;
		$this->entityRelationMapper = $catalogue->entityRelationMapper;
		$this->chunkMapper = $catalogue->chunkMapper;
		$this->matcher = $catalogue->matcher;
		$this->auditTrailMapper = $recording->auditTrailMapper;
		$this->rootFolder = $recording->rootFolder;
		$this->db = $recording->db;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Add an operator-supplied manual entity to a file.
	 *
	 * See the spec for the full contract. High-level flow:
	 *
	 *   1. Verify the actor has write access to the file (throws on denial).
	 *   2. Fetch the file's chunks; if none → throw `ManualEntityException(REASON_FILE_NOT_EXTRACTED)`.
	 *   3. Begin transaction.
	 *   4. Lookup-or-create the catalogue entry for (value, type).
	 *   5. Run the chunk-aware matcher.
	 *   6. For each match: probe `existsForFileAtPosition`; skip if exists, else buffer.
	 *   7. Batch-insert the buffered relation rows.
	 *   8. Write audit-trail entries.
	 *   9. Commit. On any exception in steps 4-8, rollback and re-throw.
	 *
	 * @param ManualEntityRequest $request The file, the operator-supplied value and type,
	 *                                     the match options and the acting user (already
	 *                                     authenticated upstream).
	 *
	 * @return ManualEntityResult The persisted entity + relation rows + match counts.
	 *
	 * @throws ManualEntityException For orchestration-layer failures (file not extracted,
	 *                               regex compile failure, audit-write failure, etc.).
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function addManualEntity(ManualEntityRequest $request): ManualEntityResult {
		$this->assertFileWriteAccess(request: $request);

		// Run the matcher BEFORE opening the transaction: a regex compile
		// failure aborts the operation without any DB writes to roll back.
		$matches = $this->matchFileChunks(request: $request);

		return $this->persistMatches(request: $request, matches: $matches);

	}//end addManualEntity()

	/**
	 * Run the chunk-aware matcher over the file's extracted chunks.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 *
	 * @return array<int, array{chunkId: int, positionStart: int, positionEnd: int, context: string}> The matches.
	 *
	 * @throws ManualEntityException When the file has no chunks or the needle cannot be compiled.
	 */
	private function matchFileChunks(ManualEntityRequest $request): array {
		$chunks = $this->chunkMapper->findBySource(
			sourceType: self::CHUNK_SOURCE_TYPE,
			sourceId: $request->fileId
		);
		if (empty($chunks) === true) {
			throw new ManualEntityException(
				reason: ManualEntityException::REASON_FILE_NOT_EXTRACTED,
				message: sprintf('File %d has no extracted chunks; run text extraction first.', $request->fileId)
			);
		}

		// Derive the effective overlap from the file's own chunks so the
		// matcher's needle-length bound and cross-boundary invariant track
		// whatever `chunk_overlap` the extractor actually used (the value is
		// persisted per chunk). Fall back to the default only for legacy
		// rows that predate the `overlap_size` column.
		$chunkOverlap = $chunks[0]->getOverlapSize();
		if ($chunkOverlap <= 0) {
			$chunkOverlap = self::FALLBACK_CHUNK_OVERLAP_CHARS;
		}

		try {
			return $this->matcher->search(search: new ChunkSearch(
				chunks: $chunks,
				request: $request,
				chunkOverlap: $chunkOverlap
			));
		} catch (ChunkMatcherException $e) {
			// Both `value_too_long` and `regex_compile_failure` map to
			// `regex_compile_failure`; the controller does not distinguish them.
			throw new ManualEntityException(
				reason: ManualEntityException::REASON_REGEX_COMPILE_FAILURE,
				message: $e->getMessage(),
				previous: $e
			);
		}

	}//end matchFileChunks()

	/**
	 * Write the catalogue entry, the relation rows and the audit rows in one transaction.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 * @param array<int, array{chunkId: int, positionStart: int, positionEnd: int, context: string}> $matches The matcher output.
	 *
	 * @return ManualEntityResult The persisted entity, relation rows and match counts.
	 *
	 * @throws ManualEntityException When any write fails; the transaction is rolled back.
	 */
	private function persistMatches(ManualEntityRequest $request, array $matches): ManualEntityResult {
		$this->db->beginTransaction();
		try {
			[$entity, $entityWasNew] = $this->lookupOrCreateEntity(
				value: $request->value,
				type: $request->type
			);

			[$insertedRelations, $matchesSkipped] = $this->createRelationsForMatches(
				placement: new EntityPlacement(fileId: $request->fileId, entity: $entity),
				matches: $matches
			);

			$result = new ManualEntityResult(
				entity: $entity,
				entityWasNew: $entityWasNew,
				relations: $insertedRelations,
				matchesSkipped: $matchesSkipped
			);
			$this->writeAuditTrails(request: $request, result: $result);

			$this->db->commit();
		} catch (Throwable $error) {
			$this->db->rollBack();
			if ($error instanceof ManualEntityException) {
				throw $error;
			}

			throw $this->transactionFailure(request: $request, error: $error);
		}//end try

		return $result;

	}//end persistMatches()

	/**
	 * Log a failed transactional write and wrap it as an internal error.
	 *
	 * ADR-005: the exception message and trace are NOT logged. Both can
	 * surface the operator-supplied `value` (PII): getTraceAsString()
	 * formats call-frame arguments, and a DB driver may embed bound
	 * parameters into the wrapped exception's message. Only PII-safe
	 * structural fields are recorded; the audit trail (ADR-022 forensic
	 * exception) is the sole place `value` lives.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 * @param Throwable $error The root cause.
	 *
	 * @return ManualEntityException The exception to throw.
	 */
	private function transactionFailure(ManualEntityRequest $request, Throwable $error): ManualEntityException {
		$this->logger->error(
			'[ManualEntityService] Transactional manual-entity write failed; rolled back',
			[
				'file' => __FILE__,
				'line' => __LINE__,
				'fileId' => $request->fileId,
				'actor' => $request->actor->getUID(),
				'errorClass' => $error::class,
				'errorCode' => $error->getCode(),
				'errorLocation' => $error->getFile() . ':' . $error->getLine(),
			]
		);

		return new ManualEntityException(
			reason: ManualEntityException::REASON_INTERNAL_ERROR,
			message: 'Transactional manual-entity write failed: ' . $error->getMessage(),
			previous: $error
		);

	}//end transactionFailure()

	/**
	 * Verify the acting user can write the target file.
	 *
	 * Mirrors `EntityRelationsController::canWriteFile`: the file MUST
	 * be reachable in the actor's user-folder and `isUpdateable()`
	 * MUST return true. Denial is reported as a 403 by the controller.
	 *
	 * @param ManualEntityRequest $request The manual-entity input: the Nextcloud file id and the acting user.
	 *
	 * @return void
	 *
	 * @throws ManualEntityException When the file does not exist for the actor or is read-only.
	 */
	private function assertFileWriteAccess(ManualEntityRequest $request): void {
		$nodes = $this->actorNodes(request: $request);

		if (empty($nodes) === true) {
			// No node visible to the actor. Per the spec a write-blocked
			// actor and a missing file are indistinguishable from the
			// operator's perspective (no oracle); the FILE_NOT_EXTRACTED
			// reason is the closest semantic match because either way
			// there's nothing the operator can do without write access
			// / re-extraction.
			throw $this->notVisible(request: $request);
		}

		$node = $nodes[0];
		if (($node instanceof NcFile) === false || $node->isUpdateable() === false) {
			// Read-only access. REASON_FORBIDDEN is the type-checked signal
			// the controller maps to HTTP 403 (vs the default 500 for
			// REASON_INTERNAL_ERROR).
			throw $this->writeDenied(request: $request, node: $node);
		}

	}//end assertFileWriteAccess()

	/**
	 * The nodes with the file's id in the actor's user folder.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 *
	 * @return array The nodes found; empty when the actor cannot see the file.
	 *
	 * @throws ManualEntityException When the lookup itself fails.
	 */
	private function actorNodes(ManualEntityRequest $request): array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($request->actor->getUID());
			return $userFolder->getById($request->fileId);
		} catch (Throwable $e) {
			throw $this->lookupFailure(request: $request, error: $e);
		}

	}//end actorNodes()

	/**
	 * Log a failed file lookup and wrap it as an internal error.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 * @param Throwable           $error   The lookup failure.
	 *
	 * @return ManualEntityException The exception to throw.
	 */
	private function lookupFailure(ManualEntityRequest $request, Throwable $error): ManualEntityException {
		$this->logger->warning(
			'[ManualEntityService] File lookup raised exception',
			['file' => __FILE__, 'line' => __LINE__] + $this->actorFields(request: $request) + ['error' => $error->getMessage()]
		);
		return new ManualEntityException(
			reason: ManualEntityException::REASON_INTERNAL_ERROR,
			message: 'File lookup failed: ' . $error->getMessage(),
			previous: $error
		);

	}//end lookupFailure()

	/**
	 * Log a file the actor cannot see and refuse it as not extracted.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 *
	 * @return ManualEntityException The exception to throw.
	 */
	private function notVisible(ManualEntityRequest $request): ManualEntityException {
		$this->logger->info(
			'[ManualEntityService] File not visible to actor',
			['file' => __FILE__, 'line' => __LINE__] + $this->actorFields(request: $request)
		);
		return new ManualEntityException(
			reason: ManualEntityException::REASON_FILE_NOT_EXTRACTED,
			message: sprintf('File %d is not accessible to the acting user.', $request->fileId)
		);

	}//end notVisible()

	/**
	 * Log a node the actor may not write and refuse it as forbidden.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 * @param mixed               $node    The first node found: not a file, or a read-only file.
	 *
	 * @return ManualEntityException The exception to throw.
	 */
	private function writeDenied(ManualEntityRequest $request, mixed $node): ManualEntityException {
		$this->logger->info(
			'[ManualEntityService] Write-access denied on target file',
			['file' => __FILE__, 'line' => __LINE__] + $this->actorFields(request: $request) + [
				'isFile' => ($node instanceof NcFile),
				'isUpdateable' => self::isUpdateableFile(node: $node),
			]
		);
		return new ManualEntityException(
			reason: ManualEntityException::REASON_FORBIDDEN,
			message: 'write access to file required'
		);

	}//end writeDenied()

	/**
	 * Whether the node is a file the actor may update.
	 *
	 * @param mixed $node The node.
	 *
	 * @return bool
	 */
	private static function isUpdateableFile(mixed $node): bool {
		$isUpdateable = false;
		if ($node instanceof NcFile) {
			$isUpdateable = $node->isUpdateable();
		}

		return $isUpdateable;

	}//end isUpdateableFile()

	/**
	 * The file id and acting user the access log lines carry.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 *
	 * @return array{fileId: int, actor: string}
	 */
	private function actorFields(ManualEntityRequest $request): array {
		return [
			'fileId' => $request->fileId,
			'actor' => $request->actor->getUID(),
		];

	}//end actorFields()

	/**
	 * Find an existing catalogue row by (value, type) or create a new one.
	 *
	 * Category is always derived server-side from `$type` via
	 * `EntityRecognitionHandler::getCategoryForType()` so the catalogue
	 * stays consistent with detector-produced rows. The
	 * `oc_openregister_entities.category` column is NOT NULL with no
	 * default — every insert path MUST set it. Operator override on
	 * category is intentionally not exposed in v1 of this endpoint;
	 * future work can add a `?string $category` parameter back if a
	 * concrete use case emerges.
	 *
	 * @param string $value Entity value.
	 * @param string $type Entity type tag.
	 *
	 * @return array{0: GdprEntity, 1: bool} Tuple of (entity, wasNewlyInserted).
	 */
	private function lookupOrCreateEntity(string $value, string $type): array {
		$existing = $this->gdprEntityMapper->findOneByValueAndType(value: $value, type: $type);
		if ($existing !== null) {
			return [$existing, false];
		}

		// Mirror the detector flow's column population
		// (see `EntityRecognitionHandler::findOrCreateEntity`) — the
		// `category`, `detected_at`, and `updated_at` columns are all
		// NOT NULL without defaults; every insert path must set them.
		$now = new DateTime();
		$entity = new GdprEntity();
		$entity->setUuid(Uuid::v4()->toRfc4122());
		$entity->setValue($value);
		$entity->setType($type);
		$entity->setCategory(EntityRecognitionHandler::getCategoryForType(type: $type));
		$entity->setDetectedAt($now);
		$entity->setUpdatedAt($now);

		$entity = $this->gdprEntityMapper->insert($entity);
		return [$entity, true];
	}//end lookupOrCreateEntity()

	/**
	 * For each match position, insert a new relation row unless one
	 * already exists at exactly that position.
	 *
	 * @param EntityPlacement $placement Target file and the catalogue entry the relations point to.
	 * @param array<int, array{chunkId: int, positionStart: int, positionEnd: int, context: string}> $matches Output of `ChunkTextMatcher::match`.
	 *
	 * @return array{0: EntityRelation[], 1: int} Tuple of (insertedRelations, matchesSkipped).
	 */
	private function createRelationsForMatches(EntityPlacement $placement, array $matches): array {
		$fileId = $placement->fileId;
		$rowsToInsert = [];
		$matchesSkipped = 0;

		foreach ($matches as $match) {
			$mention = EntityMention::atMatch(entityId: $placement->entityId(), match: $match);
			if ($this->entityRelationMapper->existsForFileAtPosition(fileId: $fileId, mention: $mention) === true) {
				$matchesSkipped++;
				continue;
			}

			$rowsToInsert[] = [
				'entityId' => $mention->entityId,
				'fileId' => $fileId,
				'chunkId' => $mention->chunkId,
				'positionStart' => $mention->positionStart,
				'positionEnd' => $mention->positionEnd,
				'context' => $match['context'],
				'detectionMethod' => DetectionMethod::MANUAL,
				'role' => 'anonymisable',
				'confidence' => 1.0,
				'anonymized' => false,
				'skipAnonymization' => false,
				'createdAt' => new DateTime(),
			];
		}//end foreach

		$inserted = $this->entityRelationMapper->insertBatch(rows: $rowsToInsert);
		return [$inserted, $matchesSkipped];
	}//end createRelationsForMatches()

	/**
	 * Write the per-operation audit-trail rows.
	 *
	 * Two action types per the spec:
	 *   - `entity_create`: only when a NEW catalogue row was inserted.
	 *   - `entity_relations_batch_create`: every call (records operator intent
	 *     even when zero matches).
	 *
	 * PII rule: ADR-005 keeps the operator-supplied `value` out of HTTP
	 * logs and error responses. The audit trail is the explicit forensic
	 * exception per ADR-022, so `value` is allowed here.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 * @param ManualEntityResult $result The persisted entity and relation rows.
	 *
	 * @return void
	 */
	private function writeAuditTrails(ManualEntityRequest $request, ManualEntityResult $result): void {
		$now = new DateTime();

		if ($result->entityWasNew === true) {
			// The audit row reads the category from the entity, so the
			// server-derived value is recorded verbatim.
			$entityAudit = $this->newAuditRow(request: $request, created: $now);
			$entityAudit->setAction('entity_create');
			$entityAudit->setChanged(self::entityCreateChange(request: $request, result: $result));
			$this->auditTrailMapper->insert($entityAudit);
		}

		$batchAudit = $this->newAuditRow(request: $request, created: $now);
		$batchAudit->setAction('entity_relations_batch_create');
		$batchAudit->setChanged(self::batchCreateChange(request: $request, result: $result));
		$this->auditTrailMapper->insert($batchAudit);

	}//end writeAuditTrails()

	/**
	 * The `changed` payload of the `entity_create` audit row.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 * @param ManualEntityResult  $result  The persisted entity and relation rows.
	 *
	 * @return array<string, mixed>
	 */
	private static function entityCreateChange(ManualEntityRequest $request, ManualEntityResult $result): array {
		return [
			'subjectType' => 'openregister_entities',
			'subjectId' => (int)$result->entity->getId(),
			'fields' => [
				'value' => $request->value,
				'type' => $request->type,
				'category' => $result->entity->getCategory(),
			],
		];

	}//end entityCreateChange()

	/**
	 * The `changed` payload of the `entity_relations_batch_create` audit row.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 * @param ManualEntityResult  $result  The persisted entity and relation rows.
	 *
	 * @return array<string, mixed>
	 */
	private static function batchCreateChange(ManualEntityRequest $request, ManualEntityResult $result): array {
		return [
			'subjectType' => 'openregister_files',
			'subjectId' => $request->fileId,
			'fields' => [
				'value' => $request->value,
				'type' => $request->type,
				'fileId' => $request->fileId,
				'detectionMethod' => DetectionMethod::MANUAL,
				'matchCount' => (count($result->relations) + $result->matchesSkipped),
				'matchesSkipped' => $result->matchesSkipped,
				'relationIds' => self::relationIds(result: $result),
			],
		];

	}//end batchCreateChange()

	/**
	 * The ids of the relation rows created.
	 *
	 * @param ManualEntityResult $result The persisted entity and relation rows.
	 *
	 * @return list<int>
	 */
	private static function relationIds(ManualEntityResult $result): array {
		return array_map(
			static fn (EntityRelation $r): int => (int)$r->getId(),
			$result->relations
		);

	}//end relationIds()

	/**
	 * A new audit row stamped with a fresh uuid, the actor's UID and the given time.
	 *
	 * @param ManualEntityRequest $request The manual-entity input.
	 * @param DateTime $created The creation time shared by the rows of one call.
	 *
	 * @return AuditTrail The unsaved row.
	 */
	private function newAuditRow(ManualEntityRequest $request, DateTime $created): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setUser($request->actor->getUID());
		$row->setUserName(null);
		$row->setCreated($created);
		return $row;

	}//end newAuditRow()
}//end class
