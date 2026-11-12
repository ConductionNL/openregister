<?php

/**
 * Unit test for PartyObjectAccess.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use Exception;
use OCA\OpenRegister\Controller\Support\PartyObjectAccess;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckOptions;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Party routes resolve the object, ask the update right on writes and map failures to statuses.
 *
 * @covers \OCA\OpenRegister\Controller\Support\PartyObjectAccess
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class PartyObjectAccessTest extends TestCase {
	/**
	 * The objects under test or its double.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The permissions under test or its double.
	 *
	 * @var PermissionHandlerInterface&MockObject
	 */
	private PermissionHandlerInterface&MockObject $permissions;

	/**
	 * The access under test or its double.
	 *
	 * @var PartyObjectAccess
	 */
	private PartyObjectAccess $access;

	protected function setUp(): void {
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->access      = new PartyObjectAccess(objects: $this->objects, permissions: $this->permissions);
	}

	private function objectOwnedBy(string $owner): ObjectEntity {
		$object = new ObjectEntity();
		$object->setOwner($owner);

		return $object;
	}

	public function testReadPointsTheServiceAtTheTargetAndPassesTheObjectToTheHandler(): void {
		$object = $this->objectOwnedBy('alice');
		$this->objects->expects($this->once())->method('setRegister')->with('reg');
		$this->objects->expects($this->once())->method('setSchema')->with('sch');
		$this->objects->expects($this->once())->method('setObject')->with('obj-1');
		$this->objects->method('getObject')->willReturn($object);

		$seen     = null;
		$response = $this->access->read(
			['reg', 'sch', 'obj-1'],
			function (ObjectEntity $given) use (&$seen): JSONResponse {
				$seen = $given;
				return new JSONResponse(['ok' => true], 200);
			}
		);

		$this->assertSame($object, $seen);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['ok' => true], $response->getData());
	}

	public function testReadIsA404WhenTheObjectDoesNotResolve(): void {
		$this->objects->method('getObject')->willReturn(null);

		$response = $this->access->read(['r', 's', 'o'], fn (): JSONResponse => $this->fail('handler must not run'));

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Object not found'], $response->getData());
	}

	public function testReadIsA404WhenTheLookupThrowsDoesNotExist(): void {
		$this->objects->method('getObject')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->access->read(['r', 's', 'o'], fn (): JSONResponse => $this->fail('handler must not run'));

		$this->assertSame(404, $response->getStatus());
	}

	public function testReadMapsAHandlerExceptionToItsKeptStatus(): void {
		$this->objects->method('getObject')->willReturn($this->objectOwnedBy('alice'));

		$response = $this->access->read(
			['r', 's', 'o'],
			static function (): JSONResponse {
				throw new Exception('conflict', 409);
			}
		);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'conflict'], $response->getData());
	}

	public function testErrorResponseKeepsEveryNamedClientStatus(): void {
		foreach ([400, 401, 403, 404, 409, 422] as $code) {
			$this->assertSame($code, $this->access->errorResponse(new Exception('e', $code))->getStatus());
		}
	}

	public function testErrorResponseTurnsOtherCodesInto400(): void {
		$this->assertSame(400, $this->access->errorResponse(new Exception('boom', 500))->getStatus());
		$this->assertSame(400, $this->access->errorResponse(new Exception('boom'))->getStatus());
		$this->assertSame(400, $this->access->errorResponse(new Exception('teapot', 418))->getStatus());
	}

	public function testWriteAsksTheUpdateRightWithTheObjectOwnerBeforeRunningTheHandler(): void {
		$object = $this->objectOwnedBy('bob');
		$schema = $this->createMock(Schema::class);
		$this->objects->method('getObject')->willReturn($object);
		$this->objects->method('getCurrentSchemaEntity')->willReturn($schema);

		$order = [];
		$this->permissions->expects($this->once())->method('checkPermission')->with(
			$schema,
			'update',
			$this->callback(
				static fn (PermissionCheckOptions $options): bool => $options->objectOwner === 'bob' && $options->object === $object
			),
			true
		)->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'check';
			}
		);

		$response = $this->access->write(
			['r', 's', 'o'],
			static function () use (&$order): JSONResponse {
				$order[] = 'handler';
				return new JSONResponse([], 201);
			}
		);

		$this->assertSame(['check', 'handler'], $order);
		$this->assertSame(201, $response->getStatus());
	}

	public function testWriteIsA403AndSkipsTheHandlerWhenTheRightIsRefused(): void {
		$this->objects->method('getObject')->willReturn($this->objectOwnedBy('bob'));
		$this->objects->method('getCurrentSchemaEntity')->willReturn($this->createMock(Schema::class));
		$this->permissions->method('checkPermission')->willThrowException(new NotAuthorizedException('no'));

		$response = $this->access->write(['r', 's', 'o'], fn (): JSONResponse => $this->fail('handler must not run'));

		$this->assertSame(403, $response->getStatus());
	}

	public function testWriteRefusesWhenTheSchemaCannotBeRead(): void {
		$this->objects->method('getObject')->willReturn($this->objectOwnedBy('bob'));
		$this->objects->method('getCurrentSchemaEntity')->willReturn(null);
		$this->permissions->expects($this->never())->method('checkPermission');

		$response = $this->access->write(['r', 's', 'o'], fn (): JSONResponse => $this->fail('handler must not run'));

		$this->assertSame(403, $response->getStatus());
		$this->assertStringContainsString('update right cannot be asked', $response->getData()['error']);
	}
}
