<?php

/**
 * Who may ask who else has access to one object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The object resolution and the access-review checks of the per-object access reads.
 *
 * `/permissions` and `/grants` ask the same two questions in the same order:
 * can this caller read the object at all (no: 404, so existence does not
 * leak), and may this caller see who else holds access to it (no: 403). One
 * class answers both, so the two endpoints cannot drift on who may ask.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
class AccessReviewGate {

	/**
	 * The verb a schema writes to admit callers to an object's grants list.
	 *
	 * @var string
	 */
	public const READ_GRANTS = 'readGrants';

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objectService Resolves the object through the RBAC boundary.
	 * @param ObjectAccessReport $report        Resolves the schema the request named.
	 * @param IUserSession       $userSession   The calling principal.
	 * @param IGroupManager      $groupManager  Administrator detection and the caller's groups.
	 * @param GrantManageGuard   $grantManage   The owner, owning group, administrator and `grant_manage` rule.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ObjectAccessReport $report,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly GrantManageGuard $grantManage,
	) {
	}//end __construct()

	/**
	 * Resolve the object through the RBAC boundary, or null when the caller cannot read it.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return ObjectEntity|null The object, or null.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) `id` is the route parameter's name.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function resolve(string $register, string $schema, string $id): ?ObjectEntity {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		try {
			$object = $this->objectService->getObject();
		} catch (\Throwable $e) {
			return null;
		}

		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		return $object;
	}//end resolve()

	/**
	 * Whether the caller may read the object's access set (`/permissions`).
	 *
	 * The owner, an administrator, or a holder of `manage` through a rule
	 * somebody actually wrote.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return bool True when the caller may ask.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function mayReviewAccess(ObjectEntity $object): bool {
		$userId = $this->callerUid();

		if ($userId !== null && $userId === $object->getOwner()) {
			return true;
		}

		if ($userId !== null && $this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->managesTheSchema(userId: $userId);
	}//end mayReviewAccess()

	/**
	 * Whether the caller may read the object's grants list (`/grants`).
	 *
	 * Everybody {@see mayReviewAccess()} admits, the owning group and a holder
	 * of the organisation's `grant_manage` special right on the record, and a
	 * caller the record's `readGrants` rule admits.
	 *
	 * @param ObjectEntity $object The resolved object.
	 *
	 * @return bool True when the caller may ask.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function mayReadGrants(ObjectEntity $object): bool {
		if ($this->mayReviewAccess(object: $object) === true) {
			return true;
		}

		$userId = $this->callerUid();
		$groups = [];
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$groups = $this->groupManager->getUserGroupIds($user);
		}

		if ($this->grantManage->admittedAs(userId: $userId, userGroups: $groups, object: $object) !== null) {
			return true;
		}

		return $this->readGrantsAdmits(object: $object, userId: $userId);
	}//end mayReadGrants()

	/**
	 * Whether this caller holds `manage` here through a rule somebody wrote.
	 *
	 * 🔴 THE GRANT HAS TO BE WRITTEN DOWN. A schema that configures nothing
	 * resolves default-open, so `manage` would answer true for every signed-in
	 * caller, and the access reads would hand back the rules that
	 * `renderEntity()` deliberately strips out of a non-admin object read. On
	 * such a schema only the owner and an administrator may ask.
	 *
	 * @param string|null $userId The caller.
	 *
	 * @return bool True when the caller may review access here.
	 */
	private function managesTheSchema(?string $userId): bool {
		$schema = $this->schema();
		if ($schema === null) {
			return false;
		}

		$handler = $this->objectService->getPermissionHandler();

		try {
			$resolved = $handler->resolveAuthorization(schema: $schema);
		} catch (\Throwable $e) {
			// Fail closed: a report about rules nobody could resolve would be a
			// report about nothing.
			return false;
		}

		if (is_array($resolved) === false || $resolved === []) {
			return false;
		}

		return $handler->hasPermission(schema: $schema, action: 'manage', userId: $userId);
	}//end managesTheSchema()

	/**
	 * Whether the record's `readGrants` rule admits this caller.
	 *
	 * 🔴 NO DEFAULT-OPEN. `hasPermission()` grants every verb on a block that
	 * writes nothing, so the verb is only asked when the cascaded block for
	 * this record actually writes a `readGrants` list. The record is passed,
	 * so a conditional entry such as `{"group": "authenticated", "match":
	 * {"assignee": "$userId"}}` is evaluated against it.
	 *
	 * @param ObjectEntity $object The record.
	 * @param string|null  $userId The caller.
	 *
	 * @return bool True when a written `readGrants` rule admits the caller.
	 */
	private function readGrantsAdmits(ObjectEntity $object, ?string $userId): bool {
		$schema = $this->schema();
		if ($schema === null || $userId === null) {
			return false;
		}

		$handler = $this->objectService->getPermissionHandler();

		try {
			$resolved = $handler->resolveAuthorization(schema: $schema, object: $object);
		} catch (\Throwable $e) {
			return false;
		}

		if (is_array($resolved) === false || empty($resolved[self::READ_GRANTS]) === true) {
			return false;
		}

		return $handler->hasPermission(
			schema: $schema,
			action: self::READ_GRANTS,
			userId: $userId,
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end readGrantsAdmits()

	/**
	 * The schema the request named, or null when it cannot be resolved.
	 *
	 * @return Schema|null The schema.
	 */
	private function schema(): ?Schema {
		try {
			$schema = $this->report->schema(reference: $this->objectService->getSchema());
		} catch (\Throwable $e) {
			return null;
		}

		if (($schema instanceof Schema) === false) {
			return null;
		}

		return $schema;
	}//end schema()

	/**
	 * The current caller's uid.
	 *
	 * @return string|null The uid, or null when anonymous.
	 */
	private function callerUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end callerUid()
}//end class
