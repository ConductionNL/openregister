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

	protected function setUp(): void {
		parent::setUp();
		$this->resolver    = $this->createMock(DeferredEntryObjectResolver::class);
		$this->refresher   = $this->createMock(ReferencedCalculationRefresher::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
	}//end setUp()

	private function runJob(array $entries): void {
		$job = new ReferencedCalculationJob(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->userManager,
			organisation: $this->createMock(OrganisationService::class),
			logger: new NullLogger(),
			resolver: $this->resolver,
			refresher: $this->refresher,
		);
		$argument = (new DeferredListenerContext(userId: 'alice', orgUuid: null, entries: $entries))->toJobArguments();
		$method   = (new \ReflectionClass($job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}//end runJob()

	public function testTheEntryIsRefreshedAtItsDepth(): void {
		$object = new ObjectEntity();
		$object->setUuid('type-1');
		$this->resolver->method('resolve')->willReturn($object);
		$this->refresher->expects($this->once())->method('refreshChanged')->with($object, ['startableFlows'], 1)->willReturn(2);

		$this->runJob([['uuid' => 'type-1', 'register' => '7', 'schema' => '1', 'changed' => ['startableFlows'], 'depth' => 1]]);
	}//end testTheEntryIsRefreshedAtItsDepth()

	public function testAGoneObjectRefreshesNothing(): void {
		$this->resolver->method('resolve')->willReturn(null);
		$this->refresher->expects($this->never())->method('refreshChanged');

		$this->runJob([['uuid' => 'gone', 'changed' => ['startableFlows'], 'depth' => 0]]);
	}//end testAGoneObjectRefreshesNothing()
}//end class
