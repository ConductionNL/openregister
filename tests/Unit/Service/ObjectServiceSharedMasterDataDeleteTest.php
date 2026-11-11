<?php

declare(strict_types=1);

/**
 * A delete in a schema the caller's organisation only consumes is refused.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\IDBConnection;
use ReflectionClass;
use ReflectionMethod;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\ObjectServiceFactory;

/**
 * Real ObjectService and SharedMasterDataService; the mapper finds the row, as the read filter
 * does for a consumer of shared master data, and the delete handler records what reaches it.
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Service\Object\AccessFlags
 * @uses   \OCA\OpenRegister\Service\Object\BulkCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\ObjectCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\ReferencePreviewInvalidation
 * @uses   \OCA\OpenRegister\Service\Object\SavedObjectScope
 * @uses   \OCA\OpenRegister\Service\Object\SavedScopeIds
 * @uses   \OCA\OpenRegister\Service\SharedMasterDataService
 * @uses   \OCA\OpenRegister\Exception\SharedMasterDataWriteException
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Search\Db\View
 * @uses   \OCA\OpenRegister\Service\Object\ObjectDeleteRequest
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 */
class ObjectServiceSharedMasterDataDeleteTest extends TestCase {

	/** @var MockObject&DeleteObject */
	private MockObject $deleteHandler;

	/**
	 * The schema each delete right was asked of.
	 *
	 * @var list<int|null>
	 */
	private array $rightsAskedOf = [];

	/**
	 * Whether a row's schema can be found.
	 */
	private bool $schemaResolves = true;

	/**
	 * Whether the caller's organisations can be found.
	 */
	private bool $organisationsResolve = true;

	/**
	 * A service acting for these organisations, with schema 2 or register 10 shared.
	 *
	 * @param list<string> $organisations The caller's active organisations.
	 * @param array        $declarations  The share declarations.
	 *
	 * @return ObjectService
	 */
	private function service(array $organisations, array $declarations): ObjectService {
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$shares = new SharedMasterDataService($this->createMock(IDBConnection::class));
		(new ReflectionClass($shares))->getProperty('declarations')->setValue($shares, $declarations);
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => $id === SharedMasterDataService::class ? $shares : null
		);
		$orgs = $organisations;
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserActiveOrganisations')->willReturnCallback(
			function () use ($orgs): array {
				if ($this->organisationsResolve === false) {
					throw new \RuntimeException('no session');
				}

				return $orgs;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$permissions = $this->createMock(PermissionHandler::class);
		$this->rightsAskedOf = [];
		$permissions->method('checkPermission')->willReturnCallback(
			function (Schema $schema): void {
				$this->rightsAskedOf[] = $schema->getId();
			}
		);
		$permissions->method('filterUuidsForPermissions')->willReturnArgument(0);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (int|string $id): Schema {
				if ($this->schemaResolves === false) {
					throw new DoesNotExistException('gone');
				}

				return self::schema((int)$id);
			}
		);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(static fn (int|string $id): Register => self::register((int)$id));
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findStoredAcrossAllMagicTables')->willReturnCallback(
			static fn (array $uuids): array => array_map(self::row(...), $uuids)
		);
		$mapper->method('find')->willReturnCallback(
			static fn (string|int $identifier): ObjectEntity => self::row((string)$identifier)
		);

		return new ObjectService(...ObjectServiceFactory::args(
			$this->createMock(DataManipulationHandler::class),
			$this->deleteHandler,
			$this->createMock(GetObject::class),
			$permissions,
			$this->createMock(RenderObject::class),
			$this->createMock(SaveObject::class),
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$this->createMock(ValidateObject::class),
			$this->createMock(LockHandler::class),
			$this->createMock(AuditHandler::class),
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(QueryHandler::class),
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$this->createMock(CascadingHandler::class),
			$this->createMock(MigrationHandler::class),
			$registers,
			$schemas,
			$this->createMock(ViewMapper::class),
			$mapper,
			$this->createMock(FileService::class),
			$session,
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$organisations,
			$this->createMock(LoggerInterface::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DateTimeNormalizer::class),
			$container,
			$this->createMock(ObjectSourceRegistry::class)
		));
	}//end service()

	/**
	 * The holder's row, in register 10 and schema 2.
	 *
	 * @param string $uuid The row's uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function row(string $uuid): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setOrganisation('org-holder');
		$row->setOwner('importer');
		$row->setRegister('10');
		$row->setSchema('2');

		return $row;
	}//end row()

	/**
	 * A register.
	 *
	 * @param int $id The register's id.
	 *
	 * @return Register
	 */
	private static function register(int $id): Register {
		$register = new Register();
		$register->setId($id);

		return $register;
	}//end register()

	/**
	 * A schema.
	 *
	 * @param int $id The schema's id.
	 *
	 * @return Schema
	 */
	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('schema' . $id);

		return $schema;
	}//end schema()

	/**
	 * Leave another call's register and schema on the service.
	 *
	 * @param ObjectService $service  The service.
	 * @param int           $register The register left behind.
	 * @param int           $schema   The schema left behind.
	 *
	 * @return void
	 */
	private static function leaveScope(ObjectService $service, int $register, int $schema): void {
		$class = new ReflectionClass(ObjectService::class);
		$class->getProperty('currentRegister')->setValue($service, self::register($register));
		$class->getProperty('currentSchema')->setValue($service, self::schema($schema));
	}//end leaveScope()

	/**
	 * Declarations with one shared table.
	 *
	 * @param string $table The shared table.
	 * @param int    $id    The shared row.
	 *
	 * @return array
	 */
	private static function shared(string $table, int $id): array {
		$declarations = [SharedMasterDataService::REGISTERS => [], SharedMasterDataService::SCHEMAS => []];
		$declarations[$table] = [['id' => $id, 'holder' => 'org-holder', 'consumers' => ['org-consumer'], 'title' => 'shared']];

		return $declarations;
	}//end shared()

	/**
	 * Delete a row of register 10, schema 2.
	 *
	 * @param ObjectService $service The service.
	 * @param bool          $flags   The rbac and multitenancy flags.
	 *
	 * @return void
	 */
	private static function delete(ObjectService $service, bool $flags = true): void {
		$register = new Register();
		$register->setId(10);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('note');
		$service->deleteObject(uuid: 'holder-row', register: $register, schema: $schema, _rbac: $flags, _multitenancy: $flags);
	}//end delete()

	public function testAConsumerOfASharedSchemaMayNotDelete(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(SharedMasterDataWriteException::class);
		self::delete($service);
	}

	public function testAConsumerOfASharedRegisterMayNotDelete(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::REGISTERS, 10));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(SharedMasterDataWriteException::class);
		self::delete($service);
	}

	public function testTheHolderMayDelete(): void {
		$service = $this->service(['org-holder'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->once())->method('deleteObject')->willReturn(true);

		self::delete($service);
	}

	public function testAnOrganisationOutsideTheShareIsNotAskedAboutIt(): void {
		$service = $this->service(['org-other'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->once())->method('deleteObject')->willReturn(true);

		self::delete($service);
	}

	public function testAnAdministratorWithTheFlagsOffIsNotKeptFromIt(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->once())->method('deleteObject')->willReturn(true);

		self::delete($service, flags: false);
	}

	/**
	 * Calls without a scope, each way a consumer's delete can arrive at the row.
	 *
	 * @return array<string, array{string, int, int|null, int|null}>
	 */
	public static function unscopedConsumerCalls(): array {
		return [
			'no scope, schema shared'            => [SharedMasterDataService::SCHEMAS, 2, null, null],
			'no scope, register shared'          => [SharedMasterDataService::REGISTERS, 10, null, null],
			'another call\'s scope, schema shared'   => [SharedMasterDataService::SCHEMAS, 2, 11, 3],
			'another call\'s scope, register shared' => [SharedMasterDataService::REGISTERS, 10, 11, 3],
		];
	}//end unscopedConsumerCalls()

	/**
	 * @dataProvider unscopedConsumerCalls
	 */
	public function testAConsumerMayNotDeleteWithoutAScope(string $table, int $id, ?int $register, ?int $schema): void {
		$service = $this->service(['org-consumer'], self::shared($table, $id));
		if ($register !== null && $schema !== null) {
			self::leaveScope($service, $register, $schema);
		}

		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(SharedMasterDataWriteException::class);
		$service->deleteObject(uuid: 'holder-row');
	}

	public function testAScopeNamingOnlyARegisterIsAskedOfTheRow(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(SharedMasterDataWriteException::class);
		$service->deleteObject(uuid: 'holder-row', register: self::register(10));
	}

	public function testTheHolderMayDeleteWithoutAScope(): void {
		$service = $this->service(['org-holder'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->once())->method('deleteObject')->willReturn(true);

		$service->deleteObject(uuid: 'holder-row');
	}

	public function testAScopeLeftBehindDoesNotRefuseARowOutsideTheShare(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 3));
		self::leaveScope($service, 11, 3);
		$this->deleteHandler->expects($this->once())->method('deleteObject')->willReturn(true);

		$service->deleteObject(uuid: 'holder-row');
	}

	public function testTheRightIsAskedOfTheRowsSchemaNotOfAScopeLeftBehind(): void {
		$service = $this->service(['org-other'], self::shared(SharedMasterDataService::SCHEMAS, 99));
		self::leaveScope($service, 11, 3);
		$this->deleteHandler->method('deleteObject')->willReturn(true);

		$service->deleteObject(uuid: 'holder-row');

		$this->assertSame([2], $this->rightsAskedOf);
	}

	public function testAScopeLeftBehindComesBackAfterTheDelete(): void {
		$service = $this->service(['org-other'], self::shared(SharedMasterDataService::SCHEMAS, 99));
		self::leaveScope($service, 11, 3);
		$this->deleteHandler->method('deleteObject')->willReturn(true);

		$service->deleteObject(uuid: 'holder-row');

		$current = (new ReflectionClass(ObjectService::class))->getProperty('currentSchema')->getValue($service);
		$this->assertSame(3, $current->getId());
	}

	public function testARowWhoseSchemaCannotBeFoundIsNotDeleted(): void {
		$service = $this->service(['org-holder'], self::shared(SharedMasterDataService::SCHEMAS, 99));
		self::leaveScope($service, 11, 3);
		$this->schemaResolves = false;
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(\RuntimeException::class);
		$service->deleteObject(uuid: 'holder-row');
	}

	public function testAConsumerMayNotDeleteFromASourcedSchemaNamedWithoutARegister(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$schema = self::schema(2);
		$schema->setConfiguration(['x-openregister-object-source' => ['type' => 'external']]);

		$this->expectException(SharedMasterDataWriteException::class);
		$service->deleteObject(uuid: 'holder-row', schema: $schema);
	}

	public function testOrganisationsThatCannotBeResolvedConsumeNoShare(): void {
		$this->organisationsResolve = false;
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->once())->method('deleteObject')->willReturn(true);

		self::delete($service);
	}

	public function testAnAdministratorWithoutAScopeIsNotKeptFromIt(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->once())->method('deleteObject')->willReturn(true);

		$service->deleteObject(uuid: 'holder-row', _rbac: false, _multitenancy: false);
	}

	/**
	 * Either flag alone binds a delete to the organisation.
	 *
	 * @return array<string, array{bool, bool}>
	 */
	public static function singleFlags(): array {
		return [
			'multitenancy only' => [false, true],
			'rbac only'         => [true, false],
		];
	}//end singleFlags()

	/**
	 * @dataProvider singleFlags
	 */
	public function testOneFlagIsEnoughToAskOnTheScopedRoute(bool $rbac, bool $multitenancy): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(SharedMasterDataWriteException::class);
		$service->deleteObject(uuid: 'holder-row', register: self::register(10), schema: self::schema(2), _rbac: $rbac, _multitenancy: $multitenancy);
	}

	/**
	 * @dataProvider singleFlags
	 */
	public function testOneFlagIsEnoughToAskWithoutAScope(bool $rbac, bool $multitenancy): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(SharedMasterDataWriteException::class);
		$service->deleteObject(uuid: 'holder-row', _rbac: $rbac, _multitenancy: $multitenancy);
	}

	public function testAnOrganisationContextAsksOnTheScopedRouteWithTheFlagsOff(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(SharedMasterDataWriteException::class);
		OrganisationContext::run('org-consumer', static fn () => self::delete($service, flags: false));
	}

	public function testAnOrganisationContextAsksWithoutAScopeWithTheFlagsOff(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(SharedMasterDataWriteException::class);
		OrganisationContext::run('org-consumer', static fn () => $service->deleteObject(uuid: 'holder-row', _rbac: false, _multitenancy: false));
	}

	public function testABulkDeleteAsksOfTheRowsRegisterNotOfAScopeLeftBehind(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::REGISTERS, 10));
		self::leaveScope($service, 11, 3);
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$result = $service->deleteObjects(uuids: ['holder-row']);

		$this->assertSame('OBJECT_NOT_PERMITTED', $result['skipped_reasons']['holder-row']['error'] ?? null);
	}

	public function testABulkDeleteInAnOrganisationContextAsksWithTheFlagsOff(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$result = OrganisationContext::run(
			'org-consumer',
			static fn () => $service->deleteObjects(uuids: ['holder-row'], _rbac: false, _multitenancy: false)
		);

		$this->assertSame('OBJECT_NOT_PERMITTED', $result['skipped_reasons']['holder-row']['error'] ?? null);
	}

	/**
	 * @dataProvider singleFlags
	 */
	public function testOneFlagIsEnoughToAskOnABulkDelete(bool $rbac, bool $multitenancy): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$result = $service->deleteObjects(uuids: ['holder-row'], _rbac: $rbac, _multitenancy: $multitenancy);

		$this->assertSame('OBJECT_NOT_PERMITTED', $result['skipped_reasons']['holder-row']['error'] ?? null);
	}

	/**
	 * Ask the bulk delete's refusal for a row of register 10, schema 2.
	 *
	 * @param ObjectService $service The service.
	 * @param bool          $flags   The rbac and multitenancy flags.
	 *
	 * @return array|null
	 */
	private static function bulkRefusal(ObjectService $service, bool $flags = true): ?array {
		$register = new Register();
		$register->setId(10);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('note');
		$scope = ['schemaId' => 2, 'draft' => false, 'register' => $register, 'row' => new ObjectEntity()];
		$run = ['_rbac' => $flags, '_multitenancy' => $flags, 'schemaCache' => [2 => $schema]];
		$refusal = new ReflectionMethod(ObjectService::class, 'bulkDeleteRefusal');
		$args = [$scope, &$run];

		return $refusal->invokeArgs($service, $args);
	}//end bulkRefusal()

	public function testABulkDeleteByAConsumerRefusesTheRow(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));

		$this->assertSame('OBJECT_NOT_PERMITTED', self::bulkRefusal($service)['error'] ?? null);
	}

	public function testABulkDeleteByTheHolderIsLeftAlone(): void {
		$service = $this->service(['org-holder'], self::shared(SharedMasterDataService::SCHEMAS, 2));

		$this->assertNull(self::bulkRefusal($service));
	}

	public function testABulkDeleteWithTheFlagsOffIsLeftAlone(): void {
		$service = $this->service(['org-consumer'], self::shared(SharedMasterDataService::SCHEMAS, 2));

		$this->assertNull(self::bulkRefusal($service, flags: false));
	}
}//end class
