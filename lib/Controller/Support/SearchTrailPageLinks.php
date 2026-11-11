<?php

/**
 * OpenRegister SearchTrailPageLinks
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * The next and previous page links of a search-trail listing, as `_page` parameters of the current URL.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
 */
final class SearchTrailPageLinks {
	/**
	 * Links built from the URL of the current request.
	 *
	 * @param string $currentUrl The URL of the current request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function __construct(
		private readonly string $currentUrl,
	) {
	}//end __construct()

	/**
	 * The `next` link while a later page exists and the `prev` link past the first page.
	 *
	 * @param int|float $page  The current page.
	 * @param int|float $pages The number of pages.
	 *
	 * @return array{next?: string, prev?: string} The links that apply.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function around(int|float $page, int|float $pages): array {
		$targets = [];
		if ($page < $pages) {
			$targets['next'] = ($page + 1);
		}

		if ($page > 1) {
			$targets['prev'] = ($page - 1);
		}

		return array_map(fn (int|float $target): string => $this->toPage(page: $target), $targets);
	}//end around()

	/**
	 * The URL with its `_page` (or legacy `page`) parameter set to a page, `&_page=` appended when it has neither.
	 *
	 * @param int|float $page The page to link to.
	 *
	 * @return string The URL of that page.
	 */
	private function toPage(int|float $page): string {
		$url = preg_replace('/([?&])_page=\d+/', '$1_page=' . $page, $this->currentUrl);
		if (strpos($url, '_page=') !== false) {
			return $url;
		}

		// Also handle the legacy 'page' parameter.
		$url = preg_replace('/([?&])page=\d+/', '$1_page=' . $page, $url);
		if (strpos($url, '_page=') !== false) {
			return $url;
		}

		return $url . '&_page=' . $page;
	}//end toPage()
}//end class
