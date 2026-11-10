<?php

/**
 * OpenRegister TranslationFilter
 *
 * The column filters of a translation search.
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
 * The language, status, object and source-language filters of a translation search.
 *
 * A null or empty filter does not narrow the search.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
 */
final class TranslationFilter {
	/**
	 * Construct the filter.
	 *
	 * @param string|null $language       Optional language filter (BCP-47 tag).
	 * @param string|null $status         Optional workflow-status filter.
	 * @param string|null $objectUuid     Optional object-uuid scope.
	 * @param string|null $sourceLanguage Optional source-language filter (BCP-47 tag).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $language = null,
		public readonly ?string $status = null,
		public readonly ?string $objectUuid = null,
		public readonly ?string $sourceLanguage = null,
	) {
	}//end __construct()

	/**
	 * The column equalities this filter asks for, in query order.
	 *
	 * @param bool $isOutOfDate When true, also narrows to status='outdated'.
	 *
	 * @return array<int, array{0: string, 1: string|null}> Column and value pairs.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function equalities(bool $isOutOfDate): array {
		$filters = [
			['language', $this->language],
			['status', $this->status],
			['object_uuid', $this->objectUuid],
			['source_language', $this->sourceLanguage],
		];
		if ($isOutOfDate === true) {
			$filters[] = ['status', Translation::STATUS_OUTDATED];
		}

		return $filters;
	}//end equalities()
}//end class
