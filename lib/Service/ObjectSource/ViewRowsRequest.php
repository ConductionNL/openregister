<?php

/**
 * One read of a view-backed type's rows: the type's schema, its view binding and the caller's query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\Schema;

/**
 * What {@see ViewObjectSourceProvider} reads or counts: which view-backed schema, through which view, with which caller query.
 *
 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
 */
final class ViewRowsRequest {
	/**
	 * Constructor.
	 *
	 * @param Schema               $schema The view-backed schema.
	 * @param array<string, mixed> $query  The caller's query (filters, limit, offset, sort).
	 * @param array<string, mixed> $config The object-source config (`view`).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly array $query,
		public readonly array $config,
	) {
	}//end __construct()
}//end class
