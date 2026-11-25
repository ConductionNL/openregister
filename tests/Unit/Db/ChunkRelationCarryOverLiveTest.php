<?php

declare(strict_types=1);

/**
 * ChunkRelationCarryOver LIVE tests: real IDBConnection, real SQL.
 *
 * WHAT THIS PROVES. Re-extracting a source replaces its chunks with new ids.
 * Before the carry-over, the relations of the old chunks stayed on the file
 * and detection added a fresh set: every extract of the same document added
 * another copy of every finding (seen live: 31 copies of each value after
 * repeated extracts), and a reviewer's decisions sat on the stale copies.
 * These tests insert probe relations, run the carry-over against the real
 * database and read the rows back.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://conduction.nl
 * @spec openspec/specs/text-extraction/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkRelationCarryOver;
use OCA\OpenRegister\Files\Db\DetectionMethod;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Live (real-database) tests for the relation carry-over.
 *
 * @group DB
 */
class ChunkRelationCarryOverLiveTest extends TestCase {

	/**
	 * Probe chunk ids, far above any real chunk, so nothing else is touched.
	 */
	private const OLD_A = 990000001;
	private const OLD_B = 990000002;
	private const NEW_A = 990000011;

	/**
	 * Probe file id.
	 */
	private const FILE = 990000099;

	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();

		$connection = null;
		if (class_exists(\OC::class) === true && isset(\OC::$server) === true) {
			$connection = \OC::$server->get(IDBConnection::class);
		}

		if (($connection instanceof IDBConnection) === false) {
			$this->markTestSkipped('ChunkRelationCarryOverLiveTest needs a bootstrapped Nextcloud (real IDBConnection).');
		}

		$this->db = $connection;
		$this->clean();
	}//end setUp()

	protected function tearDown(): void {
		if (isset($this->db) === true) {
			$this->clean();
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A relation on an old chunk moves to the new chunk with the same index and
	 * text, its decision intact; nothing is added or lost.
	 *
	 * @return void
	 */
	public function testARelationFollowsItsChunkWithItsDecision(): void {
		$this->relation(chunk: self::OLD_A, entity: 1, skip: true);
		$this->relation(chunk: self::OLD_A, entity: 2);

		$result = (new ChunkRelationCarryOver(db: $this->db))->carry(
			previous: [self::chunk(id: self::OLD_A, index: 0, checksum: 'same')],
			current: [self::chunk(id: self::NEW_A, index: 0, checksum: 'same')]
		);

		self::assertSame(expected: ['moved' => 2, 'dropped' => 0], actual: $result);
		self::assertSame(
			expected: [[self::NEW_A, 1, 1], [self::NEW_A, 2, 0]],
			actual: $this->rows()
		);
	}//end testARelationFollowsItsChunkWithItsDecision()

	/**
	 * A chunk whose text changed has no successor: its undecided and detected
	 * relations go, a decided one and a manual one stay.
	 *
	 * @return void
	 */
	public function testAChangedChunkDropsOnlyWhatNobodyDecided(): void {
		$this->relation(chunk: self::OLD_B, entity: 1);
		$this->relation(chunk: self::OLD_B, entity: 2, skip: true);
		$this->relation(chunk: self::OLD_B, entity: 3, method: DetectionMethod::MANUAL);
		$this->relation(chunk: self::OLD_B, entity: 4, bases: '["art-5-1-e"]');

		$result = (new ChunkRelationCarryOver(db: $this->db))->carry(
			previous: [self::chunk(id: self::OLD_B, index: 0, checksum: 'before')],
			current: [self::chunk(id: self::NEW_A, index: 0, checksum: 'after')]
		);

		self::assertSame(expected: ['moved' => 0, 'dropped' => 1], actual: $result);
		self::assertSame(
			expected: [[self::OLD_B, 2, 1], [self::OLD_B, 3, 0], [self::OLD_B, 4, 0]],
			actual: $this->rows()
		);
	}//end testAChangedChunkDropsOnlyWhatNobodyDecided()

	/**
	 * A probe chunk.
	 *
	 * @param int    $id       The id.
	 * @param int    $index    The chunk index.
	 * @param string $checksum The text checksum.
	 *
	 * @return Chunk
	 */
	private static function chunk(int $id, int $index, string $checksum): Chunk {
		$chunk = new Chunk();
		$chunk->setId($id);
		$chunk->setChunkIndex($index);
		$chunk->setChecksum($checksum);
		$chunk->setTextContent('probe');

		return $chunk;
	}//end chunk()

	/**
	 * Insert a probe relation.
	 *
	 * @param int         $chunk  The chunk id.
	 * @param int         $entity The entity id.
	 * @param bool        $skip   Whether a person skipped it.
	 * @param string      $method The detection method.
	 * @param string|null $bases  The legal bases, as stored.
	 *
	 * @return void
	 */
	private function relation(int $chunk, int $entity, bool $skip=false, string $method='regex', ?string $bases=null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('openregister_entity_relations')->values(
			[
				'entity_id'          => $qb->createNamedParameter($entity, IQueryBuilder::PARAM_INT),
				'chunk_id'           => $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT),
				'file_id'            => $qb->createNamedParameter(self::FILE, IQueryBuilder::PARAM_INT),
				'position_start'     => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'position_end'       => $qb->createNamedParameter(5, IQueryBuilder::PARAM_INT),
				'confidence'         => $qb->createNamedParameter('0.90'),
				'detection_method'   => $qb->createNamedParameter($method),
				'anonymized'         => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'skip_anonymization' => $qb->createNamedParameter($skip, IQueryBuilder::PARAM_BOOL),
				'bases'              => $qb->createNamedParameter($bases),
				'created_at'         => $qb->createNamedParameter(new DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE),
			]
		)->executeStatement();
	}//end relation()

	/**
	 * The probe relations as [chunk, entity, skip], by entity.
	 *
	 * @return array<int, array{0: int, 1: int, 2: int}>
	 */
	private function rows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('chunk_id', 'entity_id', 'skip_anonymization')
			->from('openregister_entity_relations')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter(self::FILE, IQueryBuilder::PARAM_INT)))
			->orderBy('entity_id');
		$rows   = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$rows[] = [(int) $row['chunk_id'], (int) $row['entity_id'], (int) $row['skip_anonymization']];
		}

		$result->closeCursor();

		return $rows;
	}//end rows()

	/**
	 * Remove every probe relation.
	 *
	 * @return void
	 */
	private function clean(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('openregister_entity_relations')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter(self::FILE, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}//end clean()
}//end class
