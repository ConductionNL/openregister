<?php

/**
 * TranslationStatusServiceInterface: the part of TranslationStatusService that the translation controller uses.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Translation;

/**
 * The methods of TranslationStatusService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface TranslationStatusServiceInterface {

	/**
	 * Update the workflow status for a translation slot.
	 *
	 * Caller is the human/automation that knows the new status (e.g. a
	 * translator's UI promotes draft → human_reviewed). The translator
	 * uid is derived from the active session.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $property The property name.
	 * @param string $language The language code.
	 * @param string $status The new workflow status.
	 *
	 * @return Translation The persisted translation row.
	 *
	 * @throws InvalidArgumentException When status is invalid or no slot exists.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function setStatus(string $objectUuid, string $property, string $language, string $status): Translation;

	/**
	 * Per-object completeness ratio per language.
	 *
	 * Returns `[language => ['translated' => int, 'total' => int, 'ratio' => float]]`.
	 * `total` is the count of translatable properties on the schema;
	 * `translated` is the count of slots with non-empty values for the
	 * given language. `ratio` is `translated / total` rounded to 2dp.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param Schema $schema The schema entity.
	 *
	 * @return array<string, array{translated: int, total: int, ratio: float}>
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function completenessForObject(string $objectUuid, Schema $schema): array;

	/**
	 * Search translation rows.
	 *
	 * @param string|null $query Optional full-text query.
	 * @param string|null $language Optional language filter.
	 * @param string|null $status Optional status filter.
	 * @param string|null $objectUuid Optional object UUID filter.
	 * @param int $limit Maximum number of results.
	 * @param string|null $sourceLanguage Optional source-language filter.
	 * @param bool $isOutOfDate When true, restrict to status=outdated.
	 *
	 * @return array<string, mixed>[]
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function search(
		?string $query = null,
		?string $language = null,
		?string $status = null,
		?string $objectUuid = null,
		int $limit = 100,
		?string $sourceLanguage = null,
		bool $isOutOfDate = false,
	): array;

	/**
	 * Search translation rows and attach the source-language value side-by-side.
	 *
	 * Used to power `GET /api/translations/search?compareToSource=true` so
	 * editorial tooling can render the source vs target value pair without
	 * a follow-up round trip.
	 *
	 * @param string|null $query Optional full-text query.
	 * @param string|null $language Optional language filter.
	 * @param string|null $status Optional status filter.
	 * @param string|null $objectUuid Optional object UUID filter.
	 * @param int $limit Maximum number of results.
	 * @param string|null $sourceLanguage Optional source-language filter.
	 * @param bool $isOutOfDate When true, restrict to status=outdated.
	 *
	 * @return array<string, mixed>[] Each row carries an extra `sourceValue` field.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function searchWithSourceValues(
		?string $query = null,
		?string $language = null,
		?string $status = null,
		?string $objectUuid = null,
		int $limit = 100,
		?string $sourceLanguage = null,
		bool $isOutOfDate = false,
	): array;
}//end interface
