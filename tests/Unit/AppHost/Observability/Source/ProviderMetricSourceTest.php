<?php

/**
 * ProviderMetricSourceTest.
 *
 * Complements MetricSourceTest (alias discovery, own-container resolution,
 * fallback) with the defensive branches: an alias bound to something that is
 * not an IMetricsProvider, non-sample entries in a provider's output, and a
 * provider that throws.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability\Source;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\AppProviderResolver;
use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers kind(), non-provider aliases, sample filtering and the throw path.
 */
class ProviderMetricSourceTest extends TestCase {
	private const ALIAS = IMetricsProvider::class . '::shillinq';

	private ContainerInterface&MockObject $appContainer;

	private LoggerInterface&MockObject $logger;

	private ProviderMetricSource $source;

	protected function setUp(): void {
		$this->appContainer = $this->createMock(ContainerInterface::class);
		$this->logger       = $this->createMock(LoggerInterface::class);

		$fallback = $this->createMock(ContainerInterface::class);
		$fallback->expects($this->never())->method('has');

		$locator = $this->createMock(AppContainerLocator::class);
		$locator->method('findOr')->with('shillinq', $fallback)->willReturn($this->appContainer);

		$this->source = new ProviderMetricSource(new AppProviderResolver($fallback, $locator), $this->logger);
	}

	private function descriptor(): MetricDescriptor {
		return new MetricDescriptor('bridge', 'gauge', 'provider', []);
	}

	public function testKindIsProvider(): void {
		$this->assertSame('provider', $this->source->kind());
	}

	public function testAliasMissingFromAppContainerYieldsNoSamplesWithoutGet(): void {
		$this->appContainer->expects($this->once())->method('has')->with(self::ALIAS)->willReturn(false);
		$this->appContainer->expects($this->never())->method('get');

		$this->assertSame([], $this->source->collect('shillinq', $this->descriptor()));
	}

	public function testAliasBoundToNonProviderYieldsNoSamples(): void {
		$this->appContainer->method('has')->with(self::ALIAS)->willReturn(true);
		$this->appContainer->expects($this->once())->method('get')->with(self::ALIAS)->willReturn(new \stdClass());
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame([], $this->source->collect('shillinq', $this->descriptor()));
	}

	public function testOnlyMetricSampleEntriesAreKept(): void {
		$first  = new MetricSample('a', 'gauge', 'A', [['labels' => [], 'value' => 1]]);
		$second = new MetricSample('b', 'counter', 'B', [['labels' => [], 'value' => 2]]);
		$provider = new class ($first, $second) implements IMetricsProvider {
			public function __construct(private MetricSample $first, private MetricSample $second) {
			}

			public function metrics(): array {
				return [$this->first, ['name' => 'not-a-sample'], 'junk', $this->second];
			}
		};
		$this->appContainer->method('has')->with(self::ALIAS)->willReturn(true);
		$this->appContainer->method('get')->with(self::ALIAS)->willReturn($provider);

		$this->assertSame([$first, $second], $this->source->collect('shillinq', $this->descriptor()));
	}

	public function testThrowingProviderIsLoggedAndYieldsNoSamples(): void {
		$provider = new class implements IMetricsProvider {
			public function metrics(): array {
				throw new RuntimeException('bridge down');
			}
		};
		$this->appContainer->method('has')->willReturn(true);
		$this->appContainer->method('get')->willReturn($provider);
		$this->logger->expects($this->once())->method('warning')->with(
			'[AppHost\\Metrics] provider for app "shillinq" threw: bridge down',
			$this->callback(static fn (array $ctx): bool => isset($ctx['file'], $ctx['line']))
		);

		$this->assertSame([], $this->source->collect('shillinq', $this->descriptor()));
	}
}
