<?php

/**
 * Answers whether a user belongs to an organisation.
 *
 * Callers outside lib/Service (lib/AppHost/Scheduling) type against this interface;
 * ContractBindings aliases it to FlowTenantMembership. Types only: the membership
 * check itself, and where callers run it, are unchanged.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Answers whether a user belongs to an organisation.
 */
interface FlowTenantMembershipInterface {

	/**
	 * Whether a user is a member of an organisation.
	 *
	 * @param string $uid          The user.
	 * @param string $organisation The organisation uuid.
	 *
	 * @return bool False too when the organisation cannot be read.
	 */
	public function isMember(string $uid, string $organisation): bool;
}//end interface
