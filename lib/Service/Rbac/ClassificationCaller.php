<?php

/**
 * ClassificationCaller: who asks the classification gate, and for what.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The caller of one classification check.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
 */
final class ClassificationCaller {

	/**
	 * Constructor.
	 *
	 * @param string|null        $userId The caller, or null when anonymous.
	 * @param array<int, string> $groups The groups they hold.
	 * @param string             $action The action asked for.
	 * @param bool|null          $administrator Nextcloud's administrator verdict, or null to read the groups.
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly array $groups,
		public readonly string $action,
		public readonly ?bool $administrator=null,
	) {
	}//end __construct()

	/**
	 * Whether the caller is an administrator.
	 *
	 * Nextcloud's verdict when one was passed, so an `admin` group member outside
	 * `allowed_admin_ranges` is not an administrator; else group membership.
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
}//end class
