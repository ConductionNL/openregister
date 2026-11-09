<?php

/**
 * OpenRegister TranslationSlot
 *
 * The object, property and language that key one translation row.
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
 * The slot key of one translation row: `(object_uuid, property, language)`.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationSlot {
	/**
	 * Construct the slot key.
	 *
	 * @param string $objectUuid UUID of the object being translated.
	 * @param string $property   Property path within the object.
	 * @param string $language   BCP-47 language tag of the slot.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $property,
		public readonly string $language,
	) {
	}//end __construct()
}//end class
