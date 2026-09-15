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
use OCA\OpenRegister\Service\Flow\FlowExpression;

/**
 * Pure validation logic for the `x-openregister-lifecycle` annotation.
 *
 * Hooked into the schema-save path in SchemaService (or its caller). Errors
 * map to HTTP 422 responses as schema-save failures.
 */
final class LifecycleAnnotationValidator {

	/**
	 * The rule kinds a state's `fields` block may declare.
	 *
	 * @var array<int, string>
	 */
	private const FIELD_RULE_KINDS = ['hidden', 'readOnly', 'required'];

	/**
	 * The roots of a condition reference that name something other than a property.
	 *
	 * `object` is in the list because `object.bedrag` and a bare `bedrag` are
	 * the same reference written two ways; the rest name the evaluation
	 * document's other branches, which no schema property backs.
	 *
	 * @var array<int, string>
	 */
	private const CONDITION_NAMESPACES = ['object', 'previous', 'user', 'state', 'transition'];

	/**
	 * Validate the annotation block on a schema definition.
	 *
	 * @param array<string, mixed> $schema Full schema definition (top-level shape — must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-lifecycle']) === false) {
			return [];
		}

		$annotation = $schema['x-openregister-lifecycle'];
		$errors = [];

		// `property` is an additive alias for `field`; normalise it up-front so
		// the rest of validation (and the runtime listener) sees a single key.
		if (isset($annotation['field']) === false && isset($annotation['property']) === true) {
			$annotation['field'] = $annotation['property'];
		}

		// Provider mode: checked BEFORE graph so an annotation that declares
		// both is refused by the provider-mode conflict rule below, with a
		// message naming the real mistake, instead of being shape-checked as
		// a graph block that happens to carry a stray key.
		if (isset($annotation['provider']) === true) {
			return array_merge(
				$this->validateProviderMode(annotation: $annotation, schema: $schema),
				$this->validateStates(annotation: $annotation, schema: $schema, enumSet: null)
			);
		}

		// Graph mode: when a non-empty `graph` block is declared, the lifecycle
		// field is a `$ref` with no enum, so shape-check the graph block instead
		// of the static `transitions`/enum contract. Static-only schemas fall
		// straight through to the unchanged validation below (no regression).
		if (isset($annotation['graph']) === true
			&& is_array($annotation['graph']) === true
			&& $annotation['graph'] !== []
		) {
			return array_merge(
				$this->validateGraphMode(annotation: $annotation, schema: $schema),
				$this->validateStates(annotation: $annotation, schema: $schema, enumSet: null)
			);
		}

		// Required top-level fields.
		foreach (['field', 'initial', 'transitions'] as $required) {
			if (isset($annotation[$required]) === false) {
				$errors[] = [
					'code' => 'lifecycle-missing-key',
					'message' => sprintf('x-openregister-lifecycle is missing required key "%s".', $required),
				];
			}
		}

		if (count($errors) > 0) {
			return $errors;
		}

		$field = (string)$annotation['field'];
		$initial = (string)$annotation['initial'];
		$transitions = $annotation['transitions'];
		$final = ($annotation['final'] ?? []);

		// Field must exist on the schema.
		$properties = ($schema['properties'] ?? []);
		if (isset($properties[$field]) === false) {
			$errors[] = [
				'code' => 'lifecycle-field-missing',
				'message' => sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', $field),
			];
			// Without the field, other checks can't proceed meaningfully.
			return $errors;
		}

		// Field must be a string with an enum constraint.
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
			return $errors;
		}

		$enumSet = array_flip($enum);

		// Initial value must be in the enum.
		if (isset($enumSet[$initial]) === false) {
			$errors[] = [
				'code' => 'lifecycle-initial-not-in-enum',
				'message' => sprintf('x-openregister-lifecycle.initial "%s" is not in the field\'s enum.', $initial),
			];
		}

		// Final values (if declared) must be in the enum.
		if (is_array($final) === true) {
			foreach ($final as $finalState) {
				if (isset($enumSet[(string)$finalState]) === false) {
					$errors[] = [
						'code' => 'lifecycle-final-not-in-enum',
						'message' => sprintf('x-openregister-lifecycle.final value "%s" is not in the field\'s enum.', $finalState),
					];
				}
			}
		}

		// Transitions must be a non-empty map.
		if (is_array($transitions) === false || count($transitions) === 0) {
			$errors[] = [
				'code' => 'lifecycle-transitions-empty',
				'message' => 'x-openregister-lifecycle.transitions must declare at least one action.',
			];
			return $errors;
		}

		foreach ($transitions as $action => $spec) {
			if (is_array($spec) === false) {
				$errors[] = [
					'code' => 'lifecycle-transition-malformed',
					'message' => sprintf('Transition "%s" must be an object with `from` and `to`.', (string)$action),
				];
				continue;
			}

			// From: required, a single state string or an array of states, all
			// in the enum. A string is coerced to a one-element list.
			$from = ($spec['from'] ?? null);
			if (is_string($from) === true && $from !== '') {
				$from = [$from];
			}

			$fromOk = (is_array($from) === true && count($from) > 0);
			if ($fromOk === false) {
				$errors[] = [
					'code' => 'lifecycle-from-missing',
					'message' => sprintf('Transition "%s" must declare a non-empty `from` array.', (string)$action),
				];
			}

			$fromIterable = [];
			if ($fromOk === true) {
				$fromIterable = $from;
			}

			foreach ($fromIterable as $fromState) {
				if (isset($enumSet[(string)$fromState]) === false) {
					$errors[] = [
						'code' => 'lifecycle-from-not-in-enum',
						'message' => sprintf(
							'Transition "%s" lists "from" state "%s" which is not in the field\'s enum.',
							(string)$action,
							(string)$fromState
						),
					];
				}
			}

			// To: required string in the enum.
			$to = ($spec['to'] ?? null);
			if (is_string($to) === false || $to === '') {
				$errors[] = [
					'code' => 'lifecycle-to-missing',
					'message' => sprintf('Transition "%s" must declare a string `to` value.', (string)$action),
				];
			} elseif (isset($enumSet[$to]) === false) {
				$errors[] = [
					'code' => 'lifecycle-to-not-in-enum',
					'message' => sprintf(
						'Transition "%s" `to` value "%s" is not in the field\'s enum.',
						(string)$action,
						$to
					),
				];
			}

			// Optional `requires` — must be a non-empty string when present.
			// We don't try to resolve the DI tag at validation time; that's
			// an install-time concern (warning) and a first-invocation
			// concern (hard fail). At schema-save we just shape-check.
			if (isset($spec['requires']) === true) {
				if (is_string($spec['requires']) === false || $spec['requires'] === '') {
					$errors[] = [
						'code' => 'lifecycle-requires-malformed',
						'message' => sprintf(
							'Transition "%s" `requires` must be a non-empty DI tag string.',
							(string)$action
						),
					];
				}
			}

			// Optional `authorization` — declarative per-transition group/role
			// gate (Engine 1). When present it must be a non-empty list whose
			// entries are either NC group id strings or `{ "role": "<name>" }`
			// objects. Shape-check only; group existence is a runtime concern.
			if (isset($spec['authorization']) === true) {
				$authError = $this->validateTransitionAuthorization(
					authorization: $spec['authorization'],
					action: (string)$action
				);
				if ($authError !== null) {
					$errors[] = $authError;
				}
			}

			// Optional `condition` — a declarative JSONLogic precondition on
			// the object's own data. Shape-checked here so a broken expression
			// cannot be stored; see validateTransitionCondition() for why a
			// scalar is refused rather than accepted as a literal.
			if (isset($spec['condition']) === true) {
				$conditionError = $this->validateTransitionCondition(
					condition: $spec['condition'],
					action: (string)$action
				);
				if ($conditionError !== null) {
					$errors[] = $conditionError;
				}
			}

			// Optional `autoWhen` / `executionMode` — the declaration that makes
			// this transition fire on its own. Refused rather than warned about,
			// because a stored malformed rule fires on EVERY write from its
			// `from` state; see the change's design.md.
			$errors = array_merge(
				$errors,
				$this->validateAutomaticTransition(spec: $spec, action: (string)$action)
			);

			// Optional `message` — the refusal text a declined `condition`
			// carries. A non-empty string, or a per-locale map.
			if (isset($spec['message']) === true) {
				$errors = array_merge(
					$errors,
					$this->validateTransitionMessage(
						message: $spec['message'],
						action: (string)$action
					)
				);
			}
		}//end foreach

		// Per-state field rules and state conditions. Validated last so a
		// malformed transition is reported as a transition problem rather than
		// as a state one, and so the enum the states are checked against has
		// already been established.
		$errors = array_merge(
			$errors,
			$this->validateStates(annotation: $annotation, schema: $schema, enumSet: $enumSet)
		);

		$errors = array_merge(
			$errors,
			$this->validateInputsAgainstHiddenFields(annotation: $annotation, transitions: $transitions)
		);

		return $errors;
	}//end validate()

	/**
	 * Validate `x-openregister-lifecycle.states`.
	 *
	 * The three refusals the change names are all here: a field the schema does
	 * not declare, a state the lifecycle does not declare, and a condition
	 * naming a property that does not exist. Each is refused at schema save
	 * rather than warned about, because a field rule that silently matches
	 * nothing reads as enforced and is not — the same reason a malformed
	 * transition `condition` is refused.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param array<string, mixed> $schema Full schema definition.
	 * @param array<string, int>|null $enumSet The declared states, or null when the mode does not publish them.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
	 */
	private function validateStates(array $annotation, array $schema, ?array $enumSet): array {
		if (isset($annotation['states']) === false) {
			return [];
		}

		$states = $annotation['states'];
		if (is_array($states) === false || $states === []) {
			return [
				[
					'code' => 'lifecycle-states-malformed',
					'message' => 'x-openregister-lifecycle.states must be a non-empty map of state names to blocks.',
				],
			];
		}

		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		$errors = [];
		foreach ($states as $state => $block) {
			$state = (string)$state;
			if ($enumSet !== null && isset($enumSet[$state]) === false) {
				$errors[] = [
					'code' => 'lifecycle-state-unknown',
					'message' => sprintf(
						'x-openregister-lifecycle.states declares state "%s" which is not in the field\'s enum.',
						$state
					),
				];
				continue;
			}

			if (is_array($block) === false) {
				$errors[] = [
					'code' => 'lifecycle-state-malformed',
					'message' => sprintf('x-openregister-lifecycle.states."%s" must be an object.', $state),
				];
				continue;
			}

			$errors = array_merge(
				$errors,
				$this->validateStateFields(block: $block, state: $state, properties: $properties),
				$this->validateStateConditions(block: $block, state: $state, properties: $properties)
			);
		}//end foreach

		return $errors;
	}//end validateStates()

	/**
	 * Validate one state's `fields` block.
	 *
	 * @param array<string, mixed> $block The state block.
	 * @param string $state The state name.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
	 */
	private function validateStateFields(array $block, string $state, array $properties): array {
		if (isset($block['fields']) === false) {
			return [];
		}

		$fields = $block['fields'];
		if (is_array($fields) === false || $fields === []) {
			return [
				[
					'code' => 'lifecycle-state-fields-malformed',
					'message' => sprintf(
						'x-openregister-lifecycle.states."%s".fields must be a non-empty object of '
						. '"hidden", "readOnly" and/or "required" lists.',
						$state
					),
				],
			];
		}

		$errors = [];
		foreach ($fields as $kind => $entries) {
			$kind = (string)$kind;
			if (in_array($kind, self::FIELD_RULE_KINDS, true) === false) {
				$errors[] = [
					'code' => 'lifecycle-state-fields-unknown-kind',
					'message' => sprintf(
						'x-openregister-lifecycle.states."%s".fields declares "%s"; only %s are rule kinds.',
						$state,
						$kind,
						implode(', ', self::FIELD_RULE_KINDS)
					),
				];
				continue;
			}

			if (is_array($entries) === false || $entries === []) {
				$errors[] = [
					'code' => 'lifecycle-state-fields-malformed',
					'message' => sprintf(
						'x-openregister-lifecycle.states."%s".fields."%s" must be a non-empty list of entries.',
						$state,
						$kind
					),
				];
				continue;
			}

			foreach ($entries as $entry) {
				$errors = array_merge(
					$errors,
					$this->validateFieldRuleEntry(
						entry: $entry,
						state: $state,
						kind: $kind,
						properties: $properties
					)
				);
			}
		}//end foreach

		return $errors;
	}//end validateStateFields()

	/**
	 * Validate one `{fields, groups, when}` entry.
	 *
	 * @param mixed $entry The declared entry.
	 * @param string $state The state name.
	 * @param string $kind The rule kind the entry sits under.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
	 */
	private function validateFieldRuleEntry(mixed $entry, string $state, string $kind, array $properties): array {
		if (is_array($entry) === false) {
			return [
				[
					'code' => 'lifecycle-state-field-entry-malformed',
					'message' => sprintf(
						'An entry of x-openregister-lifecycle.states."%s".fields."%s" must be an object '
						. 'with a "fields" list.',
						$state,
						$kind
					),
				],
			];
		}

		$names = ($entry['fields'] ?? ($entry['field'] ?? null));
		if (is_string($names) === true) {
			$names = [$names];
		}

		if (is_array($names) === false || $names === []) {
			return [
				[
					'code' => 'lifecycle-state-field-entry-malformed',
					'message' => sprintf(
						'An entry of x-openregister-lifecycle.states."%s".fields."%s" must name at least one field.',
						$state,
						$kind
					),
				],
			];
		}

		$errors = [];
		foreach ($names as $name) {
			if (is_string($name) === false || $name === '') {
				$errors[] = [
					'code' => 'lifecycle-state-field-entry-malformed',
					'message' => sprintf(
						'x-openregister-lifecycle.states."%s".fields."%s" names a field that is not a string.',
						$state,
						$kind
					),
				];
				continue;
			}

			if (isset($properties[$name]) === false) {
				$errors[] = [
					'code' => 'lifecycle-state-field-missing',
					'message' => sprintf(
						'x-openregister-lifecycle.states."%s".fields."%s" names field "%s", '
						. 'which is not declared in `properties`.',
						$state,
						$kind,
						$name
					),
				];
			}
		}//end foreach

		$groupError = $this->validateFieldRuleGroups(entry: $entry, state: $state, kind: $kind);
		if ($groupError !== null) {
			$errors[] = $groupError;
		}

		$when = ($entry['when'] ?? ($entry['condition'] ?? null));
		if ($when !== null) {
			$errors = array_merge(
				$errors,
				$this->validateStateCondition(
					condition: $when,
					state: $state,
					label: sprintf('fields."%s" condition', $kind),
					properties: $properties
				)
			);
		}

		return $errors;
	}//end validateFieldRuleEntry()

	/**
	 * Validate an entry's optional `groups` clause.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 * @param string $state The state name.
	 * @param string $kind The rule kind the entry sits under.
	 *
	 * @return array{code: string, message: string}|null The error, or null.
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
	 */
	private function validateFieldRuleGroups(array $entry, string $state, string $kind): ?array {
		if (isset($entry['groups']) === false) {
			return null;
		}

		$groups = $entry['groups'];
		if (is_string($groups) === true) {
			return null;
		}

		if (is_array($groups) === false || $groups === []) {
			return [
				'code' => 'lifecycle-state-field-groups-malformed',
				'message' => sprintf(
					'x-openregister-lifecycle.states."%s".fields."%s" declares `groups` that is neither '
					. 'a group id nor a non-empty list of them. Omit `groups` to apply the rule to everyone.',
					$state,
					$kind
				),
			];
		}

		foreach ($groups as $group) {
			if (is_string($group) === false || $group === '') {
				return [
					'code' => 'lifecycle-state-field-groups-malformed',
					'message' => sprintf(
						'x-openregister-lifecycle.states."%s".fields."%s" lists a group that is not a '
						. 'non-empty string.',
						$state,
						$kind
					),
				];
			}
		}

		return null;
	}//end validateFieldRuleGroups()

	/**
	 * Validate a state's `entry` and `exit` conditions.
	 *
	 * @param array<string, mixed> $block The state block.
	 * @param string $state The state name.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
	 */
	private function validateStateConditions(array $block, string $state, array $properties): array {
		$errors = [];
		foreach (['entry', 'exit', 'condition'] as $key) {
			if (isset($block[$key]) === false) {
				continue;
			}

			$declared = $block[$key];
			if (is_array($declared) === true) {
				$nested = ($declared['condition'] ?? ($declared['when'] ?? null));
				if (is_array($nested) === true && $nested !== []) {
					$declared = $nested;
				}
			}

			$errors = array_merge(
				$errors,
				$this->validateStateCondition(
					condition: $declared,
					state: $state,
					label: $key,
					properties: $properties
				)
			);
		}

		return $errors;
	}//end validateStateConditions()

	/**
	 * Shape-check one condition and check every property it names.
	 *
	 * @param mixed $condition The declared condition node.
	 * @param string $state The state name.
	 * @param string $label Where the condition sits, for the message.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
	 */
	private function validateStateCondition(mixed $condition, string $state, string $label, array $properties): array {
		if (is_array($condition) === false || $condition === []) {
			return [
				[
					'code' => 'lifecycle-state-condition-malformed',
					'message' => sprintf(
						'x-openregister-lifecycle.states."%s" %s must be a non-empty condition object. '
						. 'A scalar is refused: it would evaluate as a literal and let every write through.',
						$state,
						$label
					),
				],
			];
		}

		$errors = [];
		foreach (self::propertyReferences(node: $condition) as $reference) {
			if (isset($properties[$reference]) === true) {
				continue;
			}

			$errors[] = [
				'code' => 'lifecycle-state-condition-property-missing',
				'message' => sprintf(
					'x-openregister-lifecycle.states."%s" %s reads property "%s", '
					. 'which is not declared in `properties`.',
					$state,
					$label,
					$reference
				),
			];
		}

		return $errors;
	}//end validateStateCondition()

	/**
	 * A transition may not ask for a field the state it leads to hides.
	 *
	 * The two declarations would otherwise contradict each other at run time:
	 * the form asks for the value and the render strips it back out, and the
	 * user fills in a field that vanishes. Refusing the pair at schema save is
	 * the only moment both halves are in front of the same reader.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param mixed $transitions The declared transitions.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
	 */
	private function validateInputsAgainstHiddenFields(array $annotation, mixed $transitions): array {
		$states = ($annotation['states'] ?? null);
		if (is_array($states) === false || is_array($transitions) === false) {
			return [];
		}

		$errors = [];
		foreach ($transitions as $action => $spec) {
			if (is_array($spec) === false || is_array(($spec['inputs'] ?? null)) === false) {
				continue;
			}

			$target = (string)($spec['to'] ?? '');
			$hidden = self::hiddenFieldsOf(block: ($states[$target] ?? null));
			if ($hidden === []) {
				continue;
			}

			foreach ($spec['inputs'] as $input) {
				$field = null;
				if (is_string($input) === true) {
					$field = $input;
				} elseif (is_array($input) === true && is_string(($input['field'] ?? null)) === true) {
					$field = $input['field'];
				}

				if ($field === null || in_array($field, $hidden, true) === false) {
					continue;
				}

				$errors[] = [
					'code' => 'lifecycle-input-hidden-in-target',
					'message' => sprintf(
						'Transition "%s" asks for input "%s", which state "%s" hides.',
						(string)$action,
						$field,
						$target
					),
				];
			}
		}//end foreach

		return $errors;
	}//end validateInputsAgainstHiddenFields()

	/**
	 * Every field name a state block hides, whatever the rule's conditions.
	 *
	 * The check this feeds is a contradiction between two declarations, not a
	 * runtime decision, so a conditionally hidden field counts: the transition
	 * would ask for a field that sometimes vanishes, which is the same mistake.
	 *
	 * @param mixed $block The state block.
	 *
	 * @return array<int, string> The hidden field names.
	 */
	private static function hiddenFieldsOf(mixed $block): array {
		if (is_array($block) === false) {
			return [];
		}

		$entries = ($block['fields']['hidden'] ?? null);
		if (is_array($entries) === false) {
			return [];
		}

		$hidden = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$names = ($entry['fields'] ?? ($entry['field'] ?? []));
			if (is_string($names) === true) {
				$names = [$names];
			}

			if (is_array($names) === false) {
				continue;
			}

			foreach ($names as $name) {
				if (is_string($name) === true && $name !== '') {
					$hidden[] = $name;
				}
			}
		}

		return $hidden;
	}//end hiddenFieldsOf()

	/**
	 * Every object property a condition reads, by name.
	 *
	 * Both dialects are walked because a state condition may be written in
	 * either: JSONLogic spells a reference `var`, the JSON AST spells it
	 * `prop`. A reference into `user`, `state`, `transition`, `previous` or an
	 * `@`-prefixed namespace is a built-in and names no property, so it is
	 * skipped rather than reported as missing.
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return array<int, string> The property names, de-duplicated.
	 */
	private static function propertyReferences(mixed $node): array {
		$found = [];
		self::collectReferences(node: $node, found: $found);
		return array_values(array_unique($found));
	}//end propertyReferences()

	/**
	 * Walk a condition node, collecting the property names it reads.
	 *
	 * @param mixed $node The condition node.
	 * @param array<int, string> $found The names collected so far.
	 *
	 * @return void
	 */
	private static function collectReferences(mixed $node, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			if (($key === 'var' || $key === 'prop') && is_string($value) === true) {
				$name = self::propertyNameOf(reference: $value);
				if ($name !== null) {
					$found[] = $name;
				}

				continue;
			}

			if (($key === 'var' || $key === 'prop') && is_array($value) === true
				&& is_string(($value[0] ?? null)) === true
			) {
				$name = self::propertyNameOf(reference: $value[0]);
				if ($name !== null) {
					$found[] = $name;
				}

				continue;
			}

			self::collectReferences(node: $value, found: $found);
		}
	}//end collectReferences()

	/**
	 * The schema property a reference path names, or null when it names none.
	 *
	 * @param string $reference The reference path as declared.
	 *
	 * @return string|null The property name.
	 */
	private static function propertyNameOf(string $reference): ?string {
		$path = trim($reference);
		if ($path === '' || str_starts_with($path, '@') === true || str_starts_with($path, '_') === true) {
			return null;
		}

		$segments = explode('.', $path);
		$root = array_shift($segments);
		if (in_array($root, self::CONDITION_NAMESPACES, true) === true) {
			if ($root !== 'object' || $segments === []) {
				return null;
			}

			$root = array_shift($segments);
		}

		if ($root === '' || str_starts_with($root, '@') === true || str_starts_with($root, '_') === true) {
			return null;
		}

		return $root;
	}//end propertyNameOf()

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
			$initialError = $this->validateInitialForm(initial: $annotation['initial']);
			if ($initialError !== null) {
				$errors[] = $initialError;
			}
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each check maps to one distinct, irreducible graph-shape rule.
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function validateGraphMode(array $annotation, array $schema): array {
		$errors = [];

		// `field` remains required and non-empty, but the enum/type:string
		// constraint is relaxed for a `$ref` lifecycle field.
		$field = ($annotation['field'] ?? null);
		$fieldValid = (is_string($field) === true && $field !== '');
		if ($fieldValid === false) {
			$errors[] = [
				'code' => 'lifecycle-missing-key',
				'message' => 'x-openregister-lifecycle is missing required key "field".',
			];
		}

		if ($fieldValid === true) {
			$properties = ($schema['properties'] ?? []);
			if (is_array($properties) === true && isset($properties[$field]) === false) {
				$errors[] = [
					'code' => 'lifecycle-field-missing',
					'message' => sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', $field),
				];
			}
		}

		// `initial` (optional): accept the literal-string form or the object
		// form `{ from, field }` with both keys non-empty strings.
		if (isset($annotation['initial']) === true) {
			$initialError = $this->validateInitialForm(initial: $annotation['initial']);
			if ($initialError !== null) {
				$errors[] = $initialError;
			}
		}

		// Graph block: required non-empty string keys.
		$graph = $annotation['graph'];
		foreach (['schema', 'parentField', 'parentFrom', 'orderField', 'finalField'] as $key) {
			$value = ($graph[$key] ?? null);
			if (is_string($value) === false || $value === '') {
				$errors[] = [
					'code' => 'lifecycle-graph-missing-key',
					'message' => sprintf('x-openregister-lifecycle.graph is missing required string key "%s".', $key),
				];
			}
		}

		// A `condition` on the graph block is REFUSED, not ignored and not
		// half-enforced. Graph-mode moves are derived inside TransitionEngine;
		// a graph annotation declares no `transitions`, so LifecycleValidation-
		// Listener returns through its app-managed branch and the ordinary save
		// path enforces nothing. A condition honoured on the engine route but
		// absent on the save path would leave an author believing a state is
		// unreachable when it is one direct write away — the silent direction
		// of the failure is why this refuses instead. Unblocked by graph-mode
		// enforcement on the save path (`lifecycle-graph-enforcement`).
		if (isset($graph['condition']) === true) {
			$errors[] = [
				'code' => 'lifecycle-condition-graph-unsupported',
				'message' => 'x-openregister-lifecycle.graph does not support `condition`: '
					. 'graph-mode moves are not enforced on the save path, so a condition '
					. 'declared here would not hold. Declare conditions on static transitions.',
			];
		}

		// An `autoWhen` on the graph block is REFUSED for the reason its
		// `condition` sibling above is: a graph block declares no transitions,
		// so the ordinary save path enforces nothing on a graph-mode move, and
		// an automatic move made through the engine could be undone by one
		// unchecked direct write. A graph block also has no per-transition
		// object in which an author could say which derived sibling to move to.
		// Unblocked by `lifecycle-graph-enforcement`.
		if (isset($graph['autoWhen']) === true) {
			$errors[] = [
				'code' => 'lifecycle-autowhen-graph-unsupported',
				'message' => 'x-openregister-lifecycle.graph does not support `autoWhen`: '
					. 'graph-mode automatic transitions are not supported while graph-mode '
					. 'moves are unenforced on the save path. Declare `autoWhen` on a static transition.',
			];
		}

		// `allowedMoves`: required, one of forward|adjacent|any.
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
	}//end validateGraphMode()

	/**
	 * Shape-check the `initial` value in its two accepted forms.
	 *
	 * Valid: a non-empty string (literal form) or an object with non-empty
	 * string `from` and `field` keys (object form). Returns a single structured
	 * error on violation, or null when valid.
	 *
	 * @param mixed $initial The raw `initial` value off the annotation.
	 *
	 * @return array{code: string, message: string}|null Error, or null when valid.
	 */
	private function validateInitialForm(mixed $initial): ?array {
		if (is_string($initial) === true) {
			return null;
		}

		if (is_array($initial) === true) {
			$from = ($initial['from'] ?? null);
			$field = ($initial['field'] ?? null);
			if (is_string($from) === false || $from === ''
				|| is_string($field) === false || $field === ''
			) {
				return [
					'code' => 'lifecycle-initial-malformed',
					'message' => 'x-openregister-lifecycle.initial object form must declare non-empty "from" and "field" strings.',
				];
			}

			return null;
		}

		return [
			'code' => 'lifecycle-initial-malformed',
			'message' => 'x-openregister-lifecycle.initial must be a string or an object with "from" and "field".',
		];
	}//end validateInitialForm()

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
	 * Shape-check a transition's optional JSONLogic `condition`.
	 *
	 * 🔴 A SCALAR IS REFUSED, AND THAT IS THE POINT OF THIS METHOD.
	 * `FlowExpression::isValid()` answers TRUE for any non-array, because in a
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
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's
	 * stateless expression facade; calling it statically IS the reuse.
	 *
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	private function validateTransitionCondition(mixed $condition, string $action): ?array {
		if (is_array($condition) === false) {
			return [
				'code' => 'lifecycle-condition-malformed',
				'message' => sprintf(
					'Transition "%s" `condition` must be a JSONLogic rule object such as '
					. '{"!!": {"var": "object.motivering"}}. A string or other scalar is refused: '
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

		if (FlowExpression::isValid(logic: $condition) === false) {
			return [
				'code' => 'lifecycle-condition-malformed',
				'message' => sprintf(
					'Transition "%s" `condition` is not a valid JSONLogic expression.',
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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
	 * handed to `FlowExpression::isValid()` is a literal and always valid. Here
	 * a truthy literal does not merely fail open once, it fires the transition
	 * again on every write.
	 *
	 * @param mixed $autoWhen Raw value of the transition's `autoWhen` key.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array{code: string, message: string}|null Error, or null when well-formed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's
	 * stateless expression facade; calling it statically IS the reuse.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function validateAutoWhenRule(mixed $autoWhen, string $action): ?array {
		$code = 'lifecycle-autowhen-malformed';

		if (is_array($autoWhen) === false) {
			return [
				'code' => $code,
				'message' => sprintf(
					'Transition "%s" `autoWhen` must be a JSONLogic rule object such as '
					. '{"!!": {"var": "object.motivering"}}. A string or other scalar is refused: '
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

		if (FlowExpression::isValid(logic: $autoWhen) === false) {
			return [
				'code' => $code,
				'message' => sprintf(
					'Transition "%s" `autoWhen` is not a valid JSONLogic expression. '
					. 'Write it as a rule object, for example {"!!": {"var": "object.motivering"}}.',
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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
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
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
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
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
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
