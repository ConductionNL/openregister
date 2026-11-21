<?php

/**
 * Characterisation tests for the step row FlowStepHistory writes per hop.
 *
 * Pins every field of a written row (and the order the fields are set in,
 * which is the column order of the INSERT), the defaults for a sparse entry,
 * the warning for a row that cannot be written and the hop audit call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Service\Flow\FlowStepHistory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class FlowStepHistoryRowTest extends TestCase {

	/** @var array<int, FlowRunStep> */
	private array $written = [];

	private function history(LoggerInterface $logger, ?string $failOn = null): FlowStepHistory {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(6);
		$steps->method('insert')->willReturnCallback(
			function (FlowRunStep $step) use ($failOn): FlowRunStep {
				$this->written[] = $step;
				if ($step->getNodeId() === $failOn) {
					throw new RuntimeException('disk full');
				}

				return $step;
			}
		);

		return new FlowStepHistory(steps: $steps, logger: $logger);
	}

	private function aRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-9');
		$run->setFlowId('flow-9');

		return $run;
	}

	public function testAFullEntryFillsEveryFieldInOrder(): void {
		$before = new DateTime('-1 second');
		$this->history($this->createMock(LoggerInterface::class))->record(
			$this->aRun(),
			[
				[
					'transition' => 'check',
					'type' => 'decision',
					'streamId' => 's-2',
					'ordinalPath' => '0001.0002',
					'status' => 'failed',
					'durationMs' => 42,
					'error' => 'no match',
					'itemsIn' => 3,
					'checkId' => 'c-1',
				],
			]
		);

		$step = $this->written[0];
		$this->assertSame(
			[
				'runUuid', 'flowId', 'nodeId', 'nodeType', 'sequence', 'streamId', 'ordinalPath',
				'status', 'durationMs', 'created', 'finished', 'error', 'output',
			],
			array_keys($step->getUpdatedFields())
		);
		$this->assertSame('run-9', $step->getRunUuid());
		$this->assertSame('flow-9', $step->getFlowId());
		$this->assertSame('check', $step->getNodeId());
		$this->assertSame('decision', $step->getNodeType());
		$this->assertSame(7, $step->getSequence());
		$this->assertSame('s-2', $step->getStreamId());
		$this->assertSame('0001.0002', $step->getOrdinalPath());
		$this->assertSame('failed', $step->getStatus());
		$this->assertSame(42, $step->getDurationMs());
		$this->assertSame('no match', $step->getError());
		$this->assertSame(['itemsIn' => 3, 'checkId' => 'c-1'], $step->getOutput());
		$this->assertGreaterThanOrEqual($before, $step->getCreated());
		$this->assertGreaterThanOrEqual($before, $step->getFinished());
	}

	public function testASparseEntryGetsTheDefaults(): void {
		$this->history($this->createMock(LoggerInterface::class))->record($this->aRun(), [[]]);

		$step = $this->written[0];
		$this->assertSame('', $step->getNodeId());
		$this->assertNull($step->getNodeType());
		$this->assertNull($step->getStreamId());
		$this->assertSame('0001', $step->getOrdinalPath());
		$this->assertSame('unknown', $step->getStatus());
		$this->assertNull($step->getDurationMs());
		$this->assertSame(7, $step->getSequence());
	}

	public function testAnUnwritableRowIsLoggedWithItsRunAndTheNextStillCounts(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[FlowStepHistory] Could not record a step row for run run-9: disk full',
			$this->callback(static fn (array $context): bool => $context['file'] !== '' && is_int($context['line']))
		);

		$this->history($logger, 'a')->record(
			$this->aRun(),
			['skipped', ['transition' => 'a'], ['transition' => 'gone', 'recorded' => true], ['transition' => 'b']]
		);

		$numbered = array_map(static fn (FlowRunStep $s): array => [$s->getNodeId(), $s->getSequence()], $this->written);
		$this->assertSame([['a', 7], ['b', 8]], $numbered);
	}
}//end class
