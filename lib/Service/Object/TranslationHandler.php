<?php

/**
 * OpenRegister Translation Handler
 *
 * Handler class responsible for resolving translatable properties in objects.
 * Supports both reading (selecting the correct language variant) and writing
 * (normalizing input to language-keyed objects) for translatable properties.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\LanguageService;
use Psr\Log\LoggerInterface;

/**
 * Handler for translatable property resolution in objects.
 *
 * This handler reads the schema's property definitions to determine which
 * properties are translatable, then either resolves them to a single language
 * value (for rendering) or normalizes them to language-keyed objects (for saving).
 *
 * @package OCA\OpenRegister\Service\Object
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class TranslationHandler
{
    /**
     * Constructor.
     *
     * @param LanguageService $languageService The request-scoped language service
     * @param LoggerInterface $logger          Logger interface
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    public function __construct(
        private readonly LanguageService $languageService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Get the list of translatable property names from a schema.
     *
     * Inspects the schema's properties and returns the names of those
     * that have `translatable: true` in their definition.
     *
     * @param Schema $schema The schema to inspect
     *
     * @return string[] Array of translatable property names
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    public function getTranslatableProperties(Schema $schema): array
    {
        $properties   = $schema->getProperties() ?? [];
        $translatable = [];

        foreach ($properties as $propertyName => $propertyDef) {
            if (is_array($propertyDef) === false) {
                continue;
            }

            if (($propertyDef['translatable'] ?? false) === true) {
                $translatable[] = $propertyName;
            }
        }

        return $translatable;
    }//end getTranslatableProperties()

    /**
     * Resolve translatable properties in object data for rendering.
     *
     * For each translatable property:
     * - If _translations=all is requested, returns the full language object
     * - Otherwise, resolves to the single value for the best matching language
     * - Falls back to the register's default language if requested language is missing
     *
     * @param array         $objectData The object data array
     * @param Schema        $schema     The schema for property definitions
     * @param Register|null $register   The register for language configuration
     *
     * @return array The object data with resolved translations
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    public function resolveTranslationsForRender(
        array $objectData,
        Schema $schema,
        ?Register $register=null
    ): array {
        // If returning all translations, no resolution needed.
        if ($this->languageService->shouldReturnAllTranslations() === true) {
            return $objectData;
        }

        $translatableProps = $this->getTranslatableProperties(schema: $schema);

        if (empty($translatableProps) === true) {
            return $objectData;
        }

        // Determine register languages and resolve best language.
        $registerLanguages = [];
        $defaultLanguage   = 'nl';
        if ($register !== null) {
            $registerLanguages = $register->getLanguages() ?? [];
            $defaultLanguage   = $register->getDefaultLanguage();
        }

        $resolvedLanguage = $this->languageService->resolveLanguageForRegister($registerLanguages);

        // Build the per-property fallback chain (Decision 2 from
        // register-i18n architecture pass): try the user's resolved
        // language first, then walk the register's languages list in
        // declared order, then any remaining variant. Each property
        // resolves independently — a missing NL value for `body`
        // doesn't force `title` (which has NL) to fall back too.
        $chain = [$resolvedLanguage];
        foreach ($registerLanguages as $lang) {
            if (in_array($lang, $chain, true) === false) {
                $chain[] = $lang;
            }
        }

        if (in_array($defaultLanguage, $chain, true) === false) {
            $chain[] = $defaultLanguage;
        }

        foreach ($translatableProps as $propName) {
            if (isset($objectData[$propName]) === false) {
                continue;
            }

            $value = $objectData[$propName];

            // Only resolve if the value is a language-keyed object (associative array).
            if (is_array($value) === false || $this->isLanguageKeyedObject(value: $value) === false) {
                continue;
            }

            // Walk the configured chain. If the picked language isn't
            // the user's resolved one, mark fallback-used so the
            // Content-Language response header can advertise it.
            $picked = null;
            foreach ($chain as $candidate) {
                if (isset($value[$candidate]) === true) {
                    $picked = $candidate;
                    $objectData[$propName] = $value[$candidate];
                    if ($candidate !== $resolvedLanguage) {
                        $this->languageService->setFallbackUsed(true);
                    }

                    break;
                }
            }

            // Final fallback: any available variant (useful when an
            // object carries a translation in a language not in the
            // register's configured chain — e.g. legacy data).
            if ($picked === null) {
                $firstValue = reset($value);
                if ($firstValue !== false) {
                    $objectData[$propName] = $firstValue;
                    $this->languageService->setFallbackUsed(true);
                }
            }
        }//end foreach

        return $objectData;
    }//end resolveTranslationsForRender()

    /**
     * Normalize translatable properties in object data for saving.
     *
     * For each translatable property:
     * - If the value is already a language-keyed object, stores as-is
     * - If the value is a simple (non-array) value, wraps it under the default language
     * - Validates that the default language always has a value
     *
     * @param array         $objectData The incoming object data
     * @param Schema        $schema     The schema for property definitions
     * @param Register|null $register   The register for language configuration
     *
     * @return array The normalized object data with translations wrapped correctly
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    public function normalizeTranslationsForSave(
        array $objectData,
        Schema $schema,
        ?Register $register=null
    ): array {
        $translatableProps = $this->getTranslatableProperties(schema: $schema);

        if (empty($translatableProps) === true) {
            return $objectData;
        }

        $defaultLanguage = 'nl';
        if ($register !== null) {
            $defaultLanguage = $register->getDefaultLanguage();
        }

        foreach ($translatableProps as $propName) {
            if (isset($objectData[$propName]) === false) {
                continue;
            }

            $value = $objectData[$propName];

            // If it's already a language-keyed object, validate and keep.
            if (is_array($value) === true && $this->isLanguageKeyedObject(value: $value) === true) {
                // Ensure default language has a value.
                if (isset($value[$defaultLanguage]) === false || $value[$defaultLanguage] === null) {
                    $this->logger->warning(
                        message: '[TranslationHandler] Translatable property missing default language value',
                        context: [
                            'file'            => __FILE__,
                            'line'            => __LINE__,
                            'property'        => $propName,
                            'defaultLanguage' => $defaultLanguage,
                        ]
                    );
                }

                $objectData[$propName] = $value;
                continue;
            }

            // Simple value: wrap under the default language.
            if ($value !== null) {
                $objectData[$propName] = [$defaultLanguage => $value];
            }
        }//end foreach

        return $objectData;
    }//end normalizeTranslationsForSave()

    /**
     * Check if an array is a language-keyed object.
     *
     * A language-keyed object has string keys that look like BCP 47 language codes
     * (2-3 letter codes, optionally with region suffixes like "en-US").
     *
     * @param array $value The array to check
     *
     * @return bool True if this looks like a language-keyed object
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function isLanguageKeyedObject(array $value): bool
    {
        if (empty($value) === true) {
            return false;
        }

        // Check that all keys are strings that match language code patterns.
        foreach (array_keys($value) as $key) {
            if (is_string($key) === false) {
                return false;
            }

            // BCP 47 language tag pattern: 2-3 lowercase letters, optionally followed by hyphen + subtag.
            if (preg_match('/^[a-z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $key) !== 1) {
                return false;
            }
        }

        return true;
    }//end isLanguageKeyedObject()
}//end class
