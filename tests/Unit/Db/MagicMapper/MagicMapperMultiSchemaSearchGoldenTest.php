<?php

/**
 * Golden test for the multi-schema search of MagicMapper.
 *
 * Records the register and schema lookups, per-table counts, the union search,
 * log lines and the response for schemas whose owning register is loaded on
 * demand, fails to load, is missing, whose schema fails to load, and for a count
 * that hits an encrypted-field filter. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\MagicMapper\MultiSchemaSearch;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\EncryptedFieldFilterException
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MultiSchemaSearch
 * @uses \OCA\OpenRegister\Db\Register\RegisterJson
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 */
class MagicMapperMultiSchemaSearchGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/multi-schema-search-golden.json';

	private array $events = [];

	private static function cases(): array {
		$search = ['_search' => 'zaak', '@self' => ['schema' => [2, 4, 5, 6]]];
		$count = ['_search' => 'zaak', '@self' => ['schema' => [2, 4, 5, 6], 'register' => 1]];
		return [
			'membership, lazy registers' => [[$search, $count, [], [2, '4', 5, 6, 3]], [true, true], null],
			'membership, no rbac' => [[$search, $count, [], [2, 3]], [false, false], null],
			'given registers' => [[$search, $count, [1, 8, 404], [2, 5, 3, 4]], [true, false], null],
			'no owners' => [[$search, $count, [404], [2]], [true, true], null],
			'no schema survives' => [[$search, $count, [], [5, 6]], [true, true], null],
			'membership fails' => [[$search, $count, [], [2]], [true, true], 'membership'],
			'encrypted filter' => [[$search, $count, [], [2, 3]], [true, true], 'encrypted'],
		];
	}//end cases()

	public function testMultiSchemaSearchMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$queries, $flags, $fail]) {
			$this->events = [];
			$mapper = $this->makeMapper(fail: $fail);
			try {
				$result = $this->search(mapper: $mapper, queries: $queries, flags: $flags);
				$result['results'] = array_map(static fn ($r) => is_string($r) ? $r : $r::class, $result['results']);
				$actual[$name] = ['events' => $this->events, 'result' => $result];
			} catch (\Throwable $e) {
				$actual[$name] = ['events' => $this->events, 'exception' => $e::class];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testMultiSchemaSearchMatchesGolden()

	private function search(MagicMapper $mapper, array $queries, array $flags): array {
		$method = new ReflectionMethod(MagicMapper::class, 'searchObjectsPaginatedMultiSchema');
		return $method->invoke($mapper, (new MultiSchemaSearch($queries[0], $queries[1], $queries[2], $queries[3]))->securedBy($flags[0], $flags[1]));
	}//end search()

	private function makeMapper(?string $fail): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['countObjectsInRegisterSchemaTable', 'searchAcrossMultipleTables'])
			->getMock();
		$mapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query, Register $register, Schema $schema) use ($fail): int {
				$this->events[] = ['count' => [$query, $register->getId(), $schema->getId()]];
				if ($fail === 'encrypted' && $schema->getId() === 3) {
					throw new EncryptedFieldFilterException('secret');
				}

				return 10 + (int) $schema->getId();
			}
		);
		$mapper->method('searchAcrossMultipleTables')->willReturnCallback(
			function (array $query, array $registerSchemaPairs): array {
				$this->events[] = ['searchAcross' => [$query, array_map(static fn ($p) => [$p['register']->getId(), $p['schema']->getId()], $registerSchemaPairs)]];
				return ['union-row'];
			}
		);

		$result = $this->createMock(IResult::class);
		$rows = [
			['id' => 1, 'schemas' => '[2, 3]'],
			['id' => 7, 'schemas' => '[4]'],
			['id' => 8, 'schemas' => '[5]'],
			['id' => 9, 'schemas' => 'not json'],
		];
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return array_shift($rows) ?? false;
		});
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('executeQuery')->willReturnCallback(function () use ($result, $fail): IResult {
			if ($fail === 'membership') {
				throw new \RuntimeException('membership refused');
			}

			return $result;
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->events[] = ['registerFind' => $args];
				$id = (int) $args[0];
				if (in_array($id, [1, 8], true) === false) {
					throw new \RuntimeException('no register ' . $id);
				}

				$register = new Register();
				$register->setId($id);
				$register->setSchemas($id === 1 ? [2, 3] : [5]);
				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (...$args): Schema {
				$this->events[] = ['schemaFind' => $args];
				$id = (int) $args[0];
				if (in_array($id, [2, 3, 4], true) === false) {
					throw new \RuntimeException('no schema ' . $id);
				}

				$schema = new Schema();
				$schema->setId($id);
				return $schema;
			}
		);

		foreach (['db' => $db, 'logger' => $logger, 'registerMapper' => $registers, 'schemaMapper' => $schemas] as $prop => $value) {
			(new ReflectionProperty(MagicMapper::class, $prop))->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()
}//end class
