<?php

/**
 * EntityRelationAnonymisedReadGoldenTest — the anonymised-entity read and the bases comparison.
 *
 * Pins the exact query findAnonymisedEntitiesWithBasesForFile() builds and how it
 * normalises each row (the JSON `bases` column and the boolean flags), and every
 * outcome of the private multiset comparison basesAreEqual() that decides whether
 * a decision-metadata write changed the redaction grounds.
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
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\EntityRelationMapper
 */
final class EntityRelationAnonymisedReadGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The query and every row normalisation.
	 *
	 * @return void
	 */
	public function testAnonymisedReadQueryAndRowNormalisation(): void {
		$this->queuedRows = [[
			['relation_id' => 1, 'bases' => '["a","b"]', 'skip_anonymization' => 0, 'anonymized' => 1],
			['relation_id' => 2, 'bases' => '{"k":"v"}', 'skip_anonymization' => '1', 'anonymized' => '0'],
			['relation_id' => 3, 'bases' => '"just a string"', 'skip_anonymization' => true],
			['relation_id' => 4, 'bases' => 'not json', 'anonymized' => 'yes'],
			['relation_id' => 5, 'bases' => null, 'skip_anonymization' => null, 'anonymized' => null],
			['relation_id' => 6, 'bases' => ['already' => 'array'], 'anonymized' => 0],
			['relation_id' => 7, 'bases' => '', 'skip_anonymization' => ''],
			['relation_id' => 8, 'bases' => 12],
			['relation_id' => 9],
		]];

		$rows = $this->mapper()->findAnonymisedEntitiesWithBasesForFile(fileId: 42);

		$this->assertSame(
			[
				['relation_id' => 1, 'bases' => ['a', 'b'], 'skip_anonymization' => false, 'anonymized' => true],
				['relation_id' => 2, 'bases' => ['k' => 'v'], 'skip_anonymization' => true, 'anonymized' => false],
				['relation_id' => 3, 'bases' => null, 'skip_anonymization' => true],
				['relation_id' => 4, 'bases' => null, 'anonymized' => true],
				['relation_id' => 5, 'bases' => null, 'skip_anonymization' => null, 'anonymized' => null],
				['relation_id' => 6, 'bases' => ['already' => 'array'], 'anonymized' => false],
				['relation_id' => 7, 'bases' => null, 'skip_anonymization' => false],
				['relation_id' => 8, 'bases' => 12],
				['relation_id' => 9],
			],
			$rows
		);
		$this->assertSame(
			[
				'#1 select(r.id as relation_id, r.entity_id, r.position_start, r.position_end, r.confidence, r.bases, r.skip_anonymization, r.anonymized, r.anonymized_value, e.type as entity_type, e.value as entity_value, e.category)',
				'#1 from(openregister_entity_relations, r)',
				'#1 expr.eq(r.entity_id, e.id, NULL)',
				'#1 innerJoin(r, openregister_entities, e, eq(r.entity_id, e.id, NULL))',
				'#1 param :p1 = 42, 1',
				'#1 expr.eq(r.file_id, :p1, NULL)',
				'#1 param :p2 = true, boolean',
				'#1 expr.eq(r.anonymized, :p2, NULL)',
				'#1 expr.andX(eq(r.file_id, :p1, NULL), eq(r.anonymized, :p2, NULL))',
				'#1 where(andX(eq(r.file_id, :p1, NULL), eq(r.anonymized, :p2, NULL)))',
				'#1 orderBy(r.position_start, ASC)',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
	}//end testAnonymisedReadQueryAndRowNormalisation()

	/**
	 * No rows give an empty list.
	 *
	 * @return void
	 */
	public function testNoRowsGiveAnEmptyList(): void {
		$this->queuedRows = [[]];
		$this->assertSame([], $this->mapper()->findAnonymisedEntitiesWithBasesForFile(fileId: 1));
	}//end testNoRowsGiveAnEmptyList()

	/**
	 * Every outcome of the bases multiset comparison.
	 *
	 * @return void
	 */
	public function testBasesAreEqual(): void {
		$equal = new ReflectionMethod(EntityRelationMapper::class, 'basesAreEqual');
		$cases = [
			[null, null, true],
			[null, [], false],
			[[], null, false],
			[['a'], null, false],
			[null, ['a'], false],
			[[], [], true],
			[['a', 'b'], ['b', 'a'], true],
			[['a', 'b', 'a'], ['b', 'a'], true],
			[['a'], ['a', 'b'], false],
			[['x' => 'a'], ['a'], true],
			[[1, '1'], ['1'], false],
			[['a', 'B'], ['b', 'A'], false],
		];
		foreach ($cases as $i => [$a, $b, $expected]) {
			$this->assertSame($expected, $equal->invoke(null, $a, $b), 'case ' . $i);
		}
	}//end testBasesAreEqual()

	/**
	 * A mapper on a recording connection.
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
