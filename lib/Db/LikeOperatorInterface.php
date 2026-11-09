<?php

/**
 * OpenRegister LikeOperatorInterface.
 *
 * The `like` property filter as callers outside lib/Db use it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * The `like` filter key and the SQL it builds for a list of terms.
 *
 * Callers outside lib/Db get an instance from {@see LikeOperators::forPlatform()}.
 */
interface LikeOperatorInterface {

	/**
	 * The filter key: `?title[like]=foo`.
	 */
	public const KEY = 'like';

	/**
	 * The non-empty scalar terms of a `like` value (one string or a list).
	 *
	 * @param mixed $value The value under the `like` key.
	 *
	 * @return array<int, string> The non-empty terms.
	 */
	public function terms(mixed $value): array;

	/**
	 * The LIKE pattern for one term: escaped, wrapped in `%`.
	 *
	 * @param string $term The user's search term.
	 *
	 * @return string The pattern, to bind as a parameter.
	 */
	public function pattern(string $term): string;

	/**
	 * The OR of one condition per pattern, or null when there are none.
	 *
	 * @param string             $column      The column reference, already qualified or quoted.
	 * @param array<int, string> $patternSqls The patterns as SQL (placeholders or quoted literals).
	 *
	 * @return string|null The SQL condition, or null when there is nothing to match.
	 */
	public function anyCondition(string $column, array $patternSqls): ?string;
}//end interface
