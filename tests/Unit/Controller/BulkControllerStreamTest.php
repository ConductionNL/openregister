<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A streaming bulk save is for administrators only.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\BulkControllerFactory;
use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The streaming path writes each row through the save handler with none of the per-row
 * checks of the default path, so a schema manage right does not open it. `mallory` manages
 * the schema through the `planners` group; only `root` is an administrator, and the
 * administrator lookup answers per user id, so a check made for anybody but the caller fails.
 *
 * @covers \OCA\OpenRegister\Controller\BulkController
 * @uses \OCA\OpenRegister\Controller\Support\BulkStore
 * @uses \OCA\OpenRegister\Controller\Support\BulkPlatform
 * @uses \OCA\OpenRegister\Controller\Support\BulkControllerServices
 * @uses   \OCA\OpenRegister\Service\Rbac\SchemaManageGate
 * @uses   \OCA\OpenRegister\Service\Object\BatchOperationStatus
 * @uses   \OCA\OpenRegister\Dto\BulkSaveOutcome
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class BulkControllerStreamTest extends TestCase {

	/**
	 * Which write path the controller took.
	 *
	 * @var list<string>
	 */
	private array $writes = [];

	/**
	 * A bulk save as a signed-in user.
	 *
	 * @param string $uid    The caller.
	 * @param array  $params The request body.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function save(string $uid, array $params): \OCP\AppFramework\Http\JSONResponse {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
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

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('getRegister')->willReturn(1);
		$objects->method('getSchema')->willReturn(2);
		$objects->method('saveObjectsStreaming')->willReturnCallback(
			function (): BatchOperationStatus {
				$this->writes[] = 'stream';
				$status = new BatchOperationStatus();
				$status->start();
				$status->recordCreated('11111111-1111-1111-1111-111111111111');
				$status->complete();
				return $status;
			}
		);
		$objects->method('saveObjects')->willReturnCallback(
			function (): array {
				$this->writes[] = 'checked';
				return ['statistics' => ['saved' => 1, 'updated' => 0]];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params + ['objects' => [['name' => 'row']]]);

		$controller = BulkControllerFactory::create(
			'openregister',
			$request,
			$objects,
			$this->createMock(RegisterMapper::class),
			$schemas,
			$session,
			$groups,
			$this->createMock(IDBConnection::class)
		);

		return $controller->save('1', '2');
	}//end save()

	public function testNoSessionIsNoAdministrator(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('isAdmin');
		$gate = new SchemaManageGate(userSession: $session, groupManager: $groups, schemaMapper: $this->createMock(SchemaMapper::class));

		$this->assertFalse($gate->callerIsAdministrator());
	}

	public function testAManagerWhoIsNoAdministratorMayNotStream(): void {
		$response = $this->save(uid: 'mallory', params: ['stream' => true]);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(
			expected: 'Streaming bulk saves are for administrators. Send the batch without "stream".',
			actual: $response->getData()['error']
		);
		$this->assertSame(expected: [], actual: $this->writes);
	}

	public function testAStreamFlagSentAsTextIsRefusedToo(): void {
		$response = $this->save(uid: 'mallory', params: ['stream' => 'true']);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->writes);
	}

	public function testTheSameManagerWithoutStreamTakesTheCheckedPath(): void {
		$response = $this->save(uid: 'mallory', params: []);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['checked'], actual: $this->writes);
	}

	public function testAnAdministratorMayStream(): void {
		$response = $this->save(uid: 'root', params: ['stream' => true]);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['stream'], actual: $this->writes);
	}
}//end class
