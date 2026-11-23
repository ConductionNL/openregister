<?php

/**
 * ObjectActivityAudit: the Core side of ObjectActivityAuditInterface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectActivityAuditInterface;

/**
 * Reads the object without RBAC or tenant scope and writes the entry through
 * the audit trail mapper. Errors propagate: the caller decides whether a
 * missing entry matters.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */
final class ObjectActivityAudit implements ObjectActivityAuditInterface {

	/**
	 * Constructor.
	 *
	 * @param MagicObjectFinderInterface $objects    Reads the object by uuid.
	 * @param AuditTrailMapperInterface  $auditTrail Writes the hash-chained entry.
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $objects,
		private readonly AuditTrailMapperInterface $auditTrail,
	) {
	}//end __construct()

	/**
	 * Write one entry on the object's audit trail.
	 *
	 * @param string               $objectUuid The object the entry is about.
	 * @param string               $action     The entry's action.
	 * @param array<string, mixed> $context    The entry's context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	public function record(string $objectUuid, string $action, array $context = []): void {
		$object = $this->objects->find(identifier: $objectUuid, _rbac: false, _multitenancy: false);
		$this->auditTrail->recordObjectEvent(
			event: new ObjectAuditEvent(object: $object, action: $action, context: $context)
		);
	}//end record()
}//end class
