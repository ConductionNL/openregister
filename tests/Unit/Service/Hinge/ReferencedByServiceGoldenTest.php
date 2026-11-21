<?php

/**
 * Characterisation tests for ReferencedByService::readGroup.
 *
 * Pins every branch of reading one schema group — an unresolvable table, a
 * failing query, an empty or access-filtered group, the rows that are skipped
 * and the paging that is clamped — together with the exact query the caller's
 * access flag reaches, so a refactor of the group read cannot change what a
 * caller is allowed or refused to see.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Hinge\ReferencedByService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ReferencedByServiceGoldenTest extends TestCase {

	/**
	 * Every count call: the filters and the schema id it was asked for.
	 *
	 * @var array<int, array>
	 */
	private array $counts = [];

	/**
	 * Every find call: its arguments.
	 *
	 * @var array<int, array>
	 */
	private array $finds = [];

	/**
	 * Debug messages the service logged.
	 *
	 * @var array<int, array>
	 */
	private array $logged = [];

	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug('reg'.$id);
		$register->setTitle('Reg '.$id);
		return $register;
	}

	private function schema(int $id, ?array $configuration = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid('schema-uuid-'.$id);
		$schema->setSlug('slug'.$id);
		$schema->setTitle('Schema '.$id);
		if ($configuration !== null) {
			$schema->setConfiguration($configuration);
		}

		return $schema;
	}

	private function object(string $uuid, ?string $name = null, mixed $data = [], bool $updated = true): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		if (is_array($data) === true) {
			$object->setObject($data);
		}

		if ($updated === true) {
			$object->setUpdated(new DateTime('2026-09-14T10:00:00+00:00'));
		}

		return $object;
	}

	/**
	 * Build the service over doubles.
	 *
	 * @param array    $tables    Table descriptors.
	 * @param array    $schemas   Schema id => Schema (missing id throws).
	 * @param callable $countFn   fn(array $filters, Schema $schema): int|throws.
	 * @param callable $rowsFn    fn(array $filters, Schema $schema): array|throws.
	 * @param array    $registers Register id => Register (missing id throws).
	 *
	 * @return ReferencedByService The service.
	 */
	private function service(array $tables, array $schemas, callable $countFn, callable $rowsFn, ?array $registers = null): ReferencedByService {
		$registers = ($registers ?? [1 => $this->register(1)]);
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getExistingRegisterSchemaTables')->willReturn($tables);
		$magicMapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query, Register $register, Schema $schema) use ($countFn): int {
				$this->counts[] = ['filters' => $query, 'schema' => $schema->getId(), 'register' => $register->getId()];
				return $countFn($query, $schema);
			}
		);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (RegisterSchema $table, ListQuery $listing) use ($rowsFn): array {
				[$register, $schema, $limit, $offset, $filters, $sort] = [$table->register, $table->schema, $listing->limitOrNull(), $listing->offset, ($listing->filters === [] ? null : $listing->filters), $listing->sort];
				$this->finds[] = [
					'register' => $register->getId(),
					'schema' => $schema->getId(),
					'limit' => $limit,
					'offset' => $offset,
					'filters' => $filters,
					'sort' => $sort,
				];
				return $rowsFn($filters, $schema);
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($id) use ($registers): Register {
				if (isset($registers[(int)$id]) === false) {
					throw new RuntimeException('register gone');
				}

				return $registers[(int)$id];
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id) use ($schemas): Schema {
				if (isset($schemas[(int)$id]) === false) {
					throw new RuntimeException('schema gone');
				}

				return $schemas[(int)$id];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function ($message, array $context = []): void {
				unset($context['line']);
				unset($context['file']);
				$this->logged[] = [$message, $context];
			}
		);

		return new ReferencedByService(
			catalog: new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $magicMapper),
			logger: $logger
		);
	}

	public function testAnUnresolvableTableIsSkippedAndLoggedAndTheRestStillRead(): void {
		$tables = [
			['registerId' => 1, 'schemaId' => 99, 'tableName' => 't99'],
			['registerId' => 7, 'schemaId' => 10],
			['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10'],
		];
		$service = $this->service(
			$tables,
			[10 => $this->schema(10)],
			static fn (): int => 1,
			fn (): array => [$this->object('uuid-r', 'R')]
		);

		$result = $service->getReferencingGroups($this->object('uuid-target'));

		$this->assertSame(1, $result['total']);
		$this->assertCount(1, $result['groups']);
		$this->assertSame(
			[
				['[ReferencedByService] Could not resolve register/schema for a table', ['table' => 't99', 'error' => 'schema gone']],
				['[ReferencedByService] Could not resolve register/schema for a table', ['table' => 'unknown', 'error' => 'register gone']],
			],
			$this->logged
		);
		$this->assertCount(1, $this->counts);
	}

	public function testAFailingCountOrFindDropsOnlyThatGroup(): void {
		$tables = [
			['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10'],
			['registerId' => 1, 'schemaId' => 11, 'tableName' => 't11'],
			['registerId' => 1, 'schemaId' => 12],
		];
		$service = $this->service(
			$tables,
			[10 => $this->schema(10), 11 => $this->schema(11), 12 => $this->schema(12)],
			static function (array $filters, Schema $schema): int {
				if ($schema->getId() === 10) {
					throw new RuntimeException('count broke');
				}

				return 2;
			},
			function (array $filters, Schema $schema): array {
				if ($schema->getId() === 11) {
					throw new RuntimeException('find broke');
				}

				return [$this->object('uuid-a', 'A'), $this->object('uuid-b', 'B')];
			}
		);

		$result = $service->getReferencingGroups($this->object('uuid-target'));

		$this->assertSame(2, $result['total']);
		$this->assertSame(['slug12'], array_column(array_column($result['groups'], 'schema'), 'slug'));
		$this->assertSame(
			[
				['[ReferencedByService] Reverse lookup failed for a table', ['table' => 't10', 'error' => 'count broke']],
				['[ReferencedByService] Reverse lookup failed for a table', ['table' => 't11', 'error' => 'find broke']],
			],
			$this->logged
		);
	}

	public function testAGroupTheCallerMayNotReadIsRefusedAndOneTheyMayIsAllowed(): void {
		// The doubled storage plays the SQL access filter: with `_rbac` on,
		// nothing in schema 10 is readable; with it off, everything is.
		$tables = [['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10']];
		$countFn = static function (array $filters): int {
			if ($filters['_rbac'] === true) {
				return 0;
			}

			return 1;
		};
		$rowsFn = function (array $filters): array {
			if ($filters['_rbac'] === true) {
				return [];
			}

			return [$this->object('uuid-secret', 'Secret')];
		};

		$refused = $this->service($tables, [10 => $this->schema(10)], $countFn, $rowsFn)
			->getReferencingGroups($this->object('uuid-target'));
		$this->assertSame(['object' => $refused['object'], 'groups' => [], 'total' => 0], $refused);
		$this->assertSame([], $this->finds, 'A refused group must not load its rows.');
		$this->assertSame(
			[['filters' => ['_relations_contains' => 'uuid-target', '_rbac' => true], 'schema' => 10, 'register' => 1]],
			$this->counts
		);

		$this->counts = [];
		$allowed = $this->service($tables, [10 => $this->schema(10)], $countFn, $rowsFn)
			->getReferencingGroups($this->object('uuid-target'), [], false);
		$this->assertSame(1, $allowed['total']);
		$this->assertSame('Secret', $allowed['groups'][0]['results'][0]['title']);
		$this->assertSame(
			[
				[
					'register' => 1,
					'schema' => 10,
					'limit' => 10,
					'offset' => 0,
					'filters' => ['_relations_contains' => 'uuid-target', '_rbac' => false],
					'sort' => ['_updated' => 'DESC'],
				],
			],
			$this->finds
		);
	}

	public function testTheGroupShapeIsPinned(): void {
		$tables = [['registerId' => 1, 'schemaId' => 10, 'tableName' => 't10']];
		$schema = $this->schema(10, ['x-openregister-lifecycle' => ['property' => 'fase']]);
		$rows = [
			$this->object('uuid-1', 'One', ['fase' => 3]),
			'not-an-entity',
			$this->object('uuid-target', 'Self'),
			$this->object('uuid-2', null, ['fase' => ['nested']], false),
			$this->object('uuid-3', 'Three', ['other' => 'x']),
		];
		$service = $this->service($tables, [10 => $schema], static fn (): int => 5, static fn (): array => $rows);

		$result = $service->getReferencingGroups($this->object('uuid-target'));

		$this->assertSame(
			[
				'register' => ['id' => '1', 'slug' => 'reg1', 'title' => 'Reg 1'],
				'schema' => ['id' => '10', 'slug' => 'slug10', 'title' => 'Schema 10'],
				'total' => 5,
				'limit' => 10,
				'offset' => 0,
				'results' => [
					['id' => 'uuid-1', 'title' => 'One', 'status' => '3', 'updated' => '2026-09-14T10:00:00+00:00'],
					['id' => 'uuid-2', 'title' => null, 'status' => null, 'updated' => null],
					['id' => 'uuid-3', 'title' => 'Three', 'status' => null, 'updated' => '2026-09-14T10:00:00+00:00'],
				],
			],
			$result['groups'][0]
		);
		$this->assertSame(5, $result['total']);
	}

	public function testAnEmptyLifecycleFieldReportsNoStatus(): void {
		$tables = [['registerId' => 1, 'schemaId' => 10]];
		$schema = $this->schema(10, ['x-openregister-lifecycle' => ['field' => '', 'property' => 'status']]);
		$service = $this->service(
			$tables,
			[10 => $schema],
			static fn (): int => 1,
			fn (): array => [$this->object('uuid-1', 'One', ['status' => 'open'])]
		);

		$result = $service->getReferencingGroups($this->object('uuid-target'));

		$this->assertNull($result['groups'][0]['results'][0]['status']);
	}

	/**
	 * @return array<string, array{0: array, 1: int, 2: int}>
	 */
	public static function pagingCases(): array {
		return [
			'defaults' => [[], 10, 0],
			'non-numeric limit' => [['_limit' => 'many'], 10, 0],
			'zero limit' => [['_limit' => 0], 10, 0],
			'negative limit' => [['_limit' => '-3'], 10, 0],
			'too large limit' => [['_limit' => 500], 100, 0],
			'numeric string' => [['_limit' => '25', '_offset' => '4'], 25, 4],
			'negative offset' => [['_offset' => -9], 10, 0],
		];
	}

	#[DataProvider('pagingCases')]
	public function testPagingIsClamped(array $query, int $limit, int $offset): void {
		$tables = [['registerId' => 1, 'schemaId' => 10]];
		$service = $this->service($tables, [10 => $this->schema(10)], static fn (): int => 1, static fn (): array => []);

		$result = $service->getReferencingGroups($this->object('uuid-target'), $query);

		$this->assertSame($limit, $this->finds[0]['limit']);
		$this->assertSame($offset, $this->finds[0]['offset']);
		$this->assertSame($limit, $result['groups'][0]['limit']);
		$this->assertSame($offset, $result['groups'][0]['offset']);
		$this->assertSame([], $result['groups'][0]['results']);
	}

	public function testOnlySchemaMatchesByIdOrUuidAndSkipsOthersBeforeQuerying(): void {
		$tables = [
			['registerId' => 1, 'schemaId' => 10],
			['registerId' => 1, 'schemaId' => 11],
			['registerId' => 1, 'schemaId' => 12],
		];
		$schemas = [10 => $this->schema(10), 11 => $this->schema(11), 12 => $this->schema(12)];

		$this->service($tables, $schemas, static fn (): int => 1, static fn (): array => [])
			->getReferencingGroups($this->object('uuid-target'), ['_schema' => '11']);
		$this->assertSame([11], array_column($this->counts, 'schema'));

		$this->counts = [];
		$this->service($tables, $schemas, static fn (): int => 1, static fn (): array => [])
			->getReferencingGroups($this->object('uuid-target'), ['_schema' => 'schema-uuid-12']);
		$this->assertSame([12], array_column($this->counts, 'schema'));

		$this->counts = [];
		$none = $this->service($tables, $schemas, static fn (): int => 1, static fn (): array => [])
			->getReferencingGroups($this->object('uuid-target'), ['_schema' => 'nope']);
		$this->assertSame([], $this->counts);
		$this->assertSame([], $none['groups']);
	}

	public function testGroupsAreOrderedByTotalDescending(): void {
		$tables = [
			['registerId' => 1, 'schemaId' => 10],
			['registerId' => 1, 'schemaId' => 11],
			['registerId' => 1, 'schemaId' => 12],
		];
		$totals = [10 => 1, 11 => 7, 12 => 3];
		$service = $this->service(
			$tables,
			[10 => $this->schema(10), 11 => $this->schema(11), 12 => $this->schema(12)],
			static fn (array $filters, Schema $schema): int => $totals[$schema->getId()],
			static fn (): array => []
		);

		$result = $service->getReferencingGroups($this->object('uuid-target'));

		$this->assertSame([7, 3, 1], array_column($result['groups'], 'total'));
		$this->assertSame(11, $result['total']);
	}
}
