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
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use Throwable;

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
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
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
	 * @param CalculationEvaluator $evaluator The JSON-AST evaluator, the same one calculations use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalculationEvaluator $evaluator,
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
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function declaresAny(array $properties): bool {
		foreach ($properties as $property) {
			if (is_array($property) === true && isset($property[self::ANNOTATION]) === true) {
				return true;
			}
		}

		return false;
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
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function apply(array $properties, array $data): array {
		$derived = [];
		foreach ($properties as $name => $property) {
			if (is_array($property) === false || isset($property[self::ANNOTATION]) === false) {
				continue;
			}

			$key = (string)$name;
			if (array_key_exists($key, $data) === true && $data[$key] !== null && $data[$key] !== '') {
				// The caller supplied a value, so the default does not apply.
				continue;
			}

			$derived[$key] = $this->evaluateOrRefuse(
				name: $key,
				expression: $property[self::ANNOTATION],
				data: $data
			);
		}

		if ($derived === []) {
			return $data;
		}

		return array_merge($data, $derived);
	}//end apply()

	/**
	 * Evaluate one expression, or refuse naming the property.
	 *
	 * @param string $name The property the default belongs to.
	 * @param mixed $expression The declared expression.
	 * @param array<string, mixed> $data The object as submitted.
	 *
	 * @return mixed The derived value, never null.
	 *
	 * @throws ExpressionDefaultException When the expression throws or yields nothing.
	 */
	private function evaluateOrRefuse(string $name, mixed $expression, array $data): mixed {
		try {
			$value = $this->evaluator->evaluate($data, $expression);
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
	}//end evaluateOrRefuse()

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
	 * @SuppressWarnings(PHPMD.StaticAccess) ConditionDialect's shape check reads one
	 *   constant table and holds no state; it is the same walk the evaluator dispatches on.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public static function validateDeclarations(array $properties): array {
		$errors = [];
		foreach ($properties as $name => $property) {
			if (is_array($property) === false || array_key_exists(self::ANNOTATION, $property) === false) {
				continue;
			}

			$expression = $property[self::ANNOTATION];
			if (is_array($expression) === false
				|| $expression === []
				|| ConditionDialect::isWellFormedAst(node: $expression) === false
			) {
				$errors[] = [
					'code' => self::CODE_MALFORMED,
					'message' => sprintf(
						'Property "%s": %s must be a JSON-AST expression such as '
						. '{"dateAdd": [{"prop": "ontvangstdatum"}, 42, "days"]}.',
						(string)$name,
						self::ANNOTATION
					),
				];
				continue;
			}

			if (array_key_exists('default', $property) === true && $property['default'] !== null) {
				$errors[] = [
					'code' => self::CODE_TWO_DEFAULTS,
					'message' => sprintf(
						'Property "%s" declares both a literal "default" and %s, and no rule says '
						. 'which wins. Keep one.',
						(string)$name,
						self::ANNOTATION
					),
				];
			}
		}//end foreach

		return $errors;
	}//end validateDeclarations()
}//end class
