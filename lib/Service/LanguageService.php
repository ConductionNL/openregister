<?php

/**
 * OpenRegister Language Service
 *
 * Request-scoped service that stores the resolved language from the Accept-Language header.
 * Used by RenderObject and SaveObject to determine which translation variant to serve or store.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * Request-scoped service for language negotiation.
 *
 * Stores the preferred language resolved from the Accept-Language header.
 * The LanguageMiddleware sets this early in the request lifecycle, and
 * RenderObject / SaveObject read it when processing translatable properties.
 *
 * @package OCA\OpenRegister\Service
 */
class LanguageService
{
    /**
     * The preferred language code resolved from the request.
     *
     * @var string
     */
    private string $preferredLanguage = 'nl';

    /**
     * The full list of accepted languages in priority order.
     *
     * @var string[]
     */
    private array $acceptedLanguages = [];

    /**
     * Whether the _translations=all query parameter is present.
     *
     * @var bool
     */
    private bool $returnAllTranslations = false;

    /**
     * Whether a fallback was used (requested language not available).
     *
     * @var bool
     */
    private bool $fallbackUsed = false;

    /**
     * Set the preferred language.
     *
     * @param string $language The BCP 47 language code
     *
     * @return void
     */
    public function setPreferredLanguage(string $language): void
    {
        $this->preferredLanguage = $language;
    }//end setPreferredLanguage()

    /**
     * Get the preferred language.
     *
     * @return string The BCP 47 language code
     */
    public function getPreferredLanguage(): string
    {
        return $this->preferredLanguage;
    }//end getPreferredLanguage()

    /**
     * Set the full list of accepted languages in priority order.
     *
     * @param string[] $languages Array of BCP 47 language codes
     *
     * @return void
     */
    public function setAcceptedLanguages(array $languages): void
    {
        $this->acceptedLanguages = $languages;
    }//end setAcceptedLanguages()

    /**
     * Get the full list of accepted languages in priority order.
     *
     * @return string[] Array of BCP 47 language codes
     */
    public function getAcceptedLanguages(): array
    {
        return $this->acceptedLanguages;
    }//end getAcceptedLanguages()

    /**
     * Set whether all translations should be returned.
     *
     * @param bool $returnAll True to return all translation variants
     *
     * @return void
     */
    public function setReturnAllTranslations(bool $returnAll): void
    {
        $this->returnAllTranslations = $returnAll;
    }//end setReturnAllTranslations()

    /**
     * Check if all translations should be returned.
     *
     * @return bool True if _translations=all was requested
     */
    public function shouldReturnAllTranslations(): bool
    {
        return $this->returnAllTranslations;
    }//end shouldReturnAllTranslations()

    /**
     * Mark that a fallback language was used.
     *
     * @param bool $fallback True if fallback was needed
     *
     * @return void
     */
    public function setFallbackUsed(bool $fallback): void
    {
        $this->fallbackUsed = $fallback;
    }//end setFallbackUsed()

    /**
     * Check if a fallback language was used.
     *
     * @return bool True if the served language differs from the requested one
     */
    public function isFallbackUsed(): bool
    {
        return $this->fallbackUsed;
    }//end isFallbackUsed()

    /**
     * Resolve the best matching language for a register.
     *
     * Matches the request's accepted languages against a register's
     * available languages, returning the best match or the register's
     * default language as fallback.
     *
     * @param array $registerLanguages Array of language codes from the register
     *
     * @return string The best matching language code
     */
    public function resolveLanguageForRegister(array $registerLanguages): string
    {
        if (empty($registerLanguages) === true) {
            return $this->preferredLanguage;
        }

        // Try each accepted language in priority order.
        foreach ($this->acceptedLanguages as $accepted) {
            // Exact match.
            if (in_array($accepted, $registerLanguages, true) === true) {
                return $accepted;
            }

            // Try base language (e.g., "en" from "en-US").
            $baseLang = strtolower(explode('-', $accepted)[0]);
            if (in_array($baseLang, $registerLanguages, true) === true) {
                return $baseLang;
            }
        }

        // Fall back to register's default language (first in list).
        $this->fallbackUsed = true;
        return $registerLanguages[0];
    }//end resolveLanguageForRegister()

    /**
     * Parse an Accept-Language header string per RFC 9110.
     *
     * Parses the header value into an ordered list of language codes
     * sorted by quality factor (q-value).
     *
     * Example input: "en-US,en;q=0.9,nl;q=0.8"
     * Example output: ["en-US", "en", "nl"]
     *
     * @param string $headerValue The Accept-Language header value
     *
     * @return string[] Ordered array of language codes (highest priority first)
     */
    public static function parseAcceptLanguageHeader(string $headerValue): array
    {
        if (trim($headerValue) === '' || $headerValue === '*') {
            return [];
        }

        $languages = [];
        $parts     = explode(',', $headerValue);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Split on semicolon to separate language from quality.
            $segments = explode(';', $part);
            $language = trim($segments[0]);

            if ($language === '' || $language === '*') {
                continue;
            }

            // Extract quality factor (default 1.0).
            $quality = 1.0;
            for ($i = 1; $i < count($segments); $i++) {
                $segment = trim($segments[$i]);
                if (strpos($segment, 'q=') === 0) {
                    $qValue  = substr($segment, 2);
                    $quality = (float) $qValue;
                    break;
                }
            }

            $languages[] = [
                'language' => $language,
                'quality'  => $quality,
            ];
        }//end foreach

        // Sort by quality descending, then by order of appearance.
        usort(
            $languages,
            function ($a, $b) {
                if ($a['quality'] === $b['quality']) {
                    return 0;
                }

                return ($a['quality'] > $b['quality']) ? -1 : 1;
            }
        );

        return array_map(
            function ($item) {
                return $item['language'];
            },
            $languages
        );
    }//end parseAcceptLanguageHeader()
}//end class
