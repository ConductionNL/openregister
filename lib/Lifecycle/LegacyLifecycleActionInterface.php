<?php

/**
 * OpenRegister LegacyLifecycleActionInterface
 *
 * A lifecycle action handler in the released positional form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Lifecycle
 * @package  OCA\OpenRegister\Lifecycle
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

namespace OCA\OpenRegister\Lifecycle;

/**
 * A lifecycle action handler in the positional form of the released contract.
 *
 * @deprecated Implement ContextLifecycleActionInterface.
 *
 * @see LifecycleActionInterface for what a handler must do.
 */
interface LegacyLifecycleActionInterface extends LifecycleActionInterface {
	/**
	 * Run the action on a transitioning object.
	 *
	 * @param array<string, mixed> $objectData   The object payload after the transition.
	 * @param array<string, mixed> $previousData The object payload before the transition.
	 * @param array<string, mixed> $parameters   The declared `actionParameters` block.
	 * @param string               $actionName   The declared action name.
	 *
	 * @return array<string, mixed> The object payload, with any self-mutations applied.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array;
}//end interface
