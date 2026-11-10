<?php

/**
 * Checking or unchecking one checklist item of a task.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * One checklist item and the flag it is set to.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class ChecklistTick {
	/**
	 * The tick.
	 *
	 * @param string $itemId The checklist item id.
	 * @param bool $checked The new flag.
	 */
	public function __construct(
		public readonly string $itemId,
		public readonly bool $checked,
	) {
	}//end __construct()

	/**
	 * The audit reason for this tick.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function reason(): string {
		return sprintf("Item '%s' set to '%s'.", $this->itemId, var_export($this->checked, true));
	}//end reason()
}//end class
