<?php

/**
 * Copying an object reads it under the caller's rights, asks create on the schema and creates a new object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
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
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Service\Object\CopyPayload
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectCreate
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectCreateControls
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses   \OCA\OpenRegister\Controller\Support\WriteCaller
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses   \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses   \OCA\OpenRegister\Service\CauseFrame
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Service\WritePhase
 */
class ObjectsControllerCopyTest extends TestCase {

	/**
	 * The bodies the object service was asked to save.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * With create on the schema the copy is a new object from the source and the overrides.
	 *
	 * @return void
	 */
	public function testACopyIsCreatedFromTheSourceAndTheOverrides(): void {
		$response = $this->controller(params: ['overrides' => ['naam' => 'Zaaksysteem B']], rights: ['create'])->copy('1', '10', 'u-1', $this->objectService);

		$this->assertSame(201, $response->getStatus());
		$this->assertSame([['naam' => 'Zaaksysteem B', 'leverancier' => 'Conduction']], $this->saved);
		$this->assertSame('u-2', $response->getData()['object']['id']);
		$this->assertSame(['relationRows' => [], 'incoming' => [], 'files' => []], $response->getData()['links']);
	}//end testACopyIsCreatedFromTheSourceAndTheOverrides()

	/**
	 * Without create on the schema nothing is saved.
	 *
	 * @return void
	 */
	public function testWithoutCreateTheCopyIsForbidden(): void {
		$response = $this->controller(params: [], rights: [])->copy('1', '10', 'u-1', $this->objectService);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->saved);
	}//end testWithoutCreateTheCopyIsForbidden()

	/**
	 * A source the caller cannot read answers 404 and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceIsNotFound(): void {
		$response = $this->controller(params: [], rights: ['create'], readable: false)->copy('1', '10', 'u-1', $this->objectService);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame([], $this->saved);
	}//end testAnUnreadableSourceIsNotFound()

	/**
	 * Asking for links, or for an upsert, is refused before anything is read or saved.
	 *
	 * @return void
	 */
	public function testLinksAndUpsertsAreRefusedNotIgnored(): void {
		$links  = $this->controller(params: ['include' => ['relationRows']], rights: ['create'])->copy('1', '10', 'u-1', $this->objectService);
		$upsert = $this->controller(params: ['_upsertOn' => 'naam'], rights: ['create'])->copy('1', '10', 'u-1', $this->objectService);

		$this->assertSame(422, $links->getStatus());
		$this->assertSame(422, $upsert->getStatus());
		$this->assertSame([], $this->saved);
	}//end testLinksAndUpsertsAreRefusedNotIgnored()

	/**
	 * A create the save path refuses is answered as that refusal, without the copy envelope.
	 *
	 * @return void
	 */
	public function testARefusedCreateIsNotACopy(): void {
		$response = $this->controller(params: [], rights: ['create'], saveFails: true)->copy('1', '10', 'u-1', $this->objectService);

		$this->assertNotSame(201, $response->getStatus());
		$this->assertArrayNotHasKey('links', (array) $response->getData());
	}//end testARefusedCreateIsNotACopy()

	/**
	 * Without a session the copy answers 401.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller(params: [], rights: ['create'], signedIn: false)->copy('1', '10', 'u-1', $this->objectService);

		$this->assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * The object service of the controller under test.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * A controller over one readable source object `u-1`.
	 *
	 * @param array<string, mixed> $params    The request parameters.
	 * @param list<string>         $rights    The actions the caller holds on the schema.
	 * @param bool                 $readable  Whether the caller can read the source.
	 * @param bool                 $saveFails Whether the save refuses.
	 * @param bool                 $signedIn  Whether a user is signed in.
	 *
	 * @return ObjectsController
	 */
	private function controller(array $params, array $rights, bool $readable = true, bool $saveFails = false, bool $signedIn = true): ObjectsController {
		$source = new ObjectEntity();
		$source->setUuid('u-1');
		$source->setOwner('anna');
		$source->setObject(['naam' => 'Zaaksysteem A', 'leverancier' => 'Conduction', 'id' => 'u-1']);

		$schema = new Schema();
		$schema->setId(10);
		$schema->setProperties(['naam' => ['type' => 'string'], 'leverancier' => ['type' => 'string']]);
		$register = new Register();
		$register->setId(1);

		$this->objectService = $this->createMock(ObjectService::class);
		if ($readable === true) {
			$this->objectService->method('find')->willReturn($source);
		} else {
			$this->objectService->method('find')->willThrowException(new RuntimeException('not readable'));
		}

		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(10);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($saveFails): ObjectEntity {
				if ($saveFails === true) {
					throw new RuntimeException('refused');
				}

				$this->saved[] = $object;
				$copy = new ObjectEntity();
				$copy->setUuid('u-2');
				$copy->setObject($object);
				return $copy;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($signedIn === true) ? $user : null);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action): bool => in_array($action, $rights, true)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($permissions);

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
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
