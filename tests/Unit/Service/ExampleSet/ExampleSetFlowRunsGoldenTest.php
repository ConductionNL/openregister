<?php

/**
 * Golden test for ExampleSetFlowRuns: what refusal(), unnamedRefusals(),
 * taskRefusal(), write() and fill() answer and store for a fixed table of
 * declarations. Moments fill() takes from the clock are recorded as their
 * distance from each other, never as dates. Re-record with UPDATE_GOLDEN=1.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRunCheck
 */
class ExampleSetFlowRunsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-flow-runs-golden.json';

	/**
	 * @var array<int, FlowRun>
	 */
	private array $inserted = [];

	/**
	 * @var array<int, FlowRun>
	 */
	private array $updated = [];

	/**
	 * How often the container handed out the mapper.
	 *
	 * @var int
	 */
	private int $gets = 0;

	public function testFlowRunsMatchGolden(): void {
		$actual = [
			'refusal'         => $this->refusals(),
			'unnamedRefusals' => $this->unnamed(),
			'taskRefusal'     => $this->taskRefusals(),
			'write'           => $this->writes(),
			'fill'            => $this->fills(),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $part => $outcomes) {
			$this->assertSame($outcomes, json_decode((string) json_encode($actual[$part]), true), $part);
		}
	}

	private function runs(): ExampleSetFlowRuns {
		$mapper = $this->createMock(FlowRunMapperInterface::class);
		$mapper->method('insert')->willReturnCallback(function (Entity $entity): Entity {
			assert($entity instanceof FlowRun);
			$this->inserted[] = $entity;
			return $entity;
		});
		$mapper->method('update')->willReturnCallback(function (Entity $entity): FlowRun {
			assert($entity instanceof FlowRun);
			$this->updated[] = $entity;
			return $entity;
		});
		$mapper->method('findByUuid')->willReturnCallback(function (string $uuid): FlowRun {
			$run = new FlowRun();
			$run->setUuid($uuid);
			$run->setUpdated(new DateTime('2020-01-01T00:00:00Z'));
			$context = ['keep' => 1];
			if ($uuid === 'with-slot') {
				$context['resumeState'] = ['step' => ['taskUuid' => 'old', 'extra' => 'kept'], 'other' => ['x' => 1]];
			}

			$run->setContext($context);
			return $run;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function () use ($mapper): FlowRunMapperInterface {
			$this->gets++;
			return $mapper;
		});

		return new ExampleSetFlowRuns(container: $container);
	}

	/**
	 * @return array<string, string>
	 */
	private function refusals(): array {
		$nodes = [['id' => 'ask', 'type' => 'openregister.user-task'], ['id' => 'mail', 'type' => 'mail'], 'not-a-step'];
		$done  = ['status' => 'completed', 'subject' => 's'];
		$cases = [
			'not an array'          => ['x', $nodes],
			'not a list'            => [['a' => $done], $nodes],
			'empty'                 => [[], $nodes],
			'finished'              => [[$done], $nodes],
			'each terminal'         => [[['status' => 'stopped'] + $done, ['status' => 'dead-letter'] + $done, ['status' => 'failed'] + $done], $nodes],
			'unknown field'         => [[$done + ['foo' => 1, 'bar' => 2]], $nodes],
			'queued'                => [[['status' => 'queued', 'subject' => 's']], $nodes],
			'no status'             => [[['subject' => 's']], $nodes],
			'no subject'            => [[['status' => 'completed']], $nodes],
			'blank subject'         => [[['status' => 'completed', 'subject' => '  ']], $nodes],
			'subject not text'      => [[['status' => 'completed', 'subject' => 5]], $nodes],
			'bad created'           => [[$done + ['created' => 'not a date']], $nodes],
			'bad updated'           => [[$done + ['created' => '2024-01-01', 'updated' => 'nope']], $nodes],
			'good dates'            => [[$done + ['created' => '2024-01-01T10:00:00Z', 'updated' => '2024-01-02']], $nodes],
			'second run refused'    => [[$done, ['status' => 'running', 'subject' => 's']], $nodes],
			'run not array'         => [['text'], $nodes],
			'waiting'               => [[['status' => 'suspended', 'subject' => 's', 'key' => 'w', 'node' => 'ask']], $nodes],
			'waiting without key'   => [[['status' => 'suspended', 'subject' => 's', 'node' => 'ask']], $nodes],
			'waiting blank key'     => [[['status' => 'suspended', 'subject' => 's', 'key' => ' ', 'node' => 'ask']], $nodes],
			'waiting on other type' => [[['status' => 'suspended', 'subject' => 's', 'key' => 'w', 'node' => 'mail']], $nodes],
			'waiting on no node'    => [[['status' => 'suspended', 'subject' => 's', 'key' => 'w']], $nodes],
			'waiting nodes scalar'  => [[['status' => 'suspended', 'subject' => 's', 'key' => 'w', 'node' => 'ask']], 'x'],
			'waiting no subject'    => [[['status' => 'suspended', 'key' => 'w', 'node' => 'ask']], $nodes],
		];
		$outcomes = [];
		foreach ($cases as $name => $case) {
			$outcomes[$name] = $this->runs()->refusal(runs: $case[0], nodes: $case[1]);
		}

		return $outcomes;
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	private function unnamed(): array {
		$waiting  = ['status' => 'suspended', 'key' => 'w1'];
		$fragment = [
			'file'  => 'app/demo.json',
			'tasks' => [['data' => ['runUuid' => ' {{flow:f1.runs.w1}} ']], ['data' => []], ['other' => 1]],
			'flows' => [
				['key' => 'f1', 'data' => ['runs' => [
					$waiting,
					['status' => 'suspended', 'key' => 'w2'],
					['status' => 'completed', 'key' => 'c'],
					['status' => 'suspended'],
					'x',
				]]],
				['key' => 'f2', 'data' => ['runs' => [$waiting]]],
				['key' => 'f3', 'data' => []],
			],
		];

		return [
			'mixed'    => $this->runs()->unnamedRefusals(fragment: $fragment),
			'no flows' => $this->runs()->unnamedRefusals(fragment: ['file' => 'f', 'tasks' => []]),
			'no tasks' => $this->runs()->unnamedRefusals(fragment: ['file' => 'f', 'flows' => [['key' => 'k', 'data' => ['runs' => [$waiting]]]]]),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function taskRefusals(): array {
		$context = ['flows' => [
			['waitsOn' => ['other' => 'x']],
			['waitsOn' => []],
			['waitsOn' => ['run-1' => 'ask']],
			['waitsOn' => ['run-1' => 'later']],
		]];
		$cases   = [
			'neither'            => [[], $context],
			'blank both'         => [['runUuid' => ' ', 'nodeId' => ''], $context],
			'run only'           => [['runUuid' => 'run-1'], $context],
			'node only'          => [['nodeId' => 'ask'], $context],
			'matches'            => [['runUuid' => 'run-1', 'nodeId' => 'ask'], $context],
			'matches trimmed'    => [['runUuid' => ' run-1 ', 'nodeId' => ' ask '], $context],
			'other step'         => [['runUuid' => 'run-1', 'nodeId' => 'mail'], $context],
			'unknown run'        => [['runUuid' => 'run-2', 'nodeId' => 'ask'], $context],
			'no flows'           => [['runUuid' => 'run-1', 'nodeId' => 'ask'], []],
		];
		$outcomes = [];
		foreach ($cases as $name => $case) {
			$outcomes[$name] = $this->runs()->taskRefusal(data: $case[0], context: $case[1]);
		}

		return $outcomes;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function writes(): array {
		$flow = new Flow();
		$flow->setUuid('flow-uuid');
		$flow->setTrigger('object.created');
		$flow->setOrganisation('org-1');
		$bare = new Flow();
		$bare->setUuid('bare');
		$runs = [
			['key' => 'done', 'status' => 'completed', 'subject' => 's1', 'subjectRegister' => 'r', 'subjectSchema' => 'sc', 'trigger' => 'manual',
				'triggeredBy' => 'admin', 'error' => null, 'created' => '2024-01-01T10:00:00+02:00', 'updated' => '2024-01-01T11:00:00Z'],
			['status' => 'failed', 'subject' => 's2', 'error' => 'boom', 'created' => '2024-02-01T10:00:00Z'],
			['key' => 'wait', 'status' => 'suspended', 'subject' => 's3', 'node' => 'ask', 'triggeredBy' => 'sanne', 'created' => '2024-03-01T10:00:00Z'],
			['key' => 'wait2', 'status' => 'suspended', 'subject' => 's4', 'node' => 'ask2', 'created' => '2024-03-02T10:00:00Z', 'updated' => '2024-03-03'],
		];
		$outcomes = [];
		$cases = [
			'published' => [$flow, 3, $runs], 'draft' => [$flow, null, [$runs[1]]], 'bare flow' => [$bare, null, [$runs[1]]],
			'none' => [$flow, 1, []],
		];
		foreach ($cases as $name => $case) {
			$count          = 0;
			$this->inserted = [];
			$entry          = $this->runs()->write(flow: $case[0], version: $case[1], runs: $case[2], newUuid: function () use (&$count): string {
				return 'uuid-'.++$count;
			});
			$outcomes[$name] = ['entry' => $entry, 'inserted' => array_map(static fn (FlowRun $run): array => $run->jsonSerialize(), $this->inserted)];
		}

		return $outcomes;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fills(): array {
		$outcomes = [];
		$cases    = ['no run' => ['  ', 'step', 'task'], 'fresh slot' => ['run-1', 'step', 'task-1'], 'existing slot' => ['with-slot', 'step', 'task-2']];
		foreach ($cases as $name => $case) {
			$this->updated = [];
			$this->gets    = 0;
			$this->runs()->fill(runUuid: $case[0], nodeId: $case[1], taskUuid: $case[2]);
			$outcomes[$name] = ['gets' => $this->gets, 'updated' => array_map(fn (FlowRun $run): array => $this->filled(run: $run), $this->updated)];
		}

		return $outcomes;
	}

	/**
	 * @param FlowRun $run The run fill() updated.
	 *
	 * @return array<string, mixed>
	 */
	private function filled(FlowRun $run): array {
		$context = (array) $run->getContext();
		$asked   = (string) ($context['resumeState']['step']['askedAt'] ?? '');
		$this->assertNotFalse(strtotime($asked));
		$context['resumeState']['step']['askedAt'] = '<now>';
		$updated = $run->getUpdated();
		$resume  = $run->getResumeAt();

		return [
			'context'              => $context,
			'updatedIsNotTheOld'   => $updated->getTimestamp() !== strtotime('2020-01-01T00:00:00Z'),
			'resumeMinutesAfter'   => (int) round(($resume->getTimestamp() - $updated->getTimestamp()) / 60),
			'askedSecondsBeforeUp' => abs($updated->getTimestamp() - (int) strtotime($asked)) <= 2,
		];
	}
}
