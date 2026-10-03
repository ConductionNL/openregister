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

use OCA\OpenRegister\Db\RegisterMapper;

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
	 * @param RegisterMapper $registerMapper Resolves the register a schema falls back to.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
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
		if (is_array($authorization) === true && empty($authorization) === false) {
			return $this->expandRoles(authorization: $authorization, schema: $schema);
		}

		// Fall back to register authorization.
		try {
			$registerId = $this->registerMapper->getFirstRegisterWithSchema(schemaId: $schema->getId());
			if ($registerId !== null) {
				$register = $this->registerMapper->find(id: $registerId);
				$registerAuth = $register->getAuthorization();
				if (is_array($registerAuth) === true && empty($registerAuth) === false) {
					return $this->expandRoles(authorization: $registerAuth, schema: $schema, register: $register);
				}
			}
		} catch (\Throwable $e) {
			// Fallback: no register authorization available.
		}

		return null;
	}//end forSchema()

	/**
	 * Expand role references into the action-level entries they stand for.
	 *
	 * @param array $authorization The authorization block.
	 * @param object $schema The schema object.
	 * @param object|null $register The register object (optional, looked up if needed).
	 *
	 * @return array The authorization with roles expanded.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function expandRoles(array $authorization, object $schema, ?object $register = null): array {
		if (isset($authorization['roles']) === false || is_array($authorization['roles']) === false) {
			return $authorization;
		}

		$roleAssignments = $authorization['roles'];
		unset($authorization['roles']);

		// Get register for role definitions.
		if ($register === null) {
			try {
				$registerId = $this->registerMapper->getFirstRegisterWithSchema($schema->getId());
				if ($registerId !== null) {
					$register = $this->registerMapper->find($registerId);
				}
			} catch (\Throwable $e) {
				return $authorization;
			}
		}

		if ($register === null) {
			return $authorization;
		}

		$config = $register->getConfiguration();
		$roles = $config['roles'] ?? [];
		if (empty($roles) === true) {
			return $authorization;
		}

		// Build role map.
		$roleMap = [];
		foreach ($roles as $roleDef) {
			if (isset($roleDef['name']) === true && isset($roleDef['actions']) === true) {
				$roleMap[$roleDef['name']] = $roleDef['actions'];
			}
		}

		// Expand roles to action-level entries.
		foreach ($roleAssignments as $roleName => $groups) {
			if (isset($roleMap[$roleName]) === false) {
				continue;
			}

			foreach ($roleMap[$roleName] as $action) {
				if (isset($authorization[$action]) === false) {
					$authorization[$action] = [];
				}

				foreach ((array)$groups as $group) {
					if (in_array($group, $authorization[$action], true) === false) {
						$authorization[$action][] = $group;
					}
				}
			}
		}

		return $authorization;
	}//end expandRoles()

}//end class
