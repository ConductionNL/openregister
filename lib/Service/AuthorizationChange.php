<?php

/**
 * Authorization Change
 *
 * One change to the authorization or role definitions of a register or schema,
 * as the authorization audit records it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The entity whose authorization changed, and its value before and after.
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
 */
final class AuthorizationChange {
	/**
	 * Constructor.
	 *
	 * @param int        $entityId    The register or schema id.
	 * @param string     $entityTitle The register or schema title.
	 * @param array|null $before      The value before the change.
	 * @param array|null $after       The value after the change.
	 */
	public function __construct(
		public readonly int $entityId,
		public readonly string $entityTitle,
		public readonly ?array $before,
		public readonly ?array $after,
	) {
	}//end __construct()
}//end class
