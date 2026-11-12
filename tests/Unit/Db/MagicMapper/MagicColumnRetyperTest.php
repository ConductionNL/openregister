<?php

/**
 * MagicColumnRetyperTest: the PostgreSQL-only column retypes of a magic table sync.
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

use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnRetyper;
use OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner;
use OCA\OpenRegister\Db\MagicMapper\TableStructureSync;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicColumnRetyper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlFailure
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableStructureSync
 */
class MagicColumnRetyperTest extends TestCase {

	/**
	 * Statements the connection ran.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * Log lines, as `level message context`.
	 *
	 * @var list<string>
	 */
	private array $log = [];

	/**
	 * A retyper whose connection refuses statements naming a column in $refuse.
	 *
	 * @param list<string> $refuse Column names whose ALTER fails.
	 *
	 * @return MagicColumnRetyper
	 */
	private function retyper(array $refuse = []): MagicColumnRetyper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($refuse): int {
				foreach ($refuse as $column) {
					if (str_contains($sql, '"' . $column . '"') === true) {
						throw new RuntimeException('cannot cast');
					}
				}

				$this->statements[] = $sql;
				return 0;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->log[] = $level . ' ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new MagicColumnRetyper(new MagicDdlRunner($db, $logger), $logger);
	}//end retyper()

	/**
	 * A sync whose live and required columns disagree on type.
	 *
	 * @param bool $isPostgres The platform.
	 *
	 * @return TableStructureSync
	 */
	private function sync(bool $isPostgres): TableStructureSync {
		$current = [
			'meta' => ['type' => 'JSONB'],
			'extra' => ['type' => 'json'],
			'owner' => ['type' => 'jsonb'],
			'link' => ['type' => 'json'],
			'name' => ['type' => 'varchar'],
		];
		$required = [
			'meta' => ['type' => 'json_ordered'],
			'extra' => ['type' => 'json_ordered'],
			'owner' => ['type' => 'string'],
			'link' => ['type' => 'string'],
			'name' => ['type' => 'string'],
			'absent' => ['type' => 'json_ordered'],
		];

		return new TableStructureSync('openregister_table_1_2', 'oc_openregister_table_1_2', new MagicColumnDrift($current, $required), $isPostgres);
	}//end sync()

	public function testOrderedJsonDoesNothingOutsidePostgres(): void {
		$this->assertSame([], $this->retyper()->orderedJson($this->sync(false)));
		$this->assertSame([], $this->statements);
	}//end testOrderedJsonDoesNothingOutsidePostgres()

	public function testOrderedJsonMovesOnlyJsonbColumnsToJson(): void {
		$retyped = $this->retyper()->orderedJson($this->sync(true));

		$this->assertSame(['meta'], $retyped);
		$this->assertSame(['ALTER TABLE "oc_openregister_table_1_2" ALTER COLUMN "meta" TYPE JSON USING "meta"::text::json'], $this->statements);
		$this->assertSame(['debug [MagicMapper] Migrated object-typed column from JSONB to JSON for key-order preservation {"columnName":"meta"}'], $this->log);
	}//end testOrderedJsonMovesOnlyJsonbColumnsToJson()

	public function testAFailedJsonRetypeIsLoggedAndNotReported(): void {
		$retyped = $this->retyper(['meta'])->orderedJson($this->sync(true));

		$this->assertSame([], $retyped);
		$this->assertSame(['warning [MagicMapper] Failed to migrate column from JSONB to JSON {"columnName":"meta","error":"cannot cast"}'], $this->log);
	}//end testAFailedJsonRetypeIsLoggedAndNotReported()

	public function testRelationsDoesNothingOutsidePostgres(): void {
		$this->assertSame([], $this->retyper()->relations($this->sync(false)));
		$this->assertSame([], $this->statements);
	}//end testRelationsDoesNothingOutsidePostgres()

	public function testRelationsRetypeJsonStringColumnsToVarchar(): void {
		$retyped = $this->retyper()->relations($this->sync(true));

		$this->assertSame(['owner', 'link'], $retyped);
		$this->assertSame(
			[
				'ALTER TABLE "oc_openregister_table_1_2" ALTER COLUMN "owner" TYPE VARCHAR(255) USING "owner" #>> \'{}\'',
				'ALTER TABLE "oc_openregister_table_1_2" ALTER COLUMN "link" TYPE VARCHAR(255) USING "link" #>> \'{}\'',
			],
			$this->statements
		);
		$this->assertStringContainsString('{"tableName":"openregister_table_1_2","columnName":"owner"}', $this->log[0]);
	}//end testRelationsRetypeJsonStringColumnsToVarchar()

	public function testARefusedRelationRetypeKeepsTheOthers(): void {
		$retyped = $this->retyper(['owner'])->relations($this->sync(true));

		$this->assertSame(['link'], $retyped);
		$this->assertStringStartsWith('warning [MagicMapper] Could not retype relation column to VARCHAR; it still holds data that is not a UUID reference.', $this->log[0]);
		$this->assertStringEndsWith('{"tableName":"openregister_table_1_2","columnName":"owner","error":"cannot cast"}', $this->log[0]);
	}//end testARefusedRelationRetypeKeepsTheOthers()
}//end class
