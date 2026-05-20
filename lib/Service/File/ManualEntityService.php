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
 * @spec openspec/changes/manual-entity-anonymisation/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\DetectionMethod;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Exception\ChunkMatcherException;
use OCA\OpenRegister\Exception\ManualEntityException;
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
class ManualEntityService
{
    /**
     * Chunk overlap used by `TextExtractionService` (chars). Needles
     * longer than this cannot reliably be matched per-chunk and are
     * rejected upstream by `ChunkTextMatcher`.
     */
    private const CHUNK_OVERLAP_CHARS = 200;

    /**
     * Source-type tag used by `ChunkMapper::findBySource` for file-
     * scoped chunks. Mirrors the convention already in use across
     * `TextExtractionService`, `FileSidebarService`, etc.
     */
    private const CHUNK_SOURCE_TYPE = 'file';

    /**
     * Constructor.
     *
     * @param GdprEntityMapper     $gdprEntityMapper     Catalogue mapper.
     * @param EntityRelationMapper $entityRelationMapper Relation mapper.
     * @param ChunkMapper          $chunkMapper          Chunk reader for the target file.
     * @param ChunkTextMatcher     $matcher              Chunk-aware string-match utility.
     * @param AuditTrailMapper     $auditTrailMapper     Audit-trail persistence.
     * @param IRootFolder          $rootFolder           Nextcloud root folder (for file-write access check).
     * @param IDBConnection        $db                   Connection for explicit transaction control.
     * @param LoggerInterface      $logger               Structured log sink for write-access failures and
     *                                                   forensic context on rollback paths.
     */
    public function __construct(
        private readonly GdprEntityMapper $gdprEntityMapper,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly ChunkMapper $chunkMapper,
        private readonly ChunkTextMatcher $matcher,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IRootFolder $rootFolder,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {

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
     * @param int         $fileId        Nextcloud file id the manual entity applies to.
     * @param string      $value         Operator-supplied text to add as an anonymisable entity.
     * @param string      $type          Entity type tag (e.g. `PERSON`, `ORGANIZATION`).
     * @param string|null $category      Optional finer-grained classification within `$type`.
     * @param bool        $wholeWord     Wrap the needle in `\b...\b` regex boundaries when true.
     * @param bool        $caseSensitive Use case-sensitive matching when true.
     * @param IUser       $actor         The acting user (already authenticated upstream).
     *
     * @return ManualEntityResult The persisted entity + relation rows + match counts.
     *
     * @throws ManualEntityException For orchestration-layer failures (file not extracted,
     *                               regex compile failure, audit-write failure, etc.).
     */
    public function addManualEntity(
        int $fileId,
        string $value,
        string $type,
        ?string $category,
        bool $wholeWord,
        bool $caseSensitive,
        IUser $actor
    ): ManualEntityResult {
        $this->assertFileWriteAccess(fileId: $fileId, actor: $actor);

        $chunks = $this->chunkMapper->findBySource(
            sourceType: self::CHUNK_SOURCE_TYPE,
            sourceId: $fileId
        );
        if (empty($chunks) === true) {
            throw new ManualEntityException(
                reason: ManualEntityException::REASON_FILE_NOT_EXTRACTED,
                message: sprintf('File %d has no extracted chunks; run text extraction first.', $fileId)
            );
        }

        // Run the matcher BEFORE opening the transaction — a regex
        // compile failure here aborts the operation without any DB
        // writes to roll back.
        try {
            $matches = $this->matcher->match(
                chunks: $chunks,
                needle: $value,
                wholeWord: $wholeWord,
                caseSensitive: $caseSensitive,
                chunkOverlap: self::CHUNK_OVERLAP_CHARS
            );
        } catch (ChunkMatcherException $e) {
            // Translate to the orchestration-layer exception. Both
            // `value_too_long` and `regex_compile_failure` are mapped
            // to `regex_compile_failure` here for the controller's
            // 400 → response shape simplicity; the controller doesn't
            // need to distinguish.
            throw new ManualEntityException(
                reason: ManualEntityException::REASON_REGEX_COMPILE_FAILURE,
                message: $e->getMessage(),
                previous: $e
            );
        }

        $this->db->beginTransaction();
        try {
            [$entity, $entityWasNew] = $this->lookupOrCreateEntity(
                value: $value,
                type: $type,
                category: $category
            );

            [$insertedRelations, $matchesSkipped] = $this->createRelationsForMatches(
                fileId: $fileId,
                entity: $entity,
                matches: $matches
            );

            $this->writeAuditTrails(
                entity: $entity,
                entityWasNew: $entityWasNew,
                fileId: $fileId,
                value: $value,
                type: $type,
                category: $category,
                insertedRelations: $insertedRelations,
                matchesSkipped: $matchesSkipped,
                actor: $actor
            );

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            if ($error instanceof ManualEntityException) {
                throw $error;
            }

            throw new ManualEntityException(
                reason: ManualEntityException::REASON_INTERNAL_ERROR,
                message: 'Transactional manual-entity write failed: '.$error->getMessage(),
                previous: $error
            );
        }//end try

        return new ManualEntityResult(
            entity: $entity,
            entityWasNew: $entityWasNew,
            relations: $insertedRelations,
            matchCount: count($matches),
            matchesSkipped: $matchesSkipped
        );

    }//end addManualEntity()

    /**
     * Verify the acting user can write the target file.
     *
     * Mirrors `EntityRelationsController::canWriteFile`: the file MUST
     * be reachable in the actor's user-folder and `isUpdateable()`
     * MUST return true. Denial is reported as a 403 by the controller.
     *
     * @param int   $fileId Nextcloud file id.
     * @param IUser $actor  Acting user.
     *
     * @return void
     *
     * @throws ManualEntityException When the file does not exist for the actor or is read-only.
     */
    private function assertFileWriteAccess(int $fileId, IUser $actor): void
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($actor->getUID());
            $nodes      = $userFolder->getById($fileId);
        } catch (Throwable $e) {
            $this->logger->warning(
                '[ManualEntityService] File lookup raised exception',
                [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'fileId' => $fileId,
                    'actor'  => $actor->getUID(),
                    'error'  => $e->getMessage(),
                ]
            );
            throw new ManualEntityException(
                reason: ManualEntityException::REASON_INTERNAL_ERROR,
                message: 'File lookup failed: '.$e->getMessage(),
                previous: $e
            );
        }

        if (empty($nodes) === true) {
            // No node visible to the actor. Per the spec a write-blocked
            // actor and a missing file are indistinguishable from the
            // operator's perspective (no oracle); the FILE_NOT_EXTRACTED
            // reason is the closest semantic match because either way
            // there's nothing the operator can do without write access
            // / re-extraction.
            $this->logger->info(
                '[ManualEntityService] File not visible to actor',
                [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'fileId' => $fileId,
                    'actor'  => $actor->getUID(),
                ]
            );
            throw new ManualEntityException(
                reason: ManualEntityException::REASON_FILE_NOT_EXTRACTED,
                message: sprintf('File %d is not accessible to the acting user.', $fileId)
            );
        }//end if

        $node = $nodes[0];
        if (($node instanceof NcFile) === false || $node->isUpdateable() === false) {
            // Read-only access. The `forbidden:` message prefix tells the
            // controller layer to map this to HTTP 403 (vs the default
            // 500 for REASON_INTERNAL_ERROR).
            $this->logger->info(
                '[ManualEntityService] Write-access denied on target file',
                [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'fileId'       => $fileId,
                    'actor'        => $actor->getUID(),
                    'isFile'       => ($node instanceof NcFile),
                    'isUpdateable' => ($node instanceof NcFile ? $node->isUpdateable() : false),
                ]
            );
            throw new ManualEntityException(
                reason: ManualEntityException::REASON_INTERNAL_ERROR,
                message: 'forbidden: write access to file required'
            );
        }

    }//end assertFileWriteAccess()

    /**
     * Find an existing catalogue row by (value, type) or create a new one.
     *
     * @param string      $value    Entity value.
     * @param string      $type     Entity type tag.
     * @param string|null $category Optional category. Only used on new inserts;
     *                              an existing row's category is preserved.
     *
     * @return array{0: GdprEntity, 1: bool} Tuple of (entity, wasNewlyInserted).
     */
    private function lookupOrCreateEntity(string $value, string $type, ?string $category): array
    {
        $existing = $this->gdprEntityMapper->findOneByValueAndType(value: $value, type: $type);
        if ($existing !== null) {
            return [$existing, false];
        }

        $entity = new GdprEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setValue($value);
        $entity->setType($type);
        if ($category !== null && $category !== '') {
            $entity->setCategory($category);
        }

        $entity->setDetectedAt(new DateTime());

        $entity = $this->gdprEntityMapper->insert($entity);
        return [$entity, true];

    }//end lookupOrCreateEntity()

    /**
     * For each match position, insert a new relation row unless one
     * already exists at exactly that position.
     *
     * @param int                                                                                    $fileId  Target file id.
     * @param GdprEntity                                                                             $entity  Catalogue entry the relations point to.
     * @param array<int, array{chunkId: int, positionStart: int, positionEnd: int, context: string}> $matches Output of `ChunkTextMatcher::match`.
     *
     * @return array{0: EntityRelation[], 1: int} Tuple of (insertedRelations, matchesSkipped).
     */
    private function createRelationsForMatches(int $fileId, GdprEntity $entity, array $matches): array
    {
        $rowsToInsert   = [];
        $matchesSkipped = 0;

        foreach ($matches as $match) {
            $existsAlready = $this->entityRelationMapper->existsForFileAtPosition(
                fileId: $fileId,
                entityId: (int) $entity->getId(),
                chunkId: $match['chunkId'],
                positionStart: $match['positionStart'],
                positionEnd: $match['positionEnd']
            );
            if ($existsAlready === true) {
                $matchesSkipped++;
                continue;
            }

            $rowsToInsert[] = [
                'entityId'          => (int) $entity->getId(),
                'fileId'            => $fileId,
                'chunkId'           => $match['chunkId'],
                'positionStart'     => $match['positionStart'],
                'positionEnd'       => $match['positionEnd'],
                'context'           => $match['context'],
                'detectionMethod'   => DetectionMethod::MANUAL,
                'role'              => 'anonymisable',
                'confidence'        => 1.0,
                'anonymized'        => false,
                'skipAnonymization' => false,
                'createdAt'         => new DateTime(),
            ];
        }//end foreach

        $inserted = $this->entityRelationMapper->insertBatch(rows: $rowsToInsert);
        return [$inserted, $matchesSkipped];

    }//end createRelationsForMatches()

    /**
     * Write the per-operation audit-trail rows.
     *
     * Two action types per the spec:
     *   - `entity_create` — only when a NEW catalogue row was inserted.
     *   - `entity_relations_batch_create` — every call (records operator intent
     *     even when zero matches).
     *
     * PII rule: ADR-005 keeps the operator-supplied `value` out of HTTP
     * logs and error responses. The audit trail is the explicit forensic
     * exception per ADR-022 — `value` is allowed here.
     *
     * @param GdprEntity       $entity            Catalogue entry referenced by the relations.
     * @param bool             $entityWasNew      Whether `$entity` was just inserted (vs reused).
     * @param int              $fileId            Target file id.
     * @param string           $value             Operator-supplied value (PII, audit-only).
     * @param string           $type              Entity type tag.
     * @param string|null      $category          Optional category.
     * @param EntityRelation[] $insertedRelations Relations created by this call.
     * @param int              $matchesSkipped    How many matches were skipped because the
     *                                            relation row already existed.
     * @param IUser            $actor             Acting user (UID-only is what gets persisted).
     *
     * @return void
     */
    private function writeAuditTrails(
        GdprEntity $entity,
        bool $entityWasNew,
        int $fileId,
        string $value,
        string $type,
        ?string $category,
        array $insertedRelations,
        int $matchesSkipped,
        IUser $actor
    ): void {
        $userId = $actor->getUID();
        $now    = new DateTime();

        if ($entityWasNew === true) {
            $entityAudit = new AuditTrail();
            $entityAudit->setUuid(Uuid::v4()->toRfc4122());
            $entityAudit->setAction('entity_create');
            $entityAudit->setUser($userId);
            $entityAudit->setUserName(null);
            $entityAudit->setCreated($now);
            $entityAudit->setChanged(
                [
                    'subjectType' => 'openregister_entities',
                    'subjectId'   => (int) $entity->getId(),
                    'fields'      => [
                        'value'    => $value,
                        'type'     => $type,
                        'category' => $category,
                    ],
                ]
            );
            $this->auditTrailMapper->insert($entityAudit);
        }

        $relationIds = array_map(
            static fn (EntityRelation $r): int => (int) $r->getId(),
            $insertedRelations
        );

        $batchAudit = new AuditTrail();
        $batchAudit->setUuid(Uuid::v4()->toRfc4122());
        $batchAudit->setAction('entity_relations_batch_create');
        $batchAudit->setUser($userId);
        $batchAudit->setUserName(null);
        $batchAudit->setCreated($now);
        $batchAudit->setChanged(
            [
                'subjectType' => 'openregister_files',
                'subjectId'   => $fileId,
                'fields'      => [
                    'value'           => $value,
                    'type'            => $type,
                    'fileId'          => $fileId,
                    'detectionMethod' => DetectionMethod::MANUAL,
                    'matchCount'      => (count($insertedRelations) + $matchesSkipped),
                    'matchesSkipped'  => $matchesSkipped,
                    'relationIds'     => $relationIds,
                ],
            ]
        );
        $this->auditTrailMapper->insert($batchAudit);

    }//end writeAuditTrails()
}//end class
