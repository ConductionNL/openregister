<?php

/**
 * The request, request duration and error metrics families.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Service\Observability\RequestMetricsProvider;
use OCA\OpenRegister\Service\Observability\RequestMetricsStore;
use OCA\OpenRegister\Service\Observability\RouteTemplates;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\RequestMetricsProvider
 * @uses   \OCA\OpenRegister\Service\Observability\RouteTemplates
 * @uses   \OCA\OpenRegister\Service\Observability\HistogramPoints
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class RequestMetricsProviderTest extends TestCase {

	/**
	 * Requests by method, endpoint template and status; routes with the same template are added up.
	 *
	 * @return void
	 */
	public function testRequestCounterLabelsAndSums(): void {
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('requestTotals')->willReturn(
			[
				['method' => 'GET', 'route' => 'objects.index', 'status' => '200', 'count' => 50, 'sumMs' => 0],
				['method' => 'GET', 'route' => 'objects.list', 'status' => '200', 'count' => 2, 'sumMs' => 0],
				['method' => 'POST', 'route' => 'objects.create', 'status' => '422', 'count' => 3, 'sumMs' => 0],
			]
		);
		$store->method('bucketTotals')->willReturn([]);
		$store->method('errorTotals')->willReturn([]);

		$metrics = $this->provider(store: $store)->metrics();

		$this->assertSame(['requests_total', 'request_duration_seconds', 'errors_total'], array_map(static fn (MetricSample $m): string => $m->name, $metrics));
		$this->assertSame('counter', $metrics[0]->type);
		$this->assertSame(
			[
				['labels' => ['method' => 'GET', 'endpoint' => '/api/objects', 'status' => '200'], 'value' => 52],
				['labels' => ['method' => 'POST', 'endpoint' => '/api/objects', 'status' => '422'], 'value' => 3],
			],
			$metrics[0]->samples
		);
	}//end testRequestCounterLabelsAndSums()

	/**
	 * The histogram is cumulative over the nine bounds, `+Inf` adds the overflow, the sum comes from the request rows.
	 *
	 * @return void
	 */
	public function testDurationHistogramIsCumulative(): void {
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('requestTotals')->willReturn(
			[
				['method' => 'GET', 'route' => 'objects.index', 'status' => '200', 'count' => 6, 'sumMs' => 2500],
				['method' => 'GET', 'route' => 'objects.index', 'status' => '500', 'count' => 1, 'sumMs' => 12000],
			]
		);
		$store->method('bucketTotals')->willReturn(
			[
				['method' => 'GET', 'route' => 'objects.index', 'bucket' => '50', 'count' => 4],
				['method' => 'GET', 'route' => 'objects.index', 'bucket' => '1000', 'count' => 2],
				['method' => 'GET', 'route' => 'objects.index', 'bucket' => 'inf', 'count' => 1],
				['method' => 'GET', 'route' => 'objects.index', 'bucket' => '7', 'count' => 99],
			]
		);
		$store->method('errorTotals')->willReturn([]);

		$histogram = $this->provider(store: $store)->metrics()[1];
		$this->assertSame('histogram', $histogram->type);

		$byLe = [];
		foreach ($histogram->samples as $point) {
			if ($point['suffix'] === '_bucket') {
				$byLe[$point['labels']['le']] = $point['value'];
				$this->assertSame(['method' => 'GET', 'endpoint' => '/api/objects'], array_diff_key($point['labels'], ['le' => true]));
			} else {
				$byLe[$point['suffix']] = $point['value'];
			}
		}

		$this->assertSame(
			['0.01' => 0, '0.05' => 4, '0.1' => 4, '0.25' => 4, '0.5' => 4, '1.0' => 6, '2.5' => 6, '5.0' => 6, '10.0' => 6, '+Inf' => 7, '_sum' => 14.5, '_count' => 7],
			$byLe
		);
	}//end testDurationHistogramIsCumulative()

	/**
	 * Errors by type, with database, validation and other always present.
	 *
	 * @return void
	 */
	public function testErrorCounterHasTheStandardTypes(): void {
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('requestTotals')->willReturn([]);
		$store->method('bucketTotals')->willReturn([]);
		$store->method('errorTotals')->willReturn(['database' => 2, 'validation' => 5]);

		$errors = $this->provider(store: $store)->metrics()[2];

		$this->assertSame('errors_total', $errors->name);
		$this->assertSame(
			[
				['labels' => ['type' => 'database'], 'value' => 2],
				['labels' => ['type' => 'validation'], 'value' => 5],
				['labels' => ['type' => 'other'], 'value' => 0],
			],
			$errors->samples
		);
	}//end testErrorCounterHasTheStandardTypes()

	/**
	 * A failing request query drops the request families only; a failing error query drops only errors.
	 *
	 * @return void
	 */
	public function testAFailingQueryDropsOnlyItsFamilies(): void {
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('requestTotals')->willThrowException(new RuntimeException('gone'));
		$store->method('errorTotals')->willReturn([]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('request metrics unavailable'));

		$metrics = $this->provider(store: $store, logger: $logger)->metrics();
		$this->assertSame(['errors_total'], array_map(static fn (MetricSample $m): string => $m->name, $metrics));

		$broken = $this->createMock(RequestMetricsStore::class);
		$broken->method('requestTotals')->willReturn([]);
		$broken->method('bucketTotals')->willReturn([]);
		$broken->method('errorTotals')->willThrowException(new RuntimeException('gone'));
		$this->assertSame(
			['requests_total', 'request_duration_seconds'],
			array_map(static fn (MetricSample $m): string => $m->name, $this->provider(store: $broken)->metrics())
		);
	}//end testAFailingQueryDropsOnlyItsFamilies()

	/**
	 * The provider under test, over a small route table.
	 *
	 * @param RequestMetricsStore  $store  The store.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return RequestMetricsProvider
	 */
	private function provider(RequestMetricsStore $store, ?LoggerInterface $logger = null): RequestMetricsProvider {
		return new RequestMetricsProvider(
			store: $store,
			templates: RouteTemplates::forRoutes(
				routes: [
					'routes' => [
						['name' => 'objects#index', 'url' => '/api/objects'],
						['name' => 'objects#list', 'url' => '/api/objects'],
						['name' => 'objects#create', 'url' => '/api/objects'],
					],
				]
			),
			logger: $logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end provider()
}//end class
