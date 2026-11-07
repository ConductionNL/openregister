<?php

/**
 * OpenRegister - golden for the batched soft delete.
 *
 * Records, per scenario, every event dispatched, every UPDATE and full-row
 * save issued, every log line and the returned entities of
 * MagicMapper::softDeleteMultipleObjectEntities(), so a decomposition keeps
 * the exact order and outcome. Re-record with UPDATE_GOLDEN=1.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\DB\QueryBuilder\IExpressionBuilder;
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
 * Batched soft delete, recorded per scenario.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 */
class MagicMapperSoftDeleteBatchGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/soft-delete-batch-golden.json';

	/**
	 * Everything the mapper did, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trace = [];

	/**
	 * Uuid => behaviour of the updating hook: 'stop', 'modify'.
	 *
	 * @var array<string, string>
	 */
	private array $hooks = [];

	/**
	 * Tables whose UPDATE fails.
	 *
	 * @var array<int, string>
	 */
	private array $failingTables = [];

	/**
	 * Uuids whose full-row save fails.
	 *
	 * @var array<int, string>
	 */
	private array $failingSaves = [];

	public function testSoftDeleteBatchesMatchGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->run_($scenario);
			$actual[$name . ' / system operation'] = SystemOperationContext::run(fn (): array => $this->run_($scenario));
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
	}//end testSoftDeleteBatchesMatchGolden()

	/**
	 * The scenarios: entities (uuid, register, schema), hooks, failures, old entities.
	 *
	 * @return array<string, array<string, mixed>> The scenarios.
	 */
	private function scenarios(): array {
		return [
			'empty' => ['entities' => []],
			'one table' => ['entities' => [['a', '1', '2'], ['b', '1', '2']]],
			'two tables' => ['entities' => [['a', '1', '2'], ['c', '1', '3'], ['b', '1', '2']]],
			'missing scope' => ['entities' => [['a', '1', '2'], ['', '1', '2'], ['x', null, '2'], ['y', '1', 'abc']]],
			'hook stops one' => ['entities' => [['a', '1', '2'], ['b', '1', '2']], 'hooks' => ['a' => 'stop']],
			'hook modifies one' => ['entities' => [['a', '1', '2'], ['b', '1', '2']], 'hooks' => ['b' => 'modify']],
			'modified save fails' => ['entities' => [['a', '1', '2'], ['b', '1', '2']], 'hooks' => ['a' => 'modify'], 'failingSaves' => ['a']],
			'update fails' => ['entities' => [['a', '1', '2'], ['c', '1', '3']], 'failingTables' => ['openregister_table_1_3']],
			'old entities' => ['entities' => [['a', '1', '2'], ['b', '1', '2']], 'old' => ['b']],
			'modified with old' => ['entities' => [['a', '1', '2'], ['b', '1', '2']], 'hooks' => ['a' => 'modify'], 'old' => ['a']],
			'not an entity' => ['entities' => [['a', '1', '2'], 'bogus', ['b', '1', '2']]],
			'mixed' => [
				'entities' => [['a', '1', '2'], ['s', '1', '2'], ['m', '1', '3'], ['', '1', '2'], ['c', '1', '3'], ['d', '4', '5']],
				'hooks' => ['s' => 'stop', 'm' => 'modify'],
				'failingTables' => ['openregister_table_4_5'],
				'old' => ['c'],
			],
		];
	}//end scenarios()

	/**
	 * Run one scenario.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed> The trace and result.
	 */
	private function run_(array $scenario): array {
		$this->trace = [];
		$this->hooks = ($scenario['hooks'] ?? []);
		$this->failingTables = ($scenario['failingTables'] ?? []);
		$this->failingSaves = ($scenario['failingSaves'] ?? []);

		$entities = [];
		$old = [];
		foreach ($scenario['entities'] as $spec) {
			if (is_array($spec) === false) {
				$entities[] = $spec;
				continue;
			}

			$entities[] = $this->entity($spec[0], $spec[1], $spec[2]);
			if (in_array($spec[0], ($scenario['old'] ?? []), true) === true) {
				$prior = $this->entity($spec[0], $spec[1], $spec[2]);
				$prior->setName('prior ' . $spec[0]);
				$old[$spec[0]] = $prior;
			}
		}

		$outcome = [];
		try {
			$result = $this->mapper()->softDeleteMultipleObjectEntities($entities, $old);
			$outcome['result'] = array_map(
				static fn (ObjectEntity $e): string => $e->getUuid() . ' ' . json_encode($e->getObject()),
				$result
			);
		} catch (Exception $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['trace'] = $this->trace;
		return $outcome;
	}//end run_()

	/**
	 * A deleted-marked entity.
	 *
	 * @param string      $uuid     The uuid.
	 * @param string|null $register The register id.
	 * @param string|null $schema   The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, ?string $register, ?string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister($register);
		$entity->setSchema($schema);
		$entity->setObject(['title' => 'object ' . $uuid]);
		$entity->setDeleted(['deletedBy' => 'alice', 'objectId' => $uuid]);
		return $entity;
	}//end entity()

	/**
	 * The mapper under test, with a recording database, dispatcher and logger.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class));
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->updateBuilder());

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$uuid = $event->getNewObject()->getUuid();
				$oldName = $event->getOldObject()?->getName();
				$this->trace[] = 'event ' . (new \ReflectionClass($event))->getShortName() . ' ' . $uuid . ' old=' . var_export($oldName, true);
				if ($event instanceof ObjectUpdatingEvent === false) {
					return;
				}

				if (($this->hooks[$uuid] ?? '') === 'stop') {
					$event->stopPropagation();
				}

				if (($this->hooks[$uuid] ?? '') === 'modify') {
					$event->setModifiedData(['hooked' => true]);
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->trace[] = $level . ' ' . $message . ' ' . json_encode($context);
				}
			);
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int) $id);
				return $register;
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int) $id);
				return $schema;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$services = [
			DateTimeNormalizerInterface::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverterInterface::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container->method('get')->willReturnCallback(static fn (string $id) => ($services[$id] ?? null));

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$db,
					$schemaMapper,
					$registerMapper,
					$this->createMock(IConfig::class),
					$dispatcher,
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IUserManager::class),
					$this->createMock(IAppConfig::class),
					$logger,
					$this->createMock(SettingsService::class),
					$container,
				]
			)
			->onlyMethods(['getTableNameForRegisterSchema', 'saveObjectToRegisterSchemaTable'])
			->getMock();

		$mapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 'openregister_table_' . $register->getId() . '_' . $schema->getId()
		);
		$mapper->method('saveObjectToRegisterSchemaTable')->willReturnCallback(
			function (...$args): string {
				// Either (objectData, register, schema, tableName) or one row-write object carrying them.
				$write = (is_object($args[0]) === true ? $args[0] : null);
				$objectData = ($write?->objectData ?? $args[0]);
				$tableName = ($write?->tableName ?? $args[3]);
				$uuid = ($objectData['id'] ?? ($objectData['@self']['id'] ?? '?'));
				$this->trace[] = 'save ' . $tableName . ' ' . $uuid . ' ' . json_encode($objectData['hooked'] ?? null);
				if (in_array($uuid, $this->failingSaves, true) === true) {
					throw new Exception('save failed for ' . $uuid);
				}

				return (string) $uuid;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * An UPDATE builder that records its statement and fails for a failing table.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function updateBuilder(): IQueryBuilder {
		$state = ['table' => '', 'params' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturnCallback(static fn ($col, $param): string => $col . ' IN ' . $param);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$state): string {
				$state['params'][] = $value;
				return ':p' . count($state['params']);
			}
		);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql): string => $sql);
		$qb->method('update')->willReturnCallback(
			function (string $table) use (&$state, $qb): IQueryBuilder {
				$state['table'] = $table;
				return $qb;
			}
		);
		$qb->method('set')->willReturnCallback(
			function (string $col, $value) use ($qb): IQueryBuilder {
				$this->trace[] = 'set ' . $col . ' = ' . $value;
				return $qb;
			}
		);
		$qb->method('where')->willReturnCallback(
			function ($cond) use ($qb): IQueryBuilder {
				$this->trace[] = 'where ' . $cond;
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$state): int {
				$this->trace[] = 'execute ' . $state['table'] . ' ' . json_encode($state['params']);
				if (in_array($state['table'], $this->failingTables, true) === true) {
					throw new Exception('update failed on ' . $state['table']);
				}

				return count($state['params']);
			}
		);
		return $qb;
	}//end updateBuilder()
}//end class
