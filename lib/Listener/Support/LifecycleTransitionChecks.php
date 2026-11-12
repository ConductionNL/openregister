<?php

/**
 * OpenRegister LifecycleTransitionChecks
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistryInterface;
use OCA\OpenRegister\Service\Rules\ConditionTracerInterface;

/**
 * What a matched transition is checked with after authorization: its condition and its guard.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleTransitionChecks {
	/**
	 * Bundle the services.
	 *
	 * @param LifecycleGuardRegistryInterface      $guardRegistry      Registry resolving guard ids to instances.
	 * @param LifecycleConditionEvaluatorInterface $conditionEvaluator Decides whether a transition `condition` lets it through.
	 * @param ConditionTracerInterface             $conditionTracer    Names the operand that decided a condition.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly LifecycleGuardRegistryInterface $guardRegistry,
		public readonly LifecycleConditionEvaluatorInterface $conditionEvaluator,
		public readonly ConditionTracerInterface $conditionTracer,
	) {
	}//end __construct()
}//end class
