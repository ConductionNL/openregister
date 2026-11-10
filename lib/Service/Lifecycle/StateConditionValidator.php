<?php

/**
 * OpenRegister StateConditionValidator
 *
 * Schema-save validation of the conditions `x-openregister-lifecycle.states`
 * declares: a state's `entry`, `exit` and `condition`, and a field rule's `when`.
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
 * Shape-checks a state condition and refuses every property it reads that the schema does not declare.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class StateConditionValidator {

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
	 * Validate a state's `entry`, `exit` and `condition` conditions.
	 *
	 * @param array<string, mixed> $block The state block.
	 * @param StateConditionSite   $site  The state and properties the block sits in.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validateBlock(array $block, StateConditionSite $site): array {
		$errors = [];
		foreach (['entry', 'exit', 'condition'] as $key) {
			if (isset($block[$key]) === false) {
				continue;
			}

			$condition = self::unwrapCondition(declared: $block[$key]);
			$errors    = array_merge($errors, $this->validate(condition: $condition, site: $site->withLabel(label: $key)));
		}

		return $errors;
	}//end validateBlock()

	/**
	 * Shape-check one condition and check every property it names.
	 *
	 * @param mixed              $condition The declared condition node.
	 * @param StateConditionSite $site      The state, label and properties the condition sits in.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(mixed $condition, StateConditionSite $site): array {
		if (is_array($condition) === false || $condition === []) {
			return [self::malformedError(site: $site)];
		}

		$errors = [];
		foreach ($this->propertyReferences(node: $condition) as $reference) {
			if (isset($site->properties[$reference]) === false) {
				$errors[] = self::propertyMissingError(site: $site, reference: $reference);
			}
		}

		return $errors;
	}//end validate()

	/**
	 * The condition a declared `entry`/`exit` holds, unwrapping a `{condition}` or `{when}` wrapper.
	 *
	 * @param mixed $declared The declared value.
	 *
	 * @return mixed The condition node.
	 */
	private static function unwrapCondition(mixed $declared): mixed {
		if (is_array($declared) === false) {
			return $declared;
		}

		$nested = ($declared['condition'] ?? ($declared['when'] ?? null));
		if (is_array($nested) === true && $nested !== []) {
			return $nested;
		}

		return $declared;
	}//end unwrapCondition()

	/**
	 * The refusal for a condition that is not a non-empty object.
	 *
	 * @param StateConditionSite $site The state, label and properties the condition sits in.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private static function malformedError(StateConditionSite $site): array {
		$message = sprintf(
			'x-openregister-lifecycle.states."%s" %s must be a non-empty condition object. '
			. 'A scalar is refused: it would evaluate as a literal and let every write through.',
			$site->state,
			$site->label
		);
		return ['code' => 'lifecycle-state-condition-malformed', 'message' => $message];
	}//end malformedError()

	/**
	 * The refusal for a condition reading a property the schema does not declare.
	 *
	 * @param StateConditionSite $site      The state, label and properties the condition sits in.
	 * @param string             $reference The property the condition reads.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private static function propertyMissingError(StateConditionSite $site, string $reference): array {
		$message = sprintf(
			'x-openregister-lifecycle.states."%s" %s reads property "%s", which is not declared in `properties`.',
			$site->state,
			$site->label,
			$reference
		);
		return ['code' => 'lifecycle-state-condition-property-missing', 'message' => $message];
	}//end propertyMissingError()

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
	private function propertyReferences(mixed $node): array {
		$names = array_filter($this->referencesIn(node: $node), static fn (?string $name): bool => $name !== null);
		return array_values(array_unique($names));
	}//end propertyReferences()

	/**
	 * Walk a condition node, listing the property each reference names (null when it names none).
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return array<int, string|null> The names, in walk order.
	 */
	private function referencesIn(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$found = [];
		foreach ($node as $key => $value) {
			$found = array_merge($found, $this->referencesOfEntry(key: $key, value: $value));
		}

		return $found;
	}//end referencesIn()

	/**
	 * The name one node entry references, or the names under it when it is no reference.
	 *
	 * @param int|string $key   The node key.
	 * @param mixed      $value The node value.
	 *
	 * @return array<int, string|null> The names, in walk order.
	 */
	private function referencesOfEntry(int|string $key, mixed $value): array {
		$reference = self::referenceOf(key: $key, value: $value);
		if ($reference === null) {
			return $this->referencesIn(node: $value);
		}

		return [self::propertyNameOf(reference: $reference)];
	}//end referencesOfEntry()

	/**
	 * The reference path a `var` or `prop` node declares, in its scalar or list spelling, or null.
	 *
	 * @param int|string $key   The node key.
	 * @param mixed      $value The node value.
	 *
	 * @return string|null The reference path, or null when the node is not a reference.
	 */
	private static function referenceOf(int|string $key, mixed $value): ?string {
		if (in_array($key, ['var', 'prop'], true) === false) {
			return null;
		}

		$path = $value;
		if (is_array($value) === true) {
			$path = ($value[0] ?? null);
		}

		if (is_string($path) === true) {
			return $path;
		}

		return null;
	}//end referenceOf()

	/**
	 * The schema property a reference path names, or null when it names none.
	 *
	 * @param string $reference The reference path as declared.
	 *
	 * @return string|null The property name.
	 */
	private static function propertyNameOf(string $reference): ?string {
		$segments = explode('.', trim($reference));
		$root     = array_shift($segments);
		if (in_array($root, self::CONDITION_NAMESPACES, true) === true) {
			$root = self::objectPropertyRoot(root: $root, segments: $segments);
		}

		if ($root === null || self::isBuiltInName(name: $root) === true) {
			return null;
		}

		return $root;
	}//end propertyNameOf()

	/**
	 * The property under an `object.` reference, or null for any other namespace.
	 *
	 * @param string             $root     The namespace root.
	 * @param array<int, string> $segments The path segments after the root.
	 *
	 * @return string|null The property segment.
	 */
	private static function objectPropertyRoot(string $root, array $segments): ?string {
		if ($root !== 'object' || $segments === []) {
			return null;
		}

		return $segments[0];
	}//end objectPropertyRoot()

	/**
	 * Whether a path segment is empty or a built-in (`@` or `_` prefixed) name.
	 *
	 * @param string $name The path segment.
	 *
	 * @return bool True when the segment names no schema property.
	 */
	private static function isBuiltInName(string $name): bool {
		return $name === '' || str_starts_with($name, '@') === true || str_starts_with($name, '_') === true;
	}//end isBuiltInName()
}//end class
