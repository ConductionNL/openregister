<?php

/**
 * The deleted list's register, schema and deletedSince filters (incremental-sync-for-api-consumers REQ-SYNC-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Controller\Support\DeletedAccess;
use OCA\OpenRegister\Controller\Support\DeletedControllerServices;
use OCA\OpenRegister\Controller\Support\DeletedStore;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\DeletedFilter;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Deletion\DeletionServiceBundle;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\DestructionServices;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DeletedControllerSyncFilterTest extends TestCase {

	/**
	 * The request's query parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Arguments of every listing and count call.
	 *
	 * @var array{list: array<int, array<string, mixed>>, count: array<int, array<string, mixed>>}
	 */
	private array $calls = ['list' => [], 'count' => []];

	private MagicMapper&MockObject $objects;
	private RegisterMapper&MockObject $registers;
	private SchemaMapper&MockObject $schemas;
	private IGroupManager&MockObject $groups;

	protected function setUp(): void {
		$this->objects = $this->createMock(MagicMapper::class);
		$this->objects->method('findDeletedAcrossAllMagicTables')->willReturnCallback(
			function (...$args): array {
				$this->calls['list'][] = $args;
				return [];
			}
		);
		$this->objects->method('countDeletedAcrossAllMagicTables')->willReturnCallback(
			function (...$args): int {
				$this->calls['count'][] = $args;
				return 2;
			}
		);

		$this->registers = $this->createMock(RegisterMapper::class);
		$this->registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if ($id !== 'school' && $id !== '3') {
					throw new DoesNotExistException('no register');
				}

				$register = new Register();
				$register->setId(3);
				return $register;
			}
		);

		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				if ($id !== 'lesson') {
					throw new DoesNotExistException('no schema');
				}

				$schema = new Schema();
				$schema->setId(7);
				return $schema;
			}
		);

		$this->groups = $this->createMock(IGroupManager::class);
	}

	/**
	 * The controller over the current params, signed in.
	 *
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return DeletedController
	 */
	private function controller(bool $admin = true): DeletedController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->willReturn($admin);

		$deletion = new DeletionServiceBundle(
			$this->createMock(DeletionWindowService::class),
			$this->createMock(DestroyRightService::class),
			$this->createMock(RetentionClockService::class),
			new DestructionServices($this->createMock(DestructionScopeService::class), $this->createMock(DestructionRecorder::class)),
		);

		return new DeletedController(
			'openregister',
			$request,
			new DeletedControllerServices(
				store: new DeletedStore(
					objectEntityMapper: $this->objects,
					registerMapper: $this->registers,
					auditTrailMapper: $this->createMock(AuditTrailMapper::class),
					deletion: $deletion,
					schemaMapper: $this->schemas,
				),
				access: new DeletedAccess(
					authorizer: new DeletedObjectAuthorizer(
						$this->createMock(SchemaMapper::class),
						new CallerContext($session, $this->groups),
						$this->createMock(PermissionHandler::class)
					),
					renderObject: $this->createMock(RenderObject::class),
					userSession: $session,
				),
			)
		);
	}

	/**
	 * The filter a call carried.
	 *
	 * @param array<int|string, mixed> $args The call's arguments.
	 *
	 * @return DeletedFilter|null
	 */
	private static function filterOf(array $args): ?DeletedFilter {
		foreach ($args as $arg) {
			if ($arg instanceof DeletedFilter) {
				return $arg;
			}
		}

		return null;
	}

	public function testOneSchemasDeletionsSinceAMomentAreAskedForAndCountedAlike(): void {
		$this->params = ['schema' => 'lesson', 'register' => 'school', 'deletedSince' => '2026-10-05T00:00:00+00:00'];

		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);

		$listed = self::filterOf($this->calls['list'][0]);
		$counted = self::filterOf($this->calls['count'][0]);
		$this->assertSame($listed, $counted);
		$this->assertSame(3, $listed->registerId);
		$this->assertSame(7, $listed->schemaId);
		$this->assertEquals(new DateTimeImmutable('2026-10-05T00:00:00+00:00'), $listed->deletedSince);
	}

	public function testWithoutFiltersTheWholeBinIsReadAsBefore(): void {
		$this->controller()->index();

		$filter = self::filterOf($this->calls['list'][0]);
		$this->assertNull($filter->registerId);
		$this->assertNull($filter->schemaId);
		$this->assertNull($filter->deletedSince);
	}

	public function testAMalformedDeletedSinceIsRefusedByName(): void {
		foreach (['yesterday', '2026-13-45T00:00:00Z', '05-10-2026', ['2026-10-05']] as $since) {
			$this->params = ['deletedSince' => $since];

			$response = $this->controller()->index();

			$this->assertSame(400, $response->getStatus());
			$this->assertSame('deletedSince', $response->getData()['parameter']);
			$this->assertStringContainsString('deletedSince', $response->getData()['error']);
		}

		$this->assertSame([], $this->calls['list']);
		$this->assertSame([], $this->calls['count']);
	}

	public function testAnUnknownSchemaOrRegisterIsNotFound(): void {
		foreach ([['schema' => 'does-not-exist'], ['register' => 'nowhere']] as $params) {
			$this->params = $params;

			$response = $this->controller()->index();

			$this->assertSame(404, $response->getStatus());
			$this->assertSame(array_key_first($params), $response->getData()['parameter']);
		}

		$this->assertSame([], $this->calls['list']);
	}

	public function testANonAdminResolvesTheFilterInTheirOwnScope(): void {
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->schemas->expects($this->once())->method('find')
			->with('lesson', [], true, true)
			->willThrowException(new DoesNotExistException('not visible'));
		$this->params = ['schema' => 'lesson'];

		$response = $this->controller(admin: false)->index();

		$this->assertSame(404, $response->getStatus());
	}
}
