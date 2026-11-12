<?php

/**
 * A refused folder bind and the reason it was refused, as the audit trail records it.
 *
 * Built by {@see FolderManagementHandler::logFolderAccessDenied()} for each
 * denial and turned into its `folder_access_denied` audit entry. It never
 * leaves that class.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/self-folder-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use Symfony\Component\Uid\Uuid;

/**
 * One denial: the refused bind and its human-readable reason.
 *
 * @spec openspec/specs/self-folder-access-control/spec.md
 */
final class FolderAccessDenial {
	/**
	 * Constructor.
	 *
	 * @param FolderBindRun $bind   The refused bind: its folder, its acting user (none means
	 *                              actor `system`) and the object it would have attached to.
	 * @param string        $reason Human-readable reason for the denial (`not found`, `not a folder`, …).
	 *
	 * @spec openspec/specs/self-folder-access-control/spec.md
	 */
	public function __construct(
		public readonly FolderBindRun $bind,
		public readonly string $reason,
	) {
	}//end __construct()

	/**
	 * The acting user's uid, `system` when nobody acts.
	 *
	 * @return string The uid.
	 *
	 * @spec openspec/specs/self-folder-access-control/spec.md
	 */
	public function actorUid(): string {
		return $this->bind->actingUser?->getUID() ?? 'system';
	}//end actorUid()

	/**
	 * The `folder_access_denied` audit entry for this denial.
	 *
	 * @return AuditTrail The unsaved entry.
	 *
	 * @spec openspec/specs/self-folder-access-control/spec.md
	 */
	public function auditEntry(): AuditTrail {
		$auditTrail = $this->baseAuditEntry();
		$auditTrail->setChanged(
			[
				'folder' => $this->bind->folderId,
				'reason' => $this->reason,
			]
		);
		$this->applyObjectScope(auditTrail: $auditTrail);

		return $auditTrail;
	}//end auditEntry()

	/**
	 * The entry's identity, actor and the NOT NULL columns.
	 *
	 * Use positional args throughout — Nextcloud Entity setters route through __call,
	 * which doesn't honour PHP named-args (the parameter name on the magic method
	 * doesn't match the entity field name).
	 *
	 * Required NOT NULL columns on `oc_openregister_audit_trails` (per migration
	 * Version1Date20241020231700: `object` INT NOT NULL, `user_name` STR NOT NULL,
	 * `session` STR NOT NULL). The audit row is about a save that was REJECTED
	 * before persistence, so there is no object id to record. We use:
	 * - `object = 0` as the documented sentinel for "no object persisted yet".
	 * Auto-increment PKs on this schema start at 1, so 0 cannot collide with
	 * a real ObjectEntity row. Audit-trail queries filtering on
	 * `WHERE action = 'folder_access_denied'` are the canonical way to
	 * find these rows; querying by `object = 0` is also safe.
	 * - `user_name = $actorUid` so the audit UI has something to display in the
	 * "user" column when the system actor is involved.
	 * - `session = ''` (empty string) rather than $actorUid: the UID is already
	 * recorded in the `user` column; duplicating it into the `session` field
	 * adds no information and would mislead forensic correlation. We use
	 * empty string (not null) because the column is NOT NULL.
	 *
	 * @return AuditTrail The entry so far.
	 *
	 * @spec openspec/specs/self-folder-access-control/spec.md
	 */
	private function baseAuditEntry(): AuditTrail {
		$actorUid   = $this->actorUid();
		$auditTrail = new AuditTrail();
		$auditTrail->setUuid(Uuid::v4()->toRfc4122());
		$auditTrail->setAction('folder_access_denied');
		$auditTrail->setUser($actorUid);
		$auditTrail->setObject(0);
		$auditTrail->setUserName($actorUid);
		$auditTrail->setSession('');
		$auditTrail->setCreated(new DateTime());

		return $auditTrail;
	}//end baseAuditEntry()

	/**
	 * Record the object the bind would have attached to: its uuid, register and schema, each when set.
	 *
	 * @param AuditTrail $auditTrail The entry under construction.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/self-folder-access-control/spec.md
	 */
	private function applyObjectScope(AuditTrail $auditTrail): void {
		$objectEntity = $this->bind->objectEntity;
		if ($objectEntity instanceof ObjectEntity === false) {
			return;
		}

		if ($objectEntity->getUuid() !== null) {
			$auditTrail->setObjectUuid($objectEntity->getUuid());
		}

		if ($objectEntity->getRegister() !== null) {
			$auditTrail->setRegister((int)$objectEntity->getRegister());
		}

		if ($objectEntity->getSchema() !== null) {
			$auditTrail->setSchema((int)$objectEntity->getSchema());
		}
	}//end applyObjectScope()
}//end class
