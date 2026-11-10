<?php

/**
 * One saved view as a given viewer reaches it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;


/**
 * A view and the reach of the caller acting on it.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final class ReachedView {
	/**
	 * Pair the view with the caller's reach.
	 *
	 * @param array<string, mixed> $view  The view, as the entity serialises it.
	 * @param ViewerReach          $reach The caller's reach.
	 */
	public function __construct(
		public readonly array $view,
		public readonly ViewerReach $reach,
	) {
	}//end __construct()
}//end class
