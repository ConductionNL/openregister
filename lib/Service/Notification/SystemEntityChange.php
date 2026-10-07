<?php

/**
 * OpenRegister SystemEntityChange
 *
 * Whether an update of a system entity (register, schema, configuration,
 * source, agent) changed anything an administrator should be told about.
 *
 * An app re-import saves every register, schema and configuration it ships,
 * whether or not the content moved. Measured on a dev instance on 7 October
 * 2026: of 106 "updated" notifications in an administrator's bell, most
 * compared equal apart from the `updated` stamp, a configuration's `version`
 * (the app version the import came from), a `created` stamp the import's
 * entity did not carry, `null` against `[]`, or the order of keys and of
 * `required`. None of those is a change anybody can act on.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/live-audit-round-one/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Compares two serialised system entities, ignoring bookkeeping.
 *
 * @spec openspec/changes/live-audit-round-one/specs/notificatie-engine/spec.md
 */
class SystemEntityChange {

	/**
	 * Fields a save or an import stamps, which say nothing about the content.
	 *
	 * @var array<int, string>
	 */
	public const BOOKKEEPING_FIELDS = [
		'updated',
		'created',
		'version',
		'lastChecked',
		'lastSyncDate',
		'syncStatus',
		'localVersion',
		'remoteVersion',
	];

	/**
	 * Whether the content differs between the two serialisations.
	 *
	 * @param array<string, mixed> $old The entity before the save.
	 * @param array<string, mixed> $new The entity after the save.
	 *
	 * @return bool True when something other than bookkeeping changed.
	 *
	 * @spec openspec/changes/live-audit-round-one/specs/notificatie-engine/spec.md
	 */
	public function isReal(array $old, array $new): bool {
		foreach (self::BOOKKEEPING_FIELDS as $field) {
			unset($old[$field], $new[$field]);
		}

		return $this->normalise(value: $old) !== $this->normalise(value: $new);
	}//end isReal()

	/**
	 * One value in a form where equal content compares equal.
	 *
	 * Empty values (`null`, `''`, `[]`) are one value; map keys are sorted; a
	 * list of scalars is sorted, because `required` and id lists carry no
	 * meaning in their order; a list of maps keeps its order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The comparable form.
	 */
	private function normalise(mixed $value): mixed {
		$value = $this->plain(value: $value);
		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === true) {
			return $this->normaliseList(list: $value);
		}

		return $this->normaliseMap(map: $value);
	}//end normalise()

	/**
	 * A date as ISO text, a serialisable object as its data, a scalar as text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The plain value.
	 */
	private function plain(mixed $value): mixed {
		if ($value instanceof \DateTimeInterface) {
			return $value->format('c');
		}

		if ($value instanceof \JsonSerializable) {
			$value = $value->jsonSerialize();
		}

		if (is_bool($value) === true) {
			return (string) (int) $value;
		}

		if (is_scalar($value) === true) {
			return (string) $value;
		}

		return $value;
	}//end plain()

	/**
	 * A map with sorted keys and its empty entries left out, or null when empty.
	 *
	 * @param array<string|int, mixed> $map The map.
	 *
	 * @return array<string, mixed>|null The comparable form.
	 */
	private function normaliseMap(array $map): ?array {
		$normalised = [];
		foreach ($map as $key => $item) {
			$item = $this->normalise(value: $item);
			if ($item !== null) {
				$normalised[(string) $key] = $item;
			}
		}

		if ($normalised === []) {
			return null;
		}

		ksort($normalised);

		return $normalised;
	}//end normaliseMap()

	/**
	 * A list, sorted when it holds only plain values.
	 *
	 * @param array<int, mixed> $list The list.
	 *
	 * @return array<int, mixed> The comparable form.
	 */
	private function normaliseList(array $list): array {
		$items = array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $list);
		if (count(array_filter($items, static fn (mixed $item): bool => is_array($item))) === 0) {
			sort($items, SORT_STRING);
		}

		return $items;
	}//end normaliseList()
}//end class
