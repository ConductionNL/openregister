<?php

/**
 * GrantChangeAuditorInterface: the special-right change record the organisation controller writes.
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
 * The methods of GrantChangeAuditor that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface GrantChangeAuditorInterface {

	/**
	 * Record a change of the groups that hold the special right on an organisation's records.
	 *
	 * @param string             $organisationUuid The organisation.
	 * @param SpecialRightChange $change           The list before and after.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function recordSpecialRight(string $organisationUuid, SpecialRightChange $change): void;
}//end interface
