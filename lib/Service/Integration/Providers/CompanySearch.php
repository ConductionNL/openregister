<?php

/**
 * One company search: its text, its paging and what narrows it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-company-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * A company search as a caller asks it.
 *
 * @spec openspec/specs/integration-company-lookup/spec.md
 */
final class CompanySearch {

	/**
	 * What narrows the search, as the register names it.
	 *
	 * @var array<string,mixed>
	 */
	public array $criteria = [];

	/**
	 * Constructor.
	 *
	 * @param string $query The free text.
	 * @param int    $limit The page size asked for.
	 * @param int    $page  The 1-based page asked for.
	 */
	public function __construct(
		private readonly string $query,
		private readonly int $limit,
		private readonly int $page,
	) {
	}//end __construct()

	/**
	 * The same search with criteria.
	 *
	 * @param array<string,mixed> $criteria What narrows the search.
	 *
	 * @return self The narrowed search.
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function narrowedBy(array $criteria): self {
		$search = clone $this;
		$search->criteria = $criteria;

		return $search;
	}//end narrowedBy()

	/**
	 * The trimmed free text.
	 *
	 * @return string The text.
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function text(): string {
		return trim($this->query);
	}//end text()

	/**
	 * The lookup of this search, with its paging clamped.
	 *
	 * @param string $operation The name to log a failure under.
	 *
	 * @return CompanyLookupRun The lookup.
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function run(string $operation): CompanyLookupRun {
		return new CompanyLookupRun(operation: $operation, limit: max(1, min(100, $this->limit)), page: max(1, $this->page));
	}//end run()
}//end class
