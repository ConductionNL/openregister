<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether the caller may manage a schema, and which schema of a mixed-schema batch they may not.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/bulk-save-applies-property-authorization/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * Default-SECURE: a schema with no `manage` rule is managed by administrators only. Deliberately NOT
 * `PermissionHandler::hasPermission()`, which is default-OPEN for object data.
 *
 * @spec openspec/changes/bulk-save-applies-property-authorization/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
 */
class SchemaManageGate {

	/**
	 * What firstUnmanageableRowSchema() names for a row that names no `@self.schema`.
	 *
	 * @var string
	 */
	public const NO_SCHEMA = '(none)';

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The caller.
	 * @param IGroupManager $groupManager The caller's groups and admin status.
	 * @param SchemaMapper  $schemaMapper Resolves a schema a row names.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Whether the caller may manage a schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True for an administrator or a member of a group the schema's `manage` rule lists.
	 *
	 * @spec openspec/changes/bulk-save-applies-property-authorization/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
	 */
	public function mayManage(Schema $schema): bool {
		return $this->allowedByManageRule(authorization: $schema->getAuthorization());
	}//end mayManage()

	/**
	 * Whether the caller may manage a register, by the same default-secure rule.
	 *
	 * @param Register $register The register.
	 *
	 * @return bool True for an administrator or a member of a group the register's `manage` rule lists.
	 *
	 * @spec openspec/changes/bulk-save-applies-property-authorization/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
	 */
	public function mayManageRegister(Register $register): bool {
		return $this->allowedByManageRule(authorization: $register->getAuthorization());
	}//end mayManageRegister()

	/**
	 * Whether an authorization block's `manage` rule admits the caller; administrators always pass.
	 *
	 * @param array<string, mixed>|null $authorization The block.
	 *
	 * @return bool True when admitted.
	 */
	private function allowedByManageRule(?array $authorization): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		$rules = ($authorization['manage'] ?? null);
		if (is_array($rules) === false || $rules === []) {
			return false;
		}

		try {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		} catch (Throwable $e) {
			return false;
		}

		return array_intersect($this->groupsNamedBy(rules: $rules), $userGroups) !== [];
	}//end allowedByManageRule()

	/**
	 * The groups a `manage` rule names, as plain strings or `{group: ...}` entries.
	 *
	 * @param array<mixed> $rules The rule.
	 *
	 * @return list<string> The group ids.
	 */
	private function groupsNamedBy(array $rules): array {
		$groups = [];
		foreach ($rules as $entry) {
			if (is_array($entry) === true) {
				$entry = ($entry['group'] ?? null);
			}

			if (is_string($entry) === true) {
				$groups[] = $entry;
			}
		}

		return $groups;
	}//end groupsNamedBy()

	/**
	 * The first schema a mixed-schema batch's rows name that the caller may not manage, or that cannot be resolved.
	 *
	 * A row that names no schema in `@self.schema` is refused as well, under the
	 * placeholder `(none)`: the save also accepts a flat row whose top-level
	 * `schema` names one, and a row the gate cannot attribute to a schema must not
	 * slip past the manage check (fail closed).
	 *
	 * @param array<mixed> $objects The rows.
	 *
	 * @return string|null The first schema refused, or null when every row names a schema that passes.
	 *
	 * @spec openspec/changes/bulk-save-applies-property-authorization/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
	 */
	public function firstUnmanageableRowSchema(array $objects): ?string {
		$checked = [];
		foreach ($objects as $object) {
			$named = null;
			if (is_array($object) === true) {
				$named = ($object['@self']['schema'] ?? null);
			}

			if (is_scalar($named) === false || (string)$named === '') {
				return self::NO_SCHEMA;
			}

			if (isset($checked[(string)$named]) === true) {
				continue;
			}

			$checked[(string)$named] = true;
			try {
				$schema = $this->schemaMapper->find((string)$named);
			} catch (Throwable $e) {
				return (string)$named;
			}

			if ($this->mayManage(schema: $schema) === false) {
				return (string)$named;
			}
		}//end foreach

		return null;
	}//end firstUnmanageableRowSchema()
}//end class
