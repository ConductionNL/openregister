<?php

/**
 * CompanyLookup: one company-register lookup and the envelope it answers with.
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
 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * The operation name (for the failure log) and, for a paged search, the
 * clamped limit and page echoed in the envelope. A limit of 0 means
 * unpaged: the envelope then carries no `limit` / `page`.
 *
 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
 */
final class CompanyLookup {

	/**
	 * Constructor.
	 *
	 * @param string $operation The `Provider::method` name logged on failure.
	 * @param int $limit The clamped page size, or 0 when unpaged.
	 * @param int $page The clamped page number.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $operation,
		private readonly int $limit = 0,
		private readonly int $page = 0,
	) {
	}//end __construct()

	/**
	 * The envelope of a lookup that answered.
	 *
	 * @param array<int, array<string, mixed>> $rows The extracted rows.
	 *
	 * @return array<string, mixed> `{ results, total[, limit, page] }`.
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
	 */
	public function found(array $rows): array {
		return ['results' => $rows, 'total' => count($rows)] + $this->paging();
	}//end found()

	/**
	 * The degraded envelope (AD-23): never a throw.
	 *
	 * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
	 *
	 * @return array<string, mixed> `{ unavailable, cause, results, total[, limit, page] }`.
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
	 */
	public function degraded(string $cause): array {
		return ['unavailable' => true, 'cause' => $cause, 'results' => [], 'total' => 0] + $this->paging();
	}//end degraded()

	/**
	 * The paging keys, or none when unpaged.
	 *
	 * @return array<string, int> `{ limit, page }` or [].
	 */
	private function paging(): array {
		if ($this->limit > 0) {
			return ['limit' => $this->limit, 'page' => $this->page];
		}

		return [];
	}//end paging()
}//end class
