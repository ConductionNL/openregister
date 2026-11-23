<?php
/**
 * FacetWeekRelabelInterface: relabels week facet buckets by a declared first week.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Relabels the week buckets of facet results by a working calendar's first week.
 *
 * Implemented by \OCA\OpenRegister\Service\Object\FacetWeekLabels (Processes),
 * so Core's facet calculation does not name the flow timer calendar.
 *
 * @spec openspec/changes/working-calendar-admin
 */
interface FacetWeekRelabelInterface {

	/**
	 * The calendar's first-week declaration the labels follow.
	 *
	 * @return string
	 */
	public function declaration(): string;

	/**
	 * Relabel every week date histogram in the facets, recursively.
	 *
	 * @param array<array-key, mixed> $facets The calculated facets.
	 *
	 * @return array<array-key, mixed> The facets with week buckets relabelled.
	 */
	public function relabel(array $facets): array;
}//end interface
