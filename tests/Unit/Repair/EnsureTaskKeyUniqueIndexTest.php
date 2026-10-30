<?php

/**
 * The task-key unique index the migration had to leave out is added by a
 * later repair run once no two tasks share a key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use Doctrine\DBAL\Schema\Schema;
use OCA\OpenRegister\Repair\EnsureTaskKeyUniqueIndex;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Repair\EnsureTaskKeyUniqueIndex
 */
class EnsureTaskKeyUniqueIndexTest extends TestCase {

	private IDBConnection&MockObject $db;

	private IAppConfig&MockObject $appConfig;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->schema = new Schema();
		$table        = $this->schema->createTable('oc_openregister_tasks');
		$table->addColumn('id', 'bigint');
		$table->addColumn('task_key', 'string', ['notnull' => false, 'length' => 255]);

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('tableExists')->willReturn(true);
		$this->db->method('createSchema')->willReturn($this->schema);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	private function step(int $shared): EnsureTaskKeyUniqueIndex {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('oc_');

		return new class($this->db, $config, $this->appConfig, new NullLogger(), $shared) extends EnsureTaskKeyUniqueIndex {
			public function __construct(IDBConnection $db, IConfig $config, IAppConfig $appConfig, NullLogger $logger, private readonly int $shared) {
				parent::__construct(db: $db, config: $config, appConfig: $appConfig, logger: $logger);
			}

			protected function sharedKeys(): int {
				return $this->shared;
			}
		};
	}//end step()

	public function testTheIndexIsAddedOnceTheKeysAreDistinct(): void {
		$this->appConfig->method('getValueBool')->willReturn(false);
		$this->db->expects($this->once())->method('migrateToSchema')->with($this->callback(
			static fn (Schema $s): bool => $s->getTable('oc_openregister_tasks')->hasIndex('or_tasks_key_uniq')
				&& $s->getTable('oc_openregister_tasks')->getIndex('or_tasks_key_uniq')->isUnique()
		));
		$this->appConfig->expects($this->once())->method('setValueBool')->with('openregister', EnsureTaskKeyUniqueIndex::DONE_KEY, true);

		$this->step(shared: 0)->run($this->createMock(IOutput::class));
	}//end testTheIndexIsAddedOnceTheKeysAreDistinct()

	public function testSharedKeysWarnNameTheCommandAndChangeNothing(): void {
		$this->appConfig->method('getValueBool')->willReturn(false);
		$this->db->expects($this->never())->method('migrateToSchema');
		$this->appConfig->expects($this->never())->method('setValueBool');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('occ maintenance:repair'));

		$this->step(shared: 2)->run($output);
	}//end testSharedKeysWarnNameTheCommandAndChangeNothing()

	public function testAnExistingIndexIsOnlyRecorded(): void {
		$this->schema->getTable('oc_openregister_tasks')->addUniqueIndex(['task_key'], 'or_tasks_key_uniq');
		$this->appConfig->method('getValueBool')->willReturn(false);
		$this->db->expects($this->never())->method('migrateToSchema');
		$this->appConfig->expects($this->once())->method('setValueBool');

		$this->step(shared: 0)->run($this->createMock(IOutput::class));
	}//end testAnExistingIndexIsOnlyRecorded()

	public function testADoneInstanceIsNotIntrospected(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->db->expects($this->never())->method('createSchema');

		$this->step(shared: 0)->run($this->createMock(IOutput::class));
	}//end testADoneInstanceIsNotIntrospected()
}//end class
