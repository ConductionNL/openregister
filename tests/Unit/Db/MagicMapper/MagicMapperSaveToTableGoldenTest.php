<?php

/**
 * Golden test for MagicMapper::saveObjectToRegisterSchemaTable().
 *
 * Records every query-builder call, the log lines, the returned uuid, the
 * generated id, the write action and any exception per object shape, stored
 * row, claim flag and insert outcome, so a decomposition keeps the exact
 * insert-or-update decision. Re-record with UPDATE_GOLDEN=1.
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

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRowWrite;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\DB\Exception as DbException;
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
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFlaggedEncryption
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRowMetadata
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDroppedProperties
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPropertyColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPropertyDate
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnprocessedUploads
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
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\ObjectExistsException
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Db\EmptyPropertyClaim
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 */
class MagicMapperSaveToTableGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/save-to-table-golden.json';

	private const UUID = '0b6a4f8e-2c1d-4e5f-9a7b-3c2d1e0f9a8b';

	private array $events = [];

	private string $insertOutcome = 'ok';

	private static function objects(): array {
		return [
			'uuid in self' => ['title' => 'A', '@self' => ['id' => self::UUID, 'owner' => 'alice']],
			'uuid in self.uuid' => ['title' => 'A', '@self' => ['uuid' => self::UUID]],
			'uuid at top' => ['title' => 'A', 'id' => self::UUID],
			'uuid key at top' => ['title' => '', 'uuid' => self::UUID],
			'no uuid' => ['title' => 'A'],
			'empty uuid' => ['title' => 'A', 'id' => ''],
		];
	}//end objects()

	public function testSaveMatchesGolden(): void {
		$actual = [];
		foreach (self::objects() as $objectName => $object) {
			foreach (['absent', 'stored'] as $stored) {
				foreach ([false, true] as $claim) {
					foreach (['ok', 'unique', 'other db', 'runtime'] as $insert) {
						$name = "{$objectName} / {$stored} / " . var_export($claim, true) . " / {$insert}";
						$actual[$name] = $this->save(object: $object, setup: [$stored, $claim, $insert]);
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
	}//end testSaveMatchesGolden()

	private function save(array $object, array $setup): array {
		[$stored, $claim, $insert] = $setup;
		$this->events = [];
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [];
		$this->insertOutcome = $insert;

		$mapper = $this->makeMapper(stored: $stored);
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['title' => ['type' => 'string']]);

		$outcome = [];
		try {
			$outcome['uuid'] = $this->callSave(mapper: $mapper, args: [$object, $register, $schema, $claim]);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['insertedId'] = $this->insertedId;
		$outcome['action'] = $mapper->getLastWriteAction();
		$outcome['events'] = $this->events;
		$outcome['queries'] = $this->queryLog;

		$json = (string) json_encode($outcome);
		$json = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', '<v4>', str_replace(self::UUID, '<given>', $json));
		$json = (string) preg_replace('/MockObject_Exception_[0-9a-f]+/', 'DbException', $json);
		$json = (string) preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^"]*/', '<now>', $json);
		return json_decode($json, true);
	}//end save()

	private ?int $insertedId = null;

	private function callSave(MagicMapper $mapper, array $args): string {
		[$object, $register, $schema, $claim] = $args;
		$this->insertedId = null;
		$method = new \ReflectionMethod(MagicMapper::class, 'saveObjectToRegisterSchemaTable');
		$method->setAccessible(true);
		$write = new MagicRowWrite(objectData: $object, register: $register, schema: $schema, tableName: 'openregister_table_1_2');
		$write->failIfExists = $claim;
		try {
			return $method->invoke($mapper, $write);
		} finally {
			$this->insertedId = $write->insertedId;
		}
	}//end callSave()

	private function makeMapper(string $stored): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->method('lastInsertId')->willReturn(41);
		$db->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->statement(sql: $sql));
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['trace']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'dbtableprefix' ? 'oc_' : $default)
		);

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$db,
					$this->createMock(SchemaMapper::class),
					$this->createMock(RegisterMapper::class),
					$config,
					$this->createMock(IEventDispatcher::class),
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IUserManager::class),
					$this->createMock(IAppConfig::class),
					$logger,
					$this->createMock(\OCA\OpenRegister\Service\SettingsService::class),
					$this->container(),
				]
			)
			->onlyMethods(['findObjectInRegisterSchemaTable'])
			->getMock();

		$mapper->method('findObjectInRegisterSchemaTable')->willReturnCallback(
			function (string $uuid, string $tableName) use ($stored): ?array {
				$this->events[] = ['find' => [$uuid, $tableName]];
				if ($stored === 'stored') {
					return ['_id' => 5, '_uuid' => $uuid, '_status' => 'active'];
				}

				return null;
			}
		);

		return $mapper;
	}//end makeMapper()

	private function builder(): IQueryBuilder {
		$qb = $this->recordingBuilder();
		if ($this->insertOutcome === 'ok') {
			return $qb;
		}

		$qb->method('insert')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$qb->method('executeStatement')->willThrowException($this->insertFailure());
				return $qb;
			}
		);
		return $qb;
	}//end builder()

	private function insertFailure(): \Throwable {
		if ($this->insertOutcome === 'runtime') {
			return new \RuntimeException('disk full');
		}

		$reason = DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
		if ($this->insertOutcome === 'other db') {
			$reason = DbException::REASON_NOT_NULL_CONSTRAINT_VIOLATION;
		}

		$failure = $this->getMockBuilder(DbException::class)->setConstructorArgs(['insert refused'])->onlyMethods(['getReason'])->getMock();
		$failure->method('getReason')->willReturn($reason);
		return $failure;
	}//end insertFailure()

	private function statement(string $sql): IPreparedStatement {
		$this->events[] = ['prepare' => preg_replace('/\s+/', ' ', $sql)];
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturnCallback(fn (): IResult => $this->servedResult([]));
		$statement->method('fetch')->willReturn(false);
		$statement->method('fetchAll')->willReturn([]);
		return $statement;
	}//end statement()

	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): ?object => match ($id) {
				\OCA\OpenRegister\Service\DateTimeNormalizer::class => $this->createMock(\OCA\OpenRegister\Service\DateTimeNormalizer::class),
				\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
				\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class => $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class),
				default => null,
			}
		);
		return $container;
	}//end container()
}//end class
