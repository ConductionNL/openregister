<?php

/**
 * The remaining published methods fleet apps call keep their release arguments.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\AgentRunDispatch;
use OCA\OpenRegister\Event\AgentRunInstruction;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\RecordDestructionCommand;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCA\OpenRegister\Service\Task\TaskService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Fleet apps call these with the names v2.1.47 published. Each test pins main's leading
 * parameters (what test doubles and main's callers rely on) and the release's names, and
 * the removed methods are checked to give the same call as the form that replaced them.
 */
class LegacyRemainingArgumentsTest extends TestCase {

	/**
	 * Main's leading parameters, then the release's names, per method.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: list<string>, 3: list<string>}>
	 */
	public static function publishedParameters(): array {
		return [
			'AgentMapper::findAll'                        => [AgentMapper::class, 'findAll', ['query'], ['offset', 'filters', 'order', 'limit']],
			'FlowMapper::findAllFlows'                    => [
				FlowMapper::class,
				'findAllFlows',
				['selection', 'query'],
				['app', 'applicationSlug', 'organisation', 'enabled', 'limit', 'offset'],
			],
			'FlowVersionService::publish'                 => [FlowVersionService::class, 'publish', ['flow', 'options'], ['publishedBy', 'bump']],
			'TaskService::terminateAsMoot'                => [TaskService::class, 'terminateAsMoot', ['uuid', 'trigger'], ['reason', 'source']],
			'TaskService::applyTimerOutcome'              => [TaskService::class, 'applyTimerOutcome', ['uuid', 'outcome'], ['source', 'reason']],
			'TaskService::offer'                          => [TaskService::class, 'offer', ['ref', 'pool'], ['uuid', 'actor']],
			'EntityRelationMapper::updateDecisionMetadata' => [EntityRelationMapper::class, 'updateDecisionMetadata', ['edit'], ['relation', 'fields', 'actingUser']],
			'FlowTimerService::arm'                       => [FlowTimerService::class, 'arm', ['config', 'act'], ['actor', 'now']],
			'MessageDispatchProvider::dispatch'           => [MessageDispatchProvider::class, 'dispatch', ['source', 'message'], ['body', 'path', 'headers']],
			'MetricSample::single'                        => [MetricSample::class, 'single', ['name', 'type', 'help', 'value', 'labels'], []],
			'DestructionRecorder::record'                 => [DestructionRecorder::class, 'record', ['object', 'scope', 'rule', 'context'], []],
			'ToolGrantResolver::waives'                   => [ToolGrantResolver::class, 'waives', ['waivedSets', 'toolId', 'arguments'], []],
			'AgentRunRequestedEvent::__construct'         => [
				AgentRunRequestedEvent::class,
				'__construct',
				['subject', 'instruction', 'dispatch'],
				['subjectUuid', 'subjectRegister', 'subjectSchema', 'agent', 'skill', 'prompt', 'resultField', 'requiresApproval', 'mode', 'flowName'],
			],
		];
	}//end publishedParameters()

	/**
	 * Main's leading parameters keep their positions; the release's names are still there.
	 *
	 * @param class-string $class   The class.
	 * @param string       $method  The method.
	 * @param list<string> $leading The parameters, in order, the method starts with.
	 * @param list<string> $named   The release's other parameter names.
	 *
	 * @return void
	 */
	#[DataProvider('publishedParameters')]
	public function testThePublishedParametersAreStillThere(string $class, string $method, array $leading, array $named): void {
		$names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod($class, $method))->getParameters());

		$this->assertSame($leading, array_slice($names, 0, count($leading)), "{$class}::{$method}() must keep its leading parameters.");
		foreach ($named as $name) {
			$this->assertContains($name, $names, "{$class}::{$method}() must keep \${$name}.");
		}
	}//end testThePublishedParametersAreStillThere()

	public function testSingleIsAMetricWithOneSample(): void {
		$this->assertEquals(
			new MetricSample(name: 'n', type: 'gauge', help: 'h', samples: [['labels' => ['a' => 'b'], 'value' => 3]]),
			MetricSample::single('n', 'gauge', 'h', 3, ['a' => 'b'])
		);
	}

	public function testRecordIsRecordDestructionWithOneCommand(): void {
		$object   = new ObjectEntity();
		$recorder = $this->getMockBuilder(DestructionRecorder::class)->disableOriginalConstructor()->onlyMethods(['recordDestruction'])->getMock();
		$recorder->expects($this->once())->method('recordDestruction')
			->with(new RecordDestructionCommand(object: $object, scope: ['scope' => []], rule: 'r', context: ['c' => 1]))
			->willReturn(['ok' => true]);

		$this->assertSame(['ok' => true], $recorder->record(object: $object, scope: ['scope' => []], rule: 'r', context: ['c' => 1]));
	}

	public function testWaivesReadsTheToolsOwnEntry(): void {
		$sets = ['tool-a' => [['status' => ['mode' => 'eq', 'values' => ['open']]]]];

		$this->assertSame(ToolGrantResolver::waivedBy(alternatives: $sets['tool-a'], arguments: ['status' => 'open']), ToolGrantResolver::waives($sets, 'tool-a', ['status' => 'open']));
		$this->assertFalse(ToolGrantResolver::waives($sets, 'tool-b', ['status' => 'open']));
	}

	public function testAnAgentRunEventFromSeparateValuesReadsBackLikeTheValueObjectForm(): void {
		$main = new AgentRunRequestedEvent(
			subject: new ObjectRef(register: 'r', schema: 's', id: 'u-1'),
			instruction: new AgentRunInstruction(agent: 'triage', skill: null, prompt: 'Go', resultField: 'summary'),
			dispatch: new AgentRunDispatch(requiresApproval: true, mode: 'async', flowName: 'intake')
		);
		$named = new AgentRunRequestedEvent(
			subjectUuid: 'u-1',
			subjectRegister: 'r',
			subjectSchema: 's',
			agent: 'triage',
			skill: null,
			prompt: 'Go',
			resultField: 'summary',
			requiresApproval: true,
			mode: 'async',
			flowName: 'intake'
		);
		$positional = new AgentRunRequestedEvent('u-1', 'r', 's', 'triage', null, 'Go', 'summary', true, 'async', 'intake');

		$expected = $main->getPayload();
		unset($expected['correlationId']);
		foreach ([$named, $positional] as $event) {
			$payload = $event->getPayload();
			unset($payload['correlationId']);
			$this->assertSame($expected, $payload);
		}
	}
}//end class
