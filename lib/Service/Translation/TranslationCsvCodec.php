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

class TranslationCsvCodec
{
    /**
     * Constructor.
     *
     * @param TranslationHandler $translationHandler Handler exposing translatable property metadata.
     */
    public function __construct(
        private readonly TranslationHandler $translationHandler
    ) {
    }//end __construct()

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
     */
    public function flattenForCsv(array $data, Schema $schema): array
    {
        $translatableProps = $this->translationHandler->getTranslatableProperties($schema);
        $row = [];

        foreach ($data as $key => $value) {
            // Untranslatable property: pass through as-is. Non-scalar
            // values get JSON-encoded so the CSV row stays single-cell-per-column.
            if (in_array($key, $translatableProps, true) === false) {
                if (is_scalar($value) === true || $value === null) {
                    $row[$key] = $value;
                } else {
                    $row[$key] = json_encode($value, JSON_UNESCAPED_SLASHES);
                }

                continue;
            }

            // Translatable property with language-keyed value:
            // emit `field_lang` columns per language present.
            if (is_array($value) === true && $this->isLanguageKeyed(value: $value) === true) {
                foreach ($value as $lang => $langValue) {
                    if (is_string($lang) === false || $lang === '') {
                        continue;
                    }

                    $row[$key.'_'.$lang] = is_scalar($langValue) === true ? $langValue : null;
                }

                continue;
            }

            // Translatable property holding a plain string (legacy
            // single-language data): emit under `field_und` (BCP 47
            // "und" = undetermined language) so the round-trip
            // preserves the variant without guessing.
            if (is_string($value) === true) {
                $row[$key.'_und'] = $value;
                continue;
            }

            // Anything else: pass through.
            $row[$key] = is_scalar($value) === true ? $value : null;
        }//end foreach

        return $row;
    }//end flattenForCsv()

    /**
     * Reverse of `flattenForCsv`. Reconstructs the nested
     * `{lang: value}` shape from a flat row.
     *
     * Recognises any column matching `<property>_<lang>` where the
     * `<property>` portion is one of the schema's translatable
     * properties. Other `_`-suffixed columns are passed through as-is
     * (they may be unrelated user fields with underscores in the name).
     *
     * @param array<string, mixed> $row    The flat CSV row to unflatten.
     * @param Schema               $schema The schema describing translatable properties.
     *
     * @return array<string, mixed>
     */
    public function unflattenFromCsv(array $row, Schema $schema): array
    {
        $translatableProps = $this->translationHandler->getTranslatableProperties($schema);
        $out = [];

        foreach ($row as $column => $value) {
            // Check if this column matches a translatable-property + language suffix.
            $matched = false;
            foreach ($translatableProps as $prop) {
                $prefix = $prop.'_';
                if (str_starts_with($column, $prefix) === true) {
                    $lang = substr($column, strlen($prefix));
                    if ($lang === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9-]{0,15}$/', $lang) !== 1) {
                        continue;
                    }

                    if (is_string($value) === false || $value === '') {
                        // Empty cells: don't write a slot (lets the
                        // projection treat this as "not translated").
                        $matched = true;
                        break;
                    }

                    if (isset($out[$prop]) === false || is_array($out[$prop]) === false) {
                        $out[$prop] = [];
                    }

                    $out[$prop][$lang] = $value;
                    $matched           = true;
                    break;
                }//end if
            }//end foreach

            if ($matched === true) {
                continue;
            }

            // Untranslatable / unrecognised column: pass through.
            $out[$column] = $value;
        }//end foreach

        return $out;
    }//end unflattenFromCsv()

    /**
     * Detect whether an array's keys are all BCP 47-style language codes.
     *
     * @param array<mixed> $value The array whose keys should be inspected.
     *
     * @return bool True when every key looks like a language code.
     */
    private function isLanguageKeyed(array $value): bool
    {
        if (count($value) === 0) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (is_string($key) === false || preg_match('/^[a-zA-Z][a-zA-Z0-9-]{0,15}$/', $key) !== 1) {
                return false;
            }
        }

        return true;
    }//end isLanguageKeyed()
}//end class
