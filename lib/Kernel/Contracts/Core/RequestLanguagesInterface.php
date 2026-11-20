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
 * the response shows. The language service that negotiates them extends this
 * interface, so whatever is bound to it answers here too.
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
}//end interface
