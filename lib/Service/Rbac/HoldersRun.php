<?php

/**
 * One level of a holders listing: where the block is written and the register's role definitions.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The level being read and the role definitions roles are expanded with.
 * Built and consumed by ObjectPermissionsResolver only; it never leaves that class.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class HoldersRun {
	/**
	 * Set the fields.
	 *
	 * @param string $level           Where the block is written.
	 * @param mixed  $roleDefinitions The register's role definitions.
	 */
	public function __construct(
		public readonly string $level,
		public readonly mixed $roleDefinitions,
	) {
	}//end __construct()
}//end class
