<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\FlowRunRetentionJob;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Flow-log retention: an administrator default, overridable per flow in both
 * directions.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
class FlowRunRetentionJobTest extends TestCase {
	private function flowWithRetention(string $uuid, ?int $days): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setRetentionDays($days);

		return $flow;
	}//end flowWithRetention()

	/**
	 * Build the job, capturing the cutoffs it asks the mapper to delete by.
	 *
	 * @param string $configured The configured retention value.
	 * @param array<int, Flow> $overriding Flows declaring their own period.
	 * @param array<string,mixed> $captured Filled with the observed calls.
	 *
	 * @return FlowRunRetentionJob The job.
	 */
	private function job(string $configured, array $overriding, array &$captured): FlowRunRetentionJob {
		$captured = ['instance' => null, 'excluded' => null, 'perFlow' => []];

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($configured);

		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findWithRetentionOverride')->willReturn($overriding);

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('deleteTerminalOlderThanExcluding')->willReturnCallback(
			function (DateTime $cutoff, array $exclude) use (&$captured): array {
				$captured['instance'] = $cutoff;
				$captured['excluded'] = $exclude;
				return [];
			}
		);
		$runs->method('deleteTerminalOlderThan')->willReturnCallback(
			function (DateTime $cutoff, ?string $flowId) use (&$captured): array {
				$captured['perFlow'][$flowId] = $cutoff;
				return [];
			}
		);

		return new FlowRunRetentionJob(
			$this->createMock(ITimeFactory::class),
			$runs,
			$this->createMock(FlowRunStepMapper::class),
			$flows,
			$config,
			new \Psr\Log\NullLogger()
		);
	}//end job()

	/**
	 * Invoke the protected run().
	 *
	 * @param FlowRunRetentionJob $job The job.
	 *
	 * @return void
	 */
	private function sweep(FlowRunRetentionJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end sweep()

	private function daysAgo(DateTime $cutoff): int {
		return (int)(new DateTime())->diff($cutoff)->days;
	}//end daysAgo()

	public function testTheDefaultIsThirtyOneDays(): void {
		$captured = [];
		$job = $this->job('', [], $captured);
		$this->sweep($job);

		$this->assertSame(31, $this->daysAgo($captured['instance']));
	}//end testTheDefaultIsThirtyOneDays()

	/**
	 * A mistyped zero must not mean "delete everything now".
	 */
	public function testANonPositiveSettingFallsBackToTheDefault(): void {
		$captured = [];
		$job = $this->job('0', [], $captured);
		$this->sweep($job);

		$this->assertSame(31, $this->daysAgo($captured['instance']));
	}//end testANonPositiveSettingFallsBackToTheDefault()

	public function testAConfiguredValueIsHonoured(): void {
		$captured = [];
		$job = $this->job('14', [], $captured);
		$this->sweep($job);

		$this->assertSame(14, $this->daysAgo($captured['instance']));
	}//end testAConfiguredValueIsHonoured()

	/**
	 * A SHORTER override: the flow is excluded from the instance pass and swept
	 * on its own, tighter cutoff.
	 */
	public function testAShorterOverrideIsSweptSeparately(): void {
		$captured = [];
		$job = $this->job('31', [$this->flowWithRetention('noisy', 7)], $captured);
		$this->sweep($job);

		$this->assertSame(['noisy'], $captured['excluded'], 'the overriding flow is excluded from the instance pass');
		$this->assertSame(7, $this->daysAgo($captured['perFlow']['noisy']));
	}//end testAShorterOverrideIsSweptSeparately()

	/**
	 * A LONGER override must work too — the requirement is explicit that the
	 * override wins in both directions, not just downward.
	 */
	public function testALongerOverrideIsSweptSeparately(): void {
		$captured = [];
		$job = $this->job('31', [$this->flowWithRetention('audited', 365)], $captured);
		$this->sweep($job);

		$this->assertSame(['audited'], $captured['excluded']);
		$this->assertSame(365, $this->daysAgo($captured['perFlow']['audited']));
	}//end testALongerOverrideIsSweptSeparately()

	/**
	 * A flow with no override is NOT excluded, so it tracks the administrator
	 * setting — including later changes to it.
	 */
	public function testAFlowWithoutAnOverrideTracksTheInstanceSetting(): void {
		$captured = [];
		$job = $this->job('14', [], $captured);
		$this->sweep($job);

		$this->assertSame([], $captured['excluded']);
		$this->assertSame([], $captured['perFlow']);
		$this->assertSame(14, $this->daysAgo($captured['instance']));
	}//end testAFlowWithoutAnOverrideTracksTheInstanceSetting()
}//end class
