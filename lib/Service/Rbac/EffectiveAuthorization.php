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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;

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
	 * @param RegisterMapperInterface<Register, Schema> $registerMapper Resolves the register a schema falls back to.
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
				return $this->grantNothingWhenEmpty(authorization: $authorization);
			}
		}

		if ($register === null) {
			return $this->grantNothingWhenEmpty(authorization: $authorization);
		}

		$config = $register->getConfiguration();
		$roles = $config['roles'] ?? [];
		if (empty($roles) === true) {
			return $this->grantNothingWhenEmpty(authorization: $authorization);
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

		return $this->grantNothingWhenEmpty(authorization: $authorization);
	}//end expandRoles()

	/**
	 * A roles block that resolved to nothing grants nothing.
	 *
	 * The same rule as `PermissionHandler::grantNothingWhenEmpty()`, which is what
	 * enforcement applies: a block that opted into authorization with `roles` and
	 * whose roles cannot be expanded (no role definitions, or every name unknown)
	 * is deny-all, not "no authorization configured". The OAS must describe the
	 * schema the way it is enforced, so it reads the same four canonical verbs
	 * with nobody in them instead of an empty block.
	 *
	 * @param array<string, mixed> $authorization The block after expansion.
	 *
	 * @return array<string, mixed> The block, or one granting nobody when it is empty.
	 *
	 * @spec openspec/changes/roles-block-that-resolves-to-nothing-grants-nothing/specs/rbac-scopes/spec.md
	 */
	private function grantNothingWhenEmpty(array $authorization): array {
		if ($authorization !== []) {
			return $authorization;
		}

		return [
			'read' => [],
			'create' => [],
			'update' => [],
			'delete' => [],
		];
	}//end grantNothingWhenEmpty()

}//end class
