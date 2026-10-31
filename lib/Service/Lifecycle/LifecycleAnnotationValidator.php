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

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Rules\ConditionDialect;

/**
 * Pure validation logic for the `x-openregister-lifecycle` annotation.
 *
 * Hooked into the schema-save path in SchemaService (or its caller). Errors
 * map to HTTP 422 responses as schema-save failures.
 */
final class LifecycleAnnotationValidator {

	/**
	 * Constructor.
	 *
	 * The state validator is defaulted rather than required so every existing
	 * `new LifecycleAnnotationValidator()` keeps working; the parameter exists
	 * so a test can substitute one.
	 *
	 * @param LifecycleStateValidator   $states Validates the `states` block.
	 * @param LifecycleDeclarationForms $forms  Owns the shape rules for `initial` and `final`.
	 * @param LifecycleGraphValidator   $graph  Owns the graph-mode rules.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LifecycleStateValidator $states = new LifecycleStateValidator(),
		private readonly LifecycleDeclarationForms $forms = new LifecycleDeclarationForms(),
		private readonly LifecycleGraphValidator $graph = new LifecycleGraphValidator(),
	) {
	}//end __construct()

	/**
	 * Validate the annotation block on a schema definition.
	 *
	 * @param array<string, mixed> $schema Full schema definition (top-level shape — must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-lifecycle']) === false) {
			return [];
		}

		$annotation = $schema['x-openregister-lifecycle'];

		// `property` is an additive alias for `field`; normalise it up-front so
		// the rest of validation (and the runtime listener) sees a single key.
		if (isset($annotation['field']) === false && isset($annotation['property']) === true) {
			$annotation['field'] = $annotation['property'];
		}

		// Provider mode is checked BEFORE graph so an annotation that declares
		// both is refused by the provider-mode conflict rule, with a message
		// naming the real mistake.
		if (isset($annotation['provider']) === true) {
			return array_merge(
				$this->validateProviderMode(annotation: $annotation, schema: $schema),
				$this->states->validateStates(annotation: $annotation, schema: $schema, enumSet: null)
			);
		}

		// Graph mode: the lifecycle field is a `$ref` with no enum, so the graph
		// block is shape-checked instead of the static `transitions`/enum contract.
		if (isset($annotation['graph']) === true
			&& is_array($annotation['graph']) === true
			&& $annotation['graph'] !== []
		) {
			return array_merge(
				$this->graph->validate(annotation: $annotation, schema: $schema),
				$this->states->validateStates(annotation: $annotation, schema: $schema, enumSet: null)
			);
		}

		return $this->validateStaticMode(annotation: $annotation, schema: $schema);
	}//end validate()

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

		$enumSet = $field['enumSet'];
		$errors = array_merge(
			$field['errors'],
			$this->validateInitialAndFinal(annotation: $annotation, enumSet: $enumSet)
		);

		$transitions = $annotation['transitions'];
		if (is_array($transitions) === false || count($transitions) === 0) {
			$errors[] = [
				'code' => 'lifecycle-transitions-empty',
				'message' => 'x-openregister-lifecycle.transitions must declare at least one action.',
			];
			return $errors;
		}

		foreach ($transitions as $action => $spec) {
			$errors = array_merge(
				$errors,
				$this->validateTransition(spec: $spec, context: ['action' => (string)$action, 'enumSet' => $enumSet])
			);
		}

		// Per-state rules come last, so a malformed transition is reported as a
		// transition problem, and the enum the states are checked against is known.
		return array_merge(
			$errors,
			$this->states->validateStates(annotation: $annotation, schema: $schema, enumSet: $enumSet),
			$this->states->validateInputsAgainstHiddenFields(annotation: $annotation, transitions: $transitions)
		);
	}//end validateStaticMode()

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
			return [
				'errors' => [[
					'code' => 'lifecycle-field-missing',
					'message' => sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', $field),
				]],
				'enumSet' => null,
			];
		}

		$errors = [];
		$fieldDef = $properties[$field];
		if (($fieldDef['type'] ?? null) !== 'string') {
			$errors[] = [
				'code' => 'lifecycle-field-not-string',
				'message' => sprintf('x-openregister-lifecycle.field "%s" must be type "string".', $field),
			];
		}

		$enum = ($fieldDef['enum'] ?? null);
		if (is_array($enum) === false || count($enum) === 0) {
			$errors[] = [
				'code' => 'lifecycle-field-no-enum',
				'message' => sprintf('x-openregister-lifecycle.field "%s" must declare an `enum` of allowed values.', $field),
			];
			return ['errors' => $errors, 'enumSet' => null];
		}

		return ['errors' => $errors, 'enumSet' => array_flip($enum)];
	}//end validateEnumField()

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
			$errors[] = [
				'code' => 'lifecycle-initial-not-in-enum',
				'message' => sprintf('x-openregister-lifecycle.initial "%s" is not in the field\'s enum.', $initial),
			];
		}

		$final = ($annotation['final'] ?? []);
		$finalError = $this->forms->validateForm($final);
		if ($finalError !== null) {
			$errors[] = $finalError;
			return $errors;
		}

		if ($this->forms->isReferenceForm($final) === true || is_array($final) === false) {
			return $errors;
		}

		foreach ($final as $finalState) {
			if (isset($enumSet[(string)$finalState]) === false) {
				$errors[] = [
					'code' => 'lifecycle-final-not-in-enum',
					'message' => sprintf('x-openregister-lifecycle.final value "%s" is not in the field\'s enum.', $finalState),
				];
			}
		}

		return $errors;
	}//end validateInitialAndFinal()

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
			return [[
				'code' => 'lifecycle-transition-malformed',
				'message' => sprintf('Transition "%s" must be an object with `from` and `to`.', $context['action']),
			]];
		}

		return array_merge(
			$this->validateTransitionFrom(spec: $spec, context: $context),
			$this->validateTransitionTo(spec: $spec, context: $context),
			$this->validateTransitionOptions(spec: $spec, action: $context['action'])
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
		$from = ($spec['from'] ?? null);
		if (is_string($from) === true && $from !== '') {
			$from = [$from];
		}

		if (is_array($from) === false || count($from) === 0) {
			return [[
				'code' => 'lifecycle-from-missing',
				'message' => sprintf('Transition "%s" must declare a non-empty `from` array.', $context['action']),
			]];
		}

		$errors = [];
		foreach ($from as $fromState) {
			if (isset($context['enumSet'][(string)$fromState]) === false) {
				$errors[] = [
					'code' => 'lifecycle-from-not-in-enum',
					'message' => sprintf(
						'Transition "%s" lists "from" state "%s" which is not in the field\'s enum.',
						$context['action'],
						(string)$fromState
					),
				];
			}
		}

		return $errors;
	}//end validateTransitionFrom()

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
			return [[
				'code' => 'lifecycle-to-missing',
				'message' => sprintf('Transition "%s" must declare a string `to` value.', $context['action']),
			]];
		}

		if (isset($context['enumSet'][$to]) === false) {
			return [[
				'code' => 'lifecycle-to-not-in-enum',
				'message' => sprintf('Transition "%s" `to` value "%s" is not in the field\'s enum.', $context['action'], $to),
			]];
		}

		return [];
	}//end validateTransitionTo()

	/**
	 * Shape-check a transition's optional keys.
	 *
	 * `requires` is a non-empty DI tag (resolved at install and first use, not
	 * here); `authorization` a group/role gate; `condition` a JSONLogic
	 * precondition; `autoWhen`/`executionMode` make the transition fire on its own
	 * (refused when malformed, as a stored broken rule fires on every write);
	 * `message` the refusal text of a declined condition.
	 *
	 * @param array<string, mixed> $spec   The transition declaration.
	 * @param string               $action The action name.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateTransitionOptions(array $spec, string $action): array {
		$errors = [];
		if (isset($spec['requires']) === true && (is_string($spec['requires']) === false || $spec['requires'] === '')) {
			$errors[] = [
				'code' => 'lifecycle-requires-malformed',
				'message' => sprintf('Transition "%s" `requires` must be a non-empty DI tag string.', $action),
			];
		}

		if (isset($spec['authorization']) === true) {
			$errors[] = $this->validateTransitionAuthorization(authorization: $spec['authorization'], action: $action);
		}

		if (isset($spec['condition']) === true) {
			$errors[] = $this->validateTransitionCondition(condition: $spec['condition'], action: $action);
		}

		$errors = array_merge(
			array_values(array_filter($errors, static fn (?array $error): bool => $error !== null)),
			$this->validateAutomaticTransition(spec: $spec, action: $action)
		);

		if (isset($spec['message']) === true) {
			$errors = array_merge($errors, $this->validateTransitionMessage(message: $spec['message'], action: $action));
		}

		return $errors;
	}//end validateTransitionOptions()


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
		$errors = [];

		// `provider` must name something resolvable: a non-empty string, a DI
		// tag or an FQCN.
		$provider = ($annotation['provider'] ?? null);
		if (is_string($provider) === false || trim($provider) === '') {
			$errors[] = [
				'code' => 'lifecycle-provider-invalid',
				'message' => 'x-openregister-lifecycle.provider must be a non-empty string naming a '
					. 'registered LifecycleActionProviderInterface service.',
			];
		}

		$errors = array_merge($errors, $this->validateProviderField(annotation: $annotation, schema: $schema));

		// `initial` (optional): accept the literal-string form or the object
		// form `{ from, field }`, as graph mode does.
		if (isset($annotation['initial']) === true) {
			$initialError = $this->forms->validateInitialForm($annotation['initial']);
			if ($initialError !== null) {
				$errors[] = $initialError;
			}
		}

		// `final` (optional): shape-checked, never enum-checked. In provider
		// mode the app owns the state vocabulary, so there is no enum to check
		// against, and the reference form is the only one that can name an end
		// when the states are rows.
		$finalError = $this->forms->validateForm(($annotation['final'] ?? null));
		if ($finalError !== null) {
			$errors[] = $finalError;
		}

		return array_merge($errors, $this->validateProviderModeConflicts(annotation: $annotation));
	}//end validateProviderMode()

	/**
	 * Check a provider-mode `field` against the schema's properties.
	 *
	 * `field` stays required and non-empty, but the `enum`/`type:string`
	 * constraint is relaxed: in provider mode the app owns the state
	 * vocabulary, so the schema has nothing to enumerate.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param array<string, mixed> $schema Full schema definition.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateProviderField(array $annotation, array $schema): array {
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
	}//end validateProviderField()

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
		$errors = [];
		foreach (['transitions', 'graph'] as $rival) {
			if (isset($annotation[$rival]) === false || $annotation[$rival] === []) {
				continue;
			}

			$errors[] = [
				'code' => 'lifecycle-provider-mode-conflict',
				'message' => sprintf(
					'x-openregister-lifecycle declares both `provider` and `%s`. A field has one '
					. 'lifecycle mode: declare the transitions in the schema or in the provider, not both.',
					$rival
				),
			];
		}

		return $errors;
	}//end validateProviderModeConflicts()

	/**
	 * Shape-check a transition's optional `authorization` list.
	 *
	 * Valid: a non-empty array whose entries are either non-empty NC group id
	 * strings or `{ "role": "<non-empty string>" }` objects. Returns a single
	 * structured error on the first violation, or null when valid.
	 *
	 * @param mixed $authorization The raw `authorization` value off the transition spec.
	 * @param string $action Transition action name, for the error message.
	 *
	 * @return array{code: string, message: string}|null Error, or null when valid.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each return is a distinct, irreducible shape violation.
	 */
	private function validateTransitionAuthorization(mixed $authorization, string $action): ?array {
		if (is_array($authorization) === false || $authorization === []) {
			return [
				'code' => 'lifecycle-authorization-malformed',
				'message' => sprintf(
					'Transition "%s" `authorization` must be a non-empty array of group ids or {role} objects.',
					$action
				),
			];
		}

		foreach ($authorization as $entry) {
			$isGroupString = (is_string($entry) === true && $entry !== '');
			$isRoleObject = (is_array($entry) === true
				&& isset($entry['role']) === true
				&& is_string($entry['role']) === true
				&& $entry['role'] !== '');

			if ($isGroupString === false && $isRoleObject === false) {
				return [
					'code' => 'lifecycle-authorization-entry-malformed',
					'message' => sprintf(
						'Transition "%s" `authorization` entries must be non-empty group id strings or {"role":"<name>"} objects.',
						$action
					),
				];
			}
		}

		return null;
	}//end validateTransitionAuthorization()

	/**
	 * Shape-check a transition's optional `condition`, in either dialect.
	 *
	 * 🔴 A SCALAR IS REFUSED, AND THAT IS THE POINT OF THIS METHOD.
	 * An expression validator answers TRUE for any non-array, because in a
	 * flow a scalar is a literal and a literal is always well-formed. Here the
	 * expression decides whether a transition may proceed, and a truthy literal
	 * authorises EVERY attempt — it fails OPEN, silently, in the one place that
	 * exists to say no.
	 *
	 * The trap is not hypothetical. This annotation already carries a second
	 * `condition` key one level deeper, on `transitions.<action>.actions[]`,
	 * written in a different dialect: the `@self.<field> == '<value>'` string
	 * that {@see LifecycleActionExecutor::evaluateCondition()} parses by regex.
	 * An author who copies that form up one level writes something that looks
	 * right, stores cleanly, and gates nothing.
	 *
	 * @param mixed $condition Raw value of the transition's `condition` key.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array{code: string, message: string}|null Error, or null when well-formed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ConditionDialect's dialect lookup reads one
	 * constant table and holds no state; calling it statically IS the reuse, and it is
	 * the SAME lookup the save path makes, which is what keeps the two from drifting.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateTransitionCondition(mixed $condition, string $action): ?array {
		if (is_array($condition) === false) {
			return [
				'code' => 'lifecycle-condition-malformed',
				'message' => sprintf(
					'Transition "%s" `condition` must be a rule object, in the JSON AST such as '
					. '{"not": {"eq": [{"prop": "object.motivering"}, null]}} or in the legacy '
					. 'JSONLogic such as {"!!": {"var": "object.motivering"}}. '
					. 'A string or other scalar is refused: '
					. 'it would evaluate as a literal and allow every attempt. The '
					. '"@self.field == \'value\'" form belongs on an `actions[]` entry, not here.',
					$action
				),
			];
		}

		if ($condition === []) {
			return [
				'code' => 'lifecycle-condition-malformed',
				'message' => sprintf('Transition "%s" `condition` must not be empty.', $action),
			];
		}

		if (ConditionDialect::isValidCondition(node: $condition) === false) {
			return [
				'code' => 'lifecycle-condition-malformed',
				'message' => sprintf(
					'Transition "%s" `condition` is neither a valid JSON-AST expression such as '
					. '{"gt": [{"prop": "object.bedrag"}, 500]} nor a valid JSONLogic one such as '
					. '{">": [{"var": "object.bedrag"}, 500]}.',
					$action
				),
			];
		}

		return null;
	}//end validateTransitionCondition()

	/**
	 * Shape-check a transition's optional `autoWhen` and `executionMode`.
	 *
	 * All four codes this method can return REFUSE the schema save rather than
	 * warn, which is a departure from the advisory treatment most lifecycle
	 * errors get. The reason is the direction each failure takes:
	 *
	 * - a stored scalar `autoWhen` evaluates as a truthy literal, so the
	 *   transition would fire on every write from its `from` state, writing an
	 *   audit row and a round of notifications each time;
	 * - an unknown `executionMode`, a required input beside `autoWhen` and an
	 *   `autoWhen` on a graph block all describe a move that can NEVER happen,
	 *   which is the silent no-op class the declarative-conditions link refused.
	 *
	 * Refusing breaks no existing import: no register can carry either key
	 * before this change ships them.
	 *
	 * @param array<string, mixed> $spec The transition's spec.
	 * @param string $action The transition name, for the error messages.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateAutomaticTransition(array $spec, string $action): array {
		$errors = [];

		if (array_key_exists('autoWhen', $spec) === true) {
			$ruleError = $this->validateAutoWhenRule(autoWhen: $spec['autoWhen'], action: $action);
			if ($ruleError !== null) {
				$errors[] = $ruleError;
			}

			if ($this->declaresRequiredInput(inputs: ($spec['inputs'] ?? [])) === true) {
				$errors[] = [
					'code' => 'lifecycle-autowhen-requires-input',
					'message' => sprintf(
						'Transition "%s" declares `autoWhen` beside a required `inputs` entry. '
						. 'An automatic move carries no payload, so this transition could only ever '
						. 'be refused. Drop `required: true`, or drop `autoWhen`.',
						$action
					),
				];
			}
		}

		if (array_key_exists('executionMode', $spec) === true
			&& in_array($spec['executionMode'], [Flow::MODE_SYNC, Flow::MODE_ASYNC], true) === false
		) {
			$shown = gettype($spec['executionMode']);
			if (is_scalar($spec['executionMode']) === true) {
				$shown = (string)$spec['executionMode'];
			}

			$errors[] = [
				'code' => 'lifecycle-execution-mode-malformed',
				'message' => sprintf(
					'Transition "%s" `executionMode` "%s" must be exactly "%s" or "%s". '
					. 'Case variants are refused rather than normalised, so the lifecycle and the '
					. 'flow engine cannot drift into two spellings of the same two words.',
					$action,
					$shown,
					Flow::MODE_SYNC,
					Flow::MODE_ASYNC
				),
			];
		}

		return $errors;
	}//end validateAutomaticTransition()

	/**
	 * Shape-check the rule object of a transition's `autoWhen`.
	 *
	 * 🔴 A SCALAR IS REFUSED, AND THAT IS THE POINT OF THIS METHOD, for the
	 * same reason {@see validateTransitionCondition()} refuses one: a scalar
	 * handed to an expression validator is a literal and always valid. Here a
	 * truthy literal does not merely fail open once, it fires the transition
	 * again on every write.
	 *
	 * `autoWhen` is evaluated by {@see \OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator::holds()},
	 * the same method a `condition` goes through, so it accepts the same two
	 * dialects. Accepting fewer here would refuse at save a rule the engine
	 * would have run.
	 *
	 * @param mixed $autoWhen Raw value of the transition's `autoWhen` key.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array{code: string, message: string}|null Error, or null when well-formed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ConditionDialect's dialect lookup reads one
	 * constant table and holds no state; calling it statically IS the reuse.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateAutoWhenRule(mixed $autoWhen, string $action): ?array {
		$code = 'lifecycle-autowhen-malformed';

		if (is_array($autoWhen) === false) {
			return [
				'code' => $code,
				'message' => sprintf(
					'Transition "%s" `autoWhen` must be a rule object, in the JSON AST such as '
					. '{"not": {"eq": [{"prop": "object.motivering"}, null]}} or in the legacy '
					. 'JSONLogic such as {"!!": {"var": "object.motivering"}}. '
					. 'A string or other scalar is refused: '
					. 'it would evaluate as a literal and fire the transition on every write from '
					. 'its `from` state. The "@self.field == \'value\'" form belongs on an '
					. '`actions[]` entry, not here.',
					$action
				),
			];
		}

		if ($autoWhen === []) {
			return [
				'code' => $code,
				'message' => sprintf('Transition "%s" `autoWhen` must not be empty.', $action),
			];
		}

		if (ConditionDialect::isValidCondition(node: $autoWhen) === false) {
			return [
				'code' => $code,
				'message' => sprintf(
					'Transition "%s" `autoWhen` is neither a valid JSON-AST expression nor a valid '
					. 'JSONLogic one. Write it as a rule object, for example '
					. '{"not": {"eq": [{"prop": "object.motivering"}, null]}}.',
					$action
				),
			];
		}

		return null;
	}//end validateAutoWhenRule()

	/**
	 * Whether a transition's `inputs` declaration carries a required entry.
	 *
	 * Malformed entries are skipped rather than fatal, matching
	 * {@see TransitionEngine::normaliseDeclaredInputs()}: a broken declaration
	 * allowlists nothing, and reporting it is that method's `inputs` contract,
	 * not this one's.
	 *
	 * @param mixed $inputs The transition's declared `inputs` list.
	 *
	 * @return bool True when at least one declared input is `required`.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function declaresRequiredInput(mixed $inputs): bool {
		if (is_array($inputs) === false) {
			return false;
		}

		foreach ($inputs as $input) {
			if (is_array($input) === true && ($input['required'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end declaresRequiredInput()

	/**
	 * Shape-check a transition's optional refusal `message`.
	 *
	 * Accepts a non-empty string, or a per-locale map optionally carrying
	 * `defaultLocale`. The map form mirrors the `x-openregister-notifications`
	 * dialect key for key, so an author who has written one has written both.
	 * Every malformed shape returns the single code `lifecycle-message-malformed`.
	 *
	 * @param mixed $message Raw value of the transition's `message` key.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateTransitionMessage(mixed $message, string $action): array {
		$code = 'lifecycle-message-malformed';

		if (is_string($message) === true) {
			if ($message === '') {
				return [
					[
						'code' => $code,
						'message' => sprintf(
							'Transition "%s" `message` must be a non-empty string when present.',
							$action
						),
					],
				];
			}

			return [];
		}

		if (is_array($message) === false) {
			return [
				[
					'code' => $code,
					'message' => sprintf(
						'Transition "%s" `message` must be a string or a per-locale map.',
						$action
					),
				],
			];
		}

		return $this->validateMessageMap(message: $message, action: $action);
	}//end validateTransitionMessage()

	/**
	 * Shape-check the per-locale map form of a transition `message`.
	 *
	 * At least one locale, every locale a non-empty string, and a
	 * `defaultLocale` (when present) naming a declared locale.
	 *
	 * @param array<mixed> $message The per-locale map.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateMessageMap(array $message, string $action): array {
		$code = 'lifecycle-message-malformed';
		$errors = [];
		$localeKeys = array_filter(
			array_keys($message),
			static fn ($key): bool => $key !== 'defaultLocale' && is_string($key) === true
		);
		if (count($localeKeys) === 0) {
			$errors[] = [
				'code' => $code,
				'message' => sprintf(
					'Transition "%s" `message` map must declare at least one locale (e.g. nl, en).',
					$action
				),
			];
		}

		foreach ($localeKeys as $localeKey) {
			if (is_string($message[$localeKey]) === false || $message[$localeKey] === '') {
				$errors[] = [
					'code' => $code,
					'message' => sprintf(
						'Transition "%s" `message` for locale "%s" must be a non-empty string.',
						$action,
						$localeKey
					),
				];
			}
		}

		$defaultLocaleError = $this->validateDefaultLocale(message: $message, action: $action);
		if ($defaultLocaleError !== null) {
			$errors[] = $defaultLocaleError;
		}

		return $errors;
	}//end validateMessageMap()

	/**
	 * Check that a message map's `defaultLocale`, when present, names a declared locale.
	 *
	 * @param array<mixed> $message The per-locale map.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array{code: string, message: string}|null Error, or null when absent or valid.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateDefaultLocale(array $message, string $action): ?array {
		if (isset($message['defaultLocale']) === false) {
			return null;
		}

		$defaultLocale = $message['defaultLocale'];
		if (is_string($defaultLocale) === true && isset($message[$defaultLocale]) === true) {
			return null;
		}

		$shown = gettype($defaultLocale);
		if (is_string($defaultLocale) === true) {
			$shown = $defaultLocale;
		}

		return [
			'code' => 'lifecycle-message-malformed',
			'message' => sprintf(
				'Transition "%s" `message` defaultLocale "%s" is not declared in the message map.',
				$action,
				$shown
			),
		];
	}//end validateDefaultLocale()
}//end class
