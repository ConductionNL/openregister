<?php

/**
 * PATCH reads the object it merges into in the caller's own scope.
 *
 * An object outside what the caller may read (another organisation's, or
 * behind a read rule) is a 404 on PATCH and POST-patch, as on PUT and GET:
 * no lock holder, no version timestamps, no merge and no write.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses \OCA\OpenRegister\Controller\Support\ConflictTrail
 * @uses \OCA\OpenRegister\Controller\Support\ObjectVersionGuard
 * @uses \OCA\OpenRegister\Controller\Support\SentWrite
 * @uses \OCA\OpenRegister\Controller\Support\VersionClaim
 * @uses \OCA\OpenRegister\Controller\Support\StringTypedPatch
 * @uses \OCA\OpenRegister\Controller\Support\ObjectTarget
 * @uses \OCA\OpenRegister\Controller\Support\ObjectUpdate
 * @uses \OCA\OpenRegister\Controller\Support\WriteCaller
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectsControllerPatchReadScopeTest extends TestCase {

	private IRequest&MockObject $request;
	private ObjectService&MockObject $objectService;
	private IGroupManager&MockObject $groupManager;

	/**
	 * The scope flags of each pre-read, as [rbac, multitenancy].
	 *
	 * @var array<int, array{0: bool, 1: bool}>
	 */
	private array $reads = [];

	/**
	 * The data each save was handed.
	 *
	 * @var array<int, mixed>
	 */
	private array $saves = [];

	/**
	 * The stored object, which only an unscoped read returns unless `$inScope` is set.
	 */
	private ObjectEntity $stored;

	private bool $inScope = false;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->stored = new ObjectEntity();
		$this->stored->setUuid('uuid-b');
		$this->stored->setRegister('1');
		$this->stored->setSchema('2');
		$this->stored->setOrganisation('org-b');
		$this->stored->setObject(['title' => 'Stored title', 'amount' => 900]);
		$this->stored->setUpdated(new \DateTime('2026-07-01T10:00:00+00:00'));

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);
		$this->objectService->method('findSilent')->willReturnCallback(
			function (...$args): ObjectEntity {
				[$id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy] = FindCallShape::findSilent(args: $args);
				$this->reads[] = [$_rbac, $_multitenancy];
				if ($this->inScope === true || ($_rbac === false && $_multitenancy === false)) {
					return $this->stored;
				}

				throw new DoesNotExistException('Object not found');
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->saves[] = $args[0];
				$saved = new ObjectEntity();
				$saved->setUuid('uuid-b');
				return $saved;
			}
		);
	}//end setUp()

	/**
	 * A controller for alice, an administrator or not.
	 *
	 * @param bool $isAdmin Whether alice is in the admin group.
	 *
	 * @return ObjectsController The controller.
	 */
	private function controller(bool $isAdmin = false): ObjectsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($isAdmin === true ? ['admin'] : ['staff']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => $id === 'userId' ? 'alice' : throw new \RuntimeException('not available')
		);

		return ObjectsControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$session,
			$this->groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The request body and the `_expectedUpdated` the caller sends.
	 *
	 * @param array       $body     The patch body.
	 * @param string|null $expected The version the caller claims to have read.
	 *
	 * @return void
	 */
	private function sends(array $body, ?string $expected = null): void {
		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')->willReturn('application/json');
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === '_expectedUpdated' ? $expected : $default
		);
	}//end sends()

	/**
	 * Lock the stored object for bob.
	 *
	 * @return void
	 */
	private function lockedByBob(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($bob);
		$this->stored->lock(userSession: $session, process: 'edit', duration: 3600);
	}//end lockedByBob()

	public function testAStaleVersionOnAnObjectOutsideTheReadScopeIs404WithoutTimestamps(): void {
		$this->sends(['title' => 'guess'], '1970-01-01T00:00:00+00:00');

		$result = $this->controller()->patch('1', '2', 'uuid-b', $this->objectService);

		$this->assertSame(404, $result->getStatus());
		$this->assertStringNotContainsString('2026-07-01', (string)json_encode($result->getData()), 'the stored version reached a caller who may not read the object');
		$this->assertSame([], $this->saves);
	}

	public function testALockOnAnObjectOutsideTheReadScopeIsNotNamed(): void {
		$this->lockedByBob();
		$this->sends(['title' => 'x']);

		$patch = $this->controller()->patch('1', '2', 'uuid-b', $this->objectService);
		$postPatch = $this->controller()->postPatch('1', '2', 'uuid-b', $this->objectService);

		$this->assertSame(404, $patch->getStatus());
		$this->assertSame(404, $postPatch->getStatus());
		$this->assertStringNotContainsString('bob', (string)json_encode([$patch->getData(), $postPatch->getData()]));
	}

	public function testPatchDoesNotWriteAnObjectOutsideTheReadScope(): void {
		$this->sends(['amount' => 1]);

		$result = $this->controller()->patch('1', '2', 'uuid-b', $this->objectService);

		$this->assertSame(404, $result->getStatus());
		$this->assertSame([], $this->saves, 'PATCH merged and saved an object the caller may not read');
	}

	public function testPostPatchDoesNotWriteAnObjectOutsideTheReadScope(): void {
		$this->sends(['amount' => 1]);

		$result = $this->controller()->postPatch('1', '2', 'uuid-b', $this->objectService);

		$this->assertSame(404, $result->getStatus());
		$this->assertSame([], $this->saves, 'POST-patch merged and saved an object the caller may not read');
	}

	public function testANonAdminReadsWithRbacAndMultitenancy(): void {
		$this->sends(['amount' => 1]);

		$this->controller()->patch('1', '2', 'uuid-b', $this->objectService);
		$this->controller()->postPatch('1', '2', 'uuid-b', $this->objectService);

		$this->assertSame([[true, true], [true, true]], $this->reads);
	}

	public function testAnAdministratorStillReadsUnscoped(): void {
		$this->sends(['amount' => 1]);

		$this->controller(isAdmin: true)->patch('1', '2', 'uuid-b', $this->objectService);

		$this->assertSame([[false, false]], $this->reads);
		$this->assertSame(['id' => 'uuid-b', 'title' => 'Stored title', 'amount' => 1], $this->saves[0]);
	}

	public function testAnObjectInsideTheReadScopeIsMergedAndSaved(): void {
		$this->inScope = true;
		$this->sends(['amount' => 1]);

		$patch = $this->controller()->patch('1', '2', 'uuid-b', $this->objectService);
		$postPatch = $this->controller()->postPatch('1', '2', 'uuid-b', $this->objectService);

		$this->assertSame(200, $patch->getStatus());
		$this->assertSame(200, $postPatch->getStatus());
		$this->assertSame(['id' => 'uuid-b', 'title' => 'Stored title', 'amount' => 1], $this->saves[0]);
		$this->assertSame(['id' => 'uuid-b', 'title' => 'Stored title', 'amount' => 1], $this->saves[1]);
	}
}
