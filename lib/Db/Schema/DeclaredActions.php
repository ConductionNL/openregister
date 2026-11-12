<?php

/**
 * DeclaredActions: the actions a schema declares beside the canonical verbs.
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

use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;

/**
 * Reads the `x-openregister-action` block for the action names it declares.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class DeclaredActions {

	/**
	 * The keys of the declared actions that NAME and DESCRIBE themselves.
	 *
	 * A declaration must NAME and DESCRIBE itself. An entry with no
	 * description is how an action ends up in a permission matrix with
	 * nothing telling the person granting it what they are agreeing to.
	 *
	 * @param mixed $configuration The schema's stored configuration.
	 *
	 * @return array<int, string> Declared action keys.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function names(mixed $configuration): array {
		$configuration = ($configuration ?? []);
		if (is_array($configuration) === false) {
			return [];
		}

		$declared = ($configuration['x-openregister-action'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		return $this->declaredKeys(declared: $declared);
	}//end names()

	/**
	 * The keys of the usable declarations, in declaration order.
	 *
	 * @param array $declared The `x-openregister-action` block.
	 *
	 * @return array<int, string>
	 */
	private function declaredKeys(array $declared): array {
		$names = [];
		foreach ($declared as $key => $definition) {
			if ($this->isDeclared(key: $key, definition: $definition) === true) {
				$names[] = $key;
			}
		}

		return $names;
	}//end declaredKeys()

	/**
	 * Whether one entry is a usable declaration.
	 *
	 * @param int|string $key        The declared key.
	 * @param mixed      $definition Its definition.
	 *
	 * @return bool
	 */
	private function isDeclared(int|string $key, mixed $definition): bool {
		if (is_string($key) === false || $this->isDeclarableName(name: $key) === false) {
			return false;
		}

		return is_array($definition) === true && isset($definition['name']) === true && isset($definition['description']) === true;
	}//end isDeclared()

	/**
	 * Whether a schema may declare an action under this name.
	 *
	 * A canonical verb or a control key is never a declared action: declaring
	 * `deny` would otherwise take the deny block out of the save-time catalogue
	 * check.
	 *
	 * @param string $name The declared key.
	 *
	 * @return bool True when the name is free for a declared action.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private function isDeclarableName(string $name): bool {
		if ($name === '' || isset(PermissionCatalogueInterface::CANONICAL[$name]) === true) {
			return false;
		}

		return in_array($name, PermissionCatalogueInterface::CONTROL_KEYS, true) === false;
	}//end isDeclarableName()
}//end class
