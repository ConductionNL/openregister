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
 * @spec openspec/specs/object-lifecycle/spec.md
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
 * @spec openspec/specs/object-lifecycle/spec.md
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
	 * Constructor.
	 *
	 * @param StateConditionValidator $conditions Validates the state and field rule conditions.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly StateConditionValidator $conditions = new StateConditionValidator(),
	) {
	}//end __construct()

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
	 * @param array<string, mixed>  $annotation The normalised annotation block.
	 * @param StateDeclarationScope $scope      The schema's declared properties and the declared states.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validateStates(array $annotation, StateDeclarationScope $scope): array {
		$states = ($annotation['states'] ?? null);
		return (self::statesShapeErrors(states: $states) ?? $this->validateStateMap(states: $states, scope: $scope));
	}//end validateStates()

	/**
	 * What a `states` block is checked against: the schema's declared properties and the declared states.
	 *
	 * @param array<string, mixed>    $schema  Full schema definition.
	 * @param array<string, int>|null $enumSet The declared states, or null when the mode does not publish them.
	 *
	 * @return StateDeclarationScope The scope.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function scopeOf(array $schema, ?array $enumSet): StateDeclarationScope {
		return new StateDeclarationScope(properties: self::declaredProperties(schema: $schema), enumSet: $enumSet);
	}//end scopeOf()

	/**
	 * Validate every block of a well-shaped `states` map.
	 *
	 * @param array<string, mixed>  $states The declared `states` map.
	 * @param StateDeclarationScope $scope  The schema's properties and the lifecycle's declared states.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateStateMap(array $states, StateDeclarationScope $scope): array {
		$errors = [];
		foreach ($states as $state => $block) {
			$site        = new StateConditionSite(state: (string)$state, label: '', properties: $scope->properties);
			$stateErrors = self::unknownStateErrors(state: $site->state, enumSet: $scope->enumSet);
			$errors      = array_merge($errors, ($stateErrors ?? $this->validateStateBlock(block: $block, site: $site)));
		}

		return $errors;
	}//end validateStateMap()

	/**
	 * No errors for an absent `states` block, the refusal for a malformed one, or null for a usable map.
	 *
	 * @param mixed $states The declared `states` block.
	 *
	 * @return array<int, array{code: string, message: string}>|null The errors, or null to go on validating.
	 */
	private static function statesShapeErrors(mixed $states): ?array {
		if ($states === null) {
			return [];
		}

		if (is_array($states) === false || $states === []) {
			$message = 'x-openregister-lifecycle.states must be a non-empty map of state names to blocks.';
			return [self::error(code: 'lifecycle-states-malformed', message: $message)];
		}

		return null;
	}//end statesShapeErrors()

	/**
	 * The schema's declared properties, or an empty map when it declares none.
	 *
	 * @param array<string, mixed> $schema Full schema definition.
	 *
	 * @return array<string, mixed> The declared properties.
	 */
	private static function declaredProperties(array $schema): array {
		if (is_array(($schema['properties'] ?? null)) === true) {
			return $schema['properties'];
		}

		return [];
	}//end declaredProperties()

	/**
	 * The refusal for a state the field's enum does not declare, or null.
	 *
	 * @param string                  $state   The state name.
	 * @param array<string, int>|null $enumSet The declared states, or null when the mode does not publish them.
	 *
	 * @return array<int, array{code: string, message: string}>|null The refusal, or null when the state is known.
	 */
	private static function unknownStateErrors(string $state, ?array $enumSet): ?array {
		if ($enumSet === null || isset($enumSet[$state]) === true) {
			return null;
		}

		$message = sprintf(
			'x-openregister-lifecycle.states declares state "%s" which is not in the field\'s enum.',
			$state
		);
		return [self::error(code: 'lifecycle-state-unknown', message: $message)];
	}//end unknownStateErrors()

	/**
	 * Validate one declared state's block: its field rules, then its conditions.
	 *
	 * @param mixed              $block The state block.
	 * @param StateConditionSite $site  The state and properties the block sits in.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateStateBlock(mixed $block, StateConditionSite $site): array {
		if (is_array($block) === false) {
			$message = sprintf('x-openregister-lifecycle.states."%s" must be an object.', $site->state);
			return [self::error(code: 'lifecycle-state-malformed', message: $message)];
		}

		return array_merge(
			$this->validateStateFields(block: $block, site: $site),
			$this->conditions->validateBlock(block: $block, site: $site)
		);
	}//end validateStateBlock()

	/**
	 * Validate one state's `fields` block.
	 *
	 * @param array<string, mixed> $block The state block.
	 * @param StateConditionSite   $site  The state and properties the block sits in.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateStateFields(array $block, StateConditionSite $site): array {
		$fields = ($block['fields'] ?? null);
		if ($fields === null) {
			return [];
		}

		if (is_array($fields) === false || $fields === []) {
			return [self::fieldsMalformedError(state: $site->state)];
		}

		$errors = [];
		foreach ($fields as $kind => $entries) {
			$scope  = new StateFieldRuleScope(state: $site->state, kind: (string)$kind, properties: $site->properties);
			$errors = array_merge($errors, $this->validateFieldRuleKind(entries: $entries, scope: $scope));
		}

		return $errors;
	}//end validateStateFields()

	/**
	 * The refusal for a `fields` block that is not a non-empty object.
	 *
	 * @param string $state The state name.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private static function fieldsMalformedError(string $state): array {
		$message = sprintf(
			'x-openregister-lifecycle.states."%s".fields must be a non-empty object of '
			. '"hidden", "readOnly" and/or "required" lists.',
			$state
		);
		return self::error(code: 'lifecycle-state-fields-malformed', message: $message);
	}//end fieldsMalformedError()

	/**
	 * Validate the entries one rule kind of a `fields` block declares.
	 *
	 * @param mixed               $entries The entries declared under the kind.
	 * @param StateFieldRuleScope $scope   The state, rule kind and properties the entries sit in.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateFieldRuleKind(mixed $entries, StateFieldRuleScope $scope): array {
		$kindError = self::fieldRuleKindError(entries: $entries, scope: $scope);
		if ($kindError !== null) {
			return [$kindError];
		}

		$errors = [];
		foreach ($entries as $entry) {
			$errors = array_merge($errors, $this->validateFieldRuleEntry(entry: $entry, scope: $scope));
		}

		return $errors;
	}//end validateFieldRuleKind()

	/**
	 * The error for an unknown rule kind or a kind with no entries, or null.
	 *
	 * @param mixed               $entries The entries declared under the kind.
	 * @param StateFieldRuleScope $scope   The state, rule kind and properties the entries sit in.
	 *
	 * @return array{code: string, message: string}|null The error, or null.
	 */
	private static function fieldRuleKindError(mixed $entries, StateFieldRuleScope $scope): ?array {
		$prefix = sprintf('x-openregister-lifecycle.states."%s".fields', $scope->state);
		if (in_array($scope->kind, self::FIELD_RULE_KINDS, true) === false) {
			$allowed = implode(', ', self::FIELD_RULE_KINDS);
			$message = sprintf('%s declares "%s"; only %s are rule kinds.', $prefix, $scope->kind, $allowed);
			return self::error(code: 'lifecycle-state-fields-unknown-kind', message: $message);
		}

		if (is_array($entries) === false || $entries === []) {
			$message = sprintf('%s."%s" must be a non-empty list of entries.', $prefix, $scope->kind);
			return self::error(code: 'lifecycle-state-fields-malformed', message: $message);
		}

		return null;
	}//end fieldRuleKindError()

	/**
	 * Validate one `{fields, groups, when}` entry.
	 *
	 * @param mixed               $entry The declared entry.
	 * @param StateFieldRuleScope $scope The state, rule kind and properties the entry sits in.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateFieldRuleEntry(mixed $entry, StateFieldRuleScope $scope): array {
		$shapeError = $this->fieldRuleEntryShapeError(entry: $entry, scope: $scope);
		if ($shapeError !== null) {
			return [$shapeError];
		}

		$errors = $this->validateFieldRuleNames(names: self::fieldRuleNames(entry: $entry), scope: $scope);

		$groupError = $this->validateFieldRuleGroups(entry: $entry, scope: $scope);
		if ($groupError !== null) {
			$errors[] = $groupError;
		}

		return array_merge($errors, $this->validateFieldRuleCondition(entry: $entry, scope: $scope));
	}//end validateFieldRuleEntry()

	/**
	 * Validate an entry's optional `when` (or `condition`) clause.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 * @param StateFieldRuleScope  $scope The state, rule kind and properties the entry sits in.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateFieldRuleCondition(array $entry, StateFieldRuleScope $scope): array {
		$when = ($entry['when'] ?? ($entry['condition'] ?? null));
		if ($when === null) {
			return [];
		}

		$site = new StateConditionSite(
			state: $scope->state,
			label: sprintf('fields."%s" condition', $scope->kind),
			properties: $scope->properties
		);
		return $this->conditions->validate(condition: $when, site: $site);
	}//end validateFieldRuleCondition()

	/**
	 * The error for an entry that is not an object or names no field, or null.
	 *
	 * @param mixed               $entry The declared entry.
	 * @param StateFieldRuleScope $scope The state, rule kind and properties the entry sits in.
	 *
	 * @return array{code: string, message: string}|null The error, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function fieldRuleEntryShapeError(mixed $entry, StateFieldRuleScope $scope): ?array {
		if (is_array($entry) === false) {
			return [
				'code' => 'lifecycle-state-field-entry-malformed',
				'message' => 'An entry of ' . $scope->path() . ' must be an object with a "fields" list.',
			];
		}

		if (self::fieldRuleNames(entry: $entry) === []) {
			return [
				'code' => 'lifecycle-state-field-entry-malformed',
				'message' => 'An entry of ' . $scope->path() . ' must name at least one field.',
			];
		}

		return null;
	}//end fieldRuleEntryShapeError()

	/**
	 * The field names an entry declares, under `fields` or `field`, as a list.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return array<mixed> The declared names, or an empty list when none are declared.
	 */
	private static function fieldRuleNames(array $entry): array {
		$names = ($entry['fields'] ?? ($entry['field'] ?? null));
		if (is_string($names) === true) {
			return [$names];
		}

		if (is_array($names) === false) {
			return [];
		}

		return $names;
	}//end fieldRuleNames()

	/**
	 * Check every name an entry declares is a string the schema declares as a property.
	 *
	 * @param array<mixed>        $names The declared names.
	 * @param StateFieldRuleScope $scope The state, rule kind and properties the entry sits in.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateFieldRuleNames(array $names, StateFieldRuleScope $scope): array {
		$errors = [];
		foreach ($names as $name) {
			$error = self::fieldRuleNameError(name: $name, scope: $scope);
			if ($error !== null) {
				$errors[] = $error;
			}
		}

		return $errors;
	}//end validateFieldRuleNames()

	/**
	 * The error for a declared name that is not a string or not a declared property, or null.
	 *
	 * @param mixed               $name  The declared name.
	 * @param StateFieldRuleScope $scope The state, rule kind and properties the entry sits in.
	 *
	 * @return array{code: string, message: string}|null The error, or null.
	 */
	private static function fieldRuleNameError(mixed $name, StateFieldRuleScope $scope): ?array {
		if (is_string($name) === false || $name === '') {
			$message = $scope->path() . ' names a field that is not a string.';
			return self::error(code: 'lifecycle-state-field-entry-malformed', message: $message);
		}

		if (isset($scope->properties[$name]) === true) {
			return null;
		}

		$message = sprintf('%s names field "%s", which is not declared in `properties`.', $scope->path(), $name);
		return self::error(code: 'lifecycle-state-field-missing', message: $message);
	}//end fieldRuleNameError()

	/**
	 * Validate an entry's optional `groups` clause.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 * @param StateFieldRuleScope  $scope The state, rule kind and properties the entry sits in.
	 *
	 * @return array{code: string, message: string}|null The error, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateFieldRuleGroups(array $entry, StateFieldRuleScope $scope): ?array {
		$groups = ($entry['groups'] ?? null);
		// Absent (read as '') and a single group id are both well-formed.
		if (is_string(($groups ?? '')) === true) {
			return null;
		}

		if (is_array($groups) === false || $groups === []) {
			$message = $scope->path() . ' declares `groups` that is neither '
				. 'a group id nor a non-empty list of them. Omit `groups` to apply the rule to everyone.';
			return self::error(code: 'lifecycle-state-field-groups-malformed', message: $message);
		}

		return self::groupMemberError(groups: $groups, scope: $scope);
	}//end validateFieldRuleGroups()

	/**
	 * The error for the first listed group that is not a non-empty string, or null.
	 *
	 * @param array<mixed>        $groups The listed groups.
	 * @param StateFieldRuleScope $scope  The state, rule kind and properties the entry sits in.
	 *
	 * @return array{code: string, message: string}|null The error, or null.
	 */
	private static function groupMemberError(array $groups, StateFieldRuleScope $scope): ?array {
		foreach ($groups as $group) {
			if (is_string($group) === false || $group === '') {
				$message = $scope->path() . ' lists a group that is not a non-empty string.';
				return self::error(code: 'lifecycle-state-field-groups-malformed', message: $message);
			}
		}

		return null;
	}//end groupMemberError()

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validateInputsAgainstHiddenFields(array $annotation, mixed $transitions): array {
		$states = ($annotation['states'] ?? null);
		if (is_array($states) === false || is_array($transitions) === false) {
			return [];
		}

		$errors = [];
		foreach ($transitions as $action => $spec) {
			foreach (self::hiddenInputHits(spec: $spec, states: $states) as $hit) {
				$errors[] = self::hiddenInputError(action: (string)$action, hit: $hit);
			}
		}

		return $errors;
	}//end validateInputsAgainstHiddenFields()

	/**
	 * The refusal for a transition asking for an input its target state hides.
	 *
	 * @param string                               $action The transition's action name.
	 * @param array{field: string, target: string} $hit    The hidden input and the state hiding it.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private static function hiddenInputError(string $action, array $hit): array {
		$message = sprintf(
			'Transition "%s" asks for input "%s", which state "%s" hides.',
			$action,
			$hit['field'],
			$hit['target']
		);
		return self::error(code: 'lifecycle-input-hidden-in-target', message: $message);
	}//end hiddenInputError()

	/**
	 * Every input a transition asks for that its target state hides, in declaration order.
	 *
	 * @param mixed                $spec   The declared transition.
	 * @param array<string, mixed> $states The declared state blocks.
	 *
	 * @return array<int, array{field: string, target: string}> The hidden inputs and the state hiding them.
	 */
	private static function hiddenInputHits(mixed $spec, array $states): array {
		if (is_array($spec) === false || is_array(($spec['inputs'] ?? null)) === false) {
			return [];
		}

		$target = (string)($spec['to'] ?? '');
		$hidden = self::hiddenFieldsOf(block: ($states[$target] ?? null));
		$hits   = [];
		foreach ($spec['inputs'] as $input) {
			$field = self::inputFieldName(input: $input);
			if (in_array($field, $hidden, true) === true) {
				$hits[] = ['field' => $field, 'target' => $target];
			}
		}

		return $hits;
	}//end hiddenInputHits()

	/**
	 * The field a declared input asks for, as a bare name or under `field`, or null.
	 *
	 * @param mixed $input The declared input.
	 *
	 * @return string|null The field name.
	 */
	private static function inputFieldName(mixed $input): ?string {
		if (is_string($input) === true) {
			return $input;
		}

		if (is_array($input) === true && is_string(($input['field'] ?? null)) === true) {
			return $input['field'];
		}

		return null;
	}//end inputFieldName()

	/**
	 * Every field name a state block hides, whatever the rule's conditions.
	 *
	 * The check this feeds is a contradiction between two declarations, not a
	 * runtime decision, so a conditionally hidden field counts: the transition
	 * would ask for a field that sometimes vanishes, which is the same mistake.
	 * A malformed entry is skipped rather than thrown on, because this feeds a
	 * cross-check and the shape itself is refused elsewhere.
	 *
	 * @param mixed $block The state block.
	 *
	 * @return array<int, string> The hidden field names.
	 */
	private static function hiddenFieldsOf(mixed $block): array {
		$entries = null;
		if (is_array($block) === true) {
			$entries = ($block['fields']['hidden'] ?? null);
		}

		if (is_array($entries) === false) {
			return [];
		}

		$hidden = [];
		foreach ($entries as $entry) {
			$hidden = array_merge($hidden, self::namedFieldsOf(entry: $entry));
		}

		return $hidden;
	}//end hiddenFieldsOf()

	/**
	 * The non-empty string field names one hidden entry declares.
	 *
	 * @param mixed $entry The declared entry.
	 *
	 * @return array<int, string> The field names, in declaration order.
	 */
	private static function namedFieldsOf(mixed $entry): array {
		if (is_array($entry) === false) {
			return [];
		}

		return array_values(
			array_filter(
				self::fieldRuleNames(entry: $entry),
				static fn (mixed $name): bool => is_string($name) === true && $name !== ''
			)
		);
	}//end namedFieldsOf()

	/**
	 * One validation error.
	 *
	 * @param string $code    The error code.
	 * @param string $message The message a schema author reads.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private static function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()
}//end class
