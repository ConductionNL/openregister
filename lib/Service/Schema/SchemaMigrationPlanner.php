<?php

/**
 * SchemaMigrationPlanner — pure transform engine for object migration plans.
 *
 * Applies an ordered list of declarative transforms to a single object's
 * data array, in memory, with no database or framework dependencies. The
 * supported transforms are `rename`, `setDefault`, `cast`, `drop` and
 * `compute`; `compute` delegates template rendering to an injected
 * callable (defaulting to a minimal `{{ field }}` substitution) so the
 * planner can be unit tested without the Twig/MappingService stack while
 * the production wiring injects the real templating engine.
 *
 * The planner is the substrate for both preview (apply to a sample, never
 * persist) and execution (apply per object, then persist through the save
 * pipeline). It also validates a plan's structure so malformed transforms
 * are rejected before any object is touched — the first line of the
 * no-data-loss guard.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use RuntimeException;

/**
 * Pure transform engine for declarative migration plans.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
class SchemaMigrationPlanner {

	/**
	 * The set of recognised transform operations.
	 *
	 * @var array<int, string>
	 */
	public const TRANSFORMS = [
		'rename',
		'setDefault',
		'cast',
		'drop',
		'compute',
	];

	/**
	 * What each op requires: keys that must be non-empty, keys that must be
	 * present (any value), and the problem reported otherwise.
	 *
	 * @var array<string, array{filled: string[], present: string[], problem: string}>
	 */
	private const REQUIREMENTS = [
		'rename' => ['filled' => ['from', 'to'], 'present' => [], 'problem' => 'rename transform #%d requires "from" and "to".'],
		'setDefault' => ['filled' => ['field'], 'present' => ['value'], 'problem' => 'setDefault transform #%d requires "field" and "value".'],
		'cast' => ['filled' => ['field', 'to'], 'present' => [], 'problem' => 'cast transform #%d requires "field" and "to".'],
		'drop' => ['filled' => ['field'], 'present' => [], 'problem' => 'drop transform #%d requires "field".'],
		'compute' => ['filled' => ['field'], 'present' => ['template'], 'problem' => 'compute transform #%d requires "field" and "template".'],
	];

	/**
	 * Optional template renderer for the `compute` transform.
	 *
	 * Signature: fn(string $template, array<string,mixed> $context): string.
	 * When null a minimal `{{ field }}` substitution is used.
	 *
	 * @var callable|null
	 */
	private $templateRenderer;

	/**
	 * Caster for the `cast` transform.
	 *
	 * @var MigrationValueCaster
	 */
	private readonly MigrationValueCaster $caster;

	/**
	 * Constructor.
	 *
	 * @param callable|null $templateRenderer Optional template renderer for
	 *                                        the `compute` transform.
	 */
	public function __construct(?callable $templateRenderer = null) {
		$this->templateRenderer = $templateRenderer;
		$this->caster = new MigrationValueCaster();
	}//end __construct()

	/**
	 * Validate a migration plan's structure.
	 *
	 * Returns a list of human-readable problems; an empty list means the
	 * plan is structurally valid. Unknown transforms or missing required
	 * fields are reported so the caller can refuse the plan (HTTP 422)
	 * before any object is loaded.
	 *
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 *
	 * @return array<int, string> The list of validation problems (empty when valid).
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function validatePlan(array $plan): array {
		if (count($plan) === 0) {
			return ['Migration plan must contain at least one transform.'];
		}

		$problems = [];
		foreach ($plan as $index => $step) {
			$problem = $this->stepProblem(index: $index, step: $step);
			if ($problem !== null) {
				$problems[] = $problem;
			}
		}

		return $problems;
	}//end validatePlan()

	/**
	 * Apply a transform chain to one object's data.
	 *
	 * The input data is never mutated; a transformed copy is returned in
	 * the result. An uncastable value (or any transform error, an unknown
	 * op included) yields a failed result with the original data preserved,
	 * so a failure can never corrupt or partially write an object.
	 *
	 * @param array<string, mixed>             $data The object's current data.
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 *
	 * @return MigrationPlanResult The transform outcome.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function apply(array $data, array $plan): MigrationPlanResult {
		$working = $data;
		$applied = [];
		foreach ($plan as $step) {
			try {
				$outcome = $this->applyStep(data: $working, step: $step);
			} catch (\Throwable $e) {
				return new MigrationPlanResult($data, false, $e->getMessage(), $applied);
			}
			$working = $outcome['data'];
			$applied[] = $outcome['applied'];
		}

		return new MigrationPlanResult($working, ($working !== $data), null, $applied);
	}//end apply()

	/**
	 * The problem with one plan step, if any.
	 *
	 * @param int|string $index The step's index in the plan.
	 * @param mixed      $step  The step.
	 *
	 * @return string|null The problem, or null when the step is valid.
	 */
	private function stepProblem(int|string $index, mixed $step): ?string {
		if (is_array($step) === false || isset($step['op']) === false) {
			return sprintf('Transform #%d is missing an "op" key.', $index);
		}

		if (in_array($step['op'], self::TRANSFORMS, true) === false) {
			return sprintf('Transform #%d has unknown op "%s".', $index, (string)$step['op']);
		}

		return $this->requirementProblem(index: $index, step: $step);
	}//end stepProblem()

	/**
	 * The problem with a known op's required keys or cast target, if any.
	 *
	 * @param int|string           $index The step's index in the plan.
	 * @param array<string, mixed> $step  The step, with a known op.
	 *
	 * @return string|null The problem, or null when the step is valid.
	 */
	private function requirementProblem(int|string $index, array $step): ?string {
		$requirement = self::REQUIREMENTS[$step['op']];
		if ($this->meetsRequirement(step: $step, requirement: $requirement) === false) {
			return sprintf($requirement['problem'], $index);
		}

		if ($step['op'] === 'cast' && in_array($step['to'], MigrationValueCaster::TYPES, true) === false) {
			return sprintf('cast transform #%d has unsupported target type "%s".', $index, (string)$step['to']);
		}

		return null;
	}//end requirementProblem()

	/**
	 * Whether a step carries the keys its op requires.
	 *
	 * @param array<string, mixed>                                      $step        The step.
	 * @param array{filled: string[], present: string[], problem: string} $requirement The op's requirement.
	 *
	 * @return bool True when every required key is there.
	 */
	private function meetsRequirement(array $step, array $requirement): bool {
		foreach ($requirement['filled'] as $key) {
			if (empty($step[$key]) === true) {
				return false;
			}
		}

		foreach ($requirement['present'] as $key) {
			if (array_key_exists($key, $step) === false) {
				return false;
			}
		}

		return true;
	}//end meetsRequirement()

	/**
	 * Apply one step.
	 *
	 * @param array<string, mixed> $data The data so far.
	 * @param mixed                $step The step.
	 *
	 * @return array{data: array<string, mixed>, applied: string} The new data and the step's description.
	 *
	 * @throws RuntimeException When the op is unknown or the step fails.
	 */
	private function applyStep(array $data, mixed $step): array {
		$op = $this->stepOp(step: $step);
		if ($op === 'rename') {
			return $this->renameStep(data: $data, step: $step);
		}

		if ($op === 'setDefault') {
			return $this->setDefaultStep(data: $data, step: $step);
		}

		return $this->castDropOrComputeStep(data: $data, step: $step, op: $op);
	}//end applyStep()

	/**
	 * Apply a cast, drop or compute step; any other op is unknown.
	 *
	 * @param array<string, mixed> $data The data so far.
	 * @param mixed                $step The step.
	 * @param mixed                $op   The step's op.
	 *
	 * @return array{data: array<string, mixed>, applied: string} The new data and the step's description.
	 *
	 * @throws RuntimeException When the op is unknown or the value is uncastable.
	 */
	private function castDropOrComputeStep(array $data, mixed $step, mixed $op): array {
		if ($op === 'cast') {
			return $this->castStep(data: $data, step: $step);
		}

		return $this->dropOrComputeStep(data: $data, step: $step, op: $op);
	}//end castDropOrComputeStep()

	/**
	 * Apply a drop or compute step; any other op is unknown.
	 *
	 * @param array<string, mixed> $data The data so far.
	 * @param mixed                $step The step.
	 * @param mixed                $op   The step's op.
	 *
	 * @return array{data: array<string, mixed>, applied: string} The new data and the step's description.
	 *
	 * @throws RuntimeException When the op is unknown.
	 */
	private function dropOrComputeStep(array $data, mixed $step, mixed $op): array {
		if ($op === 'drop') {
			return $this->dropStep(data: $data, step: $step);
		}

		if ($op === 'compute') {
			return $this->computeStep(data: $data, step: $step);
		}

		throw new RuntimeException(sprintf('Unknown transform op "%s".', (string)$op));
	}//end dropOrComputeStep()

	/**
	 * The op a step asks for.
	 *
	 * A boolean `true` op selects `rename`: the original switch compared
	 * loosely and `true` equals the first case. Kept as-is.
	 *
	 * @param mixed $step The step.
	 *
	 * @return mixed The op.
	 */
	private function stepOp(mixed $step): mixed {
		$op = ($step['op'] ?? '');
		if ($op === true) {
			return 'rename';
		}

		return $op;
	}//end stepOp()

	/**
	 * Apply a rename step.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return array{data: array<string, mixed>, applied: string}
	 */
	private function renameStep(array $data, array $step): array {
		$data = $this->applyRename(data: $data, from: (string)$step['from'], to: (string)$step['to']);
		return ['data' => $data, 'applied' => sprintf('rename %s -> %s', $step['from'], $step['to'])];
	}//end renameStep()

	/**
	 * Apply a setDefault step.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return array{data: array<string, mixed>, applied: string}
	 */
	private function setDefaultStep(array $data, array $step): array {
		$data = $this->applySetDefault(data: $data, field: (string)$step['field'], value: $step['value']);
		return ['data' => $data, 'applied' => sprintf('setDefault %s', $step['field'])];
	}//end setDefaultStep()

	/**
	 * Apply a cast step.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return array{data: array<string, mixed>, applied: string}
	 *
	 * @throws RuntimeException When the value is uncastable.
	 */
	private function castStep(array $data, array $step): array {
		$data = $this->applyCast(data: $data, field: (string)$step['field'], toType: (string)$step['to'], format: ($step['format'] ?? null));
		return ['data' => $data, 'applied' => sprintf('cast %s -> %s', $step['field'], $step['to'])];
	}//end castStep()

	/**
	 * Apply a drop step.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return array{data: array<string, mixed>, applied: string}
	 */
	private function dropStep(array $data, array $step): array {
		unset($data[(string)$step['field']]);
		return ['data' => $data, 'applied' => sprintf('drop %s', $step['field'])];
	}//end dropStep()

	/**
	 * Apply a compute step.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return array{data: array<string, mixed>, applied: string}
	 */
	private function computeStep(array $data, array $step): array {
		$data = $this->applyCompute(data: $data, field: (string)$step['field'], template: (string)$step['template']);
		return ['data' => $data, 'applied' => sprintf('compute %s', $step['field'])];
	}//end computeStep()

	/**
	 * Apply a rename transform.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $from Source property.
	 * @param string               $to   Target property.
	 *
	 * @return array<string, mixed> The transformed data.
	 */
	private function applyRename(array $data, string $from, string $to): array {
		if (array_key_exists($from, $data) === false) {
			return $data;
		}

		$data[$to] = $data[$from];
		unset($data[$from]);

		return $data;
	}//end applyRename()

	/**
	 * Apply a setDefault transform (only when missing or null).
	 *
	 * @param array<string, mixed> $data  The data.
	 * @param string               $field Target property.
	 * @param mixed                $value The default value.
	 *
	 * @return array<string, mixed> The transformed data.
	 */
	private function applySetDefault(array $data, string $field, mixed $value): array {
		if (array_key_exists($field, $data) === false || $data[$field] === null) {
			$data[$field] = $value;
		}

		return $data;
	}//end applySetDefault()

	/**
	 * Apply a cast transform; a missing or null value is left as-is.
	 *
	 * @param array<string, mixed> $data   The data.
	 * @param string               $field  Target property.
	 * @param string               $toType Target type.
	 * @param string|null          $format Optional date format.
	 *
	 * @return array<string, mixed> The transformed data.
	 *
	 * @throws RuntimeException When the value is uncastable.
	 */
	private function applyCast(array $data, string $field, string $toType, ?string $format): array {
		if (array_key_exists($field, $data) === false || $data[$field] === null) {
			return $data;
		}

		$data[$field] = $this->caster->cast(value: $data[$field], toType: $toType, format: $format);
		return $data;
	}//end applyCast()

	/**
	 * Apply a compute transform via the template renderer.
	 *
	 * @param array<string, mixed> $data     The data.
	 * @param string               $field    Target property.
	 * @param string               $template The template string.
	 *
	 * @return array<string, mixed> The transformed data.
	 */
	private function applyCompute(array $data, string $field, string $template): array {
		if ($this->templateRenderer !== null) {
			$data[$field] = ($this->templateRenderer)($template, $data);
			return $data;
		}

		$data[$field] = $this->renderSimpleTemplate(template: $template, context: $data);
		return $data;
	}//end applyCompute()

	/**
	 * Render a minimal `{{ field }}` template against the object's own data.
	 *
	 * Used as the default `compute` renderer so the planner is testable
	 * without the Twig stack. Production wiring injects the MappingService
	 * renderer for full template parity.
	 *
	 * @param string               $template The template.
	 * @param array<string, mixed> $context  The object's data.
	 *
	 * @return string The rendered string.
	 */
	private function renderSimpleTemplate(string $template, array $context): string {
		return preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
			fn (array $matches): string => $this->templateValue(context: $context, key: $matches[1]),
			$template
		);
	}//end renderSimpleTemplate()

	/**
	 * The text a template placeholder renders: a scalar value as a string,
	 * anything else (missing, null, array) as ''.
	 *
	 * @param array<string, mixed> $context The object's data.
	 * @param string               $key     The placeholder's field name.
	 *
	 * @return string The rendered text.
	 */
	private function templateValue(array $context, string $key): string {
		$value = ($context[$key] ?? null);
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}//end templateValue()

}//end class
