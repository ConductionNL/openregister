<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\MigrateNotificationSubscriptionsToUserConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the one-shot subscription→user-config migration's defensive
 * degradation: it must never throw, even when the DB/services are unavailable.
 */
class MigrateNotificationSubscriptionsToUserConfigTest extends TestCase {
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;
	private MigrateNotificationSubscriptionsToUserConfig $step;

	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->step = new MigrateNotificationSubscriptionsToUserConfig(
			$this->container,
			$this->logger
		);
	}

	public function testGetNameIsHumanReadable(): void {
		$this->assertStringContainsString('notification subscriptions', $this->step->getName());
	}

	public function testRunSkipsGracefullyWhenDbUnavailable(): void {
		// Container cannot resolve the DB connection (fresh install) — the
		// step must log "skipped" and return without throwing.
		$this->container->method('get')->willThrowException(new \RuntimeException('no service'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('info');

		$this->step->run($output);
		$this->addToAssertionCount(1);
	}

	public function testRunDoesNotThrowOnInfrastructureErrors(): void {
		// Any throwable from the container must be swallowed.
		$this->container->method('get')->willThrowException(new \LogicException('boom'));

		$output = $this->createMock(IOutput::class);

		$this->step->run($output);
		$this->addToAssertionCount(1);
	}
}
