<?php

/**
 * What a portal may do with each property of a schema, on behalf of a resident.
 *
 * A property declares `portal`: `none`, `read` or `edit`. The field carries
 * whether the resident may see and change it, because that is a property of
 * the field (decision D16). Absent means `none` for a caller acting on
 * behalf of a portal subject; callers without one are never asked here.
 *
 * Pure rules over a schema and two value maps: no database, no session.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Kernel\Contracts\PortalPropertyPolicyInterface;

/**
 * The read and write rules of the `portal` property key.
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
 */
final class PortalPropertyPolicy implements PortalPropertyPolicyInterface {

	/**
	 * The property key.
	 *
	 * @var string
	 */
	public const KEY = 'portal';

	/**
	 * The portal neither sees nor changes the property. The default.
	 *
	 * @var string
	 */
	public const NONE = 'none';

	/**
	 * The portal shows the property and may not change it.
	 *
	 * @var string
	 */
	public const READ = 'read';

	/**
	 * The portal shows the property and may change it.
	 *
	 * @var string
	 */
	public const EDIT = 'edit';

	/**
	 * The values the key accepts, in the order a form offers them.
	 *
	 * @var array<int, string>
	 */
	public const LEVELS = [self::NONE, self::READ, self::EDIT];

	/**
	 * Keys of an object's JSON that are no schema property and always pass: its metadata and its id.
	 *
	 * @var array<int, string>
	 */
	private const ENVELOPE_KEYS = ['@self', 'id'];

	/**
	 * What the portal may do with one property definition.
	 *
	 * Absent, or anything that is not a declared level, reads as `none`: a
	 * field nobody marked stays off the portal.
	 *
	 * @param mixed $definition The property definition.
	 *
	 * @return string One of LEVELS.
	 *
	 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
	 */
	public static function levelOf(mixed $definition): string {
		if (is_array($definition) === false || is_string($definition[self::KEY] ?? null) === false) {
			return self::NONE;
		}

		$level = strtolower(trim($definition[self::KEY]));
		if (in_array($level, self::LEVELS, true) === false) {
			return self::NONE;
		}

		return $level;
	}//end levelOf()

	/**
	 * An object as the portal may see it: every property whose `portal` is `none` or absent left out.
	 *
	 * A key the schema does not declare is left out too, since nobody
	 * declared it may be shown. The id and the `@self` metadata stay.
	 *
	 * @param array<string, mixed> $properties The schema's property definitions by name.
	 * @param array<string, mixed> $object     The object as the API renders it.
	 *
	 * @return array<string, mixed> The object without the properties the portal may not see.
	 *
	 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#scenario-an-internal-note-stays-off-the-portal
	 */
	public function readable(array $properties, array $object): array {
		$visible = [];
		foreach ($object as $key => $value) {
			if (in_array($key, self::ENVELOPE_KEYS, true) === true
				|| self::levelOf(definition: ($properties[$key] ?? null)) !== self::NONE
			) {
				$visible[$key] = $value;
			}
		}

		return $visible;
	}//end readable()

	/**
	 * The properties a portal write would change although their `portal` is not `edit`.
	 *
	 * A write merges (`replace` false: only the keys it sends count) or
	 * replaces (`replace` true: a schema property it leaves out becomes
	 * empty, so leaving out a stored value is a change too). A value sent
	 * equal to the stored one is no change. Keys starting with `_` and the
	 * envelope keys are request options and metadata, never a property.
	 *
	 * @param array<string, mixed>      $properties The schema's property definitions by name.
	 * @param array<string, mixed>      $incoming   The values the write sends.
	 * @param array<string, mixed>|null $existing   The stored values, null for a create.
	 * @param bool                      $replace    Whether the write replaces the object (PUT) rather than merging (PATCH, POST).
	 *
	 * @return array<int, string> The refused property names; empty when the write may go ahead.
	 *
	 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#scenario-the-resident-changes-only-what-they-may-change
	 */
	public function refusedChanges(array $properties, array $incoming, ?array $existing, bool $replace = false): array {
		$stored     = ($existing ?? []);
		$candidates = array_keys($incoming);
		if ($replace === true) {
			$candidates = array_values(array_unique(array_merge(array_keys($properties), $candidates)));
		}

		$refused = [];
		foreach ($candidates as $key) {
			$key = (string)$key;
			if (self::isPropertyKey(key: $key) === false
				|| self::levelOf(definition: ($properties[$key] ?? null)) === self::EDIT
				|| self::changes(key: $key, incoming: $incoming, stored: $stored) === false
			) {
				continue;
			}

			$refused[] = $key;
		}

		return $refused;
	}//end refusedChanges()

	/**
	 * Whether the write changes one key: a sent value that differs from the stored one, or a stored value a replace drops.
	 *
	 * @param string               $key      The key.
	 * @param array<string, mixed> $incoming The sent values.
	 * @param array<string, mixed> $stored   The stored values.
	 *
	 * @return bool True when the key's value would change.
	 */
	private static function changes(string $key, array $incoming, array $stored): bool {
		$sent = array_key_exists($key, $incoming);
		$had  = (array_key_exists($key, $stored) === true && $stored[$key] !== null);
		if ($sent === false) {
			// Only a replace asks about a key it did not send: it empties it.
			return $had;
		}

		return self::normalised(value: $incoming[$key]) !== self::normalised(value: ($stored[$key] ?? null));
	}//end changes()

	/**
	 * A value in a form two equal values share, so a re-sent unchanged value is no change.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string Its canonical JSON.
	 */
	private static function normalised(mixed $value): string {
		if (is_array($value) === true && array_is_list($value) === false) {
			ksort($value);
		}

		return (string)json_encode($value);
	}//end normalised()

	/**
	 * Whether a key of a write body can be a property.
	 *
	 * @param string $key The key.
	 *
	 * @return bool False for request options (`_…`) and the envelope.
	 */
	private static function isPropertyKey(string $key): bool {
		return $key !== '' && str_starts_with($key, '_') === false && in_array($key, self::ENVELOPE_KEYS, true) === false;
	}//end isPropertyKey()
}//end class
