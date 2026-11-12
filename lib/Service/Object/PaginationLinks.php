<?php

/**
 * OpenRegister PaginationLinks
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The next and previous page URLs of a paginated list, built from the request URI.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class PaginationLinks {

	/**
	 * Links built from one request URI.
	 *
	 * @param string $uri The current request URI.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly string $uri,
	) {
	}//end __construct()

	/**
	 * The `next` and `prev` links around a page: `next` while there are more pages, `prev` past the first.
	 *
	 * @param int $page  The current page.
	 * @param int $pages The number of pages.
	 *
	 * @return array{next?: string, prev?: string} The links that apply.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function around(int $page, int $pages): array {
		$links = [];
		if ($page < $pages) {
			$links['next'] = $this->toPage(page: ($page + 1));
		}

		if ($page > 1) {
			$links['prev'] = $this->toPage(page: ($page - 1));
		}

		return $links;
	}//end around()

	/**
	 * The URI with its `page` or `_page` parameter set, or `page` added with the right separator.
	 *
	 * @param int $page The page.
	 *
	 * @return string The URI.
	 */
	private function toPage(int $page): string {
		$rewritten = 0;
		$url = (string)preg_replace('/([?&]_?page=)[^&]*/', '${1}'.$page, $this->uri, -1, $rewritten);
		if ($rewritten > 0) {
			return $url;
		}

		$separator = '&';
		if (str_contains($url, '?') === false) {
			$separator = '?';
		}

		return $url.$separator.'page='.$page;
	}//end toPage()
}//end class
