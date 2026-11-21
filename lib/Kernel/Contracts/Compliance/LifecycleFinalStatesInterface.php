<?php
/**
 * LifecycleFinalStatesInterface: whether a lifecycle state ends the lifecycle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Compliance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Compliance;

/**
 * The question archival asks the lifecycle: has the object reached an end?
 *
 * Archival (Compliance) nominates an object once it reaches a final state;
 * the lifecycle (Processes) knows what `final` means and binds its answer in
 * its own bindings.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
 */
interface LifecycleFinalStatesInterface {

	/**
	 * Does the declared `final`, a list of states or a `{ from, field }` reference, end at this state?
	 *
	 * @param array  $final The schema's declared `final` value.
	 * @param string $state The lifecycle value the object reached.
	 *
	 * @return bool True when the state is final; an unresolvable reference is not.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	public function isFinalState(array $final, string $state): bool;
}//end interface
