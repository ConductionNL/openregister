<?php

/**
 * Unit tests for the schema -> owning-register resolution behind cross-schema
 * unified search.
 *
 * A schema belongs to exactly one register, and the magic table is named after
 * the pair. The cross-schema search used to take the FIRST register it had
 * loaded and pair every schema with it, so all but one schema asked a table
 * that does not exist and unified search answered nothing. These tests pin the
 * map that replaced that: which register owns which schema, that a query
 * naming schemas only still resolves owners, and that a register that cannot
 * be loaded takes only its own schemas out of the search.
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class MagicMapperSchemaOwnershipTest extends TestCase {

	private IDBConnection&MockObject $db;

	private RegisterMapper&MockObject $registerMapper;

	private MagicMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$container = $this->createMock(ContainerInterface::class);
		$dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$conditionMatcher = $this->createMock(ConditionMatcher::class);
		$schemaTypeConverter = $this->createMock(SchemaTypeConverter::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($dateTimeNormalizer, $conditionMatcher, $schemaTypeConverter) {
				return match ($id) {
					DateTimeNormalizer::class => $dateTimeNormalizer,
					ConditionMatcher::class => $conditionMatcher,
					SchemaTypeConverter::class => $schemaTypeConverter,
					default => null,
				};
			}
		);

		$this->mapper = new MagicMapper(
			$this->db,
			$this->createMock(SchemaMapper::class),
			$this->registerMapper,
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SettingsService::class),
			$container
		);
	}//end setUp()

	/**
	 * Build a real Register. Entity getters are magic, so a mock cannot answer
	 * getSchemas() at all.
	 *
	 * @param int   $id      The register id.
	 * @param array $schemas The schema membership.
	 *
	 * @return Register
	 */
	private function register(int $id, array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setTitle('Register ' . $id);
		$register->setSchemas($schemas);
		return $register;
	}//end register()

	/**
	 * Call the private resolver.
	 *
	 * @param array $registerIds The register filter.
	 *
	 * @return array The resolver's result.
	 */
	private function resolve(array $registerIds): array {
		$method = new \ReflectionMethod(MagicMapper::class, 'resolveSchemaOwnership');
		$method->setAccessible(true);
		return $method->invoke($this->mapper, $registerIds);
	}//end resolve()

	/**
	 * Each schema is mapped to the register that actually lists it, not to
	 * whichever register was loaded first.
	 *
	 * @return void
	 */
	public function testEachSchemaMapsToItsOwnRegister(): void {
		$this->registerMapper->method('find')->willReturnCallback(
			function (int $id): Register {
				return match ($id) {
					7 => $this->register(7, [4306, 4307]),
					9 => $this->register(9, [4309]),
					default => throw new \RuntimeException('unexpected register ' . $id),
				};
			}
		);

		$ownership = $this->resolve([7, 9]);

		$this->assertSame(
			[4306 => 7, 4307 => 7, 4309 => 9],
			$ownership['schemaToRegisterId']
		);
		$this->assertSame([7, 9], array_keys($ownership['registers']));
	}//end testEachSchemaMapsToItsOwnRegister()

	/**
	 * A register that cannot be loaded takes only its own schemas out of the
	 * search; the rest still resolve.
	 *
	 * @return void
	 */
	public function testAnUnloadableRegisterDoesNotEmptyTheMap(): void {
		$this->registerMapper->method('find')->willReturnCallback(
			function (int $id): Register {
				if ($id === 7) {
					throw new \RuntimeException('gone');
				}

				return $this->register(9, [4309]);
			}
		);

		$ownership = $this->resolve([7, 9]);

		$this->assertSame([4309 => 9], $ownership['schemaToRegisterId']);
		$this->assertArrayNotHasKey(7, $ownership['registers']);
	}//end testAnUnloadableRegisterDoesNotEmptyTheMap()

	/**
	 * A query that names schemas only (what unified search sends) still
	 * resolves every owner, by reading the membership directly rather than
	 * through findAll(), whose organisation filter would hide most registers.
	 *
	 * @return void
	 */
	public function testSchemaOnlyQueryResolvesOwnersFromTheMembershipTable(): void {
		$rows = [
			['id' => 7, 'schemas' => '[4306, 4307]'],
			['id' => 9, 'schemas' => '{"4309": "Pet"}'],
		];

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls($rows[0], $rows[1], false);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('executeQuery')->willReturn($result);
		$this->db->method('getQueryBuilder')->willReturn($queryBuilder);

		$this->registerMapper->expects($this->never())->method('findAll');

		$ownership = $this->resolve([]);

		$this->assertSame([4306 => 7, 4307 => 7, 4309 => 9], $ownership['schemaToRegisterId']);
	}//end testSchemaOnlyQueryResolvesOwnersFromTheMembershipTable()

	/**
	 * A membership lookup that cannot run answers an empty map rather than
	 * throwing; the caller then returns an empty page.
	 *
	 * @return void
	 */
	public function testAFailingMembershipLookupAnswersAnEmptyMap(): void {
		$this->db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no database'));

		$ownership = $this->resolve([]);

		$this->assertSame([], $ownership['schemaToRegisterId']);
		$this->assertSame([], $ownership['registers']);
	}//end testAFailingMembershipLookupAnswersAnEmptyMap()
}//end class
