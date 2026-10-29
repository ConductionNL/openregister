<?php

/**
 * A save that leaves draft writes its status as a compare-and-swap, so two parallel requests cannot both announce.
 *
 * The allowance is per process, so two requests on one draft can both read it
 * as a draft. The UPDATE of the one that leaves draft carries
 * `AND _status = 'draft'`; the request that finds nothing to change is
 * refused as DraftAlreadyLeftException.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\DraftAlreadyLeftException;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The conditional UPDATE and its refusal.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\StoredEntity
 */
class MagicMapperLeaveDraftCompareAndSwapTest extends TestCase {

	/**
	 * The conditions put on the UPDATE, as column => value.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $where = [];

	protected function setUp(): void {
		DraftStatusPolicy::resetPromotions();
		$this->where = [];
	}//end setUp()

	protected function tearDown(): void {
		DraftStatusPolicy::resetPromotions();
	}//end tearDown()

	/**
	 * A mapper whose connection answers an UPDATE with the given number of changed rows.
	 *
	 * @param int $changed The rows the UPDATE changes.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapperChanging(int $changed): MagicMapper {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, $value): string {
				$this->where[] = [$column, $value];

				return $column . ' = ' . $value;
			}
		);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willReturn($changed);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('db');
		$property->setValue($mapper, $db);

		return $mapper;
	}//end mapperChanging()

	/**
	 * Run updateExistingObject().
	 *
	 * @param MagicMapper $mapper The mapper.
	 *
	 * @return void
	 */
	private function update(MagicMapper $mapper): void {
		$method = new ReflectionMethod(MagicMapper::class, 'updateExistingObject');
		$method->invoke($mapper, 'uuid-1', ['_status' => 'active', '_uuid' => 'uuid-1'], 'openregister_table_1_2');
	}//end update()

	/**
	 * An ordinary update has no status condition, and a zero count is no refusal.
	 */
	public function testAnOrdinaryUpdateIsNotConditional(): void {
		$this->update($this->mapperChanging(changed: 0));

		$this->assertSame([['_uuid', 'uuid-1']], $this->where);
	}//end testAnOrdinaryUpdateIsNotConditional()

	/**
	 * The save that leaves draft only changes a row that is still a draft.
	 */
	public function testLeavingDraftChangesOnlyARowThatIsStillADraft(): void {
		$this->promote();

		$this->update($this->mapperChanging(changed: 1));

		$this->assertSame([['_uuid', 'uuid-1'], ['_status', ObjectEntity::STATUS_DRAFT]], $this->where);
	}//end testLeavingDraftChangesOnlyARowThatIsStillADraft()

	/**
	 * The request that finds the row already left is refused, and announces nothing. Goes red without the condition's count check.
	 */
	public function testTheLosingRequestIsRefused(): void {
		$this->promote();

		$this->expectException(DraftAlreadyLeftException::class);
		$this->update($this->mapperChanging(changed: 0));
	}//end testTheLosingRequestIsRefused()

	/**
	 * A later write of the same object in the same request (the file write of a file-bearing save, an automatic
	 * transition) is an ordinary update: the row is active by then, and a zero count is no refusal. Goes red when
	 * the leaving flag is read without being consumed.
	 */
	public function testOnlyTheWriteThatLeavesDraftIsConditional(): void {
		$this->promote();

		$this->update($this->mapperChanging(changed: 1));
		$this->where = [];
		$this->update($this->mapperChanging(changed: 0));

		$this->assertSame([['_uuid', 'uuid-1']], $this->where);
	}//end testOnlyTheWriteThatLeavesDraftIsConditional()

	/**
	 * Let the policy allow and use one promotion of uuid-1, as the save of a form submit does.
	 *
	 * @return void
	 */
	private function promote(): void {
		DraftStatusPolicy::allowPromotion(uuid: 'uuid-1');
		$draft = new ObjectEntity();
		$draft->setId(1);
		$draft->setUuid('uuid-1');
		$draft->setStatus(ObjectEntity::STATUS_DRAFT);
		$this->assertSame('active', (new DraftStatusPolicy())->resolveStatus(entity: $draft, selfData: ['status' => 'active']));
		$this->assertTrue(DraftStatusPolicy::isLeaving(uuid: 'uuid-1'));
	}//end promote()
}//end class
