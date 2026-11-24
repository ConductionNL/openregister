<?php

/**
 * Runs an operation inside KeepLifecycleStatesScope, for callers below the lifecycle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Kernel\Contracts\Core\LifecycleStatesKeeperInterface;

/**
 * Answers the register import's historic-import request with the lifecycle's own scope.
 *
 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
 */
final class LifecycleStatesKeeper implements LifecycleStatesKeeperInterface {

	/**
	 * Run the operation; while it runs, a create keeps the lifecycle state it carries.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returned.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) KeepLifecycleStatesScope is an ambient marker; a static call is the whole point of it.
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
	 */
	public function keepingStates(callable $operation): mixed {
		return KeepLifecycleStatesScope::run($operation);
	}//end keepingStates()
}//end class
