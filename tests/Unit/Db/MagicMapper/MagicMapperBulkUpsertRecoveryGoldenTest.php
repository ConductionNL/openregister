<?php

/**
 * MagicMapperBulkUpsertRecoveryGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Closure;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Pins what MagicMapper::bulkUpsert() does: the delegation to the bulk handler
 * and the recovery when the table is missing.
 *
 * The mapper is built without its constructor, so the test does not depend on
 * how the constructor is wired.
 */
class MagicMapperBulkUpsertRecoveryGoldenTest extends TestCase {

	/**
	 * What happened, in order.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	/**
	 * A plain upsert passes the rows and the pre-update flag through.
	 *
	 * @return void
	 */
	public function testAnUpsertIsDelegated(): void {
		$mapper = $this->mapper(failures: [], tableExists: false);

		$result = $mapper->bulkUpsert(objects: [['id' => 'a']], register: $this->register(), schema: $this->schema(), tableName: 'openregister_table_1_2', needsPreUpdateState: false);

		$this->assertSame(['ok:openregister_table_1_2'], $result);
		$this->assertSame(
			[
				'debug [MagicMapper] Delegating bulk upsert to MagicBulkHandler {"register":1,"schema":2,"table":"openregister_table_1_2","object_count":1}',
				'upsert openregister_table_1_2 a pre=false',
			],
			$this->trace
		);
	}//end testAnUpsertIsDelegated()

	/**
	 * A missing table is created and the upsert retried, with the default pre-update flag.
	 *
	 * @return void
	 */
	public function testAMissingTableIsCreatedAndTheUpsertRetried(): void {
		$mapper = $this->mapper(failures: ['SQLSTATE[42P01]: relation does not exist'], tableExists: false);

		$result = $mapper->bulkUpsert(objects: [['id' => 'a']], register: $this->register(), schema: $this->schema(), tableName: 't', needsPreUpdateState: false);

		$this->assertSame(['ok:t'], $result);
		$this->assertSame(
			[
				'debug [MagicMapper] Delegating bulk upsert to MagicBulkHandler {"register":1,"schema":2,"table":"t","object_count":1}',
				'upsert t a pre=false',
				'warning [MagicMapper] Table does not exist, creating and retrying bulkUpsert {"register":1,"schema":2,"table":"t","error":"SQLSTATE[42P01]: relation does not exist"}',
				'tableExists t',
				'ensure table 1_2 force=true',
				'upsert t a pre=true',
			],
			$this->trace
		);
	}//end testAMissingTableIsCreatedAndTheUpsertRetried()

	/**
	 * Every spelling of a missing table is recovered.
	 *
	 * @return void
	 */
	public function testEverySpellingOfAMissingTableIsRecovered(): void {
		foreach (['Table x does not exist', "Table 'x' doesn't exist", 'SQLSTATE[42S02]: 1146 Base table'] as $message) {
			$this->trace = [];
			$mapper = $this->mapper(failures: [$message], tableExists: false);
			$this->assertSame(['ok:t'], $mapper->bulkUpsert(objects: [['id' => 'a']], register: $this->register(), schema: $this->schema(), tableName: 't'), $message);
			$this->assertContains('ensure table 1_2 force=true', $this->trace, $message);
		}
	}//end testEverySpellingOfAMissingTableIsRecovered()

	/**
	 * A table that exists is never recreated: the failure surfaces.
	 *
	 * @return void
	 */
	public function testAnExistingTableIsNeverRecreated(): void {
		$mapper = $this->mapper(failures: ['column "x" of relation "t" does not exist'], tableExists: true);

		try {
			$mapper->bulkUpsert(objects: [['id' => 'a']], register: $this->register(), schema: $this->schema(), tableName: 't');
			$this->fail('expected the failure to surface');
		} catch (Exception $e) {
			$this->assertSame('column "x" of relation "t" does not exist', $e->getMessage());
		}

		$this->assertNotContains('ensure table 1_2 force=true', $this->trace);
		$this->assertSame('tableExists t', $this->trace[array_key_last($this->trace)]);
	}//end testAnExistingTableIsNeverRecreated()

	/**
	 * Any other failure surfaces without a table check.
	 *
	 * @return void
	 */
	public function testAnotherFailureSurfaces(): void {
		$mapper = $this->mapper(failures: ['deadlock'], tableExists: false);

		try {
			$mapper->bulkUpsert(objects: [['id' => 'a']], register: $this->register(), schema: $this->schema(), tableName: 't');
			$this->fail('expected the failure to surface');
		} catch (Exception $e) {
			$this->assertSame('deadlock', $e->getMessage());
		}

		$this->assertSame(
			[
				'debug [MagicMapper] Delegating bulk upsert to MagicBulkHandler {"register":1,"schema":2,"table":"t","object_count":1}',
				'upsert t a pre=true',
			],
			$this->trace
		);
	}//end testAnotherFailureSurfaces()

	/**
	 * A failing retry surfaces.
	 *
	 * @return void
	 */
	public function testAFailingRetrySurfaces(): void {
		$mapper = $this->mapper(failures: ['1146', 'still broken'], tableExists: false);

		try {
			$mapper->bulkUpsert(objects: [['id' => 'a']], register: $this->register(), schema: $this->schema(), tableName: 't');
			$this->fail('expected the failure to surface');
		} catch (Exception $e) {
			$this->assertSame('still broken', $e->getMessage());
		}
	}//end testAFailingRetrySurfaces()

	/**
	 * A mapper whose bulk handler fails with the given messages, in order, then succeeds.
	 *
	 * @param list<string> $failures    The failure messages
	 * @param bool         $tableExists What the connection answers for the table
	 *
	 * @return MagicMapper
	 */
	private function mapper(array $failures, bool $tableExists): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['ensureTableForRegisterSchema'])
			->getMock();
		$mapper->method('ensureTableForRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema, bool $force = false): bool {
				$this->trace[] = 'ensure table '.$register->getId().'_'.$schema->getId().' force='.var_export($force, true);
				return true;
			}
		);

		$bulk = $this->createMock(MagicBulkHandler::class);
		$bulk->method('bulkUpsert')->willReturnCallback(
			function (array $objects, Register $register, Schema $schema, string $tableName, bool $needsPreUpdateState = true) use (&$failures): array {
				$ids = array_map(static fn (array $o): string => $o['id'], $objects);
				$this->trace[] = 'upsert '.$tableName.' '.implode(',', $ids).' pre='.var_export($needsPreUpdateState, true);
				if ($failures !== []) {
					throw new Exception(array_shift($failures));
				}

				return ['ok:'.$tableName];
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(
			function (string $table) use ($tableExists): bool {
				$this->trace[] = 'tableExists '.$table;
				return $tableExists;
			}
		);

		$logger = new class ($this->trace) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param list<string> $trace The shared trace
			 */
			public function __construct(private array &$trace) {
			}//end __construct()

			/**
			 * Record a log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array             $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context=[]): void {
				unset($context['file'], $context['line']);
				$this->trace[] = $level.' '.$message.' '.json_encode($context);
			}//end log()
		};

		$container = $this->createMock(ContainerInterface::class);
		Closure::bind(
			function () use ($db, $logger, $container, $bulk): void {
				$this->db          = $db;
				$this->logger      = $logger;
				$this->container   = $container;
				$this->bulkHandler = $bulk;
			},
			$mapper,
			MagicMapper::class
		)();

		return $mapper;
	}//end mapper()

	/**
	 * Register 1.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(1);
		return $register;
	}//end register()

	/**
	 * Schema 2, without encrypted properties.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(2);
		return $schema;
	}//end schema()
}//end class
