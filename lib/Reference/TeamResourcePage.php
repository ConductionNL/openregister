<?php

/**
 * OpenRegister team resource page.
 *
 * One page of the objects a team owns, with the cursor that asks for the next
 * page (design D-2).
 *
 * @category Reference
 * @package  OCA\OpenRegister\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Reference;

use OCP\Teams\TeamResource;

/**
 * A page of team resources and the cursor of the next page.
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */
final class TeamResourcePage {

	/**
	 * Constructor.
	 *
	 * @param list<TeamResource> $resources  The resources on this page.
	 * @param string|null        $nextCursor The cursor of the next page, or null on the last page.
	 */
	public function __construct(
		public readonly array $resources,
		public readonly ?string $nextCursor,
	) {
	}//end __construct()
}//end class
