<?php

declare(strict_types=1);

/**
 * The deletes that empty a whole table are for administrators.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\BulkControllerFactory;
use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `mallory` manages the schema and the register through the `planners` group; only `root` is
 * an administrator, and the lookup answers per user id, so a check for anybody but the caller fails.
 *
 * @covers \OCA\OpenRegister\Controller\BulkController
 * @uses \OCA\OpenRegister\Controller\Support\BulkStore
 * @uses \OCA\OpenRegister\Controller\Support\BulkPlatform
 * @uses \OCA\OpenRegister\Controller\Support\BulkControllerServices
 * @uses   \OCA\OpenRegister\Service\Rbac\SchemaManageGate
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class BulkControllerWholeTableDeleteTest extends TestCase {

	/**
	 * What reached the service.
	 *
	 * @var list<string>
	 */
	private array $deletes = [];

	/**
	 * A controller for this caller.
	 *
	 * @param string|null $uid The caller; null without a session.
	 *
	 * @return BulkController
	 */
	private function controller(?string $uid): BulkController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $who): bool => $who === 'root');
		$groups->method('getUserGroupIds')->willReturn(['planners']);

		$schema = new Schema();
		$schema->setId(2);
		$schema->setAuthorization(['manage' => ['planners']]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([2]);
		$register->setAuthorization(['manage' => ['planners']]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('getRegister')->willReturn(1);
		$objects->method('getSchema')->willReturn(2);
		$objects->method('deleteObjectsOfTable')->willReturnCallback(
			function (): array {
				$this->deletes[] = 'schema';
				return ['deleted_count' => 1, 'deleted_uuids' => ['u'], 'schema_id' => 2];
			}
		);
		$objects->method('deleteObjectsByRegister')->willReturnCallback(
			function (): array {
				$this->deletes[] = 'register';
				return ['deleted_count' => 1, 'deleted_uuids' => ['u'], 'register_id' => 1];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		return BulkControllerFactory::create('openregister', $request, $objects, $registers, $schemas, $session, $groups, $this->createMock(IDBConnection::class));
	}//end controller()

	/**
	 * Each route that empties a table, called by this controller.
	 *
	 * @return array<string, array{callable(BulkController): JSONResponse, string}>
	 */
	public static function routes(): array {
		return [
			'delete-schema'   => [static fn (BulkController $c): JSONResponse => $c->deleteSchema('1', '2'), 'schema'],
			'delete-objects'  => [static fn (BulkController $c): JSONResponse => $c->deleteSchemaObjects('1', '2'), 'schema'],
			'delete-register' => [static fn (BulkController $c): JSONResponse => $c->deleteRegister('1'), 'register'],
		];
	}//end routes()

	/**
	 * @dataProvider routes
	 */
	public function testAManagerWhoIsNoAdministratorIsRefused(callable $route): void {
		$this->assertSame(403, $route($this->controller('mallory'))->getStatus());
		$this->assertSame([], $this->deletes);
	}

	/**
	 * @dataProvider routes
	 */
	public function testACallerWithoutASessionIsRefused(callable $route): void {
		$this->assertSame(403, $route($this->controller(null))->getStatus());
		$this->assertSame([], $this->deletes);
	}

	/**
	 * @dataProvider routes
	 */
	public function testAnAdministratorDeletes(callable $route, string $door): void {
		$this->assertSame(200, $route($this->controller('root'))->getStatus());
		$this->assertSame([$door], $this->deletes);
	}
}//end class
