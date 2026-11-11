<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task key becomes unique, unless stored keys already collide.
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261024120000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/flow-tasks/spec.md
 */
class Version1Date20261024120000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * Run the step over a tasks table, with this many shared keys.
	 *
	 * @param int $shared The shared keys the database reports.
	 * @param bool $hasIndex Whether the index exists already.
	 * @param int $expectIndex How often addUniqueIndex is expected.
	 * @param int $expectWarning How often a warning is expected.
	 *
	 * @return void
	 */
	private function migrate(int $shared, bool $hasIndex, int $expectIndex, int $expectWarning): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->method('hasIndex')->willReturn($hasIndex);
		$table->expects($this->exactly($expectIndex))->method('addUniqueIndex')->with(['task_key'], Version1Date20261024120000::INDEX);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly($expectWarning))->method('warning');

		$step = new class($this->createMock(IDBConnection::class), $shared) extends Version1Date20261024120000 {
			public function __construct(IDBConnection $db, private int $shared) {
				parent::__construct(db: $db);
			}

			public function sharedKeys(): int {
				return $this->shared;
			}
		};

		$closure = static fn () => $schema;
		$step->preSchemaChange($output, $closure, []);
		$step->changeSchema($output, $closure, []);
	}//end migrate()

	/**
	 * @return void
	 */
	public function testTheKeyBecomesUnique(): void {
		$this->migrate(shared: 0, hasIndex: false, expectIndex: 1, expectWarning: 0);
	}//end testTheKeyBecomesUnique()

	/**
	 * @return void
	 */
	public function testSharedKeysWarnAndAddNothing(): void {
		$this->migrate(shared: 2, hasIndex: false, expectIndex: 0, expectWarning: 1);
	}//end testSharedKeysWarnAndAddNothing()

	/**
	 * @return void
	 */
	public function testAnExistingIndexIsLeftAlone(): void {
		$this->migrate(shared: 0, hasIndex: true, expectIndex: 0, expectWarning: 0);
	}//end testAnExistingIndexIsLeftAlone()
}//end class
