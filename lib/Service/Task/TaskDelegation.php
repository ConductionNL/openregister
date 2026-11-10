<?php

/**
 * A delegation of a task: who takes it over, under which mandate.
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
 * The delegate and the mandate of one delegation.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskDelegation {
	/**
	 * The delegation.
	 *
	 * @param string $delegate Who takes the task over.
	 * @param string $mandate The authority relied on.
	 */
	public function __construct(
		public readonly string $delegate,
		public readonly string $mandate,
	) {
	}//end __construct()
}//end class
