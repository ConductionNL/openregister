<?php

/**
 * ContextChatSubmissionJob unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ContextChatSubmissionJob;
use OCA\OpenRegister\BackgroundJob\Support\ActorForwardingServices;
use OCA\OpenRegister\ContextChat\Listener\ContextChatSubmissionListenerInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The deferred submit/remove acts on the object's state at job time.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\ContextChatSubmissionJob
 * @uses \OCA\OpenRegister\BackgroundJob\ActorForwardedJob
 * @uses \OCA\OpenRegister\BackgroundJob\Support\ActorForwardingServices
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Deferral\DeferredListenerContext
 */
class ContextChatSubmissionJobTest extends TestCase {
	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $userManager;
	private DeferredEntryObjectResolverInterface&MockObject $resolver;
	private ContextChatSubmissionListenerInterface&MockObject $submission;
	private LoggerInterface&MockObject $logger;
	private ContextChatSubmissionJob $job;

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->resolver = $this->createMock(DeferredEntryObjectResolverInterface::class);
		$this->submission = $this->createMock(ContextChatSubmissionListenerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new ContextChatSubmissionJob(
			time: $this->createMock(ITimeFactory::class),
			actor: new ActorForwardingServices(
				userSession: $this->userSession,
				userManager: $this->userManager,
				organisation: $this->createMock(OrganisationService::class),
				logger: $this->logger
			),
			resolver: $this->resolver,
			submission: $this->submission,
		);
	}

	private function runJob(array $entries): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$argument = (new DeferredListenerContext(userId: 'alice', orgUuid: null, entries: $entries))->toJobArguments();

		$method = (new \ReflectionClass($this->job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, $argument);
	}

	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('7');

		return $object;
	}

	public function testALiveObjectIsSubmittedUnderTheForwardedActor(): void {
		$live = $this->object(uuid: 'a');
		$this->resolver->expects($this->once())->method('resolve')->with(['uuid' => 'a'])->willReturn($live);

		$impersonated = false;
		$this->userSession->method('setUser')->willReturnCallback(
			function ($user) use (&$impersonated): void {
				$impersonated = ($user !== null);
			}
		);
		$seen = [];
		$this->submission->expects($this->once())->method('submitIfEligible')
			->willReturnCallback(function (ObjectEntity $object) use (&$seen, &$impersonated): bool {
				$seen[] = [$object, $impersonated];
				return true;
			});
		$this->submission->expects($this->never())->method('remove');

		$this->runJob([['uuid' => 'a', 'schemaId' => '7']]);

		$this->assertSame([[$live, true]], $seen);
	}

	public function testAGoneObjectHasItsContentRemovedByUuidAndSchema(): void {
		$this->resolver->method('resolve')->willReturn(null);
		$this->submission->expects($this->never())->method('submitIfEligible');

		$removed = [];
		$this->submission->expects($this->once())->method('remove')
			->willReturnCallback(function (ObjectEntity $object) use (&$removed): void {
				$removed[] = [$object->getUuid(), $object->getSchema()];
			});

		$this->runJob([['uuid' => 'gone', 'schemaId' => '7']]);

		$this->assertSame([['gone', '7']], $removed);
	}

	public function testADeleteWhoseUuidWasReCreatedSubmitsInsteadOfRemoving(): void {
		// The delete enqueued 'a'; before the job ran, 'a' was created again.
		$this->resolver->method('resolve')->willReturn($this->object(uuid: 'a'));
		$this->submission->expects($this->never())->method('remove');
		$this->submission->expects($this->once())->method('submitIfEligible')->willReturn(true);

		$this->runJob([['uuid' => 'a', 'schemaId' => '7']]);
	}

	public function testAnEntryWithoutUuidIsSkipped(): void {
		$this->resolver->expects($this->never())->method('resolve');
		$this->submission->expects($this->never())->method('remove');
		$this->submission->expects($this->never())->method('submitIfEligible');

		$this->runJob([['schemaId' => '7'], ['uuid' => '', 'schemaId' => '7']]);
	}

	public function testOneFailingEntryDoesNotAbortTheChunk(): void {
		$this->resolver->method('resolve')->willReturnCallback(
			fn (array $entry): ObjectEntity => $this->object(uuid: $entry['uuid'])
		);

		$submitted = [];
		$this->submission->expects($this->exactly(2))->method('submitIfEligible')
			->willReturnCallback(function (ObjectEntity $object) use (&$submitted): bool {
				if ($object->getUuid() === 'a') {
					throw new \RuntimeException('context chat down');
				}

				$submitted[] = $object->getUuid();
				return true;
			});
		$this->logger->expects($this->once())->method('warning')
			->with('[ContextChatSubmissionJob] Context Chat update failed for entry', $this->callback(
				fn (array $context): bool => $context['uuid'] === 'a' && $context['error'] === 'context chat down'
			));

		$this->runJob([['uuid' => 'a', 'schemaId' => '7'], ['uuid' => 'b', 'schemaId' => '7']]);

		$this->assertSame(['b'], $submitted);
	}
}
