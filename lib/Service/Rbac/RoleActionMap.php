<?php

/**
 * Flattens a register's role definitions into a role name to actions map.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The one reading of role definitions the permission reports share.
 *
 * `extends` is NOT followed here. The hierarchy is resolved in
 * {@see \OCA\OpenRegister\Service\Object\PermissionHandlerInterface::expandRoles()},
 * and a second walk of it here could disagree with the first, which would make
 * a report name a role that did not actually decide. Callers that need
 * inherited actions pass the already-resolved definitions.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RoleActionMap {

	/**
	 * Flatten role definitions into a name to actions map.
	 *
	 * @param mixed $roleDefinitions The register's role definitions.
	 *
	 * @return array<string, array<int, string>> Role name to its actions.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function of(mixed $roleDefinitions): array {
		if (is_array($roleDefinitions) === false) {
			return [];
		}

		$map = [];
		foreach ($roleDefinitions as $key => $definition) {
			$map = $this->withDefinition(map: $map, key: $key, definition: $definition);
		}

		return $map;
	}//end of()

	/**
	 * The map with one definition added, when it is one.
	 *
	 * Two shapes reach this: the list of definitions a register stores, each with
	 * its own `name`, and the flat `name => actions` map the hierarchy resolver
	 * produces. Both are read rather than one being declared canonical, because a
	 * caller holding the resolved map is exactly the caller whose answer is most
	 * accurate.
	 *
	 * @param array<string, array<int, string>> $map        The map so far.
	 * @param int|string                        $key        The definition's key.
	 * @param mixed                             $definition The definition.
	 *
	 * @return array<string, array<int, string>> The map.
	 */
	private function withDefinition(array $map, int|string $key, mixed $definition): array {
		if (is_array($definition) === false) {
			return $map;
		}

		if (isset($definition['name']) === true && is_array(($definition['actions'] ?? null)) === true) {
			$map[(string)$definition['name']] = array_values(array_filter($definition['actions'], 'is_string'));
			return $map;
		}

		if (is_string($key) === true) {
			$map[$key] = array_values(array_filter($definition, 'is_string'));
		}

		return $map;
	}//end withDefinition()
}//end class
