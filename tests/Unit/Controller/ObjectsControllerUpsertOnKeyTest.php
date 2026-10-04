<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Listener\UniqueConstraintListener;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Import\MatchResolver;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\UpsertOnKeyHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * `POST /api/objects/{register}/{schema}?_upsertOn=<constraint>` through the real controller.
 *
 * The controller, the UpsertOnKeyHandler, the UniqueConstraintEvaluator and
 * the MatchResolver are real; the object store and the lock provider are doubles.
 *
 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
 */
class ObjectsControllerUpsertOnKeyTest extends TestCase {

	private ObjectsController $controller;

	private IRequest&MockObject $request;

	private ObjectService&MockObject $objectService;

	private IUserSession&MockObject $userSession;

	/** @var array<string, mixed> Raw request parameters by name. */
	private array $params = [];

	/** @var array<int, string|null> The uuid each saveObject() call was given. */
	private array $savedUuids = [];

	/** @var array<int, string> What the store finds for the key. */
	private array $found = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setConfiguration(
			['uniqueConstraints' => ['zaaksleutel' => ['properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse']]]
		);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);
		$this->objectService->method('findAll')->willReturnCallback(
			fn (): array => array_map(static fn (string $uuid): array => ['@self' => ['id' => $uuid]], $this->found)
		);

		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$this->request->method('getHeader')->willReturn('application/json');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);

		$handler = new UpsertOnKeyHandler(
			evaluator: new UniqueConstraintEvaluator(),
			matchResolver: new MatchResolver(objectService: $this->objectService, logger: new NullLogger()),
			lockingProvider: $this->createMock(ILockingProvider::class)
		);

		// With no interception webhook configured, the real service hands the
		// request's own parameters back unchanged.
		$webhooks = $this->createMock(WebhookService::class);
		$webhooks->method('interceptRequest')->willReturnCallback(fn (): array => $this->params);

		$this->controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$this->userSession,
			$groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$webhooks,
			$this->createMock(LoggerInterface::class),
			upsertOnKeyHandler: $handler
		);
	}//end setUp()

	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('integration');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedIn()

	private function savesEcho(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			// Positional, in ObjectService::saveObject()'s own order: object, extend, register, schema, uuid.
			function (array $object, $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->savedUuids[] = $uuid;
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'new-uuid');
				$entity->setObject($object);
				return $entity;
			}
		);
	}//end savesEcho()

	private function post(array $params): \OCP\AppFramework\Http\JSONResponse {
		$this->params = $params;

		return $this->controller->create(register: '1', schema: '2', objectService: $this->objectService);
	}//end post()

	public function testAKeyNobodyHoldsCreatesWith201(): void {
		$this->signedIn();
		$this->savesEcho();

		$result = $this->post(['_upsertOn' => 'zaaksleutel', 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1']);

		$this->assertSame(201, $result->getStatus());
		$this->assertSame([null], $this->savedUuids);
	}//end testAKeyNobodyHoldsCreatesWith201()

	public function testAHeldKeyUpdatesThatRecordWith200(): void {
		$this->signedIn();
		$this->savesEcho();
		$this->found = ['u-1'];

		$result = $this->post(['_upsertOn' => 'zaaksleutel', 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1', 'titel' => 'Nieuw']);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['u-1'], $this->savedUuids);
	}//end testAHeldKeyUpdatesThatRecordWith200()

	public function testADuplicatedKeyIs409WithItsMatches(): void {
		$this->signedIn();
		$this->savesEcho();
		$this->found = ['u-1', 'u-2'];

		$result = $this->post(['_upsertOn' => 'zaaksleutel', 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1']);

		$this->assertSame(409, $result->getStatus());
		$this->assertSame(['u-1', 'u-2'], $result->getData()['matches']);
		$this->assertSame([], $this->savedUuids);
	}//end testADuplicatedKeyIs409WithItsMatches()

	public function testAnAnonymousUpsertIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->post(['_upsertOn' => 'zaaksleutel', 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1']);

		$this->assertSame(401, $result->getStatus());
	}//end testAnAnonymousUpsertIs401()

	public function testUpsertWithFailIfExistsIs400(): void {
		$this->signedIn();
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->post(['_upsertOn' => 'zaaksleutel', '_failIfExists' => 'true', 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1']);

		$this->assertSame(400, $result->getStatus());
	}//end testUpsertWithFailIfExistsIs400()

	public function testAnUnknownKeyIs400ListingTheKeys(): void {
		$this->signedIn();
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->post(['_upsertOn' => 'status', 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1']);

		$this->assertSame(400, $result->getStatus());
		$this->assertSame(['zaaksleutel'], $result->getData()['refuseConstraints']);
	}//end testAnUnknownKeyIs400ListingTheKeys()

	public function testAnUnseenHolderIs409WithoutItsUuid(): void {
		$this->signedIn();
		$this->objectService->method('saveObject')->willThrowException(
			new HookStoppedException(
				'breach',
				['code' => UniqueConstraintListener::ERROR_CODE, 'constraint' => 'zaaksleutel', 'conflictingObject' => 'secret-uuid']
			)
		);

		$result = $this->post(['_upsertOn' => 'zaaksleutel', 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1']);

		$this->assertSame(409, $result->getStatus());
		$this->assertStringNotContainsString('secret-uuid', (string)json_encode($result->getData()));
	}//end testAnUnseenHolderIs409WithoutItsUuid()

	public function testAnUpdateTheCallerMayNotMakeIs403(): void {
		$this->signedIn();
		$this->found = ['u-1'];
		$this->objectService->method('saveObject')->willThrowException(
			new NotAuthorizedException(message: "User 'integration' does not have permission to 'update' objects in schema 'Zaak'")
		);

		$result = $this->post(['_upsertOn' => 'zaaksleutel', 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1']);

		$this->assertSame(403, $result->getStatus());
	}//end testAnUpdateTheCallerMayNotMakeIs403()

	public function testWithoutUpsertOnACreateIsUnchanged(): void {
		$this->signedIn();
		$this->savesEcho();
		$this->found = ['u-1'];

		$result = $this->post(['gemeentecode' => '0363', 'zaaknummer' => 'Z-1']);

		$this->assertSame(201, $result->getStatus());
		$this->assertSame([null], $this->savedUuids);
	}//end testWithoutUpsertOnACreateIsUnchanged()
}//end class
