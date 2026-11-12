<?php

/**
 * OpenRegister TransitionMove
 *
 * The action a committed transition applied, and the states it moved between.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The action a committed transition applied, and the states it moved between.
 *
 * What {@see TransitionEngine} reports on ObjectTransitionedEvent.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionMove {

	/**
	 * Name the move.
	 *
	 * @param string $action The transition action that was applied.
	 * @param string $from   The lifecycle value before the transition.
	 * @param string $to     The lifecycle value after the transition.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $action,
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()
}//end class
