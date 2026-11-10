<?php

/**
 * OpenRegister - golden for MagicMapper::updateTableStructure().
 *
 * Records, per scenario and platform, every DDL statement, every log line
 * and the returned change summary, so a decomposition keeps the exact
 * statements and their order. A column named `boom` makes its statement
 * fail. Re-record with UPDATE_GOLDEN=1.
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

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\IPreparedStatement;
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
 * Table structure updates, recorded per scenario and platform.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnAdder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNullability
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnRetyper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlFailure
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDecimalWidener
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNullabilityChange
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberWidening
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicObsoleteColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableAlterer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableColumnReader
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableStructureSync
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 */
class MagicMapperUpdateTableStructureGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/update-table-structure-golden.json';

	private const HELPER_FIXTURE = __DIR__ . '/fixtures/table-alter-helpers-golden.json';

	/**
	 * Everything the mapper did, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trace = [];

	/**
	 * The information_schema rows a prepared statement answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $columnRows = [];

	/**
	 * Whether preparing a statement fails.
	 *
	 * @var boolean
	 */
	private bool $prepareFails = false;

	public function testUpdateTableStructureMatchesGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => [$current, $required]) {
			foreach (['mysql' => MySQLPlatform::class, 'postgres' => PostgreSQLPlatform::class] as $platformName => $platform) {
				$this->trace = [];
				$outcome = [];
				try {
					$outcome['result'] = $this->mapper($platform)->updateTableStructure('openregister_table_1_2', $current, $required);
				} catch (Exception $e) {
					$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
				}

				$outcome['trace'] = $this->trace;
				$actual[$name . ' / ' . $platformName] = $outcome;
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
	}//end testUpdateTableStructureMatchesGolden()

	public function testTableHelpersMatchGolden(): void {
		$actual = [];
		foreach (['mysql' => MySQLPlatform::class, 'postgres' => PostgreSQLPlatform::class] as $platformName => $platform) {
			foreach ($this->helperCalls() as $name => $call) {
				$this->trace = [];
				$this->columnRows = self::columnRows();
				$this->prepareFails = str_contains($name, 'unreadable');
				$outcome = [];
				try {
					$outcome['result'] = $call($this->mapper($platform));
				} catch (Exception $e) {
					$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
				}

				$outcome['trace'] = $this->trace;
				$actual[$name . ' / ' . $platformName] = $outcome;
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::HELPER_FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::HELPER_FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testTableHelpersMatchGolden()

	/**
	 * Rows as information_schema returns them.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private static function columnRows(): array {
		$row = static fn (string $name, string $type, array $rest = []): array => $rest + [
			'column_name' => $name,
			'data_type' => $type,
			'character_maximum_length' => null,
			'numeric_precision' => null,
			'numeric_scale' => null,
			'is_nullable' => 'YES',
			'column_default' => null,
		];

		return [
			$row('title', 'character varying', ['character_maximum_length' => 255, 'is_nullable' => 'NO']),
			$row('amount', 'numeric', ['numeric_precision' => 10, 'numeric_scale' => 2, 'column_default' => '0.00', 'is_nullable' => 'NO']),
			$row('price', 'DECIMAL', ['numeric_precision' => 12, 'numeric_scale' => 2]),
			$row('wide', 'numeric', ['numeric_precision' => 30, 'numeric_scale' => 10]),
			$row('boom', 'numeric', ['numeric_precision' => 8, 'numeric_scale' => 1]),
			$row('note', 'text'),
		];
	}//end columnRows()

	/**
	 * The public helpers around a structure update, one call each.
	 *
	 * @return array<string, callable(MagicMapper): mixed> The calls.
	 */
	private function helperCalls(): array {
		$table = 'openregister_table_1_2';
		$current = [
			'owner_ref' => ['name' => 'owner_ref', 'type' => 'json'],
			'parent' => ['name' => 'parent', 'type' => 'JSONB'],
			'boom' => ['name' => 'boom', 'type' => 'jsonb'],
			'rules' => ['name' => 'rules', 'type' => 'jsonb'],
			'title' => ['name' => 'title', 'type' => 'varchar'],
			'amount' => ['name' => 'amount', 'type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => false, 'default' => '0.00'],
			'price' => ['name' => 'price', 'type' => 'numeric', 'precision' => 12, 'scale' => 2, 'default' => "'x'::text"],
			'no_size' => ['name' => 'no_size', 'type' => 'decimal'],
		];
		$required = [
			'ownerRef' => ['type' => 'string'],
			'parent' => ['name' => 'parent', 'type' => 'string'],
			'boom' => ['name' => 'boom', 'type' => 'string'],
			'rules' => ['name' => 'rules', 'type' => 'json_ordered'],
			'title' => ['name' => 'title', 'type' => 'string'],
			'amount' => ['name' => 'amount', 'type' => 'decimal', 'precision' => 20, 'scale' => 6],
			'price' => ['name' => 'price', 'type' => 'decimal'],
			'noSize' => ['type' => 'decimal'],
			'absentNumber' => ['name' => 'absent_number', 'type' => 'decimal'],
			'absentText' => ['type' => 'json_ordered'],
		];
		$numbers = array_intersect_key($required, ['amount' => true, 'price' => true, 'noSize' => true, 'absentNumber' => true]);
		$failing = ['boom' => ['name' => 'boom', 'type' => 'decimal', 'precision' => 12, 'scale' => 4]];
		$failingLive = ['boom' => ['name' => 'boom', 'type' => 'decimal', 'precision' => 8, 'scale' => 1]];

		return [
			'columns' => static fn (MagicMapper $m) => $m->getExistingTableColumns($table),
			'columns unreadable' => static fn (MagicMapper $m) => $m->getExistingTableColumns($table),
			'missing' => static fn (MagicMapper $m) => $m->findMissingColumns($current, $required),
			'jsonb' => static fn (MagicMapper $m) => $m->findJsonbColumnsNeedingRetype($current, $required),
			'relation' => static fn (MagicMapper $m) => $m->findRelationColumnsNeedingRetype($current, $required),
			'relation retype' => static fn (MagicMapper $m) => $m->migrateRelationColumnsToVarchar($table, new MagicColumnDrift($current, $required)),
			'decimal' => static fn (MagicMapper $m) => $m->findDecimalColumnsNeedingWiden($current, $required),
			'widen' => static fn (MagicMapper $m) => $m->widenDecimalColumns($table, new MagicColumnDrift($current, $numbers)),
			'decimal retyped to text' => static fn (MagicMapper $m) => $m->findDecimalColumnsNeedingWiden($current, ['amount' => ['name' => 'amount', 'type' => 'string']]),
			'metadata camelCase kept' => static fn (MagicMapper $m) => $m->updateTableStructure(
				$table,
				['_fooBar' => ['name' => '_fooBar', 'type' => 'varchar'], '_foo_bar' => ['name' => '_foo_bar', 'type' => 'varchar']],
				['fooBar' => ['name' => '_foo_bar', 'type' => 'string']]
			),
			'widen nothing' => static fn (MagicMapper $m) => $m->widenDecimalColumns($table, new MagicColumnDrift(['title' => $current['title']], [])),
			'widen fails' => static fn (MagicMapper $m) => $m->widenDecimalColumns($table, new MagicColumnDrift($failingLive, $failing)),
			'widen to default' => static fn (MagicMapper $m) => $m->widenNumberColumnsToDefault($table),
			'add column plain' => static fn (MagicMapper $m) => $m->buildAddColumnSql($table, ['name' => 'plain', 'type' => 'string']),
			'add column text' => static fn (MagicMapper $m) => $m->buildAddColumnSql($table, ['name' => 'who', 'type' => 'string', 'default' => "O'Brien", 'nullable' => false]),
			'add column flag' => static fn (MagicMapper $m) => $m->buildAddColumnSql($table, ['name' => 'on', 'type' => 'boolean', 'default' => false]),
			'add column number' => static fn (MagicMapper $m) => $m->buildAddColumnSql($table, ['name' => 'n', 'type' => 'integer', 'default' => 3]),
		];
	}//end helperCalls()

	/**
	 * Current and required columns per scenario.
	 *
	 * @return array<string, array{0: array, 1: array}> The scenarios.
	 */
	private function scenarios(): array {
		$current = [
			'_uuid' => ['name' => '_uuid', 'type' => 'varchar', 'nullable' => false],
			'_custom' => ['name' => '_custom', 'type' => 'varchar', 'nullable' => false],
			'title' => ['name' => 'title', 'type' => 'varchar', 'nullable' => false],
			'count' => ['name' => 'count', 'type' => 'integer', 'nullable' => true],
			'flag' => ['name' => 'flag', 'type' => 'boolean', 'nullable' => true],
			'firstName' => ['name' => 'firstName', 'type' => 'varchar', 'nullable' => true],
			'first_name' => ['name' => 'first_name', 'type' => 'varchar', 'nullable' => true],
			'lastName' => ['name' => 'lastName', 'type' => 'varchar', 'nullable' => false],
			'legacy' => ['name' => 'legacy', 'type' => 'text', 'nullable' => false],
			'old_open' => ['name' => 'old_open', 'type' => 'text', 'nullable' => true],
			'rules' => ['name' => 'rules', 'type' => 'jsonb', 'nullable' => true],
			'plain' => ['name' => 'plain', 'type' => 'json', 'nullable' => true],
			'amount' => ['name' => 'amount', 'type' => 'decimal', 'nullable' => false, 'precision' => 10, 'scale' => 2, 'default' => '0.00'],
			'boom' => ['name' => 'boom', 'type' => 'varchar', 'nullable' => false],
		];
		$required = [
			'title' => ['name' => 'title', 'type' => 'string', 'nullable' => true],
			'count' => ['name' => 'count', 'type' => 'integer', 'nullable' => false, 'default' => 0],
			'flag' => ['name' => 'flag', 'type' => 'boolean', 'nullable' => false],
			'firstName' => ['name' => 'first_name', 'type' => 'string', 'nullable' => true],
			'lastName' => ['name' => 'last_name', 'type' => 'string', 'nullable' => true],
			'rules' => ['name' => 'rules', 'type' => 'json_ordered', 'nullable' => true],
			'plain' => ['name' => 'plain', 'type' => 'json_ordered', 'nullable' => true],
			'amount' => ['name' => 'amount', 'type' => 'decimal', 'nullable' => false, 'precision' => 20, 'scale' => 4],
			'newCol' => ['name' => 'new_col', 'type' => 'string', 'nullable' => true, 'length' => 80],
			'newReq' => ['name' => 'new_req', 'type' => 'integer', 'nullable' => false, 'default' => 5],
			'newReq2' => ['type' => 'string', 'nullable' => false],
			'newBool' => ['name' => 'new_bool', 'type' => 'boolean', 'nullable' => false, 'default' => true],
			'newText' => ['name' => 'new_text', 'type' => 'text', 'nullable' => false, 'default' => "it's"],
			'boom' => ['name' => 'boom', 'type' => 'string', 'nullable' => true],
			'boomNew' => ['name' => 'boom_new', 'type' => 'string', 'nullable' => true],
		];

		return [
			'nothing' => [[], []],
			'all new' => [[], $required],
			'in sync' => [['title' => $current['title']], ['title' => ['name' => 'title', 'type' => 'string', 'nullable' => false]]],
			'mixed' => [$current, $required],
			'mixed, statements succeed' => [array_diff_key($current, ['boom' => true]), array_diff_key($required, ['boom' => true, 'boomNew' => true])],
			'failing existing column' => [$current, array_diff_key($required, ['boomNew' => true])],
			'obsolete only' => [$current, []],
			'decimal only' => [['amount' => $current['amount']], ['amount' => $required['amount']]],
		];
	}//end scenarios()

	/**
	 * The mapper under test on a platform, with a recording database and logger.
	 *
	 * @param class-string $platform The DBAL platform class.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(string $platform): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$dbPlatform = $this->createMock($platform);
		$dbPlatform->method('quoteStringLiteral')->willReturnCallback(static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'");
		$db->method('getDatabasePlatform')->willReturn($dbPlatform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->trace[] = 'sql ' . preg_replace('/\s+/', ' ', trim($sql)) . ' ' . json_encode($params);
				if (str_contains($sql, 'boom') === true) {
					throw new Exception('statement failed');
				}

				return 0;
			}
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				$this->trace[] = 'prepare ' . preg_replace('/\s+/', ' ', trim($sql));
				if ($this->prepareFails === true) {
					throw new Exception('prepare failed');
				}

				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('fetchAll')->willReturn($this->columnRows);
				return $stmt;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn (string $key, $default = '') => ($key === 'dbtableprefix' ? 'oc_' : $default));

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

		return new MagicMapper(
			$db,
			new MagicMapperLookups(
				$this->createMock(SchemaMapper::class),
				$this->createMock(RegisterMapper::class),
				$container
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
	}//end mapper()
}//end class
