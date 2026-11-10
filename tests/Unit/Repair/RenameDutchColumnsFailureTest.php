<?php

/**
 * RenameDutchColumns leaves a column alone when its back-fill or its column listing fails.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\RenameDutchColumns;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The two failure branches the existing test leaves: a back-fill UPDATE that
 * the database rejects, and a table whose columns cannot be listed.
 */
class RenameDutchColumnsFailureTest extends TestCase {

	private const SHARD = 'oc_openregister_table_5_7';

	private IDBConnection&MockObject $db;

	/** @var list<array{0: string, 1: array}> */
	private array $warnings = [];

	/** @var list<string> */
	private array $info = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$registers = $this->createMock(IResult::class);
		$registers->method('fetchAll')->willReturn([5]);
		$this->db->method('executeQuery')->willReturn($registers);
		$this->db->method('getDatabasePlatform')->willReturn(
			new class {
				public function quoteSingleIdentifier(string $identifier): string {
					return '"' . $identifier . '"';
				}
			}
		);
	}

	/**
	 * @param list<array<string, string>> $rows Rows the statement yields.
	 */
	private function statement(array $rows): IPreparedStatement {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		return $statement;
	}

	private function runStep(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->warnings[] = [$message, $context];
		});
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->info[] = $message;
		});

		(new RenameDutchColumns($this->db, $logger))->run($output);
	}

	public function testARejectedBackfillIsLoggedAndNotCounted(): void {
		$this->db->method('prepare')->willReturnCallback(
			fn (string $sql): IPreparedStatement => str_contains($sql, 'information_schema.tables')
				? $this->statement([['table_name' => self::SHARD]])
				: $this->statement([['column_name' => 'stem'], ['column_name' => 'vote']])
		);
		$this->db->expects($this->once())->method('executeStatement')
			->with('UPDATE "' . self::SHARD . '" SET "vote" = "stem" WHERE "vote" IS NULL AND "stem" IS NOT NULL')
			->willThrowException(new Exception('deadlock'));

		$this->runStep();

		$this->assertSame('RenameDutchColumns: statement failed; leaving the column as it was.', $this->warnings[0][0]);
		$this->assertSame('deadlock', $this->warnings[0][1]['exception']);
		$this->assertSame(['RenameDutchColumns: 0 renamed, 0 back-filled, 0 refused, across 1 shard table(s).'], $this->info);
	}

	public function testATableWhoseColumnsCannotBeListedIsSkipped(): void {
		$this->db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				if (str_contains($sql, 'information_schema.tables') === true) {
					return $this->statement([['table_name' => self::SHARD]]);
				}
				throw new Exception('columns view denied');
			}
		);
		$this->db->expects($this->never())->method('executeStatement');

		$this->runStep();

		$this->assertSame('RenameDutchColumns: could not read columns; skipping table.', $this->warnings[0][0]);
		$this->assertSame(['table' => self::SHARD, 'exception' => 'columns view denied'], $this->warnings[0][1]);
		$this->assertSame(['RenameDutchColumns: 0 renamed, 0 back-filled, 0 refused, across 1 shard table(s).'], $this->info);
	}
}
