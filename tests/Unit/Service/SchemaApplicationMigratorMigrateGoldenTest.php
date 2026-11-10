<?php

/**
 * Golden tests for SchemaApplicationMigrator::migrate() outcomes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SchemaApplicationMigrator;
use OCA\OpenRegister\Service\ApplicationMove;
use PHPUnit\Framework\TestCase;

/**
 * Pins the outcome array of migrate() for each branch.
 */
class SchemaApplicationMigratorMigrateGoldenTest extends TestCase {

	public function testInvalidArgumentsRefuseWithoutLookups(): void {
		$migrator = $this->createPartialMock(SchemaApplicationMigrator::class, ['collidingSlugs', 'moveTable']);
		$migrator->expects($this->never())->method('collidingSlugs');
		$migrator->expects($this->never())->method('moveTable');
		$expected = ['ok' => false, 'reason' => 'invalid-arguments', 'collisions' => [], 'schemas' => 0, 'registers' => 0];
		$this->assertSame($expected, $migrator->migrate('', 'b'));
		$this->assertSame($expected, $migrator->migrate('a', ''));
		$this->assertSame($expected, $migrator->migrate('a', 'a'));
	}//end testInvalidArgumentsRefuseWithoutLookups()

	public function testCollisionsRefuseWithoutMoving(): void {
		$migrator = $this->createPartialMock(SchemaApplicationMigrator::class, ['collidingSlugs', 'moveTable']);
		$migrator->method('collidingSlugs')->with('a', 'b')->willReturn(['person']);
		$migrator->expects($this->never())->method('moveTable');
		$this->assertSame(
			['ok' => false, 'reason' => 'collisions', 'collisions' => ['person'], 'schemas' => 0, 'registers' => 0],
			$migrator->migrate('a', 'b')
		);
	}//end testCollisionsRefuseWithoutMoving()

	public function testMovesSchemasThenRegisters(): void {
		$migrator = $this->createPartialMock(SchemaApplicationMigrator::class, ['collidingSlugs', 'moveTable']);
		$migrator->method('collidingSlugs')->willReturn([]);
		$calls = [];
		$migrator->method('moveTable')->willReturnCallback(
			function (string $table, ApplicationMove $move) use (&$calls): int {
				$calls[] = [$table, $move->from, $move->to];
				return count($calls) * 2;
			}
		);
		$this->assertSame(
			['ok' => true, 'reason' => 'migrated', 'collisions' => [], 'schemas' => 2, 'registers' => 4],
			$migrator->migrate('a', 'b')
		);
		$this->assertSame([['openregister_schemas', 'a', 'b'], ['openregister_registers', 'a', 'b']], $calls);
	}//end testMovesSchemasThenRegisters()

	public function testPlanCollisionsKeepsFirstSeenOrderAndNumericSlugs(): void {
		$this->assertSame(
			['b', 'a', 12],
			SchemaApplicationMigrator::planCollisions(['B', 'x', 'a', 'b', '12'], ['A', 'b', '12'])
		);
	}//end testPlanCollisionsKeepsFirstSeenOrderAndNumericSlugs()
}//end class
