<?php

/**
 * One lookup at a company register, and the shape of its answer.
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
 * Keeps the answer of a lookup the same shape on success and when degraded (AD-23).
 *
 * @spec openspec/specs/integration-company-lookup/spec.md
 */
final class CompanyLookupRun {

	/**
	 * Constructor.
	 *
	 * @param string $operation The name to log a failure under.
	 * @param int    $limit     The resolved page size; 0 for a lookup without paging.
	 * @param int    $page      The resolved page.
	 */
	public function __construct(
		public readonly string $operation,
		public readonly int $limit = 0,
		public readonly int $page = 0,
	) {
	}//end __construct()

	/**
	 * The answer with rows.
	 *
	 * @param array<int,array<string,mixed>> $rows The rows.
	 *
	 * @return array<string,mixed> The answer.
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function found(array $rows): array {
		return ['results' => $rows, 'total' => count($rows)] + $this->paging();
	}//end found()

	/**
	 * The answer when the source is missing or down.
	 *
	 * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
	 *
	 * @return array<string,mixed> The answer.
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function degraded(string $cause): array {
		return ['unavailable' => true, 'cause' => $cause] + $this->found(rows: []);
	}//end degraded()

	/**
	 * The paging keys of an answer.
	 *
	 * @return array<string,int> The keys, or nothing without paging.
	 */
	private function paging(): array {
		if ($this->limit <= 0) {
			return [];
		}

		return ['limit' => $this->limit, 'page' => $this->page];
	}//end paging()
}//end class
