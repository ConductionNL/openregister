<?php

/**
 * The checklist an example set may give a task: a list of items, each with a
 * unique id and a label, optionally checked.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-checklist
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Checks an example set task's checklist.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-checklist
 */
final class ExampleSetTaskChecklist {
	/**
	 * Why the task's checklist cannot be written, or '' (also when it has none).
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-checklist
	 */
	public static function refusal(array $data): string {
		if (array_key_exists('checklist', $data) === false) {
			return '';
		}

		if (is_array($data['checklist']) === false || array_is_list($data['checklist']) === false) {
			return 'checklist must be a list of items';
		}

		return self::itemsRefusal(items: $data['checklist']);
	}//end refusal()

	/**
	 * Why one of the checklist's items cannot be written, or '': the first one that cannot.
	 *
	 * @param array<int, mixed> $items The items.
	 *
	 * @return string
	 */
	private static function itemsRefusal(array $items): string {
		$ids = [];
		foreach ($items as $item) {
			$why = self::itemRefusal(item: $item, seen: $ids);
			if ($why !== '') {
				return $why;
			}

			$ids[] = $item['id'];
		}

		return '';
	}//end itemsRefusal()

	/**
	 * Why one checklist item cannot be written, or ''.
	 *
	 * @param mixed              $item The item.
	 * @param array<int, string> $seen The ids of the items before it.
	 *
	 * @return string
	 */
	private static function itemRefusal(mixed $item, array $seen): string {
		if (self::hasIdAndLabel(item: $item) === false) {
			return 'every checklist item needs an id and a label';
		}

		if (isset($item['checked']) === true && is_bool($item['checked']) === false) {
			return 'checklist item "'.$item['id'].'": checked is true or false';
		}

		if (in_array($item['id'], $seen, true) === true) {
			return 'checklist item id "'.$item['id'].'" is used twice';
		}

		return '';
	}//end itemRefusal()

	/**
	 * Whether an item is a map with a filled id and a filled label.
	 *
	 * @param mixed $item The item.
	 *
	 * @return bool
	 */
	private static function hasIdAndLabel(mixed $item): bool {
		return is_array($item) === true && self::filled(value: ($item['id'] ?? null)) === true && self::filled(value: ($item['label'] ?? null)) === true;
	}//end hasIdAndLabel()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function filled(mixed $value): bool {
		return is_string($value) === true && trim($value) !== '';
	}//end filled()
}//end class
