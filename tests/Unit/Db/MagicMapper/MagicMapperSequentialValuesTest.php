<?php

/**
 * What a table-by-table cross-schema search asks each table and how it merges.
 *
 * Driven through the public search, so the same cases pin the code on any
 * class layout.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
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
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSequentialOrder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionPage
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RecentLensOrder
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Support\OrderParameter
 * @uses   \OCA\OpenRegister\Support\QueryLimit
 */
class MagicMapperSequentialValuesTest extends TestCase {
	/**
	 * The `_order` each table was asked for.
	 *
	 * @var array<int, mixed>
	 */
	private array $asked = [];

	public function testAKeyNamedTwiceAsksTheTableOnce(): void {
		$this->search(query: ['_order' => ['_name' => 'desc', '@self.name' => 'asc']], entities: []);

		$this->assertSame(['@self.name' => 'DESC', '@self.uuid' => 'ASC'], $this->asked[0]);
	}//end testAKeyNamedTwiceAsksTheTableOnce()

	public function testTheTableIsAskedForTheScoreAsRelevance(): void {
		$this->search(query: ['_search' => 'x'], entities: []);

		$this->assertSame(['_relevance' => 'DESC', '@self.uuid' => 'ASC'], $this->asked[0]);
	}//end testTheTableIsAskedForTheScoreAsRelevance()

	public function testAPropertyIsAskedUnderItsOwnName(): void {
		$this->search(query: ['_order' => ['firstName' => 'desc']], entities: []);

		$this->assertSame(['firstName' => 'DESC', '@self.uuid' => 'ASC'], $this->asked[0]);
	}//end testAPropertyIsAskedUnderItsOwnName()

	public function testTheScoreComesFromTheRowThenTheRelevanceAsAFraction(): void {
		$entities = [
			$this->entity(uuid: 'none'),
			$this->entity(uuid: 'relevance', relevance: 50),
			$this->entity(uuid: 'data', data: ['_search_score' => 0.9]),
		];

		$this->assertSame(['data', 'relevance', 'none'], $this->search(query: ['_search' => 'x'], entities: $entities));
	}//end testTheScoreComesFromTheRowThenTheRelevanceAsAFraction()

	public function testDatesCompareToTheMicrosecond(): void {
		$entities = [
			$this->entity(uuid: 'b', data: ['when' => new DateTime('2026-01-01 10:00:00.000002')]),
			$this->entity(uuid: 'c', data: ['when' => new DateTime('2026-01-01 10:00:00.000001')]),
		];

		$this->assertSame(['c', 'b'], $this->search(query: ['_order' => ['when' => 'asc']], entities: $entities));
	}//end testDatesCompareToTheMicrosecond()

	public function testBooleansCompareAsNumbersAfterAMissingValue(): void {
		$entities = [
			$this->entity(uuid: 'a', data: ['flag' => true]),
			$this->entity(uuid: 'b', data: ['flag' => false]),
			$this->entity(uuid: 'c'),
		];

		$this->assertSame(['c', 'b', 'a'], $this->search(query: ['_order' => ['flag' => 'asc']], entities: $entities));
	}//end testBooleansCompareAsNumbersAfterAMissingValue()

	public function testArraysCompareAsJson(): void {
		$entities = [
			$this->entity(uuid: 'a', data: ['tags' => ['z']]),
			$this->entity(uuid: 'b', data: ['tags' => ['a']]),
		];

		$this->assertSame(['b', 'a'], $this->search(query: ['_order' => ['tags' => 'asc']], entities: $entities));
	}//end testArraysCompareAsJson()

	public function testATwoWordMetadataColumnReadsTheEntityField(): void {
		$first = $this->entity(uuid: 'z');
		$first->setLastUserActivity(new DateTime('2026-01-01 00:00:00'));
		$second = $this->entity(uuid: 'a');
		$second->setLastUserActivity(new DateTime('2026-01-02 00:00:00'));

		$this->assertSame(
			['z', 'a'],
			$this->search(query: ['_order' => ['@self.lastUserActivity' => 'asc']], entities: [$second, $first])
		);
	}//end testATwoWordMetadataColumnReadsTheEntityField()

	/**
	 * Run a search on the sequential path, each table holding the entities once.
	 *
	 * @param array<string, mixed>     $query    The query.
	 * @param array<int, ObjectEntity> $entities What the first table answers.
	 *
	 * @return array<int, string|null> The uuids.
	 */
	private function search(array $query, array $entities): array {
		$mapper = $this->mapper(entities: $entities);
		$results = $mapper->searchAcrossMultipleTables(
			query: array_merge($query, ['_aggregations' => []]),
			registerSchemaPairs: [$this->pair(id: 1), $this->pair(id: 2)]
		);

		return array_map(static fn (ObjectEntity $object): ?string => $object->getUuid(), $results);
	}//end search()

	/**
	 * An entity.
	 *
	 * @param string               $uuid      The uuid.
	 * @param array<string, mixed> $data      The object data.
	 * @param float|null           $relevance The relevance.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data = [], ?float $relevance = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);
		$object->setRelevance($relevance);

		return $object;
	}//end entity()

	/**
	 * A pair whose schema has the ordered properties.
	 *
	 * @param int $id The schema id.
	 *
	 * @return array{register: Register, schema: Schema} The pair.
	 */
	private function pair(int $id): array {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('s' . $id);
		$schema->setProperties(
			[
				'firstName' => ['type' => 'string'],
				'when' => ['type' => 'string'],
				'flag' => ['type' => 'boolean'],
				'tags' => ['type' => 'array'],
			]
		);
		$register = new Register();
		$register->setId(7);

		return ['register' => $register, 'schema' => $schema];
	}//end pair()

	/**
	 * The mapper, with the per-table search answered from the entities.
	 *
	 * @param array<int, ObjectEntity> $entities What the first table answers.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(array $entities): MagicMapper {
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
						$this->createMock(LoggerInterface::class)
					),
				]
			)
			->onlyMethods(['searchObjectsInRegisterSchemaTable'])
			->getMock();

		$mapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			function (RegisterSchema $table, array $query) use ($entities): array {
				[$register, $schema] = [$table->register, $table->schema];
				$this->asked[] = $query['_order'] ?? null;
				if ($schema->getId() === 1) {
					return $entities;
				}

				return [];
			}
		);

		return $mapper;
	}//end mapper()
}//end class
