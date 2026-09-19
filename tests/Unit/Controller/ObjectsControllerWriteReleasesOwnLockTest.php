<?php

declare(strict_types=1);

/**
 * The response to a write tells the truth about the lock that write released.
 *
 * A save is a check-in: `ObjectsController` hands the writer's own lock back
 * once their write has landed, and leaves a lock held by anybody else alone.
 * The release goes to the database through `unlockObject()`, which re-reads
 * the row, so the entity this request is about to serialise kept the lock
 * payload it was loaded with and the 200 body claimed a lock the same request
 * had just released. A client that trusts that body, such as
 * `@conduction/nextcloud-vue`'s `useObjectLock`, goes on believing it holds a
 * lock until its release answers 404.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
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

/**
 * Covers the post-write release and what the response says about it.
 */
class ObjectsControllerWriteReleasesOwnLockTest extends TestCase {
	private const CALLER = 'alice';

	private ObjectsController $controller;
	private IRequest&MockObject $request;
	private ContainerInterface&MockObject $container;
	private ObjectService&MockObject $objectService;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->container->method('get')->willReturnCallback(
			static function (string $id) {
				if ($id === 'userId') {
					return self::CALLER;
				}

				return null;
			}
		);

		$this->controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$this->userSession,
			$this->groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The caller is an administrator so RBAC and multitenancy are off and the
	 * write reaches the release. The lock question is decided by the payload
	 * on the entity, not by who the caller is.
	 */
	private function setUpCaller(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::CALLER);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
	}//end setUpCaller()

	/**
	 * A live user lock held by $holder, in the shape `ObjectEntity::lock()`
	 * writes: `{user, process, created, duration, expiration}`.
	 *
	 * @param string $holder The user id recorded as holding the lock.
	 *
	 * @return array<string, mixed> The lock payload.
	 */
	private function liveLockHeldBy(string $holder): array {
		return [
			'user' => $holder,
			'process' => 'editing',
			'created' => (new \DateTime('-1 minute'))->format('c'),
			'duration' => 3600,
			'expiration' => (new \DateTime('+1 hour'))->format('c'),
		];
	}//end liveLockHeldBy()

	/**
	 * Drive `update()` over an object that the save returns carrying $lock.
	 *
	 * @param array<string, mixed>|null $lock The lock payload on the saved entity.
	 *
	 * @return array<string, mixed> The response body. The lock lives under
	 *                              `@self`, which is where `jsonSerialize()`
	 *                              puts every metadata field. A top-level
	 *                              `locked` key is absent whatever happens,
	 *                              so asserting on one passes without ever
	 *                              reading the lock.
	 */
	private function updateReturningLock(?array $lock): array {
		$existing = new ObjectEntity();
		$existing->setUuid('uuid-123');
		$existing->setRegister(1);
		$existing->setSchema(2);
		$existing->setObject(['title' => 'Old']);

		$saved = new ObjectEntity();
		$saved->setUuid('uuid-123');
		$saved->setRegister(1);
		$saved->setSchema(2);
		$saved->setObject(['title' => 'Updated']);
		$saved->setLocked($lock);

		$this->request->method('getParams')->willReturn(['title' => 'Updated']);
		$this->request->method('getHeader')->willReturn('application/json');
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);
		$this->objectService->method('findSilent')->willReturn($existing);
		$this->objectService->method('saveObject')->willReturn($saved);

		$response = $this->controller->update('1', '2', 'uuid-123', $this->objectService);
		$this->assertSame(200, $response->getStatus());

		return $response->getData();
	}//end updateReturningLock()

	/**
	 * The writer's own lock is released, and the body says so instead of
	 * handing back the payload of a lock that is no longer on the row.
	 */
	public function testTheBodyOfAWriteStopsClaimingTheLockThatWriteReleased(): void {
		$this->setUpCaller();

		$this->objectService->expects($this->once())
			->method('unlockObject')
			->with('uuid-123')
			->willReturn(true);

		$body = $this->updateReturningLock($this->liveLockHeldBy(self::CALLER));

		$this->assertNull(
			$body['@self']['locked'],
			'The write released the caller own lock, so the response must not report one.'
		);
	}//end testTheBodyOfAWriteStopsClaimingTheLockThatWriteReleased()

	/**
	 * The control, and the one that matters most: a lock the writer does NOT
	 * hold is neither released nor hidden. Without it, clearing the payload
	 * would read as a pass while an administrator's write quietly stripped
	 * somebody else's lock from the answer.
	 */
	public function testALockHeldByAnotherUserSurvivesTheWriteAndStaysInTheBody(): void {
		$this->setUpCaller();

		$this->objectService->expects($this->never())->method('unlockObject');

		$body = $this->updateReturningLock($this->liveLockHeldBy('bob'));

		$this->assertIsArray($body['@self']['locked']);
		$this->assertSame('bob', $body['@self']['locked']['user']);
	}//end testALockHeldByAnotherUserSurvivesTheWriteAndStaysInTheBody()

	/**
	 * A write to an unlocked object must not pay for the release path at all:
	 * `unlockObject()` re-reads the row across every magic table to conclude
	 * there was nothing to release.
	 */
	public function testAnUnlockedObjectIsNotSentThroughTheReleasePath(): void {
		$this->setUpCaller();

		$this->objectService->expects($this->never())->method('unlockObject');

		$body = $this->updateReturningLock(null);

		$this->assertNull($body['@self']['locked']);
	}//end testAnUnlockedObjectIsNotSentThroughTheReleasePath()

	/**
	 * A release that fails leaves the lock where it is, and the body keeps
	 * reporting it: the row is still locked, so saying otherwise would be the
	 * same lie in the other direction. The write itself still answers 200.
	 */
	public function testAFailedReleaseLeavesTheLockInTheBody(): void {
		$this->setUpCaller();

		$this->objectService->expects($this->once())
			->method('unlockObject')
			->willThrowException(new \Exception('magic table object'));

		$body = $this->updateReturningLock($this->liveLockHeldBy(self::CALLER));

		$this->assertIsArray($body['@self']['locked']);
		$this->assertSame(self::CALLER, $body['@self']['locked']['user']);
	}//end testAFailedReleaseLeavesTheLockInTheBody()
}//end class
