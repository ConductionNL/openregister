<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How FlowRunAdvancer handles a run that cannot proceed or whose pass throws,
 * a subjectless run seeded from its payload, and the revealing-process wrapper.
 *
 * @category Test
 * @package  Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Flow\FlowExecution;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowItemShape;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunOrigin;
use OCA\OpenRegister\Service\Flow\FlowRunPass;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowStreamAdvance;
use OCA\OpenRegister\Service\Rbac\ProcessRevealAuditInterface;
use OCA\OpenRegister\Service\Rbac\RevealingProcess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * A failing pass is logged and swallowed for the worker, rethrown for a synchronous caller.
 */
#[CoversClass(FlowRunAdvancer::class)]
#[UsesClass(FlowExecution::class)]
#[UsesClass(FlowRun::class)]
#[UsesClass(FlowRunPass::class)]
#[UsesClass(FlowRunOrigin::class)]
#[UsesClass(FlowStreamAdvance::class)]
#[UsesClass(FlowItems::class)]
#[UsesClass(FlowItemOptions::class)]
#[UsesClass(FlowItemShape::class)]
#[UsesClass(ObjectRef::class)]
#[UsesClass(RevealingProcess::class)]
class FlowRunAdvancerFailureTest extends TestCase {

	/**
	 * Persists a run that cannot proceed.
	 *
	 * @var FlowRunMapperInterface&MockObject
	 */
	private FlowRunMapperInterface&MockObject $mapper;

	/**
	 * Executes the pass.
	 *
	 * @var FlowRunServiceInterface&MockObject
	 */
	private FlowRunServiceInterface&MockObject $runner;

	/**
	 * Resolves the flow and its subject.
	 *
	 * @var FlowLocator&MockObject
	 */
	private FlowLocator&MockObject $resolvers;

	/**
	 * Records failures.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper    = $this->createMock(FlowRunMapperInterface::class);
		$this->runner    = $this->createMock(FlowRunServiceInterface::class);
		$this->resolvers = $this->createMock(FlowLocator::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The advancer under test, optionally with a reveal audit.
	 *
	 * @param ProcessRevealAuditInterface|null $audit The reveal audit.
	 *
	 * @return FlowRunAdvancer
	 */
	private function advancer(?ProcessRevealAuditInterface $audit = null): FlowRunAdvancer {
		return new FlowRunAdvancer(
			$this->mapper,
			new FlowExecution($this->resolvers, $this->runner, $this->logger),
			$audit
		);
	}//end advancer()

	/**
	 * A run on flow f1, with a subject unless one is left out.
	 *
	 * @param string $subject The subject uuid, '' for a subjectless run.
	 * @param array  $context The run context.
	 *
	 * @return FlowRun
	 */
	private function flowRun(string $subject = 'obj-1', array $context = []): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-7');
		$run->setFlowId('f1');
		$run->setSubjectUuid($subject);
		$run->setSubjectRegister('reg');
		$run->setSubjectSchema('sch');
		$run->setContext($context);
		$run->setTrigger('object.updated');

		return $run;
	}//end flowRun()

	/**
	 * A flow no app provides fails the run with that reason and never walks it.
	 *
	 * @return void
	 */
	public function testAFlowNoAppProvidesFailsTheRun(): void {
		$run = $this->flowRun();
		$this->resolvers->method('resolveFlow')->with('f1')->willReturn(null);
		$this->runner->expects($this->never())->method('executePass');
		$this->mapper->expects($this->once())->method('update')->with($run)->willReturnArgument(0);

		$result = $this->advancer()->advance($run);

		$this->assertSame($run, $result);
		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertSame('No app provides flow "f1" (deleted, or its app removed?).', $run->getError());
	}//end testAFlowNoAppProvidesFailsTheRun()

	/**
	 * advanceStream fails the run the same way and returns what the mapper stored.
	 *
	 * @return void
	 */
	public function testAdvanceStreamOnAMissingFlowReturnsTheStoredRun(): void {
		$run    = $this->flowRun();
		$stored = $this->flowRun();
		$this->resolvers->method('resolveFlow')->willReturn(null);
		$this->runner->expects($this->never())->method('advanceBranch');
		$this->mapper->expects($this->once())->method('update')->with($run)->willReturn($stored);

		$this->assertSame($stored, $this->advancer()->advanceStream($run, 's-1', 'all'));
		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
	}//end testAdvanceStreamOnAMissingFlowReturnsTheStoredRun()

	/**
	 * A pass that throws is logged with the run's uuid and the run comes back untouched.
	 *
	 * @return void
	 */
	public function testAThrowingPassIsLoggedAndSwallowedByDefault(): void {
		$run = $this->flowRun();
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'f1']);
		$this->resolvers->method('resolveObject')->willReturn(new stdClass());
		$this->runner->method('executePass')->willThrowException(new RuntimeException('boom'));
		$this->mapper->expects($this->never())->method('update');
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[FlowRunAdvancer] Failed to advance a run',
				$this->callback(
					static fn (array $context): bool => $context['run'] === 'run-7' && $context['error'] === 'boom'
				)
			);

		$this->assertSame($run, $this->advancer()->advance($run));
		$this->assertNull($run->getError());
	}//end testAThrowingPassIsLoggedAndSwallowedByDefault()

	/**
	 * A synchronous caller asking for the error gets it, after it was logged.
	 *
	 * @return void
	 */
	public function testAThrowingPassIsRethrownWhenAsked(): void {
		$run = $this->flowRun();
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'f1']);
		$this->resolvers->method('resolveObject')->willReturn(new stdClass());
		$this->runner->method('executePass')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('boom');

		$this->advancer()->advance($run, true);
	}//end testAThrowingPassIsRethrownWhenAsked()

	/**
	 * A subjectless run is never resolved as an object and is seeded from its payload.
	 *
	 * @return void
	 */
	public function testASubjectlessRunIsSeededFromItsPayload(): void {
		$run = $this->flowRun(subject: '', context: ['payload' => ['path' => '/a.txt']]);
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'f1']);
		$this->resolvers->expects($this->never())->method('resolveObject');

		$seed = null;
		$this->runner->expects($this->once())
			->method('executePass')
			->willReturnCallback(
				function (FlowRunPass $pass, FlowRunOrigin $origin) use (&$seed): FlowRun {
					$this->assertInstanceOf(stdClass::class, $pass->subject);
					$this->assertSame([], get_object_vars($pass->subject));
					$seed = $origin->items;

					return $pass->run;
				}
			);

		$this->advancer()->advance($run);

		$this->assertCount(1, $seed);
		$this->assertSame(['path' => '/a.txt'], $seed[0][FlowItems::JSON]);
	}//end testASubjectlessRunIsSeededFromItsPayload()

	/**
	 * With a reveal audit, the pass runs inside a process named after the run.
	 *
	 * @return void
	 */
	public function testThePassRunsAsARevealingProcessNamedAfterTheRun(): void {
		$run  = $this->flowRun();
		$done = $this->flowRun();
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'f1']);
		$this->resolvers->method('resolveObject')->willReturn(new stdClass());
		$this->runner->expects($this->once())->method('advanceBranch')->willReturn($done);

		$audit = $this->createMock(ProcessRevealAuditInterface::class);
		$audit->expects($this->once())
			->method('run')
			->willReturnCallback(
				function (RevealingProcess $process, callable $operation): mixed {
					$this->assertSame('flow', $process->process);
					$this->assertSame('run-7', $process->runId);

					return $operation();
				}
			);

		$this->assertSame($done, $this->advancer($audit)->advanceStream($run, 's-1', 2));
	}//end testThePassRunsAsARevealingProcessNamedAfterTheRun()
}//end class
