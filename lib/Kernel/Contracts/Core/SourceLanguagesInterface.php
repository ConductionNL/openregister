<?php
/**
 * SourceLanguagesInterface: the language a translatable property of an object is written in.
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

use OCA\OpenRegister\Service\SourceLanguageLookup;

/**
 * Resolves the source language of one translatable property.
 *
 * The object save path (Core) asks when it compares an update's old and new
 * source values; the translation projection (Configuration) owns the rule and
 * binds its implementation in its own bindings.
 */
interface SourceLanguagesInterface {

	/**
	 * The source language of the property the lookup names.
	 *
	 * @param SourceLanguageLookup $lookup The schema, the object, the property and the register's default language.
	 *
	 * @return string The BCP-47 source language.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
	 */
	public function resolveSourceLanguage(SourceLanguageLookup $lookup): string;
}//end interface
