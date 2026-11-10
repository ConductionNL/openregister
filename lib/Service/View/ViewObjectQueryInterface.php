<?php

/**
 * ViewObjectQueryInterface: the ViewObjectQuery as the view alert sweep reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\View
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\View;

/**
 * Turns a saved view's stored query into the search query that counts it,
 * typed for the background sweep that measures view alerts.
 *
 * Bound in ContractBindings to ViewObjectQuery (stateless).
 */
interface ViewObjectQueryInterface {

	/**
	 * The search query for a view's stored query.
	 *
	 * The facet filters are read the way a view-backed schema reads them
	 * (ViewObjectSourceProvider::combine()): an empty selection is no filter, a
	 * `@self.<field>` key filters metadata, and a field named by both `filters`
	 * and `facetFilters` keeps only the values both allow.
	 *
	 * @param array<string, mixed> $viewQuery The view's stored query.
	 *
	 * @return array<string, mixed>|null The search query; null when the view's filters exclude each other.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
	 */
	public function forView(array $viewQuery): ?array;
}//end interface
