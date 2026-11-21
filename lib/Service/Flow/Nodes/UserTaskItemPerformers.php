<?php

/**
 * The user-task step's performers, read from the item it runs for.
 *
 * A step may name its performer through a field of the item instead of a
 * fixed id: `"assignee": "{{ employee.0.managerUserId }}"` gives the task to
 * the manager of the case's employee, whoever that is for this run. The
 * placeholders are resolved once, when the step is entered, so the task, the
 * resume slot (whose assignee the resume check compares) and the suspension
 * all read the same performer.
 *
 * A placeholder that resolves to nothing leaves that performer source empty;
 * the other sources (candidate groups, a routing fallback) still apply.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-hr-workflows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;

/**
 * Resolves item placeholders in a user-task step's performer fields.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate::render() is the
 * shared placeholder walk every node uses.
 *
 * @spec openspec/changes/platform-hr-workflows
 */
final class UserTaskItemPerformers {

	/**
	 * The configuration keys that name a performer.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = ['assignee', 'candidates', 'routingFallback', 'candidateUsers', 'candidateGroups', 'candidateRole'];

	/**
	 * The step configuration with its performer placeholders resolved against the first item.
	 *
	 * Only the performer keys are touched, and only when they carry a
	 * placeholder; a configuration without one is returned as it is.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array<int, mixed>    $items  The step's input items; the first is the representative.
	 *
	 * @return array<string, mixed> The configuration with resolved performers.
	 *
	 * @spec openspec/changes/platform-hr-workflows
	 */
	public static function resolve(array $config, array $items): array {
		$json = self::representativeJson(items: $items);
		foreach (self::KEYS as $key) {
			if (array_key_exists($key, $config) === false || self::hasPlaceholder(value: $config[$key]) === false) {
				continue;
			}

			$config[$key] = self::withoutBlanks(value: FlowValueTemplate::render(value: $config[$key], json: $json));
		}

		return $config;
	}//end resolve()

	/**
	 * Whether a configured value carries a `{{ … }}` placeholder anywhere.
	 *
	 * @param mixed $value A string, a list or a `{type, id}` map.
	 *
	 * @return bool
	 */
	private static function hasPlaceholder(mixed $value): bool {
		if (is_string($value) === true) {
			return str_contains($value, '{{');
		}

		if (is_array($value) === false) {
			return false;
		}

		foreach ($value as $part) {
			if (self::hasPlaceholder(value: $part) === true) {
				return true;
			}
		}

		return false;
	}//end hasPlaceholder()

	/**
	 * A resolved value with the entries that came out empty left out of a list.
	 *
	 * A list of candidates where one field was empty keeps the others; a list
	 * entry that is empty would otherwise read as a performer nobody is.
	 *
	 * @param mixed $value The resolved value.
	 *
	 * @return mixed
	 */
	private static function withoutBlanks(mixed $value): mixed {
		// A placeholder that is the whole value keeps the resolved type, so a
		// field the item lacks comes back null; the performer keys hold text.
		if ($value === null) {
			return '';
		}

		if (is_array($value) === false || array_is_list($value) === false) {
			return $value;
		}

		return array_values(
			array_filter(
				$value,
				static fn (mixed $entry): bool => ($entry !== null && $entry !== '' && $entry !== [])
			)
		);
	}//end withoutBlanks()

	/**
	 * The first item's record.
	 *
	 * @param array<int, mixed> $items The items.
	 *
	 * @return array<string, mixed>
	 */
	private static function representativeJson(array $items): array {
		foreach ($items as $item) {
			if (is_array($item) === true) {
				return (array)($item[FlowItems::JSON] ?? []);
			}
		}

		return [];
	}//end representativeJson()
}//end class
