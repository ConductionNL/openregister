<?php

/**
 * Golden output of FileSidebarService::getExtractionStatus().
 *
 * Pinned on the code before the method was split into small units: the exact
 * keys, their order and their values for a file without chunks, a file without
 * an extraction timestamp, and a file whose entities include an anonymized one,
 * two of one type and one whose GDPR entity is gone.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\FileSidebarEntityStore;
use OCA\OpenRegister\Files\Service\FileSidebarSchemaLookup;
use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Files\Service\FileSidebarService
 */
final class FileSidebarExtractionStatusGoldenTest extends TestCase {

	/**
	 * A file without chunks has not been extracted.
	 *
	 * @return void
	 */
	public function testAFileWithoutChunksIsNotExtracted(): void {
		$service = $this->service(chunks: 0, timestamp: 1700000000, relations: [], risk: 'high');

		$this->assertSame(
			[
				'fileId' => 7, 'extractionStatus' => 'none', 'chunkCount' => 0, 'entityCount' => 0, 'riskLevel' => 'none',
				'extractedAt' => null, 'entities' => [], 'anonymized' => false, 'anonymizedAt' => null, 'anonymizedFileId' => null,
			],
			$service->getExtractionStatus(7)
		);
	}//end testAFileWithoutChunksIsNotExtracted()

	/**
	 * Without a timestamp the extraction date is null; without relations nothing is counted.
	 *
	 * @return void
	 */
	public function testAFileWithoutTimestampOrEntities(): void {
		$service = $this->service(chunks: 3, timestamp: null, relations: [], risk: 'none');

		$this->assertSame(
			[
				'fileId' => 7, 'extractionStatus' => 'completed', 'chunkCount' => 3, 'entityCount' => 0, 'riskLevel' => 'none',
				'extractedAt' => null, 'entities' => [], 'anonymized' => false, 'anonymizedAt' => null, 'anonymizedFileId' => null,
			],
			$service->getExtractionStatus(7)
		);
	}//end testAFileWithoutTimestampOrEntities()

	/**
	 * Entities are counted by type in first-seen order, a gone entity is skipped but still counted
	 * in entityCount, and one anonymized relation marks the file anonymized.
	 *
	 * @return void
	 */
	public function testEntitiesAreCountedByType(): void {
		$service = $this->service(
			chunks: 2,
			timestamp: 1700000000,
			relations: [[1, false], [2, false], [1, true], [99, false]],
			risk: 'medium'
		);

		$this->assertSame(
			[
				'fileId' => 7, 'extractionStatus' => 'completed', 'chunkCount' => 2, 'entityCount' => 4, 'riskLevel' => 'medium',
				'extractedAt' => date('c', 1700000000), 'entities' => [['type' => 'PERSON', 'count' => 2], ['type' => 'EMAIL', 'count' => 1]],
				'anonymized' => true, 'anonymizedAt' => null, 'anonymizedFileId' => null,
			],
			$service->getExtractionStatus(7)
		);
	}//end testEntitiesAreCountedByType()

	/**
	 * The service over a file with this many chunks, this timestamp and these [entityId, anonymized] relations.
	 *
	 * @param int                           $chunks    How many chunks the file has.
	 * @param int|null                      $timestamp The latest chunk update.
	 * @param array<int, array{int, bool}> $relations The file's entity relations.
	 * @param string                        $risk      The risk level.
	 *
	 * @return FileSidebarService
	 */
	private function service(int $chunks, ?int $timestamp, array $relations, string $risk): FileSidebarService {
		$chunkMapper = $this->createMock(ChunkMapper::class);
		$chunkMapper->method('findBySource')->willReturn(array_fill(0, $chunks, new Chunk()));
		$chunkMapper->method('getLatestUpdatedTimestamp')->willReturn($timestamp);

		$rows = [];
		foreach ($relations as [$entityId, $anonymized]) {
			$relation = new EntityRelation();
			$relation->setEntityId($entityId);
			$relation->setAnonymized($anonymized);
			$rows[] = $relation;
		}

		$relationMapper = $this->createMock(EntityRelationMapper::class);
		$relationMapper->method('findByFileId')->willReturn($rows);

		$gdpr = $this->createMock(GdprEntityMapper::class);
		$gdpr->method('find')->willReturnCallback(fn (int $id): GdprEntity => $this->gdprEntity(id: $id));

		$riskLevel = $this->createMock(RiskLevelService::class);
		$riskLevel->method('getRiskLevel')->willReturn($risk);

		return new FileSidebarService(
			new FileSidebarSchemaLookup($this->createMock(RegisterMapper::class), $this->createMock(SchemaMapper::class)),
			new FileSidebarEntityStore($this->createMock(IDBConnection::class), $chunkMapper, $relationMapper, $gdpr),
			$riskLevel,
			new NullLogger()
		);
	}//end service()

	/**
	 * GDPR entity 1 is a PERSON, 2 an EMAIL; any other id is gone.
	 *
	 * @param int $id The entity id.
	 *
	 * @return GdprEntity
	 */
	private function gdprEntity(int $id): GdprEntity {
		$types = [1 => 'PERSON', 2 => 'EMAIL'];
		if (isset($types[$id]) === false) {
			throw new DoesNotExistException('gone');
		}

		$entity = new GdprEntity();
		$entity->setType($types[$id]);
		return $entity;
	}//end gdprEntity()
}//end class
