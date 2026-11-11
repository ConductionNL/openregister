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
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * Default-SECURE: a schema with no `manage` rule is managed by administrators only. Deliberately NOT
 * `PermissionHandlerInterface::hasPermission()`, which is default-OPEN for object data.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
 */
class SchemaManageGate implements SchemaManageGateInterface {

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The caller.
	 * @param IGroupManager $groupManager The caller's groups and admin status.
	 * @param SchemaMapperInterface  $schemaMapper Resolves a schema a row names.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Whether the caller may manage a schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True for an administrator or a member of a group the schema's `manage` rule lists.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
	 */
	public function mayManageRegister(Register $register): bool {
		return $this->allowedByManageRule(authorization: $register->getAuthorization());
	}//end mayManageRegister()

	/**
	 * Whether the caller is an administrator.
	 *
	 * @return bool False without a session.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function callerIsAdministrator(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->groupManager->isAdmin($user->getUID()) === true;
	}//end callerIsAdministrator()

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

		$rules = self::manageRuleOf(authorization: $authorization);
		if ($rules === []) {
			return false;
		}

		return $this->sharesAGroupWith(user: $user, rules: $rules);
	}//end allowedByManageRule()

	/**
	 * The block's `manage` rule, or an empty rule when it has none or it is not a list.
	 *
	 * @param array<string, mixed>|null $authorization The block.
	 *
	 * @return array<mixed> The rule.
	 */
	private static function manageRuleOf(?array $authorization): array {
		$rules = ($authorization['manage'] ?? null);
		if (is_array($rules) === false) {
			return [];
		}

		return $rules;
	}//end manageRuleOf()

	/**
	 * Whether the user is in a group the rule names; refused when membership cannot be read.
	 *
	 * @param IUser        $user  The caller.
	 * @param array<mixed> $rules The non-empty `manage` rule.
	 *
	 * @return bool True when they share a group.
	 */
	private function sharesAGroupWith(IUser $user, array $rules): bool {
		try {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		} catch (Throwable $e) {
			return false;
		}

		return array_intersect($this->groupsNamedBy(rules: $rules), $userGroups) !== [];
	}//end sharesAGroupWith()

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
	 * A row that names no schema is left to the save, which records it as invalid.
	 *
	 * @param array<mixed> $objects The rows.
	 *
	 * @return string|null The first schema refused, or null when every named schema passes.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
	 */
	public function firstUnmanageableRowSchema(array $objects): ?string {
		foreach (self::rowSchemaNames(objects: $objects) as $named) {
			if ($this->mayManageNamed(schemaId: $named) === false) {
				return $named;
			}
		}

		return null;
	}//end firstUnmanageableRowSchema()

	/**
	 * The distinct schemas the rows name, in first-named order.
	 *
	 * A row that names no schema (or names one that is not a non-empty scalar)
	 * is left to the save, which records it as invalid.
	 *
	 * @param array<mixed> $objects The rows.
	 *
	 * @return list<string> The schema ids.
	 */
	private static function rowSchemaNames(array $objects): array {
		$names = [];
		foreach ($objects as $object) {
			$named = self::rowSchemaOf(object: $object);
			if (is_scalar($named) === false || (string)$named === '') {
				continue;
			}

			$names[(string)$named] = (string)$named;
		}

		return array_values($names);
	}//end rowSchemaNames()

	/**
	 * The schema one row names under `@self`, or null when the row is not a map.
	 *
	 * @param mixed $object The row.
	 *
	 * @return mixed The named schema.
	 */
	private static function rowSchemaOf(mixed $object): mixed {
		if (is_array($object) === false) {
			return null;
		}

		return ($object['@self']['schema'] ?? null);
	}//end rowSchemaOf()

	/**
	 * Whether the caller may manage the schema with this id; refused when it cannot be resolved.
	 *
	 * @param string $schemaId The schema id.
	 *
	 * @return bool
	 */
	private function mayManageNamed(string $schemaId): bool {
		try {
			$schema = $this->schemaMapper->find($schemaId);
		} catch (Throwable $e) {
			return false;
		}

		return $this->mayManage(schema: $schema);
	}//end mayManageNamed()
}//end class
