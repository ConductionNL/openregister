<?php

/**
 * A special right given to one group, optionally narrowed to a register and schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Which special right a group is given, and what it is narrowed to.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class SpecialRightGrant {
	/**
	 * Describe the grant.
	 *
	 * @param string        $right    The special right's key.
	 * @param string        $group    The group that holds it.
	 * @param string|null   $register The register it is narrowed to, or null for all.
	 * @param string[]|null $schemas  The schemas it is narrowed to, or null for all.
	 */
	public function __construct(
		public readonly string $right,
		public readonly string $group,
		public readonly ?string $register = null,
		public readonly ?array $schemas = null,
	) {
	}//end __construct()

	/**
	 * The entry as an organisation stores it: the group id alone when nothing narrows it.
	 *
	 * @return string|array{group: string, register?: string, schemas?: array<int, string>} The entry.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function entry(): string|array {
		if ($this->register === null && $this->schemas === null) {
			return $this->group;
		}

		$entry = ['group' => $this->group];
		if ($this->register !== null) {
			$entry['register'] = $this->register;
		}

		if ($this->schemas !== null) {
			$entry['schemas'] = array_values($this->schemas);
		}

		return $entry;
	}//end entry()
}//end class
