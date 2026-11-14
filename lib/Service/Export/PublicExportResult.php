<?php

/**
 * OpenRegister PublicExportResult
 *
 * What an anonymous export answers: the file and its paging, or the refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * The outcome of one anonymous export.
 *
 * Either a refusal (a status code and its body, no content) or a page of the
 * file with the numbers a harvester walks the set by (design D-4).
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */
final class PublicExportResult {

	/**
	 * Describe the outcome.
	 *
	 * @param ExportRefusedException|null $refusal The refusal, or null when the file is served.
	 * @param string|null                 $content The file content, or null when refused.
	 * @param ExportPage                  $page    The page that was read.
	 * @param int                         $total   How many rows the whole selection holds.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ?ExportRefusedException $refusal,
		public readonly ?string $content,
		public readonly ExportPage $page = new ExportPage(),
		public readonly int $total = 0,
	) {
	}//end __construct()

	/**
	 * A refused anonymous export.
	 *
	 * @param ExportRefusedException $refusal The refusal.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public static function refused(ExportRefusedException $refusal): self {
		return new self(refusal: $refusal, content: null);
	}//end refused()

	/**
	 * The HTTP status: 200 for a served page, else the refusal's.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function statusCode(): int {
		return ($this->refusal?->getStatusCode() ?? 200);
	}//end statusCode()

	/**
	 * The refusal's response body, or an empty one when the page is served.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function refusalBody(): array {
		return ($this->refusal?->toResponseBody() ?? []);
	}//end refusalBody()

	/**
	 * How many rows this page holds: a full page, or what is left on the last.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function rowCount(): int {
		if ($this->content === null) {
			return 0;
		}

		$before = (($this->page->number - 1) * $this->page->size);

		return max(0, min($this->page->size, ($this->total - $before)));
	}//end rowCount()

	/**
	 * How many pages the selection takes at this page size.
	 *
	 * @return int The page count, at least 1.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function pages(): int {
		return $this->page->pagesFor(total: $this->total);
	}//end pages()

	/**
	 * The paging headers of the served page.
	 *
	 * `Link rel="next"` is sent only while a later page exists, and points at
	 * the same request with `_page` advanced, every other parameter kept.
	 *
	 * @param string $requestUri The URI the export was requested on.
	 *
	 * @return array<string, string> Header name to value.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function headers(string $requestUri): array {
		$headers = [
			'X-Total-Count' => (string)$this->total,
			'X-Page' => (string)$this->page->number,
			'X-Pages' => (string)$this->pages(),
		];

		if ($this->page->number < $this->pages()) {
			$headers['Link'] = '<' . self::withPage(uri: $requestUri, page: ($this->page->number + 1)) . '>; rel="next"';
		}

		return $headers;
	}//end headers()

	/**
	 * The URI with its `_page` parameter set, the path and the other parameters unchanged.
	 *
	 * @param string $uri  The request URI.
	 * @param int    $page The page to point at.
	 *
	 * @return string The URI.
	 */
	private static function withPage(string $uri, int $page): string {
		$parts = explode('?', $uri, 2);
		$query = [];
		parse_str(($parts[1] ?? ''), $query);
		$query['_page'] = (string)$page;

		return $parts[0] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}//end withPage()
}//end class
