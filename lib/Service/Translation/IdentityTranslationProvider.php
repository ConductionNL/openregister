<?php

/**
 * OpenRegister IdentityTranslationProvider
 *
 * Default no-op implementation: returns the source text unchanged.
 * Useful for testing the bulk-translate flow + status state machine
 * without an external API key. Operators register a real provider
 * (LibreTranslate / DeepL / Google) in `Application.php` to get
 * actual machine translations.
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

class IdentityTranslationProvider implements TranslationProviderInterface
{
    /**
     * Identity translation: returns the source text unchanged.
     *
     * @param string $text     The source text.
     * @param string $fromLang BCP 47 source language code.
     * @param string $toLang   BCP 47 target language code.
     *
     * @return string|null Always the source text (passthrough).
     */
    public function translate(string $text, string $fromLang, string $toLang): ?string
    {
        // Same-language translation is a degenerate but valid request.
        // Different-language: passthrough (the operator should plug a
        // real provider in front of this for real translations).
        return $text;
    }//end translate()

    /**
     * Provider identifier used for status attribution.
     *
     * @return string The literal `identity`.
     */
    public function getIdentifier(): string
    {
        return 'identity';
    }//end getIdentifier()
}//end class
