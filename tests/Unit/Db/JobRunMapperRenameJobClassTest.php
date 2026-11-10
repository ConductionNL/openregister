<?php

/**
 * Unit test for JobRunMapper::renameJobClass.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\JobRunMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\JobRunMapper
 */
final class JobRunMapperRenameJobClassTest extends TestCase {

	/**
	 * The update sets the new name on exactly the rows of the old name.
	 *
	 * @return void
	 */
	public function testTheRowsOfTheOldNameGetTheNewName(): void {
		$calls = [];

		$expression = $this->createMock(IExpressionBuilder::class);
		$expression->method('eq')->willReturnCallback(
			static function (string $column, string $value) use (&$calls): string {
				$calls[] = ['eq', $column, $value];
				return 'cond';
			}
		);

		$query = $this->createMock(IQueryBuilder::class);
		$query->method('expr')->willReturn($expression);
		$query->method('createNamedParameter')->willReturnCallback(
			static fn (mixed $value): string => ':'.$value
		);
		$query->method('update')->willReturnCallback(
			static function (string $table) use (&$calls, &$query): IQueryBuilder {
				$calls[] = ['update', $table];
				return $query;
			}
		);
		$query->method('set')->willReturnCallback(
			static function (string $column, string $value) use (&$calls, &$query): IQueryBuilder {
				$calls[] = ['set', $column, $value];
				return $query;
			}
		);
		$query->method('where')->willReturnSelf();
		$query->method('executeStatement')->willReturn(3);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($query);

		$updated = (new JobRunMapper(db: $db))->renameJobClass(from: 'Old\\Job', to: 'New\\Job');

		$this->assertSame(expected: 3, actual: $updated);
		$this->assertSame(
			expected: [
				['update', 'openregister_job_runs'],
				['set', 'job_class', ':New\\Job'],
				['eq', 'job_class', ':Old\\Job'],
			],
			actual: $calls
		);
	}//end testTheRowsOfTheOldNameGetTheNewName()
}//end class
