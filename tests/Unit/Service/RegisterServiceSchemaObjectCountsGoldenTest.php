<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterServiceHelpers;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\RegisterServiceStorage;
use OCP\DB\IPreparedStatement;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Records the SQL, fetched rows, log lines and result of getSchemaObjectCounts(), per scenario,
 * against fixtures/register-schema-object-counts-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\RegisterService
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Serializer\RegisterSerializer
 */
class RegisterServiceSchemaObjectCountsGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * A statement that returns the given rows, or throws on execute.
	 *
	 * @param array      $rows  Rows to fetch.
	 * @param string|null $error Message to throw on execute.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(array $rows, ?string $error): IPreparedStatement {
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('bindValue')->willReturnCallback(function ($key, $value) {
			$this->calls[] = ['bind', $key, $value];
			return true;
		});
		$stmt->method('execute')->willReturnCallback(function () use ($error) {
			if ($error !== null) {
				throw new Exception($error);
			}
			return $this->createMock(\OCP\DB\IResult::class);
		});
		$stmt->method('fetch')->willReturnCallback(function () use (&$rows) {
			$row = array_shift($rows);
			return $row ?? false;
		});
		$stmt->method('closeCursor')->willReturnCallback(function () {
			$this->calls[] = ['close'];
			return true;
		});
		return $stmt;
	}

	/**
	 * Builds the service for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return RegisterService
	 */
	private function service(array $s): RegisterService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturnCallback(fn () => $s['postgres'] ? $this->createMock(PostgreSQLPlatform::class) : $this->createMock(MySQLPlatform::class));
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getTableName')->willReturnCallback(fn ($t) => '*PREFIX*' . $t);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('tableExists')->willReturnCallback(function ($t) use ($s) {
			$this->calls[] = ['tableExists', $t];
			return in_array($t, $s['tables'], true);
		});
		$db->method('prepare')->willReturnCallback(function ($sql) use ($s) {
			$sql = trim(preg_replace('/\s+/', ' ', $sql));
			$this->calls[] = ['prepare', $sql];
			if (str_contains($sql, 'information_schema') === true) {
				$rows = array_map(fn ($t) => ['table_name' => 'oc_' . $t], $s['tables']);
				$rows[] = ['table_name' => ''];
				$rows[] = ['table_name' => 'oc_other'];
				return $this->statement($rows, $s['catalogFails'] ? 'no catalog' : null);
			}
			return $this->statement($s['rows'], $s['countFails'] ? 'count failed' : null);
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				if (str_starts_with($message, '[RegisterService] Stack trace') === true) {
					$message = 'STACK';
				}
				$this->calls[] = ['log.' . $level, trim(preg_replace('/\s+/', ' ', $message))];
			});
		}

		return new RegisterService(
			new RegisterServiceStorage($db, new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class))),
			new RegisterServiceHelpers(
				$this->createMock(FileService::class),
				new RegisterSerializer($this->createMock(SchemaMapper::class), $logger)
			),
			$this->createMock(OrganisationService::class),
			$logger
		);
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$rows = [
			['schema_id' => '3', 'total' => '7', 'deleted' => '1', 'invalid' => '0', 'locked' => '0', 'size' => '0'],
			['schema_id' => '5', 'total' => '2', 'deleted' => '0', 'invalid' => '0', 'locked' => '0', 'size' => '0'],
		];
		$base = [
			'schemas' => [3, ['id' => 5], 'objectSchema', 9], 'tables' => ['openregister_table_1_3', 'openregister_table_1_5'],
			'rows' => $rows, 'postgres' => false, 'catalogFails' => false, 'countFails' => false,
		];
		return [
			'mysql mixed shapes' => [$base],
			'postgres' => [['postgres' => true] + $base],
			'no schemas' => [['schemas' => []] + $base],
			'no tables at all' => [['tables' => []] + $base],
			'only unusable shapes' => [['schemas' => ['x', ['name' => 'n'], null]] + $base],
			'catalog read fails' => [['catalogFails' => true] + $base],
			'count query fails' => [['countFails' => true] + $base],
			'array id as string' => [['schemas' => [['id' => '5'], '3']] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testSchemaObjectCountsGolden(array $s): void {
		$schemas = [];
		foreach ($s['schemas'] as $schema) {
			if ($schema === 'objectSchema') {
				$schema = new Schema();
				$schema->setId(9);
			}
			$schemas[] = $schema;
		}
		$this->calls[] = ['result', $this->service($s)->getSchemaObjectCounts(1, $schemas)];

		$file = __DIR__ . '/fixtures/register-schema-object-counts-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls, JSON_UNESCAPED_UNICODE), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
