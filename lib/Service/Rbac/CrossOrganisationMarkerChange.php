<?php

/**
 * OpenRegister CrossOrganisationMarkerChange
 *
 * Whether a new authorization block lets a rule cross organisations that the
 * old block did not: a rule or department matrix row carrying
 * `crossOrganisation: true` that the old block did not hold in exactly that
 * form. Removing a marker, or a marked rule, narrows and is not a change in
 * this sense.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Compares the crossing rules of two authorization blocks.
 *
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */
final class CrossOrganisationMarkerChange implements CrossOrganisationMarkerChangeInterface {

	/**
	 * Whether the new block holds a marked rule the old one did not.
	 *
	 * Rules are compared whole and by where they stand, with their keys in a
	 * fixed order, so a marked rule whose group or match changed, or that moved
	 * to another action, counts as new. Each marked rule counts as often as it
	 * occurs.
	 *
	 * @param mixed $old The authorization block before the change.
	 * @param mixed $new The authorization block after the change.
	 *
	 * @return bool True when the change adds a crossing.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function widens(mixed $old, mixed $new): bool {
		$before = array_count_values($this->markedRules(block: $this->asStored(authorization: $old)));
		foreach (array_count_values($this->markedRules(block: $this->asStored(authorization: $new))) as $rule => $count) {
			if ($count > ($before[$rule] ?? 0)) {
				return true;
			}
		}

		return false;
	}//end widens()

	/**
	 * The authorization block a schema stores for a value as it was sent: the
	 * entity setter decodes a JSON text, so that text is judged as its decoded form.
	 *
	 * @param mixed $authorization The authorization value as sent.
	 *
	 * @return mixed The decoded block, or the value itself when it is no text.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function asStored(mixed $authorization): mixed {
		if (is_string($authorization) === true) {
			return json_decode($authorization, true);
		}

		return $authorization;
	}//end asStored()

	/**
	 * Every array in the block that carries `crossOrganisation: true`, as a
	 * canonical JSON text with the keys above it, at any depth (action rules
	 * and matrix rows alike). List positions are left out: reordering moves nothing.
	 *
	 * @param mixed  $block An authorization block or a part of one.
	 * @param string $path  The associative keys above the block.
	 *
	 * @return array<int, string>
	 */
	private function markedRules(mixed $block, string $path=''): array {
		if (is_array($block) === false) {
			return [];
		}

		$found = [];
		if (($block[MatrixRowWhere::CROSS_ORGANISATION_KEY] ?? null) === true) {
			$found[] = $path.(string)json_encode($this->canonical(value: $block));
		}

		foreach ($block as $key => $value) {
			$below = $path;
			if (is_string($key) === true) {
				$below = $path.json_encode($key).'/';
			}

			array_push($found, ...$this->markedRules(block: $value, path: $below));
		}

		return $found;
	}//end markedRules()

	/**
	 * The value with the keys of every associative array sorted.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === true) {
			$value = array_map(fn (mixed $item): mixed => $this->canonical(value: $item), $value);
			if (array_is_list($value) === false) {
				ksort($value);
			}
		}

		return $value;
	}//end canonical()
}//end class
