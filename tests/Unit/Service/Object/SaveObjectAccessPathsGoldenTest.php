<?php

/**
 * Golden: the access paths of SaveObject whose private signatures lane K17 reshapes.
 *
 * Pins, on the code as it stood before the reshaping, what is allowed and what
 * is refused, and what the refusal records:
 *  - the shared master data guard: which register and schema it asks about, and
 *    the audit row and log line a refusal leaves;
 *  - the read-only object-source rejection that follows an allowed guard;
 *  - the existing-object lookup of a save: always without RBAC and multitenancy,
 *    a free object returned, a locked, archived or frozen one refused.
 *
 * Driven through `saveResolvedObject()` and `existingObjectForSave()`, whose
 * signatures do not change, on a constructor-less instance, so the pin holds
 * across both the method and the constructor reshaping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusal;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\DeclaredRow;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveCall;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveFlags;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden: shared master data guard, object-source rejection and existing-object lookup.
 */
class SaveObjectAccessPathsGoldenTest extends TestCase {

	/**
	 * Every assertWritable() call: [table, id, activeOrgUuids].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $asked = [];

	/**
	 * Every audit refusal row written, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $audited = [];

	/**
	 * Every warning logged: [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Every find() call on the object mapper, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * A guard that allows the write lets the save go on to the object-source rejection.
	 *
	 * @return void
	 */
	public function testAnAllowedWriteReachesTheReadOnlySourceRejection(): void {
		$handler = $this->handler(refuseTable: null, found: null);

		try {
			$this->saveResolved(handler: $handler, uuid: 'obj-1');
			self::fail('a read-only source must refuse the write');
		} catch (RuntimeException $e) {
			self::assertSame('Schema "product" is a read-only projection of object-source provider "dbal-source"; writes are not allowed.', $e->getMessage());
		}

		self::assertSame(
			[
				[SharedMasterDataService::REGISTERS, 4, ['org-consumer']],
				[SharedMasterDataService::SCHEMAS, 41, ['org-consumer']],
			],
			$this->asked
		);
		self::assertSame([], $this->audited);
	}//end testAnAllowedWriteReachesTheReadOnlySourceRejection()

	/**
	 * A refused register is refused, recorded on the audit trail and logged.
	 *
	 * @return void
	 */
	public function testARefusedRegisterIsRecordedAndRefused(): void {
		$handler = $this->handler(refuseTable: SharedMasterDataService::REGISTERS, found: null);

		try {
			$this->saveResolved(handler: $handler, uuid: 'obj-1');
			self::fail('a consumed register must refuse the write');
		} catch (SharedMasterDataWriteException $e) {
			self::assertSame('org-holder', $e->getHolderUuid());
		}

		self::assertSame([[SharedMasterDataService::REGISTERS, 4, ['org-consumer']]], $this->asked);
		self::assertCount(1, $this->audited);
		$row = $this->audited[0];
		self::assertSame('org-holder', $row['holderOrganisation']);
		self::assertSame('org-consumer', $row['sourceOrganisation']);
		self::assertSame(4, $row['register']);
		self::assertSame(41, $row['schema']);
		self::assertSame('obj-1', $row['objectUuid']);
		self::assertNull($row['actorId']);
		self::assertSame('actor:anonymous', $row['actorReference']);
		self::assertSame('shared_master_data_write_denied', $row['context']['type']);
		self::assertSame(4, $row['context']['register']);
		self::assertSame(41, $row['context']['schema']);
		self::assertSame('obj-1', $row['context']['objectUuid']);
		self::assertSame('[SaveObject] Refused a write to shared master data held by another organisation', $this->warnings[0][0]);
	}//end testARefusedRegisterIsRecordedAndRefused()

	/**
	 * A refused schema is refused after the register was allowed, with no uuid named.
	 *
	 * @return void
	 */
	public function testARefusedSchemaIsRecordedWithoutAUuid(): void {
		$handler = $this->handler(refuseTable: SharedMasterDataService::SCHEMAS, found: null);

		$this->expectException(SharedMasterDataWriteException::class);
		try {
			$this->saveResolved(handler: $handler, uuid: null);
		} finally {
			self::assertCount(2, $this->asked);
			self::assertNull($this->audited[0]['objectUuid']);
			self::assertSame(4, $this->audited[0]['register']);
			self::assertSame(41, $this->audited[0]['schema']);
		}
	}//end testARefusedSchemaIsRecordedWithoutAUuid()

	/**
	 * The existing-object lookup never applies RBAC or multitenancy, and returns a free object.
	 *
	 * @return void
	 */
	public function testTheLookupRunsWithoutRbacAndReturnsAFreeObject(): void {
		$stored  = $this->stored(uuid: 'obj-1');
		$handler = $this->handler(refuseTable: null, found: $stored);

		self::assertSame($stored, $this->existing(handler: $handler, uuid: 'obj-1'));
		self::assertSame(
			[['identifier' => 'obj-1', 'register' => 4, 'schema' => 41, 'includeDeleted' => false, '_rbac' => false, '_multitenancy' => false]],
			$this->finds
		);
	}//end testTheLookupRunsWithoutRbacAndReturnsAFreeObject()

	/**
	 * A row found under another uuid is no existing object, and an absent row is none either.
	 *
	 * @return void
	 */
	public function testAnotherRowOrNoRowIsACreate(): void {
		self::assertNull($this->existing(handler: $this->handler(refuseTable: null, found: $this->stored(uuid: 'other')), uuid: 'obj-1'));
		self::assertNull($this->existing(handler: $this->handler(refuseTable: null, found: false), uuid: 'obj-1'));
	}//end testAnotherRowOrNoRowIsACreate()

	/**
	 * A locked, archived or frozen object refuses the write.
	 *
	 * @return void
	 */
	public function testALockedArchivedOrFrozenObjectIsRefused(): void {
		$locked = $this->stored(uuid: 'obj-1');
		$locked->setLocked(['user' => 'someone-else', 'expiration' => '2999-01-01T00:00:00+00:00']);

		$archived = $this->stored(uuid: 'obj-1');
		$archived->setArchived(['archivedAt' => '2026-01-01T00:00:00+00:00']);

		$frozen = $this->stored(uuid: 'obj-1');
		$frozen->setFrozen(['frozenAt' => '2026-01-01T00:00:00+00:00']);

		$refusals = [];
		foreach (['locked' => $locked, 'archived' => $archived, 'frozen' => $frozen] as $name => $stored) {
			try {
				$this->existing(handler: $this->handler(refuseTable: null, found: $stored), uuid: 'obj-1');
				$refusals[$name] = 'allowed';
			} catch (LockedException $e) {
				$refusals[$name] = 'locked';
			} catch (ObjectStateWriteException $e) {
				$refusals[$name] = 'state';
			}
		}

		self::assertSame(['locked' => 'locked', 'archived' => 'state', 'frozen' => 'state'], $refusals);
	}//end testALockedArchivedOrFrozenObjectIsRefused()

	/**
	 * Run saveResolvedObject() on a persisting, multitenant save into a read-only sourced schema.
	 *
	 * @param SaveObject  $handler The handler.
	 * @param string|null $uuid    The uuid the caller named.
	 *
	 * @return void
	 */
	private function saveResolved(SaveObject $handler, ?string $uuid): void {
		$schema = $this->schema();
		$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'dbal-source']]);

		$call = $this->call(uuid: $uuid);
		$call->schema = $schema;

		$method = new ReflectionMethod(SaveObject::class, 'saveResolvedObject');
		$method->setAccessible(true);
		$method->invoke($handler, $call);
	}//end saveResolved()

	/**
	 * Run existingObjectForSave() for a uuid.
	 *
	 * @param SaveObject $handler The handler.
	 * @param string     $uuid    The uuid.
	 *
	 * @return ObjectEntity|null The stored object, or null.
	 */
	private function existing(SaveObject $handler, string $uuid): ?ObjectEntity {
		$method = new ReflectionMethod(SaveObject::class, 'existingObjectForSave');
		$method->setAccessible(true);

		return $method->invoke($handler, $this->call(uuid: $uuid));
	}//end existing()

	/**
	 * A save call into register 4, schema 41.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return ObjectSaveCall The call.
	 */
	private function call(?string $uuid): ObjectSaveCall {
		$call = new ObjectSaveCall();
		$call->register   = $this->register();
		$call->registerId = 4;
		$call->schema     = $this->schema();
		$call->schemaId   = 41;
		$call->data       = ['name' => 'x'];
		$call->uuid       = $uuid;
		$call->flags      = new ObjectSaveFlags();

		return $call;
	}//end call()

	/**
	 * A stored object.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function stored(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}//end stored()

	/**
	 * A constructor-less handler with the collaborators these paths read.
	 *
	 * @param string|null             $refuseTable The table the guard refuses, or null to allow.
	 * @param ObjectEntity|false|null $found       What the mapper finds; false for "does not exist".
	 *
	 * @return SaveObject The handler.
	 */
	private function handler(?string $refuseTable, ObjectEntity|false|null $found): SaveObject {
		$this->asked = $this->audited = $this->warnings = $this->finds = [];

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserActiveOrganisations')->willReturn(['org-consumer']);

		$shares = $this->createMock(SharedMasterDataService::class);
		$shares->method('assertWritable')->willReturnCallback(
			function (DeclaredRow $row, array $activeOrgUuids) use ($refuseTable): void {
				$this->asked[] = [$row->table, $row->id, $activeOrgUuids];
				if ($row->table === $refuseTable) {
					throw new SharedMasterDataWriteException(holderUuid: 'org-holder');
				}
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === SharedMasterDataService::class) ? $shares : throw new RuntimeException('no '.$id)
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('recordSharedMasterDataRefusal')->willReturnCallback(
			function (SharedMasterDataRefusal $refusal): void {
				$options = $refusal->options;
				$this->audited[] = [
					'holderOrganisation' => $refusal->holderOrganisation,
					'sourceOrganisation' => $refusal->sourceOrganisation,
					'resourceType' => $refusal->resourceType,
					'context' => $options->context,
					'register' => $options->target->register,
					'schema' => $options->target->schema,
					'objectUuid' => $options->target->objectUuid,
					'actorId' => $options->actor->id,
					'actorReference' => $options->actor->name,
				];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message, $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (
				string|int $identifier,
				?Register $register = null,
				?Schema $schema = null,
				bool $includeDeleted = false,
				bool $_rbac = true,
				bool $_multitenancy = true
			) use ($found): ObjectEntity {
				$this->finds[] = [
					'identifier' => $identifier,
					'register' => $register?->getId(),
					'schema' => $schema?->getId(),
					'includeDeleted' => $includeDeleted,
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
				];
				if ($found instanceof ObjectEntity) {
					return $found;
				}

				throw new DoesNotExistException('none');
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$reflection = new ReflectionClass(SaveObject::class);
		$handler    = $reflection->newInstanceWithoutConstructor();
		$values     = [
			'organisationService' => $organisations,
			'container' => $container,
			'auditTrailMapper' => $audit,
			'logger' => $logger,
			'objectEntityMapper' => $mapper,
			'userSession' => $session,
			'appConfig' => null,
			'objectSourceRegistry' => null,
			'runContext' => null,
		];
		foreach ($values as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($handler, $value);
		}

		return $handler;
	}//end handler()

	/**
	 * The schema.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(41);
		$schema->setSlug('product');

		return $schema;
	}//end schema()

	/**
	 * The register.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(4);
		$register->setSlug('catalogue');

		return $register;
	}//end register()
}//end class
