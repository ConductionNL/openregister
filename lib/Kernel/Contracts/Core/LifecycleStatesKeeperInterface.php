<?php
/**
 * LifecycleStatesKeeperInterface: run an operation in which a create keeps the lifecycle state it carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Lifts the initial-state check on creates for the length of one operation.
 *
 * The register import (Core) asks for an administrator's historic import; the
 * lifecycle (Processes) owns the check it lifts and binds its answer in its own
 * registrar. Permissions, guards and validation still apply: it is not an elevation.
 */
interface LifecycleStatesKeeperInterface {

	/**
	 * Run the operation; while it runs, a create keeps the lifecycle state it carries.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returned.
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
	 */
	public function keepingStates(callable $operation): mixed;
}//end interface
