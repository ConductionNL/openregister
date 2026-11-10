<?php

/**
 * OpenRegister StateMove
 *
 * One object's lifecycle value moving from one state to another.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The object whose lifecycle value moves, the state it leaves and the state it
 * enters, as {@see LifecycleTransitionResolverInterface::resolve()} matches it
 * against the declared transitions (ADR-011 Rule 2).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class StateMove {

	/**
	 * Capture the move.
	 *
	 * @param string $uuid The object's uuid, for the declared-action lookup.
	 * @param string $from The lifecycle value being moved away from.
	 * @param string $to   The lifecycle value being moved to.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()
}//end class
