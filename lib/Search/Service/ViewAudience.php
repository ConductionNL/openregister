<?php

/**
 * OpenRegister view audience
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Search\Service;

/**
 * Who a saved view is for: its owner, whether everyone sees it, whether it is the owner's default, and its group shares.
 *
 * Not strict on purpose: the views controller fills it from the request body, as it filled
 * ViewService::create()'s parameters before.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final readonly class ViewAudience {
	/**
	 * Name the audience of a view.
	 *
	 * @param string     $owner      The owner user ID.
	 * @param bool       $isPublic   Whether the view is public (accessible to all users).
	 * @param bool       $isDefault  Whether the view is the default view for the owner.
	 * @param array|null $sharedWith Validated group shares, `[{group, mode}]`; null = none.
	 */
	public function __construct(
		public string $owner,
		public bool $isPublic=false,
		public bool $isDefault=false,
		public ?array $sharedWith=null,
	) {
	}//end __construct()
}//end class
