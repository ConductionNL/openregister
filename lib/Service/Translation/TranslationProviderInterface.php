<?php

/**
 * OpenRegister TranslationProviderInterface
 *
 * Strategy interface for machine translation. Concrete implementations
 * wire one of: LibreTranslate, DeepL, Google Cloud Translation, or any
 * future provider. v1 ships an `IdentityTranslationProvider` (returns
 * the source text verbatim) so the bulk-translate UI works for testing
 * without provisioning external API keys.
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

interface TranslationProviderInterface
{
    /**
     * Translate `$text` from `$fromLang` to `$toLang`.
     *
     * Both arguments are BCP 47 language codes (e.g. "nl", "en", "fr-CA").
     * Returns the translated string, or null when the provider can't
     * service this language pair / hits a transient error / has no
     * configured API key. Callers MUST handle null gracefully — the
     * bulk service skips the slot rather than persisting null.
     *
     * @param string $text     The source text to translate.
     * @param string $fromLang BCP 47 source language code.
     * @param string $toLang   BCP 47 target language code.
     *
     * @return string|null The translated text, or null on miss/error.
     */
    public function translate(string $text, string $fromLang, string $toLang): ?string;

    /**
     * Identifier used for status attribution and logging.
     *
     * Returned value lands in `Translation::translator` as
     * `provider:{identifier}` so audits can distinguish machine
     * vs human translations.
     *
     * @return string The provider identifier slug.
     */
    public function getIdentifier(): string;
}//end interface
