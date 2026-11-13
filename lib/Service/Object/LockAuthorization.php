<?php

/**
 * LockAuthorization: who may take, release and break an object lock.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The authorization decisions of LockHandler, apart from the lock bookkeeping.
 *
 * Taking a lock needs the update right on the object. Releasing one is open to
 * the holder, the administrator, and for a user lock the object owner and a
 * schema-manage group. Breaking one is for an administrator only.
 */
class LockAuthorization {

	/**
	 * Constructor
	 *
	 * @param IUserSession                    $userSession       The caller.
	 * @param IGroupManager                   $groupManager      Administrator and group membership checks.
	 * @param SchemaMapperInterface           $schemaMapper      Resolves the manage rules of the owning schema.
	 * @param PermissionHandlerInterface      $permissionHandler RBAC, asked for update permission before a user takes a lock.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly PermissionHandlerInterface $permissionHandler,
	) {
	}//end __construct()

	/**
	 * Refuse a lock to a caller who may not update the object.
	 *
	 * A lock refuses every other writer, and one taken without a duration holds
	 * until it is unlocked, so taking it needs the right to update the object,
	 * not only to find it. A flow run is asked the same: it runs as its acting
	 * user, whom the session then names. Without a schema to ask there is no
	 * answer, and the lock is refused.
	 *
	 * @param array $context The resolved object with its register and schema.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException If the caller may not update the object, or nothing can tell.
	 *
	 * @spec openspec/changes/lock-needs-update-permission/specs/lock-needs-update-permission/spec.md#requirement-a-user-lock-needs-update-permission-on-the-object
	 */
	public function assertMayLock(array $context): void {
		$object = $context['object'];
		if (($context['schema'] ?? null) instanceof Schema === false) {
			throw new NotAuthorizedException(message: 'This object cannot be locked: its update rule cannot be checked.');
		}

		$this->permissionHandler->checkPermission(
			schema: $context['schema'],
			action: 'update',
			options: new PermissionCheckOptions(objectOwner: $object->getOwner(), object: $object),
			_rbac: true
		);
	}//end assertMayLock()

	/**
	 * Whether the caller may release the lock on this object.
	 *
	 * The caller may unlock the object only when one of:
	 *  - the caller is a Nextcloud administrator, OR
	 *  - the caller is the lock holder (the user recorded in the lock payload), OR
	 *  - for a user lock, the caller is the object owner, OR
	 *  - for a user lock, the caller has schema-manage permission on the owning schema.
	 *
	 * Anonymous callers are always refused. This closes the wave-3 C14 finding
	 * where any authenticated user could unlock any locked object.
	 *
	 * @param ObjectEntity $object  The locked object the caller wants to unlock.
	 * @param string|null  $runUuid The flow run asking, when a run is asking.
	 *
	 * @return bool True if the caller may unlock this object.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-an-administrator-may-break-a-lock-and-the-break-is-recorded
	 */
	public function mayUnlock(ObjectEntity $object, ?string $runUuid = null): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		// Admins always pass: for a run lock this is the break-lock route and the
		// only one. The holder: for a run lock this admits only the holding run,
		// and NOT the run's own runAs user.
		$userId = $user->getUID();
		if ($this->groupManager->isAdmin($userId) === true || $object->isLockedBySomeoneElse(userId: $userId, runUuid: $runUuid) === false) {
			return true;
		}

		return $this->mayReleaseUserLock(object: $object, userId: $userId);
	}//end mayUnlock()

	/**
	 * Whether the caller may break a lock: an administrator only.
	 *
	 * @return bool True when the caller is an administrator.
	 */
	public function mayBreak(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->groupManager->isAdmin($user->getUID()) !== false;
	}//end mayBreak()

	/**
	 * The owner and schema-manage routes, which are for USER locks only.
	 *
	 * Extending them to run locks would mean any case owner could quietly defeat
	 * the engine's mutual exclusion, which is the whole point of a run lock.
	 *
	 * @param ObjectEntity $object The locked object.
	 * @param string       $userId The caller.
	 *
	 * @return bool True when the caller owns the object or may manage its schema.
	 */
	private function mayReleaseUserLock(ObjectEntity $object, string $userId): bool {
		if ($object->getLockedByRun() !== null) {
			return false;
		}

		return $object->getOwner() === $userId || $this->mayManageSchema(object: $object);
	}//end mayReleaseUserLock()

	/**
	 * Whether the caller has schema-manage permission on the schema owning the object.
	 *
	 * Default-SECURE: a schema with no `manage` authorization rule can only be
	 * managed by administrators (admins always pass). When manage rules are
	 * present, group-membership grants permission.
	 *
	 * @param ObjectEntity $object The object whose owning schema is checked.
	 *
	 * @return bool True if the current user may manage the owning schema.
	 */
	private function mayManageSchema(ObjectEntity $object): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID()) === true || $this->manageRuleAdmits(object: $object, user: $user);
	}//end mayManageSchema()

	/**
	 * Whether a manage rule of the owning schema names one of the caller's groups.
	 *
	 * @param ObjectEntity $object The object whose owning schema is checked.
	 * @param IUser        $user   The caller.
	 *
	 * @return bool True when a manage rule names one of the caller's groups.
	 */
	private function manageRuleAdmits(ObjectEntity $object, IUser $user): bool {
		$manageRules = $this->manageRules(object: $object);
		if ($manageRules === null) {
			return false;
		}

		try {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		} catch (\Throwable $e) {
			return false;
		}

		return $this->rulesNameAGroup(manageRules: $manageRules, userGroups: $userGroups);
	}//end manageRuleAdmits()

	/**
	 * The manage rules of the object's schema, or null when there are none or the schema cannot be read.
	 *
	 * @param ObjectEntity $object The object whose owning schema is read.
	 *
	 * @return mixed The `manage` entry of the schema's authorization, or null.
	 */
	private function manageRules(ObjectEntity $object): mixed {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		try {
			$authorization = $this->schemaMapper->find((int) $schemaId)->getAuthorization();
		} catch (\Throwable $e) {
			return null;
		}

		return $this->declaredManageRules(authorization: $authorization);
	}//end manageRules()

	/**
	 * The `manage` entry of an authorization block, or null when it declares none.
	 *
	 * Default-secure: no manage rule defined means admin-only.
	 *
	 * @param mixed $authorization The schema's authorization block.
	 *
	 * @return mixed The `manage` entry, or null.
	 */
	private function declaredManageRules(mixed $authorization): mixed {
		if (empty($authorization) === true || isset($authorization['manage']) === false) {
			return null;
		}

		return $authorization['manage'];
	}//end declaredManageRules()

	/**
	 * Whether any manage rule names any of the caller's groups.
	 *
	 * @param mixed $manageRules The `manage` entry of the schema's authorization.
	 * @param array $userGroups  The caller's group ids.
	 *
	 * @return bool True when a rule names one of the groups.
	 */
	private function rulesNameAGroup(mixed $manageRules, array $userGroups): bool {
		foreach ($userGroups as $groupId) {
			foreach ($manageRules as $entry) {
				if ($this->entryNamesGroup(entry: $entry, groupId: $groupId) === true) {
					return true;
				}
			}
		}

		return false;
	}//end rulesNameAGroup()

	/**
	 * Whether one manage entry, a group id or `{group: id}`, names this group.
	 *
	 * @param mixed $entry   The manage entry.
	 * @param mixed $groupId The group id.
	 *
	 * @return bool True when the entry names the group.
	 */
	private function entryNamesGroup(mixed $entry, mixed $groupId): bool {
		if (is_string($entry) === true && $entry === $groupId) {
			return true;
		}

		return is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId;
	}//end entryNamesGroup()
}//end class
