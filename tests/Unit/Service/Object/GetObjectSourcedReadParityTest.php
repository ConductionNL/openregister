<?php

declare(strict_types=1);

/**
 * Read access parity for objects served by an object source
 *
 * A caller who may not read an object that an object source (an external
 * database, say) holds gets the same "not found" as for an id the source does
 * not hold, so no difference in the answer tells a prober which rows exist.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md#requirement-read-access-parity-and-no-enumeration-oracle
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadHistoryInterface;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\GetObjectSources;
use OCA\OpenRegister\Service\Object\ObjectFindRequest;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Object\ReadScope;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GetObject::find() on a schema served by an object source, with and without the read right.
 */
#[CoversClass(GetObject::class)]
#[UsesClass(GetObjectSources::class)]
#[UsesClass(ObjectFindRequest::class)]
#[UsesClass(ReadScope::class)]
#[UsesClass(PermissionAsk::class)]
#[UsesClass(SourcedTable::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Register::class)]
#[UsesClass(ObjectEntity::class)]
class GetObjectSourcedReadParityTest extends TestCase {
	/** @var list<PermissionAsk> The read questions the permission check was asked. */
	private array $asked = [];

	/** @var bool Whether the permission check grants the read. */
	private bool $granted = true;

	/**
	 * A GetObject whose schema is served by a source holding one row, `permit-1`, owned by `ada`.
	 *
	 * @param bool $withPermissions Whether a permission check is wired.
	 *
	 * @return GetObject
	 */
	private function getObject(bool $withPermissions = true): GetObject {
		$provider = new class implements ObjectSourceProvider {
			public function getId(): string {
				return 'dbal-source';
			}

			public function isEnabled(): bool {
				return true;
			}

			public function find(SourcedTable $source, string $id): ?ObjectEntity {
				if ($id !== 'permit-1') {
					return null;
				}

				$row = new ObjectEntity();
				$row->setUuid('permit-1');
				$row->setOwner('ada');
				return $row;
			}

			public function findAll(SourcedTable $source, array $query = []): array {
				return [];
			}

			public function count(SourcedTable $source, array $query = []): int {
				return 0;
			}
		};
		$registry = $this->createMock(ObjectSourceRegistry::class);
		$registry->method('get')->willReturn($provider);

		$permissions = null;
		if ($withPermissions === true) {
			$permissions = $this->createMock(PermissionCheckInterface::class);
			$permissions->method('hasPermission')->willReturnCallback(
				function (PermissionAsk $ask, bool $_rbac = true): bool {
					$this->asked[] = $ask;
					return $this->granted;
				}
			);
		}

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->expects($this->never())->method('find');

		return new GetObject(
			objectMapper: $mapper,
			auditTrailMapper: $this->createMock(AuditTrailMapperInterface::class),
			readHistory: $this->createMock(ReadHistoryInterface::class),
			sources: new GetObjectSources(objectSourceRegistry: $registry, logger: new NullLogger(), permissions: $permissions)
		);
	}//end getObject()

	/**
	 * The scope of a schema served by the DBAL source.
	 *
	 * @return ReadScope
	 */
	private static function scope(): ReadScope {
		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('permits');
		$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'dbal-source', 'config' => ['table' => 'permits']]]);

		$register = new Register();
		$register->setId(2);

		return new ReadScope(register: $register, schema: $schema);
	}//end scope()

	/**
	 * The message a read ends with, or the uuid it returned.
	 *
	 * @param GetObject $getObject The reader.
	 * @param string    $id        The id to read.
	 * @param bool      $rbac      Whether the read checks the caller's rights.
	 *
	 * @return string The uuid, or `404: <message>`.
	 */
	private static function read(GetObject $getObject, string $id, bool $rbac = true): string {
		try {
			return (string) $getObject->find(request: new ObjectFindRequest(id: $id, extend: null, files: false, rbac: $rbac, multitenancy: true, audit: false), scope: self::scope())->getUuid();
		} catch (DoesNotExistException $e) {
			return '404: ' . $e->getMessage();
		}
	}//end read()

	/**
	 * A reader with the right gets the object, asked as a read of that object by its owner's rule.
	 *
	 * @return void
	 */
	public function testAReaderWithTheRightGetsTheObject(): void {
		$this->granted = true;

		$this->assertSame('permit-1', self::read(getObject: $this->getObject(), id: 'permit-1'));
		$this->assertCount(1, $this->asked);
		$this->assertSame('read', $this->asked[0]->action);
		$this->assertSame('ada', $this->asked[0]->objectOwner);
		$this->assertSame('permit-1', $this->asked[0]->object?->getUuid());
		$this->assertSame('permits', $this->asked[0]->schema->getSlug());
	}//end testAReaderWithTheRightGetsTheObject()

	/**
	 * Denied and absent answer the same: a refused existing row reads exactly like an id the source lacks.
	 *
	 * @return void
	 */
	public function testDeniedAndAbsentAreIndistinguishable(): void {
		$this->granted = false;
		$getObject     = $this->getObject();

		$denied = self::read(getObject: $getObject, id: 'permit-1');
		$absent = self::read(getObject: $getObject, id: 'permit-9');

		$this->assertSame('404: Object permit-1 not found', $denied);
		$this->assertSame('404: Object permit-9 not found', $absent);
		$this->assertSame(
			str_replace('permit-1', '{id}', $denied),
			str_replace('permit-9', '{id}', $absent)
		);
	}//end testDeniedAndAbsentAreIndistinguishable()

	/**
	 * An id the source lacks is not put to the permission check at all: there is nothing to ask about.
	 *
	 * @return void
	 */
	public function testAnAbsentIdAsksNoQuestion(): void {
		$this->granted = true;

		$this->assertSame('404: Object permit-9 not found', self::read(getObject: $this->getObject(), id: 'permit-9'));
		$this->assertSame([], $this->asked);
	}//end testAnAbsentIdAsksNoQuestion()

	/**
	 * A read with RBAC off (a system context) is not checked, as for a native schema.
	 *
	 * @return void
	 */
	public function testAReadWithoutRbacIsNotChecked(): void {
		$this->granted = false;

		$this->assertSame('permit-1', self::read(getObject: $this->getObject(), id: 'permit-1', rbac: false));
		$this->assertSame([], $this->asked);
	}//end testAReadWithoutRbacIsNotChecked()

	/**
	 * Without a permission check wired the read answers as before and leaves the check to the caller.
	 *
	 * @return void
	 */
	public function testWithoutAPermissionCheckTheReadIsUnchanged(): void {
		$this->assertSame('permit-1', self::read(getObject: $this->getObject(withPermissions: false), id: 'permit-1'));
	}//end testWithoutAPermissionCheckTheReadIsUnchanged()
}//end class
