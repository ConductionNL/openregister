<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
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
 * Releasing a lock says whether there was one to release.
 *
 * 🔴 THE TWO ANSWERS USED TO BE ONE ANSWER. `unlock()` returned 200 whether it
 * freed a lock or found none, so a client that releases when its editor closes
 * could not tell "I handed mine back" from "somebody had already taken it away",
 * and reported success on a lock it never held.
 *
 * It matters beyond tidiness because of what the library on the other side
 * does. `@conduction/nextcloud-vue`'s `useObjectLock.release()` reads a 404 as
 * "already released; idempotent" and returns without a word. Before
 * nextcloud-vue#1202 it sent `DELETE /lock`, a verb this app did not declare,
 * so every release in every app on that library got its 404 from the ROUTER,
 * took the idempotent branch, and freed nothing. That branch was right by
 * accident, and it would have gone on being right if the lock had never worked
 * at all.
 *
 * So these tests pin the two statuses apart, and the route test beside them
 * pins the verb. Asserting only that the call "succeeded" passes in both
 * worlds.
 *
 * 🔑 THE 404 IS NOT AN ERROR. Nothing was refused and nothing threw; the status
 * carries a fact about the object. `locked: false` is true in both answers on
 * purpose, so a client that only reads that field keeps working.
 *
 * @package Unit\Controller
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
 */
class ObjectsControllerUnlockTest extends TestCase {

	private ObjectsController $controller;
	private ObjectService&MockObject $objectService;

	/**
	 * Build the controller with the collaborators unlock() actually reaches.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new ObjectsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$userSession,
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A release that freed a lock answers 200 and says it released one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testAReleasedLockAnswersOkAndSaysSo(): void {
		$this->objectService->method('unlockObject')->willReturn(true);

		$response = $this->controller->unlock(register: 'dossiq', schema: 'case', id: 'abc');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue(($body['released'] ?? null), 'a lock was actually handed back');
		$this->assertFalse(($body['locked'] ?? null));
		$this->assertArrayNotHasKey('error', $body, 'a release is not an error');
	}

	/**
	 * 🔴 Releasing a lock that is not there answers 404, not success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testReleasingALockThatIsNotThereAnswers404(): void {
		$this->objectService->method('unlockObject')->willReturn(false);

		$response = $this->controller->unlock(register: 'dossiq', schema: 'case', id: 'abc');
		$body = $response->getData();

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$response->getStatus(),
			'404 is the fact that this object carried no lock'
		);
		$this->assertSame('not-locked', ($body['error'] ?? null));
		$this->assertFalse(($body['released'] ?? null));
		// Still false, and still true: a client reading only this keeps working.
		$this->assertFalse(($body['locked'] ?? null));
	}

	/**
	 * The two answers are distinguishable, which is the whole point.
	 *
	 * Written as its own test because each of the two above passes on an
	 * implementation that answers ITS status for both cases.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testTheTwoAnswersAreNotTheSameAnswer(): void {
		$service = $this->createMock(ObjectService::class);
		$service->method('unlockObject')->willReturnOnConsecutiveCalls(true, false);

		$controller = $this->controllerOver(service: $service);

		$released = $controller->unlock(register: 'dossiq', schema: 'case', id: 'abc');
		$nothing = $controller->unlock(register: 'dossiq', schema: 'case', id: 'abc');

		$this->assertNotSame(
			$released->getStatus(),
			$nothing->getStatus(),
			'a release and a no-op must not report the same status'
		);
	}

	/**
	 * An unauthenticated caller releases nothing, before any lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testAnAnonymousCallerIsRefusedBeforeAnythingIsRead(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$service = $this->createMock(ObjectService::class);
		$service->expects($this->never())->method('unlockObject');

		$controller = $this->controllerOver(service: $service, session: $session);

		$this->assertSame(
			401,
			$controller->unlock(register: 'dossiq', schema: 'case', id: 'abc')->getStatus()
		);
	}

	/**
	 * A controller over the given collaborators.
	 *
	 * @param ObjectService     $service The object service.
	 * @param IUserSession|null $session The session, or a signed-in one.
	 *
	 * @return ObjectsController The controller.
	 */
	private function controllerOver(ObjectService $service, ?IUserSession $session = null): ObjectsController {
		if ($session === null) {
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($this->createMock(IUser::class));
		}

		return new ObjectsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$service,
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}
}//end class
