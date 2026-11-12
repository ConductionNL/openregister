<?php

/**
 * LifecycleActionExecutorInterface: what callers outside the component use of LifecycleActionExecutor.
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

use RuntimeException;

/**
 * Run the actions a lifecycle transition declares.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface LifecycleActionExecutorInterface {

	/**
	 * Run every declared action for a matched transition.
	 *
	 * @param array<int, mixed> $actions The transition's declared `actions[]` list.
	 * @param TransitionPayload $payload The transition name and the object payload after and before it.
	 *
	 * @return array<string, mixed> The object payload after all self-mutating actions applied.
	 *
	 * @throws RuntimeException When an action is malformed, its condition is unparseable, or its handler is missing.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function run(array $actions, TransitionPayload $payload): array;
}//end interface
