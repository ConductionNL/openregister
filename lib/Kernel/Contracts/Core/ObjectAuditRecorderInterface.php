<?php
/**
 * ObjectAuditRecorderInterface: where Core records an action on one object.
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
 * Records a non-CRUD action on one object (a lock broken, a move, a draft removed) on the audit trail.
 *
 * Unlike AuditEntryWriterInterface, which stores entries the caller has fully
 * prepared, the trail fills in the object's own columns (id, register, schema)
 * and, when the entry names no actor, the session user. Core asks; the audit
 * trail (Compliance) answers through its mapper, whose interface extends this one.
 * A failed write is thrown to the caller, which decides whether the operation still stands.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
interface ObjectAuditRecorderInterface {

	/**
	 * Record the action, hash-chained, before returning.
	 *
	 * @param ObjectAuditEntry $entry The object, the action, its context and the explicit actor, if any.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception When the trail cannot store it.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function writeObjectEvent(ObjectAuditEntry $entry): void;
}//end interface
