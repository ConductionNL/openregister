<?php

/**
 * OpenRegister RuleTrialEngine
 *
 * The evaluators a rule trial runs a rule through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules\Support;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;

/**
 * The calculation evaluator, the condition dialect and the tracer, bundled for RuleTrialService.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleTrialEngine {

	/**
	 * Bundle the evaluators.
	 *
	 * @param CalculationEvaluatorInterface $evaluator The JSON-AST evaluator.
	 * @param ConditionTracer $tracer Names the operand that decided.
	 * @param ConditionDialect $dialect Decides and evaluates a condition in whichever dialect it is written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly CalculationEvaluatorInterface $evaluator,
		public readonly ConditionTracer $tracer,
		public readonly ConditionDialect $dialect,
	) {
	}//end __construct()
}//end class
