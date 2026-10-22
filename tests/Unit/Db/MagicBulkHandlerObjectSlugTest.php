<?php

/**
 * The bulk writer keeps the slug decision and a stored slug (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkUpsertSql;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for MagicBulkHandler's `_slug` column handling.
 */
class MagicBulkHandlerObjectSlugTest extends TestCase {

	/**
	 * Call the private withDisplayColumns() on a handler built without its constructor.
	 *
	 * @param array $selfData The @self block.
	 * @param array $object   The raw object.
	 * @param bool  $managed  Whether the schema has objectSlugField.
	 *
	 * @return array The prepared row.
	 */
	private function displayColumns(array $selfData, array $object, bool $managed): array {
		$handler = (new ReflectionClass(MagicBulkHandler::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(MagicBulkHandler::class, 'withDisplayColumns');
		return $method->invoke($handler, [], $selfData, $object, $managed);
	}

	public function testAnExplicitNullSlugIsKeptForAManagedSchema(): void {
		$row = $this->displayColumns(['slug' => null], ['slug' => 'business-value'], true);

		$this->assertNull($row['_slug']);
	}

	public function testAnUnmanagedSchemaKeepsTheFallbackToTheObject(): void {
		$row = $this->displayColumns(['slug' => null], ['slug' => 'business-value'], false);

		$this->assertSame('business-value', $row['_slug']);
	}

	public function testADecidedSlugIsWritten(): void {
		$row = $this->displayColumns(['slug' => 'bulk-item'], [], true);

		$this->assertSame('bulk-item', $row['_slug']);
	}

	public function testManagedSlugsFollowTheSchemaConfiguration(): void {
		$managed = new Schema();
		$managed->setConfiguration(['objectSlugField' => 'title']);
		$plain = new Schema();
		$plain->setConfiguration(['objectNameField' => 'title']);

		$this->assertTrue(MagicBulkUpsertSql::hasManagedSlugs($managed));
		$this->assertFalse(MagicBulkUpsertSql::hasManagedSlugs($plain));
	}

	/**
	 * Run executeUpsertChunk() against a mocked connection and return the INSERT it prepared.
	 *
	 * @param bool $postgres   Whether the connection is PostgreSQL.
	 * @param bool $freezeSlug Whether the schema has managed slugs.
	 *
	 * @return string The upsert statement.
	 */
	private function upsertSql(bool $postgres, bool $freezeSlug): string {
		$prepared = [];
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturn($this->createMock(IResult::class));
		$statement->method('fetch')->willReturnOnConsecutiveCalls(
			['Field' => '_uuid', 'column_name' => '_uuid'],
			['Field' => '_slug', 'column_name' => '_slug'],
			['Field' => '_updated', 'column_name' => '_updated'],
			['Field' => '_name', 'column_name' => '_name'],
			false
		);
		$statement->method('fetchAll')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$platform = ($postgres === true) ? $this->createMock(PostgreSQLPlatform::class) : $this->createMock(MySQLPlatform::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->createMock(IQueryBuilder::class));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use (&$prepared, $statement): IPreparedStatement {
				$prepared[] = $sql;
				return $statement;
			}
		);

		$handler = new MagicBulkHandler(
			db: $db,
			logger: $this->createMock(LoggerInterface::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class),
			config: $this->createMock(IConfig::class)
		);
		$method = new ReflectionMethod(MagicBulkHandler::class, 'executeUpsertChunk');
		$method->invoke(
			$handler,
			[['_uuid' => 'u-1', '_slug' => 'hello', '_name' => 'Hello']],
			'openregister_table_1_2',
			1,
			false,
			$freezeSlug
		);

		$inserts = array_values(array_filter($prepared, static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO')));
		$this->assertCount(1, $inserts, 'exactly one upsert statement is prepared');
		return $inserts[0];
	}//end upsertSql()

	public function testTheMySqlUpsertKeepsAStoredSlug(): void {
		$sql = $this->upsertSql(postgres: false, freezeSlug: true);

		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
		$this->assertStringContainsString("`_slug` = COALESCE(NULLIF(`_slug`, ''), VALUES(`_slug`))", $sql);
		$this->assertStringContainsString('`_name` = VALUES(`_name`)', $sql);
	}

	public function testThePostgresUpsertKeepsAStoredSlug(): void {
		$sql = $this->upsertSql(postgres: true, freezeSlug: true);

		$this->assertStringContainsString('ON CONFLICT (_uuid) DO UPDATE SET', $sql);
		$this->assertMatchesRegularExpression('/"_slug" = COALESCE\(NULLIF\("[^"]+"\."_slug", \'\'\), EXCLUDED\."_slug"\)/', $sql);
		$this->assertStringContainsString('"_name" = EXCLUDED."_name"', $sql);
	}

	public function testASchemaWithoutManagedSlugsStillOverwritesTheSlug(): void {
		$mysql = $this->upsertSql(postgres: false, freezeSlug: false);
		$postgres = $this->upsertSql(postgres: true, freezeSlug: false);

		$this->assertStringContainsString('`_slug` = VALUES(`_slug`)', $mysql);
		$this->assertStringNotContainsString('COALESCE', $mysql);
		$this->assertStringContainsString('"_slug" = EXCLUDED."_slug"', $postgres);
		$this->assertStringNotContainsString('COALESCE', $postgres);
	}
}//end class
