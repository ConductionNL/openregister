<?php

/**
 * OpenRegister - the sequential cross-table search orders like the UNION one.
 *
 * A multi-schema search runs as one UNION statement, or table by table when
 * it carries `_aggregations`. The UNION path orders the merged rows on the
 * requested `_order`, else the search score, closed by the uuid. The
 * sequential path joined the tables one after the other, so the same
 * question came back in a different order, and with a different page 2,
 * depending on the path. These tests run the same query on both and expect
 * the same uuids on every page.
 *
 * The UNION path cannot run without a database, so its answer is taken from
 * mergeUnionBatchRows(): the PHP twin of its ORDER BY that the batched UNION
 * path already merges with. The per-table search is a fake that orders and
 * pages like the single-table query would, falling back to insertion (`_id`)
 * order when no `_order` reaches it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/sequential-cross-table-search-orders-like-union/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Support\QueryLimit;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The same multi-schema query pages the same on both paths.
 */
class MagicMapperSequentialOrderTest extends TestCase {

	/**
	 * Rows per table, in insertion (`_id`) order, as the UNION arms project them.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private const ROWS = [
		'zaak' => [
			['_uuid' => 'c3', '_created' => '2026-01-03 00:00:00', 'title' => 'beta', '_search_score' => 0.4],
			['_uuid' => 'a1', '_created' => '2026-01-05 00:00:00', 'title' => 'delta', '_search_score' => 0.9],
			['_uuid' => 'e5', '_created' => '2026-01-01 00:00:00', 'title' => 'alpha', '_search_score' => 0.4],
		],
		'taak' => [
			['_uuid' => 'b2', '_created' => '2026-01-04 00:00:00', 'title' => 'alpha', '_search_score' => 0.7],
			['_uuid' => 'f6', '_created' => '2026-01-02 00:00:00', '_search_score' => 0.1],
			['_uuid' => 'd4', '_created' => '2026-01-06 00:00:00', 'title' => 'gamma', '_search_score' => 0.4],
		],
	];

	/**
	 * The mapper, with the per-table search replaced by an ordering fake.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(): MagicMapper {
		$container = $this->createMock(ContainerInterface::class);
		$services = [
			DateTimeNormalizer::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverter::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container->method('get')->willReturnCallback(static fn (string $id) => ($services[$id] ?? null));

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					$this->createMock(SchemaMapper::class),
					$this->createMock(RegisterMapper::class),
					$this->createMock(IConfig::class),
					$this->createMock(IEventDispatcher::class),
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IUserManager::class),
					$this->createMock(IAppConfig::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SettingsService::class),
					$container,
				]
			)
			->onlyMethods(['searchObjectsInRegisterSchemaTable'])
			->getMock();

		$mapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			static function (array $query, Register $register, Schema $schema): array {
				$rows = self::orderLikeOneTable(rows: self::ROWS[$schema->getSlug()], query: $query);
				$rows = array_slice(
					$rows,
					max(0, (int) ($query['_offset'] ?? 0)),
					QueryLimit::normalise($query['_limit'] ?? null)
				);

				return array_map([self::class, 'entity'], $rows);
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * Order one table's rows the way the single-table query would.
	 *
	 * Reads the single-table `_order` syntax (`@self.x`, `_relevance`, a
	 * property name). With no `_order` the rows keep insertion order, which
	 * is the single-table default (`_id`).
	 *
	 * @param array<int, array<string, mixed>> $rows  The table's rows.
	 * @param array<string, mixed>             $query The per-table query.
	 *
	 * @return array<int, array<string, mixed>> The ordered rows.
	 */
	private static function orderLikeOneTable(array $rows, array $query): array {
		$order = $query['_order'] ?? [];
		if (is_array($order) === false || $order === []) {
			return $rows;
		}

		usort(
			$rows,
			static function (array $left, array $right) use ($order): int {
				foreach ($order as $field => $direction) {
					$column = $field;
					if (str_starts_with($field, '@self.') === true) {
						$column = '_' . substr($field, 6);
					} elseif ($field === '_relevance') {
						$column = '_search_score';
					}

					$comparison = ((string) ($left[$column] ?? null) <=> (string) ($right[$column] ?? null));
					if ($comparison !== 0) {
						return (strtoupper((string) $direction) === 'DESC') ? -$comparison : $comparison;
					}
				}

				return 0;
			}
		);

		return $rows;
	}//end orderLikeOneTable()

	/**
	 * One row as the entity the single-table search returns.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(array $row): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($row['_uuid']);
		$object->setCreated(new DateTime($row['_created']));
		$object->setRelevance($row['_search_score'] * 100);
		$data = [];
		if (array_key_exists('title', $row) === true) {
			$data['title'] = $row['title'];
		}

		$object->setObject($data);

		return $object;
	}//end entity()

	/**
	 * Two register+schema pairs.
	 *
	 * @return array<int, array{register: Register, schema: Schema}> The pairs.
	 */
	private function pairs(): array {
		$pairs = [];
		foreach (['zaak' => 1, 'taak' => 2] as $slug => $id) {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug($slug);
			$schema->setProperties(['title' => ['type' => 'string']]);
			$register = new Register();
			$register->setId(7);
			$pairs[] = ['register' => $register, 'schema' => $schema];
		}

		return $pairs;
	}//end pairs()

	/**
	 * A page on the sequential path (`_aggregations` sends it there), as uuids.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<int, string|null> The uuids.
	 */
	private function sequentialPage(array $query): array {
		$results = $this->mapper()->searchAcrossMultipleTables(
			query: array_merge($query, ['_aggregations' => []]),
			registerSchemaPairs: $this->pairs()
		);

		return array_map(static fn (ObjectEntity $object): ?string => $object->getUuid(), $results);
	}//end sequentialPage()

	/**
	 * The same page as the UNION path orders and cuts it, as uuids.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function unionPage(array $query): array {
		$method = new \ReflectionMethod(MagicMapper::class, 'mergeUnionBatchRows');
		$method->setAccessible(true);
		$rows = $method->invoke(
			$this->mapper(),
			array_merge(self::ROWS['zaak'], self::ROWS['taak']),
			$query,
			false
		);

		return array_column($rows, '_uuid');
	}//end unionPage()

	/**
	 * The orders the UNION path knows.
	 *
	 * @return array<string, array{0: array<string, mixed>}> Queries without paging.
	 */
	public static function orders(): array {
		return [
			'a property, with ties and a schema row that lacks it' => [['_order' => ['title' => 'ASC']]],
			'a metadata key' => [['_order' => ['@self.created' => 'DESC']]],
			'no order: the uuid' => [[]],
			'a search term: the score, then the uuid' => [['_search' => 'x']],
			'relevance asked by name' => [['_search' => 'x', '_order' => ['_relevance' => 'DESC']]],
			'an unknown metadata key is dropped' => [['_order' => ['@self.bogus' => 'ASC', 'title' => 'DESC']]],
		];
	}//end orders()

	/**
	 * Every page of the same query holds the same uuids on both paths.
	 *
	 * @param array<string, mixed> $query The query, without paging.
	 *
	 * @return void
	 */
	#[DataProvider('orders')]
	public function testEveryPageMatchesTheUnionPath(array $query): void {
		for ($offset = 0; $offset < 6; $offset += 2) {
			$paged = array_merge($query, ['_offset' => $offset, '_limit' => 2]);
			$this->assertSame(
				$this->unionPage(query: $paged),
				$this->sequentialPage(query: $paged),
				"page at offset {$offset}"
			);
		}

		$unpaged = array_merge($query, ['_limit' => false]);
		$this->assertSame($this->unionPage(query: $unpaged), $this->sequentialPage(query: $unpaged));
	}//end testEveryPageMatchesTheUnionPath()

	/**
	 * With no order the rows interleave across schemas by uuid.
	 *
	 * @return void
	 */
	public function testNoOrderInterleavesTheSchemasByUuid(): void {
		$this->assertSame(['a1', 'b2', 'c3'], $this->sequentialPage(query: ['_offset' => 0, '_limit' => 3]));
		$this->assertSame(['d4', 'e5', 'f6'], $this->sequentialPage(query: ['_offset' => 3, '_limit' => 3]));
	}//end testNoOrderInterleavesTheSchemasByUuid()

	/**
	 * The recent lens orders the sequential path by the read history.
	 *
	 * @return void
	 */
	public function testTheRecentLensOrdersByTheReadHistory(): void {
		$views = [
			'f6' => '2026-10-10T10:00:00+00:00',
			'c3' => '2026-10-10T09:00:00+00:00',
			'd4' => '2026-10-10T08:00:00+00:00',
		];

		$this->assertSame(
			['f6', 'c3'],
			$this->sequentialPage(query: ['_recentViews' => $views, '_offset' => 0, '_limit' => 2])
		);
		$this->assertSame(
			['d4', 'a1'],
			$this->sequentialPage(query: ['_recentViews' => $views, '_offset' => 2, '_limit' => 2])
		);
	}//end testTheRecentLensOrdersByTheReadHistory()
}//end class
