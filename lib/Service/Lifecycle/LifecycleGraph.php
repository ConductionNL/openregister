<?php

/**
 * OpenRegister LifecycleGraph
 *
 * A lifecycle graph given as data, as LifecycleTransitionsValidator checks it.
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
 * The states, initial state, transitions and guard catalogue of one lifecycle
 * passed as data: the operation object of
 * {@see LifecycleTransitionsValidator::validate()} (ADR-011 Rule 2).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleGraph {

	/**
	 * Capture the graph.
	 *
	 * @param array<int, mixed>        $states      State names, or `{name}` objects.
	 * @param string|null              $initial     The initial state.
	 * @param array<int|string, mixed> $transitions A list, or a map keyed by action, of `{from, to, guards?}`.
	 *                                              `from` is a state or a list of states.
	 * @param array<int, string>|null  $knownGuards The app's guard catalogue. When given, a guard token outside
	 *                                              it is refused; when null, guards are shape-checked only.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly array $states,
		public readonly ?string $initial,
		public readonly array $transitions,
		public readonly ?array $knownGuards=null,
	) {
	}//end __construct()
}//end class
