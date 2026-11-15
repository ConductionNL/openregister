<?php

/**
 * ObjectCollections: an object's collections and the members a reader may access.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Collaboration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Collaboration;

use OCA\OpenRegister\Collaboration\ObjectCollections;
use OCA\OpenRegister\Collaboration\ObjectResourceProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Collaboration\Resources\ICollection;
use OCP\Collaboration\Resources\IManager;
use OCP\Collaboration\Resources\IResource;
use OCP\Collaboration\Resources\ResourceException;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reading an object's collections.
 */
#[CoversClass(ObjectCollections::class)]
class ObjectCollectionsTest extends TestCase {

	private const UUID = '5f0c2a6e-1b2c-4d3e-8f9a-0b1c2d3e4f5a';

	/**
	 * The case object in register 3, schema 7.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setUuid(self::UUID);
		return $object;
	}//end object()

	/**
	 * A resource of a type and id, readable or not by the reader.
	 *
	 * @param string $type       The type.
	 * @param string $id         The id.
	 * @param bool   $accessible Whether the reader may access it.
	 *
	 * @return IResource
	 */
	private function resource(string $type, string $id, bool $accessible = true): IResource {
		$resource = $this->createMock(IResource::class);
		$resource->method('getType')->willReturn($type);
		$resource->method('getId')->willReturn($id);
		$resource->method('canAccess')->willReturn($accessible);
		$resource->method('getRichObject')->willReturn(['type' => $type, 'id' => $id, 'name' => $type . ' ' . $id]);
		return $resource;
	}//end resource()

	/**
	 * A collection holding the given resources.
	 *
	 * @param int             $id         The id.
	 * @param string          $name       The name.
	 * @param list<IResource> $resources  The members.
	 * @param bool            $accessible Whether the reader may access it.
	 *
	 * @return ICollection
	 */
	private function collection(int $id, string $name, array $resources, bool $accessible = true): ICollection {
		$collection = $this->createMock(ICollection::class);
		$collection->method('getId')->willReturn($id);
		$collection->method('getName')->willReturn($name);
		$collection->method('getResources')->willReturn($resources);
		$collection->method('canAccess')->willReturn($accessible);
		return $collection;
	}//end collection()

	/**
	 * The reader with the object's resource in the given collections.
	 *
	 * @param list<ICollection> $collections The collections.
	 * @param bool              $mayJoin     Whether the schema opts in.
	 * @param IUser             $reader      The reader.
	 *
	 * @return ObjectCollections
	 */
	private function reader(array $collections, bool $mayJoin, IUser $reader): ObjectCollections {
		$own = $this->createMock(IResource::class);
		$own->method('getType')->willReturn(ObjectResourceProvider::TYPE);
		$own->method('getId')->willReturn('3/7/' . self::UUID);
		$own->method('getCollections')->willReturn($collections);
		$manager = $this->createMock(IManager::class);
		$manager->method('getResourceForUser')->with(ObjectResourceProvider::TYPE, '3/7/' . self::UUID, $reader)->willReturn($own);
		$provider = $this->createMock(ObjectResourceProvider::class);
		$provider->method('mayJoin')->with(7)->willReturn($mayJoin);

		return new ObjectCollections(manager: $manager, provider: $provider);
	}//end reader()

	/**
	 * A case in a collection with a folder and a conversation returns both, never itself.
	 *
	 * @return void
	 */
	public function testTheFolderAndTheConversationAreReturned(): void {
		$reader = $this->createMock(IUser::class);
		$collection = $this->collection(
			id: 12,
			name: 'Dossier Stadskantoor',
			resources: [
				$this->resource(ObjectResourceProvider::TYPE, '3/7/' . self::UUID),
				$this->resource('file', '901'),
				$this->resource('room', 'abc123'),
			]
		);

		$result = $this->reader(collections: [$collection], mayJoin: true, reader: $reader)->forObject(object: $this->object(), reader: $reader);

		$this->assertSame(
			[[
				'id' => 12,
				'name' => 'Dossier Stadskantoor',
				'members' => [
					['type' => 'file', 'id' => '901', 'name' => 'file 901'],
					['type' => 'room', 'id' => 'abc123', 'name' => 'room abc123'],
				],
			]],
			$result
		);
	}

	/**
	 * A member the reader may not access, and a collection they may not access, are left out.
	 *
	 * @return void
	 */
	public function testWhatTheReaderMayNotAccessIsLeftOut(): void {
		$reader = $this->createMock(IUser::class);
		$visible = $this->collection(id: 1, name: 'Open', resources: [$this->resource('file', '1'), $this->resource('file', '2', accessible: false)]);
		$hidden = $this->collection(id: 2, name: 'Closed', resources: [$this->resource('file', '3')], accessible: false);

		$result = $this->reader(collections: [$visible, $hidden], mayJoin: true, reader: $reader)->forObject(object: $this->object(), reader: $reader);

		$this->assertSame([1], array_column($result, 'id'));
		$this->assertSame(['1'], array_column($result[0]['members'], 'id'));
	}

	/**
	 * An object whose schema has not opted in has no collections, and none are looked up.
	 *
	 * @return void
	 */
	public function testAnOptedOutSchemaHasNoCollections(): void {
		$reader = $this->createMock(IUser::class);
		$manager = $this->createMock(IManager::class);
		$manager->expects($this->never())->method('getResourceForUser');
		$provider = $this->createMock(ObjectResourceProvider::class);
		$provider->method('mayJoin')->willReturn(false);

		$this->assertSame([], (new ObjectCollections(manager: $manager, provider: $provider))->forObject(object: $this->object(), reader: $reader));
	}

	/**
	 * A resource the platform cannot give the reader means no collections.
	 *
	 * @return void
	 */
	public function testAnUnresolvableResourceHasNoCollections(): void {
		$reader = $this->createMock(IUser::class);
		$manager = $this->createMock(IManager::class);
		$manager->method('getResourceForUser')->willThrowException(new ResourceException('not found'));
		$provider = $this->createMock(ObjectResourceProvider::class);
		$provider->method('mayJoin')->willReturn(true);

		$this->assertSame([], (new ObjectCollections(manager: $manager, provider: $provider))->forObject(object: $this->object(), reader: $reader));
	}

	/**
	 * The resource id is register/schema/uuid, and an object missing one has none.
	 *
	 * @return void
	 */
	public function testTheResourceId(): void {
		$this->assertSame('3/7/' . self::UUID, ObjectCollections::resourceId(object: $this->object()));
		$this->assertNull(ObjectCollections::resourceId(object: new ObjectEntity()));
	}
}
