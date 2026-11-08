<?php

/**
 * ObjectSlugTargetInterface: the slug an object keeps when it lands at another address.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * What callers outside lib/Service need of ObjectSlugHandler: the slug to store on a restore or a move.
 *
 * Bound to ObjectSlugHandler in ContractBindings, so it resolves to the same shared instance.
 */
interface ObjectSlugTargetInterface {

	/**
	 * The slug a restored object gets in its register and schema.
	 *
	 * @param ObjectEntity $entity   The object being restored.
	 * @param Register     $register Its register.
	 * @param Schema       $schema   Its schema.
	 *
	 * @return string|null The slug to store; the entity's own slug when nothing changes.
	 */
	public function slugForRestore(ObjectEntity $entity, Register $register, Schema $schema): string|null;

	/**
	 * The slug an object keeps when a move or migration takes it to another register or schema.
	 *
	 * @param ObjectEntity $entity   The object being moved (still carrying its uuid).
	 * @param Register     $register The target register.
	 * @param Schema       $schema   The target schema.
	 *
	 * @return string|null The slug to store at the target.
	 */
	public function slugForMove(ObjectEntity $entity, Register $register, Schema $schema): string|null;
}//end interface
