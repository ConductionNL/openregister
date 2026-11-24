<?php

/**
 * EntityRelationWithBasesReadTest — the paged read of relations that carry bases.
 *
 * Pins the query findWithBases() builds (bases not null, id above the cursor,
 * id order, the page size clamped to 1..1000) and that its rows come back as
 * EntityRelation entities with their bases decoded.
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
use OCA\OpenRegister\Db\EntityRelation;
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
 * @uses \OCA\OpenRegister\Db\EntityRelation
 * @uses \OCA\OpenRegister\Service\Registers\EntityEventPublisher
 */
final class EntityRelationWithBasesReadTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * One page after a cursor: the query and the entities it maps to.
	 *
	 * @return void
	 */
	public function testAPageAfterTheCursorInIdOrder(): void {
		$this->queuedRows = [[
			['id' => 8, 'entity_id' => 3, 'file_id' => 41, 'bases' => '["art-5-1-2-e"]'],
			['id' => 9, 'entity_id' => 4, 'file_id' => 41, 'bases' => '[]'],
		]];

		$relations = $this->mapper()->findWithBases(afterId: 7, limit: 2);

		$this->assertCount(2, $relations);
		$this->assertContainsOnlyInstancesOf(EntityRelation::class, $relations);
		$this->assertSame([8, 9], array_map(static fn (EntityRelation $relation): int => $relation->getId(), $relations));
		$this->assertSame(['art-5-1-2-e'], $relations[0]->getBases());
		$this->assertSame(
			[
				'#1 select(*)',
				'#1 from(openregister_entity_relations, NULL)',
				'#1 expr.isNotNull(bases)',
				'#1 where(isNotNull(bases))',
				'#1 param :p1 = 7, 1',
				'#1 expr.gt(id, :p1, NULL)',
				'#1 andWhere(gt(id, :p1, NULL))',
				'#1 orderBy(id, ASC)',
				'#1 setMaxResults(2)',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
	}//end testAPageAfterTheCursorInIdOrder()

	/**
	 * The page size is clamped to 1..1000 and the default cursor is 0.
	 *
	 * @return void
	 */
	public function testThePageSizeIsClamped(): void {
		$this->queuedRows = [[], [], []];
		$mapper = $this->mapper();

		$this->assertSame([], $mapper->findWithBases(limit: 0));
		$this->assertSame([], $mapper->findWithBases(limit: 50000));
		$this->assertSame([], $mapper->findWithBases());

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
