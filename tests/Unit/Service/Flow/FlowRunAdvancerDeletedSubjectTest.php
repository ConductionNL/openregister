<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression coverage for the `object.deleted` trigger gap.
 *
 * `object.deleted` is the one lifecycle event whose subject can never be
 * re-resolved: the object is already gone by the time any run, inline or
 * queued, reaches it. Before this fix, `FlowRunAdvancer::advance()` and
 * `::advanceStream()` treated that exactly like a resolution failure and
 * failed the run with "Subject ... no longer exists" — unconditionally, on
 * every single `object.deleted`-triggered run. `FlowTriggerListener` now rides
 * the deleted object's last-known state along as `context.payload`, and these
 * tests pin that the advancer uses it instead of failing.
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowExecution;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunOrigin;
use OCA\OpenRegister\Service\Flow\FlowRunPass;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowStreamAdvance;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;

class FlowRunAdvancerDeletedSubjectTest extends TestCase {

	private FlowRunMapperInterface&MockObject $mapper;

	private FlowRunServiceInterface&MockObject $runner;

	private FlowLocator&MockObject $resolvers;

	private FlowRunAdvancer $advancer;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(FlowRunMapperInterface::class);
		$this->runner = $this->createMock(FlowRunServiceInterface::class);
		$this->resolvers = $this->createMock(FlowLocator::class);

		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'f1']);

		$this->advancer = new FlowRunAdvancer(
			$this->mapper,
			new FlowExecution($this->resolvers, $this->runner, new NullLogger())
		);
	}

	/**
	 * @param array $context The run's context, e.g. `['payload' => [...]]`.
	 * @param string $trigger The run's trigger event id.
	 */
	private function runWithDeletedSubject(array $context, string $trigger = 'object.deleted'): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('f1');
		$run->setSubjectUuid('gone-1');
		$run->setSubjectRegister('woo');
		$run->setSubjectSchema('convenanten');
		$run->setContext($context);
		$run->setTrigger($trigger);

		return $run;
	}

	public function testAResolvableSubjectIsUnaffected(): void {
		$run = $this->runWithDeletedSubject([]);
		$subject = new stdClass();
		$this->resolvers->method('resolveObject')->willReturn($subject);

		$this->runner->expects($this->once())
			->method('executePass')
			->willReturnCallback(
				function (FlowRunPass $pass, FlowRunOrigin $origin) use ($run, $subject): FlowRun {
					$this->assertSame($run, $pass->run);
					$this->assertSame(['id' => 'f1'], $pass->flow);
					$this->assertSame($subject, $pass->subject);
					$this->assertNull($origin->items);

					return $run;
				}
			);

		$this->mapper->expects($this->never())->method('update');

		$this->advancer->advance($run);
	}

	public function testAnUnresolvableSubjectWithNoPayloadStillFails(): void {
		$run = $this->runWithDeletedSubject([]);
		$this->resolvers->method('resolveObject')->willReturn(null);

		$this->runner->expects($this->never())->method('executePass');
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->advancer->advance($run);

		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertStringContainsString('no longer exists', (string)$run->getError());
	}

	/**
	 * The fallback is scoped to `object.deleted` specifically — not to "any
	 * trigger with an unresolved subject and a payload". A sub-flow
	 * fire-and-forget child, for instance, queues with both a real subject
	 * AND a payload snapshot (its parent's current item); if its target is
	 * deleted before the queued run executes, that IS a genuine resolution
	 * failure and must still fail, not silently continue on stale data.
	 */
	public function testAnUnresolvableSubjectWithAPayloadOnAnotherTriggerStillFails(): void {
		$run = $this->runWithDeletedSubject(['payload' => ['uuid' => 'gone-1']], trigger: 'object.updated');
		$this->resolvers->method('resolveObject')->willReturn(null);

		$this->runner->expects($this->never())->method('executePass');
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->advancer->advance($run);

		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertStringContainsString('no longer exists', (string)$run->getError());
	}

	public function testADeletedSubjectWithASnapshotRunsFromThePayloadInstead(): void {
		$snapshot = ['uuid' => 'gone-1', 'kenmerk' => 'K-1'];
		$run = $this->runWithDeletedSubject(['payload' => $snapshot]);
		$this->resolvers->method('resolveObject')->willReturn(null);

		$seenSeed = null;
		$this->runner->expects($this->once())
			->method('executePass')
			->willReturnCallback(
				function (FlowRunPass $pass, FlowRunOrigin $origin) use (&$seenSeed, $run): FlowRun {
					$this->assertSame($run, $pass->run);
					$this->assertInstanceOf(stdClass::class, $pass->subject);
					$seenSeed = $origin->items;

					return $pass->run;
				}
			);

		$this->mapper->expects($this->never())->method('update');

		$this->advancer->advance($run);

		$this->assertNotNull($seenSeed);
		$this->assertCount(1, $seenSeed);
		$this->assertSame($snapshot, $seenSeed[0]['json']);
	}

	public function testAdvanceStreamAlsoFallsBackToThePayloadRatherThanFailing(): void {
		$run = $this->runWithDeletedSubject(['payload' => ['uuid' => 'gone-1']]);
		$this->resolvers->method('resolveObject')->willReturn(null);

		$this->runner->expects($this->once())
			->method('advanceBranch')
			->willReturnCallback(
				function (FlowRunPass $pass, FlowStreamAdvance $advance) use ($run): FlowRun {
					$this->assertSame($run, $pass->run);
					$this->assertInstanceOf(stdClass::class, $pass->subject);
					$this->assertSame('stream-1', $advance->streamId);
					$this->assertSame(0, $advance->budget);

					return $run;
				}
			);

		$this->mapper->expects($this->never())->method('update');

		$this->advancer->advanceStream($run, 'stream-1', 0);
	}

	public function testAdvanceStreamStillFailsWithoutAPayload(): void {
		$run = $this->runWithDeletedSubject([]);
		$this->resolvers->method('resolveObject')->willReturn(null);

		$this->runner->expects($this->never())->method('advanceBranch');
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->advancer->advanceStream($run, 'stream-1', 0);

		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
	}
}
