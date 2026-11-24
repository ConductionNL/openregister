<?php
/**
 * TranslationCompletenessInterface: how completely one object is translated, per language.
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

use OCA\OpenRegister\Db\Schema;

/**
 * Reports the per-language translation completeness of one object.
 *
 * The object render (Core) adds it to the object's metadata; the translation
 * status (Configuration) counts the rows and binds its implementation in its own
 * bindings.
 */
interface TranslationCompletenessInterface {

	/**
	 * The completeness ratio per language for one object.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param Schema $schema     The object's schema, which names the translatable properties.
	 *
	 * @return array<string, array{translated: int, total: int, ratio: float}> Counts and ratio per language.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function completenessForObject(string $objectUuid, Schema $schema): array;
}//end interface
