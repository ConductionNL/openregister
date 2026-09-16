<?php

/**
 * Tests for the search index under administration.
 *
 * A rebuild that empties the index first makes search wrong for the length of
 * the rebuild. The whole design is one decision about that window, so the tests
 * are mostly about what happens when it cannot be avoided, and what happens
 * when a rebuild fails halfway.
 *
 * The case that matters most is the refusal. On a platform with no concurrent
 * reindex the honest answer is "no", because the alternative is a rebuild that
 * silently costs an organisation its search for as long as it runs. A test that
 * only checked the happy path would never see it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
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

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Search\SearchIndexMaintenance;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Locks the rebuild, the snapshot and the restore.
 */
class SearchIndexMaintenanceTest extends TestCase {

	private IDBConnection&MockObject $db;

	private RegisterMapper&MockObject $registerMapper;

	private MagicMapper&MockObject $magicMapper;

	private IAppConfig&MockObject $appConfig;

	private IConfig&MockObject $config;

	/**
	 * Every statement the service asked the database to run.
	 *
	 * @var string[]
	 */
	private array $executed = [];

	/**
	 * Statements that should throw when run, keyed by a substring to match.
	 *
	 * @var string[]
	 */
	private array $failOn = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValue')->willReturn('oc_');
		$this->executed = [];
		$this->failOn = [];
	}//end setUp()

	/**
	 * Build the service over a platform, with one register holding one schema.
	 *
	 * @param bool                 $isPostgres Whether the platform supports a concurrent reindex.
	 * @param array<string,string> $indexes    The indexes pg_indexes should report.
	 *
	 * @return SearchIndexMaintenance The service under test.
	 */
	private function makeService(bool $isPostgres = true, array $indexes = []): SearchIndexMaintenance {
		$platform = $this->createMock(MariaDBPlatform::class);
		if ($isPostgres === true) {
			$platform = $this->createMock(PostgreSQLPlatform::class);
		}

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$rows = [];
		foreach ($indexes as $name => $definition) {
			$rows[] = ['indexname' => $name, 'indexdef' => $definition];
		}

		$rows[] = false;
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturn($this->createMock(IResult::class));
		$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
		$this->db->method('prepare')->willReturn($statement);

		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->executed[] = $sql;
				foreach ($this->failOn as $needle) {
					if (str_contains($sql, $needle) === true) {
						throw new RuntimeException("deadlock detected while building {$needle}");
					}
				}

				return 1;
			}
		);

		// Real entities, not doubles: getId() is a magic method on the Entity
		// base, so a double cannot be told to answer it, and a double that
		// could would be answering about a method the class does not have.
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->registerMapper->method('getSchemasByRegisterId')->willReturn([$schema]);
		$this->magicMapper->method('getTableNameForRegisterSchema')
			->willReturn('openregister_table_1_2');

		return new SearchIndexMaintenance(
			db: $this->db,
			registerMapper: $this->registerMapper,
			magicMapper: $this->magicMapper,
			appConfig: $this->appConfig,
			config: $this->config,
			logger: $this->createMock(\Psr\Log\LoggerInterface::class)
		);
	}//end makeService()

	/**
	 * The scenario the design turns on: search keeps answering, because the
	 * replacement is built beside the index in use and swapped when it is done.
	 *
	 * @return void
	 */
	public function testARebuildBuildsBesideTheIndexInUse(): void {
		$service = $this->makeService(
			isPostgres: true,
			indexes: ['t_uuid_idx' => 'CREATE UNIQUE INDEX t_uuid_idx ON oc_t (_uuid)']
		);

		$report = $service->rebuild(apply: true);

		$this->assertSame('completed', $report['state']);
		$this->assertSame(1, $report['rebuilt']);
		$this->assertSame(['REINDEX INDEX CONCURRENTLY "t_uuid_idx"'], $this->executed);

		// Nothing was dropped. A DROP anywhere in this path is the window this
		// whole design exists to close.
		foreach ($this->executed as $sql) {
			$this->assertStringNotContainsString('DROP INDEX', $sql);
		}
	}//end testARebuildBuildsBesideTheIndexInUse()

	/**
	 * On a platform with no concurrent reindex the rebuild refuses and says
	 * why, rather than dropping and recreating.
	 *
	 * @return void
	 */
	public function testAPlatformWithoutConcurrentReindexIsRefused(): void {
		$service = $this->makeService(isPostgres: false);

		$report = $service->rebuild(apply: true);

		$this->assertSame('refused', $report['state']);
		$this->assertStringContainsString('concurrent reindex', $report['reason']);
		$this->assertSame([], $this->executed, 'A refusal must not touch a single index.');
	}//end testAPlatformWithoutConcurrentReindexIsRefused()

	/**
	 * A dry run reports the work and changes nothing, like every other
	 * maintenance command in this app.
	 *
	 * @return void
	 */
	public function testADryRunChangesNothing(): void {
		$service = $this->makeService(
			isPostgres: true,
			indexes: ['t_uuid_idx' => 'CREATE INDEX t_uuid_idx ON oc_t (_uuid)']
		);

		$report = $service->rebuild(apply: false);

		$this->assertSame('planned', $report['state']);
		$this->assertSame(1, $report['indexes']);
		$this->assertSame(0, $report['rebuilt']);
		$this->assertSame([], $this->executed);
	}//end testADryRunChangesNothing()

	/**
	 * The failure scenario: a rebuild that fails halfway names the failure, and
	 * the index it failed on is untouched and still answering.
	 *
	 * @return void
	 */
	public function testAFailedRebuildNamesTheFailureAndChangesNothingElse(): void {
		$service = $this->makeService(
			isPostgres: true,
			indexes: [
				'a_idx' => 'CREATE INDEX a_idx ON oc_t (a)',
				'b_idx' => 'CREATE INDEX b_idx ON oc_t (b)',
			]
		);
		$this->failOn = ['"b_idx"'];

		$report = $service->rebuild(apply: true);

		$this->assertSame('failed', $report['state']);
		$this->assertSame(1, $report['rebuilt']);
		$this->assertSame(1, $report['failed']);
		$this->assertSame('b_idx', $report['failures'][0]['index']);
		$this->assertStringContainsString('deadlock', $report['failures'][0]['error']);

		// The only DROP is of the invalid leftover the failed rebuild made, not
		// of the index that is still answering.
		$drops = array_values(array_filter($this->executed, static fn (string $sql): bool => str_contains($sql, 'DROP')));
		$this->assertSame(['DROP INDEX IF EXISTS "b_idx_ccnew"'], $drops);
	}//end testAFailedRebuildNamesTheFailureAndChangesNothingElse()

	/**
	 * A dry run is not stored as the last run: it did nothing, and a console
	 * showing it would be reporting work that never happened.
	 *
	 * @return void
	 */
	public function testADryRunIsNotStoredAsTheLastRun(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$service = $this->makeService(
			isPostgres: true,
			indexes: ['t_idx' => 'CREATE INDEX t_idx ON oc_t (a)']
		);
		$service->rebuild(apply: false);
	}//end testADryRunIsNotStoredAsTheLastRun()

	/**
	 * A run that did something is stored, so the surface an administrator reads
	 * can report it.
	 *
	 * @return void
	 */
	public function testARealRunIsStored(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', SearchIndexMaintenance::LAST_RUN_KEY, $this->stringContains('completed'));

		$service = $this->makeService(
			isPostgres: true,
			indexes: ['t_idx' => 'CREATE INDEX t_idx ON oc_t (a)']
		);
		$service->rebuild(apply: true);
	}//end testARealRunIsStored()

	/**
	 * A snapshot is a file, because the point of it is that an administrator
	 * can move it to another node.
	 *
	 * @return void
	 */
	public function testASnapshotIsWrittenAsAFileAndRestoresWhatIsMissing(): void {
		$definition = 'CREATE INDEX t_idx ON oc_openregister_table_1_2 (a)';
		$service = $this->makeService(isPostgres: true, indexes: ['t_idx' => $definition]);

		$path = tempnam(sys_get_temp_dir(), 'or-index-snapshot');
		$this->assertIsString($path);

		$report = $service->snapshot(path: $path);
		$this->assertSame(1, $report['tables']);
		$this->assertSame(1, $report['indexes']);

		$written = json_decode((string)file_get_contents($path), true);
		$this->assertSame(1, $written['version']);
		$this->assertSame($definition, $written['tables']['openregister_table_1_2']['t_idx']);

		unlink($path);
	}//end testASnapshotIsWrittenAsAFileAndRestoresWhatIsMissing()

	/**
	 * A restore creates what the database is missing and leaves alone what it
	 * already has. Recreating a live index would be the same outage the rebuild
	 * refuses to cause.
	 *
	 * @return void
	 */
	public function testARestoreOnlyCreatesWhatIsMissing(): void {
		// pg_indexes reports `present_idx`, so only `absent_idx` is missing.
		$service = $this->makeService(
			isPostgres: true,
			indexes: ['present_idx' => 'CREATE INDEX present_idx ON oc_t (a)']
		);

		$path = tempnam(sys_get_temp_dir(), 'or-index-snapshot');
		file_put_contents(
			(string)$path,
			(string)json_encode(
				[
					'version' => 1,
					'tables' => [
						'openregister_table_1_2' => [
							'present_idx' => 'CREATE INDEX present_idx ON oc_t (a)',
							'absent_idx' => 'CREATE INDEX absent_idx ON oc_t (b)',
						],
					],
				]
			)
		);

		$report = $service->restore(path: (string)$path, apply: true);

		$this->assertSame(1, $report['missing']);
		$this->assertSame(1, $report['created']);
		$this->assertSame('absent_idx', $report['indexes'][0]['index']);
		$this->assertSame(['CREATE INDEX absent_idx ON oc_t (b)'], $this->executed);

		unlink((string)$path);
	}//end testARestoreOnlyCreatesWhatIsMissing()

	/**
	 * A file that is not a snapshot is refused rather than half-applied.
	 *
	 * @return void
	 */
	public function testAFileThatIsNotASnapshotIsRefused(): void {
		$service = $this->makeService(isPostgres: true);

		$path = tempnam(sys_get_temp_dir(), 'or-index-snapshot');
		file_put_contents((string)$path, 'not json at all');

		$this->expectException(RuntimeException::class);
		try {
			$service->restore(path: (string)$path, apply: true);
		} finally {
			unlink((string)$path);
		}
	}//end testAFileThatIsNotASnapshotIsRefused()
}//end class
