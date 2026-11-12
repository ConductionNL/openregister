<?php

/**
 * advanceTo() keeps the counter past an imported number when it loses the seed race.
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

use OCA\OpenRegister\Db\SequenceMapper;
use OCA\OpenRegister\Service\SequenceScope;
use OCA\OpenRegister\Service\SequenceService;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SequenceService
 */
final class SequenceServiceAdvanceRaceTest extends TestCase {

	/**
	 * A concurrent writer creates the row between the read and the seed insert.
	 *
	 * The counter must then be raised to the imported number plus one; spending
	 * a number on it instead leaves the counter below the import, so the next
	 * reservation hands out a number that is already taken.
	 *
	 * @return void
	 */
	public function testALostSeedRaceStillRaisesTheCounterPastTheImport(): void {
		$raised = [];
		$mapper = $this->createMock(SequenceMapper::class);
		$mapper->method('raiseTo')->willReturnCallback(
			function (SequenceScope $scope, int $minNextValue) use (&$raised): int {
				$raised[] = $minNextValue;
				return 0;
			}
		);
		$mapper->method('findForScope')->willReturn(null);
		$mapper->method('insert')->willThrowException(new DbException('duplicate key'));
		$mapper->expects($this->never())->method('incrementScope');

		$service = new SequenceService($mapper, $this->createMock(IDBConnection::class));
		$service->advanceTo(new SequenceScope(1, 2, 'scope'), 41);

		$this->assertSame([42, 42], $raised);
	}//end testALostSeedRaceStillRaisesTheCounterPastTheImport()

	/**
	 * A seed that wins the race stores the imported number plus one and raises nothing more.
	 *
	 * @return void
	 */
	public function testAWonSeedStoresTheImportPlusOne(): void {
		$mapper = $this->createMock(SequenceMapper::class);
		$mapper->expects($this->once())->method('raiseTo')->willReturn(0);
		$mapper->method('findForScope')->willReturn(null);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			function ($entity) {
				$this->assertSame(42, $entity->getNextValue());
				$this->assertSame('scope', $entity->getScopeKey());
				return $entity;
			}
		);

		$service = new SequenceService($mapper, $this->createMock(IDBConnection::class));
		$service->advanceTo(new SequenceScope(1, 2, 'scope'), 41);
	}//end testAWonSeedStoresTheImportPlusOne()
}//end class
