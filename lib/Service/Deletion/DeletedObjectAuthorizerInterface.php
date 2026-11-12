<?php

/**
 * DeletedObjectAuthorizerInterface: the authorization and schema-resolution questions the trash surface asks of a soft-deleted object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ArchivalImmutableException;

/**
 * The authorization and schema-resolution questions the trash surface asks of a soft-deleted object.
 *
 * Callers outside lib/Service type against this; ContractBindings binds it to DeletedObjectAuthorizer.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
interface DeletedObjectAuthorizerInterface {
	/**
	 * Check if the current user is an admin.
	 *
	 * @return bool True if the user is in the admin group, false otherwise.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function isCurrentUserAdmin(): bool;

	/**
	 * Resolve a soft-deleted object's schema and check the caller has the required action permission.
	 *
	 * @param ObjectEntity $object The soft-deleted object being acted on.
	 * @param string       $action The action to authorize ('delete'|'update').
	 *
	 * @return bool True if the caller may perform the action on this object.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function userMayActOnDeletedObject(ObjectEntity $object, string $action): bool;

	/**
	 * The destruction records of one object the caller may read.
	 *
	 * @param array<int, AuditTrail> $records The destruction records of one object.
	 *
	 * @return array<int, AuditTrail> The readable ones.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function readableDestructionRecords(array $records): array;

	/**
	 * Resolve a soft-deleted object's schema.
	 *
	 * @param ObjectEntity $object The object whose schema to resolve.
	 *
	 * @return Schema|null The schema, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function resolveSchema(ObjectEntity $object): ?Schema;

	/**
	 * The archival refusal a purge of this object earns, if any.
	 *
	 * @param ObjectEntity $object The object being purged.
	 *
	 * @return ArchivalImmutableException|null The refusal, or null when the purge may proceed.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function archivalRefusal(ObjectEntity $object): ?ArchivalImmutableException;
}//end interface
