<?php

/**
 * Characterisation test for RegisterMapper::getSchemasByRegisterId().
 *
 * Pins what the linked-schema read passes on: the register lookup gets both
 * filter flags, each schema lookup gets the caller's RBAC flag but never the
 * organisation filter, a schema that no longer exists is skipped, and a
 * register the filters refuse stays refused.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\RegisterMapper
 */
final class RegisterMapperLinkedSchemasTest extends TestCase {
	/**
	 * Schema lookups seen, as [id, extend, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $schemaLookups = [];

	/**
	 * Register lookups seen, as [id, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $registerLookups = [];

	public function testEachLinkedSchemaIsReadWithoutTheOrganisationFilterAndMissingOnesAreSkipped(): void {
		$mapper = $this->mapper(schemaIds: [3, '4', 9], missing: [4]);

		$schemas = $mapper->getSchemasByRegisterId(registerId: 12, _rbac: true, _multitenancy: true);

		$this->assertSame([3, 9], array_map(static fn (Schema $schema): int => (int)$schema->getId(), $schemas));
		$this->assertSame([[12, true, true]], $this->registerLookups);
		$this->assertSame([[3, [], true, false], [4, [], true, false], [9, [], true, false]], $this->schemaLookups);
	}//end testEachLinkedSchemaIsReadWithoutTheOrganisationFilterAndMissingOnesAreSkipped()

	public function testTheRbacFlagIsPassedThroughAndTheTenancyFlagReachesOnlyTheRegister(): void {
		$mapper = $this->mapper(schemaIds: ['7'], missing: []);

		$schemas = $mapper->getSchemasByRegisterId(registerId: 5, _rbac: false, _multitenancy: false);

		$this->assertCount(1, $schemas);
		$this->assertSame([[5, false, false]], $this->registerLookups);
		$this->assertSame([[7, [], false, false]], $this->schemaLookups);
	}//end testTheRbacFlagIsPassedThroughAndTheTenancyFlagReachesOnlyTheRegister()

	public function testARegisterWithoutSchemasReadsNoSchema(): void {
		$mapper = $this->mapper(schemaIds: [], missing: []);

		$this->assertSame([], $mapper->getSchemasByRegisterId(registerId: 5));
		$this->assertSame([], $this->schemaLookups);
	}//end testARegisterWithoutSchemasReadsNoSchema()

	public function testARefusedRegisterStaysRefusedAndNoSchemaIsRead(): void {
		$mapper = $this->mapper(schemaIds: null, missing: []);

		$this->expectException(DoesNotExistException::class);
		try {
			$mapper->getSchemasByRegisterId(registerId: 5);
		} finally {
			$this->assertSame([], $this->schemaLookups);
		}
	}//end testARefusedRegisterStaysRefusedAndNoSchemaIsRead()

	/**
	 * A mapper whose find() answers one register (or refuses when $schemaIds is null).
	 *
	 * @param array<int, int|string>|null $schemaIds The register's schema ids; null refuses the register.
	 * @param array<int, int>             $missing   Schema ids that no longer exist.
	 *
	 * @return RegisterMapper&MockObject The mapper.
	 */
	private function mapper(?array $schemaIds, array $missing): RegisterMapper&MockObject {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($missing): Schema {
				$this->schemaLookups[] = [$id, $_extend, $_rbac, $_multitenancy];
				if (in_array($id, $missing, true) === true) {
					throw new DoesNotExistException('gone');
				}

				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);

		$mapper = $this->getMockBuilder(RegisterMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					$schemaMapper,
					$this->createMock(IEventDispatcher::class),
					$this->createMock(ContainerInterface::class),
					$this->createMock(OrganisationMapper::class),
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IAppConfig::class),
					$this->createMock(LoggerInterface::class),
				]
			)
			->onlyMethods(['find'])
			->getMock();

		$mapper->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac = true, bool $_multitenancy = true) use ($schemaIds): Register {
				$this->registerLookups[] = [$id, $_rbac, $_multitenancy];
				if ($schemaIds === null) {
					throw new DoesNotExistException('filtered');
				}

				$register = new Register();
				$register->setSchemas($schemaIds);
				return $register;
			}
		);

		return $mapper;
	}//end mapper()
}//end class
