<?php

/**
 * OpenRegister NewRowStamps
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * The uuid and the timestamps a mapper fills on a row it is about to insert.
 *
 * @spec exclude shared mapper plumbing, not a capability of its own
 */
final class NewRowStamps {

	/**
	 * Fill a blank uuid, then stamp created (when unset) and updated with the same time.
	 *
	 * @param ContentReport|ProcessingPurpose|Verwerkingsactiviteit $entity The row to insert.
	 *
	 * @return void
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function onInsert(Entity $entity): void {
		$this->fillBlankUuid(entity: $entity);
		$this->stampCreatedAndUpdated(entity: $entity);
	}//end onInsert()

	/**
	 * Give a row without a uuid (null or empty) a new v4 uuid.
	 *
	 * @param ContentReport|ProcessingPurpose|Verwerkingsactiviteit $entity The row to insert.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function fillBlankUuid(Entity $entity): void {
		if ($entity->getUuid() === null || $entity->getUuid() === '') {
			$entity->setUuid(Uuid::v4()->toRfc4122());
		}
	}//end fillBlankUuid()

	/**
	 * Stamp created, when unset, and updated with the same time.
	 *
	 * @param ContentReport|ProcessingPurpose|Verwerkingsactiviteit $entity The row to insert.
	 *
	 * @return void
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function stampCreatedAndUpdated(Entity $entity): void {
		$now = new DateTime();
		if ($entity->getCreated() === null) {
			$entity->setCreated($now);
		}

		$entity->setUpdated($now);
	}//end stampCreatedAndUpdated()
}//end class
