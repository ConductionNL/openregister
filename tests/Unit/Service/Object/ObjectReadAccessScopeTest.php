<?php

/**
 * ObjectReadAccess reads an object the way the object GET does.
 *
 * Every reader path that leans on it (an object's history, the scoped audit
 * list) is only as narrow as the flags it hands ObjectService::find(). Those
 * paths mock it in their own tests, so the flags are pinned here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\ObjectReadAccess
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Service\CauseFrame
 */
class ObjectReadAccessScopeTest extends TestCase {

	/**
	 * The arguments of the find() call, by parameter name.
	 *
	 * @param array $args The positional arguments the mock received.
	 *
	 * @return array<string, mixed> The arguments by name.
	 */
	private function byName(array $args): array {
		return array_combine(
			['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit'],
			FindCallShape::find(args: $args)
		);
	}//end byName()

	public function testTheReadKeepsRbacAndMultitenancyOnForTheIdentifierAsked(): void {
		$captured = null;
		$found = new ObjectEntity();
		$found->setUuid('obj-1');
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('find')->willReturnCallback(
			function (...$args) use (&$captured, $found): ObjectEntity {
				$captured = $args;
				return $found;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$access = new ObjectReadAccess($objectService, $session, $this->createMock(SchemaMapper::class));
		$result = $access->readable(register: '5', schema: '7', id: 'obj-1');

		$this->assertSame($found, $result);
		$named = $this->byName(args: $captured);
		$this->assertTrue($named['_rbac'], 'the object read runs without RBAC');
		$this->assertTrue($named['_multitenancy'], 'the object read runs without the organisation boundary');
		$this->assertSame('obj-1', $named['id']);
		$this->assertSame('5', $named['register']);
		$this->assertSame('7', $named['schema']);
	}

	public function testAnAnonymousCallerIsNotRead(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('find');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$access = new ObjectReadAccess($objectService, $session, $this->createMock(SchemaMapper::class));

		$this->assertNull($access->readable(register: '5', schema: '7', id: 'obj-1'));
	}
}
