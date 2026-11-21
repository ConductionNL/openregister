<?php
/**
 * PlacedAuditEntry: an audit entry about something in a register and a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The entry, and the register and schema the trail files it under, where they are known.
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
final class PlacedAuditEntry {

	/**
	 * Constructor.
	 *
	 * @param AuditEntry $entry    The entry.
	 * @param int|null   $register The id of the register it happened in, when known.
	 * @param int|null   $schema   The id of the schema it happened in, when known.
	 */
	public function __construct(
		public readonly AuditEntry $entry,
		public readonly ?int $register = null,
		public readonly ?int $schema = null,
	) {
	}//end __construct()
}//end class
