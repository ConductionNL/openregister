<?php

/**
 * One search over timeline entries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

/**
 * A search term, the objects and kind it runs over, and the visibility the caller is entitled to.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class TimelineSearch {
	/**
	 * Constructor.
	 *
	 * @param string             $term       The search term.
	 * @param TimelineEntryScope $scope      The objects, kind and page size.
	 * @param string|null        $visibility The visibility the caller is entitled to, or null for both.
	 */
	public function __construct(
		public readonly string $term,
		public readonly TimelineEntryScope $scope,
		public readonly ?string $visibility = null,
	) {
	}//end __construct()
}//end class
