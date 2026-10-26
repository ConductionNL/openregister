<?php

/**
 * OpenRegister DeletedLens.
 *
 * The deleted lens on an object list: the working set, the soft-deleted rows
 * alone, or both.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
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

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * Reads `_deleted` (and the older `_includeDeleted`) off a query and spells
 * the predicate, once for every path that builds a list, a count or facets.
 *
 * @spec openspec/changes/object-deleted-lens/specs/object-lifecycle/spec.md
 */
final class DeletedLens {

	/**
	 * The working set: soft-deleted rows are left out. The default.
	 *
	 * @var string
	 */
	public const EXCLUDE = 'exclude';

	/**
	 * The deleted lens: soft-deleted rows and nothing else.
	 *
	 * @var string
	 */
	public const ONLY = 'only';

	/**
	 * Both at once. The literal is the parameter value callers send
	 * (`_deleted=any`), and what the older `_includeDeleted=true` means.
	 *
	 * @var string
	 */
	public const ANY = 'any';

	/**
	 * Which lens a query asks for.
	 *
	 * `_deleted` absent or false is the working set, `_deleted=true` is the
	 * soft-deleted rows alone and `_deleted=any` is both. Without `_deleted`,
	 * `_includeDeleted=true` still means both. Anything else reads as the
	 * working set, so an unknown value never widens a list.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return string One of the constants.
	 */
	public function mode(array $query): string {
		if (array_key_exists('_deleted', $query) === false) {
			if (filter_var($query['_includeDeleted'] ?? false, FILTER_VALIDATE_BOOLEAN) === true) {
				return self::ANY;
			}

			return self::EXCLUDE;
		}

		$raw = $query['_deleted'];
		if (is_string($raw) === true && strtolower(trim($raw)) === self::ANY) {
			return self::ANY;
		}

		if (filter_var($raw, FILTER_VALIDATE_BOOLEAN) === true) {
			return self::ONLY;
		}

		return self::EXCLUDE;
	}//end mode()

	/**
	 * The predicate for a lens, or null when it puts no condition on the list.
	 *
	 * @param string $mode         One of the constants.
	 * @param string $columnPrefix The table alias with its dot, or empty.
	 *
	 * @return string|null The predicate, without a leading AND.
	 */
	public function condition(string $mode, string $columnPrefix): ?string {
		if ($mode === self::ANY) {
			return null;
		}

		if ($mode === self::ONLY) {
			return $columnPrefix.'_deleted IS NOT NULL';
		}

		return $columnPrefix.'_deleted IS NULL';
	}//end condition()
}//end class
