<?php

/**
 * One facet filter of a saved view: a field and the values it allows.
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
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * A view's facet filter on one field; `@self.` fields address object metadata.
 *
 * @spec openspec/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
 */
final class ViewFacet {
	/**
	 * Constructor.
	 *
	 * @param string            $field  The filtered field, or `@self.<name>` for metadata.
	 * @param array<int, mixed> $values The values the view allows (non-empty).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $field,
		public readonly array $values,
	) {
	}//end __construct()
}//end class
