<?php

/**
 * ExampleSetCasePlans over a mocked case plan service, item and task mappers
 * and object finder: what a plan row is refused for, how its items are
 * brought in, and how the created items (and the tasks they became) are
 * proved lost and taken away.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Db\CaseItem
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFieldRules
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowShape
 */
class ExampleSetCasePlansBehaviourTest extends TestCase {

	private PartyService&MockObject $parties;

	private CasePlanService&MockObject $plans;

	private CaseItemMapperInterface&MockObject $items;

	private TaskMapper&MockObject $tasks;

	protected function setUp(): void {
		parent::setUp();
		$this->parties = $this->createMock(PartyService::class);
		$this->plans   = $this->createMock(CasePlanService::class);
		$this->items   = $this->createMock(CaseItemMapperInterface::class);
		$this->tasks   = $this->createMock(TaskMapper::class);
	}

	private function subject(): ExampleSetCasePlans {
		$services  = [
			PartyService::class            => $this->parties,
			CasePlanService::class         => $this->plans,
			CaseItemMapperInterface::class => $this->items,
			TaskMapper::class              => $this->tasks,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new ExampleSetCasePlans($container);
	}

	private static function item(string $key, ?string $kind = null, ?string $uuid = null): CaseItem {
		$item = new CaseItem();
		$item->setItemKey($key);
		$item->setRealisationKind($kind);
		$item->setRealisationUuid($uuid);
		return $item;
	}

	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('3');
		$object->setSchema('5');
		return $object;
	}

	public function testItLivesInTheCasePlansPart(): void {
		$this->assertSame('casePlans', $this->subject()->part());
		$this->assertSame('case plan', $this->subject()->noun());
	}

	/**
	 * @dataProvider refusals
	 */
	public function testAPlanIsRefusedForItsFirstFault(array $data, string $refusal): void {
		$this->assertSame($refusal, $this->subject()->refusal($data, []));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		$ok = ['object' => 'obj-1', 'definition' => ['items' => [['key' => 'intake']]]];
		return [
			'complete'            => [$ok, ''],
			'with a history'      => [$ok + ['history' => [['item' => 'intake', 'to' => 'completed']]], ''],
			'history null'        => [$ok + ['history' => null], ''],
			'unknown field'       => [$ok + ['owner' => 'anna'], 'field "owner" is not a case plan field a set may write'],
			'no object'           => [['definition' => $ok['definition']], 'a case plan needs the object it is on (object)'],
			'no definition'       => [['object' => 'obj-1'], 'a case plan needs a definition with its items (definition.items)'],
			'definition as text'  => [['object' => 'obj-1', 'definition' => 'plan'], 'a case plan needs a definition with its items (definition.items)'],
			'items not a list'    => [['object' => 'obj-1', 'definition' => ['items' => 'intake']], 'a case plan needs a definition with its items (definition.items)'],
			'no items'            => [['object' => 'obj-1', 'definition' => ['items' => []]], 'a case plan needs a definition with its items (definition.items)'],
			'history as text'     => [$ok + ['history' => 'done'], 'history is a list'],
			'history as a map'    => [$ok + ['history' => ['intake' => 'completed']], 'history is a list'],
		];
	}

	public function testAMissingObjectIsAnErrorAndNoItemIsWritten(): void {
		$this->parties->expects($this->once())->method('findUnscoped')->with('obj-9')->willReturn(null);
		$this->plans->expects($this->never())->method('ensureItems');

		$this->assertSame(
			['error' => 'no object "obj-9" to put the plan on', 'entry' => null],
			$this->subject()->write(new ExampleSetRow('zaak', 'k', ['object' => 'obj-9', 'definition' => ['items' => [['key' => 'a']]]]))
		);
	}

	public function testThePlanIsBroughtInAsTheFragmentsAppAndItsCreatedItemsRecorded(): void {
		$definition = ['items' => [['key' => 'intake'], ['key' => 'besluit']]];
		$history    = [['item' => 'intake', 'to' => 'completed']];
		$this->parties->method('findUnscoped')->with('obj-1')->willReturn(self::object());
		$this->plans->expects($this->once())->method('ensureItems')
			->with('obj-1', 3, 5, $definition, $history, 'zaak')
			->willReturn(['created' => ['x' => 'intake', 'y' => 'besluit']]);

		$this->assertSame(
			['error' => '', 'entry' => ['object' => 'obj-1', 'created' => ['intake', 'besluit']]],
			$this->subject()->write(new ExampleSetRow('zaak', 'k', ['object' => 'obj-1', 'definition' => $definition, 'history' => $history]))
		);
	}

	public function testAPlanWithoutHistoryIsBroughtInWithAnEmptyOne(): void {
		$this->parties->method('findUnscoped')->willReturn(self::object());
		$this->plans->expects($this->once())->method('ensureItems')
			->with('obj-1', 3, 5, ['items' => [['key' => 'a']]], [], 'zaak')
			->willReturn(['created' => []]);

		$this->assertSame(
			['error' => '', 'entry' => ['object' => 'obj-1', 'created' => []]],
			$this->subject()->write(new ExampleSetRow('zaak', 'k', ['object' => 'obj-1', 'definition' => ['items' => [['key' => 'a']]]]))
		);
	}

	public function testARefusedDefinitionIsTheWriteError(): void {
		$this->parties->method('findUnscoped')->willReturn(self::object());
		$this->plans->method('ensureItems')->willThrowException(new RuntimeException('item "a" has no type'));

		$this->assertSame(
			['error' => 'item "a" has no type', 'entry' => null],
			$this->subject()->write(new ExampleSetRow('zaak', 'k', ['object' => 'obj-1', 'definition' => ['items' => [['key' => 'a']]]]))
		);
	}

	public function testAPlanIsLostOnlyWhenNoneOfItsCreatedItemsRemain(): void {
		$this->items->method('findByObject')->willReturnCallback(static fn (string $uuid): array => match ($uuid) {
			'kept'  => [self::item('intake'), self::item('other')],
			'empty' => [self::item('other')],
			'down'  => throw new RuntimeException('database is gone'),
		});
		$subject = $this->subject();

		$this->assertFalse($subject->lost(['object' => 'kept', 'created' => ['intake']]));
		$this->assertTrue($subject->lost(['object' => 'empty', 'created' => ['intake']]));
		$this->assertFalse($subject->lost(['object' => 'empty', 'created' => []]), 'a plan that created nothing is not lost');
		$this->assertFalse($subject->lost(['object' => 'empty']), 'an entry without created items is not lost');
		$this->assertTrue($subject->lost(['object' => 'down', 'created' => ['intake']]));
	}

	public function testRevertDeletesOnlyTheCreatedItemsChildrenFirstWithTheirTasks(): void {
		$parent = self::item('fase', CaseItem::REALISATION_TASK, ' task-1 ');
		$child  = self::item('stap');
		$kept   = self::item('eerder', CaseItem::REALISATION_TASK, 'task-9');
		$this->items->expects($this->once())->method('findByObject')->with('obj-1')->willReturn([$parent, $kept, $child]);
		$task = new Task();
		$this->tasks->expects($this->once())->method('findByUuid')->with('task-1')->willReturn($task);
		$this->tasks->expects($this->once())->method('delete')->with($this->identicalTo($task));
		$deleted = [];
		$this->items->expects($this->exactly(2))->method('delete')->willReturnCallback(static function (CaseItem $item) use (&$deleted): CaseItem {
			$deleted[] = $item->getItemKey();
			return $item;
		});

		$this->assertSame('removed', $this->subject()->revert(['object' => 'obj-1', 'created' => ['fase', 'stap']]));
		$this->assertSame(['stap', 'fase'], $deleted);
	}

	public function testAnItemRealisedByAnotherKindOrWithoutUuidKeepsTasksUntouched(): void {
		$this->items->method('findByObject')->willReturn([self::item('a', 'case', 'case-1'), self::item('b', CaseItem::REALISATION_TASK, '  ')]);
		$this->tasks->expects($this->never())->method('findByUuid');
		$this->items->expects($this->exactly(2))->method('delete');

		$this->assertSame('removed', $this->subject()->revert(['object' => 'obj-1', 'created' => ['a', 'b']]));
	}

	public function testATaskAlreadyGoneStillLetsItsItemBeDeleted(): void {
		$item = self::item('fase', CaseItem::REALISATION_TASK, 'task-1');
		$this->items->method('findByObject')->willReturn([$item]);
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->tasks->expects($this->never())->method('delete');
		$this->items->expects($this->once())->method('delete')->with($this->identicalTo($item));

		$this->assertSame('removed', $this->subject()->revert(['object' => 'obj-1', 'created' => ['fase']]));
	}

	public function testRevertFailsWhenAnItemCannotBeDeleted(): void {
		$this->items->method('findByObject')->willReturn([self::item('a')]);
		$this->items->method('delete')->willThrowException(new RuntimeException('database is gone'));

		$this->assertSame('failed', $this->subject()->revert(['object' => 'obj-1', 'created' => ['a']]));
	}

	public function testRevertWithNothingCreatedDeletesNothing(): void {
		$this->items->method('findByObject')->willReturn([self::item('a')]);
		$this->items->expects($this->never())->method('delete');

		$this->assertSame('removed', $this->subject()->revert(['object' => 'obj-1']));
	}
}
