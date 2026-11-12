<?php

/**
 * Golden tests for SequenceService::advanceTo() and the scope arguments of reserveNext().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Sequence;
use OCA\OpenRegister\Db\SequenceMapper;
use OCA\OpenRegister\Service\SequenceScope;
use OCA\OpenRegister\Service\SequenceService;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the current observable behaviour of advanceTo().
 */
class SequenceServiceAdvanceToGoldenTest extends TestCase {

	/** @var SequenceMapper&MockObject */
	private SequenceMapper $mapper;

	private SequenceService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(SequenceMapper::class);
		$this->service = new SequenceService($this->mapper, $this->createMock(IDBConnection::class));
	}//end setUp()

	public function testNonPositiveNumberDoesNothing(): void {
		$this->mapper->expects($this->never())->method('raiseTo');
		$this->mapper->expects($this->never())->method('findForScope');
		$this->service->advanceTo(new SequenceScope(1, 2, '2026'), 0);
		$this->service->advanceTo(new SequenceScope(1, 2, '2026'), -5);
	}//end testNonPositiveNumberDoesNothing()

	public function testRaisedCounterStops(): void {
		$this->mapper->expects($this->once())->method('raiseTo')->with($this->equalTo(new SequenceScope(1, 2, '2026')), 121)->willReturn(1);
		$this->mapper->expects($this->never())->method('findForScope');
		$this->service->advanceTo(new SequenceScope(1, 2, '2026'), 120);
	}//end testRaisedCounterStops()

	public function testExistingCounterAlreadyPastStops(): void {
		$this->mapper->method('raiseTo')->willReturn(0);
		$this->mapper->expects($this->once())->method('findForScope')->with($this->equalTo(new SequenceScope(1, 2, '2026')))->willReturn(new Sequence());
		$this->mapper->expects($this->never())->method('insert');
		$this->service->advanceTo(new SequenceScope(1, 2, '2026'), 120);
	}//end testExistingCounterAlreadyPastStops()

	public function testMissingCounterIsSeededPastTheNumber(): void {
		$this->mapper->expects($this->once())->method('raiseTo')->willReturn(0);
		$this->mapper->method('findForScope')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			function (Sequence $entity): Sequence {
				$this->assertSame([1, 2, '2026', 121], [$entity->getRegisterId(), $entity->getSchemaId(), $entity->getScopeKey(), $entity->getNextValue()]);
				return $entity;
			}
		);
		$this->service->advanceTo(new SequenceScope(1, 2, '2026'), 120);
	}//end testMissingCounterIsSeededPastTheNumber()

	public function testSeedDbFailureRaisesInsteadOfSpendingANumber(): void {
		$calls = [];
		$this->mapper->method('raiseTo')->willReturnCallback(
			function (SequenceScope $scope, int $min) use (&$calls): int {
				$calls[] = [$scope->registerId, $scope->schemaId, $scope->scopeKey, $min];
				return 0;
			}
		);
		$this->mapper->expects($this->once())->method('findForScope')->with($this->equalTo(new SequenceScope(1, 2, '2026')))->willReturn(null);
		$this->mapper->method('insert')->willThrowException(new DbException('duplicate'));
		$this->mapper->expects($this->never())->method('incrementScope');
		$this->service->advanceTo(new SequenceScope(1, 2, '2026'), 120);
		$this->assertSame([[1, 2, '2026', 121], [1, 2, '2026', 121]], $calls);
	}//end testSeedDbFailureRaisesInsteadOfSpendingANumber()

	public function testSeedOtherFailureRaisesAgain(): void {
		$calls = [];
		$this->mapper->method('raiseTo')->willReturnCallback(
			function (SequenceScope $scope, int $min) use (&$calls): int {
				$calls[] = [$scope->registerId, $scope->schemaId, $scope->scopeKey, $min];
				return 0;
			}
		);
		$this->mapper->method('findForScope')->willReturn(null);
		$this->mapper->method('insert')->willThrowException(new RuntimeException('boom'));
		$this->mapper->expects($this->never())->method('incrementScope');
		$this->service->advanceTo(new SequenceScope(1, 2, '2026'), 120);
		$this->assertSame([[1, 2, '2026', 121], [1, 2, '2026', 121]], $calls);
	}//end testSeedOtherFailureRaisesAgain()

	public function testReserveNextPassesTheScopeToTheMapper(): void {
		$row = new Sequence();
		$row->setNextValue(8);
		$this->mapper->expects($this->once())->method('incrementScope')->with($this->equalTo(new SequenceScope(3, 4, '2026-06')))->willReturn(1);
		$this->mapper->expects($this->once())->method('findForScope')->with($this->equalTo(new SequenceScope(3, 4, '2026-06')))->willReturn($row);
		$this->assertSame(7, $this->service->reserveNext(new SequenceScope(3, 4, '2026-06')));
	}//end testReserveNextPassesTheScopeToTheMapper()

	public function testReserveNextOnMissingRowReturnsZeroBased(): void {
		$this->mapper->method('incrementScope')->willReturn(1);
		$this->mapper->method('findForScope')->willReturn(null);
		$this->assertSame(0, $this->service->reserveNext(new SequenceScope(3, 4, '')));
	}//end testReserveNextOnMissingRowReturnsZeroBased()

	public function testReserveNextSeedsWithNextValueTwo(): void {
		$this->mapper->method('incrementScope')->willReturn(0);
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			function (Sequence $entity): Sequence {
				$this->assertSame([3, 4, 'x', 2], [$entity->getRegisterId(), $entity->getSchemaId(), $entity->getScopeKey(), $entity->getNextValue()]);
				return $entity;
			}
		);
		$this->assertSame(1, $this->service->reserveNext(new SequenceScope(3, 4, 'x')));
	}//end testReserveNextSeedsWithNextValueTwo()
}//end class
