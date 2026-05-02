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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Translation;

class IdentityTranslationProvider implements TranslationProviderInterface
{
    public function translate(string $text, string $fromLang, string $toLang): ?string
    {
        // Same-language translation is a degenerate but valid request.
        // Different-language: passthrough (the operator should plug a
        // real provider in front of this for real translations).
        return $text;
    }//end translate()

    public function getIdentifier(): string
    {
        return 'identity';
    }//end getIdentifier()
}//end class
