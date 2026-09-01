<?php

/**
 * Reads a step's form declaration and refuses the ones no performer could complete.
 *
 * Every failure an AUTHOR can fix is caught here, at the moment the author is
 * present: a field that is not a property of the subject schema, one the
 * schema marks read-only or not visible, a lifecycle action the schema does
 * not declare, an external form on an instance without the Forms app. Each is
 * refused naming the schema, the field and the reason. The alternative is the
 * renderer dropping the field silently and the performer holding a form they
 * cannot complete, cannot skip and cannot diagnose (design D-3).
 *
 * Two dialects, one normaliser. A user-task step spells its form as flat
 * `form*` config keys, like every other key in the node's vocabulary, so the
 * server-driven config form needs no editor change to draw it. A run-less
 * task carries the normalised shape under `metadata.form`. Both end here, as
 * one {@see TaskForm}.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;
use UnexpectedValueException;

/**
 * Normalises and validates task form declarations against the live schema.
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
 */
class TaskFormReader {

	/**
	 * The Nextcloud app the external kind depends on.
	 *
	 * @var string
	 */
	public const FORMS_APP = 'forms';

	/**
	 * The flat config keys a user-task step spells its form with.
	 *
	 * @var array<int, string>
	 */
	public const CONFIG_KEYS = [
		'formKind',
		'formSchema',
		'formAction',
		'formFields',
		'formId',
		'formRequireChecklist',
	];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemas Resolves the subject schema.
	 * @param TransitionEngine $engine Reads a transition's declared inputs.
	 * @param IAppManager $apps Answers whether the Forms app is installed.
	 * @param IL10N $l10n Translations, for refusals an author reads.
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
		private readonly TransitionEngine $engine,
		private readonly IAppManager $apps,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The declaration a user-task step's flat config spells.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return TaskForm The declaration; `hasForm()` is false when `formKind` is empty.
	 *
	 * @throws UnexpectedValueException When the declaration is malformed.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
	 */
	public function fromConfig(array $config): TaskForm {
		return $this->fromRecord(
			record: [
				'kind' => ($config['formKind'] ?? null),
				'schema' => ($config['formSchema'] ?? null),
				'action' => ($config['formAction'] ?? null),
				'fields' => ($config['formFields'] ?? null),
				'formId' => ($config['formId'] ?? null),
				'requireChecklist' => ($config['formRequireChecklist'] ?? null),
			]
		);
	}//end fromConfig()

	/**
	 * The declaration a task record carries under `metadata.form`.
	 *
	 * @param array<string, mixed> $record The stored declaration.
	 *
	 * @return TaskForm The declaration; `hasForm()` is false when `kind` is empty.
	 *
	 * @throws UnexpectedValueException When the declaration is malformed.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
	 */
	public function fromRecord(array $record): TaskForm {
		$requireChecklist = filter_var(($record['requireChecklist'] ?? false), FILTER_VALIDATE_BOOLEAN);

		$kind = trim((string)($record['kind'] ?? ''));
		if ($kind === '') {
			$this->refuseOrphanedFormKeys(record: $record);

			return new TaskForm(kind: null, requireChecklist: $requireChecklist);
		}

		if (in_array($kind, TaskForm::KINDS, true) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('Form kind "%1$s" is not one of %2$s.', [$kind, implode(', ', TaskForm::KINDS)])
			);
		}

		if ($kind === TaskForm::KIND_EXTERNAL) {
			return new TaskForm(
				kind: $kind,
				formId: $this->formIdOf(record: $record),
				requireChecklist: $requireChecklist
			);
		}

		$action = $this->nullIfEmpty(value: trim((string)($record['action'] ?? '')));
		$fields = $this->fieldsOf(value: ($record['fields'] ?? null));
		if ($action !== null && $fields !== []) {
			throw new UnexpectedValueException(
				$this->l10n->t('Name a lifecycle action or list the fields, not both: the action already declares its fields.')
			);
		}

		if ($action === null && $fields === []) {
			throw new UnexpectedValueException(
				$this->l10n->t('A field form needs a lifecycle action to inherit its fields from, or a list of fields.')
			);
		}

		return new TaskForm(
			kind: $kind,
			schema: trim((string)($record['schema'] ?? '')),
			action: $action,
			fields: $fields,
			requireChecklist: $requireChecklist
		);
	}//end fromRecord()

	/**
	 * Refuse a declaration no performer could complete, naming what and why.
	 *
	 * @param TaskForm $form The declaration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the declaration is refused.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	public function validate(TaskForm $form): void {
		if ($form->hasForm() === false) {
			return;
		}

		if ($form->isExternal() === true) {
			$this->validateExternal();

			return;
		}

		$schema = $this->schema(reference: $form->schema);
		foreach ($this->declaredFields(form: $form, schema: $schema) as $field) {
			$reason = $this->unrenderableReason(schema: $schema, field: $field['field']);
			if ($reason !== null) {
				throw new UnexpectedValueException(
					$this->l10n->t(
						'Field "%1$s" of schema "%2$s" cannot be asked for: %3$s',
						[$field['field'], (string)$schema->getSlug(), $reason]
					)
				);
			}
		}
	}//end validate()

	/**
	 * The fields a native form asks for: the action's declared inputs, or the inline list.
	 *
	 * @param TaskForm $form The declaration.
	 * @param Schema $schema The live subject schema.
	 *
	 * @return array<int, array{field: string, required: bool}> The field list, in declared order.
	 *
	 * @throws UnexpectedValueException When the named action is not declared on the schema.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
	 */
	public function declaredFields(TaskForm $form, Schema $schema): array {
		if ($form->action === null) {
			return $form->fields;
		}

		$declared = $this->engine->declaredInputs(schema: $schema, action: $form->action);
		if ($declared === null) {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'Schema "%1$s" declares no lifecycle action "%2$s".',
					[(string)$schema->getSlug(), $form->action]
				)
			);
		}

		return $declared;
	}//end declaredFields()

	/**
	 * Why a field of this schema cannot be rendered, or null when it can.
	 *
	 * The three reasons are the three the shared renderer drops a property for
	 * BEFORE it consults the field whitelist; a declared field hitting any of
	 * them renders nothing at all.
	 *
	 * @param Schema $schema The live subject schema.
	 * @param string $field The property name.
	 *
	 * @return string|null The reason, translated, or null when renderable.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	public function unrenderableReason(Schema $schema, string $field): ?string {
		$properties = $schema->getProperties();
		if (array_key_exists($field, $properties) === false) {
			return $this->l10n->t('the schema has no such property.');
		}

		$property = (array)$properties[$field];
		if (($property['readOnly'] ?? false) === true) {
			return $this->l10n->t('the schema marks it read-only, so a submitted value would be refused.');
		}

		if (($property['visible'] ?? true) === false) {
			return $this->l10n->t('the schema marks it not visible, so no form can show it.');
		}

		return null;
	}//end unrenderableReason()

	/**
	 * The live subject schema a reference names.
	 *
	 * @param string $reference The schema id, uuid or slug.
	 *
	 * @return Schema The schema.
	 *
	 * @throws UnexpectedValueException When the reference is empty or names no schema.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	public function schema(string $reference): Schema {
		if (trim($reference) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('A field form must name the subject schema its fields belong to.')
			);
		}

		try {
			// Multitenancy off: a flow's step is authored against a schema by
			// reference and the performer may sit in another organisation;
			// what may be WRITTEN is decided on the save path, not here.
			return $this->schemas->find($reference, _multitenancy: false);
		} catch (Throwable) {
			throw new UnexpectedValueException(
				$this->l10n->t('Schema "%1$s" does not exist.', [$reference])
			);
		}
	}//end schema()

	/**
	 * Refuse an external form where the Forms app is absent.
	 *
	 * Moved from completion time, where the link service would raise it in
	 * front of the performer, to save time, where the author can act on it.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the Forms app is not installed.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-external-form-path-binds-an-existing-forms-form-and-validates-nothing-about-its-contents
	 */
	private function validateExternal(): void {
		if ($this->apps->isInstalled(self::FORMS_APP) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('An external form needs the Nextcloud Forms app, which is not installed on this instance.')
			);
		}
	}//end validateExternal()

	/**
	 * The Forms form id an external declaration names.
	 *
	 * @param array<string, mixed> $record The declaration.
	 *
	 * @return int The form id.
	 *
	 * @throws UnexpectedValueException When absent or not a positive integer.
	 */
	private function formIdOf(array $record): int {
		$raw = ($record['formId'] ?? null);
		if (is_numeric($raw) === false || (int)$raw <= 0) {
			throw new UnexpectedValueException(
				$this->l10n->t('An external form must name the Forms form by its id.')
			);
		}

		return (int)$raw;
	}//end formIdOf()

	/**
	 * A field list from any of the accepted spellings.
	 *
	 * Accepted: a list of `{field, required}` objects (the contract's shape);
	 * a list of property names, where a trailing `*` marks the field required
	 * (what a multi-select editor yields); or that list as a comma-separated
	 * string. A duplicate field is refused rather than merged, because two
	 * entries with different `required` flags have no honest resolution.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array<int, array{field: string, required: bool}> The normalised list.
	 *
	 * @throws UnexpectedValueException When an entry is malformed or duplicated.
	 */
	private function fieldsOf(mixed $value): array {
		if (is_string($value) === true) {
			$value = explode(',', $value);
		}

		if ($value === null) {
			return [];
		}

		if (is_array($value) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('Form fields must be a list of {field, required} entries.')
			);
		}

		$fields = [];
		foreach ($value as $entry) {
			$field = $this->fieldOf(entry: $entry);
			if ($field === null) {
				continue;
			}

			if (array_key_exists($field['field'], $fields) === true) {
				throw new UnexpectedValueException(
					$this->l10n->t('Field "%1$s" is listed twice.', [$field['field']])
				);
			}

			$fields[$field['field']] = $field;
		}

		return array_values($fields);
	}//end fieldsOf()

	/**
	 * One field entry, from an object or a name; null for a blank entry.
	 *
	 * @param mixed $entry The configured entry.
	 *
	 * @return array{field: string, required: bool}|null The normalised entry.
	 *
	 * @throws UnexpectedValueException When the entry is neither.
	 */
	private function fieldOf(mixed $entry): ?array {
		if (is_array($entry) === true) {
			$name = trim((string)($entry['field'] ?? ''));
			if ($name === '') {
				throw new UnexpectedValueException(
					$this->l10n->t('Every form field entry must name its field.')
				);
			}

			return [
				'field' => $name,
				'required' => filter_var(($entry['required'] ?? false), FILTER_VALIDATE_BOOLEAN),
			];
		}

		if (is_scalar($entry) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('Form fields must be a list of {field, required} entries.')
			);
		}

		$name = trim((string)$entry);
		if ($name === '') {
			return null;
		}

		$required = str_ends_with($name, '*');
		if ($required === true) {
			$name = rtrim(substr($name, 0, -1));
		}

		return [
			'field' => $name,
			'required' => $required,
		];
	}//end fieldOf()

	/**
	 * Refuse form keys given without a kind: they would look configured and do nothing.
	 *
	 * @param array<string, mixed> $record The declaration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When any form key other than the checklist rule is set.
	 */
	private function refuseOrphanedFormKeys(array $record): void {
		foreach (['schema', 'action', 'fields', 'formId'] as $key) {
			$value = ($record[$key] ?? null);
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			throw new UnexpectedValueException(
				$this->l10n->t('A form was described without a kind. Set the form kind to "fields" or "external", or clear the form settings.')
			);
		}
	}//end refuseOrphanedFormKeys()

	/**
	 * Null for an empty string.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The value, or null when empty.
	 */
	private function nullIfEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfEmpty()
}//end class
