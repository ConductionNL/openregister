<?php

/**
 * DeletedObjectAuthorizer — the authorization decisions about a soft-deleted
 * object, in one place.
 *
 * Deciding whether a caller may restore or destroy a trashed object, resolving
 * the schema the decision hangs on, and refusing a purge of a legally retained
 * archival record are one cohesive concern. They used to live on the
 * DeletedController alongside the request handling; they are collected here so
 * the controller asks the question rather than answering it. The rules are
 * unchanged — every method is the fail-closed decision it was before.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Answers the authorization and schema-resolution questions the trash surface
 * asks of every soft-deleted object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
class DeletedObjectAuthorizer {
	/**
	 * Wire the RBAC and schema collaborators.
	 *
	 * @param SchemaMapper $schemaMapper The schema mapper.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager for admin checks.
	 * @param PermissionHandler $permissionHandler Handler for per-schema RBAC checks.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly PermissionHandler $permissionHandler,
	) {
	}//end __construct()

	/**
	 * Check if the current user is an admin
	 *
	 * @return bool True if the user is in the admin group, false otherwise.
	 */
	public function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return (bool)$this->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	/**
	 * Resolve a soft-deleted object's schema and check the caller has the
	 * required action permission.
	 *
	 * Refuses the call (returns false) when:
	 *  - no user is authenticated, OR
	 *  - the object lacks a resolvable register/schema context, OR
	 *  - PermissionHandler denies the action for the caller.
	 *
	 * Admin users always pass. This mirrors the fail-closed write-RBAC
	 * pattern from #1949: when register/schema context cannot be derived,
	 * the destructive operation is refused.
	 *
	 * @param ObjectEntity $object The soft-deleted object being acted on.
	 * @param string $action The action to authorize ('delete'|'update').
	 *
	 * @return bool True if the caller may perform the action on this object.
	 */
	public function userMayActOnDeletedObject(ObjectEntity $object, string $action): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		// Admin bypass.
		if ($this->isCurrentUserAdmin() === true) {
			return true;
		}

		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			// Fail-closed: cannot resolve schema, refuse.
			return false;
		}

		try {
			$schema = $this->schemaMapper->find((int)$schemaId);
		} catch (\Throwable $e) {
			return false;
		}

		try {
			return $this->permissionHandler->hasPermission(
				schema: $schema,
				action: $action,
				userId: $user->getUID(),
				objectOwner: $object->getOwner(),
				_rbac: true,
				object: $object
			);
		} catch (\Throwable $e) {
			return false;
		}
	}//end userMayActOnDeletedObject()

	/**
	 * Resolve a soft-deleted object's schema, or null when it cannot be found.
	 *
	 * @param ObjectEntity $object The object whose schema to resolve.
	 *
	 * @return Schema|null The schema, or null when it cannot be resolved.
	 */
	public function resolveSchema(ObjectEntity $object): ?Schema {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find((int)$schemaId);
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveSchema()

	/**
	 * Refuse a purge when the object is a legally retained archival record.
	 *
	 * `DELETE /api/objects/{register}/{schema}/{id}` rejects a delete on an
	 * archival schema with 403 SCHEMA_ARCHIVAL_IMMUTABLE
	 * ({@see \OCA\OpenRegister\Service\ObjectService::deleteObject()}). Purging
	 * is strictly more destructive than deleting, so it answers on the same
	 * terms, from the same definition ({@see Schema::hasArchivalAnnotation()}) —
	 * otherwise the trash is a second door onto the records the first door
	 * exists to protect.
	 *
	 * Fails CLOSED: an object whose schema cannot be resolved is refused, since
	 * an unresolvable schema is exactly the case where the annotation cannot be
	 * read and the row might be retained.
	 *
	 * @param ObjectEntity $object The object being purged.
	 *
	 * @return ArchivalImmutableException|null The refusal, or null when the purge may proceed.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function archivalRefusal(ObjectEntity $object): ?ArchivalImmutableException {
		$schema = $this->resolveSchema(object: $object);
		if ($schema === null) {
			return new ArchivalImmutableException(
				schemaIdentifier: (string)($object->getSchema() ?? 'unknown'),
				operation: 'purge'
			);
		}

		if ($schema->hasArchivalAnnotation() === false) {
			return null;
		}

		return new ArchivalImmutableException(
			schemaIdentifier: ($schema->getSlug() ?? (string)$schema->getId()),
			operation: 'purge'
		);
	}//end archivalRefusal()
}//end class
