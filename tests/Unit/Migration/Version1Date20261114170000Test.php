<?php

/**
 * Unit tests for Version1Date20261114170000: the expression index on the lowered schema slug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-write-sub-500ms/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114170000;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The index is created where the expression index exists, skipped where they do not,
 * and a refusal never fails the upgrade.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114170000
 */
class Version1Date20261114170000Test extends TestCase {

	public function testPostgresGetsTheIndexOnTheLookupsOwnExpression(): void {
		$connection = $this->connection(provider: IDBConnection::PLATFORM_POSTGRES);
		$connection->expects($this->once())->method('executeStatement')
			->with('CREATE INDEX IF NOT EXISTS idx_schemas_lower_slug ON *PREFIX*openregister_schemas (LOWER(slug))')
			->willReturn(0);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Created idx_schemas_lower_slug (or it was already present).');

		(new Version1Date20261114170000($connection))->postSchemaChange($output, static fn () => null, []);
	}

	public function testSqliteGetsItToo(): void {
		$connection = $this->connection(provider: IDBConnection::PLATFORM_SQLITE);
		$connection->expects($this->once())->method('executeStatement')->willReturn(0);

		(new Version1Date20261114170000($connection))->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	public function testAPlatformWithoutTheExpressionIndexIsLeftAlone(): void {
		foreach ([IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_ORACLE] as $provider) {
			$connection = $this->connection(provider: $provider);
			$connection->expects($this->never())->method('executeStatement');
			$output = $this->createMock(IOutput::class);
			$output->expects($this->once())->method('info')
				->with('Skipping idx_schemas_lower_slug: this expression index is created on PostgreSQL and SQLite only.');

			(new Version1Date20261114170000($connection))->postSchemaChange($output, static fn () => null, []);
		}
	}

	public function testARefusedStatementIsReportedNotThrown(): void {
		$connection = $this->connection(provider: IDBConnection::PLATFORM_POSTGRES);
		$connection->method('executeStatement')->willThrowException(new RuntimeException('permission denied'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Could not create idx_schemas_lower_slug: permission denied');
		$output->expects($this->never())->method('info');

		(new Version1Date20261114170000($connection))->postSchemaChange($output, static fn () => null, []);
	}

	/**
	 * A connection double on a platform, whose query builder prefixes table names.
	 *
	 * @param string $provider The IDBConnection::PLATFORM_* value.
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function connection(string $provider): IDBConnection {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getTableName')->willReturnCallback(static fn (string $table): string => '*PREFIX*' . $table);
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getDatabaseProvider')->willReturn($provider);
		$connection->method('getQueryBuilder')->willReturn($qb);

		return $connection;
	}
}
