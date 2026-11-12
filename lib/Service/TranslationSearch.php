<?php

/**
 * Translation Search
 *
 * One search over the translation rows: the column filter, the full-text
 * query, the result limit and the outdated restriction.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/register-i18n/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\TranslationFilter;

/**
 * What a translation search asks for.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationSearch {
	/**
	 * Constructor.
	 *
	 * @param TranslationFilter $filter      Language, status, object and source-language filters.
	 * @param string|null       $query       Optional full-text query.
	 * @param int               $limit       Maximum number of results.
	 * @param bool              $isOutOfDate When true, restrict to status=outdated.
	 */
	public function __construct(
		public readonly TranslationFilter $filter = new TranslationFilter(),
		public readonly ?string $query = null,
		public readonly int $limit = 100,
		public readonly bool $isOutOfDate = false,
	) {
	}//end __construct()
}//end class
