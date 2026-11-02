<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Golden test for ObjectService::deleteObjects().
 *
 * The service runs with its own private steps (scope resolution, the archival
 * and draft gates, cache invalidation); only the mappers, the permission
 * filter, the delete handler, the cache handler, the session and the logger
 * are scripted. Every call they receive and the returned buckets are compared
 * with fixtures/object-service-delete-objects-golden.json.
 * An empty key is keyed `(empty)`. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class ObjectServiceDeleteObjectsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/object-service-delete-objects-golden.json';

	private const ARCHIVAL = ['x-openregister-archival' => ['retention' => ['default' => 'P10Y']]];

	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$row = static fn (array $extra = []): array => $extra + ['register' => '7', 'schema' => '3'];
		return [
			'nothing requested' => [['request' => []]],
			'one deleted' => [['request' => ['a'], 'rows' => ['a' => $row()]]],
			'several deleted with cascades' => [['request' => ['a', 'b', 'c'], 'rows' => ['a' => $row(), 'b' => $row(['schema' => '4']), 'c' => $row(['register' => '8'])], 'cascade' => ['a' => 2, 'b' => 0, 'c' => 5]]],
			'filtered out' => [['request' => ['a', 'b', 'gone'], 'visible' => ['a'], 'rows' => ['a' => $row(), 'b' => $row()]]],
			'no rbac and no multitenancy' => [['request' => ['a', 'gone'], 'rbac' => false, 'multi' => false, 'rows' => ['a' => $row()], 'visible' => ['a']]],
			'rbac only' => [['request' => ['a'], 'multi' => false, 'rows' => ['a' => $row()]]],
			'not batch resolved' => [['request' => ['a'], 'rows' => ['a' => $row(['batch' => false])]]],
			'not resolvable at all' => [['request' => ['a'], 'rows' => ['a' => $row(['batch' => false, 'findThrows' => true])]]],
			'batch lookup fails' => [['request' => ['a', 'b'], 'batchThrows' => true, 'rows' => ['a' => $row(['batch' => false]), 'b' => $row(['batch' => false])]]],
			'non numeric scope' => [['request' => ['a'], 'rows' => ['a' => $row(['register' => 'reg-slug'])]]],
			'empty schema id' => [['request' => ['a'], 'rows' => ['a' => $row(['schema' => ''])]]],
			'register cannot be loaded' => [['request' => ['a'], 'rows' => ['a' => $row(['register' => '66'])]]],
			'schema cannot be loaded' => [['request' => ['a'], 'rows' => ['a' => $row(['schema' => '99'])]]],
			'archival schema' => [['request' => ['a', 'b'], 'rows' => ['a' => $row(['schema' => '5']), 'b' => $row()]]],
			'own draft on archival schema' => [['request' => ['a'], 'user' => 'alice', 'rows' => ['a' => $row(['schema' => '5', 'draft' => true, 'owner' => 'alice'])]]],
			'someone elses draft' => [['request' => ['a'], 'user' => 'alice', 'rows' => ['a' => $row(['draft' => true, 'owner' => 'bob'])]]],
			'someone elses draft as admin' => [['request' => ['a'], 'user' => 'root', 'rows' => ['a' => $row(['draft' => true, 'owner' => 'bob'])]]],
			'someone elses draft without rbac' => [['request' => ['a'], 'rbac' => false, 'user' => 'alice', 'rows' => ['a' => $row(['draft' => true, 'owner' => 'bob'])]]],
			'handler refuses' => [['request' => ['a', 'b'], 'rows' => ['a' => $row(), 'b' => $row()], 'delete' => ['a' => false]]],
			'restrict' => [['request' => ['a', 'b'], 'rows' => ['a' => $row(), 'b' => $row()], 'delete' => ['a' => 'restrict']]],
			'handler fails' => [['request' => ['a', 'b'], 'rows' => ['a' => $row(), 'b' => $row()], 'delete' => ['b' => 'fail']]],
			'nothing deleted' => [['request' => ['a'], 'rows' => ['a' => $row()], 'delete' => ['a' => false]]],
			'cache invalidation fails' => [['request' => ['a', 'b'], 'rows' => ['a' => $row(), 'b' => $row(['schema' => '4'])], 'cacheThrows' => true]],
			'deleted without scope' => [['request' => ['a'], 'rows' => ['a' => $row(['batch' => false, 'findThrows' => true])], 'unresolvedSchemaOk' => true]],
			'numeric uuid' => [['request' => ['123'], 'rows' => ['123' => $row()], 'visible' => []]],
			'mixed batch' => [[
				'request' => ['a', 'b', 'c', 'd', 'e', 'x'],
				'visible' => ['a', 'b', 'c', 'd', 'e'],
				'rows' => ['a' => $row(), 'b' => $row(['schema' => '5']), 'c' => $row(['batch' => false]), 'd' => $row(['schema' => '4']), 'e' => $row()],
				'delete' => ['d' => 'restrict', 'e' => false],
				'cascade' => ['a' => 1, 'c' => 3],
			]],
			'current scope fallback: not batch resolved' => [['request' => ['a'], 'current' => true, 'rows' => ['a' => $row(['batch' => false])]]],
			'current scope fallback: register unloadable' => [['request' => ['a'], 'current' => true, 'rows' => ['a' => $row(['register' => '66'])]]],
			'current scope fallback: non numeric' => [['request' => ['a'], 'current' => true, 'rows' => ['a' => $row(['register' => 'slug'])]]],
			'current scope: batch resolved' => [['request' => ['a'], 'current' => true, 'rows' => ['a' => $row()]]],
			'null uuid without rbac and multitenancy' => [['request' => ['a', null, 'b'], 'rbac' => false, 'multi' => false, 'rows' => ['a' => $row(), 'b' => $row()]]],
			'float uuid without filters' => [['request' => ['a', 1.5], 'rbac' => false, 'multi' => false, 'rows' => ['a' => $row()]]],
			'bool uuid without filters' => [['request' => [true], 'rbac' => false, 'multi' => false, 'rows' => []]],
			'int uuid without filters' => [['request' => [123], 'rbac' => false, 'multi' => false, 'rows' => ['123' => $row()]]],
			'duplicate uuids' => [['request' => ['a', 'a'], 'rows' => ['a' => $row()], 'visible' => ['a']]],
			'duplicate uuids second refused' => [['request' => ['a', 'a', 'b'], 'rows' => ['a' => $row(), 'b' => $row()], 'delete' => ['b' => false]]],
			'draft from per-uuid find' => [['request' => ['a'], 'user' => 'alice', 'rows' => ['a' => $row(['batch' => false, 'draft' => true, 'owner' => 'bob'])]]],
			'own draft from per-uuid find' => [['request' => ['a'], 'user' => 'alice', 'rows' => ['a' => $row(['batch' => false, 'draft' => true, 'owner' => 'alice', 'schema' => '5'])]]],
			'draft with unresolvable schema' => [['request' => ['a'], 'user' => 'alice', 'rows' => ['a' => $row(['draft' => true, 'owner' => 'bob', 'schema' => '99'])]]],
			'draft no user' => [['request' => ['a'], 'rows' => ['a' => $row(['draft' => true, 'owner' => 'bob'])]]],
			'handler throws error' => [['request' => ['a', 'b'], 'rows' => ['a' => $row(), 'b' => $row()], 'delete' => ['b' => 'error']]],
			'cascade count throws' => [['request' => ['a', 'b'], 'rows' => ['a' => $row(), 'b' => $row()], 'cascadeThrows' => ['a' => true]]],
			'null register id' => [['request' => ['a'], 'rows' => ['a' => $row(['register' => null])]]],
			'restrict then archival then ok' => [['request' => ['a', 'b', 'c'], 'rows' => ['a' => $row(), 'b' => $row(['schema' => '5']), 'c' => $row(['schema' => '5', 'register' => '9'])], 'delete' => ['a' => 'restrict']]],
			'schema cache shared across rows' => [['request' => ['a', 'b', 'c'], 'rows' => ['a' => $row(['batch' => false]), 'b' => $row(), 'c' => $row(['batch' => false, 'schema' => '4'])]]],
			'multitenancy only' => [['request' => ['a', 'z'], 'rbac' => false, 'rows' => ['a' => $row()], 'visible' => ['a']]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testDeleteObjectsMatchesGolden(array $case): void {
		$name = $this->dataName();
		$service = $this->service(case: $case);
		try {
			$actual = ['result' => $service->deleteObjects($case['request'], $case['rbac'] ?? true, $case['multi'] ?? true)];
		} catch (\Throwable $e) {
			$actual = ['threw' => get_class($e), 'message' => $this->describe($e->getMessage())];
		}

		$after = [];
		foreach (['currentRegister', 'currentSchema'] as $property) {
			$after[$property] = $this->describe((new ReflectionClass(ObjectService::class))->getProperty($property)->getValue($service));
		}

		$actual = $this->keyEmpty(value: json_decode(json_encode($actual + ['calls' => $this->calls, 'after' => $after], JSON_PARTIAL_OUTPUT_ON_ERROR), true));

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testDeleteObjectsMatchesGolden()

	/**
	 * Key every empty key as `(empty)`, so the fixture holds no empty JSON keys.
	 *
	 * @param mixed $value A decoded recording.
	 *
	 * @return mixed
	 */
	private function keyEmpty(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$keyed = [];
		foreach ($value as $key => $item) {
			$keyed[($key === '') ? '(empty)' : $key] = $this->keyEmpty(value: $item);
		}

		return $keyed;
	}//end keyEmpty()

	/**
	 * @param array<string, mixed> $case
	 */
	private function service(array $case): ObjectService {
		$rows = [];
		foreach (($case['rows'] ?? []) as $uuid => $spec) {
			$rows[(string)$uuid] = $this->entity(uuid: (string)$uuid, spec: $spec);
		}

		$visible = ($case['visible'] ?? array_map('strval', array_keys($rows)));
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('filterUuidsForPermissions')->willReturnCallback(
			function (array $uuids, bool $_rbac, bool $_multitenancy) use ($visible): array {
				$this->record('permissions.filter', [$uuids, $_rbac, $_multitenancy]);
				return array_values(array_intersect($uuids, $visible));
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturnCallback(
			function (array $uuids, bool $includeDeleted) use ($rows, $case): array {
				$this->record('objectMapper.findMultiple', [$uuids, $includeDeleted]);
				if (($case['batchThrows'] ?? false) === true) {
					throw new Exception('batch failed');
				}

				$found = [];
				foreach ($uuids as $uuid) {
					if (isset($rows[$uuid]) === true && (($case['rows'][$uuid]['batch'] ?? true) === true)) {
						$found[] = $rows[$uuid];
					}
				}

				return $found;
			}
		);
		$objectMapper->method('find')->willReturnCallback(
			function ($identifier, $register = null, $schema = null, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true) use ($rows, $case): ObjectEntity {
				$this->record('objectMapper.find', [$identifier, $register, $schema, $includeDeleted, $_rbac, $_multitenancy]);
				if (is_string($identifier) === false && is_int($identifier) === false) {
					throw new \TypeError('identifier type');
				}

				if (($case['rows'][$identifier]['findThrows'] ?? false) === true || isset($rows[$identifier]) === false) {
					throw new Exception('not found');
				}

				return $rows[$identifier];
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (int|string $id, ...$rest): Schema {
				$this->record('schemaMapper.find', [$id, $rest]);
				if ((int)$id === 99) {
					throw new Exception('schema gone');
				}

				$schema = new Schema();
				$schema->setId((int)$id);
				$schema->setSlug('schema-' . $id);
				$schema->setConfiguration((int)$id === 5 ? self::ARCHIVAL : []);
				return $schema;
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (int|string $id, ...$rest): Register {
				$this->record('registerMapper.find', [$id, $rest]);
				if ((int)$id === 66) {
					throw new Exception('register gone');
				}

				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);

		$lastCascade = 0;
		$cascadeThrows = ($case['cascadeThrows'] ?? []);
		$deleteHandler = $this->createMock(DeleteObject::class);
		$deleteHandler->method('deleteObject')->willReturnCallback(
			function ($register, $schema, string $uuid, ?string $originalObjectId = null, bool $_rbac = true, bool $_multitenancy = true, bool $scoped = false, ?ObjectEntity $preResolved = null, bool $permanent = false) use ($case, &$lastCascade): bool {
				$this->record('deleteHandler.deleteObject', [$register, $schema, $uuid, $originalObjectId, $_rbac, $_multitenancy, $scoped, $preResolved, $permanent]);
				$lastCascade = ($case['cascade'][$uuid] ?? 0);
				$outcome = ($case['delete'][$uuid] ?? true);
				if ($outcome === 'restrict') {
					throw new ReferentialIntegrityException(new DeletionAnalysis(deletable: false, blockers: [['uuid' => 'x'], ['uuid' => 'y']]));
				}

				if ($outcome === 'error') {
					throw new \TypeError('typed boom');
				}

				if ($outcome === 'fail') {
					throw new Exception('transaction rolled back');
				}

				return $outcome;
			}
		);
		$deleteHandler->method('getLastCascadeCount')->willReturnCallback(
			function () use (&$lastCascade, &$cascadeThrows): int {
				$this->record('deleteHandler.getLastCascadeCount', []);
				foreach (array_keys($cascadeThrows) as $failing) {
					unset($cascadeThrows[$failing]);
					throw new Exception('cascade read failed ' . $failing);
				}

				return $lastCascade;
			}
		);

		$cache = $this->createMock(CacheHandler::class);
		$cache->method('invalidateForObjectChange')->willReturnCallback(
			function ($object = null, string $operation = 'unknown', ?int $registerId = null, ?int $schemaId = null) use ($case): void {
				$this->record('cache.invalidate', [$object, $operation, $registerId, $schemaId]);
				if (($case['cacheThrows'] ?? false) === true) {
					throw new Exception('cache down');
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					$this->record('logger.' . $level, [$message, $context]);
				}
			);
		}

		$user = null;
		if (isset($case['user']) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $given): array => $given->getUID() === 'root' ? ['admin'] : ['users']
		);

		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		$inject = static function (string $property, mixed $value) use ($service): void {
			(new ReflectionClass(ObjectService::class))->getProperty($property)->setValue($service, $value);
		};
		$inject('permissionHandler', $permissions);
		$inject('objectMapper', $objectMapper);
		$inject('schemaMapper', $schemaMapper);
		$inject('registerMapper', $registerMapper);
		$inject('deleteHandler', $deleteHandler);
		$inject('cacheHandler', $cache);
		$inject('logger', $logger);
		$inject('userSession', $session);
		$inject('groupManager', $groups);
		if (($case['current'] ?? false) === true) {
			$currentRegister = new Register();
			$currentRegister->setId(1001);
			$currentSchema = new Schema();
			$currentSchema->setId(2002);
			$inject('currentRegister', $currentRegister);
			$inject('currentSchema', $currentSchema);
		}

		return $service;
	}//end service()

	/**
	 * @param array<string, mixed> $spec
	 */
	private function entity(string $uuid, array $spec): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister($spec['register']);
		$entity->setSchema($spec['schema']);
		$entity->setOwner($spec['owner'] ?? 'owner');
		if (($spec['draft'] ?? false) === true) {
			$entity->setStatus(ObjectEntity::STATUS_DRAFT);
		}

		return $entity;
	}//end entity()

	private function record(string $method, array $args): void {
		$this->calls[] = [$method, array_map(fn ($arg) => $this->describe($arg), $args)];
	}//end record()

	private function describe(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return ['entity' => $value->getUuid()];
		}

		if ($value instanceof Schema) {
			return ['schema' => $value->getId()];
		}

		if ($value instanceof Register) {
			return ['register' => $value->getId()];
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->describe($item), $value);
		}

		if (is_string($value) === true) {
			return preg_replace(['/MockObject_(\w+?)_[0-9a-f]{8}/', '/, called in .* on line \d+/'], ['$1', ''], $value);
		}

		return $value;
	}//end describe()
}//end class
