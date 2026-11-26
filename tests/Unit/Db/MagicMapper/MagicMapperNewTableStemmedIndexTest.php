<?php

/**
 * A magic table created just now gets the stemmed `_search` index.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit and reflection helpers use positional args.

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Measured on a sandbox Nextcloud 34 on PostgreSQL 16: a magic table created by
 * the first object of a new schema had the trigram indexes but no `_tsv_idx`,
 * because the new-table path built its indexer without the text search
 * configuration. Both index passes must ask for it.
 */
class MagicMapperNewTableStemmedIndexTest extends TestCase {

	/** @var list<string> Every statement the mapper executed. */
	private array $sql = [];

	private function mapper(AbstractPlatform $platform, ?string $config): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->sql[] = $sql;
				return 0;
			}
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturn($result);
		$db->method('prepare')->willReturn($statement);
		$db->method('executeQuery')->willReturn($result);

		$appConfig = $this->createMock(IConfig::class);
		$appConfig->method('getSystemValue')->willReturn('oc_');

		$textSearch = $this->createMock(TextSearchConfiguration::class);
		$textSearch->method('active')->willReturn($config);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper     = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => new NullLogger(), 'config' => $appConfig, 'textSearch' => $textSearch] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}

	private function index(MagicMapper $mapper, string $method): void {
		$schema = new Schema();
		$schema->setProperties(['titel' => ['type' => 'string']]);
		(new ReflectionMethod(MagicMapper::class, $method))->invoke($mapper, 'openregister_table_1_2', $schema);
	}

	/** @return list<string> */
	private function stemmed(): array {
		return array_values(array_filter($this->sql, static fn (string $sql): bool => str_contains($sql, '_tsv_idx')));
	}

	public function testANewTableGetsTheStemmedIndex(): void {
		$this->index($this->mapper(new PostgreSQLPlatform(), 'dutch'), 'createNewTableIndexes');

		$this->assertCount(1, $this->stemmed());
		$this->assertStringContainsString("USING GIN (to_tsvector('dutch', ", $this->stemmed()[0]);
	}

	public function testASyncedTableGetsTheSameIndex(): void {
		$this->index($this->mapper(new PostgreSQLPlatform(), 'dutch'), 'createNewTableIndexes');
		$new       = $this->stemmed();
		$this->sql = [];

		$this->index($this->mapper(new PostgreSQLPlatform(), 'dutch'), 'createTableIndexes');

		$this->assertSame($new, $this->stemmed());
	}

	public function testWithoutAConfigurationThereIsNone(): void {
		$this->index($this->mapper(new PostgreSQLPlatform(), null), 'createNewTableIndexes');

		$this->assertNotSame([], $this->sql);
		$this->assertSame([], $this->stemmed());
	}
}
