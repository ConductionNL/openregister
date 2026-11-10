<?php

/**
 * Moving an object asks for update on it and create on the target schema, before anything moves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ObjectsControllerMoveRightsTest extends TestCase {

	/**
	 * The rights asked for, with the schema id each was asked on.
	 *
	 * @var list<array{0: string, 1: int|null, 2: string|null}>
	 */
	private array $asked = [];

	/**
	 * Without update on the object nothing moves.
	 *
	 * @return void
	 */
	public function testMoveWithoutUpdateOnTheObjectIsForbidden(): void {
		[$status, $moved] = $this->moveHolding(rights: ['20:create']);

		$this->assertSame(403, $status);
		$this->assertFalse($moved);
	}//end testMoveWithoutUpdateOnTheObjectIsForbidden()

	/**
	 * Without create on the target schema nothing moves.
	 *
	 * @return void
	 */
	public function testMoveWithoutCreateOnTheTargetIsForbidden(): void {
		[$status, $moved] = $this->moveHolding(rights: ['10:update']);

		$this->assertSame(403, $status);
		$this->assertFalse($moved);
	}//end testMoveWithoutCreateOnTheTargetIsForbidden()

	/**
	 * With both rights the move runs, and the object's owner was put to the update check.
	 *
	 * @return void
	 */
	public function testMoveWithBothRightsRuns(): void {
		[$status, $moved] = $this->moveHolding(rights: ['10:update', '20:create']);

		$this->assertSame(200, $status);
		$this->assertTrue($moved);
		$this->assertSame([['update', 10, 'anna'], ['create', 20, null]], $this->asked);
	}//end testMoveWithBothRightsRuns()

	/**
	 * Ask for a move as a caller holding the given rights.
	 *
	 * @param list<string> $rights The rights held, as `<schema id>:<action>`.
	 *
	 * @return array{0: int, 1: bool} The status, and whether the mover was called.
	 */
	private function moveHolding(array $rights): array {
		$moved = false;
		$mover = $this->createMock(MoveObject::class);
		$mover->method('move')->willReturnCallback(
			static function () use (&$moved): array {
				$moved = true;
				return ['moved' => true, 'uuid' => 'u-1', 'from' => [], 'to' => [], 'errors' => []];
			}
		);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
			function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null) use ($rights): bool {
				$this->asked[] = [$action, $schema->getId(), $objectOwner];
				return in_array($schema->getId() . ':' . $action, $rights, true);
			}
		));

		$response = $this->controller(mover: $mover, permissions: $permissions)->move('1', '10', 'u-1');

		return [$response->getStatus(), $moved];
	}//end moveHolding()

	/**
	 * A controller that finds the object, its addresses and the two collaborators of a move.
	 *
	 * @param MoveObject        $mover       The mover.
	 * @param PermissionHandler $permissions The rights.
	 *
	 * @return ObjectsController
	 */
	private function controller(MoveObject $mover, PermissionHandler $permissions): ObjectsController {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setOwner('anna');
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($object);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['targetRegister', '', '2'], ['targetSchema', '', '20']]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === \OCA\OpenRegister\Service\Object\MoveObjectInterface::class) ? $mover : $permissions
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int) $id);
				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int) $id);
				return $schema;
			}
		);

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$registers,
			$schemas,
			$this->createMock(AuditTrailMapper::class),
			$objectService,
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			windowService: $this->createMock(DeletionWindowService::class)
		);
	}//end controller()
}//end class
