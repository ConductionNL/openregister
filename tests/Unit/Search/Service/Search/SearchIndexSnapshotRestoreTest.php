<?php

/**
 * Tests for restoring search indexes from a snapshot file.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Search;


use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;
use OCA\OpenRegister\Search\Service\Search\SearchIndexSnapshotRestore;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexSnapshotRestore
 */
class SearchIndexSnapshotRestoreTest extends TestCase {

	private string $path = '';

	/**
	 * Statements the restore ran.
	 *
	 * @var string[]
	 */
	private array $executed = [];

	protected function setUp(): void {
		$this->path = tempnam(sys_get_temp_dir(), 'or-restore-');
		$this->executed = [];
	}

	protected function tearDown(): void {
		if (is_file($this->path) === true) {
			unlink($this->path);
		}
	}

	public function testOnlyTheMissingIndexesAreCreated(): void {
		file_put_contents($this->path, json_encode(['tables' => ['t_1' => ['idx_have' => 'CREATE INDEX idx_have', 'idx_gap' => 'CREATE INDEX idx_gap']]]));

		$report = $this->restore()->restore(path: $this->path, pass: new SearchIndexPass(apply: true, progress: null));

		$this->assertSame(['CREATE INDEX idx_gap'], $this->executed);
		$this->assertSame(1, $report['missing']);
		$this->assertSame(1, $report['created']);
		$this->assertSame([['table' => 't_1', 'index' => 'idx_gap']], $report['indexes']);
	}

	public function testADryRunCreatesNothing(): void {
		file_put_contents($this->path, json_encode(['tables' => ['t_1' => ['idx_gap' => 'CREATE INDEX idx_gap']]]));

		$report = $this->restore()->restore(path: $this->path, pass: new SearchIndexPass(apply: false, progress: null));

		$this->assertSame([], $this->executed);
		$this->assertSame(1, $report['missing']);
		$this->assertSame(0, $report['created']);
	}

	public function testAFileWithoutTablesIsRefused(): void {
		file_put_contents($this->path, '{"version":1}');

		$this->expectException(RuntimeException::class);
		$this->restore()->restore(path: $this->path, pass: new SearchIndexPass(apply: true, progress: null));
	}

	private function restore(): SearchIndexSnapshotRestore {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturn($this->createMock(IResult::class));
		$statement->method('fetch')->willReturnOnConsecutiveCalls(['indexname' => 'idx_have', 'indexdef' => 'CREATE INDEX idx_have'], false);
		$db->method('prepare')->willReturn($statement);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->executed[] = $sql;
				return 1;
			}
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$maintenance = new SearchIndexMaintenance(
			db: $db,
			registerMapper: $this->createMock(RegisterMapperInterface::class),
			magicMapper: $this->createMock(MagicMapperInterface::class),
			appConfig: $this->createMock(IAppConfig::class),
			config: $config,
			logger: new NullLogger()
		);

		return new SearchIndexSnapshotRestore(maintenance: $maintenance, db: $db);
	}
}//end class
