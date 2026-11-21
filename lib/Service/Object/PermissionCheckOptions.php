<?php

/**
 * PermissionCheckOptions
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The optional parts of a throwing permission check: who asks, who owns the object, the object
 * and the register written to.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionCheckOptions {

	/**
	 * Constructor.
	 *
	 * @param string|null       $userId      The caller, or null for the session user.
	 * @param string|null       $objectOwner The object owner for the ownership check.
	 * @param ObjectEntity|null $object      The object, for conditional authorization matching.
	 * @param string|null       $register    The register written to, for a check that names no object.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly ?string $userId = null,
		public readonly ?string $objectOwner = null,
		public readonly ?ObjectEntity $object = null,
		public readonly ?string $register = null,
	) {
	}//end __construct()
}//end class
