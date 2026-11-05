<?php

/**
 * OpenRegister - the route MagicMapper::searchObjectsPaginated() takes and what it returns.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;

/**
 * Pins, for each way into the method, the calls it makes with their arguments and
 * order, the log lines, and the response with timings recorded by key only.
 *
 * Expected values live in `fixtures/search-objects-paginated.php`, one entry per case.
 */
class MagicMapperSearchObjectsPaginatedTest extends TestCase {

	/**
	 * The calls made, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every case: the call arguments and, optionally, failures and stored objects.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cases(): array {
		return [
			'no context'                         => ['search' => []],
			'register only'                      => ['search' => ['register' => 1]],
			'schema only'                        => ['search' => ['schema' => 2]],
			'single from @self'                  => ['search' => ['@self' => ['register' => 1, 'schema' => 2], '_limit' => 5], 'count' => ['x' => 1]],
			'single from underscore keys'        => ['search' => ['_register' => '1', '_schema' => '2']],
			'single from plain keys'             => ['search' => ['register' => 1, 'schema' => 2], 'rbac' => false, 'multitenancy' => false],
			'@self wins over underscore'         => ['search' => ['@self' => ['register' => 1, 'schema' => 2], '_register' => 9, '_schema' => 9]],
			'single register lookup fails'       => ['search' => ['register' => 404, 'schema' => 2]],
			'single schema lookup fails'         => ['search' => ['register' => 1, 'schema' => 404]],
			'single lookup fails with search'    => ['search' => ['register' => 404, 'schema' => 2, '_search' => 'x', '_ids' => ['u1']]],
			'schema list in @self'               => ['search' => ['@self' => ['schema' => [2, 3]], 'register' => 1]],
			'empty schema list'                  => ['search' => ['@self' => ['schema' => []], 'register' => 1]],
			'multi with registers'               => ['search' => ['_schemas' => [2, 3], '_registers' => ['1', '5']], 'ids' => ['a'], 'uses' => 'u'],
			'multi with one register'            => ['search' => ['@self' => ['schemas' => ['2']], 'register' => '1']],
			'multi registers win over register'  => ['search' => ['_schemas' => [2], 'register' => 1, '_registers' => [5]]],
			'multi empty registers list'         => ['search' => ['_schemas' => [2], 'register' => 1, '_registers' => []]],
			'multi without registers'            => ['search' => ['_schemas' => [2, 3]], 'activeOrg' => 'org-1'],
			'multi schemas not a list'           => ['search' => ['_schemas' => '2', 'register' => 1, 'schema' => 2]],
			'multi but a schema is named'        => ['search' => ['_schemas' => [2, 3], 'schema' => 2, 'register' => 1]],
			'ids'                                => ['search' => ['_ids' => ['u1', 'u2']], 'stored' => ['u1' => [1, 2], 'u2' => [5, 3]]],
			'ids with failed single lookup'      => ['search' => ['_ids' => ['u1'], 'register' => 404, 'schema' => 2], 'stored' => ['u1' => [1, 2]]],
			'empty ids'                          => ['search' => ['_ids' => []]],
			'relations'                          => ['search' => ['_relations_contains' => 'rel-1'], 'stored' => ['r1' => [1, 2]], 'rbac' => false],
			'relations with a register'          => ['search' => ['_relations_contains' => 'rel-1', 'register' => 1]],
			'relations empty'                    => ['search' => ['_relations_contains' => '']],
			'relations not a string'             => ['search' => ['_relations_contains' => ['rel-1']]],
			'text search'                        => ['search' => ['_search' => 'zaak', '_limit' => 3], 'count' => ['_search' => 'zaak']],
			'text search no pairs'               => ['search' => ['_search' => 'zaak'], 'pairs' => []],
			'text search blank'                  => ['search' => ['_search' => '   ']],
			'text search with a schema'          => ['search' => ['_search' => 'zaak', 'schema' => 2]],
			'ids before relations before search' => ['search' => ['_ids' => ['u1'], '_relations_contains' => 'r', '_search' => 's']],
		];
	}//end cases()

	/**
	 * Every case paired with its expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/search-objects-paginated.php';
		$pairs    = [];
		foreach (self::cases() as $name => $case) {
			$pairs[$name] = [$case, $expected[$name] ?? ['missing expectation' => $name]];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The search takes the recorded route and answers the recorded response.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded calls and response
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testSearchTakesTheRecordedRoute(array $case, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->runCase(case: $case));
	}//end testSearchTakesTheRecordedRoute()

	/**
	 * Every case has a recorded expectation and every expectation a case.
	 *
	 * @return void
	 */
	public function testEveryCaseIsRecorded(): void {
		$expected = require __DIR__.'/fixtures/search-objects-paginated.php';
		$this->assertSame(expected: array_keys(self::cases()), actual: array_keys($expected));
	}//end testEveryCaseIsRecorded()

	/**
	 * Run one case: the calls, the log lines and the response.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array<string, mixed>
	 */
	public function runCase(array $case): array {
		$this->calls = [];
		$logger      = new class extends AbstractLogger {
			/**
			 * The logged levels, messages and context.
			 *
			 * @var list<string>
			 */
			public array $lines = [];

			/**
			 * Record a log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array             $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context=[]): void {
				unset($context['file'], $context['line']);
				$this->lines[] = $level.': '.$message.' '.json_encode($context);
			}//end log()
		};

		$response = $this->mapper(case: $case, logger: $logger)->searchObjectsPaginated(
			searchQuery: $case['search'],
			countQuery: ($case['count'] ?? []),
			_activeOrgUuid: ($case['activeOrg'] ?? null),
			_rbac: ($case['rbac'] ?? true),
			_multitenancy: ($case['multitenancy'] ?? true),
			ids: ($case['ids'] ?? null),
			uses: ($case['uses'] ?? null)
		);

		return ['calls' => $this->calls, 'log' => $logger->lines, 'response' => $this->withoutTimings(value: $response)];
	}//end runCase()

	/**
	 * A mapper whose table-level calls are recorded and answered by the case.
	 *
	 * @param array<string, mixed> $case   The case
	 * @param AbstractLogger       $logger The logger
	 *
	 * @return MagicMapper
	 */
	private function mapper(array $case, AbstractLogger $logger): MagicMapper {
		$mapper = $this->getMockBuilder(className: MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(
				methods: [
					'searchObjectsInRegisterSchemaTable',
					'countObjectsInRegisterSchemaTable',
					'getIgnoredFilters',
					'findMultipleAcrossAllMagicTables',
					'findByRelationAcrossAllMagicTables',
					'searchAcrossMultipleTables',
					'getAllRegisterSchemaPairs',
				]
			)
			->getMock();

		$stored = $this->storedObjects(stored: ($case['stored'] ?? []));
		$mapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			fn (...$args): array => $this->record(name: 'searchTable', args: $args, answer: ['row-'.$args[2]->getId()])
		);
		$mapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			fn (...$args): int => $this->record(name: 'countTable', args: $args, answer: (10 + (int)$args[2]->getId()))
		);
		$mapper->method('getIgnoredFilters')->willReturnCallback(
			fn (): array => $this->record(name: 'getIgnoredFilters', args: [], answer: ['colour'])
		);
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturnCallback(
			fn (...$args): array => $this->record(name: 'findMultiple', args: $args, answer: array_values($stored))
		);
		$mapper->method('findByRelationAcrossAllMagicTables')->willReturnCallback(
			fn (...$args): array => $this->record(name: 'findByRelation', args: $args, answer: array_values($stored))
		);
		$mapper->method('searchAcrossMultipleTables')->willReturnCallback(
			fn (...$args): array => $this->record(name: 'searchAcross', args: $args, answer: ['union-row'])
		);
		$mapper->method('getAllRegisterSchemaPairs')->willReturnCallback(
			fn (): array => $this->record(
				name: 'getAllRegisterSchemaPairs',
				args: [],
				answer: ($case['pairs'] ?? [['registerId' => 1, 'schemaId' => 2], ['registerId' => 1, 'schemaId' => 404]])
			)
		);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no database'));
		$rbac = $this->createMock(originalClassName: MagicRbacHandler::class);
		$rbac->method('isAdmin')->willReturn(true);

		$collaborators = [
			'db'             => $db,
			'logger'         => $logger,
			'registerMapper' => $this->registerMapper(),
			'schemaMapper'   => $this->schemaMapper(),
			'rbacHandler'    => $rbac,
		];
		foreach ($collaborators as $name => $value) {
			(new ReflectionProperty(MagicMapper::class, $name))->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	/**
	 * Record a call and return its answer.
	 *
	 * @param string $name   The call
	 * @param array  $args   Its arguments
	 * @param mixed  $answer The answer
	 *
	 * @return mixed
	 */
	private function record(string $name, array $args, mixed $answer): mixed {
		$this->calls[] = $name.' '.json_encode($this->describe(value: $args));
		return $answer;
	}//end record()

	/**
	 * A value with registers, schemas and objects reduced to their ids.
	 *
	 * @param mixed $value The value
	 *
	 * @return mixed
	 */
	private function describe(mixed $value): mixed {
		if ($value instanceof Register) {
			return 'register:'.$value->getId();
		}

		if ($value instanceof Schema) {
			return 'schema:'.$value->getId();
		}

		if ($value instanceof ObjectEntity) {
			return 'object:'.$value->getUuid();
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->describe(value: $item), $value);
		}

		return $value;
	}//end describe()

	/**
	 * The response with every `*_ms` timing replaced by a marker.
	 *
	 * @param mixed $value The response
	 *
	 * @return mixed
	 */
	private function withoutTimings(mixed $value): mixed {
		if (is_array($value) === false) {
			return $this->describe(value: $value);
		}

		foreach ($value as $key => $item) {
			$value[$key] = $this->withoutTimings(value: $item);
			if (is_string($key) === true && str_ends_with($key, '_ms') === true) {
				$value[$key] = '<ms>';
			}
		}

		return $value;
	}//end withoutTimings()

	/**
	 * Objects stored across tables, keyed by uuid, each in a register and schema.
	 *
	 * @param array<string, array{0: int, 1: int}> $stored Uuid => [register id, schema id]
	 *
	 * @return array<string, ObjectEntity>
	 */
	private function storedObjects(array $stored): array {
		$objects = [];
		foreach ($stored as $uuid => [$registerId, $schemaId]) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setRegister((string)$registerId);
			$object->setSchema((string)$schemaId);
			$objects[$uuid] = $object;
		}

		return $objects;
	}//end storedObjects()

	/**
	 * A register mapper that knows registers 1 and 5 (each holding schemas 2 and 3) and fails on others.
	 *
	 * @return RegisterMapper
	 */
	private function registerMapper(): RegisterMapper {
		$mapper = $this->createMock(originalClassName: RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->calls[] = 'register find '.json_encode($args);
				$id = (int)$args[0];
				if (in_array($id, [1, 5], true) === false) {
					throw new RuntimeException('no register '.$id);
				}

				$register = new Register();
				$register->setId($id);
				$register->setTitle('register '.$id);
				$register->setSchemas([2, 3]);

				return $register;
			}
		);

		return $mapper;
	}//end registerMapper()

	/**
	 * A schema mapper that knows schemas 2 and 3 and fails on others.
	 *
	 * @return SchemaMapper
	 */
	private function schemaMapper(): SchemaMapper {
		$mapper = $this->createMock(originalClassName: SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args): Schema {
				$this->calls[] = 'schema find '.json_encode($args);
				$id = (int)$args[0];
				if (in_array($id, [2, 3], true) === false) {
					throw new RuntimeException('no schema '.$id);
				}

				$schema = new Schema();
				$schema->setId($id);
				$schema->setTitle('schema '.$id);

				return $schema;
			}
		);

		return $mapper;
	}//end schemaMapper()
}//end class
