<?php

/**
 * OpenRegister LifecycleStateValidator
 *
 * Schema-save validation of `x-openregister-lifecycle.states`: the per-state
 * field rules, the state conditions, and the transition inputs they contradict.
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
 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The `states` half of the lifecycle annotation's validation.
 *
 * It lives in its own class rather than inside {@see LifecycleAnnotationValidator}
 * because that class already validated three annotation modes and was at 975
 * lines; adding the state rules to it pushed it past 1,500 and past the
 * excessive-class-length threshold. The split is along the seam the annotation
 * already has: `transitions` describe the edges, `states` describe the nodes.
 *
 * Every check here REFUSES rather than warns. A field rule naming a property
 * that does not exist matches nothing, which reads as enforced and is not, and
 * that is the failure mode the whole change exists to close.
 *
 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) A shape validator's complexity IS
 *   its branch count: every accepted spelling and every refusal is one branch, and each
 *   one is a message a schema author reads. Collapsing them would trade a readable
 *   refusal for a lower number.
 */
final class LifecycleStateValidator {

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
	public function validateStates(array $annotation, array $schema, ?array $enumSet): array {
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per accepted spelling and
	 *   per refusal; see the class docblock.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same reason.
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Two declarations are cross-checked,
	 *   each in either of its accepted spellings, so the branches are the product.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same reason.
	 */
	public function validateInputsAgainstHiddenFields(array $annotation, mixed $transitions): array {
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Every branch is one tolerated shape of
	 *   the declaration; a malformed one is skipped rather than thrown on, because this
	 *   feeds a cross-check and the shape itself is refused elsewhere.
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Two dialects, each with a scalar and a
	 *   list spelling of a reference, plus the recursive descent.
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch rejects one class of
	 *   reference that names no schema property; merging them would hide which.
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
}//end class
