<?php

/**
 * TaskFormResolverGoldenTest.
 *
 * Pins TaskFormResolver::describe() — the full description array and every
 * collaborator call — for every branch: run-less, unpinned and pinned runs,
 * each unresolvable cause, the native form ready/broken/unreadable, and every
 * state of the external form's link. Written against the code before its
 * refactor. Expiry dates are far in the past or future, so the outcome does
 * not depend on today's date.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormFields;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCA\OpenRegister\Service\Task\TaskFormResolverServices;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * Golden descriptions of the task form resolver.
 */
class TaskFormResolverGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The declaration the reader hands out.
	 *
	 * @var TaskForm
	 */
	private TaskForm $form;

	/**
	 * The run the run mapper finds, or null for "gone".
	 *
	 * @var FlowRun|null
	 */
	private ?FlowRun $run = null;

	/**
	 * The live flow's nodes, or null for "gone".
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $liveNodes = null;

	/**
	 * The pinned graph, or null for "unresolvable".
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $graph = null;

	/**
	 * The form link, or null.
	 *
	 * @var FormLink|null
	 */
	private ?FormLink $link = null;

	/**
	 * Whether the schema read fails.
	 *
	 * @var boolean
	 */
	private bool $schemaFails = false;

	/**
	 * Build the resolver over recording doubles.
	 *
	 * @return TaskFormResolver
	 */
	private function resolver(): TaskFormResolver {
		$runs = $this->createMock(FlowRunMapperInterface::class);
		$runs->method('findByUuid')->willReturnCallback(
			function (string $uuid): FlowRun {
				$this->calls[] = 'run:' . $uuid;
				return $this->run ?? throw new RuntimeException('gone');
			}
		);
		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findByUuid')->willReturnCallback(
			function (string $uuid): Flow {
				$this->calls[] = 'flow:' . $uuid;
				if ($this->liveNodes === null) {
					throw new RuntimeException('gone');
				}

				$flow = new Flow();
				$flow->setNodes($this->liveNodes);
				return $flow;
			}
		);
		$published = $this->createMock(FlowPublishedGraph::class);
		$published->method('ofRun')->willReturnCallback(
			function (FlowRun $run): ?array {
				$this->calls[] = 'graph:' . $run->getFlowVersion();
				return $this->graph;
			}
		);
		$links = $this->createMock(FormLinkMapper::class);
		$links->method('findFormLink')->willReturnCallback(
			function (string $objectUuid, int $formId): ?FormLink {
				$this->calls[] = 'link:' . $objectUuid . ':' . $formId;
				return $this->link;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => 'T[' . $text . '|' . json_encode($params) . ']');

		return new TaskFormResolver(
			services: new TaskFormResolverServices(runs: $runs, flows: $flows, published: $published, formLinks: $links),
			reader: $this->reader(),
			l10n: $l10n
		);
	}//end resolver()

	/**
	 * A recording form reader.
	 *
	 * @return TaskFormReader
	 */
	private function reader(): TaskFormReader {
		$reader = $this->createMock(TaskFormReader::class);
		$reader->method('fromRecord')->willReturnCallback(
			function (array $record): TaskForm {
				$this->calls[] = 'fromRecord:' . json_encode($record);
				return $this->form;
			}
		);
		$reader->method('fromConfig')->willReturnCallback(
			function (array $config): TaskForm {
				$this->calls[] = 'fromConfig:' . json_encode($config);
				return $this->form;
			}
		);
		$reader->method('schema')->willReturnCallback(
			function (string $reference): Schema {
				$this->calls[] = 'schema:' . $reference;
				if ($this->schemaFails === true) {
					throw new UnexpectedValueException('no schema ' . $reference);
				}

				$schema = new Schema();
				$schema->setId(12);
				$schema->setUuid('schema-uuid');
				$schema->setSlug('person');
				$schema->setTitle('Person');
				return $schema;
			}
		);
		$reader->method('declaredFields')->willReturnCallback(
			function (TaskForm $form, Schema $schema): array {
				$this->calls[] = 'declared:' . $schema->getSlug();
				return $form->fields;
			}
		);
		$reader->method('unrenderableReason')->willReturnCallback(
			function (Schema $schema, string $field): ?string {
				$this->calls[] = 'renderable:' . $field;
				return ['gone' => 'Field gone was removed.'][$field] ?? null;
			}
		);

		return $reader;
	}//end reader()

	/**
	 * A task.
	 *
	 * @param string|null $runUuid    The run.
	 * @param string|null $objectUuid The subject.
	 *
	 * @return Task
	 */
	private static function task(?string $runUuid, ?string $objectUuid = 'obj-1'): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setRunUuid($runUuid);
		$task->setNodeId(' step-2 ');
		$task->setObjectUuid($objectUuid);
		$task->setMetadata(['form' => ['kind' => 'fields', 'schema' => 'person']]);
		$task->setExpiresAt(new DateTime('2031-05-06T07:08:09+00:00'));
		$task->setOnTimeout('escalate');
		$task->setOnReject('close');
		return $task;
	}//end task()

	/**
	 * A run.
	 *
	 * @param int|null $version The pinned version.
	 *
	 * @return FlowRun
	 */
	private static function flowRun(?int $version): FlowRun {
		$run = new FlowRun();
		$run->setFlowId('flow-9');
		$run->setFlowVersion($version);
		return $run;
	}//end flowRun()

	/**
	 * A link.
	 *
	 * @param string|null $status  The status.
	 * @param string|null $expires The expiry.
	 *
	 * @return FormLink
	 */
	private static function link(?string $status, ?string $expires): FormLink {
		$link = new FormLink();
		$link->setFormHash('hash-1');
		$link->setTitle('Intake');
		$link->setStatus($status);
		if ($expires !== null) {
			$link->setExpiresAt(new DateTime($expires));
		}

		return $link;
	}//end link()

	/**
	 * The behaviours every description carries.
	 *
	 * @var array<string, string>
	 */
	private const BEHAVIOURS = ['expiresAt' => '2031-05-06T07:08:09+00:00', 'onTimeout' => 'escalate', 'onReject' => 'close'];

	/**
	 * Run-less native form, one broken field, and the ready variant.
	 *
	 * @return void
	 */
	public function testRunlessNativeForms(): void {
		$this->form = new TaskForm(kind: TaskForm::KIND_FIELDS, native: new TaskFormFields(schema: 'person', action: 'approve', fields: [['field' => 'name', 'required' => true], ['field' => 'gone', 'required' => false]]), requireChecklist: true);
		$schema     = ['id' => 12, 'uuid' => 'schema-uuid', 'slug' => 'person', 'title' => 'Person'];

		$this->assertSame(
			[
				'form'             => [
					'kind'   => TaskForm::KIND_FIELDS,
					'state'  => TaskFormResolver::STATE_BROKEN,
					'error'  => null,
					'schema' => $schema,
					'action' => 'approve',
					'fields' => [
						['field' => 'name', 'required' => true, 'order' => 0, 'renderable' => true, 'reason' => null],
						['field' => 'gone', 'required' => false, 'order' => 1, 'renderable' => false, 'reason' => 'Field gone was removed.'],
					],
				],
				'requireChecklist' => true,
				'behaviours'       => self::BEHAVIOURS,
			],
			$this->resolver()->describe(self::task(null))
		);
		$this->assertSame(
			['fromRecord:{"kind":"fields","schema":"person"}', 'schema:person', 'declared:person', 'renderable:name', 'renderable:gone'],
			$this->calls
		);

		$this->form  = new TaskForm(kind: TaskForm::KIND_FIELDS, native: new TaskFormFields(schema: 'person', fields: [['field' => 'name', 'required' => false]]));
		$this->calls = [];
		$described   = $this->resolver()->describe(self::task('  '));
		$this->assertSame(TaskFormResolver::STATE_READY, $described['form']['state']);
		$this->assertSame([['field' => 'name', 'required' => false, 'order' => 0, 'renderable' => true, 'reason' => null]], $described['form']['fields']);
		$this->assertNull($described['form']['action']);
		$this->assertFalse($described['requireChecklist']);
	}//end testRunlessNativeForms()

	/**
	 * A native form whose schema cannot be read.
	 *
	 * @return void
	 */
	public function testUnreadableNativeForm(): void {
		$this->form        = new TaskForm(kind: TaskForm::KIND_FIELDS, native: new TaskFormFields(schema: 'ghost', action: 'x'));
		$this->schemaFails = true;

		$this->assertSame(
			[
				'form'             => ['kind' => TaskForm::KIND_FIELDS, 'state' => TaskFormResolver::STATE_BROKEN, 'error' => 'no schema ghost', 'schema' => null, 'action' => 'x', 'fields' => []],
				'requireChecklist' => false,
				'behaviours'       => self::BEHAVIOURS,
			],
			$this->resolver()->describe(self::task(null))
		);
	}//end testUnreadableNativeForm()

	/**
	 * No form declared.
	 *
	 * @return void
	 */
	public function testNoForm(): void {
		$this->form = new TaskForm(kind: null, requireChecklist: true);
		$task       = self::task(null);
		$task->setExpiresAt(null);

		$this->assertSame(
			['form' => null, 'requireChecklist' => true, 'behaviours' => ['expiresAt' => null, 'onTimeout' => 'escalate', 'onReject' => 'close']],
			$this->resolver()->describe($task)
		);
	}//end testNoForm()

	/**
	 * Every unresolvable cause.
	 *
	 * @return void
	 */
	public function testUnresolvableCauses(): void {
		$this->form = new TaskForm(kind: null);
		$cases      = [
			'run gone'        => [null, null, null, 'T[Task %1$s belongs to run %2$s, which no longer exists.|["task-1","run-1"]]', ['run:run-1']],
			'live flow gone'  => [self::flowRun(null), null, null, 'T[Flow %1$s no longer exists.|["flow-9"]]', ['run:run-1', 'flow:flow-9']],
			'live no step'    => [
				self::flowRun(null),
				[['id' => 'step-1'], 'junk'],
				null,
				'T[Version %1$s of flow %2$s has no step "%3$s", so the form of task %4$s cannot be resolved.|["draft","flow-9","step-2","task-1"]]',
				['run:run-1', 'flow:flow-9'],
			],
			'pinned gone'     => [
				self::flowRun(3),
				null,
				null,
				'T[Version %1$s of flow %2$s cannot be resolved, so the form of this task cannot be shown.|["3","flow-9"]]',
				['run:run-1', 'graph:3'],
			],
			'pinned no step'  => [
				self::flowRun(3),
				null,
				['nodes' => [['id' => 'step-1']]],
				'T[Version %1$s of flow %2$s has no step "%3$s", so the form of task %4$s cannot be resolved.|["3","flow-9","step-2","task-1"]]',
				['run:run-1', 'graph:3'],
			],
		];

		foreach ($cases as $name => [$run, $live, $graph, $error, $calls]) {
			$this->run       = $run;
			$this->liveNodes = $live;
			$this->graph     = $graph;
			$this->calls     = [];
			$this->assertSame(
				[
					'form'             => ['kind' => null, 'state' => TaskFormResolver::STATE_UNRESOLVABLE, 'error' => $error],
					'requireChecklist' => false,
					'behaviours'       => self::BEHAVIOURS,
				],
				$this->resolver()->describe(self::task('run-1')),
				$name
			);
			$this->assertSame($calls, $this->calls, $name);
		}
	}//end testUnresolvableCauses()

	/**
	 * The external form through every link state, resolved through both graphs.
	 *
	 * @return void
	 */
	public function testExternalForms(): void {
		$this->form = new TaskForm(kind: TaskForm::KIND_EXTERNAL, formId: 44, requireChecklist: true);
		$base       = ['kind' => TaskForm::KIND_EXTERNAL, 'state' => TaskFormResolver::STATE_UNAVAILABLE, 'error' => null, 'formId' => 44, 'formHash' => null, 'title' => null, 'status' => null, 'expiresAt' => null];
		$linked     = ['formHash' => 'hash-1', 'title' => 'Intake'];
		$unlinked   = 'T[The form is no longer linked to this task\'s subject, so it cannot be used to finish the work.|[]]';
		$cases      = [
			'no subject' => ['', null, array_merge($base, ['error' => $unlinked]), []],
			'no link'    => ['obj-1', null, array_merge($base, ['error' => $unlinked]), ['link:obj-1:44']],
			'closed'     => [
				'obj-1',
				self::link('closed', null),
				array_merge($base, $linked, ['status' => 'closed', 'error' => 'T[The form is %1$s and cannot be filled in.|["closed"]]']),
				['link:obj-1:44'],
			],
			'expired'    => [
				'obj-1',
				self::link('open', '2001-02-03T00:00:00+00:00'),
				array_merge($base, $linked, ['status' => 'open', 'expiresAt' => '2001-02-03T00:00:00+00:00', 'error' => 'T[The form expired on %1$s and cannot be filled in.|["2001-02-03"]]']),
				['link:obj-1:44'],
			],
			'ready'      => [
				'obj-1',
				self::link(null, '2999-02-03T00:00:00+00:00'),
				array_merge($base, $linked, ['state' => TaskFormResolver::STATE_READY, 'status' => 'open', 'expiresAt' => '2999-02-03T00:00:00+00:00']),
				['link:obj-1:44'],
			],
		];

		foreach ([[null, [['id' => 'step-2', 'config' => ['formKind' => 'external']]], null, 'flow:flow-9'], [7, null, ['nodes' => [['id' => 'step-2', 'config' => ['formKind' => 'external']]]], 'graph:7']] as [$version, $live, $graph, $graphCall]) {
			foreach ($cases as $name => [$subject, $link, $form, $calls]) {
				$this->run       = self::flowRun($version);
				$this->liveNodes = $live;
				$this->graph     = $graph;
				$this->link      = $link;
				$this->calls     = [];
				$this->assertSame(
					['form' => $form, 'requireChecklist' => true, 'behaviours' => self::BEHAVIOURS],
					$this->resolver()->describe(self::task('run-1', $subject)),
					$name
				);
				$this->assertSame(array_merge(['run:run-1', $graphCall, 'fromConfig:{"formKind":"external"}'], $calls), $this->calls, $name);
			}
		}
	}//end testExternalForms()

	/**
	 * The public declarationOf() throws the unresolvable reason.
	 *
	 * @return void
	 */
	public function testDeclarationOfThrows(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('T[Task %1$s belongs to run %2$s, which no longer exists.|["task-1","run-1"]]');

		$this->resolver()->declarationOf(self::task('run-1'));
	}//end testDeclarationOfThrows()
}//end class
