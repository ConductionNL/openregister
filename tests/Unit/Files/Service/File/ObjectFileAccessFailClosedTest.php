<?php

/**
 * ObjectFileAccess fails closed: an object that is not found, has no schema or
 * no identifier, a schema lookup or authorization resolution that throws and a
 * permission check that throws all deny. The open outcomes next to them (a
 * readable object whose schema names no file verbs, a matrix without file rows
 * falling back to update) are pinned too.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Files\Service\File\FileVerbCheck;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckOptions;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ObjectFileAccess::class)]
#[UsesClass(PermissionAsk::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectFileAccessDeniedException::class)]
#[UsesClass(FileVerbCheck::class)]
#[UsesClass(ObjectRef::class)]
#[UsesClass(ObjectFindOptions::class)]
#[UsesClass(WriteCause::class)]
#[UsesClass(BulkBatchDefaults::class)]
#[UsesClass(BulkSaveOptions::class)]
#[UsesClass(CauseFrame::class)]
#[UsesClass(ObjectFindTarget::class)]
#[UsesClass(ObjectSaveOptions::class)]
#[UsesClass(PermissionCheckOptions::class)]
#[UsesClass(SaveActor::class)]
#[UsesClass(SavePolicy::class)]
class ObjectFileAccessFailClosedTest extends TestCase {

	/** @var ObjectOperationsInterface&MockObject */
	private ObjectOperationsInterface $objects;

	/** @var PermissionHandlerInterface&MockObject */
	private PermissionHandlerInterface $permissions;

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var ObjectFileAccess The subject under test. */
	private ObjectFileAccess $access;

	protected function setUp(): void {
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->access      = new ObjectFileAccess($this->objects, $this->permissions, $this->schemas);
	}//end setUp()

	/**
	 * An object of schema 7 owned by ann.
	 *
	 * @param string|null $schema The schema id.
	 * @param string|null $uuid   The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function object(?string $schema = '7', ?string $uuid = 'obj-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('3');
		$object->setSchema($schema);
		$object->setUuid($uuid);
		$object->setOwner('ann');

		return $object;
	}//end object()

	/**
	 * Schema 7 resolves to a schema.
	 *
	 * @return Schema&MockObject
	 */
	private function schemaSeven(): Schema {
		$schema = $this->createMock(Schema::class);
		$this->schemas->method('find')->with('7', [], false, false)->willReturn($schema);

		return $schema;
	}//end schemaSeven()

	/**
	 * The authorization blocks the schema resolves to.
	 *
	 * @param array|null $authorization The effective block.
	 * @param array|null $declared      The declared block.
	 *
	 * @return void
	 */
	private function authorization(?array $authorization, ?array $declared): void {
		$this->permissions->method('resolveAuthorization')->willReturn($authorization);
		$this->permissions->method('resolveDeclaredAuthorization')->willReturn($declared);
	}//end authorization()

	/**
	 * The http status of the denial the call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return int
	 */
	private function denial(callable $call): int {
		try {
			$call();
		} catch (ObjectFileAccessDeniedException $e) {
			return $e->getHttpStatus();
		}

		$this->fail('Expected an ObjectFileAccessDeniedException');
	}//end denial()

	public function testAnObjectTheLookupDoesNotReturnIsNotReadable(): void {
		$this->objects->expects($this->once())->method('find')->willReturn(null);
		$this->schemas->expects($this->never())->method('find');

		$status = $this->denial(fn () => $this->access->readable(ref: new ObjectRef(register: 3, schema: 7, id: 'obj-1')));

		$this->assertSame(ObjectFileAccessDeniedException::NOT_READABLE, $status);
	}//end testAnObjectTheLookupDoesNotReturnIsNotReadable()

	public function testAReadableObjectWithoutASchemaIsNotReadableForFiles(): void {
		$this->objects->method('find')->willReturn(self::object(schema: ''));
		$this->schemas->expects($this->never())->method('find');
		$this->permissions->expects($this->never())->method('resolveAuthorization');

		$status = $this->denial(fn () => $this->access->changeable(ref: new ObjectRef(register: 3, schema: 7, id: 'obj-1')));

		$this->assertSame(ObjectFileAccessDeniedException::NOT_READABLE, $status);
	}//end testAReadableObjectWithoutASchemaIsNotReadableForFiles()

	public function testAnObjectWithoutUuidOrIdIsNeverLookedUp(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertFalse($this->access->mayRead(object: self::object(uuid: null)));
	}//end testAnObjectWithoutUuidOrIdIsNeverLookedUp()

	public function testAFoundObjectWhoseSchemaNamesNoFileVerbsMayBeReadWithTheGivenTenancy(): void {
		$found = self::object();
		$this->objects->expects($this->once())->method('find')->with(
			'obj-1',
			$this->callback(static fn (ObjectFindOptions $o): bool => $o->register === '3' && $o->schema === '7'),
			true,
			false
		)->willReturn($found);
		$this->schemaSeven();
		$this->authorization(authorization: ['read' => ['public']], declared: null);
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertTrue($this->access->mayRead(object: self::object(), multitenancy: false));
	}//end testAFoundObjectWhoseSchemaNamesNoFileVerbsMayBeReadWithTheGivenTenancy()

	public function testASchemaLookupThatThrowsDeniesReading(): void {
		$this->objects->method('find')->willReturn(self::object());
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->permissions->expects($this->never())->method('resolveAuthorization');

		$this->assertFalse($this->access->mayRead(object: self::object()));
	}//end testASchemaLookupThatThrowsDeniesReading()

	public function testUpdateIsDeniedWithoutASchemaOrWhenTheSchemaLookupThrows(): void {
		$this->schemas->expects($this->once())->method('find')->willThrowException(new RuntimeException('db down'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->access->mayUpdate(object: self::object(schema: null)));
		$this->assertFalse($this->access->mayUpdate(object: self::object()));
	}//end testUpdateIsDeniedWithoutASchemaOrWhenTheSchemaLookupThrows()

	public function testChangingFilesIsDeniedWithoutASchema(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertFalse($this->access->mayChangeFiles(object: self::object(schema: '')));
	}//end testChangingFilesIsDeniedWithoutASchema()

	public function testAnAuthorizationThatCannotBeResolvedDeniesChangingFiles(): void {
		$this->schemaSeven();
		$this->permissions->method('resolveAuthorization')->willThrowException(new RuntimeException('bad block'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->access->mayChangeFiles(object: self::object(), verb: ObjectFileAccess::DELETE_FILES));
	}//end testAnAuthorizationThatCannotBeResolvedDeniesChangingFiles()

	public function testAMatrixWithoutFileRowsFallsBackToTheUpdatePermission(): void {
		$schema = $this->schemaSeven();
		$this->authorization(authorization: null, declared: ['matrix' => ['rows' => 'not-a-list']]);
		$object = self::object();
		$this->permissions->expects($this->once())->method('hasPermission')
			->with(
				$this->callback(
					static fn (PermissionAsk $ask): bool => $ask->schema === $schema
						&& $ask->action === 'update'
						&& $ask->userId === null
						&& $ask->objectOwner === 'ann'
						&& $ask->object === $object
				),
				true
			)
			->willReturn(true);

		$this->assertTrue($this->access->mayChangeFiles(object: $object));
	}//end testAMatrixWithoutFileRowsFallsBackToTheUpdatePermission()

	public function testAFileVerbCheckThatThrowsDenies(): void {
		$schema = $this->schemaSeven();
		$this->authorization(authorization: ['addFiles' => ['editors']], declared: null);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with(
				$this->callback(
					static fn (PermissionAsk $ask): bool => $ask->schema === $schema
						&& $ask->action === ObjectFileAccess::ADD_FILES
				)
			)
			->willThrowException(new RuntimeException('group backend down'));

		$this->assertFalse($this->access->mayChangeFiles(object: self::object()));
	}//end testAFileVerbCheckThatThrowsDenies()
}//end class
