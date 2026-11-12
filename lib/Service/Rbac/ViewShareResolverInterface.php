<?php

/**
 * ViewShareResolverInterface: what a saved view grants one caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The methods of ViewShareResolver that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ViewShareResolverInterface {

	/**
	 * The access a caller holds on one view.
	 *
	 * OWNER WINS, then write, then read, and an administrator is resolved as an
	 * owner by the caller rather than here: this answers what the VIEW grants,
	 * and an administrator reaches it because they are an administrator, not
	 * because the view said so. Mixing the two would put `owner` on a row an
	 * administrator does not own and cannot hand back.
	 *
	 * @param array<string, mixed> $view  The view, as the entity serialises it.
	 * @param ViewerReach          $reach The caller and their group ids.
	 *
	 * @return string|null One of owner, write, read, or null when the view grants nothing.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function accessOf(array $view, ViewerReach $reach): ?string;
}//end interface
