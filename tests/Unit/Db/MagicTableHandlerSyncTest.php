<?php

/**
 * What syncTableForRegisterSchema does to a magic table, call by call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The calls, logs, caches and statistics of a table sync.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableSyncColumns
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicTableHandlerSyncTest extends TestCase {

	private const CACHE_KEY = '11_91';

	private const TABLE = 'openregister_table_11_91';

	/**
	 * Every mapper call, as "method(args)".
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Every log line, as [level, message, context without line].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logs = [];

	private MagicMapper&MockObject $magicMapper;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		MagicMapper::clearAllStaticCaches();
		$this->register = new Register();
		$this->register->setId(11);
		$this->schema = new Schema();
		$this->schema->setId(91);
	}//end setUp()

	protected function tearDown(): void {
		MagicMapper::clearAllStaticCaches();
		parent::tearDown();
	}//end tearDown()

	/**
	 * A handler over a recording mapper.
	 *
	 * @param array<string, mixed> $world exists, current, required, stats, fails (method name).
	 *
	 * @return MagicTableHandler The handler.
	 */
	private function handler(array $world): MagicTableHandler {
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->magicMapper->method('getCacheKey')->willReturn(self::CACHE_KEY);
		$answers = [
			'checkTableExistsInDatabase' => $world['exists'],
			'createTableForRegisterSchema' => true,
			'buildTableColumnsFromSchema' => $world['required'],
			'getExistingTableColumns' => $world['current'] ?? [],
			'updateTableStructure' => $world['stats'] ?? [],
			'updateTableIndexes' => null,
			'storeRegisterSchemaVersion' => null,
		];
		foreach ($answers as $method => $answer) {
			$this->magicMapper->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method, $answer, $world): mixed {
					$this->calls[] = $method.'('.implode(', ', array_map([$this, 'show'], $args)).')';
					if (($world['fails'] ?? null) === $method) {
						throw new Exception('ddl refused');
					}

					return $answer;
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['line'], $context['file']);
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new MagicTableHandler(
			db: $this->createMock(IDBConnection::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
			magicMapper: $this->magicMapper
		);
	}//end handler()

	/**
	 * A call argument as text.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return string The text.
	 */
	public function show(mixed $value): string {
		if ($value === $this->register) {
			return 'register';
		}

		if ($value === $this->schema) {
			return 'schema';
		}

		return (string)json_encode($value);
	}//end show()

	/**
	 * A missing table is created and reported as all-added.
	 *
	 * @return void
	 */
	public function testAMissingTableIsCreated(): void {
		$required = ['id' => [], 'uuid' => [], 'version' => [], 'title' => [], 'status' => []];
		$stats = $this->handler(['exists' => false, 'required' => $required])
			->syncTableForRegisterSchema(register: $this->register, schema: $this->schema);

		$this->assertSame(
			[
				'success' => true,
				'created' => true,
				'metadataProperties' => 3,
				'regularProperties' => 2,
				'totalProperties' => 5,
				'columnsAdded' => 5,
				'columnsDeRequired' => 0,
				'columnsDropped' => 0,
				'columnsUnchanged' => 0,
				'columnsAddedList' => ['id', 'uuid', 'version', 'title', 'status'],
				'columnsDeRequiredList' => [],
				'columnsDroppedList' => [],
			],
			$stats
		);
		$this->assertSame(
			[
				'checkTableExistsInDatabase("'.self::TABLE.'")',
				'createTableForRegisterSchema(register, schema)',
				'buildTableColumnsFromSchema(schema)',
			],
			$this->calls
		);
		$where = ['registerId' => 11, 'schemaId' => 91, 'tableName' => self::TABLE];
		$this->assertSame(
			[
				['info', '[MagicTableHandler] Syncing register+schema table', $where],
				['info', '[MagicTableHandler] Table does not exist, creating it', $where],
			],
			$this->logs
		);
		$this->assertNull(MagicMapper::getTableExistsCache(key: self::CACHE_KEY));
		$this->assertFalse(MagicMapper::isTableColumnsVerified(cacheKey: self::CACHE_KEY));
	}//end testAMissingTableIsCreated()

	/**
	 * An existing table is altered, re-indexed, versioned and marked verified.
	 *
	 * @return void
	 */
	public function testAnExistingTableIsUpdated(): void {
		$required = ['id' => [], 'uuid' => [], 'register' => [], 'title' => [], 'status' => [], 'size' => []];
		$current = ['id' => [], 'uuid' => [], 'register' => [], 'title' => [], 'old' => [], 'gone' => [], 'kept' => []];
		$columnStats = [
			'columnsAdded' => ['status', 'size'],
			'columnsDeRequired' => ['title'],
			'columnsReRequired' => ['uuid'],
			'columnsDropped' => ['gone'],
		];
		$before = time();
		$stats = $this->handler(['exists' => true, 'required' => $required, 'current' => $current, 'stats' => $columnStats])
			->syncTableForRegisterSchema(register: $this->register, schema: $this->schema);

		$expected = [
			'success' => true,
			'metadataProperties' => 3,
			'regularProperties' => 3,
			'totalProperties' => 6,
			'columnsAdded' => 2,
			'columnsDeRequired' => 1,
			'columnsReRequired' => 1,
			'columnsDropped' => 1,
			'columnsUnchanged' => 4,
			'columnsAddedList' => ['status', 'size'],
			'columnsDeRequiredList' => ['title'],
			'columnsReRequiredList' => ['uuid'],
			'columnsDroppedList' => ['gone'],
		];
		$this->assertSame($expected, $stats);
		$this->assertSame(
			[
				'checkTableExistsInDatabase("'.self::TABLE.'")',
				'getExistingTableColumns("'.self::TABLE.'")',
				'buildTableColumnsFromSchema(schema)',
				'updateTableStructure("'.self::TABLE.'", '.json_encode($current).', '.json_encode($required).')',
				'updateTableIndexes("'.self::TABLE.'", register, schema)',
				'storeRegisterSchemaVersion(register, schema)',
			],
			$this->calls
		);
		$where = ['registerId' => 11, 'schemaId' => 91, 'tableName' => self::TABLE];
		$this->assertSame(
			[
				['info', '[MagicTableHandler] Syncing register+schema table', $where],
				['info', '[MagicTableHandler] Table exists, updating structure', $where],
				[
					'info',
					'[MagicTableHandler] Successfully updated register+schema table',
					['tableName' => self::TABLE, 'cacheKey' => self::CACHE_KEY, 'stats' => $expected],
				],
			],
			$this->logs
		);
		$this->assertGreaterThanOrEqual($before, MagicMapper::getTableExistsCache(key: self::CACHE_KEY));
		$this->assertTrue(MagicMapper::isTableColumnsVerified(cacheKey: self::CACHE_KEY));
	}//end testAnExistingTableIsUpdated()

	/**
	 * A failing alter is logged and rethrown, and the table is not marked verified.
	 *
	 * @return void
	 */
	public function testAFailingUpdateIsLoggedAndRethrown(): void {
		$handler = $this->handler(['exists' => true, 'required' => ['id' => []], 'current' => ['id' => []], 'fails' => 'updateTableStructure']);

		try {
			$handler->syncTableForRegisterSchema(register: $this->register, schema: $this->schema);
			$this->fail('The failure was swallowed');
		} catch (Exception $e) {
			$this->assertSame('ddl refused', $e->getMessage());
		}

		$this->assertSame(
			['error', '[MagicTableHandler] Failed to update register+schema table', ['tableName' => self::TABLE, 'error' => 'ddl refused']],
			end($this->logs)
		);
		$this->assertCount(4, $this->calls);
		$this->assertFalse(MagicMapper::isTableColumnsVerified(cacheKey: self::CACHE_KEY));
	}//end testAFailingUpdateIsLoggedAndRethrown()

	/**
	 * A failing create is logged and rethrown too.
	 *
	 * @return void
	 */
	public function testAFailingCreateIsLoggedAndRethrown(): void {
		$handler = $this->handler(['exists' => false, 'required' => ['id' => []], 'fails' => 'createTableForRegisterSchema']);

		try {
			$handler->syncTableForRegisterSchema(register: $this->register, schema: $this->schema);
			$this->fail('The failure was swallowed');
		} catch (Exception $e) {
			$this->assertSame('ddl refused', $e->getMessage());
		}

		$this->assertSame(
			['error', '[MagicTableHandler] Failed to update register+schema table', ['tableName' => self::TABLE, 'error' => 'ddl refused']],
			end($this->logs)
		);
		$this->assertSame(['checkTableExistsInDatabase("'.self::TABLE.'")', 'createTableForRegisterSchema(register, schema)'], $this->calls);
	}//end testAFailingCreateIsLoggedAndRethrown()
}//end class
