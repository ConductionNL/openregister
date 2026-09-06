<?php

/**
 * A task completion payload the form contract refused, with its fields named.
 *
 * The task-side twin of {@see InvalidTransitionInputException}: the same 400,
 * the same machine-readable `fields` next to the human message, plus a `kind`
 * so a client can tell an undeclared key from a missing required input from an
 * unchecked checklist item without parsing prose. It extends the task's own
 * validation exception so every existing 400 mapping keeps applying.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-validation-failure-names-its-fields-and-completes-nothing
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * A refused completion payload, naming the fields and the kind of refusal.
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-validation-failure-names-its-fields-and-completes-nothing
 */
class TaskFormRefusedException extends TaskValidationException {

	/**
	 * The payload carried a key the form did not declare.
	 *
	 * @var string
	 */
	public const KIND_UNDECLARED = 'undeclared';

	/**
	 * A required input was absent or an empty string.
	 *
	 * @var string
	 */
	public const KIND_MISSING = 'missing';

	/**
	 * A mandatory checklist item is still unchecked.
	 *
	 * @var string
	 */
	public const KIND_CHECKLIST = 'checklist';

	/**
	 * The form itself could not be resolved, so nothing can be completed.
	 *
	 * @var string
	 */
	public const KIND_UNRESOLVABLE = 'unresolvable';

	/**
	 * The task has no subject object for the values to be written to.
	 *
	 * @var string
	 */
	public const KIND_NO_SUBJECT = 'no-subject';

	/**
	 * Constructor.
	 *
	 * @param string $message The human message, naming the fields.
	 * @param string $kind One of the KIND_* constants.
	 * @param array<int, string> $fields The offending field names or checklist item ids.
	 */
	public function __construct(
		string $message,
		private readonly string $kind,
		private readonly array $fields = [],
	) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * Which kind of refusal this is.
	 *
	 * @return string One of the KIND_* constants.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-validation-failure-names-its-fields-and-completes-nothing
	 */
	public function getKind(): string {
		return $this->kind;
	}//end getKind()

	/**
	 * The offending field names or checklist item ids.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-validation-failure-names-its-fields-and-completes-nothing
	 */
	public function getFields(): array {
		return $this->fields;
	}//end getFields()
}//end class
