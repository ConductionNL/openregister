<?php

/**
 * Unit tests for ExampleSetFlowRunCheck — why a set's flow run is refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRunCheck;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns;
use PHPUnit\Framework\TestCase;

final class ExampleSetFlowRunCheckTest extends TestCase {

	private ExampleSetFlowRunCheck $check;

	protected function setUp(): void {
		$this->check = new ExampleSetFlowRunCheck();
	}

	private function finished(array $extra = []): array {
		return ['status' => FlowRun::STATUS_COMPLETED, 'subject' => 'obj-1'] + $extra;
	}

	private function nodes(): array {
		return [
			['id' => 'start', 'type' => 'openregister.trigger'],
			['id' => 'approve', 'type' => ExampleSetFlowRuns::STEP_TYPE],
		];
	}

	public function testAFinishedRunWithSubjectAndDatesPasses(): void {
		$this->assertSame(
			'',
			$this->check->refusal($this->finished(['created' => '2026-01-01T10:00:00Z', 'updated' => '2026-01-02']), [])
		);
	}

	public function testEveryTerminalStatusIsAccepted(): void {
		foreach (FlowRun::TERMINAL as $status) {
			$this->assertSame('', $this->check->refusal(['status' => $status, 'subject' => 's'], []), $status);
		}
	}

	public function testAnUnknownFieldIsRefusedFirstNamingEveryField(): void {
		$this->assertSame(
			'field "colour", "size" is not a run field a set may write',
			$this->check->refusal(['colour' => 'red', 'size' => 1, 'status' => 'nonsense'], [])
		);
	}

	public function testARunningStatusIsRefused(): void {
		$refusal = $this->check->refusal(['status' => 'running', 'subject' => 's'], []);

		$this->assertStringStartsWith('a run is finished (status is one of '.implode(', ', FlowRun::TERMINAL).')', $refusal);
	}

	public function testAMissingStatusIsRefused(): void {
		$this->assertStringStartsWith('a run is finished', $this->check->refusal(['subject' => 's'], []));
	}

	public function testASuspendedRunNeedsAKey(): void {
		$this->assertSame(
			'a waiting run needs a key, so a task can name it',
			$this->check->refusal(['status' => FlowRun::STATUS_SUSPENDED, 'key' => '  ', 'node' => 'approve', 'subject' => 's'], $this->nodes())
		);
	}

	public function testASuspendedRunMustWaitOnAUserTaskStep(): void {
		$this->assertSame(
			'waiting run "r1" waits on an '.ExampleSetFlowRuns::STEP_TYPE.' step of its flow (node), not "start"',
			$this->check->refusal(['status' => FlowRun::STATUS_SUSPENDED, 'key' => 'r1', 'node' => 'start', 'subject' => 's'], $this->nodes())
		);
		$this->assertStringContainsString(
			'not ""',
			$this->check->refusal(['status' => FlowRun::STATUS_SUSPENDED, 'key' => 'r1', 'subject' => 's'], $this->nodes())
		);
	}

	public function testASuspendedRunOnAUserTaskStepPasses(): void {
		$this->assertSame(
			'',
			$this->check->refusal(['status' => FlowRun::STATUS_SUSPENDED, 'key' => 'r1', 'node' => 'approve', 'subject' => 's'], $this->nodes())
		);
	}

	public function testNodesGivenAsObjectsAreRead(): void {
		$nodes = [(object) ['id' => 'approve', 'type' => ExampleSetFlowRuns::STEP_TYPE]];

		$this->assertSame(
			'',
			$this->check->refusal(['status' => FlowRun::STATUS_SUSPENDED, 'key' => 'r1', 'node' => 'approve', 'subject' => 's'], $nodes)
		);
	}

	public function testARunNeedsANonBlankSubject(): void {
		$expected = 'a run needs the object it ran on (subject)';

		$this->assertSame($expected, $this->check->refusal(['status' => FlowRun::STATUS_COMPLETED], []));
		$this->assertSame($expected, $this->check->refusal(['status' => FlowRun::STATUS_COMPLETED, 'subject' => ' '], []));
		$this->assertSame($expected, $this->check->refusal(['status' => FlowRun::STATUS_COMPLETED, 'subject' => 5], []));
	}

	public function testAnUnparseableDateIsRefusedNamingTheField(): void {
		$this->assertSame(
			'run updated "next tuesday-ish" is not a date',
			$this->check->refusal($this->finished(['created' => '2026-01-01', 'updated' => 'next tuesday-ish']), [])
		);
		$this->assertSame(
			'run created "garbage" is not a date',
			$this->check->refusal($this->finished(['created' => 'garbage']), [])
		);
	}
}
