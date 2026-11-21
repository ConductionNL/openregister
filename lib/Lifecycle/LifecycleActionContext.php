<?php

/**
 * OpenRegister LifecycleActionContext
 *
 * What a lifecycle action runs on: the object payload after and before the
 * transition, the declared action parameters and the declared action name.
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
 * Read-only input of LifecycleActionInterface::execute().
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleActionContext {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $objectData   The object payload after the lifecycle field was moved to its target value.
	 * @param array<string, mixed> $previousData The object payload before the transition (for conditions / diffing).
	 * @param array<string, mixed> $parameters   The declared `actionParameters` block (empty array when absent).
	 * @param string               $actionName   The declared `action` name that resolved to the handler.
	 */
	public function __construct(
		public readonly array $objectData,
		public readonly array $previousData,
		public readonly array $parameters,
		public readonly string $actionName,
	) {
	}//end __construct()
}//end class
