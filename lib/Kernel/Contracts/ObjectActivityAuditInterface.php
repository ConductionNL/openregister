<?php

/**
 * ObjectActivityAuditInterface: write an entry on an object's audit trail, knowing only its uuid.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Records what a background process did with an object on that object's audit
 * trail. The object is read in the system context (no user, no tenant scope):
 * the caller runs without a user and knows only the uuid. Implemented in Core
 * by Service\Object\ObjectActivityAudit, so other areas reach the audit trail
 * without naming Core classes (ADR-013 Rule 1).
 */
interface ObjectActivityAuditInterface {

	/**
	 * Write one entry on the object's audit trail.
	 *
	 * @param string               $objectUuid The object the entry is about.
	 * @param string               $action     The entry's action, e.g. 'message.sent'.
	 * @param array<string, mixed> $context    The entry's context.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the object cannot be read or the entry cannot be written.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	public function record(string $objectUuid, string $action, array $context = []): void;
}//end interface
