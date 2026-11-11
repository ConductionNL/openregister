<?php

/**
 * OpenRegister TranslationCsvCodec
 *
 * Converts between OpenRegister's nested `{lang: value}` JSON shape
 * and the flat `field_lang` column shape used by CSV / Excel exports.
 * Designed to be invoked by `ImportService::importFromCsv` /
 * `ExportService::exportToCsv` once they're updated to be
 * translation-aware (Phase 2.3 wire-in).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Translation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Translation;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\TranslationHandler;

/**
 * Flattens translatable properties to `field_lang` CSV columns and back.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
class TranslationCsvCodec {
	/**
	 * Constructor.
	 *
	 * @param TranslationHandler $translationHandler Handler exposing translatable property metadata.
	 */
	public function __construct(
		private readonly TranslationHandler $translationHandler,
	) {
	}//end __construct()

	/**
	 * BCP 47-style language code accepted as a column suffix or a value key.
	 */
	private const LANGUAGE_CODE = '/^[a-zA-Z][a-zA-Z0-9-]{0,15}$/';

	/**
	 * Flatten an object's data into CSV-compatible columns.
	 *
	 * For each translatable property, emit one column per language
	 * present in the value (`title` → `title_nl`, `title_en`).
	 * Untranslatable properties pass through with their original key.
	 *
	 * Caller iterates the rows in their CSV-build path; this method
	 * is per-row.
	 *
	 * @param array<string, mixed> $data   The object's data payload to flatten.
	 * @param Schema               $schema The schema describing translatable properties.
	 *
	 * @return array<string, scalar|null>
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function flattenForCsv(array $data, Schema $schema): array {
		$translatableProps = $this->translationHandler->getTranslatableProperties($schema);
		$row = [];

		foreach ($data as $key => $value) {
			if (in_array($key, $translatableProps, true) === false) {
				$row[$key] = $this->plainCell(value: $value);
				continue;
			}

			$row = array_replace($row, $this->translatableCells(key: $key, value: $value));
		}

		return $row;
	}//end flattenForCsv()

	/**
	 * Reverse of `flattenForCsv`. Reconstructs the nested
	 * `{lang: value}` shape from a flat row.
	 *
	 * Recognises any column matching `<property>_<lang>` where the
	 * `<property>` portion is one of the schema's translatable
	 * properties. Other `_`-suffixed columns, untranslatable or unrecognised,
	 * are passed through as-is (they may be unrelated user fields with
	 * underscores in the name).
	 *
	 * @param array<string, mixed> $row    The flat CSV row to unflatten.
	 * @param Schema               $schema The schema describing translatable properties.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function unflattenFromCsv(array $row, Schema $schema): array {
		$translatableProps = $this->translationHandler->getTranslatableProperties($schema);
		$out = [];
		foreach ($row as $column => $value) {
			$match = $this->matchLanguageColumn(column: $column, props: $translatableProps);
			if ($match === null) {
				$out[$column] = $value;
				continue;
			}

			if (is_string($value) === true && $value !== '') {
				$out[$match[0]] = $this->withTranslation(current: ($out[$match[0]] ?? null), entry: [$match[1] => $value]);
			}
		}

		return $out;
	}//end unflattenFromCsv()

	/**
	 * Cell for an untranslatable property: scalars and null as-is, anything
	 * else JSON-encoded so the CSV row stays single-cell-per-column.
	 *
	 * @param mixed $value The property value.
	 *
	 * @return mixed The cell value.
	 */
	private function plainCell(mixed $value): mixed {
		if (is_scalar($value) === true || $value === null) {
			return $value;
		}

		return json_encode($value, JSON_UNESCAPED_SLASHES);
	}//end plainCell()

	/**
	 * Cells for a translatable property.
	 *
	 * A language-keyed value yields one `field_lang` column per language; a
	 * plain string (legacy single-language data) goes under `field_und`
	 * (BCP 47 "und" = undetermined language) so the round-trip preserves the
	 * variant without guessing; anything else passes through as a scalar.
	 *
	 * @param int|string $key   The property name.
	 * @param mixed      $value The property value.
	 *
	 * @return array<int|string, mixed> The cells, keyed by column.
	 */
	private function translatableCells(int|string $key, mixed $value): array {
		if (is_array($value) === true && $this->isLanguageKeyed(value: $value) === true) {
			return $this->languageCells(key: $key, value: $value);
		}

		if (is_string($value) === true) {
			return [$key . '_und' => $value];
		}

		return [$key => $this->scalarOrNull(value: $value)];
	}//end translatableCells()

	/**
	 * One `field_lang` column per language; non-scalar values become null.
	 *
	 * @param int|string           $key   The property name.
	 * @param array<string, mixed> $value The language-keyed value.
	 *
	 * @return array<string, scalar|null> The cells, keyed by column.
	 */
	private function languageCells(int|string $key, array $value): array {
		$cells = [];
		foreach ($value as $lang => $langValue) {
			$cells[$key . '_' . $lang] = $this->scalarOrNull(value: $langValue);
		}

		return $cells;
	}//end languageCells()

	/**
	 * Find the translatable property and language a column encodes.
	 *
	 * Properties are tried in order; the first whose `<property>_` prefix
	 * matches and leaves a valid language code wins.
	 *
	 * @param int|string   $column The column name.
	 * @param list<string> $props  The translatable property names.
	 *
	 * @return array{0: string, 1: string}|null Property and language, or null.
	 */
	private function matchLanguageColumn(int|string $column, array $props): ?array {
		foreach ($props as $prop) {
			$prefix = $prop . '_';
			if (str_starts_with($column, $prefix) === false) {
				continue;
			}

			$lang = substr($column, strlen($prefix));
			if ($this->isLanguageCode(code: $lang) === true) {
				return [$prop, $lang];
			}
		}

		return null;
	}//end matchLanguageColumn()

	/**
	 * Write one language cell into a property's language map.
	 *
	 * Empty and non-string cells write no slot (the caller skips them), which
	 * lets the projection treat them as "not translated". A non-array value
	 * already under the property is replaced by a language map.
	 *
	 * @param mixed                $current The value under the property so far.
	 * @param array<string, string> $entry  One language => translation pair.
	 *
	 * @return array<string, mixed> The language map with the cell applied.
	 */
	private function withTranslation(mixed $current, array $entry): array {
		if (is_array($current) === false) {
			$current = [];
		}

		return array_replace($current, $entry);
	}//end withTranslation()

	/**
	 * Return a scalar unchanged and anything else as null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return scalar|null
	 */
	private function scalarOrNull(mixed $value): mixed {
		if (is_scalar($value) === true) {
			return $value;
		}

		return null;
	}//end scalarOrNull()

	/**
	 * Detect whether an array's keys are all BCP 47-style language codes.
	 *
	 * @param array<mixed> $value The array whose keys should be inspected.
	 *
	 * @return bool True when every key looks like a language code.
	 */
	private function isLanguageKeyed(array $value): bool {
		if (count($value) === 0) {
			return false;
		}

		foreach (array_keys($value) as $key) {
			if (is_string($key) === false || $this->isLanguageCode(code: $key) === false) {
				return false;
			}
		}

		return true;
	}//end isLanguageKeyed()

	/**
	 * Whether a string is a BCP 47-style language code.
	 *
	 * @param string $code The candidate code.
	 *
	 * @return bool True when it matches.
	 */
	private function isLanguageCode(string $code): bool {
		return preg_match(self::LANGUAGE_CODE, $code) === 1;
	}//end isLanguageCode()
}//end class
