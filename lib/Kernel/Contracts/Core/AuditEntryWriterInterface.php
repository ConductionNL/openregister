<?php
/**
 * AuditEntryWriterInterface: where Core writes its audit entries.
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
 * Writes audit entries to the trail, sealed into its hash chain, before it returns.
 *
 * Core asks; the audit trail (Compliance) stores the entries and binds its writer outside Core.
 * A failed write is thrown to the caller, which decides whether the operation still stands.
 */
interface AuditEntryWriterInterface {

	/**
	 * Write the entries, in order.
	 *
	 * @param list<AuditEntry|PlacedAuditEntry> $entries The entries; a placed entry is filed under its register and schema.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception When the trail cannot store them.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function writeAuditEntries(array $entries): void;
}//end interface
