<?php

/**
 * LinkedEntityServiceWriteAccessGoldenTest
 *
 * Pins which link writes LinkedEntityService allows and which it refuses, and
 * that a refused write never reaches the mapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\LinkedEntityIntegrations;
use OCA\OpenRegister\Service\LinkedEntityMappers;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\EntityLink;

/**
 * Allowed and refused outcomes of the linked-entity write paths.
 *
 * @covers \OCA\OpenRegister\Service\LinkedEntityService
 */
class LinkedEntityServiceWriteAccessGoldenTest extends TestCase {

	/** @var MagicMapperInterface&MockObject */
	private MagicMapperInterface $magicMapper;

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemaMapper;

	/** @var RegisterMapperInterface&MockObject */
	private RegisterMapperInterface $registerMapper;

	/** @var PermissionHandlerInterface&MockObject */
	private PermissionHandlerInterface $permissionHandler;

	/** @var Schema */
	private Schema $schema;

	private LinkedEntityService $service;

	protected function setUp(): void {
		$this->magicMapper = $this->createMock(MagicMapperInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->permissionHandler = $this->createMock(PermissionHandlerInterface::class);
		$registry = $this->createMock(IntegrationRegistry::class);
		$registry->method('isValidIntegrationId')->willReturn(true);
		$this->schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($this->schema);

		$this->service = new LinkedEntityService(
			new LinkedEntityMappers(
				new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this->magicMapper),
				$this->createMock(OrganisationMapperInterface::class)
			),
			new LinkedEntityIntegrations(
				$registry,
				$this->permissionHandler,
				$this->createMock(DeepLinkRegistryService::class)
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * An object with a schema, an owner and linked notes.
	 *
	 * @param array $notes The linked note ids.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $notes): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('7');
		$object->setOwner('alice');
		$object->setNotes($notes);
		$this->magicMapper->method('find')->willReturn($object);
		return $object;
	}//end object()

	/**
	 * Expect exactly one `update` permission check on the object, permitted or refused.
	 *
	 * @param ObjectEntity $object The object.
	 * @param bool         $allow  Whether the check passes.
	 *
	 * @return void
	 */
	private function expectUpdateCheck(ObjectEntity $object, bool $allow): void {
		$expectation = $this->permissionHandler->expects($this->once())
			->method('checkPermission')
			->with($this->identicalTo($this->schema), 'update', null, 'alice', true, $this->identicalTo($object));
		if ($allow === false) {
			$expectation->willThrowException(new NotAuthorizedException('denied'));
		}
	}//end expectUpdateCheck()

	public function testAddLinkAllowedWritesAfterTheCheck(): void {
		$object = $this->object(notes: ['n-1']);
		$this->expectUpdateCheck(object: $object, allow: true);
		$this->magicMapper->expects($this->once())->method('update')->with($this->identicalTo($object));

		$this->assertSame(['n-1', 'n-2'], $this->service->addLink('o-1', new EntityLink('notes', 'n-2')));
		$this->assertSame(['n-1', 'n-2'], $object->getNotes());
	}//end testAddLinkAllowedWritesAfterTheCheck()

	public function testAddLinkRefusedNeverWrites(): void {
		$object = $this->object(notes: ['n-1']);
		$this->expectUpdateCheck(object: $object, allow: false);
		$this->magicMapper->expects($this->never())->method('update');

		try {
			$this->service->addLink('o-1', new EntityLink('notes', 'n-2'));
			$this->fail('A refused write must throw.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('denied', $e->getMessage());
		}

		$this->assertSame(['n-1'], $object->getNotes());
	}//end testAddLinkRefusedNeverWrites()

	public function testAddLinkAlreadyPresentNeitherChecksNorWrites(): void {
		$this->object(notes: ['n-1']);
		$this->permissionHandler->expects($this->never())->method('checkPermission');
		$this->magicMapper->expects($this->never())->method('update');

		$this->assertSame(['n-1'], $this->service->addLink('o-1', new EntityLink('notes', 'n-1')));
	}//end testAddLinkAlreadyPresentNeitherChecksNorWrites()

	public function testAddLinkWithoutSchemaIsRefused(): void {
		$object = new ObjectEntity();
		$object->setNotes([]);
		$this->magicMapper->method('find')->willReturn($object);
		$this->permissionHandler->expects($this->never())->method('checkPermission');
		$this->magicMapper->expects($this->never())->method('update');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot resolve schema for linked-entity write permission check.');
		$this->service->addLink('o-1', new EntityLink('notes', 'n-1'));
	}//end testAddLinkWithoutSchemaIsRefused()

	public function testRemoveLinkAllowedWritesAfterTheCheck(): void {
		$object = $this->object(notes: ['n-1', 'n-2', 'n-1']);
		$this->expectUpdateCheck(object: $object, allow: true);
		$this->magicMapper->expects($this->once())->method('update')->with($this->identicalTo($object));

		$this->assertSame(['n-2'], $this->service->removeLink('o-1', new EntityLink(type: 'notes', entityId: 'n-1')));
		$this->assertSame(['n-2'], $object->getNotes());
	}//end testRemoveLinkAllowedWritesAfterTheCheck()

	public function testRemoveLinkRefusedNeverWrites(): void {
		$object = $this->object(notes: ['n-1']);
		$this->expectUpdateCheck(object: $object, allow: false);
		$this->magicMapper->expects($this->never())->method('update');

		try {
			$this->service->removeLink('o-1', new EntityLink(type: 'notes', entityId: 'n-1'));
			$this->fail('A refused write must throw.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('denied', $e->getMessage());
		}

		$this->assertSame(['n-1'], $object->getNotes());
	}//end testRemoveLinkRefusedNeverWrites()

	public function testRemoveLinkOfAbsentIdStillChecksAndWrites(): void {
		$object = $this->object(notes: ['n-2']);
		$this->expectUpdateCheck(object: $object, allow: true);
		$this->magicMapper->expects($this->once())->method('update');

		$this->assertSame(['n-2'], $this->service->removeLink('o-1', new EntityLink(type: 'notes', entityId: 'n-9')));
	}//end testRemoveLinkOfAbsentIdStillChecksAndWrites()

	public function testAddLinkToRegisterAppendsOnceAndNotFoundThrows(): void {
		$register = new Register();
		$register->setNotes(['n-1']);
		$this->registerMapper->method('findAll')->willReturnCallback(
			fn (...$args) => (($args['filters']['uuid'] ?? $args[2]['uuid'] ?? null) === 'r-1') ? [$register] : []
		);
		$this->registerMapper->expects($this->once())->method('update')->with($this->identicalTo($register));
		$this->permissionHandler->expects($this->never())->method('checkPermission');

		$this->assertSame(['n-1', 'n-2'], $this->service->addLinkToRegister('r-1', new EntityLink('notes', 'n-2')));
		$this->assertSame(['n-1', 'n-2'], $this->service->addLinkToRegister('r-1', new EntityLink('notes', 'n-2')));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Register not found: r-9');
		$this->service->addLinkToRegister('r-9', new EntityLink('notes', 'n-2'));
	}//end testAddLinkToRegisterAppendsOnceAndNotFoundThrows()

	public function testAddLinkToSchemaAppendsOnceAndNotFoundThrows(): void {
		$schema = new Schema();
		$schema->setNotes(['n-1']);
		$this->schemaMapper->method('findAll')->willReturnCallback(
			fn (...$args) => (($args['filters']['uuid'] ?? $args[2]['uuid'] ?? null) === 's-1') ? [$schema] : []
		);
		$this->schemaMapper->expects($this->once())->method('update')->with($this->identicalTo($schema));

		$this->assertSame(['n-1', 'n-2'], $this->service->addLinkToSchema('s-1', new EntityLink('notes', 'n-2')));
		$this->assertSame(['n-1', 'n-2'], $this->service->addLinkToSchema('s-1', new EntityLink('notes', 'n-2')));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Schema not found: s-9');
		$this->service->addLinkToSchema('s-9', new EntityLink('notes', 'n-2'));
	}//end testAddLinkToSchemaAppendsOnceAndNotFoundThrows()
}//end class
