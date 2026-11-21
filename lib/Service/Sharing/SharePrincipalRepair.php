<?php

/**
 * Share-principal repair: recomputes stale derived share lists on stored objects.
 *
 * An object written before the derived lists existed, or through a write path
 * that bypasses {@see SharePrincipalDeriver::apply()}, can carry `sharedUsers`
 * / `sharedGroups` that disagree with its `sharedWith`. Either direction is an
 * access-control bug for the list predicates that match those lists, so every
 * stored object is checked and only the ones whose lists differ are rewritten.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use Throwable;

/**
 * Walks stored objects page by page and rewrites those whose derived share lists are stale.
 *
 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
 */
final class SharePrincipalRepair {

	/**
	 * How many objects one page reads.
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SharePrincipalDeriverInterface $deriver Recomputes the derived lists.
	 */
	public function __construct(
		private readonly SharePrincipalDeriverInterface $deriver,
	) {
	}//end __construct()

	/**
	 * Check every object the reader returns and rewrite the stale ones.
	 *
	 * @param Closure(int, int): array<int, mixed>   $page  Reads the objects at an offset, at most a limit of them.
	 * @param Closure(ObjectEntity, array): void     $write Writes an object's corrected data.
	 *
	 * @return array{checked: int, repaired: int, failed: array<int, string>} What was done; failed names the uuids.
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
	 */
	public function repair(Closure $page, Closure $write): array {
		$report = ['checked' => 0, 'repaired' => 0, 'failed' => []];
		$offset = 0;

		do {
			$objects = $page($offset, self::PAGE_SIZE);
			$full = (count($objects) === self::PAGE_SIZE);
			foreach ($objects as $object) {
				if (($object instanceof ObjectEntity) === false) {
					continue;
				}

				$report['checked']++;
				$this->repairOne(object: $object, write: $write, report: $report);
			}

			$offset += self::PAGE_SIZE;
		} while ($full === true);

		return $report;
	}//end repair()

	/**
	 * Whether an object's stored derived lists differ from what its share list derives.
	 *
	 * @param array<string, mixed> $data The stored object data.
	 *
	 * @return bool True when the lists are stale.
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
	 */
	public function isStale(array $data): bool {
		$derived = $this->deriver->apply(object: $data);

		return ($data[SharePrincipalDeriverInterface::PROP_SHARED_USERS] ?? null) !== $derived[SharePrincipalDeriverInterface::PROP_SHARED_USERS]
			|| ($data[SharePrincipalDeriverInterface::PROP_SHARED_GROUPS] ?? null) !== $derived[SharePrincipalDeriverInterface::PROP_SHARED_GROUPS];
	}//end isStale()

	/**
	 * Rewrite one object when its lists are stale, recording a failure by uuid.
	 *
	 * @param ObjectEntity                                                   $object The stored object.
	 * @param Closure(ObjectEntity, array): void                             $write  Writes the corrected data.
	 * @param array{checked: int, repaired: int, failed: array<int, string>} $report The report, updated in place.
	 *
	 * @return void
	 */
	private function repairOne(ObjectEntity $object, Closure $write, array &$report): void {
		$data = $object->getObject();
		if (is_array($data) === false || $this->isStale(data: $data) === false) {
			return;
		}

		try {
			$write($object, $this->deriver->apply(object: $data));
			$report['repaired']++;
		} catch (Throwable) {
			$report['failed'][] = (string)$object->getUuid();
		}
	}//end repairOne()
}//end class
