<?php

/**
 * How FlowRunAdvancer resolves a run before it walks it: a missing flow or
 * subject fails the run, a subjectless run walks over a bare holder seeded
 * from its payload, and a resolved run is handed to the engine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Flow\FlowExecution;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunOrigin;
use OCA\OpenRegister\Service\Flow\FlowRunPass;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowStreamAdvance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;

/**
 * @coversNothing
 */
final class FlowRunAdvancerResolutionGoldenTest extends TestCase {
	/**
	 * The flow locator.
	 *
	 * @var FlowLocator&MockObject
	 */
	private FlowLocator&MockObject $locator;

	/**
	 * The engine.
	 *
	 * @var FlowRunServiceInterface&MockObject
	 */
	private FlowRunServiceInterface&MockObject $runner;

	/**
	 * The run mapper.
	 *
	 * @var FlowRunMapperInterface&MockObject
	 */
	private FlowRunMapperInterface&MockObject $mapper;

	/**
	 * The passes handed to the engine, as [method, pass, origin or advance].
	 *
	 * @var array<int, array{string, FlowRunPass, object}>
	 */
	private array $passes = [];

	/**
	 * The advancer over fresh doubles.
	 *
	 * @return FlowRunAdvancer
	 */
	private function advancer(): FlowRunAdvancer {
		$this->locator = $this->createMock(FlowLocator::class);
		$this->runner = $this->createMock(FlowRunServiceInterface::class);
		$this->runner->method('executePass')->willReturnCallback(
			function (FlowRunPass $pass, FlowRunOrigin $origin): FlowRun {
				$this->passes[] = ['executePass', $pass, $origin];
				return $pass->run;
			}
		);
		$this->runner->method('advanceBranch')->willReturnCallback(
			function (FlowRunPass $pass, FlowStreamAdvance $advance): FlowRun {
				$this->passes[] = ['advanceBranch', $pass, $advance];
				return $pass->run;
			}
		);
		$this->mapper = $this->createMock(FlowRunMapperInterface::class);

		return new FlowRunAdvancer($this->mapper, new FlowExecution($this->locator, $this->runner, new NullLogger()));
	}//end advancer()

	/**
	 * A run over flow f1, about object o1 in r/s unless it has no subject.
	 *
	 * @param bool $withSubject Whether the run names a subject.
	 *
	 * @return FlowRun
	 */
	private function flowRun(bool $withSubject = true): FlowRun {
		$run = new FlowRun();
		$run->setFlowId('f1');
		$run->setContext(['payload' => ['path' => '/a.txt']]);
		if ($withSubject === true) {
			$run->setSubjectUuid('o1');
			$run->setSubjectRegister('r');
			$run->setSubjectSchema('s');
		}

		return $run;
	}//end flowRun()

	/**
	 * Advance the run the way the named entry point does.
	 *
	 * @param FlowRunAdvancer $advancer The advancer.
	 * @param string          $entry    'advance' or 'advanceStream'.
	 * @param FlowRun         $run      The run.
	 *
	 * @return FlowRun
	 */
	private function enter(FlowRunAdvancer $advancer, string $entry, FlowRun $run): FlowRun {
		if ($entry === 'advanceStream') {
			return $advancer->advanceStream($run, 'stream-1', 'all');
		}

		return $advancer->advance($run);
	}//end enter()

	/**
	 * The first pass handed to the engine.
	 *
	 * @return array{string, FlowRunPass, object}
	 */
	private function firstPass(): array {
		return $this->passes[0];
	}//end firstPass()

	/**
	 * Both entry points.
	 *
	 * @return array<string, array{string}>
	 */
	public static function entries(): array {
		return ['advance' => ['advance'], 'advance stream' => ['advanceStream']];
	}//end entries()

	/**
	 * A flow no app provides fails the run with that reason and walks nothing.
	 *
	 * @param string $entry The entry point.
	 *
	 * @return void
	 */
	#[DataProvider('entries')]
	public function testAMissingFlowFailsTheRun(string $entry): void {
		$advancer = $this->advancer();
		$run = $this->flowRun();
		$this->locator->method('resolveFlow')->with('f1')->willReturn(null);
		$this->locator->expects($this->never())->method('resolveObject');
		$this->mapper->expects($this->once())->method('update')->with($run)->willReturnArgument(0);

		$result = $this->enter(advancer: $advancer, entry: $entry, run: $run);

		$this->assertSame($run, $result);
		$this->assertSame([FlowRun::STATUS_FAILED, 'No app provides flow "f1" (deleted, or its app removed?).'], [$run->getStatus(), $run->getError()]);
		$this->assertSame([], $this->passes);
	}//end testAMissingFlowFailsTheRun()

	/**
	 * A subject that no longer exists fails the run with that reason.
	 *
	 * @param string $entry The entry point.
	 *
	 * @return void
	 */
	#[DataProvider('entries')]
	public function testAMissingSubjectFailsTheRun(string $entry): void {
		$advancer = $this->advancer();
		$run = $this->flowRun();
		$this->locator->method('resolveFlow')->willReturn(['id' => 'f1']);
		$this->locator->expects($this->once())->method('resolveObject')
			->with(new ObjectRef(register: 'r', schema: 's', id: 'o1'))->willReturn(null);
		$this->mapper->expects($this->once())->method('update')->with($run)->willReturnArgument(0);

		$this->enter(advancer: $advancer, entry: $entry, run: $run);

		$this->assertSame([FlowRun::STATUS_FAILED, 'Subject "o1" no longer exists.'], [$run->getStatus(), $run->getError()]);
		$this->assertSame([], $this->passes);
	}//end testAMissingSubjectFailsTheRun()

	/**
	 * A resolved subject is the pass's subject; advance() seeds nothing for it.
	 *
	 * @return void
	 */
	public function testAResolvedRunIsWalkedOverItsSubject(): void {
		$subject = new stdClass();
		$subject->id = 'o1';
		foreach (['advance', 'advanceStream'] as $entry) {
			$this->passes = [];
			$advancer = $this->advancer();
			$this->locator->method('resolveFlow')->willReturn(['id' => 'f1']);
			$this->locator->method('resolveObject')->willReturn($subject);
			$this->mapper->expects($this->never())->method('update');

			$this->enter(advancer: $advancer, entry: $entry, run: $this->flowRun());

			$this->assertCount(1, $this->passes, $entry);
			[$method, $pass, $how] = $this->firstPass();
			$expected = ['advance' => 'executePass', 'advanceStream' => 'advanceBranch'][$entry];
			$this->assertSame([$expected, ['id' => 'f1'], $subject], [$method, $pass->flow, $pass->subject], $entry);
			if ($how instanceof FlowRunOrigin) {
				$this->assertNull($how->items);
			}
		}
	}//end testAResolvedRunIsWalkedOverItsSubject()

	/**
	 * A subjectless run walks over a bare holder; advance() seeds it from the payload.
	 *
	 * @return void
	 */
	public function testASubjectlessRunWalksOverABareHolder(): void {
		foreach (['advance', 'advanceStream'] as $entry) {
			$this->passes = [];
			$advancer = $this->advancer();
			$this->locator->method('resolveFlow')->willReturn(['id' => 'f1']);
			$this->locator->expects($this->never())->method('resolveObject');

			$this->enter(advancer: $advancer, entry: $entry, run: $this->flowRun(withSubject: false));

			[$method, $pass, $how] = $this->firstPass();
			$this->assertEquals(new stdClass(), $pass->subject, $entry);
			if ($method === 'executePass') {
				$this->assertSame([FlowItems::item(json: ['path' => '/a.txt'])], $how->items);
			}
		}
	}//end testASubjectlessRunWalksOverABareHolder()
}//end class
