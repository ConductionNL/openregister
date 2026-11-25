<?php

/**
 * OpenRegister IdentifierFilterLift.
 *
 * A plain `id` or `uuid` filter selects by the object's identifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * Moves a top-level `id` or `uuid` filter that the schema does not declare as
 * a property into the `@self.uuid` metadata filter, so it selects on the
 * `_uuid` column.
 *
 * Without it such a key reached the object-field filters, named no column and
 * was dropped as undeclared: `findAll(['filters' => ['id' => $uuid]])`
 * answered every row of the schema (or, behind a caller's own post-filter and
 * limit, none). Apps join on it (portaliq's `match: id` via joins, shillinq's
 * lookups). A schema that declares an `id` or `uuid` property keeps filtering
 * on that property. An explicit `@self.uuid` wins over the lifted key.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class IdentifierFilterLift {

	/**
	 * The top-level keys that name the object's identifier.
	 *
	 * @var array<int, string>
	 */
	private const IDENTIFIER_KEYS = ['uuid', 'id'];

	/**
	 * The query with an undeclared `id`/`uuid` filter moved into `@self.uuid`.
	 *
	 * @param array<string, mixed>      $query      The search query.
	 * @param array<string, mixed>|null $properties The schema's declared properties.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public static function lift(array $query, ?array $properties): array {
		foreach (self::IDENTIFIER_KEYS as $key) {
			if (array_key_exists($key, $query) === false || isset(($properties ?? [])[$key]) === true) {
				continue;
			}

			$value = $query[$key];
			unset($query[$key]);
			if (self::isIdentifierValue(value: $value) === false) {
				continue;
			}

			$self = $query['@self'] ?? [];
			if (is_array($self) === false) {
				$self = [];
			}

			if (array_key_exists('uuid', $self) === false) {
				$self['uuid'] = $value;
			}

			$query['@self'] = $self;
		}//end foreach

		return $query;
	}//end lift()

	/**
	 * Whether a filter value can name identifiers: a non-empty string, or a
	 * non-empty list of non-empty strings.
	 *
	 * @param mixed $value The filter value.
	 *
	 * @return bool
	 */
	private static function isIdentifierValue(mixed $value): bool {
		if (is_string($value) === true) {
			return $value !== '';
		}

		if (is_array($value) === false || $value === [] || array_is_list($value) === false) {
			return false;
		}

		foreach ($value as $item) {
			if (is_string($item) === false || $item === '') {
				return false;
			}
		}

		return true;
	}//end isIdentifierValue()
}//end class
