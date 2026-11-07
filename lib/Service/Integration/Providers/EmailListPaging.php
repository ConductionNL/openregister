<?php

/**
 * The paging of the email integration's list.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * Reads `_limit` and the zero-indexed `_page`; paging applies only when a limit is set.
 *
 * @spec openspec/specs/integration-email/spec.md
 */
final class EmailListPaging {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $filters The list filters.
	 */
	public function __construct(
		private readonly array $filters,
	) {
	}//end __construct()

	/**
	 * The page size.
	 *
	 * @return int|null The limit, or null for everything.
	 *
	 * @spec openspec/specs/integration-email/spec.md
	 */
	public function limit(): ?int {
		if (isset($this->filters['_limit']) === false) {
			return null;
		}

		return (int)$this->filters['_limit'];
	}//end limit()

	/**
	 * The offset of this page.
	 *
	 * @return int|null The offset, or null without a limit.
	 *
	 * @spec openspec/specs/integration-email/spec.md
	 */
	public function offset(): ?int {
		$limit = $this->limit();
		if ($limit === null) {
			return null;
		}

		return ($this->page() * $limit);
	}//end offset()

	/**
	 * The cursor of the next page: its page number, while rows remain.
	 *
	 * @param int $seen  The rows on this page.
	 * @param int $total The rows on all pages.
	 *
	 * @return string|null The cursor, or null on the last page and without a limit.
	 *
	 * @spec openspec/specs/integration-email/spec.md
	 */
	public function nextCursor(int $seen, int $total): ?string {
		$offset = $this->offset();
		if ($offset === null || ($offset + $seen) >= $total) {
			return null;
		}

		return (string)($this->page() + 1);
	}//end nextCursor()

	/**
	 * The zero-indexed page, never below the first.
	 *
	 * @return int The page.
	 */
	private function page(): int {
		return max(0, (int)($this->filters['_page'] ?? 0));
	}//end page()
}//end class
