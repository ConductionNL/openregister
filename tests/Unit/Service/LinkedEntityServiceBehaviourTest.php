<?php

/**
 * LinkedEntityService behaviour: type validation, link writes and the reverse lookup.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Integration\IntegrationIdIndexInterface;
use OCA\OpenRegister\Service\LinkedEntityIntegrations;
use OCA\OpenRegister\Service\LinkedEntityMappers;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\EntityLink;

/**
 * What each branch of the linked-entity service decides.
 *
 * @covers \OCA\OpenRegister\Service\LinkedEntityService
 * @covers \OCA\OpenRegister\Service\LinkedEntityLookup
 * @uses \OCA\OpenRegister\Service\LinkedEntityMappers
 * @uses \OCA\OpenRegister\Service\LinkedEntityIntegrations
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Db\Schema\LinkedTypesRule
 * @uses \OCA\OpenRegister\Db\Organisation
 */
class LinkedEntityServiceBehaviourTest extends TestCase {

	/**
	 * The magic mapper (the concrete class: the interface lacks findByLinkedEntity).
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $magicMapper;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemaMapper;

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface $registerMapper;

	/**
	 * The organisation mapper.
	 *
	 * @var OrganisationMapperInterface&MockObject
	 */
	private OrganisationMapperInterface $organisationMapper;

	/**
	 * The integration id index.
	 *
	 * @var IntegrationIdIndexInterface&MockObject
	 */
	private IntegrationIdIndexInterface $registry;

	/**
	 * The permission handler.
	 *
	 * @var PermissionHandlerInterface&MockObject
	 */
	private PermissionHandlerInterface $permissionHandler;

	/**
	 * The deep link registry.
	 *
	 * @var DeepLinkRegistryService&MockObject
	 */
	private DeepLinkRegistryService $deepLinks;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->organisationMapper = $this->createMock(OrganisationMapperInterface::class);
		$this->registry = $this->createMock(IntegrationIdIndexInterface::class);
		$this->permissionHandler = $this->createMock(PermissionHandlerInterface::class);
		$this->deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return LinkedEntityService
	 */
	private function service(): LinkedEntityService {
		return new LinkedEntityService(
			new LinkedEntityMappers(new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this->magicMapper), $this->organisationMapper),
			new LinkedEntityIntegrations($this->registry, $this->permissionHandler, $this->deepLinks),
			$this->logger
		);
	}//end service()

	/**
	 * A schema with an id, title and linked types.
	 *
	 * @param int                $id    The id.
	 * @param array<int, string> $types The linked types it declares.
	 * @param array<int, string> $notes The notes it links.
	 *
	 * @return Schema
	 */
	private function schema(int $id, array $types = [], array $notes = []): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid('schema-' . $id);
		$schema->setTitle('Schema ' . $id);
		$schema->setIcon('icon-' . $id);
		$schema->setConfiguration(['linkedTypes' => $types]);
		$schema->setNotes($notes);

		return $schema;
	}//end schema()

	/**
	 * An unknown type is refused with the sorted, de-duplicated list of legacy and registered ids.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeIsRefusedWithEveryValidType(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(false);
		$this->registry->method('listIds')->willReturn(['zaak', 'mail']);
		$this->magicMapper->expects($this->never())->method('find');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Invalid linked entity type 'bogus'. Valid types: calendar, contacts, deck, files, mail, notes, talk, todos, zaak");
		$this->service()->addLink('o-1', new EntityLink('bogus', 'x'));
	}//end testAnUnknownTypeIsRefusedWithEveryValidType()

	/**
	 * A legacy type passes even when the registry does not know it, and an id already linked is not written again.
	 *
	 * @return void
	 */
	public function testALegacyTypePassesAndAPresentIdIsNotWritten(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(false);
		$this->registry->expects($this->never())->method('listIds');
		$object = new ObjectEntity();
		$object->setNotes(['n-1']);
		$this->magicMapper->method('find')->with('o-1')->willReturn($object);
		$this->magicMapper->expects($this->never())->method('update');
		$this->permissionHandler->expects($this->never())->method('checkPermission');

		$this->assertSame(['n-1'], $this->service()->addLink('o-1', new EntityLink('notes', 'n-1')));
	}//end testALegacyTypePassesAndAPresentIdIsNotWritten()

	/**
	 * An object without a schema cannot be permission-checked, so nothing is written.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutASchemaIsNotWritten(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(true);
		$object = new ObjectEntity();
		$this->magicMapper->method('find')->willReturn($object);
		$this->magicMapper->expects($this->never())->method('update');

		$this->expectExceptionMessage('Cannot resolve schema for linked-entity write permission check.');
		$this->service()->removeLink('o-1', new EntityLink(type: 'notes', entityId: 'n-1'));
	}//end testAnObjectWithoutASchemaIsNotWritten()

	/**
	 * Removing keeps the other ids, re-indexed, and writes after the update check on the object's schema.
	 *
	 * @return void
	 */
	public function testRemoveKeepsTheOtherIdsAndWritesAfterTheCheck(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(true);
		$object = new ObjectEntity();
		$object->setSchema('7');
		$object->setOwner('alice');
		$object->setNotes(['n-1', 'n-2', 'n-3']);
		$schema = $this->schema(id: 7);
		$this->magicMapper->method('find')->willReturn($object);
		$this->schemaMapper->method('find')->with('7')->willReturn($schema);
		$this->permissionHandler->expects($this->once())->method('checkPermission')
			->with($this->identicalTo($schema), 'update', null, 'alice', true, $this->identicalTo($object));
		$this->magicMapper->expects($this->once())->method('update')->with($this->identicalTo($object));

		$this->assertSame(['n-1', 'n-3'], $this->service()->removeLink('o-1', new EntityLink(type: 'notes', entityId: 'n-2')));
		$this->assertSame(['n-1', 'n-3'], $object->getNotes());
	}//end testRemoveKeepsTheOtherIdsAndWritesAfterTheCheck()

	/**
	 * A register link: a missing register is refused, a new id is stored, a present id is not written.
	 *
	 * @return void
	 */
	public function testRegisterLinks(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(true);
		$register = new Register();
		$register->setNotes(['n-1']);
		$this->registerMapper->method('findAll')->willReturnCallback(
			fn (?int $limit = null, ?int $offset = null, ?array $filters = []): array => ($filters['uuid'] === 'r-1' ? [$register] : [])
		);
		$this->registerMapper->expects($this->once())->method('update')->with($this->identicalTo($register));
		$service = $this->service();

		$this->assertSame(['n-1', 'n-2'], $service->addLinkToRegister('r-1', new EntityLink('notes', 'n-2')));
		$this->assertSame(['n-1', 'n-2'], $register->getNotes());
		$this->assertSame(['n-1', 'n-2'], $service->addLinkToRegister('r-1', new EntityLink('notes', 'n-1')));

		$this->expectExceptionMessage('Register not found: r-404');
		$service->addLinkToRegister('r-404', new EntityLink('notes', 'n-1'));
	}//end testRegisterLinks()

	/**
	 * A schema link: a missing schema is refused, a new id is stored, a present id is not written.
	 *
	 * @return void
	 */
	public function testSchemaLinks(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(true);
		$schema = $this->schema(id: 3, notes: ['n-1']);
		$this->schemaMapper->method('findAll')->willReturnCallback(
			fn (?int $limit = null, ?int $offset = null, ?array $filters = []): array => ($filters['uuid'] === 's-1' ? [$schema] : [])
		);
		$this->schemaMapper->expects($this->once())->method('update')->with($this->identicalTo($schema));
		$service = $this->service();

		$this->assertSame(['n-1', 'n-2'], $service->addLinkToSchema('s-1', new EntityLink('notes', 'n-2')));
		$this->assertSame(['n-1', 'n-2'], $service->addLinkToSchema('s-1', new EntityLink('notes', 'n-2')));

		$this->expectExceptionMessage('Schema not found: s-404');
		$service->addLinkToSchema('s-404', new EntityLink('notes', 'n-1'));
	}//end testSchemaLinks()

	/**
	 * The reverse lookup scans only schemas declaring the type, and reports objects, registers, schemas and organisations that link the id.
	 *
	 * @return void
	 */
	public function testTheReverseLookupReportsEveryLinkingEntity(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(true);
		$declaring = $this->schema(id: 4, types: ['notes'], notes: ['n-9']);
		$other = $this->schema(id: 5, types: ['mail']);
		$this->schemaMapper->method('findAll')->willReturnCallback(
			fn (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $s = [], ?array $p = [], ?array $e = [], bool $_rbac = true): array => ($_rbac === false ? [$declaring, $other] : [$declaring, $other])
		);
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setName('Ticket one');
		$object->setRegister('2');
		$this->magicMapper->expects($this->once())->method('findByLinkedEntity')
			->with($this->identicalTo($declaring), '_notes', 'n-9')->willReturn([$object]);
		$this->deepLinks->method('resolveUrl')->with(2, 4, ['uuid' => 'obj-1', 'register' => 2, 'schema' => 4])->willReturn('/apps/x/obj-1');
		$register = new Register();
		$register->setUuid('reg-1');
		$register->setTitle('Register one');
		$register->setNotes(['n-9']);
		$unlinked = new Register();
		$unlinked->setNotes(null);
		$this->registerMapper->method('findAll')->willReturn([$register, $unlinked]);
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setName('Org one');
		$organisation->setNotes(['n-9']);
		$this->organisationMapper->method('findAll')->willReturn([$organisation]);

		$this->assertSame(
			[
				['entityType' => 'object', 'uuid' => 'obj-1', 'name' => 'Ticket one', 'schema' => 'Schema 4', 'schemaId' => 4, 'schemaIcon' => 'icon-4', 'register' => 2, 'url' => '/apps/x/obj-1'],
				['entityType' => 'register', 'uuid' => 'reg-1', 'name' => 'Register one'],
				['entityType' => 'schema', 'uuid' => 'schema-4', 'name' => 'Schema 4'],
				['entityType' => 'organisation', 'uuid' => 'org-1', 'name' => 'Org one'],
			],
			$this->service()->reverseLookup('notes', 'n-9')
		);
	}//end testTheReverseLookupReportsEveryLinkingEntity()

	/**
	 * A failing magic table or entity table is logged and the lookup returns what the others found.
	 *
	 * @return void
	 */
	public function testFailingTablesAreLoggedAndSkipped(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(true);
		$declaring = $this->schema(id: 4, types: ['notes']);
		$this->schemaMapper->method('findAll')->willReturn([$declaring]);
		$this->magicMapper->method('findByLinkedEntity')->willThrowException(new Exception('no table'));
		$this->registerMapper->method('findAll')->willThrowException(new Exception('db down'));
		$this->organisationMapper->method('findAll')->willReturn([]);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->assertSame([], $this->service()->reverseLookup('notes', 'n-9'));
		$this->assertSame(
			[
				['[LinkedEntityService] Error scanning magic table', ['schema' => 4, 'error' => 'no table']],
				['[LinkedEntityService] Error scanning registers', ['error' => 'db down']],
			],
			$warnings
		);
	}//end testFailingTablesAreLoggedAndSkipped()

	/**
	 * After fifty declaring schemas the circuit breaker stops the scan and logs once.
	 *
	 * @return void
	 */
	public function testTheCircuitBreakerStopsAfterFiftyTables(): void {
		$this->registry->method('isValidIntegrationId')->willReturn(true);
		$schemas = [];
		for ($i = 1; $i <= 52; $i++) {
			$schemas[] = $this->schema(id: $i, types: ['notes']);
		}

		$this->schemaMapper->method('findAll')->willReturnCallback(
			fn (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $s = [], ?array $p = [], ?array $e = [], bool $_rbac = true): array => ($_rbac === false ? $schemas : [])
		);
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->organisationMapper->method('findAll')->willReturn([]);
		$this->magicMapper->expects($this->exactly(50))->method('findByLinkedEntity')->willReturn([]);
		$this->logger->expects($this->once())->method('warning')
			->with('[LinkedEntityService] Circuit breaker: max tables reached', ['maxTables' => 50, 'type' => 'notes']);

		$this->assertSame([], $this->service()->reverseLookup('notes', 'n-9'));
	}//end testTheCircuitBreakerStopsAfterFiftyTables()
}//end class
