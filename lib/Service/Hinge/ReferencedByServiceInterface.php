<?php

/**
 * ReferencedByServiceInterface: what callers outside lib/Service use of ReferencedByService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The part of ReferencedByService that code outside lib/Service uses.
 */
interface ReferencedByServiceInterface {

	/**
	 * Read the records that reference one object, grouped by schema.
	 *
	 * @param ObjectEntity $object The object being read as the hinge.
	 * @param array        $query  Paging: `_limit`, `_offset`, and `_schema` to page one group.
	 * @param bool         $_rbac  Apply the caller's access inside the query.
	 *
	 * @return array{object: array, groups: array<int, array>, total: int}
	 *                                                                    The grouped reverse view.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `_rbac` is the established
	 *   access-flag convention across the object layer (MagicSearchHandler,
	 *   PermissionHandlerInterface, ObjectService all take it); splitting this one method
	 *   in two would make it the exception rather than the rule.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getReferencingGroups(ObjectEntity $object, array $query = [], bool $_rbac = true): array;
}//end interface
