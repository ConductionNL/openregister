<?php

/**
 * OpenRegister ObjectDeleteRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCP\IUser;

/**
 * What one ObjectService::deleteObject() call asks for, and on whose behalf.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class ObjectDeleteRequest {

	/**
	 * Constructor.
	 *
	 * @param string     $uuid           The object to delete.
	 * @param bool       $rbac           Whether to apply RBAC checks.
	 * @param bool       $multitenancy   Whether to apply multitenancy filtering.
	 * @param bool       $retentionSweep Whether the call comes from the retention sweep.
	 * @param IUser|null $currentUser    Explicit acting user, or null for the session user.
	 * @param bool       $scoped         Whether the caller supplied both register and schema.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly bool $rbac,
		public readonly bool $multitenancy,
		public readonly bool $retentionSweep,
		public readonly ?IUser $currentUser,
		public readonly bool $scoped,
	) {
	}//end __construct()

	/**
	 * The explicit acting user's id, or null to let the permission check use the session user.
	 *
	 * @return string|null The acting user id.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function actingUserId(): ?string {
		return $this->currentUser?->getUID();
	}//end actingUserId()
}//end class
