<?php

/**
 * Which fields of which schema a native task form asks for.
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
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The subject schema and its fields: named through a lifecycle action, or listed inline.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
 */
final class TaskFormFields {
	/**
	 * Constructor.
	 *
	 * @param string $schema The subject schema reference (id, uuid or slug); '' when not native.
	 * @param string|null $action The lifecycle action whose inputs are the field list, or null.
	 * @param array<int, array{field: string, required: bool}> $fields The inline field list, in declared order.
	 */
	public function __construct(
		public readonly string $schema = '',
		public readonly ?string $action = null,
		public readonly array $fields = [],
	) {
	}//end __construct()
}//end class
