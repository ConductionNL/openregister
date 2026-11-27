<?php

/**
 * OpenRegister PermissionMatrixSubject
 *
 * One row subject of the permission matrix: a group, or a user with their groups.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * A group, or a user with the groups they are a member of.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */
final class PermissionMatrixSubject {

	public const TYPE_GROUP = 'group';

	public const TYPE_USER = 'user';

	/**
	 * The Nextcloud group whose members bypass every rule.
	 *
	 * @var string
	 */
	public const ADMIN_GROUP = 'admin';

	/**
	 * Constructor.
	 *
	 * @param string             $type   `group` or `user`.
	 * @param string             $id     The group id or the user id.
	 * @param array<int, string> $groups The user's groups; empty for a group.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
		public readonly array $groups = [],
	) {
	}//end __construct()

	/**
	 * A group subject.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return self The subject.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public static function group(string $groupId): self {
		return new self(type: self::TYPE_GROUP, id: $groupId);
	}//end group()

	/**
	 * A user subject with their groups.
	 *
	 * @param string             $userId The user id.
	 * @param array<int, string> $groups The groups they are a member of.
	 *
	 * @return self The subject.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public static function user(string $userId, array $groups): self {
		return new self(type: self::TYPE_USER, id: $userId, groups: array_values($groups));
	}//end user()

	/**
	 * The principal names a rule can reach this subject under.
	 *
	 * A group is reached by its own id; a user by their id and each of their
	 * groups. Both are reached by `authenticated` and `public`, the pseudo
	 * groups every signed-in caller belongs to.
	 *
	 * @return array<int, string> The principal names.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function principals(): array {
		return array_values(array_unique([$this->id, ...$this->groups, 'authenticated', 'public']));
	}//end principals()

	/**
	 * Whether this subject is a Nextcloud administrator, who passes every rule.
	 *
	 * @return bool True for the admin group or one of its members.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function isAdministrator(): bool {
		if ($this->type === self::TYPE_GROUP) {
			return $this->id === self::ADMIN_GROUP;
		}

		return in_array(self::ADMIN_GROUP, $this->groups, true);
	}//end isAdministrator()
}//end class
