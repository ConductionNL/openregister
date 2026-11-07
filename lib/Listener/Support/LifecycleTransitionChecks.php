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
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCA\OpenRegister\Service\Rules\ConditionTracerInterface;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;

/**
 * What a matched transition is checked with after authorization: its condition, its guard, and the run log.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleTransitionChecks {
	/**
	 * Bundle the services.
	 *
	 * @param LifecycleGuardRegistry               $guardRegistry      Registry resolving guard ids to instances.
	 * @param LifecycleConditionEvaluatorInterface $conditionEvaluator Decides whether a transition `condition` lets it through.
	 * @param ConditionTracerInterface             $conditionTracer    Names the operand that decided a condition.
	 * @param RuleRunRecorderInterface             $ruleRuns           Records each condition's verdict for the rule inventory.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly LifecycleGuardRegistry $guardRegistry,
		public readonly LifecycleConditionEvaluatorInterface $conditionEvaluator,
		public readonly ConditionTracerInterface $conditionTracer,
		public readonly RuleRunRecorderInterface $ruleRuns,
	) {
	}//end __construct()
}//end class
