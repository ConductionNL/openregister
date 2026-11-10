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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectService;

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
class AccessReviewGate implements AccessReviewGateInterface {

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
	 * @param CallerContext      $caller        The calling principal and its groups.
	 * @param GrantManageGuard   $grantManage   The owner, owning group, administrator and `grant_manage` rule.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ObjectAccessReport $report,
		private readonly CallerContext $caller,
		private readonly GrantManageGuard $grantManage,
	) {
	}//end __construct()

	/**
	 * Resolve the object through the RBAC boundary, or null when the caller cannot read it.
	 *
	 * @param ObjectRef $ref The register and schema (slug or id) and the object uuid.
	 *
	 * @return ObjectEntity|null The object, or null.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function resolve(ObjectRef $ref): ?ObjectEntity {
		$this->objectService->setRegister((string)$ref->register);
		$this->objectService->setSchema((string)$ref->schema);
		$this->objectService->setObject($ref->id);

		return $this->currentObject();
	}//end resolve()

	/**
	 * The object the object service is pointed at, or null when the caller cannot read it.
	 *
	 * @return ObjectEntity|null The object, or null.
	 */
	private function currentObject(): ?ObjectEntity {
		try {
			$object = $this->objectService->getObject();
		} catch (\Throwable $e) {
			return null;
		}

		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		return $object;
	}//end currentObject()

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

		if ($userId !== null && $this->caller->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->managesTheSchema();
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
		if ($this->grantManage->admittedAs(userId: $userId, userGroups: $this->callerGroups(), object: $object) !== null) {
			return true;
		}

		return $this->readGrantsAdmits(object: $object);
	}//end mayReadGrants()

	/**
	 * Whether this caller holds `manage` here through a rule somebody wrote.
	 *
	 * 🔴 THE GRANT HAS TO BE WRITTEN DOWN, UNDER `manage` ITSELF. A schema that
	 * configures nothing resolves default-open, and a block carrying
	 * `"public": true` grants every verb to every caller, so asking
	 * `hasPermission('manage')` alone would make every reader an auditor and
	 * hand back the rules `renderEntity()` strips from a non-admin read.
	 *
	 * @return bool True when the caller may review access here.
	 */
	private function managesTheSchema(): bool {
		return $this->writtenRuleAdmits(action: 'manage', object: null);
	}//end managesTheSchema()

	/**
	 * Whether the record's `readGrants` rule admits this caller.
	 *
	 * The record is passed, so a conditional entry such as `{"group":
	 * "authenticated", "match": {"assignee": "$userId"}}` is evaluated against
	 * it, and an object-level `readGrants` counts.
	 *
	 * @param ObjectEntity $object The record.
	 *
	 * @return bool True when a written `readGrants` rule admits the caller.
	 */
	private function readGrantsAdmits(ObjectEntity $object): bool {
		if ($this->callerUid() === null) {
			return false;
		}

		return $this->writtenRuleAdmits(action: self::READ_GRANTS, object: $object);
	}//end readGrantsAdmits()

	/**
	 * Whether an entry written under this verb admits the caller, and the full decision agrees.
	 *
	 * 🔴 NEITHER DEFAULT-OPEN NOR `public: true` COUNTS. Both make
	 * `hasPermission()` answer yes for every verb without any entry naming the
	 * caller. So the verb's own list must be written and non-empty, and one of
	 * its entries must admit the caller on its own: the list is matched alone,
	 * in a block that holds nothing else, for `authenticated` and each of the
	 * caller's groups (user entries and `match` clauses included, through
	 * `hasGroupPermission()`). Only then is `hasPermission()` asked, so an
	 * enforced deny still refuses.
	 *
	 * The caller is the session's user, or nobody.
	 *
	 * @param string            $action The verb.
	 * @param ObjectEntity|null $object The record, for object-level rules and conditions; null asks at schema level.
	 *
	 * @return bool True when a written entry admits the caller.
	 */
	private function writtenRuleAdmits(string $action, ?ObjectEntity $object): bool {
		$schema = $this->schema();
		if ($schema === null) {
			return false;
		}

		$run = new WrittenRuleRun(handler: $this->objectService->getPermissionHandler(), schema: $schema, action: $action, object: $object);
		$ownList = $this->ownRuleList(run: $run);
		if ($ownList === [] || $this->aGroupAdmits(run: $run, ownList: $ownList) === false) {
			return false;
		}

		return $run->handler->hasPermission(
			schema: $schema,
			action: $action,
			userId: $this->callerUid(),
			objectOwner: $object?->getOwner(),
			object: $object
		);
	}//end writtenRuleAdmits()

	/**
	 * The action's own rule list, as a one-action block; empty when the rules cannot admit.
	 *
	 * @param WrittenRuleRun $run The handler, the schema, the verb and the record (null for a schema-level question).
	 *
	 * @return array<string, array> [$action => rules], or [] when nothing could admit.
	 */
	private function ownRuleList(WrittenRuleRun $run): array {
		$action = $run->action;
		try {
			$resolved = $run->handler->resolveAuthorization(schema: $run->schema, object: $run->object);
		} catch (\Throwable $e) {
			// Fail closed: rules nobody could resolve admit nobody.
			return [];
		}

		if (is_array($resolved) === false || empty($resolved[$action]) === true || is_array($resolved[$action]) === false) {
			return [];
		}

		return [$action => $resolved[$action]];
	}//end ownRuleList()

	/**
	 * Whether the caller's groups (authenticated first) are named by the one-action block.
	 *
	 * @param WrittenRuleRun       $run     The handler and the record (null for a schema-level question).
	 * @param array<string, array> $ownList [$action => rules].
	 *
	 * @return bool True at the first group the block admits.
	 */
	private function aGroupAdmits(WrittenRuleRun $run, array $ownList): bool {
		$userId = $this->callerUid();
		$object = $run->object;
		$action = (string)array_key_first($ownList);
		foreach (['authenticated', ...$this->callerGroups()] as $groupId) {
			if ($run->handler->hasGroupPermission(
				authorization: $ownList,
				groupId: (string)$groupId,
				action: $action,
				userId: $userId,
				objectData: $object?->getMatchEnvelope(),
				objectOrganisation: $object?->getOrganisation()
			) === true
			) {
				return true;
			}
		}

		return false;
	}//end aGroupAdmits()

	/**
	 * The caller's group ids.
	 *
	 * @return string[] The groups, empty when anonymous.
	 */
	private function callerGroups(): array {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return [];
		}

		return $this->caller->groupManager->getUserGroupIds($user);
	}//end callerGroups()

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
		return $this->caller->userSession->getUser()?->getUID();
	}//end callerUid()
}//end class
