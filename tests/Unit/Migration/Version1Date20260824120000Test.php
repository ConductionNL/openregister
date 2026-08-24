<?php

/**
 * Unit coverage for the run_as migration and its schedule-trigger cutover.
 *
 * The interesting half is `declareScheduleIdentities()`, which rewrites stored
 * JSON. Two failure modes it has to avoid, both silent:
 *
 *  1. A mid-cutover node stores `config: []`, which `json_decode` returns as an
 *     empty LIST rather than an empty map. Writing a key into it re-encodes as
 *     `{"0":…}`-shaped nonsense, and the flow still saves.
 *  2. A flow with no owner has no identity to promote. Inventing one is the exact
 *     defect this change exists to remove, so it must be left alone and REPORTED.
 *
 * A migration is also the one thing that cannot be re-run to fix a mistake, so
 * idempotence is asserted rather than assumed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20260824120000;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Locks the backfill and the node cutover.
 */
class Version1Date20260824120000Test extends TestCase {

	/**
	 * Every statement the migration executed, in order.
	 *
	 * @var array<int, array{sql: string, params: array}>
	 */
	private array $statements = [];

	/**
	 * Flow rows the fake connection returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $flowRows = [];

	/**
	 * How many runs are reported as still unattributed after the backfill.
	 *
	 * @var integer
	 */
	private int $unresolved = 0;

	/**
	 * A connection double recording writes and answering the two reads.
	 *
	 * @return IDBConnection The double.
	 */
	private function connection(): IDBConnection {
		$connection = $this->createMock(IDBConnection::class);

		$connection->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->statements[] = ['sql' => $sql, 'params' => $params];

				return 1;
			}
		);

		$connection->method('executeQuery')->willReturnCallback(
			function (string $sql) {
				// The COUNT probe and the flow scan are told apart by their SQL,
				// because that is what the migration actually distinguishes them by.
				if (str_contains($sql, 'COUNT(*)') === true) {
					return $this->resultDouble(one: $this->unresolved, all: []);
				}

				return $this->resultDouble(one: null, all: $this->flowRows);
			}
		);

		return $connection;
	}

	/**
	 * A result double.
	 *
	 * @param mixed $one What fetchOne() returns.
	 * @param array $all What fetchAll() returns.
	 *
	 * @return IResult The result double.
	 */
	private function resultDouble(mixed $one, array $all): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($one);
		$result->method('fetchAll')->willReturn($all);

		return $result;
	}

	/**
	 * Run postSchemaChange against the doubles.
	 *
	 * @return array<int, string> The output lines, info and warning alike.
	 */
	private function runMigration(): array {
		$lines = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $m) use (&$lines): void {
			$lines[] = $m;
		});
		$output->method('warning')->willReturnCallback(static function (string $m) use (&$lines): void {
			$lines[] = $m;
		});

		$migration = new Version1Date20260824120000($this->connection());
		$migration->postSchemaChange($output, static fn () => null, []);

		return $lines;
	}

	/**
	 * The stored nodes of the one flow the migration rewrote.
	 *
	 * @return array|null The decoded nodes, or null when nothing was written.
	 */
	private function writtenNodes(): ?array {
		foreach ($this->statements as $statement) {
			if (str_contains($statement['sql'], 'SET `nodes`') === true) {
				return json_decode((string)$statement['params'][0], true);
			}
		}

		return null;
	}

	/**
	 * run_as is backfilled from triggered_by, column to column.
	 *
	 * Asserting the SQL text is the point: the failure mode of getting this wrong
	 * is that every row's `run_as` becomes the literal string "triggered_by",
	 * which is an unresolvable identity on every existing run and would look like
	 * a successful migration.
	 *
	 * @return void
	 */
	public function testTheBackfillCopiesTheColumnNotItsName(): void {
		$this->runMigration();

		$backfill = null;
		foreach ($this->statements as $statement) {
			if (str_contains($statement['sql'], 'SET `run_as` = `triggered_by`') === true) {
				$backfill = $statement['sql'];
			}
		}

		$this->assertNotNull($backfill, 'the backfill statement must run');
		$this->assertStringContainsString('`run_as` IS NULL', $backfill, 'only unset rows may be touched');
		$this->assertStringNotContainsString("'triggered_by'", $backfill, 'the column must not be quoted as a literal');
	}

	/**
	 * A mid-cutover trigger gets its owner promoted into a real map.
	 *
	 * @return void
	 */
	public function testAnEmptyConfigBecomesAMapNotAList(): void {
		$this->flowRows = [
			[
				'id' => 1,
				'uuid' => 'flow-1',
				'owner' => 'admin',
				'nodes' => json_encode(
					[
						['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => []],
						['id' => 'done', 'type' => 'openregister.end', 'config' => []],
					]
				),
			],
		];

		$this->runMigration();

		$nodes = $this->writtenNodes();
		$this->assertNotNull($nodes, 'the flow must be rewritten');
		$this->assertSame(['runAs' => 'admin'], $nodes[0]['config']);
		$this->assertCount(2, $nodes, 'no node may be lost in the round trip');
		$this->assertSame('openregister.end', $nodes[1]['type'], 'untouched nodes keep their shape');
	}

	/**
	 * A trigger that already names somebody is left exactly as it is.
	 *
	 * Re-running a migration must not clobber an identity a later edit set.
	 *
	 * @return void
	 */
	public function testAnAlreadyDeclaredIdentityIsNotOverwritten(): void {
		$this->flowRows = [
			[
				'id' => 1,
				'uuid' => 'flow-1',
				'owner' => 'admin',
				'nodes' => json_encode(
					[['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => ['runAs' => 'carol']]]
				),
			],
		];

		$this->runMigration();

		$this->assertNull($this->writtenNodes(), 'a flow needing no change must not be written at all');
	}

	/**
	 * A flow with no owner is reported, not guessed at.
	 *
	 * @return void
	 */
	public function testAFlowWithNoOwnerIsReportedRatherThanInvented(): void {
		$this->flowRows = [
			[
				'id' => 1,
				'uuid' => 'orphan-flow',
				'owner' => '',
				'nodes' => json_encode(
					[['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => []]]
				),
			],
		];

		$lines = $this->runMigration();

		$this->assertNull($this->writtenNodes(), 'no identity may be invented');
		$this->assertNotEmpty(
			array_filter($lines, static fn (string $l): bool => str_contains($l, 'orphan-flow')),
			'the flow that will refuse to fire must be named in the output'
		);
	}

	/**
	 * A non-schedule flow is untouched.
	 *
	 * @return void
	 */
	public function testAFlowWithNoScheduleTriggerIsUntouched(): void {
		$this->flowRows = [
			[
				'id' => 1,
				'uuid' => 'flow-1',
				'owner' => 'admin',
				'nodes' => json_encode(
					[['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []]]
				),
			],
		];

		$this->runMigration();

		$this->assertNull($this->writtenNodes());
	}

	/**
	 * Undecodable stored JSON is skipped rather than fataling the upgrade.
	 *
	 * @return void
	 */
	public function testUndecodableNodesDoNotBreakTheUpgrade(): void {
		$this->flowRows = [
			['id' => 1, 'uuid' => 'flow-1', 'owner' => 'admin', 'nodes' => '{not json'],
		];

		$this->runMigration();

		$this->assertNull($this->writtenNodes());
	}

	/**
	 * Runs left with no identity at all are reported as a warning.
	 *
	 * A count of zero is worth printing too — it is the difference between "no
	 * unattributed runs" and "the query never ran".
	 *
	 * @return void
	 */
	public function testUnattributableRunsAreReported(): void {
		$this->unresolved = 4;

		$lines = $this->runMigration();

		$this->assertNotEmpty(
			array_filter($lines, static fn (string $l): bool => str_contains($l, '4 flow run(s)')),
			'runs that cannot be attributed must be counted in the output'
		);
	}

	/**
	 * A clean instance says so explicitly.
	 *
	 * @return void
	 */
	public function testACleanInstanceReportsThatEveryRunIsAttributed(): void {
		$this->unresolved = 0;

		$lines = $this->runMigration();

		$this->assertNotEmpty(
			array_filter(
				$lines,
				static fn (string $l): bool => str_contains($l, 'every flow run carries an authorization subject')
			)
		);
	}
}
