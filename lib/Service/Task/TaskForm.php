<?php

/**
 * What a user-task step declares about how its task is completed.
 *
 * A value, not a form definition. The native kind is a list of fields of the
 * SUBJECT object's schema in the lifecycle input contract's own shape,
 * `[{field, required}]`, obtained either by naming a lifecycle action (the
 * transition's declared inputs, verbatim) or by listing them inline. The
 * external kind names a Nextcloud Forms form bound to the subject. There is no
 * third kind, and no field type vocabulary: a form is a field list plus the
 * schema those fields already belong to.
 *
 * The checklist precondition rides along because it is decided at the same
 * moment, by the same author, about the same completion; it is NOT a field,
 * and it never enters the field payload.
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
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * A step's completion declaration: its form, if any, and its checklist rule.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `requireChecklist` is a stored
 * fact on a readonly value object, constructed with named arguments by one
 * reader; it selects no behaviour in this class, which is what the rule is
 * actually about.
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
 */
final class TaskForm {

	/**
	 * The native kind: fields of the subject schema.
	 *
	 * @var string
	 */
	public const KIND_FIELDS = 'fields';

	/**
	 * The external kind: a Nextcloud Forms form bound to the subject.
	 *
	 * @var string
	 */
	public const KIND_EXTERNAL = 'external';

	/**
	 * The two kinds. No third exists.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = [self::KIND_FIELDS, self::KIND_EXTERNAL];

	/**
	 * Constructor.
	 *
	 * @param string|null $kind One of KINDS, or null when the step declares no form.
	 * @param string $schema The subject schema reference (id, uuid or slug); '' when not native.
	 * @param string|null $action The lifecycle action whose inputs are the field list, or null.
	 * @param array<int, array{field: string, required: bool}> $fields The inline field list, in declared order.
	 * @param int|null $formId The Nextcloud Forms form id, for the external kind.
	 * @param bool $requireChecklist Whether every checklist item must be checked before completion.
	 */
	public function __construct(
		public readonly ?string $kind,
		public readonly string $schema = '',
		public readonly ?string $action = null,
		public readonly array $fields = [],
		public readonly ?int $formId = null,
		public readonly bool $requireChecklist = false,
	) {

	}//end __construct()

	/**
	 * Whether the step declares a form at all.
	 *
	 * @return bool True for either kind; false for outcome-and-comment completion.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
	 */
	public function hasForm(): bool {
		return $this->kind !== null;
	}//end hasForm()

	/**
	 * Whether the form is the native, field-list kind.
	 *
	 * @return bool True when fields of the subject schema are asked for.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
	 */
	public function isNative(): bool {
		return $this->kind === self::KIND_FIELDS;
	}//end isNative()

	/**
	 * Whether the form is the external, Nextcloud Forms kind.
	 *
	 * @return bool True when a bound Forms form is the way to finish.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-external-form-path-binds-an-existing-forms-form-and-validates-nothing-about-its-contents
	 */
	public function isExternal(): bool {
		return $this->kind === self::KIND_EXTERNAL;
	}//end isExternal()

	/**
	 * The record shape: what a run-less task carries under `metadata.form`.
	 *
	 * @return array<string, mixed> The declaration, ready to store or serialise.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
	 */
	public function toArray(): array {
		return [
			'kind' => $this->kind,
			'schema' => $this->schema,
			'action' => $this->action,
			'fields' => $this->fields,
			'formId' => $this->formId,
			'requireChecklist' => $this->requireChecklist,
		];
	}//end toArray()
}//end class
