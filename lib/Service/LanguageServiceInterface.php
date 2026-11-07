<?php

/**
 * LanguageServiceInterface: what callers outside the Service component use of LanguageService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * The request-language state the language middleware writes and the Db layer reads on LanguageService.
 */
interface LanguageServiceInterface {

	/**
	 * Get the full list of accepted languages in priority order.
	 *
	 * @return string[] Array of BCP 47 language codes
	 */
	public function getAcceptedLanguages(): array;

	/**
	 * Get the preferred language.
	 *
	 * @return string The BCP 47 language code
	 */
	public function getPreferredLanguage(): string;

	/**
	 * Check if a fallback language was used.
	 *
	 * @return bool True if the served language differs from the requested one
	 */
	public function isFallbackUsed(): bool;

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
	public static function parseAcceptLanguageHeader(string $headerValue): array;

	/**
	 * Set the full list of accepted languages in priority order.
	 *
	 * @param string[] $languages Array of BCP 47 language codes
	 *
	 * @return void
	 */
	public function setAcceptedLanguages(array $languages): void;

	/**
	 * Set the preferred language.
	 *
	 * @param string $language The BCP 47 language code
	 *
	 * @return void
	 */
	public function setPreferredLanguage(string $language): void;

	/**
	 * Set the source of the resolved preferred language.
	 *
	 * @param string $source One of `'query'`, `'header'`, `'default'`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
	 */
	public function setRequestedLanguageSource(string $source): void;

	/**
	 * Set whether all translations should be returned.
	 *
	 * @param bool $returnAll True to return all translation variants
	 *
	 * @return void
	 */
	public function setReturnAllTranslations(bool $returnAll): void;

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
	public function setTargetLanguage(?string $language): void;
}//end interface
