<?php

/**
 * OpenRegister CalculationAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-calculations` annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
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

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Validates `x-openregister-calculations` annotation shape and references.
 *
 * Checks (per calculation):
 * - Spec is an object with `type` (string|integer|number|boolean|date) and `expression`.
 * - Optional `materialise` is a boolean.
 * - Every `prop` reference points to a property on the schema OR another
 *   calculation declared in this annotation.
 * - Every operator key is in the v1 vocabulary.
 *
 * Cross-calculation:
 * - Cycle detection across {prop:calcA, prop:calcB} dependency graph.
 * - `x-openregister-references` shape validation + `@ref.<name>` token recognition.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The validator mirrors the evaluator's
 *   operator vocabulary plus three reference families (@ref / @self / plain) plus the
 *   references-block shape checks; each branch is a distinct validation rule that cannot be
 *   collapsed without losing a specific, user-facing error message. Splitting into sub-validators
 *   would require a rule registry that is out of scope for this single-responsibility class.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
 */
final class CalculationAnnotationValidator implements CalculationAnnotationValidatorInterface {
	use PropNameReading;

	/**
	 * Supported unit values for the dateDiff operator.
	 */
	private const VALID_DATE_DIFF_UNITS = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds'];

	/**
	 * Operator vocabulary recognised by the calculation evaluator.
	 *
	 * Read from the published catalogue rather than restated here, so a schema
	 * can never be refused for an operator the catalogue offers an author. The
	 * catalogue in turn sits beside the evaluator's dispatch.
	 */
	private const VALID_OPS = CalculationEvaluator::OPERATORS;

	/**
	 * Allowed `metric` values for an aggregate-reference declaration.
	 */
	private const VALID_AGGREGATE_METRICS = ['count', 'sum', 'avg', 'min', 'max'];

	/**
	 * Allowed `type` values for a calculation declaration.
	 */
	private const VALID_TYPES = ['string', 'integer', 'number', 'boolean', 'date'];

	/**
	 * System fields an `@self.<field>` reference may read.
	 */
	private const SELF_FIELDS = ['id', 'uuid', 'register', 'schema', 'owner', 'created', 'updated'];

	/**
	 * Validate a schema's `x-openregister-calculations` annotation.
	 *
	 * @param array<string, mixed> $schema Full schema (must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}> Validation error list.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-calculations']) === false) {
			return [];
		}

		$calcs = $schema['x-openregister-calculations'];
		if (is_array($calcs) === false || count($calcs) === 0) {
			return [
				[
					'code' => 'calculations-empty',
					'message' => 'x-openregister-calculations must declare at least one calculation.',
				],
			];
		}

		return $this->validateDeclared(schema: $schema, calcs: $calcs);
	}//end validate()

	/**
	 * Validate the references, the calculations and their dependency graph.
	 *
	 * @param array<string, mixed> $schema Full schema.
	 * @param array<mixed>         $calcs  The declared calculations, not empty.
	 *
	 * @return array<int, array{code: string, message: string}> Validation error list.
	 */
	private function validateDeclared(array $schema, array $calcs): array {
		$walk = new CalculationWalk(
			propKeys: $this->keysOf(value: ($schema['properties'] ?? [])),
			referenceKeys: $this->keysOf(value: ($schema['x-openregister-references'] ?? [])),
			aggregateRefKeys: $this->keysOf(value: ($schema['x-openregister-aggregate-refs'] ?? []))
		);
		$walk->allRefs = array_merge($walk->propKeys, array_keys($calcs));
		$this->validateReferences(references: ($schema['x-openregister-references'] ?? []), walk: $walk);
		$this->validateAggregateRefs(aggregateRefs: ($schema['x-openregister-aggregate-refs'] ?? []), walk: $walk);
		$this->validateCalculations(calcs: $calcs, walk: $walk);
		$this->checkCycles(walk: $walk);

		return $walk->errors;
	}//end validateDeclared()

	/**
	 * Refuse a dependency cycle among the walked calculations.
	 *
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return void
	 */
	private function checkCycles(CalculationWalk $walk): void {
		$cycle = (new DependencyCycleFinder())->find(deps: $walk->deps);
		if ($cycle !== null) {
			$walk->fail(code: 'calculation-cycle', message: sprintf('Calculation cycle detected: %s.', implode(' -> ', $cycle)));
		}
	}//end checkCycles()

	/**
	 * Whether a map entry is a named object.
	 *
	 * @param mixed $name The entry's key.
	 * @param mixed $spec The entry's value.
	 *
	 * @return bool
	 */
	private function isNamedSpec(mixed $name, mixed $spec): bool {
		return is_string($name) === true && $name !== '' && is_array($spec) === true;
	}//end isNamedSpec()

	/**
	 * The keys of a map, or none when it is not an array.
	 *
	 * @param mixed $value The map.
	 *
	 * @return array<int, int|string>
	 */
	private function keysOf(mixed $value): array {
		if (is_array($value) === true) {
			return array_keys($value);
		}

		return [];
	}//end keysOf()

	/**
	 * Validate each calculation in declaration order.
	 *
	 * @param array<mixed>    $calcs The declared calculations.
	 * @param CalculationWalk $walk  The validation state.
	 *
	 * @return void
	 */
	private function validateCalculations(array $calcs, CalculationWalk $walk): void {
		foreach ($calcs as $name => $spec) {
			if (is_string($name) === false || $name === '') {
				$walk->fail(code: 'calculation-bad-name', message: 'Calculation names must be non-empty strings.');
				continue;
			}

			$walk->name = $name;
			$this->validateCalculation(spec: $spec, walk: $walk);
		}
	}//end validateCalculations()

	/**
	 * Validate one calculation: shape, type, expression and the names it reads.
	 *
	 * @param mixed           $spec The calculation spec.
	 * @param CalculationWalk $walk The validation state, named after the calculation.
	 *
	 * @return void
	 */
	private function validateCalculation(mixed $spec, CalculationWalk $walk): void {
		if (is_array($spec) === false) {
			$walk->fail(code: 'calculation-malformed', message: sprintf('Calculation "%s" must be an object.', $walk->name));
			return;
		}

		$this->checkType(spec: $spec, walk: $walk);
		if (isset($spec['expression']) === false) {
			$walk->fail(code: 'calculation-no-expression', message: sprintf('Calculation "%s" requires an expression.', $walk->name));
			return;
		}

		$this->checkDependsOn(spec: $spec, walk: $walk);
		$walk->deps[$walk->name] = [];
		$this->walk(expr: $spec['expression'], walk: $walk);
	}//end validateCalculation()

	/**
	 * Refuse a calculation type outside VALID_TYPES.
	 *
	 * @param array<string, mixed> $spec The calculation spec.
	 * @param CalculationWalk      $walk The validation state.
	 *
	 * @return void
	 */
	private function checkType(array $spec, CalculationWalk $walk): void {
		if (in_array((string)($spec['type'] ?? ''), self::VALID_TYPES, true) === true) {
			return;
		}

		$walk->fail(
			code: 'calculation-bad-type',
			message: sprintf('Calculation "%s" type must be one of [%s].', $walk->name, implode(', ', self::VALID_TYPES))
		);
	}//end checkType()

	/**
	 * Report a `dependsOn` key: the dependencies are derived from the expression.
	 *
	 * @param array<string, mixed> $spec The calculation spec.
	 * @param CalculationWalk      $walk The validation state.
	 *
	 * @return void
	 */
	private function checkDependsOn(array $spec, CalculationWalk $walk): void {
		if (array_key_exists('dependsOn', $spec) === false) {
			return;
		}

		$walk->fail(
			code: 'calculation-dependson-ignored',
			message: sprintf(
				'Calculation "%s": "dependsOn" is not read. The properties an expression '
				. 'reads are derived from the expression itself.',
				$walk->name
			)
		);
	}//end checkDependsOn()

	/**
	 * Walk an expression AST, recording errors and the names it reads.
	 *
	 * A non-array node is a literal operand and is not checked.
	 *
	 * @param mixed           $expr The expression node.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return void
	 */
	private function walk(mixed $expr, CalculationWalk $walk): void {
		$op = $this->operatorOf(expr: $expr, walk: $walk);
		if ($op === null || $op === 'lit') {
			return;
		}

		if ($op === 'prop') {
			$this->walkProp(args: $expr[$op], walk: $walk);
			return;
		}

		if ($op === 'dateDiff') {
			$this->walkDateDiff(args: $expr[$op], walk: $walk);
			return;
		}

		$this->walkArgs(args: $expr[$op], walk: $walk);
	}//end walk()

	/**
	 * The operator of a node, or null for a literal or a refused node.
	 *
	 * @param mixed           $expr The expression node.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return string|null
	 */
	private function operatorOf(mixed $expr, CalculationWalk $walk): ?string {
		if (is_array($expr) === false) {
			return null;
		}

		if (count($expr) !== 1) {
			$walk->fail(code: 'calculation-malformed-expr', message: sprintf('Calculation "%s": expression must be single-key.', $walk->name));
			return null;
		}

		$op = (string)array_key_first($expr);
		if (array_key_exists($op, self::VALID_OPS) === false) {
			$walk->fail(code: 'calculation-unknown-op', message: sprintf('Calculation "%s": unknown operator "%s".', $walk->name, $op));
			return null;
		}

		return $op;
	}//end operatorOf()

	/**
	 * Walk an operator's operands: one node, or a list of nodes.
	 *
	 * @param mixed           $args The operands.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return void
	 */
	private function walkArgs(mixed $args, CalculationWalk $walk): void {
		if (is_array($args) === false) {
			$this->walk(expr: $args, walk: $walk);
			return;
		}

		foreach ($args as $sub) {
			$this->walk(expr: $sub, walk: $walk);
		}
	}//end walkArgs()

	/**
	 * Check a `prop` reference and record a plain name as a dependency.
	 *
	 * Accepts `{prop: "name"}` and `{prop: ["name", default]}`.
	 *
	 * @param mixed           $args The `prop` operand.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return void
	 */
	private function walkProp(mixed $args, CalculationWalk $walk): void {
		$name = $this->propName(args: $args);
		if ($this->checkPrefixedName(name: $name, walk: $walk) === true) {
			return;
		}

		if ($name === '' || in_array($name, $walk->allRefs, true) === false) {
			$walk->fail(
				code: 'calculation-prop-unknown',
				message: sprintf('Calculation "%s": prop "%s" is not a property or calculation.', $walk->name, $name)
			);
			return;
		}

		$walk->dependOn(dependency: $name);
	}//end walkProp()

	/**
	 * Check an `@ref.`, `@aggregate.` or `@self.` name; false for any other name.
	 *
	 * @param string          $name The name a `prop` reads.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return bool Whether the name carried one of the prefixes.
	 */
	private function checkPrefixedName(string $name, CalculationWalk $walk): bool {
		if (str_starts_with($name, '@ref.') === true) {
			$this->checkRefName(rest: substr($name, 5), walk: $walk);
			return true;
		}

		if (str_starts_with($name, '@aggregate.') === true) {
			$this->checkAggregateName(rest: substr($name, 11), walk: $walk);
			return true;
		}

		if (str_starts_with($name, '@self.') === true) {
			$this->checkSelfName(field: substr($name, 6), walk: $walk);
			return true;
		}

		return false;
	}//end checkPrefixedName()

	/**
	 * Refuse an `@ref.<name>` whose reference is not declared.
	 *
	 * @param string          $rest The name after `@ref.`.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return void
	 */
	private function checkRefName(string $rest, CalculationWalk $walk): void {
		$refName = (explode('.', $rest)[0] ?? '');
		if (in_array($refName, $walk->referenceKeys, true) === true) {
			return;
		}

		$walk->fail(
			code: 'calculation-ref-unknown',
			message: sprintf(
				'Calculation "%s": @ref.%s is not a declared reference. Declared: %s.',
				$walk->name,
				$refName,
				implode(', ', $walk->referenceKeys)
			)
		);
	}//end checkRefName()

	/**
	 * Refuse an `@aggregate.<name>` whose aggregate-reference is not declared.
	 *
	 * @param string          $rest The name after `@aggregate.`.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return void
	 */
	private function checkAggregateName(string $rest, CalculationWalk $walk): void {
		$aggName = (explode('.', $rest)[0] ?? '');
		if (in_array($aggName, $walk->aggregateRefKeys, true) === true) {
			return;
		}

		$walk->fail(
			code: 'calculation-aggregate-unknown',
			message: sprintf(
				'Calculation "%s": @aggregate.%s is not a declared aggregate-reference. Declared: %s.',
				$walk->name,
				$aggName,
				implode(', ', $walk->aggregateRefKeys)
			)
		);
	}//end checkAggregateName()

	/**
	 * Refuse an `@self.<field>` outside SELF_FIELDS.
	 *
	 * @param string          $field The name after `@self.`.
	 * @param CalculationWalk $walk  The validation state.
	 *
	 * @return void
	 */
	private function checkSelfName(string $field, CalculationWalk $walk): void {
		if (in_array($field, self::SELF_FIELDS, true) === true) {
			return;
		}

		$walk->fail(
			code: 'calculation-self-unknown',
			message: sprintf(
				'Calculation "%s": @self.%s is not a known system field. Allowed: %s.',
				$walk->name,
				$field,
				implode(', ', self::SELF_FIELDS)
			)
		);
	}//end checkSelfName()

	/**
	 * Check a `dateDiff` operand: `from`, `to` and a supported `unit`.
	 *
	 * @param mixed           $args The `dateDiff` operand.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return void
	 */
	private function walkDateDiff(mixed $args, CalculationWalk $walk): void {
		if ($this->hasDateDiffKeys(args: $args) === false) {
			$walk->fail(
				code: 'calculation-dateDiff-missing-keys',
				message: sprintf('Calculation "%s": dateDiff requires keys: from, to, unit.', $walk->name)
			);
			return;
		}

		$this->walk(expr: $args['from'], walk: $walk);
		$this->walk(expr: $args['to'], walk: $walk);
		$this->checkDateDiffUnit(unit: $args['unit'], walk: $walk);
	}//end walkDateDiff()

	/**
	 * Whether a `dateDiff` operand is a map with `from`, `to` and `unit`.
	 *
	 * @param mixed $args The `dateDiff` operand.
	 *
	 * @return bool
	 */
	private function hasDateDiffKeys(mixed $args): bool {
		if (is_array($args) === false) {
			return false;
		}

		foreach (['from', 'to', 'unit'] as $key) {
			if (array_key_exists($key, $args) === false) {
				return false;
			}
		}

		return true;
	}//end hasDateDiffKeys()

	/**
	 * Refuse a string `dateDiff` unit outside VALID_DATE_DIFF_UNITS.
	 *
	 * @param mixed           $unit The unit.
	 * @param CalculationWalk $walk The validation state.
	 *
	 * @return void
	 */
	private function checkDateDiffUnit(mixed $unit, CalculationWalk $walk): void {
		if (is_string($unit) === false || in_array($unit, self::VALID_DATE_DIFF_UNITS, true) === true) {
			return;
		}

		$walk->fail(
			code: 'calculation-dateDiff-invalid-unit',
			message: sprintf(
				'Calculation "%s": dateDiff unit "%s" is invalid. Supported: %s.',
				$walk->name,
				$unit,
				implode(', ', self::VALID_DATE_DIFF_UNITS)
			)
		);
	}//end checkDateDiffUnit()

	/**
	 * Validate the `x-openregister-references` block.
	 *
	 * @param mixed           $references The references block.
	 * @param CalculationWalk $walk       The validation state.
	 *
	 * @return void
	 */
	private function validateReferences(mixed $references, CalculationWalk $walk): void {
		if (is_array($references) === false) {
			return;
		}

		foreach ($references as $refName => $refSpec) {
			if ($this->isNamedSpec(name: $refName, spec: $refSpec) === false) {
				$walk->fail(code: 'reference-malformed', message: sprintf('Reference "%s" must be a named object.', (string)$refName));
				continue;
			}

			$walk->name = $refName;
			$this->validateReference(spec: $refSpec, walk: $walk);
		}
	}//end validateReferences()

	/**
	 * Validate one reference: target schema, mode and the mode's own key.
	 *
	 * @param array<string, mixed> $spec The reference spec.
	 * @param CalculationWalk      $walk The validation state, named after the reference.
	 *
	 * @return void
	 */
	private function validateReference(array $spec, CalculationWalk $walk): void {
		if ((string)($spec['schema'] ?? '') === '') {
			$walk->fail(code: 'reference-no-schema', message: sprintf('Reference "%s" requires a target schema.', $walk->name));
		}

		$this->checkReferenceMode(spec: $spec, walk: $walk);
	}//end validateReference()

	/**
	 * Check a reference's mode and the mode's own key.
	 *
	 * @param array<string, mixed> $spec The reference spec.
	 * @param CalculationWalk      $walk The validation state, named after the reference.
	 *
	 * @return void
	 */
	private function checkReferenceMode(array $spec, CalculationWalk $walk): void {
		$mode = (string)($spec['mode'] ?? '');
		if ($mode === 'relatedObject') {
			$this->checkReferenceField(field: (string)($spec['field'] ?? ''), walk: $walk);
			return;
		}

		if ($mode !== 'lookup') {
			$walk->fail(code: 'reference-bad-mode', message: sprintf('Reference "%s" mode must be relatedObject or lookup.', $walk->name));
			return;
		}

		if (is_array($spec['filters'] ?? null) === false) {
			$walk->fail(code: 'reference-no-filters', message: sprintf('Reference "%s" (lookup) requires a filters map.', $walk->name));
		}
	}//end checkReferenceMode()

	/**
	 * Check the field of a relatedObject reference: present and a schema property.
	 *
	 * @param string          $field The field.
	 * @param CalculationWalk $walk  The validation state.
	 *
	 * @return void
	 */
	private function checkReferenceField(string $field, CalculationWalk $walk): void {
		if ($field === '') {
			$walk->fail(code: 'reference-no-field', message: sprintf('Reference "%s" (relatedObject) requires a field.', $walk->name));
			return;
		}

		if (in_array($field, $walk->propKeys, true) === false) {
			$walk->fail(
				code: 'reference-field-unknown',
				message: sprintf('Reference "%s": field "%s" is not a schema property.', $walk->name, $field)
			);
		}
	}//end checkReferenceField()

	/**
	 * Validate the `x-openregister-aggregate-refs` block.
	 *
	 * @param mixed           $aggregateRefs The aggregate-references block.
	 * @param CalculationWalk $walk          The validation state.
	 *
	 * @return void
	 */
	private function validateAggregateRefs(mixed $aggregateRefs, CalculationWalk $walk): void {
		if (is_array($aggregateRefs) === false) {
			return;
		}

		foreach ($aggregateRefs as $aggName => $aggSpec) {
			if ($this->isNamedSpec(name: $aggName, spec: $aggSpec) === false) {
				$message = sprintf('Aggregate-reference "%s" must be a named object.', (string)$aggName);
				$walk->fail(code: 'aggregate-ref-malformed', message: $message);
				continue;
			}

			$walk->name = $aggName;
			$this->validateAggregateRef(spec: $aggSpec, walk: $walk);
		}
	}//end validateAggregateRefs()

	/**
	 * Validate one aggregate-reference: target schema, metric and its field.
	 *
	 * @param array<string, mixed> $spec The aggregate-reference spec.
	 * @param CalculationWalk      $walk The validation state, named after the reference.
	 *
	 * @return void
	 */
	private function validateAggregateRef(array $spec, CalculationWalk $walk): void {
		if ((string)($spec['schema'] ?? '') === '') {
			$walk->fail(code: 'aggregate-ref-no-schema', message: sprintf('Aggregate-reference "%s" requires a target schema.', $walk->name));
		}

		$metric = (string)($spec['metric'] ?? '');
		if (in_array($metric, self::VALID_AGGREGATE_METRICS, true) === false) {
			$allowed = implode(', ', self::VALID_AGGREGATE_METRICS);
			$walk->fail(code: 'aggregate-ref-bad-metric', message: sprintf('Aggregate-reference "%s" metric must be one of [%s].', $walk->name, $allowed));
			return;
		}

		if ($metric !== 'count' && (string)($spec['field'] ?? '') === '') {
			$walk->fail(code: 'aggregate-ref-no-field', message: sprintf('Aggregate-reference "%s" metric "%s" requires a field.', $walk->name, $metric));
		}
	}//end validateAggregateRef()
}//end class
