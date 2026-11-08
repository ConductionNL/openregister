<?php

/**
 * AuthorizationControlKeys: the control keys of a schema authorization block.
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
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;

/**
 * Validates the reserved keys of an authorization block, which grant nothing.
 *
 * Reserved keys are cascade flags, the schema's default object scope, the
 * role-to-groups assignment and the explicit-reveal marker. None of them is an
 * action rule set: none grants anything. Keeping them in one place is what
 * lets the ACTION vocabulary stay closed, which is the property that makes a
 * typo an error rather than a rule that silently protects nothing.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class AuthorizationControlKeys {

	/**
	 * The boolean cascade flags.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED_FLAGS = ['inheritFromPublic'];

	/**
	 * Validate one reserved authorization key, if this is one.
	 *
	 * @param string $action  The authorization key.
	 * @param mixed  $value   Its value.
	 * @param string $context Context for error messages.
	 *
	 * @throws InvalidAuthorizationRuleException When a reserved key carries the wrong shape.
	 *
	 * @return bool TRUE when the key was reserved and has been validated.
	 */
	public function isControlKey(string $action, mixed $value, string $context): bool {
		if ($this->validatesKnownKey(action: $action, value: $value, context: $context) === true) {
			return true;
		}

		// 🔴 EVERY OTHER CONTROL KEY, taken from the ONE list that already
		// names them. `PermissionCatalogue::CONTROL_KEYS` exists precisely to
		// say which keys of an authorization block are settings rather than
		// verbs, and it carries two comments recording what happens when a
		// control key is read as a verb. This method had its own private copy
		// of that knowledge — three keys of it — so `matrix`, `deny`, `public`
		// and the token-grant marker fell through to the CRUD-verb check and
		// the SAVE was refused with "Invalid authorization action 'matrix'".
		// Measured on a live instance 2026-09-19: the whole of
		// rbac-department-role-matrix was unreachable over HTTP for that
		// reason, while every unit test of the compiler passed because none of
		// them crosses this validator.
		//
		// Reading the list instead of repeating it is the fix, not adding four
		// names: a fifth control key added to the catalogue tomorrow would
		// otherwise break the save again in exactly this way.
		//
		// Accepting the key here is not accepting its SHAPE. Each control has
		// its own validator at save time — SchemaMapper::validateDepartmentMatrix()
		// for the matrix, AuthorizationDenyValidator for `deny` — and those
		// refuse a malformed block with a message about the block.
		// `scope` and `roles` are answered above with their own validators, so
		// they come out of the list here rather than being tested twice. Psalm
		// reads the constant and calls the second test a paradox otherwise,
		// and it is right: the branch could never be taken for those two.
		return in_array($action, $this->unvalidatedKeys(), true);
	}//end isControlKey()

	/**
	 * The control keys accepted without a check of their own here.
	 *
	 * `scope`, `roles` and `reveal` are answered with their own validators, so
	 * they come out of the list rather than being tested twice.
	 *
	 * @return array<int, string>
	 */
	private function unvalidatedKeys(): array {
		return array_values(
			array_diff(
				PermissionCatalogueInterface::CONTROL_KEYS,
				[ObjectScopeResolver::SCOPE_KEY, Schema::ROLES_KEY, ExplicitReveal::KEY]
			)
		);
	}//end unvalidatedKeys()

	/**
	 * Validate a cascade flag or the scope, when the key is one of them.
	 *
	 * @param string $action  The authorization key.
	 * @param mixed  $value   Its value.
	 * @param string $context Context for error messages.
	 *
	 * @throws InvalidAuthorizationRuleException When the flag or scope carries the wrong shape.
	 *
	 * @return bool TRUE when the key was one of these and has been validated.
	 */
	private function validatesKnownKey(string $action, mixed $value, string $context): bool {
		if (in_array($action, self::RESERVED_FLAGS, true) === true) {
			if (is_bool($value) === false) {
				throw new InvalidAuthorizationRuleException(
					message: "Authorization flag '{$action}' in {$context} must be a boolean"
				);
			}

			return true;
		}

		if ($action === ObjectScopeResolver::SCOPE_KEY) {
			$this->validateScopeValue(scope: $value, context: $context);
			return true;
		}

		return $this->validatesAssignmentKey(action: $action, value: $value, context: $context);
	}//end validatesKnownKey()

	/**
	 * Validate the role assignment or the reveal marker, when the key is one of them.
	 *
	 * @param string $action  The authorization key.
	 * @param mixed  $value   Its value.
	 * @param string $context Context for error messages.
	 *
	 * @throws InvalidAuthorizationRuleException When the assignment or marker carries the wrong shape.
	 *
	 * @return bool TRUE when the key was one of these and has been validated.
	 */
	private function validatesAssignmentKey(string $action, mixed $value, string $context): bool {
		if ($action === Schema::ROLES_KEY) {
			$this->validateRolesAssignment(roles: $value, context: $context);
			return true;
		}

		if ($action === ExplicitReveal::KEY) {
			$this->validateRevealValue(reveal: $value, context: $context);
			return true;
		}

		return false;
	}//end validatesAssignmentKey()

	/**
	 * Refuse a role assignment that is not a map of role name to group ids.
	 *
	 * @param mixed  $roles   The `roles` value.
	 * @param string $context Context for error messages.
	 *
	 * @throws InvalidAuthorizationRuleException When the assignment carries the wrong shape.
	 *
	 * @return void
	 */
	private function validateRolesAssignment(mixed $roles, string $context): void {
		if (is_array($roles) === false) {
			throw new InvalidAuthorizationRuleException(
				message: "Authorization '" . Schema::ROLES_KEY . "' in {$context} must be a map of role name to group ids"
			);
		}

		foreach ($roles as $roleName => $groups) {
			if (is_string($roleName) === false || trim($roleName) === '') {
				throw new InvalidAuthorizationRuleException(
					message: "Authorization '" . Schema::ROLES_KEY . "' in {$context} names a role with no name"
				);
			}

			$this->validateRoleGroups(roleName: $roleName, groups: $groups, context: $context);
		}
	}//end validateRolesAssignment()

	/**
	 * Refuse a role that lists no group, or a group id that is not a non-empty string.
	 *
	 * @param string $roleName The role.
	 * @param mixed  $groups   The groups it lists.
	 * @param string $context  Context for error messages.
	 *
	 * @throws InvalidAuthorizationRuleException When the groups carry the wrong shape.
	 *
	 * @return void
	 */
	private function validateRoleGroups(string $roleName, mixed $groups, string $context): void {
		if (is_array($groups) === false || $groups === []) {
			throw new InvalidAuthorizationRuleException(
				message: "Role '{$roleName}' in {$context} must list at least one group id"
			);
		}

		foreach ($groups as $group) {
			$this->validateRoleGroup(roleName: $roleName, group: $group, context: $context);
		}
	}//end validateRoleGroups()

	/**
	 * Refuse a group id that is not a non-empty string.
	 *
	 * @param string $roleName The role.
	 * @param mixed  $group    One group it lists.
	 * @param string $context  Context for error messages.
	 *
	 * @throws InvalidAuthorizationRuleException When the group id carries the wrong shape.
	 *
	 * @return void
	 */
	private function validateRoleGroup(string $roleName, mixed $group, string $context): void {
		if (is_string($group) === false || trim($group) === '') {
			throw new InvalidAuthorizationRuleException(
				message: "Role '{$roleName}' in {$context} lists a group id that is not a non-empty string"
			);
		}
	}//end validateRoleGroup()

	/**
	 * Refuse a scope outside the vocabulary the runtime resolves it with.
	 *
	 * @param mixed  $scope   The `scope` value.
	 * @param string $context Context for error messages.
	 *
	 * @throws InvalidAuthorizationRuleException When the scope is unknown.
	 *
	 * @return void
	 */
	private function validateScopeValue(mixed $scope, string $context): void {
		$validScopes = [ObjectScopeResolver::SCOPE_ORGANISATION, ObjectScopeResolver::SCOPE_PRIVATE];

		if (in_array($scope, $validScopes, true) === false) {
			$scopeList = implode(', ', $validScopes);
			throw new InvalidAuthorizationRuleException(
				message: "Authorization scope in {$context} must be one of: {$scopeList}"
			);
		}
	}//end validateScopeValue()

	/**
	 * Refuse a reveal marker other than the one explicit value.
	 *
	 * @param mixed  $reveal  The `reveal` value.
	 * @param string $context Context for error messages.
	 *
	 * @throws InvalidAuthorizationRuleException When the marker is not `explicit`.
	 *
	 * @return void
	 */
	private function validateRevealValue(mixed $reveal, string $context): void {
		if ($reveal !== ExplicitReveal::EXPLICIT) {
			throw new InvalidAuthorizationRuleException(
				message: "Authorization key '" . ExplicitReveal::KEY . "' in {$context} must be \"" . ExplicitReveal::EXPLICIT . '"'
			);
		}
	}//end validateRevealValue()
}//end class
