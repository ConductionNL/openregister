<?php

/**
 * TaskRelationMapperInterface: what callers outside the Db component use of TaskRelationMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The methods of TaskRelationMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to TaskRelationMapper. Guarded by F05InterfaceBindingsTest.
 */
interface TaskRelationMapperInterface {

	/**
	 * Insert one relation row.
	 *
	 * @param Entity $entity The relation.
	 *
	 * @return TaskRelation The inserted relation.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
	 */
	public function insert(Entity $entity): Entity;
}//end interface
