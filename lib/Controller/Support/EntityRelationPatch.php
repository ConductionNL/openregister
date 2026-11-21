<?php

/**
 * OpenRegister entity relation patch
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\EntityRelation;
use OCP\IUser;

/**
 * One PATCH of an entity relation: the row id the route named, the row, and who acts.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final readonly class EntityRelationPatch {
	/**
	 * Bundle the relation being patched with its id and the acting user.
	 *
	 * @param int            $id       Relation row id, as the route named it.
	 * @param EntityRelation $relation The relation row.
	 * @param IUser          $user     The acting user.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public int $id,
		public EntityRelation $relation,
		public IUser $user,
	) {
	}//end __construct()
}//end class
