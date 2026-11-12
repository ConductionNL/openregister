<?php

/**
 * LifecycleGuardRegistryInterface: what callers outside the component use of LifecycleGuardRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use RuntimeException;

/**
 * Resolve a lifecycle guard tag to the guard that implements it.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface LifecycleGuardRegistryInterface {

	/**
	 * Resolve a guard tag to its registered implementation.
	 *
	 * @param string $tag DI service tag (e.g. `decidesk.meeting.openGuard`).
	 *
	 * @return LifecycleGuardInterface
	 *
	 * @throws RuntimeException When the tag is not registered or the resolved service does not implement the interface.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resolve(string $tag): LifecycleGuardInterface;
}//end interface
