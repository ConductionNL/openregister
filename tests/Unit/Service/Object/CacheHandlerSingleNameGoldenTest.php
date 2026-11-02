<?php

/**
 * CacheHandler::getSingleObjectName() reads the caches, looks up and discloses one name exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\IAppContainer;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IMemcache;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Golden record of getSingleObjectName() over every branch: the memory and distributed cache
 * reads, the organisation lookup that takes priority, the unscoped object lookup with the
 * identifier it is asked for, the names written back, the read-path questions and the answer.
 * The read path answers only for the session user, and the organisation scope is the session
 * user's own; another session user is a scenario of its own. Set OR_GOLDEN_RECORD=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\CacheHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class CacheHandlerSingleNameGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/cache-handler-single-name-golden.json';

	private const ORG_A = 'aaaaaaaa-0000-0000-0000-00000000000a';

	private const ORG_B = 'bbbbbbbb-0000-0000-0000-00000000000b';

	private const U1 = '11111111-1111-1111-1111-111111111111';

	private const U2 = '22222222-2222-2222-2222-222222222222';

	/** @var array<int, mixed> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $distributed = [];

	/** @var array<int, string> */
	private array $distributedFails = [];

	/** @var string */
	private string $uid = 'alice';

	/** @var array<string, array<int, string>> */
	private array $readableByUser = [];

	/** @var bool */
	private bool $oracleThrows = false;

	/** @var bool */
	private bool $idlessRegister = false;

	/** @var bool */
	private bool $noDistributed = false;

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$u1 = ['uuid' => self::U1, 'name' => 'One', 'org' => self::ORG_A, 'register' => '1', 'schema' => '2', 'id' => 101, 'slug' => 'one'];
		$u2 = ['uuid' => self::U2, 'name' => null, 'org' => self::ORG_B, 'register' => '1', 'schema' => '3', 'id' => 102, 'slug' => 'two'];
		$sourceless = ['uuid' => self::U1, 'name' => 'One', 'org' => self::ORG_A, 'register' => null, 'schema' => '2', 'id' => 101, 'slug' => null];
		$fromResult = ['uuid' => self::U1, 'name' => 'One', 'org' => self::ORG_A, 'register' => null, 'schema' => null, 'id' => 101, 'slug' => null, 'resultRegister' => 1, 'resultSchema' => 2];
		$readable = ['alice' => [self::U1, self::U2]];
		$memory = [self::U1 => ['Cached', self::ORG_A, '1:2']];

		$scenarios = [
			'memory hit'                  => ['id' => self::U1, 'memory' => $memory, 'readable' => $readable],
			'memory hit, not readable'    => ['id' => self::U1, 'memory' => $memory, 'readable' => ['alice' => []], 'objects' => [$u1]],
			'memory hit without source'   => ['id' => self::U1, 'memory' => [self::U1 => ['Cached', self::ORG_A, null]], 'objects' => [$u1], 'readable' => $readable],
			'memory hit, numeric key'     => ['id' => 101, 'memory' => ['101' => ['Cached', self::ORG_A, '1:2']], 'objects' => [$u1], 'readable' => $readable],
			'memory hit, slug key'        => ['id' => 'one', 'memory' => ['one' => ['Cached', self::ORG_A, '1:2']], 'objects' => [$u1], 'readable' => $readable],
			'distributed hit'             => ['id' => self::U1, 'distributed' => ['name_' . self::U1 => ['n' => 'Far', 'o' => self::ORG_A, 's' => '1:2']], 'readable' => $readable],
			'distributed hit, hidden'     => ['id' => self::U1, 'distributed' => ['name_' . self::U1 => ['n' => 'Far', 'o' => self::ORG_A, 's' => '1:2']], 'readable' => ['alice' => []], 'objects' => [$u1]],
			'distributed bad envelope'    => ['id' => self::U1, 'distributed' => ['name_' . self::U1 => ['n' => 'Far']], 'objects' => [$u1], 'readable' => $readable],
			'distributed read throws'     => ['id' => self::U1, 'distributedFails' => ['name_' . self::U1], 'objects' => [$u1], 'readable' => $readable],
			'no distributed cache'        => ['id' => self::U1, 'noDistributed' => true, 'objects' => [$u1], 'readable' => $readable],
			'organisation in scope'       => ['id' => self::ORG_A, 'organisations' => [[self::ORG_A, 'Org A']], 'readable' => $readable],
			'organisation out of scope'   => ['id' => self::ORG_B, 'organisations' => [[self::ORG_B, 'Org B']], 'readable' => $readable],
			'organisation without name'   => ['id' => self::ORG_A, 'organisations' => [[self::ORG_A, null]], 'readable' => $readable],
			'organisation lookup throws'  => ['id' => self::U1, 'organisationsThrow' => true, 'objects' => [$u1], 'readable' => $readable],
			'object found'                => ['id' => self::U1, 'objects' => [$u1], 'readable' => $readable],
			'object not readable'         => ['id' => self::U1, 'objects' => [$u1], 'readable' => ['alice' => []]],
			'object found by slug'        => ['id' => 'one', 'objects' => [$u1], 'readable' => $readable],
			'object found by id'          => ['id' => 101, 'objects' => [$u1], 'readable' => $readable],
			'object found by numeric string' => ['id' => '101', 'objects' => [$u1], 'readable' => $readable],
			'nameless object'             => ['id' => self::U2, 'objects' => [$u2], 'readable' => $readable],
			'object without source'       => ['id' => self::U1, 'objects' => [$sourceless], 'readable' => $readable],
			'source from the result'      => ['id' => self::U1, 'objects' => [$fromResult], 'readable' => $readable],
			'nothing found'               => ['id' => 'gone', 'readable' => $readable],
			'object lookup throws'        => ['id' => self::U1, 'objectsThrow' => true, 'readable' => $readable],
			'read path throws'            => ['id' => self::U1, 'objects' => [$u1], 'readable' => $readable, 'oracleThrows' => true],
			'another session user'        => ['id' => self::U1, 'objects' => [$u1], 'readable' => $readable, 'uid' => 'mallory'],
			'organisation as another user' => ['id' => self::ORG_A, 'organisations' => [[self::ORG_A, 'Org A']], 'readable' => $readable, 'uid' => 'mallory'],
			'second call hits memory'     => ['id' => self::U1, 'objects' => [$u1], 'readable' => $readable, 'again' => true],
			'second call as another user' => ['id' => self::U1, 'objects' => [$u1], 'readable' => $readable, 'againAs' => 'mallory'],
			'by slug, then by uuid'       => ['id' => 'one', 'objects' => [$u1], 'readable' => $readable, 'thenAsk' => self::U1],
			'distributed hit, then again' => ['id' => self::U1, 'distributed' => ['name_' . self::U1 => ['n' => 'Far', 'o' => self::ORG_A, 's' => '1:2']], 'readable' => $readable, 'again' => true],
			'organisation, then as another user' => ['id' => self::ORG_A, 'organisations' => [[self::ORG_A, 'Org A']], 'readable' => $readable, 'againAs' => 'mallory'],
			'idless register'             => ['id' => self::U1, 'objects' => [$u1], 'readable' => $readable, 'idlessRegister' => true],
		];

		$actual = [];
		foreach ($scenarios as $name => $scenario) {
			$actual[$name] = $this->scenario(scenario: $scenario);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run getSingleObjectName() on a fresh handler and record what happened.
	 *
	 * @param array<string, mixed> $scenario The world and the request.
	 *
	 * @return array<string, mixed>
	 */
	private function scenario(array $scenario): array {
		$this->calls = [];
		$this->distributed = $scenario['distributed'] ?? [];
		$this->distributedFails = $scenario['distributedFails'] ?? [];
		$this->uid = $scenario['uid'] ?? 'alice';
		$this->readableByUser = $scenario['readable'] ?? [];
		$this->oracleThrows = $scenario['oracleThrows'] ?? false;
		$this->idlessRegister = $scenario['idlessRegister'] ?? false;
		$this->noDistributed = $scenario['noDistributed'] ?? false;

		$handler = $this->handler(
			organisations: ($scenario['organisations'] ?? []),
			objects: ($scenario['objects'] ?? []),
			rows: [],
			organisationsThrow: ($scenario['organisationsThrow'] ?? false),
			objectsThrow: ($scenario['objectsThrow'] ?? false)
		);
		foreach (($scenario['memory'] ?? []) as $key => [$name, $organisation, $source]) {
			$handler->setObjectName(identifier: $key, name: $name, organisation: $organisation, source: $source);
		}

		$this->calls[] = ['setup done'];
		$result = ['answer' => $handler->getSingleObjectName($scenario['id'])];
		if (($scenario['again'] ?? false) === true) {
			$this->calls[] = ['again'];
			$result['again'] = $handler->getSingleObjectName($scenario['id']);
		}

		if (isset($scenario['againAs']) === true) {
			$this->uid = $scenario['againAs'];
			$this->calls[] = ['now as' => $this->uid];
			$result['again'] = $handler->getSingleObjectName($scenario['id']);
		}

		if (isset($scenario['thenAsk']) === true) {
			$this->calls[] = ['then ask' => $scenario['thenAsk']];
			$result['then'] = $handler->getSingleObjectName($scenario['thenAsk']);
		}

		$stats = (new ReflectionProperty(CacheHandler::class, 'stats'))->getValue($handler);

		return [
			'result' => $result,
			'calls'  => $this->calls,
			'stats'  => ['hits' => $stats['name_hits'], 'misses' => $stats['name_misses']],
		];
	}//end scenario()

	/**
	 * A handler over recording doubles.
	 *
	 * @param array<int, array{0: string, 1: ?string}>     $organisations      Organisations the mapper finds.
	 * @param array<int, array<string, mixed>>             $objects            Objects the magic mapper finds.
	 * @param array<string, array<int, array<int, mixed>>> $rows               Magic-table rows by table name.
	 * @param bool                                         $organisationsThrow Whether the organisation load throws.
	 * @param bool                                         $objectsThrow       Whether the object load throws.
	 *
	 * @return CacheHandler
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	private function handler(array $organisations, array $objects, array $rows, bool $organisationsThrow, bool $objectsThrow): CacheHandler {
		$names = $this->createMock(IMemcache::class);
		$names->method('get')->willReturnCallback(
			function (string $key) {
				$this->calls[] = ['distributed.get' => $key];
				if (in_array($key, $this->distributedFails, true) === true) {
					throw new Exception('cache read failed');
				}

				return ($this->distributed[$key] ?? null);
			}
		);
		$names->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->calls[] = ['distributed.set' => $key, 'value' => $value, 'ttl' => $ttl];
				$this->distributed[$key] = $value;
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			function (string $prefix) use ($names) {
				if ($this->noDistributed === true) {
					throw new Exception('no distributed cache');
				}

				return $prefix === 'openregister_object_names' ? $names : $this->createMock(IMemcache::class);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		$organisationMapper = $this->createMock(OrganisationMapper::class);
		$organisationMapper->method('getActiveOrganisationWithFallback')->willReturnCallback(
			function (string $uid): ?string {
				$this->calls[] = ['activeOrganisationFor' => $uid];
				return $uid === 'alice' ? self::ORG_A : null;
			}
		);
		$organisationMapper->method('getOrganisationHierarchy')->willReturnCallback(fn (string $uuid): array => [$uuid]);
		$organisationMapper->method('findByUuid')->willReturnCallback(
			function (string $uuid) use ($organisations, $organisationsThrow): Organisation {
				$this->calls[] = ['organisation.findByUuid' => $uuid];
				if ($organisationsThrow === true) {
					throw new \RuntimeException('organisation table down');
				}

				foreach ($organisations as [$orgUuid, $name]) {
					if ($orgUuid === $uuid) {
						$organisation = new Organisation();
						$organisation->setUuid($orgUuid);
						$organisation->setName($name);
						return $organisation;
					}
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('no organisation');
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findAcrossAllSources')->willReturnCallback(
			function (...$args) use ($objects, $objectsThrow): array {
				$this->calls[] = ['objects.findAcrossAllSources' => $args];
				if ($objectsThrow === true) {
					throw new Exception('magic tables down');
				}

				foreach ($objects as $spec) {
					$keys = array_map('strval', array_filter([$spec['uuid'], $spec['id'], $spec['slug']], fn ($key): bool => $key !== null));
					if (in_array((string) $args[0], $keys, true) === false) {
						continue;
					}

					$object = new ObjectEntity();
					$object->setId($spec['id']);
					$object->setUuid($spec['uuid']);
					$object->setName($spec['name']);
					$object->setOrganisation($spec['org']);
					$object->setRegister($spec['register']);
					$object->setSchema($spec['schema']);
					$found = ['object' => $object];
					if (isset($spec['resultRegister']) === true) {
						$register = new Register();
						$register->setId($spec['resultRegister']);
						$schema = new Schema();
						$schema->setId($spec['resultSchema']);
						$found += ['register' => $register, 'schema' => $schema];
					}

					return $found;
				}

				return [];
			}
		);
		$objectMapper->method('filterReadableUuids')->willReturnCallback(
			function (int $registerId, int $schemaId, array $uuids): array {
				$this->calls[] = ['readable?' => $registerId . ':' . $schemaId, 'uuids' => array_values($uuids), 'asUser' => $this->uid];
				if ($this->oracleThrows === true) {
					throw new \RuntimeException('read path unavailable');
				}

				return array_values(array_intersect($uuids, ($this->readableByUser[$this->uid] ?? [])));
			}
		);
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			fn (string $class) => $class === MagicMapper::class ? $objectMapper : $this->createMock($class)
		);

		$register = new Register();
		if ($this->idlessRegister === false) {
			$register->setId(1);
		}

		$register->setSchemas([2, 3]);
		$register->setConfiguration(['schemas' => ['schema-2' => ['magicMapping' => true], '3' => ['magicMapping' => true]]]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findMultipleOptimized')->willReturnCallback(
			function (array $ids): array {
				$map = [];
				foreach ($ids as $id) {
					$schema = new Schema();
					$schema->setId($id);
					$schema->setSlug('schema-' . $id);
					$map[$id] = $schema;
				}

				return $map;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($rows): IPreparedStatement {
				preg_match('/FROM\s+(\S+)/', $sql, $table);
				preg_match('/,\s*(\S+) as name_value/', $sql, $column);
				$this->calls[] = ['query' => $table[1], 'column' => $column[1]];
				$bound = [];
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('bindValue')->willReturnCallback(
					function ($param, $value) use (&$bound): bool {
						$bound[$param] = $value;
						return true;
					}
				);
				$tableRows = ($rows[$table[1]] ?? []);
				$statement->method('execute')->willReturnCallback(
					function () use (&$bound): IResult {
						$this->calls[] = ['bound' => $bound];
						return $this->createMock(IResult::class);
					}
				);
				$queue = [];
				foreach ($tableRows as [$uuid, $name, $organisation]) {
					$queue[] = ['_uuid' => $uuid, 'name_value' => $name, '_organisation' => $organisation];
				}

				$queue[] = false;
				$statement->method('fetch')->willReturnOnConsecutiveCalls(...$queue);
				return $statement;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					if (str_contains((string) $message, 'OBJECT NAME CACHED') === true) {
						return;
					}

					unset($context['file'], $context['line']);
					$this->calls[] = ['log' => $level, 'message' => (string) $message, 'context' => $context];
				}
			);
		}

		return new CacheHandler(
			organisationMapper: $organisationMapper,
			logger: $logger,
			cacheFactory: $cacheFactory,
			userSession: $session,
			container: $container,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			db: $db,
			groupManager: $groupManager
		);
	}//end handler()
}//end class
