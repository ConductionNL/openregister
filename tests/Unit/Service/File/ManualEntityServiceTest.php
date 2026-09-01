<?php

/**
 * Unit tests for `ManualEntityService`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests\Unit\Service\File
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\DetectionMethod;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Exception\ManualEntityException;
use OCA\OpenRegister\Service\File\ChunkTextMatcher;
use OCA\OpenRegister\Service\File\ManualEntityService;
use OCP\Files\File as NcFile;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the orchestration paths of the manual-entity write flow.
 *
 * The matcher itself is the real `ChunkTextMatcher` (the algorithm is
 * exercised in its own test); everything DB-bound and FS-bound is mocked.
 */
class ManualEntityServiceTest extends TestCase {

	/**
	 * Catalogue (value, type) lookup-or-insert mapper mock.
	 *
	 * @var GdprEntityMapper&MockObject
	 */
	private GdprEntityMapper&MockObject $gdprMapper;

	/**
	 * EntityRelation persistence mock.
	 *
	 * @var EntityRelationMapper&MockObject
	 */
	private EntityRelationMapper&MockObject $relationMapper;

	/**
	 * Chunk reader mock for the target file.
	 *
	 * @var ChunkMapper&MockObject
	 */
	private ChunkMapper&MockObject $chunkMapper;

	/**
	 * Audit-trail mapper mock (entity_create / entity_relations_batch_create rows).
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $auditMapper;

	/**
	 * NC root folder mock used by the write-access check.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * DB connection mock used for explicit transaction control.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Structured log sink mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * SUT under test.
	 *
	 * @var ManualEntityService
	 */
	private ManualEntityService $service;

	/**
	 * Boot the dependency mocks and the SUT for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->gdprMapper = $this->createMock(originalClassName: GdprEntityMapper::class);
		$this->relationMapper = $this->createMock(originalClassName: EntityRelationMapper::class);
		$this->chunkMapper = $this->createMock(originalClassName: ChunkMapper::class);
		$this->auditMapper = $this->createMock(originalClassName: AuditTrailMapper::class);
		$this->rootFolder = $this->createMock(originalClassName: IRootFolder::class);
		$this->db = $this->createMock(originalClassName: IDBConnection::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new ManualEntityService(
			gdprEntityMapper: $this->gdprMapper,
			entityRelationMapper: $this->relationMapper,
			chunkMapper: $this->chunkMapper,
			matcher: new ChunkTextMatcher(),
			auditTrailMapper: $this->auditMapper,
			rootFolder: $this->rootFolder,
			db: $this->db,
			logger: $this->logger
		);

	}//end setUp()

	/**
	 * Wire the user-folder / file lookup mocks so the actor is treated
	 * as having write access to the file.
	 *
	 * @param int $fileId File id to expose to the actor.
	 * @param bool $isUpdateable Whether the resolved node is writable.
	 *
	 * @return IUser&MockObject
	 */
	private function setupWritableFile(int $fileId, bool $isUpdateable = true): IUser&MockObject {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(value: 'op1');

		$file = $this->createMock(originalClassName: NcFile::class);
		$file->method('isUpdateable')->willReturn(value: $isUpdateable);

		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getById')->with($fileId)->willReturn(value: [$file]);

		$this->rootFolder->method('getUserFolder')
			->with('op1')
			->willReturn(value: $folder);

		return $user;
	}//end setupWritableFile()

	/**
	 * Build a minimal `Chunk` entity with the fields the matcher reads.
	 *
	 * @param int $id Chunk row id.
	 * @param string $text Chunk text.
	 * @param int $start Absolute start offset.
	 * @param int $index Chunk index (for dedup tiebreak).
	 *
	 * @return Chunk
	 */
	private function makeChunk(int $id, string $text, int $start = 0, int $index = 0): Chunk {
		$c = new Chunk();
		$c->setId($id);
		$c->setTextContent($text);
		$c->setStartOffset($start);
		$c->setChunkIndex($index);
		return $c;
	}//end makeChunk()

	/**
	 * Happy path: previously-unseen value, single match.
	 *
	 * Asserts:
	 *   - catalogue lookup returns null,
	 *   - new GdprEntity is inserted,
	 *   - one EntityRelation row is batch-inserted with detectionMethod=manual,
	 *   - two audit trails are written (entity_create + relations_batch),
	 *   - transaction is committed.
	 *
	 * @return void
	 */
	public function testHappyPathNewEntity(): void {
		$fileId = 42;
		$actor = $this->setupWritableFile(fileId: $fileId);

		$this->chunkMapper->method('findBySource')
			->with('file', $fileId)
			->willReturn(value: [$this->makeChunk(id: 100, text: 'Aanvraag van Jan Jansen.')]);

		// No existing catalogue row → insert.
		$this->gdprMapper->expects($this->once())
			->method('findOneByValueAndType')
			->with('Jan Jansen', 'PERSON')
			->willReturn(value: null);

		$insertedEntity = new GdprEntity();
		$insertedEntity->setId(7);
		$this->gdprMapper->expects($this->once())
			->method('insert')
			->willReturn(value: $insertedEntity);

		// No existing relation at the matched position.
		$this->relationMapper->expects($this->once())
			->method('existsForFileAtPosition')
			->willReturn(value: false);

		$insertedRelation = new EntityRelation();
		$insertedRelation->setId(200);
		$this->relationMapper->expects($this->once())
			->method('insertBatch')
			->willReturnCallback(
				callback: function (array $rows) use ($insertedRelation): array {
					$this->assertCount(expectedCount: 1, haystack: $rows);
					$this->assertSame(expected: DetectionMethod::MANUAL, actual: $rows[0]['detectionMethod']);
					$this->assertSame(expected: 1.0, actual: $rows[0]['confidence']);
					$this->assertFalse(condition: $rows[0]['anonymized']);
					$this->assertSame(expected: 'anonymisable', actual: $rows[0]['role']);
					return [$insertedRelation];
				}
			);

		// Two audit trails written.
		$this->auditMapper->expects($this->exactly(count: 2))
			->method('insert')
			->willReturnArgument(argumentIndex: 0);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->addManualEntity(
			fileId: $fileId,
			value: 'Jan Jansen',
			type: 'PERSON',
			wholeWord: true,
			caseSensitive: true,
			actor: $actor
		);

		$this->assertTrue(condition: $result->entityWasNew);
		$this->assertCount(expectedCount: 1, haystack: $result->relations);
		$this->assertSame(expected: 1, actual: $result->matchCount);
		$this->assertSame(expected: 0, actual: $result->matchesSkipped);

	}//end testHappyPathNewEntity()

	/**
	 * Reuse path: catalogue entry already exists for (value, type).
	 *
	 * Asserts:
	 *   - no new GdprEntity insert,
	 *   - one EntityRelation insert,
	 *   - only ONE audit trail (the batch row — no entity_create row).
	 *
	 * @return void
	 */
	public function testReuseExistingEntity(): void {
		$fileId = 42;
		$actor = $this->setupWritableFile(fileId: $fileId);

		$this->chunkMapper->method('findBySource')->willReturn(
			value: [$this->makeChunk(id: 100, text: 'Aanvraag van Jan Jansen.')]
		);

		$existing = new GdprEntity();
		$existing->setId(7);
		$this->gdprMapper->expects($this->once())
			->method('findOneByValueAndType')
			->willReturn(value: $existing);

		// Insert must NOT be called when reusing.
		$this->gdprMapper->expects($this->never())->method('insert');

		$this->relationMapper->method('existsForFileAtPosition')->willReturn(value: false);
		$this->relationMapper->method('insertBatch')->willReturn(value: [new EntityRelation()]);

		// Exactly one audit trail row.
		$this->auditMapper->expects($this->once())
			->method('insert')
			->willReturnArgument(argumentIndex: 0);

		$this->db->expects($this->once())->method('commit');

		$result = $this->service->addManualEntity(
			fileId: $fileId,
			value: 'Jan Jansen',
			type: 'PERSON',
			wholeWord: true,
			caseSensitive: true,
			actor: $actor
		);

		$this->assertFalse(condition: $result->entityWasNew);

	}//end testReuseExistingEntity()

	/**
	 * Idempotent retry: every match position already has a relation
	 * row. matchesSkipped equals matchCount; insertBatch receives zero
	 * rows but still returns an empty list.
	 *
	 * @return void
	 */
	public function testIdempotentRetrySkipsAll(): void {
		$fileId = 42;
		$actor = $this->setupWritableFile(fileId: $fileId);

		$this->chunkMapper->method('findBySource')->willReturn(
			value: [
				$this->makeChunk(id: 100, text: 'Jan, Jan, Jan.'),
			]
		);

		$existing = new GdprEntity();
		$existing->setId(7);
		$this->gdprMapper->method('findOneByValueAndType')->willReturn(value: $existing);

		// All probes return true → every match is a skip.
		$this->relationMapper->expects($this->exactly(count: 3))
			->method('existsForFileAtPosition')
			->willReturn(value: true);

		// InsertBatch still called once with an empty rows array.
		$this->relationMapper->expects($this->once())
			->method('insertBatch')
			->willReturnCallback(
				callback: function (array $rows): array {
					$this->assertSame(expected: [], actual: $rows);
					return [];
				}
			);

		$this->auditMapper->method('insert')->willReturnArgument(argumentIndex: 0);
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->addManualEntity(
			fileId: $fileId,
			value: 'Jan',
			type: 'PERSON',
			wholeWord: true,
			caseSensitive: true,
			actor: $actor
		);

		$this->assertSame(expected: 3, actual: $result->matchCount);
		$this->assertSame(expected: 3, actual: $result->matchesSkipped);
		$this->assertSame(expected: [], actual: $result->relations);

	}//end testIdempotentRetrySkipsAll()

	/**
	 * Zero-match: the value is not present in any chunk. The contract
	 * still requires the catalogue write + audit row.
	 *
	 * @return void
	 */
	public function testZeroMatchStillRecords(): void {
		$fileId = 42;
		$actor = $this->setupWritableFile(fileId: $fileId);

		$this->chunkMapper->method('findBySource')->willReturn(
			value: [$this->makeChunk(id: 100, text: 'nothing relevant here')]
		);

		$this->gdprMapper->method('findOneByValueAndType')->willReturn(value: null);
		$inserted = new GdprEntity();
		$inserted->setId(9);
		$this->gdprMapper->expects($this->once())
			->method('insert')
			->willReturn(value: $inserted);

		$this->relationMapper->expects($this->never())->method('existsForFileAtPosition');
		$this->relationMapper->expects($this->once())
			->method('insertBatch')
			->willReturnCallback(
				callback: function (array $rows): array {
					$this->assertSame(expected: [], actual: $rows);
					return [];
				}
			);

		$this->auditMapper->expects($this->exactly(count: 2))->method('insert');
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->addManualEntity(
			fileId: $fileId,
			value: 'Jan Jansen',
			type: 'PERSON',
			wholeWord: true,
			caseSensitive: true,
			actor: $actor
		);

		$this->assertSame(expected: 0, actual: $result->matchCount);

	}//end testZeroMatchStillRecords()

	/**
	 * File has no extracted chunks → 422 / REASON_FILE_NOT_EXTRACTED.
	 * No transaction is opened.
	 *
	 * @return void
	 */
	public function testFileNotExtractedThrows(): void {
		$fileId = 42;
		$actor = $this->setupWritableFile(fileId: $fileId);

		$this->chunkMapper->method('findBySource')->willReturn(value: []);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->gdprMapper->expects($this->never())->method('findOneByValueAndType');

		try {
			$this->service->addManualEntity(
				fileId: $fileId,
				value: 'X',
				type: 'PERSON',
				wholeWord: true,
				caseSensitive: true,
				actor: $actor
			);
			$this->fail(message: 'Expected ManualEntityException');
		} catch (ManualEntityException $e) {
			$this->assertSame(
				expected: ManualEntityException::REASON_FILE_NOT_EXTRACTED,
				actual: $e->getReason()
			);
		}

	}//end testFileNotExtractedThrows()

	/**
	 * File is read-only for the actor → REASON_FORBIDDEN (controller
	 * maps to HTTP 403). No transaction.
	 *
	 * @return void
	 */
	public function testReadOnlyFileForbidden(): void {
		$fileId = 42;
		$actor = $this->setupWritableFile(fileId: $fileId, isUpdateable: false);

		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->service->addManualEntity(
				fileId: $fileId,
				value: 'X',
				type: 'PERSON',
				wholeWord: true,
				caseSensitive: true,
				actor: $actor
			);
			$this->fail(message: 'Expected ManualEntityException');
		} catch (ManualEntityException $e) {
			$this->assertSame(
				expected: ManualEntityException::REASON_FORBIDDEN,
				actual: $e->getReason()
			);
		}

	}//end testReadOnlyFileForbidden()

	/**
	 * File not visible at all in the actor's user-folder →
	 * REASON_FILE_NOT_EXTRACTED (per the spec, no oracle between
	 * "missing" and "no-access").
	 *
	 * @return void
	 */
	public function testFileMissingForActor(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(value: 'op1');

		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getById')->willReturn(value: []);

		$this->rootFolder->method('getUserFolder')->willReturn(value: $folder);

		try {
			$this->service->addManualEntity(
				fileId: 42,
				value: 'X',
				type: 'PERSON',
				wholeWord: true,
				caseSensitive: true,
				actor: $user
			);
			$this->fail(message: 'Expected ManualEntityException');
		} catch (ManualEntityException $e) {
			$this->assertSame(
				expected: ManualEntityException::REASON_FILE_NOT_EXTRACTED,
				actual: $e->getReason()
			);
		}

	}//end testFileMissingForActor()

	/**
	 * Audit-write failure inside the transaction → rollBack + the
	 * underlying error is wrapped as REASON_INTERNAL_ERROR. The
	 * relation insert must already have happened (called once).
	 *
	 * @return void
	 */
	public function testAuditFailureRollsBack(): void {
		$fileId = 42;
		$actor = $this->setupWritableFile(fileId: $fileId);

		$this->chunkMapper->method('findBySource')->willReturn(
			value: [$this->makeChunk(id: 100, text: 'Aanvraag van Jan Jansen.')]
		);

		$existing = new GdprEntity();
		$existing->setId(7);
		$this->gdprMapper->method('findOneByValueAndType')->willReturn(value: $existing);

		$this->relationMapper->method('existsForFileAtPosition')->willReturn(value: false);
		$this->relationMapper->method('insertBatch')->willReturn(value: [new EntityRelation()]);

		$this->auditMapper->method('insert')
			->willThrowException(exception: new RuntimeException(message: 'audit insert failed'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->service->addManualEntity(
				fileId: $fileId,
				value: 'Jan Jansen',
				type: 'PERSON',
				wholeWord: true,
				caseSensitive: true,
				actor: $actor
			);
			$this->fail(message: 'Expected ManualEntityException');
		} catch (ManualEntityException $e) {
			$this->assertSame(
				expected: ManualEntityException::REASON_INTERNAL_ERROR,
				actual: $e->getReason()
			);
		}

	}//end testAuditFailureRollsBack()
}//end class
