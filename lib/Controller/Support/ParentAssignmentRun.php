<?php

/**
 * OpenRegister organisation parent assignment
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * One parent assignment of an organisation update: the organisation and the
 * parent it is given, null for none.
 *
 * Built by OrganisationController while it applies an update and never handed
 * outside that class.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class ParentAssignmentRun {
	/**
	 * Constructor.
	 *
	 * @param string $uuid      The organisation UUID.
	 * @param mixed  $newParent The parent UUID; null clears the parent.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly mixed $newParent,
	) {
	}//end __construct()
}//end class
