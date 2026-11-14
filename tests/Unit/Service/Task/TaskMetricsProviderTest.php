<?php

/**
 * The overdue-task gauge, and the manifest entry that advertises it.
 *
 * Two things can drift apart here and neither would fail loudly at runtime:
 * the sample name the provider emits versus the `provider` descriptor's name
 * in src/manifest.json, and the declared task metrics versus the table and
 * column they read. Both are pinned below, alongside the provider's own
 * behaviour: it counts through TaskMapper with the clock from
 * TaskTemporalProjection, and never with a clock of its own.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskMetricsProvider;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskMetricsProvider
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricDescriptor
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricSample
 * @uses \OCA\OpenRegister\AppHost\Observability\ObservabilityManifest
 * @uses \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthCheckTarget
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthResponsePolicy
 * @uses \OCA\OpenRegister\AppHost\Observability\ObservabilityProbes
 */
class TaskMetricsProviderTest extends TestCase {

	/**
	 * OpenRegister's own manifest, parsed.
	 *
	 * @return array<string, mixed> The manifest.
	 */
	private function manifest(): array {
		$path = (dirname(__DIR__, 4) . '/src/manifest.json');
		$this->assertFileExists($path);
		$decoded = json_decode((string)file_get_contents($path), associative: true);
		$this->assertIsArray($decoded, 'src/manifest.json MUST be valid JSON');

		return $decoded;
	}//end manifest()

	/**
	 * One declared metric, by name.
	 *
	 * @param string $name The metric name.
	 *
	 * @return array<string, mixed> The descriptor.
	 */
	private function declaredMetric(string $name): array {
		foreach (($this->manifest()['observability']['metrics'] ?? []) as $metric) {
			if (($metric['name'] ?? null) === $name) {
				return $metric;
			}
		}

		$this->fail(sprintf('src/manifest.json declares no metric named "%s"', $name));
	}//end declaredMetric()

	/**
	 * The gauge is one sample, named and typed, carrying the mapper's count.
	 *
	 * @return void
	 */
	public function testTheGaugeCarriesTheMappersOverdueCount(): void {
		$mapper = $this->createMock(originalClassName: TaskMapper::class);
		$mapper->expects($this->once())
			->method('countOverdueOpen')
			->willReturn(17);

		$samples = (new TaskMetricsProvider(mapper: $mapper, temporal: new TaskTemporalProjection()))->metrics();

		$this->assertCount(3, $samples);
		$this->assertInstanceOf(MetricSample::class, $samples[0]);
		$this->assertSame('tasks_overdue_total', $samples[0]->name);
		$this->assertSame('gauge', $samples[0]->type);
		$this->assertSame([['labels' => [], 'value' => 17]], $samples[0]->samples);
	}//end testTheGaugeCarriesTheMappersOverdueCount()

	/**
	 * The spec's scenario: open tasks in 120 flows give 101 series, the last `flow="other"`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-operators-can-chart-open-and-overdue-work-per-flow
	 */
	public function testLabelCardinalityStaysBounded(): void {
		$open = [];
		for ($i = 1; $i <= 120; $i++) {
			$open['flow-' . $i] = (200 - $i);
		}

		$mapper = $this->createMock(originalClassName: TaskMapper::class);
		$mapper->method('countOpenByFlow')->willReturnCallback(
			static fn (?DateTime $overdueAt): array => $overdueAt === null ? $open : ['flow-120' => 3, 'flow-1' => 2, 'flow-50' => 1]
		);

		$samples = (new TaskMetricsProvider(mapper: $mapper, temporal: new TaskTemporalProjection()))->metrics();
		[, $openByFlow, $overdueByFlow] = $samples;

		$this->assertSame('tasks_open_by_flow', $openByFlow->name);
		$this->assertSame('gauge', $openByFlow->type);
		$this->assertCount(101, $openByFlow->samples);
		$this->assertSame(['labels' => ['flow' => 'flow-1'], 'value' => 199], $openByFlow->samples[0]);
		$this->assertSame(['labels' => ['flow' => 'other'], 'value' => array_sum(array_slice($open, 100))], $openByFlow->samples[100]);

		// The overdue gauge charts the SAME flows, so flow-120 (beyond the cap) lands in other.
		$this->assertSame('tasks_overdue_by_flow', $overdueByFlow->name);
		$this->assertCount(101, $overdueByFlow->samples);
		$this->assertSame(['labels' => ['flow' => 'flow-1'], 'value' => 2], $overdueByFlow->samples[0]);
		$this->assertSame(['labels' => ['flow' => 'flow-2'], 'value' => 0], $overdueByFlow->samples[1]);
		$this->assertSame(['labels' => ['flow' => 'flow-50'], 'value' => 1], $overdueByFlow->samples[49]);
		$this->assertSame(['labels' => ['flow' => 'other'], 'value' => 3], $overdueByFlow->samples[100]);
	}//end testLabelCardinalityStaysBounded()

	/**
	 * Under the cap every flow gets its own series and there is no `other`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-operators-can-chart-open-and-overdue-work-per-flow
	 */
	public function testFewFlowsHaveNoOtherSeries(): void {
		$mapper = $this->createMock(originalClassName: TaskMapper::class);
		$mapper->method('countOpenByFlow')->willReturnCallback(
			static fn (?DateTime $overdueAt): array => $overdueAt === null ? ['flow-a' => 4, 'flow-b' => 1] : []
		);

		[, $openByFlow, $overdueByFlow] = (new TaskMetricsProvider(mapper: $mapper, temporal: new TaskTemporalProjection()))->metrics();

		$this->assertSame(
			[['labels' => ['flow' => 'flow-a'], 'value' => 4], ['labels' => ['flow' => 'flow-b'], 'value' => 1]],
			$openByFlow->samples
		);
		$this->assertSame(
			[['labels' => ['flow' => 'flow-a'], 'value' => 0], ['labels' => ['flow' => 'flow-b'], 'value' => 0]],
			$overdueByFlow->samples
		);
	}//end testFewFlowsHaveNoOtherSeries()

	/**
	 * The clock is TaskTemporalProjection's, never one of the provider's own:
	 * a second clock would be a second definition of overdue.
	 *
	 * @return void
	 */
	public function testTheClockComesFromTheOneDerivation(): void {
		$temporal = new TaskTemporalProjection();
		$seen = null;

		$mapper = $this->createMock(originalClassName: TaskMapper::class);
		$mapper->method('countOverdueOpen')->willReturnCallback(
			static function (DateTime $at) use (&$seen): int {
				$seen = $at;

				return 0;
			}
		);

		(new TaskMetricsProvider(mapper: $mapper, temporal: $temporal))->metrics();

		$this->assertInstanceOf(DateTime::class, $seen);
		$this->assertLessThanOrEqual(
			5,
			abs(($seen->getTimestamp() - $temporal->now()->getTimestamp())),
			'the gauge MUST be counted against TaskTemporalProjection::now(), not a clock of its own'
		);
	}//end testTheClockComesFromTheOneDerivation()

	/**
	 * The provider satisfies the interface the engine resolves by alias.
	 *
	 * @return void
	 */
	public function testTheProviderIsAnEngineMetricsProvider(): void {
		$provider = new TaskMetricsProvider(
			mapper: $this->createMock(originalClassName: TaskMapper::class),
			temporal: new TaskTemporalProjection()
		);

		$this->assertInstanceOf(IMetricsProvider::class, $provider);
	}//end testTheProviderIsAnEngineMetricsProvider()

	/**
	 * The manifest's `provider` descriptor names the sample the provider
	 * actually emits. The engine renders the PROVIDER's name, so a drift here
	 * would publish a metric no dashboard is looking for while the manifest
	 * kept advertising the old one.
	 *
	 * @return void
	 */
	public function testTheManifestDescriptorNamesTheSampleTheProviderEmits(): void {
		$descriptor = $this->declaredMetric(name: TaskMetricsProvider::METRIC_NAME);

		$this->assertSame('gauge', $descriptor['type'] ?? null);
		$this->assertSame('provider', $descriptor['source']['kind'] ?? null);
	}//end testTheManifestDescriptorNamesTheSampleTheProviderEmits()

	/**
	 * The total is a tableCount over the tasks table, grouped by the state
	 * column that actually exists on it.
	 *
	 * @return void
	 */
	public function testTheTotalCountsTheTasksTableGroupedByState(): void {
		$descriptor = $this->declaredMetric(name: 'tasks_total');

		$this->assertSame('gauge', $descriptor['type'] ?? null);
		$this->assertSame('tableCount', $descriptor['source']['kind'] ?? null);
		$this->assertSame('openregister_tasks', $descriptor['source']['table'] ?? null);
		$this->assertSame(['state'], $descriptor['source']['groupBy'] ?? null);
	}//end testTheTotalCountsTheTasksTableGroupedByState()

	/**
	 * Both entries survive the engine's own validator, so a scrape renders
	 * them rather than logging a warning and emitting nothing.
	 *
	 * @return void
	 */
	public function testBothTaskMetricsParseThroughTheEngineValidator(): void {
		$parsed = ObservabilityManifest::fromManifest('openregister', $this->manifest());

		$names = array_map(static fn ($descriptor): string => $descriptor->name, $parsed->metrics);
		$this->assertContains('tasks_total', $names);
		$this->assertContains(TaskMetricsProvider::METRIC_NAME, $names);
		$this->assertSame([], $parsed->diagnostics, 'the observability block MUST parse without diagnostics');
	}//end testBothTaskMetricsParseThroughTheEngineValidator()
}//end class
