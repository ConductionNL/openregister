<?php

/**
 * Unit tests for ApplicationPublishListener — applies a manifest's schedules on publish.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Listener
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\AppHost\Listener\ApplicationPublishListener;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowSync;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleReleaseReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ApplicationPublishListenerTest extends TestCase {

	private ScheduleReleaseReader&MockObject $reader;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;
	private ScheduleFlowSync&MockObject $sync;
	private ApplicationPublishListener $listener;

	protected function setUp(): void {
		$this->reader    = $this->createMock(ScheduleReleaseReader::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->sync      = $this->createMock(ScheduleFlowSync::class);
		$this->listener  = new ApplicationPublishListener($this->reader, $this->container, $this->logger);
	}

	private function release(): ScheduleRelease {
		return new ScheduleRelease(new ObjectEntity(), ['schedules' => []], 'alice');
	}

	public function testAnUpdateReadsTheNewObjectAgainstTheOldAndAppliesTheRelease(): void {
		$new     = new ObjectEntity();
		$old     = new ObjectEntity();
		$release = $this->release();

		$this->reader->expects($this->once())->method('releaseOf')
			->with($this->identicalTo($new), $this->identicalTo($old))
			->willReturn($release);
		$this->reader->expects($this->never())->method('releaseOfDeleted');
		$this->container->expects($this->once())->method('get')->with(ScheduleFlowSync::class)->willReturn($this->sync);
		$this->sync->expects($this->once())->method('apply')->with($this->identicalTo($release));
		$this->logger->expects($this->never())->method('error');

		$this->listener->handle(new ObjectUpdatedEvent($new, $old));
	}

	public function testACreateReadsTheObjectWithNoPredecessor(): void {
		$object  = new ObjectEntity();
		$release = $this->release();

		$this->reader->expects($this->once())->method('releaseOf')
			->with($this->identicalTo($object), null)
			->willReturn($release);
		$this->container->method('get')->willReturn($this->sync);
		$this->sync->expects($this->once())->method('apply')->with($this->identicalTo($release));

		$this->listener->handle(new ObjectCreatedEvent($object));
	}

	public function testADeleteUsesTheDeletedReading(): void {
		$object  = new ObjectEntity();
		$release = $this->release();

		$this->reader->expects($this->never())->method('releaseOf');
		$this->reader->expects($this->once())->method('releaseOfDeleted')
			->with($this->identicalTo($object))
			->willReturn($release);
		$this->container->method('get')->willReturn($this->sync);
		$this->sync->expects($this->once())->method('apply')->with($this->identicalTo($release));

		$this->listener->handle(new ObjectDeletedEvent($object));
	}

	public function testAWriteThatIsNoPublishNeverBuildsTheSync(): void {
		$this->reader->method('releaseOf')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$this->listener->handle(new ObjectCreatedEvent(new ObjectEntity()));
	}

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->reader->expects($this->never())->method('releaseOf');
		$this->reader->expects($this->never())->method('releaseOfDeleted');
		$this->container->expects($this->never())->method('get');

		$this->listener->handle(new Event());
	}

	public function testAFailingSyncIsLoggedAndDoesNotFailThePublish(): void {
		$this->reader->method('releaseOf')->willReturn($this->release());
		$this->container->method('get')->willReturn($this->sync);
		$this->sync->method('apply')->willThrowException(new RuntimeException('flow engine down'));

		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('Could not apply a published manifest: flow engine down'));

		$this->listener->handle(new ObjectCreatedEvent(new ObjectEntity()));
	}
}
