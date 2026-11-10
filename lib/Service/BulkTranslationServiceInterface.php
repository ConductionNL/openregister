<?php

/**
 * BulkTranslationServiceInterface: the per-object bulk translation of BulkTranslationService that controllers use.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The methods of BulkTranslationService that callers outside the Service component use.
 */
interface BulkTranslationServiceInterface {

	/**
	 * Translate an object's translatable properties from one language to another.
	 *
	 * Fills only slots that are currently empty in the target language.
	 *
	 * Returns a per-property patch:
	 *   `[propertyName => translatedValue]`
	 * The caller merges this into the object's `{lang: value}` JSONB
	 * map for `$toLong` and persists via the standard save path —
	 * which triggers the projection listener to populate the sidecar.
	 *
	 * Note: this method also writes directly to the sidecar so the
	 * translation is queryable immediately, even before the caller
	 * persists the object. Callers who don't want that immediate
	 * write should invoke the provider directly.
	 *
	 * @param ObjectEntity $object The object to translate.
	 * @param string $fromLong Source language code.
	 * @param string $toLong Target language code.
	 * @param string[]|null $properties Optional whitelist of property
	 *                                  names to translate; null = all.
	 *
	 * @return array{translated: array<string, string>, skipped: array<string, string>}
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function translateObject(
		ObjectEntity $object,
		string $fromLong,
		string $toLong,
		?array $properties = null,
	): array;
}//end interface
