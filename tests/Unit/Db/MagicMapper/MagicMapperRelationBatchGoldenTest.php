<?php

/**
 * Golden test for MagicMapper::findByRelationBatchInSchema(), findByRelationUsingRelationsColumn(),
 * findByRelation() and findByLinkedEntity().
 *
 * Records the statements the batch inverse-relation lookup prepares and binds,
 * its log lines and the objects it returns, per platform, signed-in state,
 * extra reference fields, missing table and failing query. Re-record with
 * UPDATE_GOLDEN=1.
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

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRelationSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 */
class MagicMapperRelationBatchGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/relation-batch-golden.json';

	private array $events = [];

	private array $rows = [];

	private array $fetchQueue = [];

	private static function cases(): array {
		$cases = [];
		foreach (['pg', 'maria'] as $platform) {
			foreach ([false, true] as $signedIn) {
				$s = $signedIn === true ? 'signed in' : 'anonymous';
				$cases["{$platform} / {$s} / relations only"] = ['batch', $platform, $signedIn, [['u-1', 'u-2'], 7, 3, 'owner', []], null];
				$cases["{$platform} / {$s} / extra fields"] = ['batch', $platform, $signedIn, [['u-1'], 7, 3, 'owner', ['parentOrg', 'contact']], null];
				$cases["{$platform} / {$s} / relations column"] = ['column', $platform, $signedIn, ['u-1'], null];
				$cases["{$platform} / {$s} / any table"] = ['relation', $platform, $signedIn, ['u-1'], null];
			}

			$cases["{$platform} / table missing"] = ['batch', $platform, true, [['u-1'], 7, 3, 'owner', []], 'missing'];
			$cases["{$platform} / query fails"] = ['batch', $platform, false, [['u-1', 'u-2'], 7, 3, 'owner', ['x']], 'query'];
			$cases["{$platform} / relations column / query fails"] = ['column', $platform, true, ['u-1'], 'query'];
			$cases["{$platform} / any table / query fails"] = ['relation', $platform, true, ['u-1'], 'query'];
			$cases["{$platform} / linked entity"] = ['linked', $platform, true, [7, 'owner_col', 'e-1'], null];
			$cases["{$platform} / linked entity / query fails"] = ['linked', $platform, true, [7, 'owner_col', 'e-1'], 'query'];
			$cases["{$platform} / linked entity / no table"] = ['linked', $platform, true, [99, 'owner_col', 'e-1'], null];
		}

		$cases['empty uuids'] = ['batch', 'pg', true, [[], 7, 3, 'owner', []], null];
		$cases['relations column / empty uuid'] = ['column', 'pg', true, [''], null];
		$cases['any table / empty uuid'] = ['relation', 'pg', true, [''], null];

		return $cases;
	}//end cases()

	public function testFindByRelationBatchInSchemaMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $platform, $signedIn, $args, $fail]) {
			$this->events = [];
			$mapper = $this->makeMapper(platform: $platform, signedIn: $signedIn, fail: $fail);
			if ($method === 'linked') {
				$schema = new Schema();
				$schema->setId($args[0]);
				$args[0] = $schema;
				$found = $mapper->findByLinkedEntity(...$args);
			} elseif ($method === 'column') {
				$found = $mapper->findByRelationUsingRelationsColumn(...$args);
			} elseif ($method === 'relation') {
				$found = $mapper->findByRelation(...$args);
			} else {
				$found = $mapper->findByRelationBatchInSchema(...$args);
			}
			$actual[$name] = [
				'events' => $this->events,
				'found' => array_map(
					static fn ($o): array => [$o->getUuid(), $o->getRegister(), $o->getName()],
					$found
				),
			];
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
	}//end testFindByRelationBatchInSchemaMatchesGolden()

	private function makeMapper(string $platform, bool $signedIn, ?string $fail): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(
			$platform === 'pg' ? $this->createMock(PostgreSQLPlatform::class) : $this->createMock(MariaDBPlatform::class)
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($fail): IPreparedStatement {
				$this->events[] = ['prepare' => preg_replace('/\s+/', ' ', $sql)];
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturnCallback(
					function (?array $params = null) use ($sql, $fail): IResult {
						$this->events[] = ['execute' => $params];
						$probe = str_contains($sql, 'information_schema');
						$this->fetchQueue = [];
						if ($probe === true) {
							$this->fetchQueue = $fail === 'missing' ? [] : [['1' => 1]];
						}

						if (str_contains($sql, 'table_name LIKE') === true) {
							$this->fetchQueue = [['table_name' => 'oc_openregister_table_3_7'], ['table_name' => 'openregister_table_3_8']];
						}

						if ($fail === 'query' && $probe === false) {
							throw new \Exception('query refused');
						}

						$rows = [
							['_uuid' => 'a-1', '_register' => 3, '_name' => 'First'],
							['_name' => 'no uuid'],
							['_uuid' => 'a-2'],
						];
						$this->rows = $rows;
						return $this->createMock(IResult::class);
					}
				);
				$statement->method('fetch')->willReturnCallback(fn () => array_shift($this->fetchQueue) ?? false);
				$statement->method('fetchAll')->willReturnCallback(fn (): array => $this->rows);
				return $statement;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['executionTime']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => $logger, 'config' => $config, 'userSession' => $session] as $prop => $value) {
			$property = $reflection->getProperty($prop);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()
}//end class
