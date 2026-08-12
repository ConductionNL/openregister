<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\AnnotationNotificationDispatchJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deferred dispatch parity with the inline listener: same triggers, same
 * old/new context pairing, stale entries no-op.
 */
class AnnotationNotificationDispatchJobTest extends TestCase {
	private IUserManager&MockObject $userManager;
	private DeferredEntryObjectResolver&MockObject $resolver;
	private AnnotationNotificationDispatcher&MockObject $dispatcher;
	private AnnotationNotificationDispatchJob $job;

	/** @var array<int, array{0: string, 1: string, 2: array}> */
	private array $dispatched = [];

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->resolver = $this->createMock(DeferredEntryObjectResolver::class);
		$this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);

		$this->dispatched = [];
		$this->dispatcher->method('dispatch')->willReturnCallback(
			function (ObjectEntity $object, string $trigger, array $context = []): void {
				$this->dispatched[] = [(string)$object->getUuid(), $trigger, $context];
			}
		);

		$this->job = new AnnotationNotificationDispatchJob(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->userManager,
			organisation: $this->createMock(OrganisationService::class),
			logger: $this->createMock(LoggerInterface::class),
			resolver: $this->resolver,
			dispatcher: $this->dispatcher,
		);
	}

	private function runJob(array $entries): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($user);

		$argument = (new DeferredListenerContext(userId: 'alice', orgUuid: null, entries: $entries))
			->toJobArguments();

		$method = (new \ReflectionClass($this->job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, $argument);
	}

	private function liveObject(string $uuid, array $data = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);
		return $object;
	}

	public function testCreatedEntryDispatchesCreatedTrigger(): void {
		$this->resolver->method('resolve')->willReturn($this->liveObject('u1'));

		$this->runJob([['uuid' => 'u1', 'register' => 'r', 'schema' => 's', 'trigger' => 'created']]);

		$this->assertSame([['u1', 'created', []]], $this->dispatched);
	}

	public function testTransitionEntryForwardsTheEventContext(): void {
		$this->resolver->method('resolve')->willReturn($this->liveObject('u1'));

		$this->runJob([
			[
				'uuid' => 'u1',
				'register' => 'r',
				'schema' => 's',
				'trigger' => 'transition',
				'action' => 'approve',
				'from' => 'draft',
				'to' => 'published',
			],
		]);

		$this->assertSame(
			[['u1', 'transition', ['action' => 'approve', 'from' => 'draft', 'to' => 'published']]],
			$this->dispatched
		);
	}

	public function testUpdatedEntryDispatchesUpdatedAndCalculatedChangeWithOldNewContext(): void {
		// The job re-fetches CURRENT data for _newData; _oldData comes from
		// the snapshot captured at dispatch time.
		$this->resolver->method('resolve')->willReturn($this->liveObject('u1', ['title' => 'after']));

		$this->runJob([
			[
				'uuid' => 'u1',
				'register' => 'r',
				'schema' => 's',
				'trigger' => 'updated',
				'oldData' => ['title' => 'before'],
			],
		]);

		$expectedContext = [
			// ObjectEntity::getObject() prepends the uuid as `id`.
			'_newData' => ['id' => 'u1', 'title' => 'after'],
			'_oldData' => ['title' => 'before'],
		];
		$this->assertSame(
			[
				['u1', 'updated', $expectedContext],
				['u1', 'calculatedChange', $expectedContext],
			],
			$this->dispatched
		);
	}

	public function testUpdatedEntryWithoutSnapshotDispatchesPlainUpdatedOnly(): void {
		$this->resolver->method('resolve')->willReturn($this->liveObject('u1', ['title' => 'after']));

		$this->runJob([['uuid' => 'u1', 'register' => 'r', 'schema' => 's', 'trigger' => 'updated']]);

		$this->assertSame([['u1', 'updated', []]], $this->dispatched);
	}

	public function testStaleEntryDispatchesNothing(): void {
		$this->resolver->method('resolve')->willReturn(null);

		$this->runJob([['uuid' => 'gone', 'register' => 'r', 'schema' => 's', 'trigger' => 'created']]);

		$this->assertSame([], $this->dispatched);
	}
}
