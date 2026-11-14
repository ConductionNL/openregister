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
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Shape-checks the `graph` block and the endpoints declared beside it.
 *
 * @psalm-suppress UnusedClass
 */
final class LifecycleGraphValidator {

	use LifecycleFieldCheck;

	/**
	 * The keys a graph block may not carry, with their refusals.
	 *
	 * @var array<string, array{code: string, message: string}>
	 */
	private const UNSUPPORTED_KEYS = [
		'condition' => [
			'code' => 'lifecycle-condition-graph-unsupported',
			'message' => 'x-openregister-lifecycle.graph does not support `condition`: '
				. 'graph-mode moves are not enforced on the save path, so a condition '
				. 'declared here would not hold. Declare conditions on static transitions.',
		],
		'autoWhen' => [
			'code' => 'lifecycle-autowhen-graph-unsupported',
			'message' => 'x-openregister-lifecycle.graph does not support `autoWhen`: '
				. 'graph-mode automatic transitions are not supported while graph-mode '
				. 'moves are unenforced on the save path. Declare `autoWhen` on a static transition.',
		],
	];

	/**
	 * Constructor.
	 *
	 * The forms reader is defaulted rather than required because it is a pure
	 * shape check with no collaborators; the parameter exists so a test can
	 * substitute one.
	 *
	 * @param LifecycleDeclarationForms    $forms Owns the shape rules for `initial` and `final`.
	 * @param LifecycleGraphMovesValidator $moves Owns the shape rules for `form`, `sideMoves` and `reopen`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LifecycleDeclarationForms $forms = new LifecycleDeclarationForms(),
		private readonly LifecycleGraphMovesValidator $moves = new LifecycleGraphMovesValidator(),
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(array $annotation, array $schema): array {
		return array_merge(
			$this->declaredFieldErrors(annotation: $annotation, schema: $schema),
			$this->forms->validateEndpoints(annotation: $annotation),
			$this->validateBlock(graph: $annotation['graph']),
			$this->unsupported(graph: $annotation['graph']),
			$this->moves->validate(graph: $annotation['graph'], schema: $schema, field: self::fieldName(annotation: $annotation))
		);
	}//end validate()

	/**
	 * The declared lifecycle field, '' when it is not a string.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	private static function fieldName(array $annotation): string {
		$field = ($annotation['field'] ?? '');
		if (is_string($field) === false) {
			return '';
		}

		return $field;
	}//end fieldName()

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
				$message = sprintf('x-openregister-lifecycle.graph is missing required string key "%s".', $key);
				$errors[] = ['code' => 'lifecycle-graph-missing-key', 'message' => $message];
			}
		}

		return array_merge($errors, $this->validateAllowedMoves(allowed: ($graph['allowedMoves'] ?? null)));
	}//end validateBlock()

	/**
	 * Check `allowedMoves` against its closed set.
	 *
	 * @param mixed $allowed The declared value.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateAllowedMoves(mixed $allowed): array {
		if (in_array($allowed, ['forward', 'adjacent', 'any'], true) === true) {
			return [];
		}

		$shown = gettype($allowed);
		if (is_scalar($allowed) === true) {
			$shown = (string)$allowed;
		}

		$message = sprintf('x-openregister-lifecycle.graph.allowedMoves "%s" must be one of forward|adjacent|any.', $shown);

		return [['code' => 'lifecycle-graph-allowedmoves-invalid', 'message' => $message]];
	}//end validateAllowedMoves()

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function unsupported(array $graph): array {
		$errors = [];
		foreach (self::UNSUPPORTED_KEYS as $key => $refusal) {
			if (isset($graph[$key]) === true) {
				$errors[] = $refusal;
			}
		}

		return $errors;
	}//end unsupported()
}//end class
