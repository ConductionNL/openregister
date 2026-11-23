<?php

/**
 * EntityRelationFileIdsReadTest — the paged read of the files that hold entities.
 *
 * Pins the query findFileIdsWithEntities() builds (distinct file ids, not
 * null, above the cursor, ascending, the page size clamped to 1..1000) and
 * that it answers the ids as integers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\EntityRelationMapper
 * @uses \OCA\OpenRegister\Service\Registers\EntityEventPublisher
 */
final class EntityRelationFileIdsReadTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * One page after a cursor: the query and the ids it answers.
	 *
	 * @return void
	 */
	public function testAPageOfFileIdsAfterTheCursor(): void {
		$this->queuedRows = [[['file_id' => '41'], ['file_id' => 57]]];

		$ids = $this->mapper()->findFileIdsWithEntities(afterFileId: 40, limit: 2);

		$this->assertSame([41, 57], $ids);
		$this->assertSame(
			[
				'#1 selectDistinct(file_id)',
				'#1 from(openregister_entity_relations, NULL)',
				'#1 expr.isNotNull(file_id)',
				'#1 where(isNotNull(file_id))',
				'#1 param :p1 = 40, 1',
				'#1 expr.gt(file_id, :p1, NULL)',
				'#1 andWhere(gt(file_id, :p1, NULL))',
				'#1 orderBy(file_id, ASC)',
				'#1 setMaxResults(2)',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
	}//end testAPageOfFileIdsAfterTheCursor()

	/**
	 * The page size is clamped to 1..1000 and the default cursor is 0.
	 *
	 * @return void
	 */
	public function testThePageSizeIsClamped(): void {
		$this->queuedRows = [[], [], []];
		$mapper = $this->mapper();

		$this->assertSame([], $mapper->findFileIdsWithEntities(limit: 0));
		$this->assertSame([], $mapper->findFileIdsWithEntities(limit: 50000));
		$this->assertSame([], $mapper->findFileIdsWithEntities());

		$this->assertSame(
			['#1 setMaxResults(1)', '#2 setMaxResults(1000)', '#3 setMaxResults(500)'],
			array_values(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, 'setMaxResults')))
		);
		$this->assertContains('#3 param :p3 = 0, 1', $this->queryLog);
	}//end testThePageSizeIsClamped()

	/**
	 * The mapper over a recording query builder.
	 *
	 * @return EntityRelationMapper
	 */
	private function mapper(): EntityRelationMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		return new EntityRelationMapper(
			$db,
			$this->createMock(AuditTrailMapperInterface::class),
			$this->createMock(IUserSession::class),
			new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)),
			new NullLogger()
		);
	}//end mapper()
}//end class
