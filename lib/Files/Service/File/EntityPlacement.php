<?php

/**
 * OpenRegister EntityPlacement.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Files\Db\EntityMention;

/**
 * A catalogue entity being placed in one file by ManualEntityService.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class EntityPlacement {
	/**
	 * Constructor.
	 *
	 * @param int        $fileId The file the entity is placed in.
	 * @param GdprEntity $entity The catalogue entity.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly GdprEntity $entity,
	) {
	}//end __construct()

	/**
	 * The catalogue entity's id.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function entityId(): int {
		return (int)$this->entity->getId();
	}//end entityId()
}//end class
