<?php

/**
 * The stemmed-match GIN index a magic table gets on PostgreSQL, and not elsewhere.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicTableIdentityIndex
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses \OCA\OpenRegister\Service\Search\TextSearchConfiguration
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
final class MagicMapperDutchIndexTest extends TestCase {

	/**
	 * Every executed statement.
	 *
	 * @var list<string>
	 */
	private array $sql = [];

	private function create(AbstractPlatform $platform, ?string $config): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(function (string $sql): int {
			$this->sql[] = $sql;
			return 0;
		});
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(false);
		$db->method('executeQuery')->willReturn($result);

		$schema = new Schema();
		$schema->setProperties(['titel' => ['type' => 'string']]);

		(new MagicTableIndexer(db: $db, logger: new NullLogger(), trgm: new MagicPgTrgmProbe(db: $db, logger: new NullLogger()), textSearchConfig: $config))
			->create(run: new MagicTableIndexRun(tableName: 'openregister_table_1_2', fullTableName: 'oc_openregister_table_1_2'), schema: $schema);
	}

	/**
	 * @return list<string>
	 */
	private function stemmedIndexes(): array {
		return array_values(array_filter($this->sql, static fn (string $sql): bool => str_contains($sql, 'to_tsvector')));
	}

	public function testPostgresGetsTheStemmedMetadataIndex(): void {
		$this->create(platform: new PostgreSQLPlatform(), config: 'dutch');

		$this->assertSame(
			["CREATE INDEX IF NOT EXISTS openregister_table_1_2_tsv_idx ON oc_openregister_table_1_2 USING GIN (to_tsvector('dutch', COALESCE(CAST(_name AS TEXT), '') || ' ' || COALESCE(CAST(_description AS TEXT), '') || ' ' || COALESCE(CAST(_summary AS TEXT), '')))"],
			$this->stemmedIndexes()
		);
	}

	public function testTheIndexNamesTheSavedConfiguration(): void {
		$this->create(platform: new PostgreSQLPlatform(), config: 'english');

		$this->assertStringContainsString("to_tsvector('english', ", $this->stemmedIndexes()[0]);
	}

	public function testMariaDbGetsNone(): void {
		$this->create(platform: new MariaDBPlatform(), config: 'dutch');

		$this->assertNotSame([], $this->sql);
		$this->assertSame([], $this->stemmedIndexes());
	}

	public function testNoConfigurationGetsNone(): void {
		$this->create(platform: new PostgreSQLPlatform(), config: null);

		$this->assertSame([], $this->stemmedIndexes());
	}
}
