<?php

/**
 * Golden for MagicMapper::ultraFastBulkSave().
 *
 * Records, per scenario, every register and schema lookup, table check,
 * bulk upsert, log line and the result, so a decomposition keeps the
 * grouping of mixed-schema saves exactly. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\SettingsService;
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
 * ultraFastBulkSave, recorded per scenario.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkSaveGroup
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
 */
class MagicMapperUltraFastBulkSaveGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/ultra-fast-bulk-save-golden.json';

	/**
	 * Everything the mapper did, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trace = [];

	public function testUltraFastBulkSaveMatchesGolden(): void {
		$obj = static fn (string $id, $register, $schema): array => ['id' => $id, '@self' => array_filter(['register' => $register, 'schema' => $schema], static fn ($v) => $v !== null)];
		$scenarios = [
			'empty, no context' => [[], [], null, null],
			'one schema, given' => [[$obj('a', 1, 2)], [], 1, 2],
			'one schema, resolved' => [[$obj('a', 1, 2), $obj('b', 1, 2)], [], null, null],
			'mixed schemas' => [[$obj('a', 1, 2), $obj('b', 1, 3), $obj('c', 1, 2)], [], null, null],
			'mixed, given register' => [[$obj('a', 1, 2), $obj('b', 4, 3)], [], 7, null],
			'given register fills a missing one' => [[$obj('a', null, 2), $obj('b', 7, 2)], [], 7, null],
			'mixed, register from object only' => [[$obj('a', null, 2), $obj('b', 1, 3)], [], null, null],
			'mixed, unknown register' => [[$obj('a', 99, 2), $obj('b', 1, 3)], [], null, null],
			'mixed, unknown schema' => [[$obj('a', 1, 98), $obj('b', 1, 3)], [], null, null],
			'mixed, one without schema' => [[$obj('a', 1, 2), $obj('x', 1, null), $obj('b', 1, 3)], [], null, null],
			'without schema, one group' => [[$obj('a', 1, 2), $obj('x', 1, null)], [], null, null],
			'given schema, mixed objects' => [[$obj('a', 1, 2), $obj('b', 1, 3)], [], 1, 2],
			'updates only' => [[], [$obj('u', 1, 2)], 1, 2],
			'mixed with updates' => [[$obj('a', 1, 2), $obj('b', 1, 3)], [$obj('u', 1, 2)], null, null],
		];

		$actual = [];
		foreach ($scenarios as $name => [$inserts, $updates, $registerId, $schemaId]) {
			foreach ([true, false] as $preUpdate) {
				$this->trace = [];
				$outcome = [];
				try {
					$result = $this->mapper()->ultraFastBulkSave(
						$inserts,
						$updates,
						$registerId === null ? null : $this->register($registerId),
						$schemaId === null ? null : $this->schema($schemaId),
						$preUpdate
					);
					$outcome['result'] = $result;
				} catch (Exception $e) {
					$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
				}

				$outcome['trace'] = $this->trace;
				$actual[$name . ($preUpdate === true ? '' : ' / no pre-update state')] = $outcome;
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
	}//end testUltraFastBulkSaveMatchesGolden()

	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}//end register()

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}//end schema()

	/**
	 * The mapper under test; ids 98 and 99 are not found.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(): MagicMapper {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function ($id): Register {
				$this->trace[] = 'find register ' . var_export($id, true);
				if ((int) $id === 99) {
					throw new Exception('no register');
				}

				return $this->register((int) $id);
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id): Schema {
				$this->trace[] = 'find schema ' . var_export($id, true);
				if ((int) $id === 98) {
					throw new Exception('no schema');
				}

				return $this->schema((int) $id);
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
					$this->createMock(IDBConnection::class),
					$schemaMapper,
					$registerMapper,
					$this->createMock(IConfig::class),
					$this->createMock(IEventDispatcher::class),
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IUserManager::class),
					$this->createMock(IAppConfig::class),
					$logger,
					$this->createMock(SettingsService::class),
					$container,
				]
			)
			->onlyMethods(['ensureTableForRegisterSchema', 'bulkUpsert'])
			->getMock();

		$mapper->method('ensureTableForRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema): bool {
				$this->trace[] = 'ensure table ' . $register->getId() . '_' . $schema->getId();
				return true;
			}
		);
		$mapper->method('bulkUpsert')->willReturnCallback(
			function (array $objects, Register $register, Schema $schema, string $tableName, bool $needsPreUpdateState = true): array {
				$ids = array_map(static fn (array $o): string => $o['id'], $objects);
				$this->trace[] = 'upsert ' . $tableName . ' ' . implode(',', $ids) . ' pre=' . var_export($needsPreUpdateState, true);
				return array_map(static fn (string $id): string => $tableName . ':' . $id, $ids);
			}
		);

		return $mapper;
	}//end mapper()
}//end class
