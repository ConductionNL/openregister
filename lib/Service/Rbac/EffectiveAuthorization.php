<?php

/**
 * The authorization a schema is actually governed by, with roles expanded.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\RegisterMapperInterface;

/**
 * Resolves a schema's effective authorization block.
 *
 * 🔴 TWO THINGS HAPPEN HERE AND BOTH ARE EASY TO LOSE. A schema with no
 * authorization block of its own is governed by its REGISTER's, and a block
 * that names roles rather than actions has to have those roles expanded
 * against the register's role definitions before anything can read it as
 * "who may create". A caller that reads the raw block sees neither, and what
 * it sees is narrower than the truth in the first case and empty in the
 * second.
 *
 * @spec openspec/specs/oas-generation/spec.md
 */
class EffectiveAuthorization {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registerMapper Resolves the register a schema falls back to.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()

	/**
	 * The effective authorization for a schema, with role references expanded.
	 *
	 * If the schema has its own authorization block, use it.
	 * Otherwise, fall back to the parent register's authorization.
	 * Also expands role references to action-level permissions.
	 *
	 * @param object $schema The schema object.
	 *
	 * @return array|null The effective authorization array.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function forSchema(object $schema): ?array {
		$authorization = $schema->getAuthorization();

		// If schema has its own authorization, expand roles and return.
		if ($this->isConfigured(block: $authorization) === true) {
			return $this->expandRoles(authorization: $authorization, schema: $schema);
		}

		return $this->registerAuthorizationFor(schema: $schema);
	}//end forSchema()

	/**
	 * The register's authorization for a schema without its own, with roles expanded.
	 *
	 * @param object $schema The schema object.
	 *
	 * @return array|null The block, or null when no register holds one or it cannot be read.
	 */
	private function registerAuthorizationFor(object $schema): ?array {
		// Fall back to register authorization.
		try {
			$registerId = $this->registerMapper->getFirstRegisterWithSchema(schemaId: $schema->getId());
			if ($registerId !== null) {
				$register = $this->registerMapper->find(id: $registerId);
				$registerAuth = $register->getAuthorization();
				if ($this->isConfigured(block: $registerAuth) === true) {
					return $this->expandRoles(authorization: $registerAuth, schema: $schema, register: $register);
				}
			}
		} catch (\Throwable $e) {
			// Fallback: no register authorization available.
		}

		return null;
	}//end registerAuthorizationFor()

	/**
	 * Whether an authorization value is a non-empty block.
	 *
	 * @param mixed $block The value.
	 *
	 * @return bool True for a non-empty array.
	 */
	private function isConfigured(mixed $block): bool {
		return (is_array($block) === true && empty($block) === false);
	}//end isConfigured()

	/**
	 * Expand role references into the action-level entries they stand for.
	 *
	 * @param array $authorization The authorization block.
	 * @param object $schema The schema object.
	 * @param object|null $register The register object (optional, looked up if needed).
	 *
	 * @return array The authorization with roles expanded.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RoleBlockExpander owns the rule enforcement applies, so the OAS describes the schema the way it is enforced.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function expandRoles(array $authorization, object $schema, ?object $register = null): array {
		if ($this->hasRoleBlock(authorization: $authorization) === false) {
			return $authorization;
		}

		$roleAssignments = $authorization['roles'];
		unset($authorization['roles']);

		// No register, an unreadable one, or one without role definitions
		// leaves an empty map, and the block then grants only what it names
		// outside its roles.
		$roleMap = $this->roleMapFor(schema: $schema, register: $register);

		// Expand roles to action-level entries.
		foreach ($roleAssignments as $roleName => $groups) {
			if (isset($roleMap[$roleName]) === false) {
				continue;
			}

			$authorization = $this->grantRole(authorization: $authorization, actions: $roleMap[$roleName], groups: $groups);
		}

		return RoleBlockExpander::grantNothingWhenEmpty(authorization: $authorization);
	}//end expandRoles()

	/**
	 * Whether a block carries a `roles` assignment list.
	 *
	 * @param array $authorization The authorization block.
	 *
	 * @return bool True when `roles` is set and an array.
	 */
	private function hasRoleBlock(array $authorization): bool {
		return (isset($authorization['roles']) === true && is_array($authorization['roles']) === true);
	}//end hasRoleBlock()

	/**
	 * Role name => actions, from the register's role definitions.
	 *
	 * @param object $schema The schema object.
	 * @param object|null $register The register, or null to look it up from the schema.
	 *
	 * @return array<string, mixed> The role map, empty when there is no register or no definitions.
	 */
	private function roleMapFor(object $schema, ?object $register): array {
		if ($register === null) {
			$register = $this->registerOf(schema: $schema);
		}

		if ($register === null) {
			return [];
		}

		$config = $register->getConfiguration();
		$roles = $config['roles'] ?? [];
		if (empty($roles) === true) {
			return [];
		}

		return $this->roleMapOf(roles: $roles);
	}//end roleMapFor()

	/**
	 * The first register holding a schema, or null when there is none or it cannot be read.
	 *
	 * @param object $schema The schema object.
	 *
	 * @return object|null The register.
	 */
	private function registerOf(object $schema): ?object {
		try {
			$registerId = $this->registerMapper->getFirstRegisterWithSchema($schema->getId());
			if ($registerId === null) {
				return null;
			}

			return $this->registerMapper->find($registerId);
		} catch (\Throwable $e) {
			return null;
		}
	}//end registerOf()

	/**
	 * Build the role map from role definitions, skipping any without a name or actions.
	 *
	 * @param mixed $roles The register's role definitions.
	 *
	 * @return array<string, mixed> Role name => actions.
	 */
	private function roleMapOf(mixed $roles): array {
		$roleMap = [];
		foreach ($roles as $roleDef) {
			if (isset($roleDef['name']) === true && isset($roleDef['actions']) === true) {
				$roleMap[$roleDef['name']] = $roleDef['actions'];
			}
		}

		return $roleMap;
	}//end roleMapOf()

	/**
	 * Add a role's groups to every action the role carries, without duplicates.
	 *
	 * @param array $authorization The authorization block.
	 * @param mixed $actions The role's actions.
	 * @param mixed $groups The groups assigned the role.
	 *
	 * @return array The block with the role's grants added.
	 */
	private function grantRole(array $authorization, mixed $actions, mixed $groups): array {
		foreach ($actions as $action) {
			if (isset($authorization[$action]) === false) {
				$authorization[$action] = [];
			}

			foreach ((array)$groups as $group) {
				if (in_array($group, $authorization[$action], true) === false) {
					$authorization[$action][] = $group;
				}
			}
		}

		return $authorization;
	}//end grantRole()
}//end class
