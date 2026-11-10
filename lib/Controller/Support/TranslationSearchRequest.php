<?php

/**
 * OpenRegister TranslationSearchRequest
 *
 * The query parameters of a translation search, read from the request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/register-i18n/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ListQuery;

/**
 * The row filters and the listing switches of one translation search.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationSearchRequest {
	/**
	 * Constructor.
	 *
	 * @param TranslationFilter        $filter  The row filters.
	 * @param TranslationSearchOptions $options The listing and the switches.
	 */
	public function __construct(
		public readonly TranslationFilter $filter,
		public readonly TranslationSearchOptions $options,
	) {
	}//end __construct()

	/**
	 * Read a search from the request parameters.
	 *
	 * Reads `query`, `language`, `status`, `objectUuid`, `limit` (clamped to
	 * 1..1000, default 100), `sourceLanguage`, `isOutOfDate` and
	 * `compareToSource`.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return self The search.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public static function fromParams(array $params): self {
		$filter = new TranslationFilter(
			language: self::text(params: $params, key: 'language'),
			status: self::text(params: $params, key: 'status'),
			objectUuid: self::text(params: $params, key: 'objectUuid'),
			sourceLanguage: self::text(params: $params, key: 'sourceLanguage')
		);
		$limit   = (int)($params['limit'] ?? 100);
		$listing = new ListQuery(limit: max(1, min(1000, $limit)), search: self::text(params: $params, key: 'query'));

		return new self(
			filter: $filter,
			options: new TranslationSearchOptions(
				listing: $listing,
				isOutOfDate: self::text(params: $params, key: 'isOutOfDate'),
				compareToSource: self::text(params: $params, key: 'compareToSource')
			)
		);
	}//end fromParams()

	/**
	 * A scalar parameter as a string, or null when absent or not scalar.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $key    The parameter name.
	 *
	 * @return string|null The value.
	 */
	private static function text(array $params, string $key): ?string {
		$value = ($params[$key] ?? null);
		if (is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end text()
}//end class
