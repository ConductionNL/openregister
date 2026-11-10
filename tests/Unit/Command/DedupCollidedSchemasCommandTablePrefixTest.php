<?php

/**
 * Every statement of the schema split names its tables through the prefix
 * placeholder, and an unmoved table is looked up without any prefix, so the
 * command works on any `dbtableprefix`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DedupCollidedSchemasCommand;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenRegister\Command\DedupCollidedSchemasCommand
 */
class DedupCollidedSchemasCommandTablePrefixTest extends TestCase {

	/**
	 * The SQL the command handed to the connection, in order.
	 *
	 * @var string[]
	 */
	private array $statements = [];

	/**
	 * Tables the command asked about.
	 *
	 * @var string[]
	 */
	private array $lookups = [];

	/**
	 * Sequence names the command passed to lastInsertId().
	 *
	 * @var string[]
	 */
	private array $sequences = [];

	/**
	 * Build the command over a connection holding one shared schema.
	 *
	 * @param bool $tableExists Whether the register's magic table exists.
	 *
	 * @return CommandTester
	 */
	private function tester(bool $tableExists): CommandTester {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn(
			[
				$this->row(registerId: 3, app: 'first'),
				$this->row(registerId: 8, app: 'second'),
			]
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql) use ($result): IResult {
				$this->statements[] = $sql;
				return $result;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				return 1;
			}
		);
		$db->method('tableExists')->willReturnCallback(
			function (string $table) use ($tableExists): bool {
				$this->lookups[] = $table;
				return $tableExists;
			}
		);
		$db->method('lastInsertId')->willReturnCallback(
			function (string $sequence): int {
				$this->sequences[] = $sequence;
				return 50;
			}
		);

		return new CommandTester(new DedupCollidedSchemasCommand(db: $db));
	}//end tester()

	/**
	 * One collision row.
	 *
	 * @param int    $registerId The register's id.
	 * @param string $app        The register's application.
	 *
	 * @return array<string, mixed>
	 */
	private function row(int $registerId, string $app): array {
		return [
			'schema_id'     => 21,
			'schema_slug'   => 'character',
			'schema_app'    => 'first',
			'register_id'   => $registerId,
			'register_slug' => 'register-' . $registerId,
			'register_app'  => $app,
		];
	}//end row()

	/**
	 * The split never spells out a literal prefix.
	 *
	 * @return void
	 */
	public function testNoStatementSpellsOutAPrefix(): void {
		$tester = $this->tester(tableExists: true);

		$this->assertSame(0, $tester->execute(['--apply' => true]));

		$this->assertCount(5, $this->statements);
		foreach ($this->statements as $sql) {
			$this->assertStringNotContainsString('oc_', $sql);
			$this->assertStringContainsString('*PREFIX*openregister_', $sql);
		}

		$this->assertSame(['*PREFIX*openregister_schemas'], $this->sequences);
	}//end testNoStatementSpellsOutAPrefix()

	/**
	 * The magic table of the register moves to the clone's id.
	 *
	 * @return void
	 */
	public function testTheMagicTableFollowsTheClone(): void {
		$tester = $this->tester(tableExists: true);

		$tester->execute(['--apply' => true]);

		$this->assertSame(['openregister_table_8_21'], $this->lookups);
		$this->assertSame(
			'ALTER TABLE *PREFIX*openregister_table_8_21 RENAME TO *PREFIX*openregister_table_8_50',
			$this->statements[3]
		);
		$this->assertSame(
			'UPDATE *PREFIX*openregister_table_8_50 SET _schema = :new WHERE _schema = :old',
			$this->statements[4]
		);
	}//end testTheMagicTableFollowsTheClone()

	/**
	 * A register without a magic table only gets its schema row split.
	 *
	 * @return void
	 */
	public function testAMissingMagicTableIsLeftAlone(): void {
		$tester = $this->tester(tableExists: false);

		$this->assertSame(0, $tester->execute(['--apply' => true]));

		$this->assertCount(3, $this->statements);
	}//end testAMissingMagicTableIsLeftAlone()
}//end class
