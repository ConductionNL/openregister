<?php

/**
 * Version1Date20261114120000: the stemmed-match GIN indexes, PostgreSQL only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Migration\Version1Date20261114120000;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114120000
 * @uses \OCA\OpenRegister\Service\Search\TextSearchConfiguration
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
final class Version1Date20261114120000Test extends TestCase {

	private IDBConnection&MockObject $db;

	/**
	 * Every executed statement.
	 *
	 * @var list<string>
	 */
	private array $sql = [];

	/**
	 * The LIKE pattern the table lookup was given.
	 *
	 * @var string|null
	 */
	private ?string $pattern = null;

	private function migration(object $platform): Version1Date20261114120000 {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getDatabasePlatform')->willReturn($platform);
		$this->db->method('executeQuery')->willReturnCallback(function (string $sql, array $params = []): IResult {
			$this->pattern = $params['pattern'] ?? null;
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn(['oc_openregister_table_1_2', 'oc_openregister_table_3_4']);
			return $result;
		});
		$this->db->method('executeStatement')->willReturnCallback(function (string $sql): int {
			$this->sql[] = $sql;
			return 0;
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('oc_');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('dutch');

		return new Version1Date20261114120000(
			connection: $this->db,
			config: $config,
			textSearch: new TextSearchConfiguration(db: $this->db, appConfig: $appConfig)
		);
	}

	private function schema(): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		return $schema;
	}

	public function testPostgresIndexesTheChunkTextAndEveryMagicTable(): void {
		$schema = $this->schema();
		$this->migration(platform: new PostgreSQLPlatform())->postSchemaChange($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertSame('oc\_openregister\_table\_%', $this->pattern);
		$this->assertCount(3, $this->sql);
		$this->assertSame(
			"CREATE INDEX IF NOT EXISTS idx_or_chunks_text_search_dutch_gin ON oc_openregister_chunks USING gin (to_tsvector('dutch', text_content))",
			$this->sql[0]
		);
		$this->assertStringStartsWith('CREATE INDEX IF NOT EXISTS openregister_table_1_2_tsv_idx ON oc_openregister_table_1_2 USING GIN (', $this->sql[1]);
		$this->assertStringStartsWith('CREATE INDEX IF NOT EXISTS openregister_table_3_4_tsv_idx ON oc_openregister_table_3_4 USING GIN (', $this->sql[2]);
	}

	public function testMariaDbIsANoOp(): void {
		$schema = $this->schema();
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('PostgreSQL only'));

		$this->migration(platform: new MariaDBPlatform())->postSchemaChange($output, fn () => $schema, []);

		$this->assertSame([], $this->sql);
	}

	public function testAFailedIndexIsAWarningAndTheOthersStillRun(): void {
		$calls = 0;
		$schema = $this->schema();
		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning')->with($this->stringContains('only slower'));

		// A connection whose first statement (the chunk index) fails.
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn(['oc_openregister_table_1_2']);
		$db->method('executeQuery')->willReturn($result);
		$db->method('executeStatement')->willReturnCallback(function () use (&$calls): int {
			$calls++;
			if ($calls === 1) {
				throw new RuntimeException('no such column');
			}
			return 0;
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('oc_');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('dutch');
		$migration = new Version1Date20261114120000(connection: $db, config: $config, textSearch: new TextSearchConfiguration(db: $db, appConfig: $appConfig));

		$migration->postSchemaChange($output, fn () => $schema, []);

		$this->assertSame(2, $calls);
	}
}
