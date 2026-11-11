<?php

/**
 * OpenRegister - the pairs a sequential cross-table search reads.
 *
 * Pins how the sequential path treats each register+schema pair: a valid
 * pair contributes its rows tagged with its register and schema, an invalid
 * pair is logged as a warning and skipped, and a table whose search fails is
 * logged as an error and skipped while the other tables still answer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\ObjectEntity;
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
use OCA\OpenRegister\Support\QueryLimit;
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
 * Valid, invalid and failing pairs on the sequential search path.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder
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
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RecentLensOrder
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSequentialOrder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionPage
 * @uses   \OCA\OpenRegister\Support\OrderParameter
 * @uses   \OCA\OpenRegister\Support\QueryLimit
 */
class MagicMapperSequentialPairsGoldenTest extends TestCase {

	/**
	 * Rows per table slug; `kapot` throws.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ROWS = [
		'zaak' => ['z1', 'z3', 'z5'],
		'taak' => ['t2', 't4'],
		'leeg' => [],
	];

	/**
	 * Logged messages, as "level message".
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * A mapper whose per-table search pages fixed rows and fails for `kapot`.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(): MagicMapper {
		$container = $this->createMock(ContainerInterface::class);
		$services = [
			DateTimeNormalizerInterface::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverterInterface::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container->method('get')->willReturnCallback(static fn (string $id) => ($services[$id] ?? null));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$keys = ['error', 'registerId', 'schemaId', 'resultCount'];
					$extra = implode('|', array_map(static fn (string $key): string => (string) ($context[$key] ?? ''), $keys));
					$this->log[] = $level . ' ' . $message . ' ' . $extra;
				}
			);
		}

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
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
						$this->createMock(IConfig::class),
						$this->createMock(IAppConfig::class),
						$this->createMock(IEventDispatcher::class),
						$logger
					),
				]
			)
			->onlyMethods(['searchObjectsInRegisterSchemaTable'])
			->getMock();

		$mapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			static function (array $query, Register $register, Schema $schema): array {
				if ($schema->getSlug() === 'kapot') {
					throw new Exception('table missing');
				}

				$rows = array_slice(
					self::ROWS[$schema->getSlug()],
					max(0, (int) ($query['_offset'] ?? 0)),
					QueryLimit::normalise($query['_limit'] ?? null)
				);

				return array_map(
					static function (string $uuid): ObjectEntity {
						$object = new ObjectEntity();
						$object->setUuid($uuid);
						return $object;
					},
					$rows
				);
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * A register+schema pair; null slug leaves the schema out.
	 *
	 * @param string|null $slug       The schema slug.
	 * @param int         $registerId The register id.
	 *
	 * @return array<string, mixed> The pair.
	 */
	private static function pair(?string $slug, int $registerId): array {
		$register = new Register();
		$register->setId($registerId);
		if ($slug === null) {
			return ['register' => $register];
		}

		$schema = new Schema();
		$schema->setId(crc32($slug) % 1000);
		$schema->setSlug($slug);
		$schema->setTitle(ucfirst($slug));
		return ['register' => $register, 'schema' => $schema];
	}//end pair()

	/**
	 * Pair lists and pages.
	 *
	 * @return array<string, array{0: array<int, array{0: ?string, 1: int}>, 1: array<string, mixed>}> The cases.
	 */
	public static function cases(): array {
		$pairSets = [
			'valid' => [['zaak', 7], ['taak', 8]],
			'invalid-first' => [[null, 7], ['zaak', 7], ['taak', 8]],
			'failing-middle' => [['zaak', 7], ['kapot', 9], ['taak', 8]],
			'all-bad' => [[null, 3], ['kapot', 9]],
			'empty-table' => [['leeg', 5], ['taak', 8]],
			'none' => [],
		];
		$queries = [
			'all' => ['_aggregations' => []],
			'p1' => ['_aggregations' => [], '_offset' => 0, '_limit' => 2],
			'p2' => ['_aggregations' => [], '_offset' => 2, '_limit' => 2],
			'tail' => ['_aggregations' => [], '_offset' => 4, '_limit' => 10],
			'negative' => ['_aggregations' => [], '_offset' => -3, '_limit' => 3],
		];
		$cases = [];
		foreach ($pairSets as $pairName => $pairs) {
			foreach ($queries as $queryName => $query) {
				$cases[$pairName . '/' . $queryName] = [$pairs, $query];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Rows, their tags and the log lines match the recorded golden.
	 *
	 * @dataProvider cases
	 *
	 * @param array<int, array{0: ?string, 1: int}> $pairSpec The pairs.
	 * @param array<string, mixed>                  $query    The query.
	 *
	 * @return void
	 */
	public function testSequentialPairsMatchGolden(array $pairSpec, array $query): void {
		$pairs = array_map(static fn (array $spec): array => self::pair($spec[0], $spec[1]), $pairSpec);
		$results = $this->mapper()->searchAcrossMultipleTables(query: $query, registerSchemaPairs: $pairs);

		$actual = [
			'rows' => array_map(
				static fn (ObjectEntity $object): string => $object->getUuid() . '@' . $object->getRegister() . '/' . $object->getSchema(),
				$results
			),
			'log' => $this->log,
		];

		$file = __DIR__ . '/fixtures/sequential-pairs-golden.json';
		$key = (string) $this->dataName();
		$golden = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$key] = $actual;
			ksort($golden);
			file_put_contents($file, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($key, $golden, 'no golden recorded for ' . $key);
		$this->assertSame($golden[$key], $actual);
	}//end testSequentialPairsMatchGolden()
}//end class
