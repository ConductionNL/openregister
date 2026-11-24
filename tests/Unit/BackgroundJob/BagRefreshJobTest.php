<?php
/**
 * Unit tests for BagRefreshJob — the monthly BAG refresh, off by default.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\BackgroundJob\BagRefreshJob;
use OCA\OpenRegister\Service\Bag\BagBulkLoader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(BagRefreshJob::class)]
final class BagRefreshJobTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private ContainerInterface&MockObject $container;

	private LoggerInterface&MockObject $logger;

	private BagBulkLoader&MockObject $loader;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->loader    = $this->createMock(BagBulkLoader::class);
	}

	private function config(bool $enabled, string $url): void {
		$this->appConfig->method('getValueBool')->with('openregister', 'bag_bulk_enabled', false)->willReturn($enabled);
		$this->appConfig->method('getValueString')->with('openregister', 'bag_bulk_url', '')->willReturn($url);
	}

	private function runJob(): void {
		$job    = new BagRefreshJob($this->createMock(ITimeFactory::class), $this->appConfig, $this->container, $this->logger);
		$method = (new \ReflectionClass($job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	public function testItDoesNothingWhileDisabled(): void {
		$this->config(false, 'https://bag.example/extract.csv');
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->never())->method($this->anything());

		$this->runJob();
	}

	public function testEnabledWithoutASourceWarnsAndLoadsNothing(): void {
		$this->config(true, '  ');
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('bag_bulk_url is empty'));

		$this->runJob();
	}

	public function testEnabledLoadsTheConfiguredSourceAndLogsTheCounts(): void {
		$counts = ['created' => 1, 'updated' => 0, 'withdrawn' => 0, 'unchanged' => 0, 'invalid' => 0, 'failed' => 0];
		$this->config(true, ' https://bag.example/extract.csv ');
		$this->container->method('get')->with(BagBulkLoader::class)->willReturn($this->loader);
		$this->loader->expects($this->once())->method('loadFrom')->with('https://bag.example/extract.csv')->willReturn($counts);
		$this->logger->expects($this->once())->method('info')->with('[BagRefreshJob] BAG extract loaded', $counts);

		$this->runJob();
	}

	public function testAFailedLoadIsLoggedNotThrown(): void {
		$this->config(true, 'https://bag.example/extract.csv');
		$this->container->method('get')->willReturn($this->loader);
		$this->loader->method('loadFrom')->willThrowException(new RuntimeException('disk full'));
		$this->logger->expects($this->once())->method('error')->with('[BagRefreshJob] BAG extract load failed: disk full');

		$this->runJob();
	}

	public function testItRunsMonthly(): void {
		$job = new BagRefreshJob($this->createMock(ITimeFactory::class), $this->appConfig, $this->container, $this->logger);

		$this->assertSame(2592000, $job->getInterval());
	}
}
