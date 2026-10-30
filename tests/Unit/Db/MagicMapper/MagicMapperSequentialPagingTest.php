<?php

/**
 * OpenRegister - paging on the sequential cross-table search path.
 *
 * The sequential fallback (taken for `_aggregations`) passed `_offset` and
 * `_limit` to every table and concatenated the pages. Each table then skipped
 * `_offset` rows on its own, so page 2 lost rows from one table and repeated
 * nothing it should have shown. These tests page through two tables and
 * assert every row arrives exactly once, in order.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Page 2 of a sequential cross-table search continues page 1.
 */
class MagicMapperSequentialPagingTest extends TestCase {

	/**
	 * Rows per table, in each table's own order.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ROWS = [
		'zaak' => ['z1', 'z2', 'z3'],
		'taak' => ['t1', 't2', 't3', 't4'],
	];

	/**
	 * A mapper whose per-table search pages fixed rows like the database would.
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
			$register = new Register();
			$register->setId(7);
			$pairs[] = ['register' => $register, 'schema' => $schema];
		}

		return $pairs;
	}//end pairs()

	/**
	 * One page, as uuids.
	 *
	 * @param int $offset The offset.
	 * @param int $limit  The page size.
	 *
	 * @return array<int, string|null> The uuids.
	 */
	private function page(int $offset, int $limit): array {
		$results = $this->mapper()->searchAcrossMultipleTables(
			query: ['_aggregations' => [], '_offset' => $offset, '_limit' => $limit],
			registerSchemaPairs: $this->pairs()
		);

		return array_map(static fn (ObjectEntity $object): ?string => $object->getUuid(), $results);
	}//end page()

	/**
	 * Page 2 starts where page 1 ended.
	 *
	 * @return void
	 */
	public function testPageTwoContinuesPageOne(): void {
		$this->assertSame(['t1', 't2'], $this->page(offset: 0, limit: 2));
		$this->assertSame(['t3', 't4'], $this->page(offset: 2, limit: 2));
		$this->assertSame(['z1', 'z2'], $this->page(offset: 4, limit: 2));
	}//end testPageTwoContinuesPageOne()

	/**
	 * Walking every page returns every row once: no gaps, no repeats.
	 *
	 * @return void
	 */
	public function testEveryRowArrivesExactlyOnce(): void {
		$seen = [];
		for ($offset = 0; $offset < 10; $offset += 3) {
			$page = $this->page(offset: $offset, limit: 3);
			$this->assertLessThanOrEqual(3, count($page), 'a page is never longer than its limit');
			$seen = array_merge($seen, $page);
		}

		// No `_order`: the merged rows are ordered by uuid, as the UNION path orders them.
		$this->assertSame(['t1', 't2', 't3', 't4', 'z1', 'z2', 'z3'], $seen);
	}//end testEveryRowArrivesExactlyOnce()
}//end class
