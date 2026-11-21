<?php

/**
 * Unit tests for FlowCommitWrite.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowClaimMapperInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapperInterface;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowCommitWrite;
use OCA\OpenRegister\Service\Flow\FlowFiring;
use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\Flow\FlowRunCommitRows;
use OCA\OpenRegister\Service\Flow\FlowStreamRef;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowCommitWrite
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Db\FlowRunStep
 * @uses   \OCA\OpenRegister\Db\FlowStream
 * @uses   \OCA\OpenRegister\Service\Flow\FlowFiring
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunCommit
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunCommitRows
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStepOutcome
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStreamRef
 */
final class FlowCommitWriteTest extends TestCase {

	/**
	 * The stream mapper.
	 *
	 * @var FlowStreamMapperInterface&MockObject
	 */
	private FlowStreamMapperInterface&MockObject $streams;

	/**
	 * The step mapper.
	 *
	 * @var FlowRunStepMapperInterface&MockObject
	 */
	private FlowRunStepMapperInterface&MockObject $steps;

	/**
	 * Stream rows passed to update(), in order.
	 *
	 * @var FlowStream[]
	 */
	private array $updated = [];

	/**
	 * Rows passed to insert() on the stream mapper, in order.
	 *
	 * @var FlowStream[]
	 */
	private array $inserted = [];

	/**
	 * The unit under test.
	 *
	 * @var FlowCommitWrite
	 */
	private FlowCommitWrite $write;

	/**
	 * Build the write against recording mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->streams = $this->createMock(FlowStreamMapperInterface::class);
		$this->steps   = $this->createMock(FlowRunStepMapperInterface::class);
		$this->streams->method('update')->willReturnCallback(function (Entity $e): Entity {
			$this->updated[] = $e;
			return $e;
		});
		$this->streams->method('insert')->willReturnCallback(function (Entity $e): Entity {
			$this->inserted[] = $e;
			return $e;
		});

		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-9');

		$rows = new FlowRunCommitRows(
			runs: $this->createMock(FlowRunMapperInterface::class),
			streams: $this->streams,
			claims: $this->createMock(FlowClaimMapperInterface::class),
			steps: $this->steps
		);

		$this->write = new FlowCommitWrite(rows: $rows, run: $run);
	}//end setUp()

	/**
	 * Build a stream row.
	 *
	 * @param string $id   The stream id.
	 * @param string $path The ordinal path.
	 *
	 * @return FlowStream The row.
	 */
	private static function stream(string $id, string $path): FlowStream {
		$stream = new FlowStream();
		$stream->setStreamId($id);
		$stream->setOrdinalPath($path);
		$stream->setStatus(FlowRun::STATUS_RUNNING);
		return $stream;
	}//end stream()

	/**
	 * A minted stream starts running after the run's highest step sequence.
	 *
	 * @return void
	 */
	public function testMintStreamInsertsARunningStreamAfterTheHighestSequence(): void {
		$this->steps->expects(self::once())->method('highestSequence')->with('run-1')->willReturn(7);

		$stream = $this->write->mintStream(new FlowStreamRef(streamId: 's1', path: '0001.0002', parent: 's0', place: 'p1'));

		self::assertSame([$stream], $this->inserted);
		self::assertSame('run-1', $stream->getRunUuid());
		self::assertSame('s1', $stream->getStreamId());
		self::assertSame('0001.0002', $stream->getOrdinalPath());
		self::assertSame('s0', $stream->getParentStreamId());
		self::assertSame('p1', $stream->getPlace());
		self::assertSame(FlowRun::STATUS_RUNNING, $stream->getStatus());
		self::assertSame(8, $stream->getNextSequence());
		self::assertSame($this->write->now, $stream->getCreated());
		self::assertSame($this->write->now, $stream->getUpdated());
	}//end testMintStreamInsertsARunningStreamAfterTheHighestSequence()

	/**
	 * A firing that consumes only its own stream folds nothing.
	 *
	 * @return void
	 */
	public function testFoldCarrierWithOneStreamUpdatesNothing(): void {
		$own    = self::stream('a', '0001');
		$firing = (new FlowFiring(streamId: 'a', transition: 't', froms: [], taken: []))->withConsumedStreamIds(['a', 'missing']);

		self::assertSame($own, $this->write->foldCarrier(['a' => $own], $firing));
		self::assertSame([], $this->updated);
	}//end testFoldCarrierWithOneStreamUpdatesNothing()

	/**
	 * A join carries on in the stream at the common prefix; the others complete.
	 *
	 * @return void
	 */
	public function testFoldCarrierPicksTheStreamAtTheCommonPrefix(): void {
		$parent  = self::stream('p', '0001');
		$left    = self::stream('l', '0001.0001');
		$right   = self::stream('r', '0001.0002');
		$streams = ['p' => $parent, 'l' => $left, 'r' => $right];
		$firing  = (new FlowFiring(streamId: 'l', transition: 't', froms: [], taken: []))->withConsumedStreamIds(['r', 'p']);

		$carrier = $this->write->foldCarrier($streams, $firing);

		self::assertSame($parent, $carrier);
		self::assertSame([$right, $left], $this->updated);
		self::assertSame(FlowRun::STATUS_COMPLETED, $left->getStatus());
		self::assertSame(FlowRun::STATUS_COMPLETED, $right->getStatus());
		self::assertSame(FlowRun::STATUS_RUNNING, $parent->getStatus());
	}//end testFoldCarrierPicksTheStreamAtTheCommonPrefix()

	/**
	 * Without a stream at the common prefix the firing's own stream carries on.
	 *
	 * @return void
	 */
	public function testFoldCarrierFallsBackToTheFiringStream(): void {
		$left   = self::stream('l', '0001.0001');
		$right  = self::stream('r', '0001.0002');
		$firing = (new FlowFiring(streamId: 'l', transition: 't', froms: [], taken: []))->withConsumedStreamIds(['r']);

		$carrier = $this->write->foldCarrier(['l' => $left, 'r' => $right], $firing);

		self::assertSame($left, $carrier);
		self::assertSame([$right], $this->updated);
		self::assertSame(FlowRun::STATUS_RUNNING, $left->getStatus());
		self::assertSame(FlowRun::STATUS_COMPLETED, $right->getStatus());
	}//end testFoldCarrierFallsBackToTheFiringStream()

	/**
	 * An appended step takes the stream's next sequence and the entry's outcome.
	 *
	 * @return void
	 */
	public function testAppendStepRecordsTheEntryOnTheCarrier(): void {
		$carrier = self::stream('c', '0001.0003');
		$this->streams->expects(self::once())->method('allocateNextSequence')->with('run-1', 'c')->willReturn(5);
		$this->steps->expects(self::once())->method('insert')->with(self::isInstanceOf(FlowRunStep::class))->willReturnArgument(0);

		$step = $this->write->appendStep(
			$carrier,
			['transition' => 'node-1', 'type' => 'mail', 'status' => 'completed', 'durationMs' => 12, 'error' => 'e', 'itemsIn' => 2]
		);

		self::assertSame('run-1', $step->getRunUuid());
		self::assertSame('flow-9', $step->getFlowId());
		self::assertSame('node-1', $step->getNodeId());
		self::assertSame('mail', $step->getNodeType());
		self::assertSame(5, $step->getSequence());
		self::assertSame('c', $step->getStreamId());
		self::assertSame('0001.0003', $step->getOrdinalPath());
		self::assertSame('completed', $step->getStatus());
		self::assertSame(12, $step->getDurationMs());
		self::assertSame('e', $step->getError());
		self::assertSame(['itemsIn' => 2], $step->getOutput());
		self::assertSame($this->write->now, $step->getFinished());
	}//end testAppendStepRecordsTheEntryOnTheCarrier()

	/**
	 * An entry without transition or status is recorded with the defaults.
	 *
	 * @return void
	 */
	public function testAppendStepDefaultsAnUnknownStatus(): void {
		$this->streams->method('allocateNextSequence')->willReturn(1);

		$step = $this->write->appendStep(self::stream('c', '0001'), []);

		self::assertSame('', $step->getNodeId());
		self::assertNull($step->getNodeType());
		self::assertSame('unknown', $step->getStatus());
		self::assertNull($step->getDurationMs());
	}//end testAppendStepDefaultsAnUnknownStatus()

	/**
	 * One taken edge moves the carrier on with the firing's stream state.
	 *
	 * @return void
	 */
	public function testSettleStreamsContinuesTheCarrierOnOneEdge(): void {
		$carrier = self::stream('c', '0001');
		$carrier->setResumeAt(new \DateTime('2026-01-01'));
		$firing = (new FlowFiring(streamId: 'c', transition: 't', froms: [], taken: ['e1' => 'next']))->withStreamState('waiting', 'why');

		$this->write->settleStreams($firing, $carrier);

		self::assertSame('c', $firing->carrierStreamId);
		self::assertSame([], $firing->childStreamIds);
		self::assertSame([$carrier], $this->updated);
		self::assertSame('waiting', $carrier->getStatus());
		self::assertSame('why', $carrier->getError());
		self::assertNull($carrier->getResumeAt());
		self::assertSame('next', $carrier->getPlace());
		self::assertSame([], $this->inserted);
	}//end testSettleStreamsContinuesTheCarrierOnOneEdge()

	/**
	 * No taken edge ends the carrier's branch.
	 *
	 * @return void
	 */
	public function testSettleStreamsCompletesTheCarrierWithoutEdges(): void {
		$carrier = self::stream('c', '0001');
		$firing  = new FlowFiring(streamId: 'c', transition: 't', froms: [], taken: []);

		$this->write->settleStreams($firing, $carrier);

		self::assertSame(FlowRun::STATUS_COMPLETED, $carrier->getStatus());
		self::assertNull($carrier->getPlace());
		self::assertSame([$carrier], $this->updated);
	}//end testSettleStreamsCompletesTheCarrierWithoutEdges()

	/**
	 * A split completes the carrier and queues one child per edge, reusing an existing child row.
	 *
	 * @return void
	 */
	public function testSettleStreamsSplitsIntoQueuedChildren(): void {
		$carrier  = self::stream('c', '0001');
		$firstId  = FlowRunCommit::streamIdFor(runUuid: 'run-1', path: '0001.0001');
		$secondId = FlowRunCommit::streamIdFor(runUuid: 'run-1', path: '0001.0002');
		$existing = self::stream($secondId, '0001.0002');
		$existing->setStatus(FlowRun::STATUS_COMPLETED);
		$existing->setError('old');
		$this->streams->method('findByRunAndStream')->willReturnCallback(
			static fn (string $run, string $id): ?FlowStream => ($run === 'run-1' && $id === $secondId) ? $existing : null
		);
		$firing = new FlowFiring(streamId: 'c', transition: 't', froms: [], taken: ['e1' => 'left', 'e2' => 'right']);

		$this->write->settleStreams($firing, $carrier);

		self::assertSame(FlowRun::STATUS_COMPLETED, $carrier->getStatus());
		self::assertSame(['left' => $firstId, 'right' => $secondId], $firing->childStreamIds);
		self::assertSame([$carrier, $existing], $this->updated);
		self::assertSame(FlowRun::STATUS_QUEUED, $existing->getStatus());
		self::assertSame('right', $existing->getPlace());
		self::assertNull($existing->getError());

		self::assertCount(1, $this->inserted);
		$child = $this->inserted[0];
		self::assertSame('run-1', $child->getRunUuid());
		self::assertSame($firstId, $child->getStreamId());
		self::assertSame('0001.0001', $child->getOrdinalPath());
		self::assertSame('c', $child->getParentStreamId());
		self::assertSame('left', $child->getPlace());
		self::assertSame(FlowRun::STATUS_QUEUED, $child->getStatus());
		self::assertSame(1, $child->getNextSequence());
	}//end testSettleStreamsSplitsIntoQueuedChildren()
}//end class
