<?php

/**
 * GeoFilterApplierInterface: what callers outside the Search component use of GeoFilterApplier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Geo
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

namespace OCA\OpenRegister\Search\Service\Geo;

/**
 * Applies spatial filters to a list of rows by post-filtering.
 */
interface GeoFilterApplierInterface {

	/**
	 * Filter a list of rows by every filter (AND semantics).
	 *
	 * @param array       $rows    The rows to filter.
	 * @param GeoFilter[] $filters The filters to apply.
	 *
	 * @return array The matching rows, in original order.
	 */
	public function applyAll(array $rows, array $filters): array;
}//end interface
