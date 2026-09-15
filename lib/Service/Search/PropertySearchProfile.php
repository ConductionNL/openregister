<?php

/**
 * OpenRegister PropertySearchProfile
 *
 * How a property wants to be searched, and which control a list surface should
 * render for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * Reads a property's declared match type and input control, or works them out.
 *
 * One declaration on the property serves the list, the facet, the API and the
 * portal. Put it in the list component instead and you get four answers, three
 * of which drift.
 *
 * The auto-detected half is bound by a promise: a property that declares
 * nothing must behave exactly as it did before this class existed. That is why
 * {@see participatesInFreeText()} asks a different question for a declared
 * property than for an undeclared one. Before this change the free-text scan
 * took every non-encrypted string property whose format was not a date, and it
 * still does for anything that stays silent.
 */
final class PropertySearchProfile {
	/**
	 * The term has to equal the value.
	 */
	public const MATCH_EXACT = 'exact';

	/**
	 * The value has to start with the term.
	 */
	public const MATCH_PREFIX = 'prefix';

	/**
	 * The field is filtered by a lower and an upper bound, not by a term.
	 */
	public const MATCH_RANGE = 'range';

	/**
	 * Near enough counts, through pg_trgm similarity.
	 */
	public const MATCH_FUZZY = 'fuzzy';

	/**
	 * The term may appear anywhere in the value. The historical behaviour.
	 */
	public const MATCH_FULLTEXT = 'fulltext';

	/**
	 * Every match type a property may declare.
	 *
	 * @var string[]
	 */
	public const MATCH_TYPES = [
		self::MATCH_EXACT,
		self::MATCH_PREFIX,
		self::MATCH_RANGE,
		self::MATCH_FUZZY,
		self::MATCH_FULLTEXT,
	];

	/**
	 * Every input control a property may ask a list surface to render.
	 *
	 * @var string[]
	 */
	public const INPUT_CONTROLS = [
		'text',
		'select',
		'multiselect',
		'range',
		'date-range',
		'boolean',
	];

	/**
	 * The formats that make a property a date.
	 *
	 * @var string[]
	 */
	private const DATE_FORMATS = ['date', 'date-time', 'datetime', 'time'];

	/**
	 * Whether the property declares a match type of its own.
	 *
	 * @param array $property The property definition.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return bool True when `matchType` is set to a non-empty string.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function declaresMatchType(array $property): bool {
		$declared = ($property['matchType'] ?? null);

		return is_string($declared) === true && trim($declared) !== '';
	}//end declaresMatchType()

	/**
	 * The match type to apply to this property.
	 *
	 * @param array $property The property definition.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return string One of the MATCH_* constants.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function matchTypeFor(array $property): string {
		if (self::declaresMatchType(property: $property) === true) {
			$declared = strtolower(trim((string)$property['matchType']));
			if (in_array($declared, self::MATCH_TYPES, true) === true) {
				return $declared;
			}
		}

		return self::autoDetect(property: $property);
	}//end matchTypeFor()

	/**
	 * The match type a property gets when it declares none.
	 *
	 * @param array $property The property definition.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return string One of the MATCH_* constants.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function autoDetect(array $property): string {
		if (self::isDate(property: $property) === true) {
			return self::MATCH_RANGE;
		}

		$type = strtolower((string)($property['type'] ?? 'string'));
		if ($type === 'integer' || $type === 'number') {
			return self::MATCH_RANGE;
		}

		if ($type === 'boolean') {
			return self::MATCH_EXACT;
		}

		if ($type === 'string') {
			return self::MATCH_FULLTEXT;
		}

		return self::MATCH_EXACT;
	}//end autoDetect()

	/**
	 * Whether this property takes part in the free-text `_search` scan.
	 *
	 * A property that declares a match type takes part unless it asked for a
	 * range, because a range is a pair of bounds rather than a term.
	 *
	 * A property that declares nothing is judged by the rule the scan has
	 * always used: a string whose format is not a date. Widening that here
	 * would change what every existing `_search` matches, which is exactly what
	 * the "behaves as today" requirement forbids.
	 *
	 * @param array $property The property definition.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return bool True when the free-text scan should read this column.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function participatesInFreeText(array $property): bool {
		if (($property['x-openregister-encrypted'] ?? false) === true) {
			return false;
		}

		if (self::declaresMatchType(property: $property) === true) {
			return self::matchTypeFor(property: $property) !== self::MATCH_RANGE;
		}

		return strtolower((string)($property['type'] ?? '')) === 'string'
			&& self::isDate(property: $property) === false;
	}//end participatesInFreeText()

	/**
	 * The control a list surface should render for this property.
	 *
	 * @param array $property The property definition.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return string One of the INPUT_CONTROLS values.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function inputControlFor(array $property): string {
		$declared = ($property['inputControl'] ?? null);
		if (is_string($declared) === true
			&& in_array(strtolower(trim($declared)), self::INPUT_CONTROLS, true) === true
		) {
			return strtolower(trim($declared));
		}

		$matchType = self::matchTypeFor(property: $property);

		if ($matchType === self::MATCH_RANGE) {
			if (self::isDate(property: $property) === true) {
				return 'date-range';
			}

			return 'range';
		}

		if (strtolower((string)($property['type'] ?? '')) === 'boolean') {
			return 'boolean';
		}

		if (isset($property['enum']) === true && is_array($property['enum']) === true) {
			if (strtolower((string)($property['type'] ?? '')) === 'array') {
				return 'multiselect';
			}

			return 'select';
		}

		if (strtolower((string)($property['type'] ?? '')) === 'array') {
			return 'multiselect';
		}

		return 'text';
	}//end inputControlFor()

	/**
	 * Whether the property carries a date-ish format.
	 *
	 * @param array $property The property definition.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return bool True for date, date-time and time.
	 */
	private static function isDate(array $property): bool {
		return in_array(
			strtolower((string)($property['format'] ?? '')),
			self::DATE_FORMATS,
			true
		);
	}//end isDate()
}//end class
