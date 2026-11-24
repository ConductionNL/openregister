<?php

/**
 * MagicRelationsTableTest: the narrow write of one row's relations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRelationsTable;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRelationsTable
 */
class MagicRelationsTableTest extends TestCase {
	use RecordsQueries;

	private const TABLE = 'openregister_table_42_72';

	/**
	 * The table under test, on a recording connection.
	 *
	 * @return MagicRelationsTable
	 */
	private function table(): MagicRelationsTable {
		return new MagicRelationsTable($this->recordingConnection(), self::TABLE);
	}//end table()

	/**
	 * Append reads the live row, then writes only its relations and updated time.
	 *
	 * @return void
	 */
	public function testAppendReadsTheLiveRowAndWritesOnlyItsRelationsAndUpdated(): void {
		$this->rowSets          = [[['_relations' => '["a"]']]];
		$this->statementResults = [1];

		$this->assertSame(1, $this->table()->append('target', 'saved'));
		$this->assertSame(
			[
				'select(_relations)',
				'from(' . self::TABLE . ')',
				"where(eq(_uuid, 'target'))",
				'andWhere(isNull(_deleted))',
				'setMaxResults(1)',
				'executeQuery',
			],
			$this->queries[0]
		);
		$this->assertSame('update(' . self::TABLE . ')', $this->queries[1][0]);
		$this->assertSame("set(_relations, '[\"a\",\"saved\"]')", $this->queries[1][1]);
		$this->assertStringStartsWith('set(_updated, ', $this->queries[1][2]);
		$this->assertSame(["where(eq(_uuid, 'target'))", 'andWhere(isNull(_deleted))', 'executeStatement'], array_slice($this->queries[1], 3));
	}//end testAppendReadsTheLiveRowAndWritesOnlyItsRelationsAndUpdated()

	/**
	 * A row without relations starts a list.
	 *
	 * @return void
	 */
	public function testAppendStartsAListOnARowWithoutRelations(): void {
		$this->rowSets = [[['_relations' => null]]];

		$this->table()->append('target', 'saved');

		$this->assertSame("set(_relations, '[\"saved\"]')", $this->queries[1][1]);
	}//end testAppendStartsAListOnARowWithoutRelations()

	/**
	 * A row that already holds the UUID is not written.
	 *
	 * @return void
	 */
	public function testAppendWritesNothingWhenTheRowAlreadyHoldsTheUuid(): void {
		$this->rowSets = [[['_relations' => '["saved"]']]];

		$this->assertSame(0, $this->table()->append('target', 'saved'));
		$this->assertCount(1, $this->queries);
	}//end testAppendWritesNothingWhenTheRowAlreadyHoldsTheUuid()

	/**
	 * A missing or deleted row is not written.
	 *
	 * @return void
	 */
	public function testAppendWritesNothingForAMissingOrDeletedRow(): void {
		$this->rowSets = [[]];

		$this->assertSame(0, $this->table()->append('gone', 'saved'));
		$this->assertCount(1, $this->queries);
	}//end testAppendWritesNothingForAMissingOrDeletedRow()
}//end class
