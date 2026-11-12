<?php

/**
 * DerivedSearchPage
 *
 * The page window one `search` verb invocation of a schema-derived MCP tool
 * reads: the 1-based page number and the clamped page size.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

/**
 * The page window of one derived `search`.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final readonly class DerivedSearchPage {
	/**
	 * Constructor.
	 *
	 * @param int $page     The 1-based page number.
	 * @param int $pageSize The clamped page size.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __construct(
		public int $page,
		public int $pageSize,
	) {
	}//end __construct()

	/**
	 * The number of results before this page.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function offset(): int {
		return (($this->page - 1) * $this->pageSize);
	}//end offset()
}//end class
