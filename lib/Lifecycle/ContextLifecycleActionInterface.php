<?php

/**
 * OpenRegister ContextLifecycleActionInterface
 *
 * A lifecycle action handler that takes one LifecycleActionContext.
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
 * A lifecycle action handler in the parameter-object form.
 *
 * @see LifecycleActionInterface for what a handler must do.
 */
interface ContextLifecycleActionInterface extends LifecycleActionInterface {
	/**
	 * Run the action on a transitioning object.
	 *
	 * @param LifecycleActionContext $context The payload after and before the transition, the declared
	 *                                        `actionParameters` block and the declared action name.
	 *
	 * @return array<string, mixed> The object payload, with any self-mutations applied. Return
	 *                              `$context->objectData` unchanged for pure side-effect actions.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function execute(LifecycleActionContext $context): array;
}//end interface
