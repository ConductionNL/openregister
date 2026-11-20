<?php
/**
 * ObjectTransitionInterface: applies a named lifecycle transition to one object.
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
 * Moves an object along its declared lifecycle, as the DSAR case API (Compliance) asks it.
 *
 * Compliance asks; the lifecycle engine (Processes) answers, with its own
 * permission checks, guards and audit entry, and binds itself outside Compliance.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface ObjectTransitionInterface {

	/**
	 * Apply a named transition to an object, as the session user.
	 *
	 * @param string $objectId Object id, uuid or slug.
	 * @param string $action   Transition action name.
	 *
	 * @return ObjectEntity The saved object after the transition.
	 *
	 * @throws \RuntimeException When the object, schema or transition is missing, the action is not
	 *                           allowed from the current state, or the save is rejected.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks update permission on the object.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function transition(string $objectId, string $action): ObjectEntity;
}//end interface
