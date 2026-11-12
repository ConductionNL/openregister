<?php

/**
 * OpenRegister Case Plan Anchor Access
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

/**
 * Who may act on the plan of an object, what that object says, and where a result is written back to it.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
final readonly class CasePlanAnchorAccess {

	/**
	 * Bundle the collaborators.
	 *
	 * @param CasePlanAuthorizationService $authorization Fail-closed decisions.
	 * @param CaseAnchorReader $anchor Read visibility and the anchor's data.
	 * @param CaseBusinessStateWriter $writer Result write-through.
	 */
	public function __construct(
		public CasePlanAuthorizationService $authorization,
		public CaseAnchorReader $anchor,
		public CaseBusinessStateWriter $writer,
	) {
	}//end __construct()
}//end class
