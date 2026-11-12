<?php

/**
 * OpenRegister ExpressionDefaultEvaluation
 *
 * Evaluates the expression defaults of one create, refusing rather than deriving nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use Throwable;

/**
 * Every expression of one create, evaluated against the object as submitted.
 *
 * Built per create by {@see ExpressionDefaultResolver::apply()}: it holds the
 * submitted object, never the values other defaults produced in the same pass,
 * so the result does not depend on the order properties are declared in.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ExpressionDefaultEvaluation {

	/**
	 * Constructor.
	 *
	 * @param CalculationEvaluatorInterface $evaluator The JSON-AST evaluator, the same one calculations use.
	 * @param array<string, mixed>          $data      The object as submitted.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalculationEvaluatorInterface $evaluator,
		private readonly array $data,
	) {
	}//end __construct()

	/**
	 * Evaluate one expression, or refuse naming the property.
	 *
	 * @param string $name       The property the default belongs to.
	 * @param mixed  $expression The declared expression.
	 *
	 * @return mixed The derived value, never null.
	 *
	 * @throws ExpressionDefaultException When the expression throws or yields nothing.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function valueOf(string $name, mixed $expression): mixed {
		$value = $this->evaluate(name: $name, expression: $expression);
		if ($value === null) {
			throw new ExpressionDefaultException(
				property: $name,
				message: sprintf(
					'The default for "%s" evaluated to nothing, so it was not written as empty. '
					. 'The object was not created.',
					$name
				)
			);
		}

		return $value;
	}//end valueOf()

	/**
	 * Evaluate one expression, turning any failure into the refusal that names the property.
	 *
	 * @param string $name       The property the default belongs to.
	 * @param mixed  $expression The declared expression.
	 *
	 * @return mixed The evaluated value, possibly null.
	 *
	 * @throws ExpressionDefaultException When the expression throws.
	 */
	private function evaluate(string $name, mixed $expression): mixed {
		try {
			return $this->evaluator->evaluate($this->data, $expression);
		} catch (Throwable $failure) {
			throw new ExpressionDefaultException(
				property: $name,
				message: sprintf(
					'The default for "%s" could not be evaluated: %s. The object was not created.',
					$name,
					$failure->getMessage()
				)
			);
		}
	}//end evaluate()
}//end class
