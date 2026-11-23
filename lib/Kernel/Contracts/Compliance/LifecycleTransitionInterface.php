<?php
/**
 * LifecycleTransitionInterface: moving an object along its lifecycle by a declared action.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Applies one lifecycle action to an object, with its guards, and saves it.
 *
 * The DSAR case API (Compliance) asks; the lifecycle transition engine
 * (Processes) answers and is bound to this contract.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface LifecycleTransitionInterface {

	/**
	 * Apply a transition action to an object and save it.
	 *
	 * @param string $objectId Object id, uuid or slug.
	 * @param string $action   The transition action name.
	 *
	 * @return ObjectEntity The saved object after the transition.
	 *
	 * @throws \Exception When the object, schema or transition is missing, the action is not
	 *                    allowed, the caller may not update the object, or the save is refused.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function transition(string $objectId, string $action): ObjectEntity;
}//end interface
