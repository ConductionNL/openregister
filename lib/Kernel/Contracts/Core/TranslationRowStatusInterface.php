<?php
/**
 * TranslationRowStatusInterface: the workflow status of the stored translation of one slot.
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
 * Reads the workflow status of the stored translation of one slot.
 *
 * The object render (Core) reports it per property in the language meta; the
 * translation store (Configuration) keeps the rows and binds its implementation
 * in its own bindings.
 */
interface TranslationRowStatusInterface {

	/**
	 * The workflow status of the slot's stored translation.
	 *
	 * @param TranslationSlot $slot The object, property and language.
	 *
	 * @return string|null The row's status (draft when the row has none), or null when there is no row.
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	public function rowStatus(TranslationSlot $slot): ?string;
}//end interface
