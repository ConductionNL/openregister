<?php

/**
 * The form declaration: what a step may say, and what is refused when it is saved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Normalising and refusing task form declarations.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskFormReader
 * @covers \OCA\OpenRegister\Service\Task\TaskForm
 */
class TaskFormReaderTest extends TestCase {

	private SchemaMapper&MockObject $schemas;

	private TransitionEngine&MockObject $engine;

	private IAppManager&MockObject $apps;

	private TaskFormReader $reader;

	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->engine = $this->createMock(TransitionEngine::class);
		$this->apps = $this->createMock(IAppManager::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$this->reader = new TaskFormReader(schemas: $this->schemas, engine: $this->engine, apps: $this->apps, l10n: $l10n);
	}//end setUp()

	/**
	 * A subject schema with one plain, one read-only and one invisible property.
	 */
	private function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setProperties(
			[
				'reason' => ['type' => 'string'],
				'note' => ['type' => 'string'],
				'locked' => ['type' => 'string', 'readOnly' => true],
				'hidden' => ['type' => 'string', 'visible' => false],
			]
		);

		return $schema;
	}//end caseSchema()

	/**
	 * A step with no form keys declares no form; completion stays outcome-and-comment.
	 */
	public function testNoFormKeysMeansNoForm(): void {
		$form = $this->reader->fromConfig(config: ['title' => 'Approve']);

		$this->assertFalse($form->hasForm());
		$this->assertFalse($form->isNative());
		$this->assertFalse($form->isExternal());
		$this->assertFalse($form->requireChecklist);
		$this->reader->validate(form: $form);
	}//end testNoFormKeysMeansNoForm()

	/**
	 * The checklist rule stands on its own: it is not a field and needs no form.
	 */
	public function testTheChecklistRuleNeedsNoForm(): void {
		$form = $this->reader->fromConfig(config: ['formRequireChecklist' => true]);

		$this->assertFalse($form->hasForm());
		$this->assertTrue($form->requireChecklist);
	}//end testTheChecklistRuleNeedsNoForm()

	/**
	 * The inline list in the contract's shape, in declared order, required from the declaration.
	 */
	public function testAnInlineListKeepsOrderAndRequired(): void {
		$form = $this->reader->fromConfig(
			config: [
				'formKind' => 'fields',
				'formSchema' => 'case',
				'formFields' => [
					['field' => 'note', 'required' => false],
					['field' => 'reason', 'required' => true],
				],
			]
		);

		$this->assertTrue($form->isNative());
		$this->assertSame('case', $form->schema);
		$this->assertNull($form->action);
		$this->assertSame(
			[
				['field' => 'note', 'required' => false],
				['field' => 'reason', 'required' => true],
			],
			$form->fields
		);
	}//end testAnInlineListKeepsOrderAndRequired()

	/**
	 * The name spelling a multi-select editor yields: `name*` is required.
	 */
	public function testANameListMarksRequiredWithAStar(): void {
		$form = $this->reader->fromConfig(
			config: [
				'formKind' => 'fields',
				'formSchema' => 'case',
				'formFields' => 'reason*, note',
			]
		);

		$this->assertSame(
			[
				['field' => 'reason', 'required' => true],
				['field' => 'note', 'required' => false],
			],
			$form->fields
		);
	}//end testANameListMarksRequiredWithAStar()

	/**
	 * Naming an action inherits its inputs; the declaration must not restate them.
	 */
	public function testAnActionAndAnInlineListTogetherAreRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('not both');
		$this->reader->fromConfig(
			config: [
				'formKind' => 'fields',
				'formSchema' => 'case',
				'formAction' => 'reject',
				'formFields' => 'reason*',
			]
		);
	}//end testAnActionAndAnInlineListTogetherAreRefused()

	/**
	 * A field form with neither an action nor fields would be an empty form: refused.
	 */
	public function testAnEmptyFieldFormIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('lifecycle action');
		$this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'case']);
	}//end testAnEmptyFieldFormIsRefused()

	/**
	 * A third kind does not exist.
	 */
	public function testAnUnknownKindIsRefusedNamingTheTwo(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('fields, external');
		$this->reader->fromConfig(config: ['formKind' => 'adhoc']);
	}//end testAnUnknownKindIsRefusedNamingTheTwo()

	/**
	 * Form keys without a kind would look configured and do nothing.
	 */
	public function testFormKeysWithoutAKindAreRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('without a kind');
		$this->reader->fromConfig(config: ['formFields' => 'reason*']);
	}//end testFormKeysWithoutAKindAreRefused()

	/**
	 * A field listed twice has no honest resolution when the flags differ.
	 */
	public function testADuplicateFieldIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('"reason" is listed twice');
		$this->reader->fromConfig(
			config: [
				'formKind' => 'fields',
				'formSchema' => 'case',
				'formFields' => [['field' => 'reason', 'required' => true], 'reason'],
			]
		);
	}//end testADuplicateFieldIsRefused()

	/**
	 * A field entry without a name is malformed.
	 */
	public function testAFieldEntryWithoutANameIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('name its field');
		$this->reader->fromConfig(
			config: [
				'formKind' => 'fields',
				'formSchema' => 'case',
				'formFields' => [['required' => true]],
			]
		);
	}//end testAFieldEntryWithoutANameIsRefused()

	/**
	 * An external form names the Forms form by id, or it names nothing.
	 */
	public function testAnExternalFormNeedsAFormId(): void {
		$form = $this->reader->fromConfig(config: ['formKind' => 'external', 'formId' => '12']);
		$this->assertTrue($form->isExternal());
		$this->assertSame(12, $form->formId);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('by its id');
		$this->reader->fromConfig(config: ['formKind' => 'external']);
	}//end testAnExternalFormNeedsAFormId()

	/**
	 * A misspelled field is refused at save time naming the schema and the field.
	 */
	public function testAFieldTheSchemaLacksIsRefusedNamingSchemaAndField(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'reasonn*']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Field "reasonn" of schema "case" cannot be asked for: the schema has no such property.');
		$this->reader->validate(form: $form);
	}//end testAFieldTheSchemaLacksIsRefusedNamingSchemaAndField()

	/**
	 * A read-only field is refused rather than rendered blank.
	 */
	public function testAReadOnlyFieldIsRefusedNamingReadOnly(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'locked']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('read-only');
		$this->reader->validate(form: $form);
	}//end testAReadOnlyFieldIsRefusedNamingReadOnly()

	/**
	 * An invisible field is unrenderable by construction, so it is refused.
	 */
	public function testAnInvisibleFieldIsRefusedNamingVisibility(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'hidden']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('not visible');
		$this->reader->validate(form: $form);
	}//end testAnInvisibleFieldIsRefusedNamingVisibility()

	/**
	 * Renderable fields pass, whatever the schema's own `required` says.
	 */
	public function testRenderableFieldsPass(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'case', 'formFields' => 'reason*, note']);

		$this->reader->validate(form: $form);
		$this->assertNull($this->reader->unrenderableReason(schema: $this->caseSchema(), field: 'reason'));
	}//end testRenderableFieldsPass()

	/**
	 * A step naming an action inherits the transition's inputs verbatim, from the engine.
	 */
	public function testAnActionInheritsTheTransitionsDeclaredInputs(): void {
		$schema = $this->caseSchema();
		$this->schemas->method('find')->willReturn($schema);
		$this->engine->expects($this->atLeastOnce())->method('declaredInputs')
			->with($schema, 'reject')
			->willReturn([['field' => 'reason', 'required' => true], ['field' => 'note', 'required' => false]]);
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'case', 'formAction' => 'reject']);

		$this->reader->validate(form: $form);
		$this->assertSame(
			[['field' => 'reason', 'required' => true], ['field' => 'note', 'required' => false]],
			$this->reader->declaredFields(form: $form, schema: $schema)
		);
	}//end testAnActionInheritsTheTransitionsDeclaredInputs()

	/**
	 * An action the subject schema does not declare is refused naming both.
	 */
	public function testAnUndeclaredActionIsRefused(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->engine->method('declaredInputs')->willReturn(null);
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'case', 'formAction' => 'vanish']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Schema "case" declares no lifecycle action "vanish".');
		$this->reader->validate(form: $form);
	}//end testAnUndeclaredActionIsRefused()

	/**
	 * An inherited input the schema marks read-only is as unrenderable as an inline one.
	 */
	public function testAnInheritedReadOnlyInputIsRefused(): void {
		$this->schemas->method('find')->willReturn($this->caseSchema());
		$this->engine->method('declaredInputs')->willReturn([['field' => 'locked', 'required' => true]]);
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'case', 'formAction' => 'reject']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('read-only');
		$this->reader->validate(form: $form);
	}//end testAnInheritedReadOnlyInputIsRefused()

	/**
	 * A schema reference that names nothing is refused naming the reference.
	 */
	public function testAMissingSchemaIsRefusedNamingIt(): void {
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formSchema' => 'ghost', 'formFields' => 'reason']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Schema "ghost" does not exist.');
		$this->reader->validate(form: $form);
	}//end testAMissingSchemaIsRefusedNamingIt()

	/**
	 * A field form must say which schema its fields belong to.
	 */
	public function testAFieldFormWithoutASchemaIsRefused(): void {
		$form = $this->reader->fromConfig(config: ['formKind' => 'fields', 'formFields' => 'reason']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('subject schema');
		$this->reader->validate(form: $form);
	}//end testAFieldFormWithoutASchemaIsRefused()

	/**
	 * An external step is refused without the Forms app, at save time, naming the app.
	 */
	public function testAnExternalFormIsRefusedWithoutTheFormsApp(): void {
		$this->apps->method('isInstalled')->with('forms')->willReturn(false);
		$form = $this->reader->fromConfig(config: ['formKind' => 'external', 'formId' => 3]);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Nextcloud Forms app');
		$this->reader->validate(form: $form);
	}//end testAnExternalFormIsRefusedWithoutTheFormsApp()

	/**
	 * With the Forms app present the external kind validates nothing about the form's contents.
	 */
	public function testAnExternalFormPassesWithTheFormsApp(): void {
		$this->apps->method('isInstalled')->with('forms')->willReturn(true);
		$this->schemas->expects($this->never())->method('find');
		$form = $this->reader->fromConfig(config: ['formKind' => 'external', 'formId' => 3]);

		$this->reader->validate(form: $form);
	}//end testAnExternalFormPassesWithTheFormsApp()

	/**
	 * The record shape round-trips: what a run-less task stores is what it reads back.
	 */
	public function testTheRecordShapeRoundTrips(): void {
		$form = $this->reader->fromConfig(
			config: [
				'formKind' => 'fields',
				'formSchema' => 'case',
				'formFields' => 'reason*, note',
				'formRequireChecklist' => 'true',
			]
		);

		$again = $this->reader->fromRecord(record: $form->toArray());

		$this->assertSame($form->toArray(), $again->toArray());
		$this->assertTrue($again->requireChecklist);
		$this->assertSame(TaskForm::KIND_FIELDS, $again->kind);
	}//end testTheRecordShapeRoundTrips()
}//end class
