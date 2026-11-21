<?php

/**
 * OpenRegister LifecycleAnnotationValidator
 *
 * Validates `x-openregister-lifecycle` schema annotations at schema-save time.
 * Returns a list of validation error messages — empty list = valid.
 *
 * Per ADR-024 (hydra#202), schemas declare state machines via this annotation;
 * the implementation is in `lifecycle-annotation` change directory.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;


/**
 * Pure validation logic for the `x-openregister-lifecycle` annotation.
 *
 * Hooked into the schema-save path in SchemaService (or its caller). Errors
 * map to HTTP 422 responses as schema-save failures.
 */
final class LifecycleAnnotationValidator implements LifecycleAnnotationValidatorInterface {

	use LifecycleFieldCheck;
	use LifecycleErrorBuilding;

	/**
	 * Validates the `states` block.
	 *
	 * @var LifecycleStateValidator
	 */
	private readonly LifecycleStateValidator $states;

	/**
	 * Owns the shape rules for `initial` and `final`.
	 *
	 * @var LifecycleDeclarationForms
	 */
	private readonly LifecycleDeclarationForms $forms;

	/**
	 * Owns the graph-mode rules.
	 *
	 * @var LifecycleGraphValidator
	 */
	private readonly LifecycleGraphValidator $graph;

	/**
	 * Owns the rules of a transition's optional keys.
	 *
	 * @var LifecycleTransitionOptionsValidator
	 */
	private readonly LifecycleTransitionOptionsValidator $options;

	/**
	 * Constructor: the part validators are pure shape checks without
	 * collaborators, so the validator builds them itself.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->states = new LifecycleStateValidator();
		$this->forms = new LifecycleDeclarationForms();
		$this->graph = new LifecycleGraphValidator();
		$this->options = new LifecycleTransitionOptionsValidator();
	}//end __construct()

	/**
	 * Validate the annotation block on a schema definition.
	 *
	 * @param array<string, mixed> $schema Full schema definition (top-level shape — must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-lifecycle']) === false) {
			return [];
		}

		$annotation = $this->normalisedAnnotation(annotation: $schema['x-openregister-lifecycle']);

		// Provider mode is checked BEFORE graph so an annotation that declares
		// both is refused by the provider-mode conflict rule, with a message
		// naming the real mistake.
		if (isset($annotation['provider']) === true) {
			$errors = $this->validateProviderMode(annotation: $annotation, schema: $schema);
			return array_merge($errors, $this->unscopedStateErrors(annotation: $annotation, schema: $schema));
		}

		// Graph mode: the lifecycle field is a `$ref` with no enum, so the graph
		// block is shape-checked instead of the static `transitions`/enum contract.
		if ($this->declaresGraph(annotation: $annotation) === true) {
			$errors = $this->graph->validate(annotation: $annotation, schema: $schema);
			return array_merge($errors, $this->unscopedStateErrors(annotation: $annotation, schema: $schema));
		}

		return $this->validateStaticMode(annotation: $annotation, schema: $schema);
	}//end validate()

	/**
	 * The state errors of an annotation whose states are not bound to an enum.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation.
	 * @param array<string, mixed> $schema     The schema shape.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function unscopedStateErrors(array $annotation, array $schema): array {
		$scope = LifecycleStateValidator::scopeOf(schema: $schema, enumSet: null);

		return $this->states->validateStates(annotation: $annotation, scope: $scope);
	}//end unscopedStateErrors()

	/**
	 * The annotation with `property`, an additive alias for `field`, folded in,
	 * so the rest of validation (and the runtime listener) sees a single key.
	 *
	 * @param mixed $annotation The raw annotation block.
	 *
	 * @return mixed The normalised block.
	 */
	private function normalisedAnnotation(mixed $annotation): mixed {
		if (isset($annotation['field']) === false && isset($annotation['property']) === true) {
			$annotation['field'] = $annotation['property'];
		}

		return $annotation;
	}//end normalisedAnnotation()

	/**
	 * Whether the annotation declares a non-empty graph block.
	 *
	 * @param mixed $annotation The normalised annotation block.
	 *
	 * @return bool
	 */
	private function declaresGraph(mixed $annotation): bool {
		return isset($annotation['graph']) === true && is_array($annotation['graph']) === true && $annotation['graph'] !== [];
	}//end declaresGraph()

	/**
	 * Validate a static-mode annotation: an enum field and a map of transitions.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param array<string, mixed> $schema     Full schema definition.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateStaticMode(array $annotation, array $schema): array {
		$errors = $this->missingRequiredKeys(annotation: $annotation);
		if ($errors !== []) {
			return $errors;
		}

		$field = $this->validateEnumField(field: (string)$annotation['field'], schema: $schema);
		if ($field['enumSet'] === null) {
			return $field['errors'];
		}

		$scope = ['schema' => $schema, 'enumSet' => $field['enumSet']];

		return array_merge($field['errors'], $this->validateAgainstEnum(annotation: $annotation, scope: $scope));
	}//end validateStaticMode()

	/**
	 * Check everything a static-mode annotation declares against the enum.
	 *
	 * @param array<string, mixed>                                               $annotation The normalised annotation block.
	 * @param array{schema: array<string, mixed>, enumSet: array<string, int>} $scope      The schema and the enum as a set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateAgainstEnum(array $annotation, array $scope): array {
		return array_merge(
			$this->validateInitialAndFinal(annotation: $annotation, enumSet: $scope['enumSet']),
			$this->validateCreateStates(createStates: ($annotation['createStates'] ?? []), enumSet: $scope['enumSet']),
			$this->validateTransitionsAndStates(annotation: $annotation, scope: $scope)
		);
	}//end validateAgainstEnum()

	/**
	 * Check the transitions, then the per-state rules.
	 *
	 * Per-state rules come last, so a malformed transition is reported as a
	 * transition problem, and the enum the states are checked against is known.
	 *
	 * @param array<string, mixed>                                               $annotation The normalised annotation block.
	 * @param array{schema: array<string, mixed>, enumSet: array<string, int>} $scope      The schema and the enum as a set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateTransitionsAndStates(array $annotation, array $scope): array {
		$transitions = $annotation['transitions'];
		if (is_array($transitions) === false || count($transitions) === 0) {
			return [$this->error(code: 'lifecycle-transitions-empty', message: 'x-openregister-lifecycle.transitions must declare at least one action.')];
		}

		return array_merge(
			$this->validateTransitions(transitions: $transitions, enumSet: $scope['enumSet']),
			$this->states->validateStates(
				annotation: $annotation,
				scope: LifecycleStateValidator::scopeOf(schema: $scope['schema'], enumSet: $scope['enumSet'])
			),
			$this->states->validateInputsAgainstHiddenFields(annotation: $annotation, transitions: $transitions)
		);
	}//end validateTransitionsAndStates()

	/**
	 * Validate every transition.
	 *
	 * @param array<mixed, mixed> $transitions The declared transitions.
	 * @param array<string, int>  $enumSet     The enum values as a set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateTransitions(array $transitions, array $enumSet): array {
		$errors = [];
		foreach ($transitions as $action => $spec) {
			$context = ['action' => (string)$action, 'enumSet' => $enumSet];
			$errors = array_merge($errors, $this->validateTransition(spec: $spec, context: $context));
		}

		return $errors;
	}//end validateTransitions()

	/**
	 * The errors for each required static-mode key the annotation lacks.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 *
	 * @return array<int, array{code: string, message: string}> One error per missing key.
	 */
	private function missingRequiredKeys(array $annotation): array {
		$errors = [];
		foreach (['field', 'initial', 'transitions'] as $required) {
			if (isset($annotation[$required]) === false) {
				$errors[] = [
					'code' => 'lifecycle-missing-key',
					'message' => sprintf('x-openregister-lifecycle is missing required key "%s".', $required),
				];
			}
		}

		return $errors;
	}//end missingRequiredKeys()

	/**
	 * Check the lifecycle field: declared, of type string, with an enum.
	 *
	 * @param string               $field  The lifecycle field name.
	 * @param array<string, mixed> $schema Full schema definition.
	 *
	 * @return array{errors: array<int, array{code: string, message: string}>, enumSet: array<string, int>|null}
	 *     The errors, and the enum as a set; a null set means validation cannot go on.
	 */
	private function validateEnumField(string $field, array $schema): array {
		$properties = ($schema['properties'] ?? []);
		if (isset($properties[$field]) === false) {
			$message = sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', $field);
			return ['errors' => [$this->error(code: 'lifecycle-field-missing', message: $message)], 'enumSet' => null];
		}

		$errors = $this->fieldTypeErrors(field: $field, definition: $properties[$field]);
		$enum = ($properties[$field]['enum'] ?? null);
		if (is_array($enum) === false || count($enum) === 0) {
			$message = sprintf('x-openregister-lifecycle.field "%s" must declare an `enum` of allowed values.', $field);
			return ['errors' => array_merge($errors, [$this->error(code: 'lifecycle-field-no-enum', message: $message)]), 'enumSet' => null];
		}

		return ['errors' => $errors, 'enumSet' => array_flip($enum)];
	}//end validateEnumField()

	/**
	 * Check that the lifecycle field is of type string.
	 *
	 * @param string $field      The lifecycle field name.
	 * @param mixed  $definition The field's definition.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function fieldTypeErrors(string $field, mixed $definition): array {
		if (($definition['type'] ?? null) === 'string') {
			return [];
		}

		$message = sprintf('x-openregister-lifecycle.field "%s" must be type "string".', $field);

		return [$this->error(code: 'lifecycle-field-not-string', message: $message)];
	}//end fieldTypeErrors()

	/**
	 * Check that `initial` and the `final` values are states of the enum.
	 *
	 * `final` in the reference form `{ from, field }` names rows in another
	 * schema, so it is shape-checked but not enum-checked.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param array<string, int>   $enumSet    The enum values as a set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateInitialAndFinal(array $annotation, array $enumSet): array {
		$errors = [];
		$initial = (string)$annotation['initial'];
		if (isset($enumSet[$initial]) === false) {
			$message = sprintf('x-openregister-lifecycle.initial "%s" is not in the field\'s enum.', $initial);
			$errors[] = $this->error(code: 'lifecycle-initial-not-in-enum', message: $message);
		}

		return array_merge($errors, $this->validateFinal(final: ($annotation['final'] ?? []), enumSet: $enumSet));
	}//end validateInitialAndFinal()

	/**
	 * Check `final`: its form, then, for a list of states, each state against the enum.
	 *
	 * @param mixed              $final   The declared value.
	 * @param array<string, int> $enumSet The enum values as a set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateFinal(mixed $final, array $enumSet): array {
		$finalError = $this->forms->validateForm(final: $final);
		if ($finalError !== null) {
			return [$finalError];
		}

		if ($this->forms->isReferenceForm(final: $final) === true || is_array($final) === false) {
			return [];
		}

		return $this->finalStateErrors(final: $final, enumSet: $enumSet);
	}//end validateFinal()

	/**
	 * Check each listed final state against the enum.
	 *
	 * @param array<mixed>       $final   The listed states.
	 * @param array<string, int> $enumSet The enum values as a set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function finalStateErrors(array $final, array $enumSet): array {
		$errors = [];
		foreach ($final as $finalState) {
			if (isset($enumSet[(string)$finalState]) === false) {
				$message = sprintf('x-openregister-lifecycle.final value "%s" is not in the field\'s enum.', $finalState);
				$errors[] = $this->error(code: 'lifecycle-final-not-in-enum', message: $message);
			}
		}

		return $errors;
	}//end finalStateErrors()

	/**
	 * Check that `createStates`, the states a create may start in besides `initial`, is a list of enum states.
	 *
	 * @param mixed              $createStates The declared value.
	 * @param array<string, int> $enumSet      The enum values as a set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-create-starts-in-the-declared-initial-state
	 */
	private function validateCreateStates(mixed $createStates, array $enumSet): array {
		if (is_array($createStates) === false || array_is_list($createStates) === false) {
			return [$this->error(code: 'lifecycle-create-states-malformed', message: 'x-openregister-lifecycle.createStates must be a list of states.')];
		}

		$errors = [];
		foreach ($createStates as $state) {
			if ($this->isEnumState(state: $state, enumSet: $enumSet) === false) {
				$message = sprintf('x-openregister-lifecycle.createStates value "%s" is not in the field\'s enum.', json_encode($state));
				$errors[] = $this->error(code: 'lifecycle-create-state-not-in-enum', message: $message);
			}
		}

		return $errors;
	}//end validateCreateStates()

	/**
	 * Whether a value is a string state of the enum.
	 *
	 * @param mixed              $state   The value.
	 * @param array<string, int> $enumSet The enum values as a set.
	 *
	 * @return bool
	 */
	private function isEnumState(mixed $state, array $enumSet): bool {
		return is_string($state) === true && isset($enumSet[$state]) === true;
	}//end isEnumState()

	/**
	 * Validate one static-mode transition.
	 *
	 * @param mixed                $spec    The transition declaration.
	 * @param array<string, mixed> $context The action name and the enum set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateTransition(mixed $spec, array $context): array {
		if (is_array($spec) === false) {
			$message = sprintf('Transition "%s" must be an object with `from` and `to`.', $context['action']);
			return [$this->error(code: 'lifecycle-transition-malformed', message: $message)];
		}

		return array_merge(
			$this->validateTransitionFrom(spec: $spec, context: $context),
			$this->validateTransitionTo(spec: $spec, context: $context),
			$this->options->validate(spec: $spec, action: $context['action'])
		);
	}//end validateTransition()

	/**
	 * Check a transition's `from`: a state or a non-empty list of states, all in the enum.
	 *
	 * @param array<string, mixed> $spec    The transition declaration.
	 * @param array<string, mixed> $context The action name and the enum set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateTransitionFrom(array $spec, array $context): array {
		$from = $this->fromStates(from: ($spec['from'] ?? null));
		if ($from === []) {
			$message = sprintf('Transition "%s" must declare a non-empty `from` array.', $context['action']);
			return [$this->error(code: 'lifecycle-from-missing', message: $message)];
		}

		$errors = [];
		foreach ($from as $fromState) {
			if (isset($context['enumSet'][(string)$fromState]) === false) {
				$template = 'Transition "%s" lists "from" state "%s" which is not in the field\'s enum.';
				$errors[] = $this->error(code: 'lifecycle-from-not-in-enum', message: sprintf($template, $context['action'], (string)$fromState));
			}
		}

		return $errors;
	}//end validateTransitionFrom()

	/**
	 * A transition's `from` as a list of states.
	 *
	 * @param mixed $from The declared value.
	 *
	 * @return array<mixed> The states; none when the value is not a state or a list.
	 */
	private function fromStates(mixed $from): array {
		if (is_string($from) === true && $from !== '') {
			return [$from];
		}

		if (is_array($from) === true) {
			return $from;
		}

		return [];
	}//end fromStates()

	/**
	 * Check a transition's `to`: a non-empty string in the enum.
	 *
	 * @param array<string, mixed> $spec    The transition declaration.
	 * @param array<string, mixed> $context The action name and the enum set.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateTransitionTo(array $spec, array $context): array {
		$to = ($spec['to'] ?? null);
		if (is_string($to) === false || $to === '') {
			$message = sprintf('Transition "%s" must declare a string `to` value.', $context['action']);
			return [$this->error(code: 'lifecycle-to-missing', message: $message)];
		}

		if (isset($context['enumSet'][$to]) === false) {
			$message = sprintf('Transition "%s" `to` value "%s" is not in the field\'s enum.', $context['action'], $to);
			return [$this->error(code: 'lifecycle-to-not-in-enum', message: $message)];
		}

		return [];
	}//end validateTransitionTo()

	/**
	 * Validate a provider-mode annotation.
	 *
	 * Provider mode delegates the whole state machine to an app service, so
	 * the schema cannot be asked what the states are: the enum requirement is
	 * relaxed exactly as it is for graph mode, where the field is a `$ref`.
	 * What IS checked is that the delegation can be performed at all, because
	 * a provider that resolves to nothing fails at render time, on a GET, in
	 * front of a user.
	 *
	 * Declaring `transitions` or `graph` beside `provider` is REFUSED rather
	 * than resolved by precedence. The engine does have a precedence order,
	 * but an author who wrote two modes on one field meant one of them, and
	 * the one the engine drops would silently never run. That is the same
	 * mistake the graph `condition` refusal already guards against: a rule
	 * that reads as enforced and is not.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param array<string, mixed> $schema Full schema definition.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateProviderMode(array $annotation, array $schema): array {
		return array_merge(
			$this->validateProviderName(provider: ($annotation['provider'] ?? null)),
			$this->declaredFieldErrors(annotation: $annotation, schema: $schema),
			$this->forms->validateEndpoints(annotation: $annotation),
			$this->validateProviderModeConflicts(annotation: $annotation)
		);
	}//end validateProviderMode()

	/**
	 * Check that `provider` can name something resolvable: a non-empty string, a DI tag or an FQCN.
	 *
	 * @param mixed $provider The declared value.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateProviderName(mixed $provider): array {
		if (is_string($provider) === true && trim($provider) !== '') {
			return [];
		}

		$message = 'x-openregister-lifecycle.provider must be a non-empty string naming a '
			. 'registered LifecycleActionProviderInterface service.';

		return [$this->error(code: 'lifecycle-provider-invalid', message: $message)];
	}//end validateProviderName()

	/**
	 * Refuse a second lifecycle mode declared beside `provider`.
	 *
	 * An empty `transitions: {}` or `graph: {}` declares no second mode, so it
	 * is not a conflict; anything else is. See {@see validateProviderMode()}
	 * for why this refuses rather than leaning on the engine's precedence.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateProviderModeConflicts(array $annotation): array {
		$template = 'x-openregister-lifecycle declares both `provider` and `%s`. A field has one '
			. 'lifecycle mode: declare the transitions in the schema or in the provider, not both.';

		$errors = [];
		foreach (['transitions', 'graph'] as $rival) {
			if (isset($annotation[$rival]) === true && $annotation[$rival] !== []) {
				$errors[] = $this->error(code: 'lifecycle-provider-mode-conflict', message: sprintf($template, $rival));
			}
		}

		return $errors;
	}//end validateProviderModeConflicts()

}//end class
