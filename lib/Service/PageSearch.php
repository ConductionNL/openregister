<?php

/**
 * OpenRegister Page Search
 *
 * A search over the NC Collectives pages a user can see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The Collectives page service, the user it reads for and an optional title filter.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class PageSearch {

	/**
	 * Lower-cased title filter; null for none.
	 *
	 * @var string|null
	 */
	private ?string $needle = null;

	/**
	 * Constructor.
	 *
	 * @param object $pageService The Collectives PageService.
	 * @param string $uid         The user the pages are read for.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly object $pageService,
		public readonly string $uid,
	) {
	}//end __construct()

	/**
	 * A copy that keeps only titles holding the search text, ignoring case.
	 *
	 * @param string|null $search Title text; null or '' for no filter.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function matching(?string $search): self {
		$copy = clone $this;
		$copy->needle = null;
		if ($search !== null && $search !== '') {
			$copy->needle = mb_strtolower($search);
		}

		return $copy;
	}//end matching()

	/**
	 * Whether a page title passes the filter.
	 *
	 * @param string $title Page title.
	 *
	 * @return bool True when there is no filter or the title holds the search text.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function matches(string $title): bool {
		return $this->needle === null || str_contains(mb_strtolower($title), $this->needle) === true;
	}//end matches()
}//end class
