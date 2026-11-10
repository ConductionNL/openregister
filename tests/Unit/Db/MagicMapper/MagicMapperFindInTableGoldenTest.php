<?php

/**
 * Golden test for MagicMapper::findInRegisterSchemaTable().
 *
 * Records every query-builder call, the table-handler and access-control
 * calls, the log lines and the found object (or the exception) per
 * identifier, served rows, flags and table state, so a decomposition keeps
 * the exact lookup. Re-record with UPDATE_GOLDEN=1.
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
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
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
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicObjectLookup
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 */
class MagicMapperFindInTableGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/find-in-table-golden.json';

	private array $events = [];

	private static function rowSets(): array {
		return [
			'none' => [],
			'one' => [['_id' => 7, '_uuid' => 'uuid-7', '_slug' => 'seven', '_name' => 'Seven']],
			'id beats slug' => [
				['_id' => 3, '_uuid' => 'uuid-3', '_slug' => '7'],
				['_id' => 7, '_uuid' => 'uuid-7', '_slug' => 'x'],
			],
			'two slugs' => [
				['_id' => 3, '_uuid' => 'uuid-3', '_slug' => 'seven'],
				['_id' => 4, '_uuid' => 'uuid-4', '_slug' => 'seven'],
			],
			'query fails' => 'fail',
		];
	}//end rowSets()

	private static function flags(): array {
		return [
			'defaults' => [true, true, false, false],
			'no rbac' => [false, true, false, false],
			'bypass' => [false, false, false, false],
			'bypass in org context' => [false, false, false, true],
			'with deleted' => [true, true, true, false],
		];
	}//end flags()

	public function testFindInRegisterSchemaTableMatchesGolden(): void {
		$actual = [];
		foreach (['exists', 'missing magic', 'missing plain'] as $table) {
			foreach ([7, '7', 'uuid-7', 'seven', '007'] as $identifier) {
				foreach (self::rowSets() as $rowName => $rows) {
					foreach (self::flags() as $flagName => $flags) {
						$name = "{$table} / " . var_export($identifier, true) . " / {$rowName} / {$flagName}";
						$actual[$name] = $this->find(setup: [$table, $rows], call: [$identifier, $flags]);
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
	}//end testFindInRegisterSchemaTableMatchesGolden()

	private function find(array $setup, array $call): array {
		[$table, $rows] = $setup;
		[$identifier, [$rbac, $multitenancy, $deleted, $orgContext]] = $call;
		$this->events = [];
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [$rows === 'fail' ? [] : $rows];

		$mapper = $this->makeMapper(table: $table, fail: $rows === 'fail');

		$register = new Register();
		$register->setId(1);
		if ($table === 'missing magic') {
			$register->setConfiguration(['schemas' => ['2' => ['magicMapping' => true]]]);
		}

		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('golden');

		$outcome = [];
		try {
			$lookup = fn () => $mapper->findInRegisterSchemaTable($identifier, $register, $schema, $rbac, $multitenancy, $deleted);
			if ($orgContext === true) {
				$found = OrganisationContext::run('org-a', $lookup);
			} else {
				$found = $lookup();
			}

			$outcome['found'] = [$found->getId(), $found->getUuid(), $found->getSlug(), $found->getName(), $found->getRegister(), $found->getSchema()];
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['events'] = $this->events;
		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end find()

	private function makeMapper(string $table, bool $fail): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($fail): IQueryBuilder {
				$qb = $this->recordingBuilder();
				if ($fail === true) {
					$qb = $this->failingBuilder(qb: $qb);
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

		$mapper = new MagicMapper(
			$db,
			new MagicMapperLookups(
				$this->createMock(SchemaMapper::class),
				$this->createMock(RegisterMapper::class),
				$this->container()
			),
			new MagicMapperIdentity(
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(IUserManager::class)
			),
			new MagicMapperRuntime(
				$this->createMock(IConfig::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(IEventDispatcher::class),
				$logger
			)
		);

		$tables = $this->createMock(MagicTableHandler::class);
		$tables->method('existsTableForRegisterSchema')->willReturnCallback(
			function () use ($table): bool {
				$this->events[] = ['tableExists' => $table];
				return $table === 'exists';
			}
		);
		$tables->method('ensureTableForRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema, bool $force = false): bool {
				$this->events[] = ['ensureTable' => [$register->getId(), $schema->getId(), $force]];
				return true;
			}
		);
		$tables->method('getTableNameForRegisterSchema')->willReturn('oc_openregister_table_1_2');

		$search = $this->createMock(MagicSearchHandler::class);
		$search->method('applyAccessControlToQuery')->willReturnCallback(
			function (IQueryBuilder $qb, Schema $schema, bool $_rbac = true, bool $_multitenancy = true, ?int $registerId = null, ?string $tableName = null): void {
				$this->events[] = ['accessControl' => [$schema->getId(), $_rbac, $_multitenancy, $registerId, $tableName]];
			}
		);

		foreach (['tableHandler' => $tables, 'searchHandler' => $search] as $name => $value) {
			$property = new \ReflectionProperty(MagicMapper::class, $name);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
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

	private function failingBuilder(IQueryBuilder $qb): IQueryBuilder {
		$qb->method('executeQuery')->willThrowException(new \RuntimeException('database refused'));
		return $qb;
	}//end failingBuilder()
}//end class
