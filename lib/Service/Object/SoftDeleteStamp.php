<?php

/**
 * OpenRegister SoftDeleteStamp
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The deletion metadata a batched cascade soft delete writes on each target.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SoftDeleteStamp {
	/**
	 * @param string $deletedBy    The deleting user, or `system`.
	 * @param mixed  $organisation The deleting user's active organisation.
	 */
	public function __construct(
		private readonly string $deletedBy,
		private readonly mixed $organisation,
	) {
	}//end __construct()

	/**
	 * Stamp every target that has a uuid as deleted now.
	 *
	 * @param ObjectEntity[] $targets The entities to soft-delete.
	 *
	 * @return array{0: array<string, true>, 1: array<string, ObjectEntity>} The stamped uuids, and a
	 *                                                                     copy of each target taken before stamping.
	 */
	public function apply(array $targets): array {
		$deletedAt = (new DateTime())->format(DateTime::ATOM);
		$handled = [];
		$oldEntities = [];
		foreach ($targets as $entity) {
			$uuid = $entity->getUuid();
			if ($uuid === null || $uuid === '') {
				continue;
			}

			$handled[$uuid] = true;
			$oldEntities[$uuid] = clone $entity;
			$entity->setDeleted($this->metadata(objectId: $uuid, deletedAt: $deletedAt));
		}

		return [$handled, $oldEntities];
	}//end apply()

	/**
	 * The deletion metadata of one object.
	 *
	 * @param string $objectId  The object's uuid.
	 * @param string $deletedAt The deletion time, ISO 8601.
	 *
	 * @return array{deletedBy: string, deletedAt: string, objectId: string, organisation: mixed}
	 */
	private function metadata(string $objectId, string $deletedAt): array {
		return [
			'deletedBy' => $this->deletedBy,
			'deletedAt' => $deletedAt,
			'objectId' => $objectId,
			'organisation' => $this->organisation,
		];
	}//end metadata()
}//end class
