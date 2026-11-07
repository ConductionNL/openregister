<?php

/**
 * OpenRegister Language Service
 *
 * Request-scoped service that stores the resolved language from the Accept-Language header.
 * Used by RenderObject and SaveObject to determine which translation variant to serve or store.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
class LanguageService {

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
	 * @var boolean
	 */
	private bool $returnAll = false;

	/**
	 * Whether a fallback was used (requested language not available).
	 *
	 * @var boolean
	 */
	private bool $fallbackUsed = false;

	/**
	 * Source of the resolved preferred language, for introspection.
	 *
	 * One of `'query'`, `'header'`, or `'default'`. Used by the
	 * `X-Source-Language` response setter (i18n-source-of-truth) and
	 * external diagnostics tooling.
	 *
	 * @var string
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
	 */
	private string $requestedLanguageSource = 'default';

	/**
	 * Optional BCP-47 target language for write requests, read from the
	 * `X-Translation-Target-Language` header.
	 *
	 * When set, the TranslationHandler treats scalar request bodies as
	 * updates to that target language instead of wrapping under the
	 * register's default language.
	 *
	 * @var string|null
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	private ?string $targetLanguage = null;

	/**
	 * Set the preferred language.
	 *
	 * @param string $language The BCP 47 language code
	 *
	 * @return void
	 */
	public function setPreferredLanguage(string $language): void {
		$this->preferredLanguage = $language;
	}//end setPreferredLanguage()

	/**
	 * Get the preferred language.
	 *
	 * @return string The BCP 47 language code
	 */
	public function getPreferredLanguage(): string {
		return $this->preferredLanguage;
	}//end getPreferredLanguage()

	/**
	 * Set the full list of accepted languages in priority order.
	 *
	 * @param string[] $languages Array of BCP 47 language codes
	 *
	 * @return void
	 */
	public function setAcceptedLanguages(array $languages): void {
		$this->acceptedLanguages = $languages;
	}//end setAcceptedLanguages()

	/**
	 * Get the full list of accepted languages in priority order.
	 *
	 * @return string[] Array of BCP 47 language codes
	 */
	public function getAcceptedLanguages(): array {
		return $this->acceptedLanguages;
	}//end getAcceptedLanguages()

	/**
	 * Set whether all translations should be returned.
	 *
	 * @param bool $returnAll True to return all translation variants
	 *
	 * @return void
	 */
	public function setReturnAllTranslations(bool $returnAll): void {
		$this->returnAll = $returnAll;
	}//end setReturnAllTranslations()

	/**
	 * Check if all translations should be returned.
	 *
	 * @return bool True if _translations=all was requested
	 *
	 * @spec exclude Trivial boolean getter for the request-scoped returnAll flag; no business logic.
	 */
	public function shouldReturnAllTranslations(): bool {
		return $this->returnAll;
	}//end shouldReturnAllTranslations()

	/**
	 * Mark that a fallback language was used.
	 *
	 * @param bool $fallback True if fallback was needed
	 *
	 * @return void
	 */
	public function setFallbackUsed(bool $fallback): void {
		$this->fallbackUsed = $fallback;
	}//end setFallbackUsed()

	/**
	 * Check if a fallback language was used.
	 *
	 * @return bool True if the served language differs from the requested one
	 */
	public function isFallbackUsed(): bool {
		return $this->fallbackUsed;
	}//end isFallbackUsed()

	/**
	 * Set the source of the resolved preferred language.
	 *
	 * @param string $source One of `'query'`, `'header'`, `'default'`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
	 */
	public function setRequestedLanguageSource(string $source): void {
		if (in_array($source, ['query', 'header', 'default'], true) === false) {
			return;
		}

		$this->requestedLanguageSource = $source;
	}//end setRequestedLanguageSource()

	/**
	 * Get the source of the resolved preferred language.
	 *
	 * @return string One of `'query'`, `'header'`, `'default'`.
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
	 */
	public function getRequestedLanguageSource(): string {
		return $this->requestedLanguageSource;
	}//end getRequestedLanguageSource()

	/**
	 * Set the write-side target language for the active request.
	 *
	 * Populated by `LanguageMiddleware::beforeController` when the
	 * `X-Translation-Target-Language` header is present on a
	 * POST/PUT/PATCH. Read by `TranslationHandler::normalizeTranslationsForSave`.
	 *
	 * @param string|null $language BCP-47 tag, or null to clear.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	public function setTargetLanguage(?string $language): void {
		if ($language === null || trim($language) === '') {
			$this->targetLanguage = null;
			return;
		}

		$this->targetLanguage = trim($language);
	}//end setTargetLanguage()

	/**
	 * Get the write-side target language for the active request.
	 *
	 * @return string|null BCP-47 tag, or null when not set.
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	public function getTargetLanguage(): ?string {
		return $this->targetLanguage;
	}//end getTargetLanguage()

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
	 *
	 * @spec openspec/specs/register-i18n/spec.md#fallback-language-chain (matches accepted languages against a
	 *       register's available languages in priority order, with base-language fallback and register-default
	 *       fallback flagged as fallbackUsed)
	 */
	public function resolveLanguageForRegister(array $registerLanguages): string {
		if (empty($registerLanguages) === true) {
			return $this->preferredLanguage;
		}

		$match = $this->matchAcceptedLanguage(registerLanguages: $registerLanguages);
		if ($match !== null) {
			return $match;
		}

		// Fall back to register's default language (first in list).
		$this->fallbackUsed = true;
		return $registerLanguages[0];
	}//end resolveLanguageForRegister()

	/**
	 * The first accepted language a register offers, in priority order.
	 *
	 * Each accepted language is tried as-is, then as its lower-cased base
	 * language (e.g. "en" from "en-US").
	 *
	 * @param array $registerLanguages Array of language codes from the register
	 *
	 * @return string|null The match, or null when none of the accepted languages is offered
	 */
	private function matchAcceptedLanguage(array $registerLanguages): ?string {
		foreach ($this->acceptedLanguages as $accepted) {
			foreach ([$accepted, strtolower(explode('-', $accepted)[0])] as $candidate) {
				if (in_array($candidate, $registerLanguages, true) === true) {
					return $candidate;
				}
			}
		}

		return null;
	}//end matchAcceptedLanguage()

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
	 *
	 * @spec openspec/specs/register-i18n/spec.md#the-api-must-support-language-negotiation-via-accept-language-header (parses the Accept-Language
	 *       header per RFC 9110, ordering language tags by descending q-value then appearance)
	 */
	public static function parseAcceptLanguageHeader(string $headerValue): array {
		if (trim($headerValue) === '' || $headerValue === '*') {
			return [];
		}

		$languages = self::parseLanguageRanges(headerValue: $headerValue);

		// Sort by quality descending; usort is stable, so ties keep their order of appearance.
		usort($languages, static fn (array $left, array $right): int => $right['quality'] <=> $left['quality']);

		return array_column($languages, 'language');
	}//end parseAcceptLanguageHeader()

	/**
	 * Parse every non-empty, non-wildcard range of an Accept-Language header, in order of appearance.
	 *
	 * @param string $headerValue The Accept-Language header value
	 *
	 * @return array<int,array{language: string, quality: float}>
	 */
	private static function parseLanguageRanges(string $headerValue): array {
		$languages = [];
		foreach (explode(',', $headerValue) as $part) {
			$range = self::parseLanguageRange(part: $part);
			if ($range !== null) {
				$languages[] = $range;
			}
		}

		return $languages;
	}//end parseLanguageRanges()

	/**
	 * Parse one comma-separated range of an Accept-Language header.
	 *
	 * @param string $part The range, e.g. "en;q=0.9"
	 *
	 * @return array{language: string, quality: float}|null Null for an empty or wildcard range
	 */
	private static function parseLanguageRange(string $part): ?array {
		// Split on semicolon to separate language from its parameters.
		$segments = explode(';', trim($part));
		$language = trim($segments[0]);
		if ($language === '' || $language === '*') {
			return null;
		}

		return [
			'language' => $language,
			'quality' => self::qualityOf(parameters: array_slice($segments, 1)),
		];
	}//end parseLanguageRange()

	/**
	 * The quality factor of a range: the first `q=` parameter, default 1.0.
	 *
	 * @param string[] $parameters The range's parameters after the language
	 *
	 * @return float
	 */
	private static function qualityOf(array $parameters): float {
		foreach ($parameters as $parameter) {
			$parameter = trim($parameter);
			if (strpos($parameter, 'q=') === 0) {
				return (float)substr($parameter, 2);
			}
		}

		return 1.0;
	}//end qualityOf()
}//end class
