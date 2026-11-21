<?php

/**
 * ExampleSetFlowRuns on its own: the run checks, the waiting-run refusals,
 * the write of declared runs and the filling of a waiting run's slot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRunSlot;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRunsWrite;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRunCheck
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRunsWrite
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRunSlot
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\FlowRun
 */
class ExampleSetFlowRunsUnitTest extends TestCase {
	/**
	 * The runs the mapper was handed to insert.
	 *
	 * @var array<int, FlowRun>
	 */
	private array $inserted = [];

	/**
	 * The runs the mapper was handed to update.
	 *
	 * @var array<int, FlowRun>
	 */
	private array $updated = [];

	/**
	 * Flow runs over a mapper that records inserts and updates and finds $found by uuid.
	 *
	 * @param FlowRun|null $found The run findByUuid hands back.
	 *
	 * @return ExampleSetFlowRuns
	 */
	private function runs(?FlowRun $found = null): ExampleSetFlowRuns {
		$mapper = $this->createMock(FlowRunMapperInterface::class);
		$mapper->method('insert')->willReturnCallback(function (Entity $entity): Entity {
			$this->inserted[] = $entity;
			return $entity;
		});
		$mapper->method('update')->willReturnCallback(function (Entity $entity): FlowRun {
			$this->updated[] = $entity;
			return $entity;
		});
		if ($found !== null) {
			$mapper->expects($this->once())->method('findByUuid')->with($found->getUuid())->willReturn($found);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(FlowRunMapperInterface::class)->willReturn($mapper);

		return new ExampleSetFlowRuns(container: $container);
	}

	/**
	 * A stored flow.
	 *
	 * @param string|null $trigger The flow's trigger.
	 *
	 * @return Flow
	 */
	private static function flow(?string $trigger = 'manual'): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setTrigger($trigger);
		$flow->setOrganisation('org-1');

		return $flow;
	}

	/**
	 * A write of $runs with sequential uuids.
	 *
	 * @param array<int, array<string, mixed>> $runs    The runs.
	 * @param string|null                      $trigger The flow's trigger.
	 *
	 * @return ExampleSetRunsWrite
	 */
	private static function write(array $runs, ?string $trigger = 'manual'): ExampleSetRunsWrite {
		$n = 0;
		return new ExampleSetRunsWrite(
			flow: self::flow(trigger: $trigger),
			version: 3,
			runs: $runs,
			newUuid: static function () use (&$n): string {
				$n++;
				return 'run-'.$n;
			}
		);
	}

	public function testRefusalNamesAShapeErrorAndTheFirstFaultyRun(): void {
		$runs  = $this->runs();
		$nodes = [['id' => 'ask', 'type' => 'openregister.user-task']];

		$this->assertSame('runs is a list', $runs->refusal(runs: 'x', nodes: $nodes));
		$this->assertSame('runs is a list', $runs->refusal(runs: ['a' => ['status' => 'completed']], nodes: $nodes));
		$this->assertSame('', $runs->refusal(runs: [], nodes: $nodes));
		$this->assertSame('', $runs->refusal(runs: [['status' => 'completed', 'subject' => 's']], nodes: $nodes));
		$this->assertSame(
			'a run needs the object it ran on (subject)',
			$runs->refusal(runs: [['status' => 'completed', 'subject' => 's'], ['status' => 'completed'], ['status' => 'queued']], nodes: $nodes)
		);
		$this->assertStringContainsString('field "foo"', $runs->refusal(runs: [['status' => 'completed', 'subject' => 's', 'foo' => 1]], nodes: $nodes));
	}

	public function testAWaitingRunNoTaskNamesIsRefusedWithItsFile(): void {
		$fragment = [
			'file'  => 'cases.json',
			'tasks' => [['data' => ['runUuid' => ' {{flow:bezwaar.runs.named}} ']], ['data' => []]],
			'flows' => [
				[
					'key'  => 'bezwaar',
					'data' => ['runs' => [
						['key' => 'named', 'status' => 'suspended'],
						['key' => 'loose', 'status' => 'suspended'],
						['key' => 'done', 'status' => 'completed'],
						['status' => 'suspended'],
						['key' => 5, 'status' => 'suspended'],
					]],
				],
				['key' => 'empty', 'data' => []],
			],
		];

		$this->assertSame(
			['cases.json: flow "bezwaar": waiting run "loose": no task of its fragment names it ({{flow:bezwaar.runs.loose}} as runUuid)'],
			$this->runs()->unnamedRefusals(fragment: $fragment)
		);
		$this->assertSame([], $this->runs()->unnamedRefusals(fragment: ['file' => 'f.json']));
	}

	public function testATaskRefusalChecksTheRunAndTheStepItWaitsOn(): void {
		$runs    = $this->runs();
		$context = ['flows' => [['waitsOn' => ['other' => 'x']], ['waitsOn' => ['run-1' => 'ask']]]];

		$together = 'runUuid and nodeId go together: the run the task waits in, and the step';
		$this->assertSame($together, $runs->taskRefusal(data: ['runUuid' => 'run-1'], context: $context));
		$this->assertSame($together, $runs->taskRefusal(data: ['nodeId' => 'ask', 'runUuid' => '  '], context: $context));
		$this->assertSame('', $runs->taskRefusal(data: [], context: $context));
		$this->assertSame('', $runs->taskRefusal(data: ['runUuid' => 'run-1', 'nodeId' => ' ask '], context: $context));
		$this->assertSame(
			'runUuid "run-9" is not a waiting run a flow of the set declares',
			$runs->taskRefusal(data: ['runUuid' => 'run-9', 'nodeId' => 'ask'], context: $context)
		);
		$this->assertSame(
			'runUuid "run-1" is not a waiting run a flow of the set declares',
			$runs->taskRefusal(data: ['runUuid' => 'run-1', 'nodeId' => 'ask'], context: [])
		);
		$this->assertSame(
			'nodeId "mail" is not the step run "run-1" waits on ("ask")',
			$runs->taskRefusal(data: ['runUuid' => 'run-1', 'nodeId' => 'mail'], context: $context)
		);
	}

	public function testWriteStoresAFinishedRunAsDeclared(): void {
		$entry = $this->runs()->write(write: self::write(runs: [[
			'key'             => 'done',
			'status'          => 'completed',
			'subject'         => 'case-1',
			'subjectRegister' => 'cases',
			'subjectSchema'   => 'case',
			'trigger'         => 'cron',
			'triggeredBy'     => 'esra',
			'error'           => 'none',
			'created'         => '2024-01-01T10:00:00+00:00',
			'updated'         => '2024-01-02T11:00:00+00:00',
		]]));

		$this->assertSame(['runs' => ['done' => 'run-1']], $entry);
		$this->assertCount(1, $this->inserted);
		$run = $this->inserted[0];
		$this->assertSame('run-1', $run->getUuid());
		$this->assertSame('flow-1', $run->getFlowId());
		$this->assertSame(3, $run->getFlowVersion());
		$this->assertSame('completed', $run->getStatus());
		$this->assertSame('case-1', $run->getSubjectUuid());
		$this->assertSame('cases', $run->getSubjectRegister());
		$this->assertSame('case', $run->getSubjectSchema());
		$this->assertSame('cron', $run->getTrigger());
		$this->assertSame('esra', $run->getTriggeredBy());
		$this->assertSame('none', $run->getError());
		$this->assertSame('org-1', $run->getOrganisation());
		$this->assertSame('2024-01-01T10:00:00+00:00', $run->getCreated()->format('c'));
		$this->assertSame('2024-01-02T11:00:00+00:00', $run->getUpdated()->format('c'));
		$this->assertNull($run->getMarking());
		$this->assertNull($run->getContext());
	}

	public function testWriteFallsBackToTheFlowTriggerThenManualAndDatesUpdatedAsCreated(): void {
		$entry = $this->runs()->write(write: self::write(runs: [
			['status' => 'completed', 'subject' => 'a', 'created' => '2024-03-01T09:00:00+00:00'],
		], trigger: 'webhook'));
		$this->runs()->write(write: self::write(runs: [['status' => 'failed', 'subject' => 'b']], trigger: null));

		$this->assertSame([], $entry, 'a run without a key records nothing');
		$this->assertSame('webhook', $this->inserted[0]->getTrigger());
		$this->assertNull($this->inserted[0]->getTriggeredBy());
		$this->assertNull($this->inserted[0]->getSubjectRegister());
		$this->assertSame('2024-03-01T09:00:00+00:00', $this->inserted[0]->getUpdated()->format('c'));
		$this->assertSame('manual', $this->inserted[1]->getTrigger());
		$this->assertInstanceOf(DateTime::class, $this->inserted[1]->getCreated());
	}

	public function testAWaitingRunIsParkedOnItsStep(): void {
		$entry = $this->runs()->write(write: self::write(runs: [
			['key' => 'wacht', 'status' => 'suspended', 'node' => 'ask', 'subject' => 'case-1', 'triggeredBy' => 'esra'],
			['key' => 'klaar', 'status' => 'completed', 'subject' => 'case-2'],
		]));

		$this->assertSame(['waitsOn' => ['run-1' => 'ask'], 'runs' => ['wacht' => 'run-1', 'klaar' => 'run-2']], $entry);
		$run  = $this->inserted[0];
		$item = ['json' => [], 'binary' => [], 'pairedItem' => ['item' => 0]];
		$this->assertSame(['ask' => 1], $run->getMarking());
		$this->assertSame(['ask' => [$item]], $run->getPlaceItems());
		$this->assertSame([$item], $run->getItems());
		$this->assertSame('esra', $run->getRunAs());
		$this->assertNull($run->getResumeAt());
		$this->assertSame(
			['runUuid' => 'run-1', 'flowId' => 'flow-1', 'triggeredBy' => 'esra', 'runAs' => 'esra', 'resumeState' => ['ask' => []]],
			$run->getContext()
		);
		$this->assertNull($this->inserted[1]->getMarking());
	}

	public function testFillPutsTheTaskInTheSlotKeepsTheRestAndSetsTheHeartbeat(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setUpdated(new DateTime('2020-01-01T00:00:00Z'));
		$run->setContext(['keep' => 1, 'resumeState' => ['ask' => ['taskUuid' => 'old', 'extra' => 'kept'], 'other' => ['x' => 1]]]);

		$before = new DateTime();
		$this->runs(found: $run)->fill(slot: new ExampleSetRunSlot(runUuid: 'run-1', nodeId: 'ask', taskUuid: 'task-7'));

		$this->assertSame([$run], $this->updated);
		$context = $run->getContext();
		$this->assertSame(1, $context['keep']);
		$this->assertSame(['x' => 1], $context['resumeState']['other']);
		$this->assertSame('task-7', $context['resumeState']['ask']['taskUuid']);
		$this->assertSame('kept', $context['resumeState']['ask']['extra']);
		$this->assertNotFalse(DateTime::createFromFormat(DATE_ATOM, $context['resumeState']['ask']['askedAt']));
		$minutes = ($run->getResumeAt()->getTimestamp() - $before->getTimestamp()) / 60;
		$this->assertGreaterThanOrEqual(14.9, $minutes);
		$this->assertLessThanOrEqual(15.1, $minutes);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $run->getUpdated()->getTimestamp());
	}

	public function testFillOnAnEmptyContextOpensTheSlot(): void {
		$run = new FlowRun();
		$run->setUuid('run-2');

		$this->runs(found: $run)->fill(slot: new ExampleSetRunSlot(runUuid: 'run-2', nodeId: 'ask', taskUuid: 'task-1'));

		$this->assertSame(['ask'], array_keys($run->getContext()['resumeState']));
		$this->assertSame(['taskUuid', 'askedAt'], array_keys($run->getContext()['resumeState']['ask']));
	}

	public function testATaskThatNamesNoRunChangesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		(new ExampleSetFlowRuns(container: $container))->fill(slot: new ExampleSetRunSlot(runUuid: '  ', nodeId: 'ask', taskUuid: 'task-1'));
	}
}
