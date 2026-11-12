<?php

/**
 * RoleBlockExpander - turns the `roles` of an authorization block into action-level entries
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use Psr\Log\LoggerInterface;

/**
 * Expands one block against a register's role definitions.
 *
 * A role definition can declare `extends: <otherRoleName>` to inherit that role's
 * action set. Schemas keep their compact `roles: {assignedRole: [groups]}` syntax,
 * and the resolved action set composes inherited and own actions.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RoleBlockExpander {

	/**
	 * The role definitions of the current expansion, keyed by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $roles = [];

	/**
	 * The flattened actions of each role of the current expansion.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $roleActions = [];

	/**
	 * The block being expanded.
	 *
	 * @var array<string, mixed>
	 */
	private array $block = [];

	/**
	 * Create the expander.
	 *
	 * @param LoggerInterface $logger   Receives the warnings on bad definitions.
	 * @param int|null        $schemaId The schema, for diagnostic context.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?int $schemaId,
	) {
	}//end __construct()

	/**
	 * Replace the block's `roles` with the action-level entries they stand for.
	 *
	 * @param array $authorization The block, its `roles` an array.
	 * @param array $definitions   The register's role definitions.
	 *
	 * @return array The block with its roles expanded.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function expand(array $authorization, array $definitions): array {
		$assignments = $authorization['roles'];
		unset($authorization['roles']);
		$this->block = $authorization;

		if (empty($definitions) === true) {
			$this->warn(message: 'Schema has role references but register has no role definitions', context: []);
			return self::grantNothingWhenEmpty(authorization: $authorization);
		}

		$this->roles = $this->rolesByName(definitions: $definitions);
		$this->roleActions = $this->flattenedRoles();
		foreach ($assignments as $roleName => $groups) {
			$this->assign(roleName: $roleName, groups: $groups);
		}

		return self::grantNothingWhenEmpty(authorization: $this->block);
	}//end expand()

	/**
	 * Add the groups of one role assignment to every action of the role.
	 *
	 * @param int|string $roleName The assigned role.
	 * @param mixed      $groups   The group, or the list of groups, it is assigned to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function assign(int|string $roleName, mixed $groups): void {
		if (isset($this->roleActions[$roleName]) === false) {
			$this->warn(message: 'Unknown role name referenced in authorization', context: ['roleName' => $roleName]);
			return;
		}

		foreach ($this->roleActions[$roleName] as $action) {
			$this->block[$action] = $this->withoutDuplicates(list: ($this->block[$action] ?? []), added: (array) $groups);
		}
	}//end assign()

	/**
	 * The named role definitions; a later definition of a name replaces an earlier one.
	 *
	 * @param array $definitions The register's role definitions.
	 *
	 * @return array<string, array<string, mixed>> The definitions keyed by name.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function rolesByName(array $definitions): array {
		$roles = [];
		foreach ($definitions as $definition) {
			if (isset($definition['name']) === true) {
				$roles[$definition['name']] = $definition;
			}
		}

		return $roles;
	}//end rolesByName()

	/**
	 * Flatten the role hierarchy into a `name => actions` map.
	 *
	 * Supports an optional `extends: <roleName>` (string) or `extends: [<roleName>, ...]`
	 * (array of role names) on each role definition. Inherited actions are merged with the
	 * role's own actions; cycles abort safely (a warned shorter chain wins) so a
	 * misconfigured register can't deadlock the request.
	 *
	 * @return array<string, array<int, string>> Flat `name => actions` map.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function flattenedRoles(): array {
		$resolved = [];
		foreach (array_keys($this->roles) as $roleName) {
			$resolved[$roleName] = $this->actionsOf(roleName: $roleName, visited: []);
		}

		return $resolved;
	}//end flattenedRoles()

	/**
	 * Walk the `extends` chain of a role and return the combined action set.
	 *
	 * @param string              $roleName Name of the role being resolved.
	 * @param array<string, true> $visited  Roles already visited in this chain (cycle guard).
	 *
	 * @return array<int, string> Deduplicated action list, inherited actions first.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function actionsOf(string $roleName, array $visited): array {
		if (isset($this->roles[$roleName]) === false) {
			return [];
		}

		if (isset($visited[$roleName]) === true) {
			$this->warn(message: 'Cyclic role-hierarchy reference; ignoring repeat', context: ['roleName' => $roleName]);
			return [];
		}

		$visited[$roleName] = true;
		$actions = $this->inheritedActions(roleName: $roleName, visited: $visited);

		return $this->withoutDuplicates(list: $actions, added: $this->ownActions(roleName: $roleName));
	}//end actionsOf()

	/**
	 * The actions a role takes over from the roles it extends.
	 *
	 * @param string              $roleName Name of the role being resolved.
	 * @param array<string, true> $visited  Roles visited in this chain, this role included.
	 *
	 * @return array<int, string> Deduplicated action list.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function inheritedActions(string $roleName, array $visited): array {
		$actions = [];
		foreach ((array) ($this->roles[$roleName]['extends'] ?? []) as $parent) {
			if (is_string($parent) === true && $parent !== '') {
				$inherited = $this->actionsOf(roleName: $parent, visited: $visited);
				$actions   = $this->withoutDuplicates(list: $actions, added: $inherited);
			}
		}

		return $actions;
	}//end inheritedActions()

	/**
	 * The actions a role declares itself; anything but a string is left out.
	 *
	 * @param string $roleName Name of the role being resolved.
	 *
	 * @return array<int|string, string> The declared actions.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function ownActions(string $roleName): array {
		$actions = ($this->roles[$roleName]['actions'] ?? []);
		if (is_array($actions) === false) {
			return [];
		}

		return array_filter($actions, 'is_string');
	}//end ownActions()

	/**
	 * The list with each added value appended unless it is already there.
	 *
	 * @param array $list  The list so far.
	 * @param array $added The values to add.
	 *
	 * @return array The merged list.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function withoutDuplicates(array $list, array $added): array {
		foreach ($added as $value) {
			if (in_array($value, $list, true) === false) {
				$list[] = $value;
			}
		}

		return $list;
	}//end withoutDuplicates()

	/**
	 * A roles block that resolved to nothing grants nothing.
	 *
	 * 🔴 THE SAME DIRECTION AS {@see \OCA\OpenRegister\Service\Object\PermissionHandler::stripMcpScope()},
	 * FOR THE SAME REASON. An author who writes `roles` has opted the schema into
	 * authorization. When none of those roles can be expanded (the register declares no
	 * role definitions, or every name is unknown) the block used to come back as `[]`,
	 * and `[]` is "no authorization configured", which is default-OPEN: every signed in
	 * account could read, change and delete. Found live on 2026-10-11, where a case
	 * schema declaring `roles` only, written so a notification dispatcher could find who
	 * holds a role, left every case of the register readable by a user in no group at all.
	 *
	 * Between a fail-safe and a fail-open reading of an ambiguous block the block gets
	 * the fail-safe one: the canonical verbs are listed with nobody in them. The block is
	 * non-empty, so an unlisted verb is refused as well, and the administrator and owner
	 * bypasses still apply. A denial is loud and fixed by declaring the roles; a grant is
	 * silent. EffectiveAuthorization reads the same rule, so the OAS describes the schema
	 * the way it is enforced.
	 *
	 * @param array<string, mixed> $authorization The block after expansion.
	 *
	 * @return array<string, mixed> The block, or one granting nobody when it is empty.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function grantNothingWhenEmpty(array $authorization): array {
		if ($authorization !== []) {
			return $authorization;
		}

		return [
			'read'   => [],
			'create' => [],
			'update' => [],
			'delete' => [],
		];
	}//end grantNothingWhenEmpty()

	/**
	 * Log a warning about the definitions, naming the schema.
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context Further context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function warn(string $message, array $context): void {
		$this->logger->warning(
			message: '[PermissionHandler] '.$message,
			context: array_merge(['file' => __FILE__, 'line' => __LINE__], $context, ['schemaId' => $this->schemaId])
		);
	}//end warn()
}//end class
