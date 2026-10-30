<?php

/**
 * The deferred referenced-calculation refresh re-fetches each entry's object
 * and re-saves its referrers at the entry's cascade depth.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ReferencedCalculationJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\ReferencedCalculationJob
 */
class ReferencedCalculationJobTest extends TestCase {

	private DeferredEntryObjectResolver&MockObject $resolver;

	private ReferencedCalculationRefresher&MockObject $refresher;

	private IUserManager&MockObject $userManager;

	private IUserSession&MockObject $session;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver    = $this->createMock(DeferredEntryObjectResolver::class);
		$this->refresher   = $this->createMock(ReferencedCalculationRefresher::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->session     = $this->createMock(IUserSession::class);
	}//end setUp()

	private function runJob(array $entries): void {
		$this->runJobAs('alice', $entries);
	}//end runJob()

	private function runJobAs(string $userId, array $entries): void {
		$job = new ReferencedCalculationJob(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->session,
			userManager: $this->userManager,
			organisation: $this->createMock(OrganisationService::class),
			logger: new NullLogger(),
			resolver: $this->resolver,
			refresher: $this->refresher,
		);
		$argument = (new DeferredListenerContext(userId: $userId, orgUuid: null, entries: $entries))->toJobArguments();
		$method   = (new \ReflectionClass($job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}//end runJobAs()

	public function testTheEntryIsRefreshedAtItsDepth(): void {
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$object = new ObjectEntity();
		$object->setUuid('type-1');
		$this->resolver->method('resolve')->willReturn($object);
		$this->refresher->expects($this->once())->method('refreshChanged')->with($object, ['startableFlows'], 1)->willReturn(2);

		$this->runJob([['uuid' => 'type-1', 'register' => '7', 'schema' => '1', 'changed' => ['startableFlows'], 'depth' => 1]]);
	}//end testTheEntryIsRefreshedAtItsDepth()

	public function testAGoneObjectRefreshesNothing(): void {
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->resolver->method('resolve')->willReturn(null);
		$this->refresher->expects($this->never())->method('refreshChanged');

		$this->runJob([['uuid' => 'gone', 'changed' => ['startableFlows'], 'depth' => 0]]);
	}//end testAGoneObjectRefreshesNothing()

	public function testTheEntryRunsAsTheUserOfTheOriginalUpdate(): void {
		$alice = $this->createMock(IUser::class);
		$bob   = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ['alice' => $alice, 'bob' => $bob][$uid] ?? null);
		$object = new ObjectEntity();
		$object->setUuid('type-1');
		$this->resolver->method('resolve')->willReturn($object);

		$current = null;
		$this->session->method('getUser')->willReturnCallback(static function () use (&$current) {
			return $current;
		});
		$this->session->method('setUser')->willReturnCallback(static function (?IUser $user) use (&$current): void {
			$current = $user;
		});
		$seen = [];
		$this->refresher->method('refreshChanged')->willReturnCallback(static function () use (&$seen, &$current): int {
			$seen[] = $current;
			return 1;
		});

		// The job's own actor is alice (captured first in the process); the entry was bob's update.
		$this->runJobAs('alice', [['uuid' => 'type-1', 'changed' => ['startableFlows'], 'depth' => 1, 'actor' => 'bob']]);

		$this->assertSame([$bob], $seen);
	}//end testTheEntryRunsAsTheUserOfTheOriginalUpdate()

	public function testAnEntryWhoseUserIsGoneIsSkipped(): void {
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'alice' ? $this->createMock(IUser::class) : null);
		$this->resolver->method('resolve')->willReturn(new ObjectEntity());
		$this->refresher->expects($this->never())->method('refreshChanged');

		$this->runJobAs('alice', [['uuid' => 'type-1', 'changed' => ['startableFlows'], 'depth' => 1, 'actor' => 'gone']]);
	}//end testAnEntryWhoseUserIsGoneIsSkipped()

	public function testAnEntryWithoutAnActorRunsAsNoUserAndTheJobActorIsRestored(): void {
		$alice = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($alice);
		$this->resolver->method('resolve')->willReturn(new ObjectEntity());

		$current = null;
		$this->session->method('getUser')->willReturnCallback(static function () use (&$current) {
			return $current;
		});
		$this->session->method('setUser')->willReturnCallback(static function (?IUser $user) use (&$current): void {
			$current = $user;
		});
		$seen = [];
		$this->refresher->method('refreshChanged')->willReturnCallback(static function () use (&$seen, &$current): int {
			$seen[] = $current;
			return 1;
		});

		$this->runJobAs('alice', [['uuid' => 'type-1', 'changed' => ['startableFlows'], 'depth' => 1]]);

		$this->assertSame([null], $seen, 'not the job\'s captured actor');
		$this->assertNull($current, 'the session the job found is restored');
	}//end testAnEntryWithoutAnActorRunsAsNoUserAndTheJobActorIsRestored()
}//end class
