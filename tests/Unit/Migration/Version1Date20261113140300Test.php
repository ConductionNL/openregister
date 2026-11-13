<?php

/**
 * Version1Date20261113140300Test: the unaccent bootstrap and its fold wrapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/search-accent-insensitive/tasks.md#1.1
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Closure;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Migration\Version1Date20261113140300;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version1Date20261113140300::class)]
final class Version1Date20261113140300Test extends TestCase {

	private IDBConnection&MockObject $connection;

	private Version1Date20261113140300 $migration;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = $this->createMock(IDBConnection::class);
		$this->migration  = new Version1Date20261113140300($this->connection);
	}//end setUp()

	/**
	 * A bare schema closure; the migration gates on no table.
	 *
	 * @return Closure
	 */
	private function schemaClosure(): Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		return fn () => $schema;
	}//end schemaClosure()

	/**
	 * Record every executed statement, failing the ones matching a needle.
	 *
	 * @param array<int, string> $statements Receives the statements.
	 * @param string|null        $failOn     A substring whose statement throws.
	 *
	 * @return void
	 */
	private function recordStatements(array &$statements, ?string $failOn=null): void {
		$this->connection->method('executeStatement')
			->willReturnCallback(
				function (string $sql) use (&$statements, $failOn): int {
					$statements[] = $sql;
					if ($failOn !== null && str_contains($sql, $failOn) === true) {
						throw new \OCP\DB\Exception('permission denied');
					}

					return 0;
				}
			);
	}//end recordStatements()

	/**
	 * An existence check answering present or absent.
	 *
	 * @param bool $present Whether unaccent is installed.
	 *
	 * @return void
	 */
	private function extensionPresent(bool $present): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($present === true ? 1 : false);
		$this->connection->method('executeQuery')
			->with("SELECT 1 FROM pg_extension WHERE extname = 'unaccent'")
			->willReturn($result);
	}//end extensionPresent()

	public function testDoesNothingOutsidePostgres(): void {
		$output = $this->createMock(IOutput::class);
		$this->connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$this->connection->expects($this->never())->method('executeStatement');
		$output->expects($this->once())->method('info')->with($this->stringContains('Skipping unaccent bootstrap'));

		$this->migration->postSchemaChange($output, $this->schemaClosure(), []);
	}//end testDoesNothingOutsidePostgres()

	public function testCreatesTheExtensionThenTheImmutableWrapper(): void {
		$output = $this->createMock(IOutput::class);
		$this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$statements = [];
		$this->recordStatements($statements);
		$output->expects($this->never())->method('warning');
		$output->expects($this->once())->method('info')->with($this->stringContains('openregister_fold() are available'));

		$this->migration->postSchemaChange($output, $this->schemaClosure(), []);

		$this->assertSame(
			[
				'CREATE EXTENSION IF NOT EXISTS unaccent',
				'CREATE OR REPLACE FUNCTION openregister_fold(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT'
				. " AS \$\$ SELECT lower(public.unaccent('public.unaccent', \$1)) \$\$",
			],
			$statements
		);
	}//end testCreatesTheExtensionThenTheImmutableWrapper()

	public function testAnExistingExtensionStillGetsTheWrapperWhenCreateIsRefused(): void {
		$output = $this->createMock(IOutput::class);
		$this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$statements = [];
		$this->recordStatements($statements, 'CREATE EXTENSION');
		$this->extensionPresent(true);
		$output->expects($this->never())->method('warning');

		$this->migration->postSchemaChange($output, $this->schemaClosure(), []);

		$this->assertCount(2, $statements);
		$this->assertStringStartsWith('CREATE OR REPLACE FUNCTION openregister_fold', $statements[1]);
	}//end testAnExistingExtensionStillGetsTheWrapperWhenCreateIsRefused()

	public function testAMissingExtensionWarnsAndCreatesNoWrapper(): void {
		$output = $this->createMock(IOutput::class);
		$this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$statements = [];
		$this->recordStatements($statements, 'CREATE EXTENSION');
		$this->extensionPresent(false);
		$output->expects($this->once())->method('warning')->with($this->stringContains('unaccent extension is not installed'));

		$this->migration->postSchemaChange($output, $this->schemaClosure(), []);

		$this->assertSame(['CREATE EXTENSION IF NOT EXISTS unaccent'], $statements);
	}//end testAMissingExtensionWarnsAndCreatesNoWrapper()

	public function testAFailedWrapperWarnsWithoutThrowing(): void {
		$output = $this->createMock(IOutput::class);
		$this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$statements = [];
		$this->recordStatements($statements, 'CREATE OR REPLACE FUNCTION');
		$output->expects($this->once())->method('warning')->with($this->stringContains('Could not create openregister_fold()'));

		$this->migration->postSchemaChange($output, $this->schemaClosure(), []);

		$this->assertCount(2, $statements);
	}//end testAFailedWrapperWarnsWithoutThrowing()
}//end class
