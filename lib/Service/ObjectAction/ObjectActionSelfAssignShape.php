<?php

/**
 * The shape of a `selfAssign` action body, and why a declaration is refused.
 *
 * ```json
 * "x-openregister-action": {
 *   "take": { "name": "Take", "description": "Assign it to yourself.", "selfAssign": { "field": "assignee" } }
 * }
 * ```
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;

/**
 * Reads and shape-checks `selfAssign` bodies.
 *
 * @spec openspec/specs/declared-actions/spec.md
 */
final class ObjectActionSelfAssignShape {

	/**
	 * Refusals about a `selfAssign` body.
	 *
	 * The action writes one property and takes the uid from the session, so it
	 * accepts no inputs, no elevation and no async run.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return list<string> The refusals.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public static function refusals(string $key, array $definition): array {
		if (isset(PermissionCatalogue::CANONICAL[$key]) === true || in_array($key, PermissionCatalogue::CONTROL_KEYS, true) === true) {
			return [sprintf('Action "%s": a self-assign action needs its own name, not a verb or a control key.', $key)];
		}

		$body = $definition[ObjectActionDefinition::KIND_SELF_ASSIGN];
		if (is_array($body) === false || self::fieldOf(body: $body) === null || array_keys($body) !== ['field']) {
			return [sprintf('Action "%s": "selfAssign" must be {"field": "<property>"} and nothing else.', $key)];
		}

		$extra = self::extraKeys(definition: $definition);
		if ($extra !== []) {
			return [sprintf('Action "%s": a self-assign action takes no %s.', $key, implode(' or ', $extra))];
		}

		return [];
	}//end refusals()

	/**
	 * The keys a self-assign action may not carry: inputs, an elevation, or a mode other than sync.
	 *
	 * @param array<string, mixed> $definition The action definition.
	 *
	 * @return array<int, string> The offending keys, in that order.
	 */
	private static function extraKeys(array $definition): array {
		$extra = array_values(array_intersect(['inputs', 'elevate'], array_keys($definition)));
		if (($definition['mode'] ?? 'sync') !== 'sync') {
			$extra[] = 'mode';
		}

		return $extra;
	}//end extraKeys()

	/**
	 * The property a `selfAssign` body names, or null when it names none.
	 *
	 * @param array<string, mixed> $body The body.
	 *
	 * @return string|null The property name.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public static function fieldOf(array $body): ?string {
		$field = ($body['field'] ?? null);
		if (is_string($field) === false || trim($field) === '') {
			return null;
		}

		return $field;
	}//end fieldOf()
}//end class
