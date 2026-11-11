<?php

/**
 * ReferentialIntegrityService finds the rows citing an object, by uuid or by key, exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Object\IntegrityCascadeControls;
use OCA\OpenRegister\Service\Object\IntegrityRelationIndex;
use OCA\OpenRegister\Service\Object\IntegrityStorage;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * Golden record of the citing-row lookups: the SQL sent for each platform and property shape, the
 * value bound, the entities built from the rows (register, schema, deletion and matched property),
 * the broad relation fallback and its filtering, and the answers and warnings when a table is
 * missing or cannot be read. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\ReferentialIntegrityService
 * @uses \OCA\OpenRegister\Service\Object\IntegrityAuditEntry
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\DeletionGraph\DeclaredRelation
 */
class ReferentialIntegrityReferencingGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/referential-integrity-referencing-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->outcome(scenario: $scenario);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testScenariosMatchTheGolden()

	/**
	 * The scenarios.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$rows = [
			['_uuid' => 'r1', '_register' => '7', '_schema' => '2', '_deleted' => null, '_prop' => 'target'],
			['_uuid' => 'r2', '_deleted' => 'null'],
			['_uuid' => 'r3', '_deleted' => '{"deletedBy":"ann"}', '_prop' => '["target"]'],
			['_uuid' => 'r4', '_deleted' => 'not json'],
			['_uuid' => 'r5', '_deleted' => ['deletedBy' => 'bob'], '_register' => null],
			['_uuid' => 'r6', '_deleted' => 5],
		];
		$cand = static function (string $uuid, ?string $schema, ?array $object): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			if ($schema !== null) {
				$entity->setSchema($schema);
			}

			if ($object !== null) {
				$entity->setObject($object);
			}

			return $entity;
		};
		$candidates = [
			$cand('c1', '2', ['labels' => 'target']),
			$cand('c2', '3', ['labels' => 'target']),
			$cand('c3', '2', null),
			$cand('c4', '2', ['other' => 'target']),
			$cand('c5', '2', ['labels' => 'other']),
			$cand('c6', '2', ['labels' => ['x', 'target']]),
			$cand('c7', '2', ['labels' => ['x']]),
			$cand('c8', null, ['labels' => 'target']),
			$cand('c9', '2', ['labels' => ['target']]),
		];

		return [
			'mysql scalar'            => ['lookup' => 'uuid', 'rows' => $rows],
			'mysql array'             => ['lookup' => 'uuid', 'isArray' => true, 'rows' => $rows],
			'postgres scalar'         => ['lookup' => 'uuid', 'platform' => 'postgres', 'rows' => $rows],
			'postgres array'          => ['lookup' => 'uuid', 'platform' => 'postgres', 'isArray' => true, 'rows' => [$rows[0]]],
			'key, mysql array'        => ['lookup' => 'key', 'isArray' => true, 'rows' => $rows],
			'key, postgres scalar'    => ['lookup' => 'key', 'platform' => 'postgres', 'rows' => [$rows[1]]],
			'no rows'                 => ['lookup' => 'uuid', 'rows' => []],
			'empty value'             => ['lookup' => 'uuid', 'value' => '', 'rows' => $rows],
			'key, empty value'        => ['lookup' => 'key', 'value' => '', 'rows' => $rows],
			'prepare fails, no table' => ['lookup' => 'uuid', 'fail' => 'prepare', 'tableExists' => false],
			'prepare fails, table'    => ['lookup' => 'uuid', 'fail' => 'prepare', 'tableExists' => true],
			'key fails, no table'     => ['lookup' => 'key', 'fail' => 'prepare', 'tableExists' => false],
			'key fails, table'        => ['lookup' => 'key', 'fail' => 'prepare', 'tableExists' => true],
			'no column, table'        => ['lookup' => 'uuid', 'fail' => 'column', 'tableExists' => true],
			'key no column, table'    => ['lookup' => 'key', 'fail' => 'column', 'tableExists' => true],
			'fallback scalar'         => ['lookup' => 'uuid', 'mapped' => false, 'candidates' => $candidates],
			'fallback array'          => ['lookup' => 'uuid', 'mapped' => false, 'isArray' => true, 'candidates' => $candidates],
			'fallback fails'          => ['lookup' => 'uuid', 'mapped' => false, 'fail' => 'relation'],
			'fallback, no schema'     => ['lookup' => 'uuid', 'mapped' => 'schemaless', 'candidates' => $candidates],
			'key, no register'        => ['lookup' => 'key', 'mapped' => false],
			'key, no schema'          => ['lookup' => 'key', 'mapped' => 'registerOnly'],
			'fallback, no schema row' => ['lookup' => 'uuid', 'mapped' => 'registerOnly', 'candidates' => $candidates],
		];
	}//end scenarios()

	/**
	 * Run one lookup and record what it sent and answered.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed> The recorded outcome.
	 */
	private function outcome(array $scenario): array {
		$record = ['sql' => [], 'bound' => [], 'logs' => []];
		$platform = new MySQLPlatform();
		if (($scenario['platform'] ?? 'mysql') === 'postgres') {
			$platform = new PostgreSQLPlatform();
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('getTableName')->willReturnCallback(
			fn (string $table): string => $platform->quoteIdentifier('oc_' . $table)
		);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('tableExists')->willReturnCallback(
			function (string $table) use (&$record, $scenario): bool {
				$record['tableExists'] = $table;
				return $scenario['tableExists'] ?? true;
			}
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use (&$record, $scenario): IPreparedStatement {
				$record['sql'][] = $sql;
				if (($scenario['fail'] ?? null) === 'prepare') {
					throw new Exception('prepare failed');
				}

				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('execute')->willReturnCallback(
					function (array $params) use (&$record): IResult {
						$record['bound'][] = $params;
						return $this->createMock(IResult::class);
					}
				);
				$stmt->method('fetchAll')->willReturn($scenario['rows'] ?? []);
				return $stmt;
			}
		);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_7_2');
		$columns = ['labels' => ['name' => 'labels_1', 'type' => 'json']];
		if (($scenario['fail'] ?? null) === 'column') {
			$columns = [];
		}

		$mapper->method('buildTableColumnsFromSchema')->willReturn($columns);
		$mapper->method('findByRelation')->willReturnCallback(
			function (string $uuid) use (&$record, $scenario): array {
				$record['relation'] = $uuid;
				if (($scenario['fail'] ?? null) === 'relation') {
					throw new Exception('relation search failed');
				}

				return $scenario['candidates'] ?? [];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message, array $context = []) use (&$record): void {
				$record['logs'][] = [$message, $context];
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$service = new ReferentialIntegrityService(
			index: new IntegrityRelationIndex(catalog: new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapperInterface::class)), cacheFactory: $cacheFactory),
			storage: new IntegrityStorage(objectEntityMapper: $mapper, db: $db),
			controls: new IntegrityCascadeControls(auditTrailMapper: $this->createMock(AuditTrailMapper::class), archivalGuard: $this->createMock(ArchivalRetentionGuard::class)),
			logger: $logger
		);

		$schema = new Schema();
		$schema->setId(2);
		$register = new Register();
		$register->setId(7);
		$mapped = $scenario['mapped'] ?? true;
		(new ReflectionProperty(ReferentialIntegrityService::class, 'schemaCache'))->setValue($service, in_array($mapped, [false, 'registerOnly'], true) === true ? [] : ['2' => $schema]);
		(new ReflectionProperty(ReferentialIntegrityService::class, 'schemaRegisterMap'))->setValue($service, in_array($mapped, [true, 'registerOnly'], true) === true ? ['2' => $register] : []);

		$dep = [
			'sourceSchemaId' => '2',
			'property' => 'labels',
			'onDelete' => 'RESTRICT',
			'isArray' => $scenario['isArray'] ?? false,
			'referenceKey' => $scenario['lookup'] === 'key' ? 'code' : null,
		];
		$found = $this->lookup(service: $service, dep: $dep, value: $scenario['value'] ?? 'target');

		$record['found'] = null;
		if ($found !== null) {
			$record['found'] = array_map(
				static fn (ObjectEntity $entity): array => [
					$entity->getUuid(),
					$entity->getRegister(),
					$entity->getSchema(),
					$entity->getDeleted(),
					$entity->getObject(),
				],
				$found
			);
		}

		return $record;
	}//end outcome()

	/**
	 * Call the uuid or key lookup the relation asks for.
	 *
	 * @param ReferentialIntegrityService $service The service.
	 * @param array<string, mixed>        $dep     The relation index entry.
	 * @param string                      $value   The cited uuid or key value.
	 *
	 * @return ObjectEntity[]|null The citing rows.
	 */
	private function lookup(ReferentialIntegrityService $service, array $dep, string $value): ?array {
		if ($dep['referenceKey'] !== null) {
			return (new ReflectionMethod(ReferentialIntegrityService::class, 'findReferencingByKey'))->invoke($service, $dep, $value);
		}

		return (new ReflectionMethod(ReferentialIntegrityService::class, 'findReferencingObjects'))->invoke($service, $dep, $value);
	}//end lookup()
}//end class
