<?php

/**
 * Tests for a form on an approval chain position (approval-position-task-form).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Task\ApprovalPositionForm;
use OCA\OpenRegister\Service\Task\ApprovalPositionFormGuard;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskCommit;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Task\TaskFormSources;
use OCA\OpenRegister\Service\Task\TaskIntake;
use OCA\OpenRegister\Service\Task\TaskLifecycleServices;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskSequenceDecisionGuard;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCA\OpenRegister\Service\Task\TaskSequenceServices;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTables;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A position's form is compiled, refused when unrenderable, and carried into its task.
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 */
final class ApprovalPositionFormTest extends TestCase {

	/**
	 * The tasks the real TaskService inserted.
	 *
	 * @var array<int, Task>
	 */
	private array $inserted = [];

	/**
	 * A translator that formats its parameters.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => ($parameters === [] ? $text : vsprintf($text, $parameters))
		);

		return $l10n;
	}//end l10n()

	/**
	 * The subject schema: an amount to approve, a read-only number and a hidden note.
	 *
	 * @return array<string, mixed>
	 */
	private static function properties(): array {
		return [
			'goedgekeurdBedrag' => ['type' => 'number'],
			'voorwaarde' => ['type' => 'string'],
			'besluitnummer' => ['type' => 'string', 'readOnly' => true],
			'interneNotitie' => ['type' => 'string', 'visible' => false],
		];
	}//end properties()

	/**
	 * The saved schema with one chain whose positions carry these forms.
	 *
	 * @param array<int, mixed> $forms One form per position; null for none.
	 *
	 * @return Schema
	 */
	private function schema(array $forms): Schema {
		$approvers = [];
		foreach ($forms as $index => $form) {
			$approver = ['role' => 'role-' . ($index + 1)];
			if ($form !== null) {
				$approver['form'] = $form;
			}

			$approvers[] = $approver;
		}

		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('subsidie');
		$schema->setProperties(self::properties());
		$schema->setConfiguration(['x-openregister-approval-chains' => ['budget' => ['transition' => 'approve', 'approvers' => $approvers]]]);

		return $schema;
	}//end schema()

	/**
	 * The form the spec's scenario declares.
	 *
	 * @return array<string, mixed>
	 */
	private static function amountForm(): array {
		return ['kind' => 'fields', 'fields' => [['name' => 'goedgekeurdBedrag', 'required' => true]]];
	}//end amountForm()

	/**
	 * A reader over the subject schema.
	 *
	 * @param bool $formsInstalled Whether the Forms app is installed.
	 *
	 * @return TaskFormReader
	 */
	private function reader(bool $formsInstalled = true): TaskFormReader {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (mixed $reference): Schema {
				self::assertSame('42', (string)$reference);
				return $this->schema(forms: []);
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($formsInstalled);

		return new TaskFormReader(sources: new TaskFormSources(schemas: $schemas, engine: $this->createMock(TransitionEngine::class)), apps: $apps, l10n: $this->l10n());
	}//end reader()

	/**
	 * The sequence service over the real TaskService import.
	 *
	 * @return TaskSequenceService
	 */
	private function sequences(): TaskSequenceService {
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('insert')->willReturnCallback(
			function (Task $task): Task {
				$this->inserted[] = $task;
				return $task;
			}
		);
		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willReturnArgument(0);

		$taskService = new TaskService(
			authorization: $this->createMock(TaskAuthorizationService::class),
			services: new TaskLifecycleServices(
				tables: new TaskTables(
					tasks: $tasks,
					candidates: $this->createMock(TaskCandidateMapper::class),
					relations: $this->createMock(TaskRelationMapper::class),
					audits: $audits
				),
				commit: new TaskCommit(db: $this->createMock(IDBConnection::class), logger: new NullLogger(), dispatcher: null),
				intake: new TaskIntake(builder: new TaskBuilder(), forms: $this->reader()),
				resolver: $this->createMock(TaskPerformerResolver::class)
			),
			sequenceGuard: new TaskSequenceDecisionGuard(sequences: $this->createMock(TaskSequenceMapper::class))
		);

		$sequenceRows = $this->createMock(TaskSequenceMapper::class);
		$sequenceRows->method('insert')->willReturnCallback(
			static function (TaskSequence $sequence): TaskSequence {
				$sequence->setId(9);
				return $sequence;
			}
		);

		return new TaskSequenceService(
			services: new TaskSequenceServices(
				sequences: $sequenceRows,
				taskReader: $this->createMock(TaskMapper::class),
				tasks: $taskService,
				dispatcher: $this->createMock(IEventDispatcher::class)
			),
			logger: new NullLogger()
		);
	}//end sequences()

	/**
	 * Provision an approval of object obj-1 from the schema's compiled chain.
	 *
	 * @param TaskSequenceService $service The sequence service.
	 * @param Schema              $schema  The schema.
	 *
	 * @return void
	 */
	private function provision(TaskSequenceService $service, Schema $schema): void {
		$template = (new ApprovalChainAnnotationInstaller(logger: new NullLogger()))->compile(schema: $schema, chainKey: 'budget');
		self::assertIsArray($template);
		$service->provision(chain: $service->chain(template: $template), anchor: $service->anchorOf(objectUuid: 'obj-1', requesterId: 'jan', registerId: 3));
	}//end provision()

	public function testTheDeclaredSpellingBecomesTheTaskFormRecord(): void {
		self::assertNull(ApprovalPositionForm::record(declared: null, schemaId: 42));
		self::assertNull(ApprovalPositionForm::record(declared: [], schemaId: 42));
		self::assertNull(ApprovalPositionForm::record(declared: ['fields' => ['goedgekeurdBedrag']], schemaId: 42), 'A form without a kind is not carried.');
		self::assertNull(ApprovalPositionForm::declaration(declared: 'fields', schemaId: 42));

		$record = ApprovalPositionForm::record(
			declared: [
				'kind' => ' fields ',
				'fields' => [
					['name' => 'voorwaarde', 'order' => 2],
					'interneNotitie',
					['name' => 'goedgekeurdBedrag', 'required' => true, 'order' => 1],
					['field' => 'besluitnummer', 'required' => false],
				],
				'requireChecklist' => 'true',
			],
			schemaId: 42
		);
		self::assertSame(
			[
				'kind' => 'fields',
				'schema' => '42',
				'action' => null,
				'fields' => [
					['field' => 'goedgekeurdBedrag', 'required' => true],
					['field' => 'voorwaarde'],
					'interneNotitie',
					['field' => 'besluitnummer', 'required' => false],
				],
				'formId' => null,
				'requireChecklist' => true,
			],
			$record
		);

		$external = ApprovalPositionForm::record(declared: ['kind' => 'external', 'formId' => 12, 'schema' => 'other', 'action' => ' sign '], schemaId: 42);
		self::assertSame(['kind' => 'external', 'schema' => 'other', 'action' => 'sign', 'fields' => null, 'formId' => 12, 'requireChecklist' => false], $external);
	}//end testTheDeclaredSpellingBecomesTheTaskFormRecord()

	public function testThePositionCarriesTheFormAndTheVersionFollowsIt(): void {
		$installer = new ApprovalChainAnnotationInstaller(logger: new NullLogger());

		$plain = $installer->compile(schema: $this->schema(forms: [null, null]), chainKey: 'budget');
		self::assertSame(ApprovalChainAnnotationInstaller::TEMPLATE_VERSION, $plain['templateVersion']);
		self::assertArrayNotHasKey('form', $plain['positions'][0]);

		$withForm = $installer->compile(schema: $this->schema(forms: [self::amountForm(), null]), chainKey: 'budget');
		self::assertSame(
			['kind' => 'fields', 'schema' => '42', 'action' => null, 'fields' => [['field' => 'goedgekeurdBedrag', 'required' => true]], 'formId' => null, 'requireChecklist' => false],
			$withForm['positions'][0]['form']
		);
		self::assertArrayNotHasKey('form', $withForm['positions'][1]);
		self::assertGreaterThan(ApprovalChainAnnotationInstaller::TEMPLATE_VERSION, $withForm['templateVersion']);
		self::assertLessThan(2 ** 31, $withForm['templateVersion']);

		$again = $installer->compile(schema: $this->schema(forms: [self::amountForm(), null]), chainKey: 'budget');
		self::assertSame($withForm['templateVersion'], $again['templateVersion'], 'The version is a pure function of the declaration.');

		$changed = $installer->compile(schema: $this->schema(forms: [['kind' => 'fields', 'fields' => ['voorwaarde']], null]), chainKey: 'budget');
		self::assertNotSame($withForm['templateVersion'], $changed['templateVersion'], 'A changed form is a different template version.');

		$moved = $installer->compile(schema: $this->schema(forms: [null, self::amountForm()]), chainKey: 'budget');
		self::assertNotSame($withForm['templateVersion'], $moved['templateVersion'], 'The same form on another position is a different version.');

		$kindless = $installer->compile(schema: $this->schema(forms: [['fields' => ['voorwaarde']]]), chainKey: 'budget');
		self::assertSame(ApprovalChainAnnotationInstaller::TEMPLATE_VERSION, $kindless['templateVersion']);
		self::assertArrayNotHasKey('form', $kindless['positions'][0]);
	}//end testThePositionCarriesTheFormAndTheVersionFollowsIt()

	public function testTheApproverFillsInTheApprovedAmount(): void {
		$this->provision(service: $this->sequences(), schema: $this->schema(forms: [self::amountForm()]));

		self::assertCount(1, $this->inserted);
		$form = ($this->inserted[0]->getMetadata() ?? [])['form'] ?? null;
		self::assertSame('fields', $form['kind']);
		self::assertSame('42', $form['schema']);

		$declaration = $this->reader()->fromRecord(record: $form);
		self::assertSame([['field' => 'goedgekeurdBedrag', 'required' => true]], $declaration->fields, 'The field is asked for and marked required.');
		self::assertSame($form, $this->inserted[0]->getTemplateSnapshot()['form'], 'The snapshot freezes the same form.');
	}//end testTheApproverFillsInTheApprovedAmount()

	public function testATaskFormThatCannotBeRenderedIsRefusedOnImport(): void {
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage('besluitnummer');

		$this->provision(service: $this->sequences(), schema: $this->schema(forms: [['kind' => 'fields', 'fields' => [['name' => 'besluitnummer']]]]));
	}//end testATaskFormThatCannotBeRenderedIsRefusedOnImport()

	public function testAnOpenTaskKeepsItsForm(): void {
		$service = $this->sequences();
		$this->provision(service: $service, schema: $this->schema(forms: [['kind' => 'fields', 'fields' => ['goedgekeurdBedrag*', 'voorwaarde']]]));
		$this->provision(service: $service, schema: $this->schema(forms: [['kind' => 'fields', 'fields' => ['voorwaarde']]]));

		self::assertCount(2, $this->inserted);
		$reader = $this->reader();
		self::assertCount(2, $reader->fromRecord(record: $this->inserted[0]->getMetadata()['form'])->fields, 'The open task still presents two fields.');
		self::assertCount(1, $reader->fromRecord(record: $this->inserted[1]->getMetadata()['form'])->fields, 'The next request presents one.');
		self::assertNotSame($this->inserted[0]->getTemplateVersion(), $this->inserted[1]->getTemplateVersion());
	}//end testAnOpenTaskKeepsItsForm()

	public function testAPositionWithoutAFormIsUnchanged(): void {
		$this->provision(service: $this->sequences(), schema: $this->schema(forms: [null, null]));

		self::assertCount(2, $this->inserted);
		foreach ($this->inserted as $task) {
			self::assertArrayNotHasKey('form', (array)($task->getMetadata() ?? []));
			self::assertSame(ApprovalChainAnnotationInstaller::TEMPLATE_VERSION, $task->getTemplateVersion());
		}
	}//end testAPositionWithoutAFormIsUnchanged()

	/**
	 * A schema body with one chain whose positions carry these forms.
	 *
	 * @param array<int, mixed> $forms One form per position.
	 *
	 * @return array<string, mixed>
	 */
	private function body(array $forms): array {
		$schema = $this->schema(forms: $forms);

		return ['slug' => 'subsidie', 'properties' => self::properties(), 'configuration' => $schema->getConfiguration()];
	}//end body()

	public function testAValidFormPassesTheSchemaSave(): void {
		$guard = new ApprovalPositionFormGuard(forms: $this->reader(), l10n: $this->l10n());

		self::assertTrue(ApprovalPositionFormGuard::declaresForms(data: $this->body(forms: [self::amountForm()])));
		self::assertSame([], $guard->violations(data: $this->body(forms: [self::amountForm(), ['kind' => 'external', 'formId' => 12]])));
		self::assertFalse(ApprovalPositionFormGuard::declaresForms(data: $this->body(forms: [null])));
		self::assertFalse(ApprovalPositionFormGuard::declaresForms(data: ['configuration' => ['x-openregister-approval-chains' => 'nope']]));
		self::assertSame([], $guard->violations(data: ['properties' => self::properties()]));
	}//end testAValidFormPassesTheSchemaSave()

	public function testAnUnrenderableFieldIsRefusedNamingChainPositionFieldAndReason(): void {
		$guard = new ApprovalPositionFormGuard(forms: $this->reader(), l10n: $this->l10n());

		$violations = $guard->violations(
			data: $this->body(
				forms: [
					null,
					['kind' => 'fields', 'fields' => [['name' => 'besluitnummer'], ['name' => 'bestaatNiet'], ['name' => 'interneNotitie'], ['name' => 'voorwaarde']]],
				]
			)
		);

		self::assertSame(
			[
				'Approval chain "budget", position 2 (role-2): Field "besluitnummer" of schema "subsidie" cannot be asked for: '
					. 'the schema marks it read-only, so a submitted value would be refused.',
				'Approval chain "budget", position 2 (role-2): Field "bestaatNiet" of schema "subsidie" cannot be asked for: the schema has no such property.',
				'Approval chain "budget", position 2 (role-2): Field "interneNotitie" of schema "subsidie" cannot be asked for: '
					. 'the schema marks it not visible, so no form can show it.',
			],
			$violations
		);
	}//end testAnUnrenderableFieldIsRefusedNamingChainPositionFieldAndReason()

	public function testAMalformedFormIsRefusedWithTheReadersReason(): void {
		$guard = new ApprovalPositionFormGuard(forms: $this->reader(formsInstalled: false), l10n: $this->l10n());

		$violations = $guard->violations(
			data: $this->body(
				forms: [
					['kind' => 'checkbox'],
					['fields' => ['voorwaarde']],
					['kind' => 'external', 'formId' => 12],
					['kind' => 'fields'],
					[],
				]
			)
		);

		self::assertCount(4, $violations);
		self::assertStringStartsWith('Approval chain "budget", position 1 (role-1): Form kind "checkbox"', $violations[0]);
		self::assertStringStartsWith('Approval chain "budget", position 2 (role-2): A form was described without a kind', $violations[1]);
		self::assertStringStartsWith('Approval chain "budget", position 3 (role-3): An external form needs the Nextcloud Forms app', $violations[2]);
		self::assertStringStartsWith('Approval chain "budget", position 4 (role-4): A field form needs a lifecycle action', $violations[3]);
	}//end testAMalformedFormIsRefusedWithTheReadersReason()
}//end class
