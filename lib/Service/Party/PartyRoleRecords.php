<?php

/**
 * Where the party role service writes: the link rows, the link writer and the audit trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;

/**
 * The stores a party role on an object is written to and recorded in.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 */
final readonly class PartyRoleRecords {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ContactLinkMapperInterface $links The link rows.
	 * @param PartyLinkWriter $writer Writes one party link.
	 * @param AuditTrailMapperInterface $audit The audit trail.
	 */
	public function __construct(
		public ContactLinkMapperInterface $links,
		public PartyLinkWriter $writer,
		public AuditTrailMapperInterface $audit,
	) {
	}//end __construct()
}//end class
