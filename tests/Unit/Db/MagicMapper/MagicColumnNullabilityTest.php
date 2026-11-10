<?php

/**
 * MagicColumnNullabilityTest: making magic-table columns required or optional.
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
use OCA\OpenRegister\Db\MagicMapper\MagicColumnNullability;
use OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner;
use OCA\OpenRegister\Db\MagicMapper\MagicNullabilityChange;
use OCA\OpenRegister\Db\MagicMapper\TableStructureSync;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicColumnNullability
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicNullabilityChange
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlFailure
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableStructureSync
 */
class MagicColumnNullabilityTest extends TestCase {

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
	 * Column names whose statement fails.
	 *
	 * @var list<string>
	 */
	private array $refuse = [];

	/**
	 * The nullability step on a recording connection.
	 *
	 * @return MagicColumnNullability
	 */
	private function nullability(): MagicColumnNullability {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				foreach ($this->refuse as $column) {
					if (str_contains($sql, '`' . $column . '`') === true || str_contains($sql, '"' . $column . '"') === true) {
						throw new RuntimeException('null values present');
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

		return new MagicColumnNullability(new MagicDdlRunner($db, $logger), $logger);
	}//end nullability()

	/**
	 * A sync where `a` is live nullable but now required, `b` is live NOT NULL
	 * but now optional, and `c` already matches.
	 *
	 * @param bool $isPostgres The platform.
	 *
	 * @return TableStructureSync
	 */
	private function sync(bool $isPostgres): TableStructureSync {
		$current = [
			'a' => ['type' => 'varchar', 'nullable' => true],
			'b' => ['type' => 'int', 'nullable' => false],
			'c' => ['type' => 'text'],
		];
		$required = [
			'a' => ['type' => 'string', 'length' => 40, 'nullable' => false],
			'b' => ['type' => 'integer'],
			'c' => ['type' => 'text', 'nullable' => true],
			'gone' => ['type' => 'text', 'nullable' => false],
		];

		return new TableStructureSync('openregister_table_1_2', 'oc_openregister_table_1_2', new MagicColumnDrift($current, $required), $isPostgres);
	}//end sync()

	public function testRequiringAColumnOnMySqlModifiesItNotNull(): void {
		$changed = $this->nullability()->apply(new MagicNullabilityChange($this->sync(false), false));

		$this->assertSame(['a'], $changed);
		$this->assertSame(['ALTER TABLE `oc_openregister_table_1_2` MODIFY COLUMN `a` VARCHAR(40) NOT NULL'], $this->statements);
		$this->assertSame(['debug [MagicMapper] Making column NOT NULL (now required) {"tableName":"openregister_table_1_2","columnName":"a"}'], $this->log);
	}//end testRequiringAColumnOnMySqlModifiesItNotNull()

	public function testRelaxingAColumnOnMySqlModifiesItNull(): void {
		$changed = $this->nullability()->apply(new MagicNullabilityChange($this->sync(false), true));

		$this->assertSame(['b'], $changed);
		$this->assertSame(['ALTER TABLE `oc_openregister_table_1_2` MODIFY COLUMN `b` INTEGER NULL'], $this->statements);
		$this->assertSame(['debug [MagicMapper] Making column nullable (no longer required) {"tableName":"openregister_table_1_2","columnName":"b"}'], $this->log);
	}//end testRelaxingAColumnOnMySqlModifiesItNull()

	public function testOnPostgresTheConstraintIsSetOrDropped(): void {
		$this->nullability()->apply(new MagicNullabilityChange($this->sync(true), false));
		$this->nullability()->apply(new MagicNullabilityChange($this->sync(true), true));

		$this->assertSame(
			[
				'ALTER TABLE "oc_openregister_table_1_2" ALTER COLUMN "a" SET NOT NULL',
				'ALTER TABLE "oc_openregister_table_1_2" ALTER COLUMN "b" DROP NOT NULL',
			],
			$this->statements
		);
	}//end testOnPostgresTheConstraintIsSetOrDropped()

	public function testAColumnThatCannotBeMadeNotNullIsLoggedAndLeftOut(): void {
		$this->refuse = ['a'];

		$changed = $this->nullability()->apply(new MagicNullabilityChange($this->sync(false), false));

		$this->assertSame([], $changed);
		$this->assertSame('warning [MagicMapper] Failed to make column NOT NULL (may contain null values) {"columnName":"a","error":"null values present"}', $this->log[1]);
	}//end testAColumnThatCannotBeMadeNotNullIsLoggedAndLeftOut()

	public function testAColumnThatCannotBeRelaxedSaysSo(): void {
		$this->refuse = ['b'];

		$this->assertSame([], $this->nullability()->apply(new MagicNullabilityChange($this->sync(true), true)));
		$this->assertSame('warning [MagicMapper] Failed to make column nullable {"columnName":"b","error":"null values present"}', $this->log[1]);
	}//end testAColumnThatCannotBeRelaxedSaysSo()

	public function testAppliesOnlyWhenTheRequiredAndLiveNullabilityDisagree(): void {
		$required = new MagicNullabilityChange($this->sync(false), false);
		$optional = new MagicNullabilityChange($this->sync(false), true);

		$this->assertTrue($required->applies('a', ['nullable' => false]));
		$this->assertFalse($required->applies('b', ['nullable' => false]));
		$this->assertFalse($required->applies('a', []));
		$this->assertTrue($optional->applies('b', []));
		$this->assertFalse($optional->applies('a', []));
		$this->assertFalse($optional->applies('c', ['nullable' => true]));
	}//end testAppliesOnlyWhenTheRequiredAndLiveNullabilityDisagree()
}//end class
