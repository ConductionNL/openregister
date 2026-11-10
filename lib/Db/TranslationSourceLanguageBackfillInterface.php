<?php

/**
 * TranslationSourceLanguageBackfillInterface: the source-language back-fill of the translations mapper.
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
 * The two queries the source-language back-fill command runs.
 *
 * Bound to TranslationMapper in DbRegistrar.
 */
interface TranslationSourceLanguageBackfillInterface {

	/**
	 * Back-fill the source_language column on rows that have an empty value.
	 *
	 * @param array<int|string, string> $registerDefaults Map of `register_id => default_language`.
	 * @param int                       $batchSize        Maximum rows updated per pass.
	 *
	 * @return int Number of rows updated across all batches.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
	 */
	public function backfillSourceLanguage(array $registerDefaults, int $batchSize = 1000): int;

	/**
	 * Count rows whose `source_language` is the empty back-fill default.
	 *
	 * @return int Count of rows still pending back-fill.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
	 */
	public function countMissingSourceLanguage(): int;
}//end interface
