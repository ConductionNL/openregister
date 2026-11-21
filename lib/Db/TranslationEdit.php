<?php

/**
 * OpenRegister TranslationEdit
 *
 * What one write puts into a translation slot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * The value, workflow status, translator and source language written into one slot.
 *
 * A null status keeps the slot's existing status, and drafts a slot that has none.
 * A null translator and an empty or null source language leave those columns as they are.
 * `TranslationMapper::upsert()` writes it; this class only carries the values.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationEdit {
	/**
	 * Construct the edit.
	 *
	 * @param string|null $value          Translated value (null clears).
	 * @param string|null $status         Optional workflow status override.
	 * @param string|null $translator     Optional UID of the translator.
	 * @param string|null $sourceLanguage Optional canonical source language for the property.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $value,
		public readonly ?string $status = null,
		public readonly ?string $translator = null,
		public readonly ?string $sourceLanguage = null,
	) {
	}//end __construct()
}//end class
