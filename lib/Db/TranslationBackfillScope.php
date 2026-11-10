<?php

/**
 * OpenRegister TranslationBackfillScope
 *
 * The register, language and batch size of one source-language back-fill pass.
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
 * Value object for TranslationMapper::backfillSourceLanguage().
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
 */
final class TranslationBackfillScope {
	/**
	 * Construct the scope.
	 *
	 * @param string $registerId Register whose objects' rows are back-filled.
	 * @param string $language Source language to write.
	 * @param int $batchSize Maximum rows updated in the bounded pass.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $registerId,
		public readonly string $language,
		public readonly int $batchSize,
	) {
	}//end __construct()
}//end class
