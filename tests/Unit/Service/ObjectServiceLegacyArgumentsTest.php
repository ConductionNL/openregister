<?php

/**
 * ObjectServiceLegacyArgumentsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\RenderSelection;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRunStep;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use OCA\OpenRegister\Kernel\Contracts\Value\UnlockRequest;
use OCA\OpenRegister\Service\Object\AccessFlags;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RenderPath;
use OCA\OpenRegister\Service\Object\RenderPreloads;
use OCA\OpenRegister\Service\Object\RenderRequest;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Each published object method gives the same call in its legacy argument form as in its options form.
 *
 * The legacy form is what consuming apps call with (`register:`, `schema:`, `uuid:` ...);
 * the options form is the value-object form. Both reach the same seam with the same value.
 */
class ObjectServiceLegacyArgumentsTest extends TestCase {

	/**
	 * An ObjectService whose options-form seams record what they receive.
	 *
	 * @return ObjectService&RecordingSeams
	 */
	private function service(): ObjectService {
		return new class extends ObjectService implements RecordingSeams {
			/**
			 * The seam calls, in order.
			 *
			 * @var list<array{0: string, 1: array<string, mixed>}>
			 */
			public array $calls = [];

			public function __construct() {
			}

			public function calls(): array {
				return $this->calls;
			}

			protected function findWithOptions(int|string $id, ObjectFindOptions $options, AccessFlags $access): ?ObjectEntity {
				$this->calls[] = ['find', ['id' => $id, 'options' => $options, 'access' => $access]];
				return new ObjectEntity();
			}

			protected function findSilentWithOptions(string $id, ObjectFindOptions $options, AccessFlags $access): ObjectEntity {
				$this->calls[] = ['findSilent', ['id' => $id, 'options' => $options, 'access' => $access]];
				return new ObjectEntity();
			}

			protected function saveWithOptions(array|ObjectEntity $object, ObjectSaveOptions $options, AccessFlags $access): ObjectEntity {
				$this->calls[] = ['save', ['object' => $object, 'options' => $options, 'access' => $access]];
				return new ObjectEntity();
			}

			protected function deleteWithDeletion(ObjectDeletion $deletion, AccessFlags $access, bool $retentionSweep=false): bool {
				$this->calls[] = ['delete', ['deletion' => $deletion, 'access' => $access, 'retentionSweep' => $retentionSweep]];
				return true;
			}

			protected function saveManyWithOptions(array $objects, BulkSaveOptions $options, AccessFlags $access): array {
				$this->calls[] = ['saveMany', ['objects' => $objects, 'options' => $options, 'access' => $access]];
				return [];
			}

			protected function patchWithPatch(ObjectPatch $patch, AccessFlags $access): ObjectEntity {
				$this->calls[] = ['patch', ['patch' => $patch, 'access' => $access]];
				return new ObjectEntity();
			}
		};
	}//end service()

	/**
	 * Sets a private handler of ObjectService on the given instance.
	 *
	 * @param ObjectService $service  The service.
	 * @param string        $property The property.
	 * @param object        $handler  The handler.
	 *
	 * @return void
	 */
	private function setHandler(ObjectService $service, string $property, object $handler): void {
		\Closure::bind(function () use ($property, $handler): void {
			$this->{$property} = $handler;
		}, $service, ObjectService::class)();
	}//end setHandler()

	public function testSaveObjectLegacyNamedArgumentsBuildTheOptionsObject(): void {
		$user = $this->createMock(IUser::class);

		$legacy = $this->service();
		$legacy->saveObject(
			object: ['title' => 'x'],
			extend: ['owner'],
			register: 'reg',
			schema: 7,
			uuid: 'u-1',
			_rbac: false,
			_multitenancy: false,
			silent: true,
			_validation: false,
			uploadedFiles: ['f' => []],
			currentUser: $user,
			failIfExists: true,
			_unowned: true,
			_dedupOverride: true,
			_owner: 'alice'
		);

		$options = new ObjectSaveOptions(
			target: new ObjectFindTarget(register: 'reg', schema: 7),
			uuid: 'u-1',
			extend: ['owner'],
			uploadedFiles: ['f' => []],
			policy: new SavePolicy(silent: true, validation: false, failIfExists: true, dedupOverride: true),
			actor: new SaveActor(currentUser: $user, owner: 'alice', unowned: true)
		);
		$named = $this->service();
		$named->saveObject(object: ['title' => 'x'], options: $options, _rbac: false, _multitenancy: false);
		$positional = $this->service();
		$positional->saveObject(['title' => 'x'], $options, _rbac: false, _multitenancy: false);

		$this->assertEquals($named->calls(), $legacy->calls());
		$this->assertEquals($named->calls(), $positional->calls());
		$this->assertSame($options, $named->calls()[0][1]['options']);
	}

	public function testSaveObjectDefaultsMatchTheDefaultOptions(): void {
		$legacy = $this->service();
		$legacy->saveObject(object: ['a' => 1]);
		$named = $this->service();
		$named->saveObject(object: ['a' => 1], options: new ObjectSaveOptions());

		$this->assertEquals($named->calls(), $legacy->calls());
		$this->assertEquals(new AccessFlags(rbac: true, multitenancy: true), $legacy->calls()[0][1]['access']);
	}

	public function testFindLegacyArgumentsBuildTheOptionsObject(): void {
		$legacy = $this->service();
		$legacy->find(id: 'u-1', _extend: ['a'], files: true, register: 'r', schema: 's', _rbac: false, _multitenancy: false, _render: false, _audit: false);
		$positional = $this->service();
		$positional->find('u-1', ['a'], true, 'r', 's', false, false, false, false);

		$options = new ObjectFindOptions(register: 'r', schema: 's', extend: ['a'], files: true, render: false, audit: false);
		$named = $this->service();
		$named->find(id: 'u-1', options: $options, _rbac: false, _multitenancy: false);
		$inSlot = $this->service();
		$inSlot->find('u-1', $options, _rbac: false, _multitenancy: false);

		$this->assertEquals($named->calls(), $legacy->calls());
		$this->assertEquals($named->calls(), $positional->calls());
		$this->assertEquals($named->calls(), $inSlot->calls());
	}

	public function testFindSilentLegacyArgumentsBuildTheOptionsObject(): void {
		$legacy = $this->service();
		$legacy->findSilent(id: 'u-1', _extend: ['a'], files: true, register: 'r', schema: 's', _rbac: false, _multitenancy: false);

		$named = $this->service();
		$named->findSilent(
			id: 'u-1',
			options: new ObjectFindOptions(register: 'r', schema: 's', extend: ['a'], files: true),
			_rbac: false,
			_multitenancy: false
		);

		$this->assertEquals($named->calls(), $legacy->calls());
	}

	public function testDeleteObjectLegacyArgumentsBuildTheDeletion(): void {
		$user = $this->createMock(IUser::class);

		$legacy = $this->service();
		$legacy->deleteObject(uuid: 'u-1', register: 'r', schema: 's', _rbac: false, _multitenancy: false, _retentionSweep: true, currentUser: $user, permanent: true);

		$deletion = new ObjectDeletion(uuid: 'u-1', target: new ObjectFindTarget(register: 'r', schema: 's'), currentUser: $user, permanent: true);
		$named = $this->service();
		$named->deleteObject(deletion: $deletion, _rbac: false, _multitenancy: false, _retentionSweep: true);
		$inSlot = $this->service();
		$inSlot->deleteObject($deletion, _rbac: false, _multitenancy: false, _retentionSweep: true);

		$this->assertEquals($named->calls(), $legacy->calls());
		$this->assertEquals($named->calls(), $inSlot->calls());
	}

	public function testDeleteObjectWithoutAUuidIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->deleteObject();
	}

	public function testSaveObjectsLegacyArgumentsBuildTheOptionsObject(): void {
		$legacy = $this->service();
		$legacy->saveObjects(objects: [['a' => 1]], register: 'r', schema: 's', _rbac: false, _multitenancy: false, validation: true, events: true, deduplicateIds: false, enrich: false, _audit: false);

		$options = new BulkSaveOptions(defaults: new BulkBatchDefaults(register: 'r', schema: 's'), validation: true, events: true, audit: false);
		$named = $this->service();
		$named->saveObjects(objects: [['a' => 1]], options: $options, _rbac: false, _multitenancy: false);
		$inSlot = $this->service();
		$inSlot->saveObjects([['a' => 1]], $options, _rbac: false, _multitenancy: false);

		$this->assertEquals($named->calls(), $legacy->calls());
		$this->assertEquals($named->calls(), $inSlot->calls());
	}

	public function testPatchObjectLegacyArgumentsBuildThePatch(): void {
		$user = $this->createMock(IUser::class);

		$legacy = $this->service();
		$legacy->patchObject(objectId: 'u-1', data: ['a' => 1], register: 'r', schema: 's', _rbac: false, _multitenancy: false, currentUser: $user);

		$patch = new ObjectPatch(objectId: 'u-1', data: ['a' => 1], target: new ObjectFindTarget(register: 'r', schema: 's'), currentUser: $user);
		$named = $this->service();
		$named->patchObject(patch: $patch, _rbac: false, _multitenancy: false);
		$inSlot = $this->service();
		$inSlot->patchObject($patch, _rbac: false, _multitenancy: false);

		$this->assertEquals($named->calls(), $legacy->calls());
		$this->assertEquals($named->calls(), $inSlot->calls());
	}

	public function testPatchObjectWithoutDataIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->patchObject(objectId: 'u-1');
	}

	public function testLockObjectLegacyArgumentsBuildTheLockRequest(): void {
		$expected = new LockRequest(identifier: 'u-1', process: 'import', duration: 60, runStep: new LockRunStep(runUuid: 'run', nodeId: 'node'));
		$lock     = $this->createMock(LockHandler::class);
		$lock->expects($this->exactly(3))->method('lock')->with($expected, true)->willReturn(['locked' => true]);

		$service = $this->service();
		$this->setHandler($service, 'lockHandler', $lock);

		$this->assertSame(['locked' => true], $service->lockObject(identifier: 'u-1', process: 'import', duration: 60, advisory: true, runUuid: 'run', nodeId: 'node'));
		$service->lockObject(request: $expected, advisory: true);
		$service->lockObject($expected, advisory: true);
	}

	public function testUnlockObjectLegacyArgumentsBuildTheUnlockRequest(): void {
		$expected = new UnlockRequest(identifier: '42', advisory: true, runUuid: 'run');
		$lock     = $this->createMock(LockHandler::class);
		$lock->expects($this->exactly(3))->method('unlock')->with($expected)->willReturn(true);

		$service = $this->service();
		$this->setHandler($service, 'lockHandler', $lock);

		$this->assertTrue($service->unlockObject(identifier: 42, advisory: true, runUuid: 'run'));
		$service->unlockObject(request: $expected);
		$service->unlockObject($expected);
	}

	public function testRenderEntityLegacyListsBuildTheSelection(): void {
		$entity    = new ObjectEntity();
		$selection = new RenderSelection(extend: ['a'], filter: ['f'], fields: ['x'], unset: ['u']);
		$requests  = [];
		$render    = $this->createMock(RenderObject::class);
		$render->method('render')->willReturnCallback(
			function (ObjectEntity $entity, RenderRequest $request, bool $_rbac, bool $_multitenancy) use (&$requests): ObjectEntity {
				$requests[] = [$request, $_rbac, $_multitenancy];
				return $entity;
			}
		);

		$service = $this->service();
		$this->setHandler($service, 'renderHandler', $render);

		$service->renderEntity(entity: $entity, _extend: ['a'], filter: ['f'], fields: ['x'], unset: ['u'], _rbac: false, _multitenancy: false);
		$service->renderEntity(entity: $entity, selection: $selection, _rbac: false, _multitenancy: false);
		$service->renderEntity($entity, $selection, _rbac: false, _multitenancy: false);
		$service->renderEntity($entity, ['a'], 2);

		$this->assertEquals([RenderRequest::page(selection: $selection), false, false], $requests[0]);
		$this->assertEquals($requests[0], $requests[1]);
		$this->assertEquals($requests[0], $requests[2]);
		$this->assertEquals(
			new RenderRequest(
				selection: new RenderSelection(extend: ['a'], filter: [], fields: [], unset: []),
				preloads: new RenderPreloads(registers: [], schemas: [], objects: []),
				path: new RenderPath(depth: 2, visitedIds: [])
			),
			$requests[3][0]
		);
	}
}//end class

/**
 * The recording seam the test's ObjectService exposes.
 */
interface RecordingSeams {

	/**
	 * The seam calls, in order.
	 *
	 * @return list<array{0: string, 1: array<string, mixed>}>
	 */
	public function calls(): array;
}//end interface
