<?php

/**
 * The reader a read rule is matched against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * A user id (null for an anonymous caller) and the user's groups.
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */
final class ReadRuleCaller {

	/**
	 * Name the caller.
	 *
	 * @param string|null        $userId The user, or null when anonymous.
	 * @param array<int, string> $groups The user's group ids.
	 * @param bool|null          $administrator Nextcloud's administrator verdict, or null to read the groups.
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly array $groups = [],
		public readonly ?bool $administrator = null,
	) {
	}//end __construct()

	/**
	 * Whether the caller is an administrator.
	 *
	 * Nextcloud's verdict when the builder passed one, so an `admin` group member
	 * outside `allowed_admin_ranges` is not one; else group membership.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function isAdministrator(): bool {
		if ($this->administrator !== null) {
			return $this->administrator;
		}

		return in_array('admin', $this->groups, true);
	}//end isAdministrator()

	/**
	 * The principals a rule entry may name for this caller, in the permission check's order.
	 *
	 * A signed-in caller holds `authenticated`, the user-override sentinel `''`, each group
	 * and `public`; an anonymous caller holds `public` only.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	public function principals(): array {
		if ($this->userId === null) {
			return ['public'];
		}

		return ['authenticated', '', ...array_values($this->groups), 'public'];
	}//end principals()
}//end class
