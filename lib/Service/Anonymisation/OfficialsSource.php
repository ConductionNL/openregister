<?php

/**
 * OfficialsSource
 *
 * Where the organisation's register of officials is read from (REQ-DKP-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The `anonymisation.officialsSource` setting, normalised.
 *
 * Two shapes are accepted: an OpenRegister schema whose objects carry a name
 * and a capacity (`{"type": "schema", "register", "schema", "nameProperty",
 * "capacityProperty"}`), or Nextcloud groups each mapped to a capacity
 * (`{"type": "groups", "groups": {"<gid>": "<capacity>"}}`). Anything else
 * reads as no source, so capacity comes from role words only.
 *
 * @spec openspec/changes/detection-knows-the-person/specs/detection-knows-the-person/spec.md#requirement-a-detected-person-gets-a-capacity-suggestion-with-its-source-req-dkp-001
 */
final class OfficialsSource {

	public const SCHEMA = 'schema';

	public const GROUPS = 'groups';

	/**
	 * The setting as stored and read: the source in one of the two shapes, or null.
	 *
	 * @param mixed $value The submitted or stored setting.
	 *
	 * @return array<string, mixed>|null The normalised source, or null when it names none.
	 *
	 * @spec openspec/changes/detection-knows-the-person/specs/detection-knows-the-person/spec.md#requirement-a-detected-person-gets-a-capacity-suggestion-with-its-source-req-dkp-001
	 */
	public static function normalise(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		return match ($value['type'] ?? null) {
			self::SCHEMA => self::schemaSource(value: $value),
			self::GROUPS => self::groupsSource(value: $value),
			default => null,
		};
	}//end normalise()

	/**
	 * A schema source, when it names a register and a schema.
	 *
	 * @param array<string, mixed> $value The setting.
	 *
	 * @return array<string, string>|null
	 */
	private static function schemaSource(array $value): ?array {
		$source = ['type' => self::SCHEMA];
		foreach (['register' => '', 'schema' => '', 'nameProperty' => 'name', 'capacityProperty' => 'capacity'] as $key => $default) {
			$source[$key] = trim((string) ($value[$key] ?? ''));
			if ($source[$key] === '') {
				$source[$key] = $default;
			}
		}

		if ($source['register'] === '' || $source['schema'] === '') {
			return null;
		}

		return $source;
	}//end schemaSource()

	/**
	 * A groups source: the groups mapped to a known capacity, when there is at least one.
	 *
	 * @param array<string, mixed> $value The setting.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function groupsSource(array $value): ?array {
		$groups = [];
		foreach ((array) ($value['groups'] ?? []) as $gid => $capacity) {
			if (is_string($gid) === true && trim($gid) !== '' && in_array($capacity, CapacityPolicy::CAPACITIES, true) === true) {
				$groups[trim($gid)] = $capacity;
			}
		}

		if ($groups === []) {
			return null;
		}

		return ['type' => self::GROUPS, 'groups' => $groups];
	}//end groupsSource()
}//end class
