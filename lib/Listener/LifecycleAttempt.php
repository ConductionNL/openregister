<?php

/**
 * The declared transition an update of the lifecycle field amounts to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

/**
 * An edit and the transition it was matched to.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleAttempt {
	/**
	 * Constructor.
	 *
	 * @param LifecycleEdit        $edit   The update.
	 * @param string               $action The matched transition's name.
	 * @param array<string, mixed> $spec   The matched transition's spec.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly LifecycleEdit $edit,
		public readonly string $action,
		public readonly array $spec,
	) {
	}//end __construct()
}//end class
