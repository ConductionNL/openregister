<?php

/**
 * OpenRegister BookmarkSearch
 *
 * A picker search over one user's Nextcloud Bookmarks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Whose bookmarks a picker lists, filtered by an optional title/url
 * substring and optional tags.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class BookmarkSearch {

	/**
	 * Describe the search.
	 *
	 * @param string             $userId The user whose bookmarks are listed.
	 * @param string             $text   Title/url substring filter; '' for none.
	 * @param array<int, string> $tags   Tag filter; empty for none.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $text,
		public readonly array $tags,
	) {
	}//end __construct()
}//end class
