<?php

/**
 * CacheHandler::getMultipleObjectNames() collects, loads and discloses names exactly as recorded.
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
 * Golden record of getMultipleObjectNames() over every branch: the memory and distributed cache
 * reads, the organisation, object and magic-table loads with the identifiers each one is asked
 * for, the names written back, the read-path questions and the disclosed map. The read path
 * answers only for the session user it is asked under, and the organisation scope is the session
 * user's own; a different session user is a scenario of its own. Set OR_GOLDEN_RECORD=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\CacheHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\NameLookupRun
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class CacheHandlerMultipleNamesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/cache-handler-multiple-names-golden.json';

	private const ORG_A = 'aaaaaaaa-0000-0000-0000-00000000000a';

	private const ORG_B = 'bbbbbbbb-0000-0000-0000-00000000000b';

	private const U1 = '11111111-1111-1111-1111-111111111111';

	private const U2 = '22222222-2222-2222-2222-222222222222';

	private const U3 = '33333333-3333-3333-3333-333333333333';

	private const U4 = '44444444-4444-4444-4444-444444444444';

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

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$objects = [
			'u1'      => ['uuid' => self::U1, 'name' => 'One', 'org' => self::ORG_A, 'register' => '1', 'schema' => '2', 'id' => 101, 'slug' => 'one', 'uri' => 'https://x/one'],
			'u2-free' => ['uuid' => self::U2, 'name' => 'Two', 'org' => self::ORG_B, 'register' => '1', 'schema' => '3', 'id' => 102, 'slug' => 'two', 'uri' => null],
			'u3-nameless' => ['uuid' => self::U3, 'name' => null, 'org' => null, 'register' => '1', 'schema' => '2', 'id' => 103, 'slug' => null, 'uri' => null],
			'u4-tableless' => ['uuid' => self::U4, 'name' => 'Four', 'org' => self::ORG_A, 'register' => null, 'schema' => '2', 'id' => 104, 'slug' => null, 'uri' => null],
		];
		$readable = ['alice' => [self::U1, self::U2, self::U3, self::U4]];

		$actual = [
			'empty'                       => $this->scenario(identifiers: []),
			'memory hit'                  => $this->scenario(identifiers: [self::U1], memory: [self::U1 => ['Cached', self::ORG_A, '1:2']], readable: $readable),
			'memory hit without source'   => $this->scenario(identifiers: [self::U1], memory: [self::U1 => ['Cached', self::ORG_A, null]], objects: [$objects['u1']], readable: $readable),
			'memory hit, numeric key'     => $this->scenario(identifiers: ['101'], memory: ['101' => ['Cached', self::ORG_A, '1:2']], readable: $readable),
			'distributed hit'             => $this->scenario(identifiers: [self::U1], distributed: ['name_' . self::U1 => ['n' => 'Far', 'o' => self::ORG_A, 's' => '1:2']], readable: $readable),
			'distributed bad envelope'    => $this->scenario(identifiers: [self::U1], distributed: ['name_' . self::U1 => ['n' => 'Far', 'o' => self::ORG_A]], objects: [$objects['u1']], readable: $readable),
			'distributed read throws'     => $this->scenario(identifiers: [self::U1, self::U2], distributedFails: ['name_' . self::U1], objects: [$objects['u1'], $objects['u2-free']], readable: $readable),
			'organisation in scope'       => $this->scenario(identifiers: [self::ORG_A], organisations: [[self::ORG_A, 'Org A']], readable: $readable),
			'organisation out of scope'   => $this->scenario(identifiers: [self::ORG_B], organisations: [[self::ORG_B, 'Org B']], readable: $readable),
			'organisation without name'   => $this->scenario(identifiers: [self::ORG_A], organisations: [[self::ORG_A, null]], readable: $readable),
			'objects found'               => $this->scenario(identifiers: [self::U1, self::U2, self::U3], objects: [$objects['u1'], $objects['u2-free'], $objects['u3-nameless']], readable: $readable),
			'object not readable'         => $this->scenario(identifiers: [self::U1, self::U2], objects: [$objects['u1'], $objects['u2-free']], readable: ['alice' => [self::U2]]),
			'object found by slug'        => $this->scenario(identifiers: ['one', self::U2], objects: [$objects['u1'], $objects['u2-free']], readable: $readable),
			'object found by id'          => $this->scenario(identifiers: ['101'], objects: [$objects['u1']], readable: $readable),
			'object found by uri'         => $this->scenario(identifiers: ['https://x/one'], objects: [$objects['u1']], readable: $readable),
			'object without table'        => $this->scenario(identifiers: [self::U4], objects: [$objects['u4-tableless']], rows: ['oc_openregister_table_1_2' => [[self::U4, 'Four from table', self::ORG_A]]], readable: $readable),
			'mixed sources'               => $this->scenario(identifiers: [self::ORG_A, 'one', self::U4, self::U3, 'gone'], organisations: [[self::ORG_A, 'Org A']], objects: [$objects['u1'], $objects['u3-nameless']], rows: ['oc_openregister_table_1_2' => [[self::U4, 'Four from table', 7]]], readable: $readable),
			'table rows, blank name'      => $this->scenario(identifiers: [self::U4], rows: ['oc_openregister_table_1_2' => [[self::U4, '  ', self::ORG_A]]], readable: $readable),
			'table on second schema'      => $this->scenario(identifiers: [self::U4, self::U2], rows: ['oc_openregister_table_1_3' => [[self::U4, 'Four', self::ORG_A], [self::U2, 'Two', null]]], readable: $readable),
			'organisation load throws'    => $this->scenario(identifiers: [self::U1, self::ORG_A], organisationsThrow: true, memory: [self::U2 => ['Kept', self::ORG_A, '1:2']], extraIdentifiers: [self::U2], readable: $readable),
			'object load throws'          => $this->scenario(identifiers: [self::ORG_A, self::U1], organisations: [[self::ORG_A, 'Org A']], objectsThrow: true, readable: $readable),
			'another session user'        => $this->scenario(identifiers: [self::U1, self::ORG_A, self::ORG_B], organisations: [[self::ORG_A, 'Org A'], [self::ORG_B, 'Org B']], objects: [$objects['u1']], readable: $readable, uid: 'mallory'),
			'read path throws'            => $this->scenario(identifiers: [self::U1, self::ORG_A], organisations: [[self::ORG_A, 'Org A']], objects: [$objects['u1']], readable: $readable, oracleThrows: true),
			'second call as another user'     => $this->scenario(identifiers: [self::ORG_A, self::U1], organisations: [[self::ORG_A, 'Org A']], objects: [$objects['u1']], readable: $readable, thenAs: 'mallory'),
			'duplicate identifiers'       => $this->scenario(identifiers: [self::U1, self::U1, 'one'], objects: [$objects['u1']], readable: $readable),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run getMultipleObjectNames() once on a fresh handler and record what happened.
	 *
	 * @param array<int, string>                         $identifiers        The identifiers asked for.
	 * @param array<string, array{0: string, 1: ?string, 2: ?string}> $memory The name cache before the call.
	 * @param array<string, mixed>                       $distributed        The distributed cache before the call.
	 * @param array<int, string>                         $distributedFails   Distributed keys whose read throws.
	 * @param array<int, array{0: string, 1: ?string}>   $organisations      Organisations the mapper finds.
	 * @param array<int, array<string, mixed>>           $objects            Objects the magic mapper finds.
	 * @param array<string, array<int, array<int, mixed>>> $rows             Magic-table rows by table name.
	 * @param array<string, array<int, string>>          $readable           Per user, the uuids the read path admits.
	 * @param string                                     $uid                The session user.
	 * @param bool                                       $organisationsThrow Whether the organisation load throws.
	 * @param bool                                       $objectsThrow       Whether the object load throws.
	 * @param bool                                       $oracleThrows       Whether the read path throws.
	 * @param array<int, string>                         $extraIdentifiers   Identifiers appended to the request.
	 * @param string|null                                $thenAs             When set, the same handler is asked again as this user.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	private function scenario(
		array $identifiers,
		array $memory = [],
		array $distributed = [],
		array $distributedFails = [],
		array $organisations = [],
		array $objects = [],
		array $rows = [],
		array $readable = [],
		string $uid = 'alice',
		bool $organisationsThrow = false,
		bool $objectsThrow = false,
		bool $oracleThrows = false,
		array $extraIdentifiers = [],
		?string $thenAs = null,
	): array {
		$this->calls = [];
		$this->distributed = $distributed;
		$this->distributedFails = $distributedFails;
		$this->uid = $uid;
		$this->readableByUser = $readable;
		$this->oracleThrows = $oracleThrows;

		$handler = $this->handler(organisations: $organisations, objects: $objects, rows: $rows, organisationsThrow: $organisationsThrow, objectsThrow: $objectsThrow);
		foreach ($memory as $key => [$name, $organisation, $source]) {
			$handler->setObjectName(identifier: $key, name: $name, organisation: $organisation, source: $source);
		}

		$this->calls[] = ['setup done'];

		$result = $handler->getMultipleObjectNames(array_merge($identifiers, $extraIdentifiers));
		if ($thenAs !== null) {
			$this->uid = $thenAs;
			$this->calls[] = ['now as' => $thenAs];
			$result = ['first' => $result, 'second' => $handler->getMultipleObjectNames(array_merge($identifiers, $extraIdentifiers))];
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
			fn (string $prefix) => $prefix === 'openregister_object_names' ? $names : $this->createMock(IMemcache::class)
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
		$organisationMapper->method('findMultipleByUuid')->willReturnCallback(
			function (array $uuids) use ($organisations, $organisationsThrow): array {
				$this->calls[] = ['organisations.find' => $uuids];
				if ($organisationsThrow === true) {
					throw new Exception('organisation table down');
				}

				$found = [];
				foreach ($organisations as [$uuid, $name]) {
					if (in_array($uuid, $uuids, true) === true) {
						$organisation = new Organisation();
						$organisation->setUuid($uuid);
						$organisation->setName($name);
						$found[] = $organisation;
					}
				}

				return $found;
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultiple')->willReturnCallback(
			function (array $ids) use ($objects, $objectsThrow): array {
				$this->calls[] = ['objects.find' => $ids];
				if ($objectsThrow === true) {
					throw new Exception('magic tables down');
				}

				$found = [];
				foreach ($objects as $spec) {
					$object = new ObjectEntity();
					$object->setId($spec['id']);
					$object->setUuid($spec['uuid']);
					$object->setName($spec['name']);
					$object->setOrganisation($spec['org']);
					$object->setRegister($spec['register']);
					$object->setSchema($spec['schema']);
					$object->setSlug($spec['slug']);
					$object->setUri($spec['uri']);
					$found[] = $object;
				}

				return $found;
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
		$register->setId(1);
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
