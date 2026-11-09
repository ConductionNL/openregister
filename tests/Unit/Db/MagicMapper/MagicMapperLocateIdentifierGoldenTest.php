<?php

/**
 * Golden test for the locate phase of MagicMapper::findAcrossAllMagicTables().
 *
 * Records the prepared UNION SQL, every bound value, every query-builder call
 * of the per-table fallback and the row fetch, the log lines and the outcome
 * per platform, table set, identifier, deleted flag and database answer, so a
 * decomposition keeps the exact queries. Re-record with UPDATE_GOLDEN=1.
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

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicCrossTableHit
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicIdentifierProbe
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 */
class MagicMapperLocateIdentifierGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/locate-identifier-golden.json';

	private array $events = [];

	private array $failBuilders = [];

	private static function tableSets(): array {
		return [
			'none' => [],
			'one' => ['openregister_table_1_2' => [1, 2]],
			'three' => [
				'openregister_table_1_2' => [1, 2],
				'openregister_table_1_3' => [1, 3],
				'openregister_table_4_5' => [4, 5],
			],
			'one malformed' => ['openregister_table_1_2' => [1, 2], 'other_table' => [9, 9]],
			'only malformed' => ['other_table' => [9, 9]],
		];
	}//end tableSets()

	/**
	 * Database answers: [union rows or 'fail', queued builder rows, failing builder numbers].
	 */
	private static function answers(): array {
		$fetched = [['_id' => 7, '_uuid' => 'uuid-7']];
		return [
			'miss' => [[], [], []],
			'hit' => [[['src_table' => 'openregister_table_1_2', 'hit_id' => '7']], [$fetched], []],
			'hit upper case' => [[['SRC_TABLE' => 'openregister_table_1_2', 'HIT_ID' => 7]], [$fetched], []],
			'hit without id' => [[['src_table' => 'openregister_table_1_2', 'hit_id' => null]], [], []],
			'hit gone at fetch' => [[['src_table' => 'openregister_table_1_2', 'hit_id' => 7]], [[]], []],
			'union fails, all miss' => ['fail', [[], [], []], []],
			'union fails, second hits' => ['fail', [[], [['_id' => '7']], $fetched], []],
			'union fails, first errors' => ['fail', [[['_id' => 7]], $fetched], [1]],
			'union fails, row without id' => ['fail', [[['_uuid' => 'x']], [], []], []],
		];
	}//end answers()

	public function testLocateMatchesGolden(): void {
		$actual = [];
		foreach (['postgres', 'mysql'] as $platform) {
			foreach (self::tableSets() as $setName => $tables) {
				foreach (['uuid-7', 7, '007'] as $identifier) {
					foreach ([false, true] as $deleted) {
						foreach (self::answers() as $answerName => $answer) {
							$name = "{$platform} / {$setName} / " . var_export($identifier, true) . ' / ' . var_export($deleted, true) . " / {$answerName}";
							$actual[$name] = $this->locate(setup: [$platform, $tables, $answer], call: [$identifier, $deleted]);
						}
					}
				}
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
	}//end testLocateMatchesGolden()

	private function locate(array $setup, array $call): array {
		[$platform, $tables, [$unionRows, $queued, $failing]] = $setup;
		[$identifier, $deleted] = $call;
		$this->events = [];
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $queued;
		$this->failBuilders = $failing;

		$mapper = $this->makeMapper(platform: $platform, unionRows: $unionRows);
		$memo = [];
		foreach ($tables as $table => [$registerId, $schemaId]) {
			$memo[$table] = ['registerId' => $registerId, 'schemaId' => $schemaId];
		}

		$property = new \ReflectionProperty(MagicMapper::class, 'liveMagicTablesMemo');
		$property->setAccessible(true);
		$property->setValue($mapper, $memo);

		$outcome = [];
		try {
			$mapper->findAcrossAllMagicTables($identifier, $deleted);
			$outcome['returned'] = true;
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['events'] = $this->events;
		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end locate()

	private function makeMapper(string $platform, array|string $unionRows): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform === 'postgres' ? new PostgreSQLPlatform() : new MySQLPlatform());
		$db->method('prepare')->willReturnCallback(
			fn (string $sql): IPreparedStatement => $this->statement(sql: $sql, rows: $unionRows)
		);
		$db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				$qb = $this->recordingBuilder();
				if (in_array($this->builderCount, $this->failBuilders, true) === true) {
					$qb->method('executeQuery')->willThrowException(new \RuntimeException('no such column'));
				}

				return $qb;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'dbtableprefix' ? 'oc_' : $default)
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function (int $id): never {
				$this->events[] = ['registerFind' => $id];
				throw new \RuntimeException('register gone');
			}
		);

		return new MagicMapper(
			$db,
			new MagicMapperLookups(
				$this->createMock(SchemaMapper::class),
				$registers,
				$this->container()
			),
			new MagicMapperIdentity(
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(IUserManager::class)
			),
			new MagicMapperRuntime(
				$config,
				$this->createMock(IAppConfig::class),
				$this->createMock(IEventDispatcher::class),
				$logger
			)
		);
	}//end makeMapper()

	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): ?object => match ($id) {
				\OCA\OpenRegister\Service\DateTimeNormalizerInterface::class => $this->createMock(\OCA\OpenRegister\Service\DateTimeNormalizer::class),
				\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
				\OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface::class => $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class),
				default => null,
			}
		);
		return $container;
	}//end container()

	private function statement(string $sql, array|string $rows): IPreparedStatement {
		$this->events[] = ['prepare' => $sql];
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('bindValue')->willReturnCallback(
			function ($param, $value, $type = null): bool {
				$this->events[] = ['bind' => [$param, $value, $type]];
				return true;
			}
		);
		$statement->method('execute')->willReturnCallback(
			function () use ($rows): IResult {
				$this->events[] = ['execute' => true];
				if ($rows === 'fail') {
					throw new \RuntimeException('union refused');
				}

				return $this->servedResult($rows);
			}
		);

		return $statement;
	}//end statement()
}//end class
