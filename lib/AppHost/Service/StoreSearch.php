<?php

/**
 * The terms of one store search.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * The optional free-text term and `kind` discriminator of one store search
 * (ADR-011 Rule 2; `kind` is ADR-080 Decision 5).
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
final class StoreSearch {
	/**
	 * Constructor.
	 *
	 * @param string|null $query Optional free-text search term.
	 * @param string|null $kind  Optional `kind` discriminator filter.
	 */
	public function __construct(
		public readonly ?string $query = null,
		public readonly ?string $kind = null,
	) {
	}//end __construct()

	/**
	 * The registry query filters: `_search` and `kind`, each trimmed and only when non-empty.
	 *
	 * @return array<string, string> The filters.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function filters(): array {
		$filters = [];
		if ($this->query !== null && trim($this->query) !== '') {
			$filters['_search'] = trim($this->query);
		}

		if ($this->kind !== null && trim($this->kind) !== '') {
			$filters['kind'] = trim($this->kind);
		}

		return $filters;
	}//end filters()
}//end class
