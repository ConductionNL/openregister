<?php

/**
 * The form a task presents: pinned version or own record, intersected with the live schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Resolving and describing a task's form, per read.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskFormResolver
 * @covers \OCA\OpenRegister\Service\Task\TaskFormReader
 * @covers \OCA\OpenRegister\Service\Task\TaskForm
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Db\FormLink
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Task
 */
class TaskFormResolverTest extends TestCase {

	private const FLOW = 'flow-1';

	private FlowRunMapper&MockObject $runs;

	private FlowMapper&MockObject $flows;

	private FlowPublishedGraph&MockObject $published;

	private FormLinkMapper&MockObject $formLinks;

	private SchemaMapper&MockObject $schemas;

	private TransitionEngine&MockObject $engine;

	private TaskFormResolver $resolver;

	protected function setUp(): void {
		$this->runs = $this->createMock(FlowRunMapper::class);
		$this->flows = $this->createMock(FlowMapper::class);
		$this->published = $this->createMock(FlowPublishedGraph::class);
		$this->formLinks = $this->createMock(FormLinkMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->engine = $this->createMock(TransitionEngine::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$reader = new TaskFormReader(
			schemas: $this->schemas,
			engine: $this->engine,
			apps: $this->createMock(IAppManager::class),
			l10n: $l10n
		);
		$this->resolver = new TaskFormResolver(
			runs: $this->runs,
			flows: $this->flows,
			published: $this->published,
			formLinks: $this->formLinks,
			reader: $reader,
			l10n: $l10n
		);
	}//end setUp()

	/**
	 * The live subject schema: `reason` and `note`, `reason` NOT in the schema's own required list.
	 */
	private function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('case');
		$schema->setTitle('Case');
		$schema->setProperties(
			[
				'note' => ['type' => 'string', 'order' => 1],
				'reason' => ['type' => 'string', 'order' => 2],
			]
		);
		$schema->setRequired(['note']);

		return $schema;
	}//end caseSchema()

	/**
	 * A task raised by node `ask` of a run.
	 */
	private function taskOfRun(?string $runUuid = 'run-1'): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setRunUuid($runUuid);
		$task->setNodeId('ask');
		$task->setObjectUuid('obj-1');

		return $task;
	}//end taskOfRun()

	/**
	 * A run of the flow, pinned to a version (or unpinned when null).
	 */
	private function pinnedRun(?int $version): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId(self::FLOW);
		$run->setFlowVersion($version);

		return $run;
	}//end run()

	/**
	 * A graph whose `ask` node declares the given form config.
	 *
	 * @param array<string, mixed> $config The node's form keys.
	 *
	 * @return array<string, mixed>
	 */
	private function graphWith(array $config): array {
		return [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.set-fields', 'config' => []],
				['id' => 'ask', 'type' => 'openregister.user-task', 'config' => array_merge(['title' => 'Approve'], $config)],
			],
			'edges' => [],
		];
	}//end graphWith()

	/**
	 * A run-less task reads its declaration off its own record: required from the
	 * DECLARATION (the schema says `reason` is optional), order from the declaration.
	 */
	public function testARunlessTaskCarriesItsOwnDeclaration(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->runs->expects($this->never())->method('findByUuid');
		$task = $this->taskOfRun(runUuid: null);
		$task->setMetadata(
			[
				'form' => [
					'kind' => 'fields',
					'schema' => 'case',
					'fields' => [['field' => 'reason', 'required' => true], ['field' => 'note', 'required' => false]],
					'requireChecklist' => true,
				],
			]
		);

		$described = $this->resolver->describe(task: $task);

		$this->assertTrue($described['requireChecklist']);
		$form = $described['form'];
		$this->assertSame('fields', $form['kind']);
		$this->assertSame(TaskFormResolver::STATE_READY, $form['state']);
		$this->assertSame(['id' => 5, 'uuid' => null, 'slug' => 'case', 'title' => 'Case'], $form['schema']);
		$this->assertSame(
			[
				['field' => 'reason', 'required' => true, 'order' => 0, 'renderable' => true, 'reason' => null],
				['field' => 'note', 'required' => false, 'order' => 1, 'renderable' => true, 'reason' => null],
			],
			$form['fields']
		);
	}//end testARunlessTaskCarriesItsOwnDeclaration()

	/**
	 * A pinned task resolves through the run's version and NEVER reads the editable head.
	 */
	public function testAPinnedTaskResolvesThroughTheVersionNotTheHead(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->runs->method('findByUuid')->with('run-1')->willReturn($this->pinnedRun(version: 3));
		$this->published->expects($this->once())->method('ofRun')
			->willReturn($this->graphWith(['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'reason*, note']));
		$this->flows->expects($this->never())->method('findByUuid');

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertSame(TaskFormResolver::STATE_READY, $form['state']);
		$this->assertSame(['reason', 'note'], array_column($form['fields'], 'field'));
		$this->assertTrue($form['fields'][0]['required']);
	}//end testAPinnedTaskResolvesThroughTheVersionNotTheHead()

	/**
	 * Editing the flow leaves an open task's form alone: the head declares four
	 * fields, the pinned version two, and the task shows two.
	 */
	public function testEditingTheFlowLeavesAnOpenTasksFormAlone(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 1));
		$this->published->method('ofRun')
			->willReturn($this->graphWith(['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'reason*, note']));
		$head = new Flow();
		$head->setNodes($this->graphWith(['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'reason*, note, a, b'])['nodes']);
		$this->flows->method('findByUuid')->willReturn($head);

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertCount(2, $form['fields']);
	}//end testEditingTheFlowLeavesAnOpenTasksFormAlone()

	/**
	 * An unresolvable pinned version fails loudly, naming flow and version, and
	 * presents no field list at all: not the head, not empty.
	 */
	public function testAnUnresolvableVersionFailsNamingFlowAndVersion(): void {
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 7));
		$this->published->method('ofRun')->willReturn(null);
		$this->flows->expects($this->never())->method('findByUuid');

		$described = $this->resolver->describe(task: $this->taskOfRun());

		$this->assertSame(TaskFormResolver::STATE_UNRESOLVABLE, $described['form']['state']);
		$this->assertStringContainsString('Version 7 of flow flow-1', $described['form']['error']);
		$this->assertArrayNotHasKey('fields', $described['form']);
		$this->assertFalse($described['requireChecklist']);
	}//end testAnUnresolvableVersionFailsNamingFlowAndVersion()

	/**
	 * A version that has no such step is as unresolvable as a missing version.
	 */
	public function testAVersionWithoutTheStepIsUnresolvable(): void {
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 2));
		$this->published->method('ofRun')->willReturn(['nodes' => [['id' => 'other', 'type' => 'openregister.end', 'config' => []]]]);

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertSame(TaskFormResolver::STATE_UNRESOLVABLE, $form['state']);
		$this->assertStringContainsString('no step "ask"', $form['error']);
	}//end testAVersionWithoutTheStepIsUnresolvable()

	/**
	 * A run that no longer exists cannot lend its declaration.
	 */
	public function testAVanishedRunIsUnresolvable(): void {
		$this->runs->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertSame(TaskFormResolver::STATE_UNRESOLVABLE, $form['state']);
		$this->assertStringContainsString('run run-1', $form['error']);
	}//end testAVanishedRunIsUnresolvable()

	/**
	 * The interactive draft test run is the one unpinned dispatch; it walks the live document.
	 */
	public function testAnUnpinnedTestRunReadsTheLiveDocument(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: null));
		$this->published->expects($this->never())->method('ofRun');
		$live = new Flow();
		$live->setNodes($this->graphWith(['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'note'])['nodes']);
		$this->flows->method('findByUuid')->with(self::FLOW)->willReturn($live);

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertSame(['note'], array_column($form['fields'], 'field'));
	}//end testAnUnpinnedTestRunReadsTheLiveDocument()

	/**
	 * A field the schema dropped after the step was saved renders as broken with
	 * its reason; the form is not presented as complete and correct; the other
	 * field still renders.
	 */
	public function testAFieldTheSchemaDroppedIsVisibleAsBroken(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 1));
		$this->published->method('ofRun')
			->willReturn($this->graphWith(['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'evidence*, note']));

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertSame(TaskFormResolver::STATE_BROKEN, $form['state']);
		$this->assertFalse($form['fields'][0]['renderable']);
		$this->assertStringContainsString('no such property', $form['fields'][0]['reason']);
		$this->assertTrue($form['fields'][0]['required'], 'the declaration still says required; nothing is silently dropped');
		$this->assertTrue($form['fields'][1]['renderable']);
	}//end testAFieldTheSchemaDroppedIsVisibleAsBroken()

	/**
	 * A step naming an action carries the transition's inputs, required from the transition.
	 */
	public function testAnActionFormCarriesTheTransitionsInputs(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->engine->method('declaredInputs')->with($this->anything(), 'reject')
			->willReturn([['field' => 'reason', 'required' => true]]);
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 1));
		$this->published->method('ofRun')
			->willReturn($this->graphWith(['formKind' => 'fields', 'formSchema' => 'case', 'formAction' => 'reject']));

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertSame('reject', $form['action']);
		$this->assertSame([['field' => 'reason', 'required' => true, 'order' => 0, 'renderable' => true, 'reason' => null]], $form['fields']);
	}//end testAnActionFormCarriesTheTransitionsInputs()

	/**
	 * A schema that no longer declares the action breaks the form, saying so.
	 */
	public function testASchemaThatDroppedTheActionBreaksTheForm(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->engine->method('declaredInputs')->willReturn(null);
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 1));
		$this->published->method('ofRun')
			->willReturn($this->graphWith(['formKind' => 'fields', 'formSchema' => 'case', 'formAction' => 'reject']));

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertSame(TaskFormResolver::STATE_BROKEN, $form['state']);
		$this->assertStringContainsString('declares no lifecycle action "reject"', $form['error']);
		$this->assertSame([], $form['fields']);
	}//end testASchemaThatDroppedTheActionBreaksTheForm()

	/**
	 * A step declaring no form describes none; the checklist rule still travels.
	 */
	public function testAStepWithoutAFormDescribesNone(): void {
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 1));
		$this->published->method('ofRun')->willReturn($this->graphWith(['formRequireChecklist' => true]));
		$this->schemas->expects($this->never())->method('find');

		$described = $this->resolver->describe(task: $this->taskOfRun());

		$this->assertNull($described['form']);
		$this->assertTrue($described['requireChecklist']);
	}//end testAStepWithoutAFormDescribesNone()

	/**
	 * An external form resolves through the subject's link and is ready while open.
	 */
	public function testAnOpenExternalFormIsReady(): void {
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 1));
		$this->published->method('ofRun')->willReturn($this->graphWith(['formKind' => 'external', 'formId' => 9]));
		$link = new FormLink();
		$link->setFormId(9);
		$link->setFormHash('abc');
		$link->setTitle('Intake');
		$link->setStatus('open');
		$this->formLinks->method('findFormLink')->with('obj-1', 9)->willReturn($link);

		$form = $this->resolver->describe(task: $this->taskOfRun())['form'];

		$this->assertSame('external', $form['kind']);
		$this->assertSame(TaskFormResolver::STATE_READY, $form['state']);
		$this->assertSame('abc', $form['formHash']);
		$this->assertSame('Intake', $form['title']);
	}//end testAnOpenExternalFormIsReady()

	/**
	 * An expired, archived or unlinked bound form is not offered as the way to finish.
	 */
	public function testAnUnusableExternalFormSaysSo(): void {
		$this->runs->method('findByUuid')->willReturn($this->pinnedRun(version: 1));
		$this->published->method('ofRun')->willReturn($this->graphWith(['formKind' => 'external', 'formId' => 9]));

		$expired = new FormLink();
		$expired->setFormId(9);
		$expired->setStatus('open');
		$expired->setExpiresAt(new DateTime('2020-01-01'));
		$archived = new FormLink();
		$archived->setFormId(9);
		$archived->setStatus('archived');
		$this->formLinks->method('findFormLink')->willReturnOnConsecutiveCalls($expired, $archived, null);

		foreach (['expired', 'archived', 'no longer linked'] as $expected) {
			$form = $this->resolver->describe(task: $this->taskOfRun())['form'];
			$this->assertSame(TaskFormResolver::STATE_UNAVAILABLE, $form['state']);
			$this->assertStringContainsString($expected, (string)$form['error']);
		}
	}//end testAnUnusableExternalFormSaysSo()
}//end class
