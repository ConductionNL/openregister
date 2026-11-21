<?php

/**
 * AuthorizationRulesValidator: the save-time check of a schema authorization block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\InvalidAuthorizationRuleException;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;

/**
 * Refuses an authorization block, schema-level or on a property, that names an unknown action or a malformed rule.
 *
 * The vocabulary is CLOSED by default and EXTENSIBLE by declaration: the
 * canonical verbs plus the actions the schema declares under
 * `x-openregister-action`. The gate is the feature rather than a rail bolted
 * onto it: an open vocabulary lets a typo save cleanly as a permission that is
 * never granted and never errors.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class AuthorizationRulesValidator {

	/**
	 * The canonical verbs and the schema's declared actions.
	 *
	 * @var array<int, string>
	 */
	private readonly array $validActions;

	/**
	 * The reserved keys, which are settings rather than verbs.
	 *
	 * @var AuthorizationControlKeys
	 */
	private readonly AuthorizationControlKeys $controls;

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $declaredActions The actions the schema declares.
	 */
	public function __construct(array $declaredActions) {
		$this->validActions = array_merge(array_keys(PermissionCatalogueInterface::CANONICAL), $declaredActions);
		$this->controls = new AuthorizationControlKeys();
	}//end __construct()

	/**
	 * Validate the schema-level block, then every property-level block.
	 *
	 * @param array|null $authorization The schema's authorization block.
	 * @param array|null $properties    The schema's properties.
	 *
	 * @throws InvalidAuthorizationRuleException If a block is invalid
	 *
	 * @return true True if all blocks are valid
	 */
	public function validate(?array $authorization, ?array $properties): bool {
		$this->validateAuthorizationRules(authorization: $authorization, context: 'schema');

		$this->validatePropertyAuthorization(properties: $properties);

		return true;
	}//end validate()

	/**
	 * Reject an authorization block that names an action the vocabulary does not know.
	 *
	 * Keys are either CRUD actions (create, read, update, delete) holding rule
	 * arrays, or reserved non-action keys. Reserved keys are behaviour toggles
	 * read at runtime, not action rule sets, so they skip the action/array
	 * checks and are validated against their own shape:
	 *
	 * - `inheritFromPublic` — a boolean cascade flag.
	 * - `scope` — the default object scope for this schema, from the
	 *   {@see \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver} vocabulary.
	 *
	 * `scope` is validated STRICTLY here even though the runtime treats an
	 * unrecognised value as private. The two are not alternatives: strict
	 * validation gives a schema author an error at authoring time, and the
	 * runtime fail-closed still covers a value that arrived some other way — an
	 * import, a direct write, or a version that knew a scope this one does not.
	 *
	 * @param array|null $authorization The authorization block to check, or null.
	 * @param string     $context       Human-readable location, used in the refusal message.
	 *
	 * @throws InvalidAuthorizationRuleException If the block is invalid
	 *
	 * @return void
	 */
	private function validateAuthorizationRules(?array $authorization, string $context): void {
		if (empty($authorization) === true) {
			return;
		}

		foreach ($authorization as $action => $rules) {
			$entry = new AuthorizationEntry(key: $action, value: $rules, context: $context);
			if ($this->controls->isControlKey(entry: $entry) === true) {
				continue;
			}

			$this->validateActionRules(entry: $entry);
		}
	}//end validateAuthorizationRules()

	/**
	 * Refuse an unknown action, rules that are no list, or a malformed rule.
	 *
	 * @param AuthorizationEntry $entry The action, the rules listed for it and the human-readable
	 *                                  location used in the refusal message.
	 *
	 * @throws InvalidAuthorizationRuleException If the action or a rule is invalid
	 *
	 * @return void
	 */
	private function validateActionRules(AuthorizationEntry $entry): void {
		if (in_array($entry->key, $this->validActions) === false) {
			$validList = implode(', ', $this->validActions);
			$msg = "Invalid authorization action '{$entry->key}' in {$entry->context}. Must be one of: {$validList}";
			throw new InvalidAuthorizationRuleException(message: $msg);
		}

		// Validate rules is an array.
		if (is_array($entry->value) === false) {
			throw new InvalidAuthorizationRuleException(
				message: "Authorization rules for {$entry->where()} must be an array"
			);
		}

		// Validate each rule is either a string (simple) or a valid conditional object.
		foreach ($entry->value as $rule) {
			$this->validateAuthorizationRule(rule: $rule, where: $entry->where());
		}
	}//end validateActionRules()

	/**
	 * Validate every property-level authorization block.
	 *
	 * @param array|null $properties The schema's properties.
	 *
	 * @throws InvalidAuthorizationRuleException If a property block is invalid
	 *
	 * @return void
	 */
	private function validatePropertyAuthorization(?array $properties): void {
		if (empty($properties) === true) {
			return;
		}

		foreach ($properties as $propertyName => $propertyConfig) {
			$this->validatePropertyBlock(propertyName: $propertyName, propertyConfig: $propertyConfig);
		}
	}//end validatePropertyAuthorization()

	/**
	 * Validate one property's authorization block, when it has one.
	 *
	 * @param int|string $propertyName   The property.
	 * @param mixed      $propertyConfig Its configuration.
	 *
	 * @throws InvalidAuthorizationRuleException If the block is invalid
	 *
	 * @return void
	 */
	private function validatePropertyBlock(int|string $propertyName, mixed $propertyConfig): void {
		if (is_array($propertyConfig) === false) {
			return;
		}

		$authorization = $propertyConfig['authorization'] ?? null;
		if (empty($authorization) === true) {
			return;
		}

		if (is_array($authorization) === false) {
			throw new InvalidAuthorizationRuleException(
				message: "Authorization for property '{$propertyName}' must be an array"
			);
		}

		$this->validateAuthorizationRules(authorization: $authorization, context: "property '{$propertyName}'");
	}//end validatePropertyBlock()

	/**
	 * Validate a single authorization rule: a group id, or a conditional object.
	 *
	 * @param mixed  $rule    The rule to validate
	 * @param string $where   The action this rule is for and its context (for error messages)
	 *
	 * @throws InvalidAuthorizationRuleException If the rule is invalid
	 *
	 * @return void
	 */
	private function validateAuthorizationRule(mixed $rule, string $where): void {
		// Simple rule: non-empty string (group name).
		if (is_string($rule) === true) {
			$this->validateGroupRule(rule: $rule, where: $where);
			return;
		}

		// Conditional rule: array with 'group' key.
		if (is_array($rule) === true) {
			$this->validateConditionalRule(rule: $rule, where: $where);
			return;
		}

		// Invalid rule type.
		throw new InvalidAuthorizationRuleException(
			message: "Authorization rule for {$where} must be a string or conditional object"
		);
	}//end validateAuthorizationRule()

	/**
	 * Refuse a simple rule that is an empty group id.
	 *
	 * @param string $rule    The group id.
	 * @param string $where   The action this rule is for and its context (for error messages)
	 *
	 * @throws InvalidAuthorizationRuleException If the group id is empty
	 *
	 * @return void
	 */
	private function validateGroupRule(string $rule, string $where): void {
		if (trim($rule) === '') {
			throw new InvalidAuthorizationRuleException(
				message: "Group ID in authorization for {$where} must be a non-empty string"
			);
		}
	}//end validateGroupRule()

	/**
	 * Validate a conditional rule: its group, its match and its cross-organisation marker.
	 *
	 * @param array  $rule    The rule.
	 * @param string $where   The action this rule is for and its context (for error messages)
	 *
	 * @throws InvalidAuthorizationRuleException If the rule is invalid
	 *
	 * @return void
	 */
	private function validateConditionalRule(array $rule, string $where): void {
		$this->validateRuleGroup(rule: $rule, where: $where);

		// Validate 'match' key if present.
		if (isset($rule['match']) === true) {
			if (is_array($rule['match']) === false) {
				throw new InvalidAuthorizationRuleException(
					message: "Conditional authorization 'match' for {$where} must be an array"
				);
			}

			$this->validateMatchOperators(match: $rule['match'], where: $where);
		}

		$this->validateCrossOrganisationMarker(rule: $rule, where: "for {$where}");
	}//end validateConditionalRule()

	/**
	 * Refuse a conditional rule without a non-empty string `group`.
	 *
	 * @param array  $rule    The rule.
	 * @param string $where   The action this rule is for and its context (for error messages)
	 *
	 * @throws InvalidAuthorizationRuleException If the group is missing or empty
	 *
	 * @return void
	 */
	private function validateRuleGroup(array $rule, string $where): void {
		if (isset($rule['group']) === false) {
			throw new InvalidAuthorizationRuleException(
				message: "Conditional authorization rule for {$where} must have a 'group' key"
			);
		}

		if (is_string($rule['group']) === false || trim($rule['group']) === '') {
			throw new InvalidAuthorizationRuleException(
				message: "Conditional authorization 'group' for {$where} must be a non-empty string"
			);
		}
	}//end validateRuleGroup()

	/**
	 * Refuse a `crossOrganisation` marker that is not a boolean.
	 *
	 * The marker says a rule may read other organisations' rows; anything but
	 * true or false would leave a reader guessing which it meant.
	 *
	 * @param array  $rule  The conditional rule.
	 * @param string $where Where the rule sits, for the refusal message.
	 *
	 * @throws InvalidAuthorizationRuleException If the marker is not a boolean
	 *
	 * @return void
	 */
	private function validateCrossOrganisationMarker(array $rule, string $where): void {
		if (array_key_exists('crossOrganisation', $rule) === true && is_bool($rule['crossOrganisation']) === false) {
			throw new InvalidAuthorizationRuleException(
				message: "Authorization 'crossOrganisation' {$where} must be true or false;"
					. ' set it to true only on a rule that may read other organisations\' rows'
			);
		}
	}//end validateCrossOrganisationMarker()

	/**
	 * Validate the `$` operators of a match, property by property.
	 *
	 * @param array  $match The match block.
	 * @param string $where The action and context, for the refusal message.
	 *
	 * @throws InvalidAuthorizationRuleException If an operator is unsupported or its operand malformed
	 *
	 * @return void
	 */
	private function validateMatchOperators(array $match, string $where): void {
		foreach ($match as $property => $value) {
			if (is_array($value) === true) {
				$this->validatePropertyOperators(operators: $value, where: "{$where}, property '{$property}'");
			}
		}
	}//end validateMatchOperators()

	/**
	 * Validate the `$` operators matched on one property.
	 *
	 * @param array  $operators The operator map of one property.
	 * @param string $where     The action, context and property, for the refusal message.
	 *
	 * @throws InvalidAuthorizationRuleException If an operator is unsupported or its operand malformed
	 *
	 * @return void
	 */
	private function validatePropertyOperators(array $operators, string $where): void {
		foreach ($operators as $operator => $operand) {
			if (is_string($operator) === true && str_starts_with($operator, '$') === true) {
				$this->validateMatchOperator(operator: new AuthorizationEntry(key: $operator, value: $operand, context: $where));
			}
		}
	}//end validatePropertyOperators()

	/**
	 * Refuse an operator outside Schema::MATCH_OPERATORS, and a `$in`/`$nin` operand that is no list.
	 *
	 * @param AuthorizationEntry $operator The operator, its operand and where it sits, for the refusal message.
	 *
	 * @throws InvalidAuthorizationRuleException If the operator is unsupported or its operand malformed
	 *
	 * @return void
	 */
	private function validateMatchOperator(AuthorizationEntry $operator): void {
		$name    = (string)$operator->key;
		$operand = $operator->value;
		$this->assertSupportedOperator(operator: $name, where: $operator->context);

		if ($this->needsListOperand(operator: $name, operand: $operand) === false) {
			return;
		}

		if (is_array($operand) === false || array_is_list($operand) === false) {
			throw new InvalidAuthorizationRuleException(
				message: "Authorization match for {$operator->context} needs a list as the '{$name}' operand"
			);
		}
	}//end validateMatchOperator()

	/**
	 * Refuse an operator outside Schema::MATCH_OPERATORS.
	 *
	 * @param string $operator The operator.
	 * @param string $where    Where it sits, for the refusal message.
	 *
	 * @throws InvalidAuthorizationRuleException If the operator is unsupported
	 *
	 * @return void
	 */
	private function assertSupportedOperator(string $operator, string $where): void {
		if (in_array($operator, Schema::MATCH_OPERATORS, true) === false) {
			throw new InvalidAuthorizationRuleException(
				message: "Authorization match for {$where} uses the unsupported operator '{$operator}'; supported are "
				.implode(', ', Schema::MATCH_OPERATORS)
			);
		}
	}//end assertSupportedOperator()

	/**
	 * Whether the operand must be a list: a `$in` or `$nin` that is no dynamic token.
	 *
	 * A dynamic token such as `$user.groups` resolves to a list at runtime.
	 *
	 * @param string $operator The operator.
	 * @param mixed  $operand  Its operand.
	 *
	 * @return bool
	 */
	private function needsListOperand(string $operator, mixed $operand): bool {
		if ($operator !== '$in' && $operator !== '$nin') {
			return false;
		}

		return (is_string($operand) === true && str_starts_with($operand, '$') === true) === false;
	}//end needsListOperand()
}//end class
