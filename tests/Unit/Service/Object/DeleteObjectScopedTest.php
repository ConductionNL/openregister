<?php

declare(strict_types=1);

/**
 * DeleteObject Scoped Delete Unit Tests.
 *
 * Covers the scoped lookup path introduced for #1638 — the handler must
 * resolve the UUID against exactly one magic table when the caller passes
 * `$scoped = true` together with concrete Register + Schema entities, and
 * a UUID in a different `(register, schema)` magic table must raise
 * DoesNotExistException without touching any row.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-object-delete-api/tasks.md#5
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests the scoped delete path on DeleteObject::deleteObject().
 *
 * The legacy unscoped path (cross-table scan via `findAcrossAllSources`) is
 * exercised by DeleteObjectTest. This file exercises ONLY the scoped path —
 * the bug that motivated #1638.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeleteObjectScopedTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var DeleteObject
	 */
	private DeleteObject $handler;

	/**
	 * Object entity mapper mock.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $objectMapper;

	/**
	 * Cache handler mock.
	 *
	 * @var CacheHandler&MockObject
	 */
	private CacheHandler $cacheHandler;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Audit-trail mapper mock.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Settings-service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Referential-integrity service mock.
	 *
	 * @var ReferentialIntegrityService&MockObject
	 */
	private ReferentialIntegrityService $integrityService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->cacheHandler = $this->createMock(CacheHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->integrityService = $this->createMock(ReferentialIntegrityService::class);

		$this->handler = new DeleteObject(
			$this->objectMapper,
			$this->cacheHandler,
			$this->userSession,
			$this->auditTrailMapper,
			$this->settingsService,
			$this->logger,
			$this->integrityService,
			$this->createMock(IDBConnection::class)
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity with the given UUID and (register, schema) IDs.
	 *
	 * @param string $uuid The entity UUID.
	 * @param string $register Register ID.
	 * @param string $schema Schema ID.
	 *
	 * @return ObjectEntity
	 */
	private function makeEntity(string $uuid, string $register, string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister($register);
		$entity->setSchema($schema);
		return $entity;
	}//end makeEntity()

	/**
	 * Set Entity::id via reflection (managed by QBMapper).
	 *
	 * @param object $entity Entity to mutate.
	 * @param int $id Value.
	 *
	 * @return void
	 */
	private function setEntityId(object $entity, int $id): void {
		$reflection = new ReflectionClass($entity);
		$class = $reflection;
		while ($class !== false) {
			if ($class->hasProperty('id') === true) {
				$prop = $class->getProperty('id');
				$prop->setAccessible(true);
				$prop->setValue($entity, $id);
				return;
			}

			$class = $class->getParentClass();
		}

	}//end setEntityId()

	/**
	 * Build a Register entity with the given ID.
	 *
	 * @param int $id Register ID.
	 *
	 * @return Register
	 */
	private function makeRegister(int $id): Register {
		$register = new Register();
		$this->setEntityId($register, $id);
		return $register;
	}//end makeRegister()

	/**
	 * Build a Schema entity with the given ID.
	 *
	 * @param int $id Schema ID.
	 *
	 * @return Schema
	 */
	private function makeSchema(int $id): Schema {
		$schema = new Schema();
		$this->setEntityId($schema, $id);
		return $schema;
	}//end makeSchema()

	/**
	 * Scoped delete refuses a UUID that lives in a different magic table.
	 *
	 * Scenario: object `abc-123` exists in (register=1, schema=10) but NOT in
	 * (register=2, schema=20). Caller invokes scoped delete against
	 * (register=2, schema=20) — handler MUST raise DoesNotExistException
	 * from MagicMapper::find() and MUST NOT touch any row.
	 *
	 * @return void
	 */
	public function testScopedDeleteRefusesCrossScopeUuid(): void {
		$wrongRegister = $this->makeRegister(2);
		$wrongSchema = $this->makeSchema(20);

		// Scoped find against the WRONG scope returns DoesNotExistException.
		$this->objectMapper
			->expects($this->once())
			->method('find')
			->with(
				$this->equalTo('abc-123'),
				$this->identicalTo($wrongRegister),
				$this->identicalTo($wrongSchema),
				$this->equalTo(true),
				$this->equalTo(true),
				$this->equalTo(true)
			)
			->willThrowException(new DoesNotExistException('not in scope'));

		// findAcrossAllSources MUST NOT be called when scoped=true.
		$this->objectMapper
			->expects($this->never())
			->method('findAcrossAllSources');

		// No mutating call must happen.
		$this->objectMapper
			->expects($this->never())
			->method('update');
		$this->objectMapper
			->expects($this->never())
			->method('deleteObjectEntity');

		$this->expectException(DoesNotExistException::class);

		$this->handler->deleteObject(
			register: $wrongRegister,
			schema: $wrongSchema,
			uuid: 'abc-123',
			originalObjectId: null,
			_rbac: true,
			_multitenancy: true,
			scoped: true
		);

	}//end testScopedDeleteRefusesCrossScopeUuid()

	/**
	 * Scoped delete succeeds when UUID is in the requested scope.
	 *
	 * Scenario: object `abc-123` lives in (register=1, schema=10). Caller
	 * invokes scoped delete against (register=1, schema=10) — handler MUST
	 * resolve via the scoped MagicMapper::find() path and MUST proceed to
	 * the soft-delete write.
	 *
	 * @return void
	 */
	public function testScopedDeleteSucceedsWhenInScope(): void {
		$register = $this->makeRegister(1);
		$schema = $this->makeSchema(10);
		$entity = $this->makeEntity('abc-123', '1', '10');

		// Scoped lookup hits exactly one magic table.
		$this->objectMapper
			->expects($this->once())
			->method('find')
			->with(
				$this->equalTo('abc-123'),
				$this->identicalTo($register),
				$this->identicalTo($schema),
				$this->equalTo(true),
				$this->equalTo(true),
				$this->equalTo(true)
			)
			->willReturn($entity);

		// The inner ::delete() helper still does its own cross-table lookup
		// for cascade context — return a matching context so the soft-delete
		// path proceeds. The PUBLIC scoped contract is enforced by the
		// outer ::find() call above (#1638).
		$this->objectMapper
			->method('findAcrossAllSources')
			->willReturn(
				[
					'object' => $entity,
					'register' => $register,
					'schema' => $schema,
				]
			);

		// No incoming refs → legacy cascade path → soft delete via update.
		$this->integrityService
			->method('hasIncomingOnDeleteReferences')
			->willReturn(false);

		// The soft-delete write happens (returns the same entity).
		$this->objectMapper
			->method('update')
			->willReturn($entity);

		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService
			->method('getRetentionSettingsOnly')
			->willReturn(['auditTrailsEnabled' => false]);

		$result = $this->handler->deleteObject(
			register: $register,
			schema: $schema,
			uuid: 'abc-123',
			originalObjectId: null,
			_rbac: true,
			_multitenancy: true,
			scoped: true
		);

		$this->assertTrue($result);

	}//end testScopedDeleteSucceedsWhenInScope()

	/**
	 * Cross-magic-table UUID collision: only the matching-scope row is touched.
	 *
	 * Scenario: a UUID exists in both (register=1, schema=10) AND
	 * (register=2, schema=20). Caller asks to delete from (register=2,
	 * schema=20). The handler MUST resolve against (register=2, schema=20)
	 * — never the (register=1, schema=10) table — and the entity returned
	 * from the scoped find is what gets soft-deleted.
	 *
	 * @return void
	 */
	public function testScopedDeleteOnlyTouchesMatchingScope(): void {
		$registerA = $this->makeRegister(1);
		$schemaX = $this->makeSchema(10);
		$registerB = $this->makeRegister(2);
		$schemaY = $this->makeSchema(20);

		// The entity that lives in the (B, Y) scope — this is what the
		// scoped lookup returns. The (A, X) row is invisible to the
		// scoped find because the lookup targets one table only.
		$entityInB = $this->makeEntity('dup-uuid', '2', '20');

		$this->objectMapper
			->expects($this->once())
			->method('find')
			->with(
				$this->equalTo('dup-uuid'),
				$this->identicalTo($registerB),
				$this->identicalTo($schemaY),
				$this->equalTo(true),
				$this->equalTo(true),
				$this->equalTo(true)
			)
			->willReturn($entityInB);

		// The inner ::delete() helper does its own findAcrossAllSources for
		// cascade-context — return the entity from the (B, Y) scope so the
		// PUBLIC contract (scoped find at the outer boundary) is what we
		// assert. The (A, X) row is never seen by the handler.
		$this->objectMapper
			->method('findAcrossAllSources')
			->willReturn(
				[
					'object' => $entityInB,
					'register' => $registerB,
					'schema' => $schemaY,
				]
			);

		$this->integrityService
			->method('hasIncomingOnDeleteReferences')
			->willReturn(false);

		// The soft-delete write happens against the entity from the (B, Y)
		// scope — confirmed by checking the entity passed to update().
		$this->objectMapper
			->expects($this->once())
			->method('update')
			->with(
				$this->callback(
					static function (ObjectEntity $arg) use ($entityInB): bool {
						return $arg === $entityInB
							&& $arg->getRegister() === '2'
							&& $arg->getSchema() === '20';
					}
				),
				$this->identicalTo($registerB),
				$this->identicalTo($schemaY)
			)
			->willReturn($entityInB);

		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService
			->method('getRetentionSettingsOnly')
			->willReturn(['auditTrailsEnabled' => false]);

		$result = $this->handler->deleteObject(
			register: $registerB,
			schema: $schemaY,
			uuid: 'dup-uuid',
			originalObjectId: null,
			_rbac: true,
			_multitenancy: true,
			scoped: true
		);

		$this->assertTrue($result);

	}//end testScopedDeleteOnlyTouchesMatchingScope()

	/**
	 * Legacy unscoped path (scoped=false default) keeps cross-table scan.
	 *
	 * Backward compatibility guarantee: callers passing
	 * `deleteObject($register, $schema, $uuid)` (positional, without the new
	 * `scoped` flag) continue to hit `findAcrossAllSources`. The new scope
	 * check is opt-in via `scoped: true`.
	 *
	 * @return void
	 */
	public function testLegacyUnscopedPathStillUsesCrossTableScan(): void {
		$register = $this->makeRegister(1);
		$schema = $this->makeSchema(10);
		$entity = $this->makeEntity('legacy-uuid', '1', '10');

		// Legacy path: cross-table scan, NOT the scoped find(). The handler
		// (and the inner ::delete() helper) both call findAcrossAllSources;
		// we set up the mock with `atLeastOnce` so both calls resolve to the
		// same context.
		$this->objectMapper
			->expects($this->atLeastOnce())
			->method('findAcrossAllSources')
			->willReturn(
				[
					'object' => $entity,
					'register' => $register,
					'schema' => $schema,
				]
			);

		// The new scoped find() MUST NOT fire in legacy mode.
		$this->objectMapper
			->expects($this->never())
			->method('find');

		$this->integrityService
			->method('hasIncomingOnDeleteReferences')
			->willReturn(false);

		$this->objectMapper->method('update')->willReturn($entity);

		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService
			->method('getRetentionSettingsOnly')
			->willReturn(['auditTrailsEnabled' => false]);

		$result = $this->handler->deleteObject(
			register: $register,
			schema: $schema,
			uuid: 'legacy-uuid',
			originalObjectId: null,
			_rbac: true,
			_multitenancy: true,
			scoped: false
		);

		$this->assertTrue($result);

	}//end testLegacyUnscopedPathStillUsesCrossTableScan()

}//end class
