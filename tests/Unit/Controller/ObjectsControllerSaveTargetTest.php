<?php

/**
 * A write lands on the object the caller's own read resolved.
 *
 * PUT, PATCH and POST-patch read the object in the caller's scope, then save.
 * The save looks its identifier up again without that scope, so the URL id
 * alone could name another row there: "1" is object B's id and object A's
 * uuid, a slug can equal another object's id. The save is therefore handed
 * the uuid of the object the caller read.
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
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 */
class ObjectsControllerSaveTargetTest extends TestCase {

	private IRequest&MockObject $request;
	private ObjectService&MockObject $objectService;
	private IGroupManager&MockObject $groupManager;

	/**
	 * The identifier each save was handed.
	 *
	 * @var array<int, mixed>
	 */
	private array $saveTargets = [];

	/**
	 * Object B: the one the caller may read, with numeric id 1 and slug "the-slug".
	 */
	private ObjectEntity $visible;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->visible = new ObjectEntity();
		$this->visible->setId(1);
		$this->visible->setUuid('uuid-b');
		$this->visible->setSlug('the-slug');
		$this->visible->setRegister('1');
		$this->visible->setSchema('2');
		$this->visible->setObject(['title' => 'B']);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);
		// The caller's own read: whatever identifier names B in the caller's scope answers B.
		$this->objectService->method('findSilent')->willReturn($this->visible);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->saveTargets[] = ($args['uuid'] ?? $args[4] ?? null);
				return $this->visible;
			}
		);

		$this->request->method('getParams')->willReturn(['title' => 'changed']);
		$this->request->method('getHeader')->willReturn('application/json');
		$this->request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $default);
	}//end setUp()

	/**
	 * A controller for alice, who is not an administrator.
	 *
	 * @return ObjectsController The controller.
	 */
	private function controller(): ObjectsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['staff']);
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
	 * Identifiers that name B for the caller and may name another row without the caller's scope.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function identifiers(): array {
		return [
			'an id that is a hidden object\'s uuid' => ['1'],
			'a slug that is a hidden object\'s id or uuid' => ['the-slug'],
		];
	}//end identifiers()

	/**
	 * @dataProvider identifiers
	 */
	public function testPutWritesTheObjectTheCallerRead(string $identifier): void {
		$result = $this->controller()->update('1', '2', $identifier, $this->objectService);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['uuid-b'], $this->saveTargets);
	}

	/**
	 * @dataProvider identifiers
	 */
	public function testPatchWritesTheObjectTheCallerRead(string $identifier): void {
		$result = $this->controller()->patch('1', '2', $identifier, $this->objectService);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['uuid-b'], $this->saveTargets);
	}

	/**
	 * @dataProvider identifiers
	 */
	public function testPostPatchWritesTheObjectTheCallerRead(string $identifier): void {
		$result = $this->controller()->postPatch('1', '2', $identifier, $this->objectService);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['uuid-b'], $this->saveTargets);
	}
}
