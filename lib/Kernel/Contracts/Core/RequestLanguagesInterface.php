<?php
/**
 * RequestLanguagesInterface: the languages the current request accepts, as the object store reads them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The request's negotiated languages.
 *
 * The object store (Core) reads them, so a translatable property sorts by the value
 * the response shows, and the aggregation runner (Core) reads whether the request
 * asked for every translation, so it leaves translatable group keys verbatim. The
 * translation handler (Core) resolves a register's served language through it and
 * records a fallback on it, on the same request-scoped instance. The
 * language service that negotiates them extends this interface, so whatever is
 * bound to it answers here too.
 *
 * @spec openspec/specs/register-i18n/spec.md#requirement-ordering-by-a-translatable-property-must-follow-the-value-a-person-sees
 */
interface RequestLanguagesInterface {

	/**
	 * Get the full list of accepted languages in priority order.
	 *
	 * @return string[] Array of BCP 47 language codes
	 *
	 * @spec openspec/specs/register-i18n/spec.md#requirement-ordering-by-a-translatable-property-must-follow-the-value-a-person-sees
	 */
	public function getAcceptedLanguages(): array;

	/**
	 * Get the preferred language.
	 *
	 * @return string The BCP 47 language code
	 *
	 * @spec openspec/specs/register-i18n/spec.md#requirement-ordering-by-a-translatable-property-must-follow-the-value-a-person-sees
	 */
	public function getPreferredLanguage(): string;

	/**
	 * Whether the request asked for every translation (`_translations=all`).
	 *
	 * @return bool True if _translations=all was requested
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function shouldReturnAllTranslations(): bool;

	/**
	 * The best matching language for a register: the first accepted language the
	 * register offers, or the register's default (flagged as a fallback).
	 *
	 * @param array $registerLanguages The language codes the register offers.
	 *
	 * @return string The language code the response serves.
	 *
	 * @spec openspec/specs/register-i18n/spec.md#fallback-language-chain
	 */
	public function resolveLanguageForRegister(array $registerLanguages): string;

	/**
	 * Record whether the response served a fallback language.
	 *
	 * @param bool $fallback True when the served language differs from the requested one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md#fallback-language-chain
	 */
	public function setFallbackUsed(bool $fallback): void;

	/**
	 * The language a write targets in the current request.
	 *
	 * @return string|null BCP 47 tag, or null when the request named none.
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	public function getTargetLanguage(): ?string;
}//end interface
