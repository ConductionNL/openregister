<?php

/**
 * OpenRegister — RBAC Group Collector
 *
 * Collects every Nextcloud group id referenced by an OAS-shaped configuration
 * document: the DERIVED floor (groups named in register/schema/property
 * `authorization` blocks) unioned with the AUTHORED superset (the OAuth2 scope
 * map at `components.securitySchemes.oauth2.flows.authorizationCode.scopes`,
 * the slot {@see \OCA\OpenRegister\Service\OasService} already emits).
 *
 * Group ids are free-form and deliberately UNPREFIXED — two apps that both
 * declare `behandelaars` converge on one Nextcloud group by design, so a
 * declaring app must never assume it owns a group it declared.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * Collects declared RBAC group ids from OAS-shaped configuration documents.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class RbacGroupCollector {

	/**
	 * Principals that are never provisionable as Nextcloud groups.
	 *
	 * `admin` is Nextcloud's built-in administrator group — it always exists and
	 * is short-circuited before any group test in
	 * {@see \OCA\OpenRegister\Service\Object\PermissionHandler::hasGroupPermission()}.
	 * `public` is a PSEUDO-principal denoting anonymous access; creating a real
	 * Nextcloud group named `public` would be meaningless at best and would
	 * suggest a membership-based grant that RBAC never consults.
	 *
	 * @var string[]
	 */
	public const RESERVED_PRINCIPALS = ['admin', 'public'];

	/**
	 * Authorization keys that hold a role-name => group(s) map rather than an
	 * action => rules list.
	 *
	 * @var string
	 */
	private const ROLES_KEY = 'roles';

	/**
	 * Collect every group id referenced anywhere in an OAS-shaped document.
	 *
	 * Unions the derived floor (register + schema + property authorization) with
	 * the authored scope map, so a group that is declared but not yet referenced
	 * by any authorization block is still provisioned.
	 *
	 * @param array<string, mixed> $document The decoded configuration document.
	 *
	 * @return string[] Unique group ids, in first-seen order, reserved principals removed.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fromDocument(array $document): array {
		$groups = array_merge(
			$this->fromAuthorizationOwners(owners: ($document['components']['registers'] ?? [])),
			$this->fromSchemaDefinitions(schemas: ($document['components']['schemas'] ?? [])),
			$this->fromScopeMap(document: $document)
		);

		return $this->provisionable(groups: $groups);
	}//end fromDocument()

	/**
	 * Read the AUTHORED scope map from the document's OAS security scheme.
	 *
	 * The scope map's KEYS are group ids; its values are human-readable
	 * descriptions surfaced in Swagger UI.
	 *
	 * @param array<string, mixed> $document The decoded configuration document.
	 *
	 * @return string[] Group ids declared in the scope map (unfiltered).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fromScopeMap(array $document): array {
		$scopes = ($document['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] ?? null);
		if (is_array($scopes) === false) {
			return [];
		}

		return array_values(array_filter(array_keys($scopes), static fn ($key) => is_string($key) === true));
	}//end fromScopeMap()

	/**
	 * Collect groups from a map of schema definitions.
	 *
	 * @param mixed $schemas The document's `components.schemas` map.
	 *
	 * @return string[] Group ids (unfiltered).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fromSchemaDefinitions($schemas): array {
		if (is_array($schemas) === false) {
			return [];
		}

		$groups = [];
		foreach ($schemas as $schemaDefinition) {
			if (is_array($schemaDefinition) === false) {
				continue;
			}

			$groups = array_merge($groups, $this->fromSchemaDefinition(schemaDefinition: $schemaDefinition));
		}

		return $groups;
	}//end fromSchemaDefinitions()

	/**
	 * Collect groups from one schema definition — its own authorization block
	 * plus every property-level authorization block.
	 *
	 * Property-level rules matter: a group may gate a single field and appear
	 * nowhere in the schema-level block.
	 *
	 * @param array<string, mixed> $schemaDefinition One schema definition or entity array.
	 *
	 * @return string[] Group ids (unfiltered).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fromSchemaDefinition(array $schemaDefinition): array {
		$groups = $this->fromAuthorizationBlock(authorization: ($schemaDefinition['authorization'] ?? null));

		$properties = ($schemaDefinition['properties'] ?? null);
		if (is_array($properties) === false) {
			return $groups;
		}

		foreach ($properties as $propertyDefinition) {
			if (is_array($propertyDefinition) === false) {
				continue;
			}

			$groups = array_merge(
				$groups,
				$this->fromAuthorizationBlock(authorization: ($propertyDefinition['authorization'] ?? null))
			);
		}

		return $groups;
	}//end fromSchemaDefinition()

	/**
	 * Collect groups from a map of authorization-bearing definitions (registers).
	 *
	 * @param mixed $owners A map of definitions each optionally carrying `authorization`.
	 *
	 * @return string[] Group ids (unfiltered).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fromAuthorizationOwners($owners): array {
		if (is_array($owners) === false) {
			return [];
		}

		$groups = [];
		foreach ($owners as $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$groups = array_merge(
				$groups,
				$this->fromAuthorizationBlock(authorization: ($definition['authorization'] ?? null))
			);
		}

		return $groups;
	}//end fromAuthorizationOwners()

	/**
	 * Collect every group id named in a single `authorization` block.
	 *
	 * Two distinct shapes live in this block and are parsed separately, mirroring
	 * {@see \OCA\OpenRegister\Service\OasService}:
	 *  - action keys (`create`/`read`/`update`/`delete`/`manage`) hold a LIST of
	 *    rules, each a bare group id or `{group: <id>, match: {...}}`;
	 *  - `roles` holds a MAP of role-name => group(s), where the KEYS are role
	 *    names and only the VALUES are group ids.
	 *
	 * A rule's `match` conditions are deliberately NOT walked: their values are
	 * field names and literals, not principals, and recursing into them would
	 * manufacture groups out of ordinary data.
	 *
	 * @param mixed $authorization The authorization block, or null when absent.
	 *
	 * @return string[] Group ids (unfiltered).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fromAuthorizationBlock($authorization): array {
		if (is_array($authorization) === false || empty($authorization) === true) {
			return [];
		}

		$groups = [];
		foreach ($authorization as $key => $value) {
			// `public: true` is an anonymous-access opt-in flag, not an action list.
			if (is_array($value) === false) {
				continue;
			}

			if ($key === self::ROLES_KEY) {
				$groups = array_merge($groups, $this->fromRoleAssignments(roleAssignments: $value));
				continue;
			}

			foreach ($value as $rule) {
				$group = $this->groupFromRule(rule: $rule);
				if ($group !== null) {
					$groups[] = $group;
				}
			}
		}

		return $groups;
	}//end fromAuthorizationBlock()

	/**
	 * Extract group ids from a `roles` assignment map.
	 *
	 * Keys are role names defined in the register's `configuration.roles[]` and
	 * are NOT groups; each value is a single group id or a list of them.
	 *
	 * @param array<string, mixed> $roleAssignments The role-name => group(s) map.
	 *
	 * @return string[] Group ids (unfiltered).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function fromRoleAssignments(array $roleAssignments): array {
		$groups = [];
		foreach ($roleAssignments as $assigned) {
			foreach ((array)$assigned as $group) {
				if (is_string($group) === true) {
					$groups[] = $group;
				}
			}
		}

		return $groups;
	}//end fromRoleAssignments()

	/**
	 * Extract the group id from a single authorization rule.
	 *
	 * Rules are either a bare group id or an object carrying a `group` key
	 * alongside optional match conditions.
	 *
	 * @param mixed $rule The authorization rule.
	 *
	 * @return string|null The group id, or null when the rule names no group.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function groupFromRule($rule): ?string {
		if (is_string($rule) === true) {
			return $rule;
		}

		if (is_array($rule) === true && isset($rule['group']) === true && is_string($rule['group']) === true) {
			return $rule['group'];
		}

		return null;
	}//end groupFromRule()

	/**
	 * Reduce a raw group list to the set that may be provisioned.
	 *
	 * Drops non-strings, blanks and {@see self::RESERVED_PRINCIPALS}, and
	 * de-duplicates while preserving first-seen order so log output is stable.
	 *
	 * The parameter is deliberately typed as `mixed[]`, not `string[]`: this is
	 * the boundary where UNVALIDATED input is made safe. It is fed decoded JSON
	 * — a scope map with numeric keys, a malformed `roles` value, a hand-edited
	 * register.json — so the non-string guard below is load-bearing, and
	 * narrowing the type to match the happy path would delete the one check that
	 * stops a non-string reaching `createGroup()`.
	 *
	 * @param mixed[] $groups The raw collected group ids.
	 *
	 * @return string[] Provisionable group ids.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function provisionable(array $groups): array {
		$provisionable = [];
		foreach ($groups as $group) {
			if (is_string($group) === false) {
				continue;
			}

			$group = trim($group);
			if ($group === '' || in_array($group, self::RESERVED_PRINCIPALS, true) === true) {
				continue;
			}

			if (in_array($group, $provisionable, true) === false) {
				$provisionable[] = $group;
			}
		}

		return $provisionable;
	}//end provisionable()
}//end class
