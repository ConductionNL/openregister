<?php

/**
 * OpenRegister ExpressionDefaultResolver
 *
 * A property default that is a JSON-AST expression rather than a literal,
 * evaluated on create in the same evaluator as a declared calculation.
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

/**
 * A default that is derived, and that refuses rather than derives nothing.
 *
 * WHY A SEPARATE KEY AND NOT `default`. JSON Schema's `default` is free-form
 * JSON: a default that is legitimately an object is legal today, and a
 * register somewhere has one. Reading a single-key object there as an
 * expression would reinterpret stored data, silently, the first time this
 * ships. `x-openregister-default-expression` cannot collide with anything
 * already written.
 *
 * WHY IT REFUSES. ADR-005. A default that cannot be evaluated and falls back
 * to an empty value is the worst of the three outcomes: the object is created,
 * the property is empty, and nothing anywhere says the derivation failed. Six
 * months later the register holds a column of nulls nobody can explain. So an
 * expression that throws, or that yields nothing, refuses the create and names
 * the property.
 *
 * EVERY EXPRESSION READS THE SUBMITTED OBJECT, not the values other expression
 * defaults produced in the same pass. That makes the result independent of the
 * order properties happen to be declared in, and it means there is no cycle to
 * detect. A value derived from a derived value is a calculation, which is a
 * different annotation with its own dependency graph.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ExpressionDefaultResolver {

	/**
	 * The property annotation this class reads.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-default-expression';

	/**
	 * The expression is not a well-formed JSON-AST node.
	 *
	 * @var string
	 */
	public const CODE_MALFORMED = 'default-expression-malformed';

	/**
	 * A literal `default` is declared beside the expression.
	 *
	 * @var string
	 */
	public const CODE_TWO_DEFAULTS = 'default-expression-and-literal';

	/**
	 * The expression could not be evaluated for this object.
	 *
	 * @var string
	 */
	public const CODE_UNEVALUABLE = 'default-expression-unevaluable';

	/**
	 * Constructor.
	 *
	 * @param CalculationEvaluatorInterface $evaluator The JSON-AST evaluator, the same one calculations use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalculationEvaluatorInterface $evaluator,
	) {
	}//end __construct()

	/**
	 * Whether any property declares an expression default.
	 *
	 * Asked first on the create path, so a schema declaring none costs one
	 * array scan and no evaluation (task 5.3).
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 *
	 * @return bool True when at least one property declares one.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function declaresAny(array $properties): bool {
		return (new AnnotatedProperties())->declares(properties: $properties, annotation: self::ANNOTATION);
	}//end declaresAny()

	/**
	 * Apply every expression default the object did not supply a value for.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 * @param array<string, mixed> $data The object being created.
	 *
	 * @return array<string, mixed> The object with the derived values written onto it.
	 *
	 * @throws ExpressionDefaultException When an expression cannot be evaluated.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function apply(array $properties, array $data): array {
		$evaluation = new ExpressionDefaultEvaluation(evaluator: $this->evaluator, data: $data);
		$derived = [];
		foreach ((new AnnotatedProperties())->annotations(properties: $properties, annotation: self::ANNOTATION) as $name => $expression) {
			$key = (string)$name;
			// A value the caller supplied means the default does not apply.
			if ($this->isSupplied(data: $data, key: $key) === false) {
				$derived[$key] = $evaluation->valueOf(name: $key, expression: $expression);
			}
		}

		if ($derived === []) {
			return $data;
		}

		return array_merge($data, $derived);
	}//end apply()

	/**
	 * Whether the object being created carries its own value for a property.
	 *
	 * @param array<string, mixed> $data The object being created.
	 * @param string               $key  The property.
	 *
	 * @return bool True when a non-empty value was supplied.
	 */
	private function isSupplied(array $data, string $key): bool {
		return array_key_exists($key, $data) === true && $data[$key] !== null && $data[$key] !== '';
	}//end isSupplied()

	/**
	 * Refuse a declaration the engine could never run, at schema save.
	 *
	 * Static because the shape check reads one constant operator table and
	 * needs no evaluator, and because the schema save has no container to
	 * build one from.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 *
	 * @return array<int, array{code: string, message: string}> The errors, empty when sound.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function validateDeclarations(array $properties): array {
		return (new ExpressionDefaultDeclarations())->errors(properties: $properties);
	}//end validateDeclarations()
}//end class
