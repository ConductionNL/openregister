<?php

/**
 * OpenRegister LifecycleRuleCheck
 *
 * Builds the document a lifecycle rule reads and asks the condition dialect
 * whether a rule object holds against it.
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

use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionEvaluation;
use OCA\OpenRegister\Service\Rules\ConditionRefusedException;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;

/**
 * The document a lifecycle rule reads, and the dialect evaluation against it.
 *
 * Only {@see LifecycleConditionEvaluator} uses this: the evaluator keeps the
 * fail-closed refusal of a value that is not a rule object, so a value reaches
 * {@see holds()} only once it is a non-empty rule object.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleRuleCheck {
	/**
	 * Resolves `$condition` references and hands every other node to the dialect.
	 *
	 * @var NamedConditionEvaluator
	 */
	private readonly NamedConditionEvaluator $named;

	/**
	 * Constructor.
	 *
	 * @param ConditionDialect             $dialect Decides which dialect a rule is written in and evaluates it.
	 * @param LifecycleRuleCaller          $caller  The caller the rule is evaluated for, for the `user` key.
	 * @param NamedConditionEvaluator|null $named   The evaluator that resolves named conditions; built over
	 *                                              the same dialect when not given.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function __construct(
		private readonly ConditionDialect $dialect,
		private readonly LifecycleRuleCaller $caller,
		?NamedConditionEvaluator $named = null,
	) {
		$this->named = ($named ?? new NamedConditionEvaluator(dialect: $this->dialect, library: new NamedConditionLibrary()));
	}//end __construct()

	/**
	 * Whether a non-empty rule object holds for this attempt.
	 *
	 * @param array<string, mixed> $rule    The rule object.
	 * @param TransitionAttempt    $attempt The write and the transition it attempts.
	 *
	 * @return bool False also when the rule cannot be evaluated.
	 *
	 * @throws ConditionRefusedException When a named condition it references cannot be resolved.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function holds(array $rule, TransitionAttempt $attempt): bool {
		return $this->named->holds(
			node: $rule,
			evaluation: new ConditionEvaluation(document: $this->document(attempt: $attempt), library: $attempt->conditions, depth: 0)
		);
	}//end holds()

	/**
	 * Build the document a rule is evaluated against, for one attempt.
	 *
	 * Exactly four keys: `object`, `previous`, `user` and `transition`.
	 *
	 * @param TransitionAttempt $attempt The write (object after and before) and the move.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function document(TransitionAttempt $attempt): array {
		$move = $attempt->move;

		return [
			'object' => $attempt->write->newData,
			'previous' => $attempt->write->oldData,
			'user' => $this->caller->describe(),
			'transition' => ['action' => $move->action, 'from' => $move->from, 'to' => $move->to],
		];
	}//end document()
}//end class
