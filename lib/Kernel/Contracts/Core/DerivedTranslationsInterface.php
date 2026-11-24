<?php
/**
 * DerivedTranslationsInterface: mark the translations derived from a changed source value as outdated.
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

use OCA\OpenRegister\Db\TranslationSlot;

/**
 * Flips the translations derived from one source slot to outdated.
 *
 * The object save path (Core) tells when a source-language value changed; the
 * translation status (Configuration) keeps the rows and binds its implementation
 * in its own bindings.
 */
interface DerivedTranslationsInterface {

	/**
	 * Mark every translation derived from the source slot as outdated.
	 *
	 * @param TranslationSlot $source The object, property and source language whose value changed.
	 *
	 * @return int The number of derived rows marked.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
	 */
	public function markDerivedTranslationsOutdated(TranslationSlot $source): int;
}//end interface
