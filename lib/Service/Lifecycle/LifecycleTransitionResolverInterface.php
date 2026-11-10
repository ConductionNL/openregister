<?php

/**
 * LifecycleTransitionResolverInterface: what callers outside the component use of LifecycleTransitionResolver.
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

/**
 * Decide which declared transition an edit of the lifecycle value is.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface LifecycleTransitionResolverInterface {

	/**
	 * The (action, spec) pair this change is, or null when none allows it.
	 *
	 * @param array<string, mixed> $transitions The annotation's transition map.
	 * @param StateMove $move The object, the state it leaves and the state it enters.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resolve(array $transitions, StateMove $move): ?array;

	/**
	 * Whether the write of this object in flight sets declared state (a seed or example-set refresh).
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-declared-state-as-the-system
	 */
	public function writesDeclaredState(string $uuid): bool;
}//end interface
