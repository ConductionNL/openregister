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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
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

	/**
	 * Everything the mapper did, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trace = [];

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
				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('fetchAll')->willReturn([]);
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
			DateTimeNormalizer::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverter::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container->method('get')->willReturnCallback(static fn (string $id) => ($services[$id] ?? null));

		return new MagicMapper(
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
			$this->createMock(SettingsService::class),
			$container
		);
	}//end mapper()
}//end class
