<?php

/**
 * RbacPermissionAsk
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;

/**
 * What a schema-level RBAC check is asked: the schema, the action, and
 * optionally the owner and data of an object that is not a stored row.
 *
 * A stored row's own `_authorization` and uuid are never named here; a check
 * on a row reads them off the row ({@see MagicRbacHandler::hasRowPermission()}).
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */
final class RbacPermissionAsk {

	/**
	 * Name the check.
	 *
	 * @param Schema      $schema      Schema to check.
	 * @param string      $action      CRUD action to check.
	 * @param string|null $objectOwner Optional object owner for the ownership check.
	 * @param array|null  $objectData  Optional object data for conditional checks.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $action,
		public readonly ?string $objectOwner=null,
		public readonly ?array $objectData=null,
	) {
	}//end __construct()
}//end class
