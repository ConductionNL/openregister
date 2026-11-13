<?php

/**
 * OpenRegister AddedGrants
 *
 * The actions an authorization block edit gives to someone new.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * Compares an authorization block before and after an edit, per action.
 *
 * An action is granted by the edit when its rule list holds an entry (a group
 * id, or a rule object compared as canonical JSON) more often than before.
 * Only additions count: a save that leaves a rule as it was, or removes from
 * it, grants nothing, so a manager may still edit a schema whose block already
 * names rights they do not hold. Reordering a list grants nothing.
 *
 * Only action keys whose value is a list are compared here; a role matrix row
 * is not an action rule.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */
final class AddedGrants {

	/**
	 * The actions the edit gives to an entry that did not have them.
	 *
	 * @param mixed $old The stored block (array, JSON text or null).
	 * @param mixed $new The block as sent (array, JSON text or null).
	 *
	 * @return array<int, string> The granted actions, each once, in the order of the new block.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
	 */
	public function between(mixed $old, mixed $new): array {
		$before = $this->rules(block: $old);
		$granted = [];
		foreach ($this->rules(block: $new) as $action => $entries) {
			$had = array_count_values($before[$action] ?? []);
			foreach (array_count_values($entries) as $entry => $count) {
				if ($count > ($had[$entry] ?? 0)) {
					$granted[] = (string)$action;
					break;
				}
			}
		}

		return $granted;
	}//end between()

	/**
	 * Each list-valued action of a block, its entries as canonical text.
	 *
	 * @param mixed $block The block (array, JSON text or null).
	 *
	 * @return array<string, array<int, string>> The entries per action.
	 */
	private function rules(mixed $block): array {
		if (is_string($block) === true) {
			$block = json_decode($block, true);
		}

		if (is_array($block) === false) {
			return [];
		}

		$rules = [];
		foreach ($block as $action => $entries) {
			if (is_string($action) === false || is_array($entries) === false || array_is_list($entries) === false) {
				continue;
			}

			$rules[$action] = array_map(fn (mixed $entry): string => $this->canonical(value: $entry), $entries);
		}

		return $rules;
	}//end rules()

	/**
	 * One entry as text that does not depend on key order.
	 *
	 * @param mixed $value The entry.
	 *
	 * @return string The canonical text.
	 */
	private function canonical(mixed $value): string {
		if (is_array($value) === true && array_is_list($value) === false) {
			ksort($value);
		}

		if (is_array($value) === true) {
			$value = array_map(fn (mixed $part): string => $this->canonical(value: $part), $value);
		}

		return (string)json_encode($value);
	}//end canonical()
}//end class
