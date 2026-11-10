<?php

/**
 * Characterisation (golden) test for `occ openregister:schemas:dedup`.
 *
 * Pins the exact console output, exit code and statement order for: no
 * collisions, the register and schema filters, owner choice (application match,
 * then lowest register id), dry run, a successful split, a failed split rolled
 * back, and a split without a magic table. Written against the code before it
 * was split into smaller methods; it must keep passing unchanged afterwards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
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

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DedupCollidedSchemasCommand;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden outcomes of the collided-schema split command.
 */
class DedupCollidedSchemasCommandGoldenTest extends TestCase {

	/**
	 * Ordered log of database calls.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * One collision row.
	 *
	 * @param int         $schemaId    The schema id.
	 * @param string|null $schemaApp   The schema's application.
	 * @param int         $registerId  The register id.
	 * @param string|null $registerApp The register's application.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(int $schemaId, ?string $schemaApp, int $registerId, ?string $registerApp): array {
		return [
			'schema_id'     => $schemaId,
			'schema_slug'   => 's'.$schemaId,
			'schema_app'    => $schemaApp,
			'register_id'   => $registerId,
			'register_slug' => 'r'.$registerId,
			'register_app'  => $registerApp,
		];
	}//end row()

	/**
	 * Run the command over scripted collision rows.
	 *
	 * @param array<string, mixed>        $input       Command input.
	 * @param array<int, array>           $rows        Rows the collision query returns.
	 * @param array<int, bool>            $failForReg  Register ids whose repoint UPDATE throws.
	 * @param array<string, bool>         $tables      Existing magic tables (unprefixed names).
	 *
	 * @return array{0: int, 1: string, 2: array<int, string>} Exit code, display, call log.
	 */
	private function runDedup(array $input, array $rows, array $failForReg=[], array $tables=[]): array {
		$this->calls = [];
		$nextId      = 500;

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use ($result): IResult {
				$this->calls[] = 'query '.(str_contains($sql, 'AND s.id = :sid') ? 'with-sid ' : '').json_encode($params)
					.(str_ends_with($sql, ' ORDER BY s.id, r.id') ? ' ordered' : '');
				return $result;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params=[]) use ($failForReg): int {
				$verb = strtok(trim($sql), ' ');
				unset($params['uuid']);
				$this->calls[] = 'stmt '.$verb.' '.json_encode($params);
				if (str_starts_with(trim($sql), 'ALTER') === true || str_starts_with(trim($sql), 'UPDATE *PREFIX*openregister_table') === true) {
					$this->calls[] = '  sql '.preg_replace('/\s+/', ' ', trim($sql));
				}

				if ($verb === 'UPDATE' && isset($params['rid']) === true && ($failForReg[(int)$params['rid']] ?? false) === true) {
					throw new RuntimeException('type error');
				}

				return 1;
			}
		);
		$db->method('tableExists')->willReturnCallback(
			function (string $table) use ($tables): bool {
				$this->calls[] = 'tableExists '.$table;
				return ($tables[$table] ?? false);
			}
		);
		$db->method('lastInsertId')->willReturnCallback(
			function () use (&$nextId): int {
				return $nextId++;
			}
		);
		$db->method('beginTransaction')->willReturnCallback(function (): void { $this->calls[] = 'begin'; });
		$db->method('commit')->willReturnCallback(function (): void { $this->calls[] = 'commit'; });
		$db->method('rollBack')->willReturnCallback(function (): void { $this->calls[] = 'rollback'; });

		$tester = new CommandTester(new DedupCollidedSchemasCommand(db: $db));
		$code   = $tester->execute($input);

		return [$code, $tester->getDisplay(), $this->calls];
	}//end runDedup()

	/**
	 * No collisions (also after the register filter drops every group).
	 *
	 * @return void
	 */
	public function testNoCollisions(): void {
		[$code, $display, $calls] = $this->runDedup(['--schema' => '7'], []);
		self::assertSame(0, $code);
		self::assertSame("No collided schemas found.\n", $display);
		self::assertSame(['query with-sid {"sid":7} ordered'], $calls);

		[$code, $display] = $this->runDedup(['--register' => 'zz'], [$this->row(1, null, 2, null), $this->row(1, null, 3, null)]);
		self::assertSame(0, $code);
		self::assertSame("No collided schemas found.\n", $display);
	}//end testNoCollisions()

	/**
	 * Dry run: owner by application, else lowest id; register filter keeps whole groups.
	 *
	 * @return void
	 */
	public function testDryRun(): void {
		$rows = [
			$this->row(21, 'app', 9, 'other'),
			$this->row(21, 'app', 4, 'app'),
			$this->row(21, 'app', 2, null),
			$this->row(30, null, 8, 'x'),
			$this->row(30, null, 6, 'y'),
			$this->row(40, '', 5, ''),
			$this->row(40, '', 1, 'z'),
			$this->row(50, 'q', 11, 'a'),
			$this->row(50, 'q', 12, 'b'),
		];

		[$code, $display, $calls] = $this->runDedup(['--register' => 'r8'], $rows);
		self::assertSame(0, $code);
		self::assertSame(
			"Found 1 schema(s) shared by more than one register.\n"
			."DRY RUN — pass --apply to write. Nothing has been changed.\n"
			."\nschema #30 s30 (application=-) — owner: register #6 r6\n"
			."    would split -> register #8 r8\n"
			."\nWould split 1 register/schema pair(s); 0 failure(s).\n",
			$display
		);
		self::assertSame(['query [] ordered'], $calls);

		[$code, $display] = $this->runDedup([], $rows);
		self::assertSame(0, $code);
		self::assertSame(
			"Found 4 schema(s) shared by more than one register.\n"
			."DRY RUN — pass --apply to write. Nothing has been changed.\n"
			."\nschema #21 s21 (application=app) — owner: register #4 r4\n"
			."    would split -> register #9 r9\n"
			."    would split -> register #2 r2\n"
			."\nschema #30 s30 (application=-) — owner: register #6 r6\n"
			."    would split -> register #8 r8\n"
			."\nschema #40 s40 (application=) — owner: register #1 r1\n"
			."    would split -> register #5 r5\n"
			."\nschema #50 s50 (application=q) — owner: register #11 r11\n"
			."    would split -> register #12 r12\n"
			."\nWould split 5 register/schema pair(s); 0 failure(s).\n",
			$display
		);
	}//end testDryRun()

	/**
	 * Apply: a split with a table, a rolled-back failure, a split without a table.
	 *
	 * @return void
	 */
	public function testApply(): void {
		$rows = [
			$this->row(21, 'app', 4, 'app'),
			$this->row(21, 'app', 9, 'other'),
			$this->row(21, 'app', 3, null),
			$this->row(21, 'app', 7, 'seven'),
		];

		[$code, $display, $calls] = $this->runDedup(['--apply' => true], $rows, [3 => true], ['openregister_table_9_21' => true]);

		self::assertSame(1, $code);
		self::assertSame(
			"Found 1 schema(s) shared by more than one register.\n"
			."\nschema #21 s21 (application=app) — owner: register #4 r4\n"
			."    split -> register #9 r9 now owns schema #500\n"
			."    failed register #3: type error\n"
			."    split -> register #7 r7 now owns schema #502\n"
			."\nSplit 2 register/schema pair(s); 1 failure(s).\n",
			$display
		);
		self::assertSame(
			[
				'query [] ordered',
				'begin',
				'stmt INSERT {"app":"other","sid":21}',
				'stmt UPDATE {"sidTxt":"21","sidQuoted":"\"21\"","newIdTxt":"500","rid":9}',
				'tableExists openregister_table_9_21',
				'stmt ALTER []',
				'  sql ALTER TABLE *PREFIX*openregister_table_9_21 RENAME TO *PREFIX*openregister_table_9_500',
				'stmt UPDATE {"new":"500","old":"21"}',
				'  sql UPDATE *PREFIX*openregister_table_9_500 SET _schema = :new WHERE _schema = :old',
				'commit',
				'begin',
				'stmt INSERT {"app":null,"sid":21}',
				'stmt UPDATE {"sidTxt":"21","sidQuoted":"\"21\"","newIdTxt":"501","rid":3}',
				'rollback',
				'begin',
				'stmt INSERT {"app":"seven","sid":21}',
				'stmt UPDATE {"sidTxt":"21","sidQuoted":"\"21\"","newIdTxt":"502","rid":7}',
				'tableExists openregister_table_7_21',
				'commit',
			],
			$calls
		);

		[$code] = $this->runDedup(['--apply' => true], array_slice($rows, 0, 2));
		self::assertSame(0, $code);
	}//end testApply()
}//end class
