<?php

/**
 * OpenRegister AppHost — one store search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store\Source;

/**
 * What a store search asks a discovery source for: the free text and the kind filter.
 *
 * @spec openspec/changes/store-plane-declarative-sources/specs/apphost-store-plane/spec.md#requirement-a-store-must-declare-its-discovery-source-defaulting-to-openregister
 */
final class StoreSearch {
	/**
	 * Constructor.
	 *
	 * @param string      $query Free-text search, possibly empty.
	 * @param string|null $kind  Kind filter, or null for every kind.
	 */
	public function __construct(
		public readonly string $query='',
		public readonly ?string $kind=null,
	) {
	}//end __construct()
}//end class
