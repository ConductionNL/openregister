<?php

/**
 * The rules a graph-mode lifecycle annotation has to obey.
 *
 * Graph mode is its own mode: the lifecycle field is a `$ref` with no enum, the
 * states are rows in a sibling schema, and the moves are derived inside
 * TransitionEngine rather than listed in the schema. Its rules therefore share
 * almost nothing with the static `transitions` contract, which is why they live
 * in their own class beside {@see LifecycleStateValidator} rather than adding
 * another hundred lines to the annotation validator.
 *
 * Sibling schemas and parent objects are NOT resolved here. Existence is a
 * runtime concern; this is a shape check at save time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Shape-checks the `graph` block and the endpoints declared beside it.
 *
 * @psalm-suppress UnusedClass
 */
final class LifecycleGraphValidator {

	/**
	 * Constructor.
	 *
	 * The forms reader is defaulted rather than required because it is a pure
	 * shape check with no collaborators; the parameter exists so a test can
	 * substitute one.
	 *
	 * @param LifecycleDeclarationForms $forms Owns the shape rules for `initial` and `final`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LifecycleDeclarationForms $forms = new LifecycleDeclarationForms(),
	) {
	}//end __construct()

	/**
	 * Validate a graph-mode `x-openregister-lifecycle` annotation.
	 *
	 * Shape-checks the `graph` block (`schema`, `parentField`, `parentFrom`,
	 * `orderField`, `finalField` as non-empty strings; `allowedMoves` one of
	 * `forward`|`adjacent`|`any`), keeps `field` required but relaxes the
	 * `enum`/`type:string` constraint (a `$ref` field has no enum), and accepts
	 * either the literal-string or object-form `initial`. Sibling schemas and
	 * parent objects are NOT resolved — existence is a runtime concern.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param array<string, mixed> $schema Full schema definition.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function validate(array $annotation, array $schema): array {
		return array_merge(
			$this->validateField(annotation: $annotation, schema: $schema),
			$this->validateEndpoints(annotation: $annotation),
			$this->validateBlock(graph: $annotation['graph']),
			$this->unsupported(graph: $annotation['graph'])
		);
	}//end validate()

	/**
	 * Check the lifecycle `field` itself.
	 *
	 * It stays required and non-empty, but the `enum`/`type:string` constraint
	 * is relaxed: a graph-mode lifecycle field is a `$ref`, which declares no
	 * enum and is not a plain string in the schema's sense.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param array<string, mixed> $schema     Full schema definition.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateField(array $annotation, array $schema): array {
		$field = ($annotation['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			return [
				[
					'code' => 'lifecycle-missing-key',
					'message' => 'x-openregister-lifecycle is missing required key "field".',
				],
			];
		}

		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === true && isset($properties[$field]) === false) {
			return [
				[
					'code' => 'lifecycle-field-missing',
					'message' => sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', $field),
				],
			];
		}

		return [];
	}//end validateField()

	/**
	 * Check the `initial` and `final` declarations beside a graph block.
	 *
	 * Both are optional and both are shape-checked, never enum-checked: a
	 * graph-mode field is a `$ref` with no enum, so the reference form is the
	 * only one that can name either endpoint.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	private function validateEndpoints(array $annotation): array {
		$errors = [];

		if (isset($annotation['initial']) === true) {
			$initialError = $this->forms->validateInitialForm($annotation['initial']);
			if ($initialError !== null) {
				$errors[] = $initialError;
			}
		}

		$finalError = $this->forms->validateForm(($annotation['final'] ?? null));
		if ($finalError !== null) {
			$errors[] = $finalError;
		}

		return $errors;
	}//end validateEndpoints()

	/**
	 * Check the `graph` block's own keys.
	 *
	 * Five required non-empty strings, plus `allowedMoves` from a closed set.
	 *
	 * @param array<string, mixed> $graph The `graph` block.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateBlock(array $graph): array {
		$errors = [];

		foreach (['schema', 'parentField', 'parentFrom', 'orderField', 'finalField'] as $key) {
			$value = ($graph[$key] ?? null);
			if (is_string($value) === false || $value === '') {
				$errors[] = [
					'code' => 'lifecycle-graph-missing-key',
					'message' => sprintf('x-openregister-lifecycle.graph is missing required string key "%s".', $key),
				];
			}
		}

		$allowed = ($graph['allowedMoves'] ?? null);
		if (in_array($allowed, ['forward', 'adjacent', 'any'], true) === false) {
			$shown = gettype($allowed);
			if (is_scalar($allowed) === true) {
				$shown = (string)$allowed;
			}

			$errors[] = [
				'code' => 'lifecycle-graph-allowedmoves-invalid',
				'message' => sprintf(
					'x-openregister-lifecycle.graph.allowedMoves "%s" must be one of forward|adjacent|any.',
					$shown
				),
			];
		}

		return $errors;
	}//end validateBlock()

	/**
	 * Refuse the two keys a graph block may not carry.
	 *
	 * A `condition` on the graph block is REFUSED, not ignored and not
	 * half-enforced. Graph-mode moves are derived inside TransitionEngine; a
	 * graph annotation declares no `transitions`, so LifecycleValidation-
	 * Listener returns through its app-managed branch and the ordinary save
	 * path enforces nothing. A condition honoured on the engine route but
	 * absent on the save path would leave an author believing a state is
	 * unreachable when it is one direct write away. The silent direction of
	 * that failure is why this refuses instead. Unblocked by graph-mode
	 * enforcement on the save path (`lifecycle-graph-enforcement`).
	 *
	 * An `autoWhen` is refused for the same reason, plus one of its own: a
	 * graph block has no per-transition object in which an author could say
	 * which derived sibling to move to.
	 *
	 * @param array<string, mixed> $graph The `graph` block.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 */
	private function unsupported(array $graph): array {
		$errors = [];

		if (isset($graph['condition']) === true) {
			$errors[] = [
				'code' => 'lifecycle-condition-graph-unsupported',
				'message' => 'x-openregister-lifecycle.graph does not support `condition`: '
					. 'graph-mode moves are not enforced on the save path, so a condition '
					. 'declared here would not hold. Declare conditions on static transitions.',
			];
		}

		if (isset($graph['autoWhen']) === true) {
			$errors[] = [
				'code' => 'lifecycle-autowhen-graph-unsupported',
				'message' => 'x-openregister-lifecycle.graph does not support `autoWhen`: '
					. 'graph-mode automatic transitions are not supported while graph-mode '
					. 'moves are unenforced on the save path. Declare `autoWhen` on a static transition.',
			];
		}

		return $errors;
	}//end unsupported()
}//end class
