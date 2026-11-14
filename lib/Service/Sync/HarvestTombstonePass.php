<?php

/**
 * OpenRegister Harvest Tombstone Pass
 *
 * After a complete run: tombstones what the source no longer carries and applies the
 * source's delete strategy to the linked objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use DateTime;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tombstones the records a complete run did not see and applies the delete strategy.
 *
 * `flag` (the default) only tombstones; `soft-delete` also soft-deletes the
 * linked objects; `hard-delete` deletes them permanently (never offered for an
 * app's source); `ignore` neither tombstones nor deletes.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
final class HarvestTombstonePass {

	/**
	 * Tombstone only; the local object is untouched.
	 */
	public const STRATEGY_FLAG = 'flag';

	/**
	 * Tombstone and soft-delete the local object.
	 */
	public const STRATEGY_SOFT_DELETE = 'soft-delete';

	/**
	 * Tombstone and delete the local object permanently.
	 */
	public const STRATEGY_HARD_DELETE = 'hard-delete';

	/**
	 * Do nothing about records the source no longer carries.
	 */
	public const STRATEGY_IGNORE = 'ignore';

	/**
	 * Every delete strategy a source may hold.
	 */
	public const STRATEGIES = [self::STRATEGY_FLAG, self::STRATEGY_SOFT_DELETE, self::STRATEGY_HARD_DELETE, self::STRATEGY_IGNORE];

	/**
	 * Constructor.
	 *
	 * @param SyncRecordMapperInterface $records The sync records.
	 * @param ObjectOperationsInterface $objects Deletes the linked objects.
	 * @param LoggerInterface           $logger  Logger.
	 */
	public function __construct(
		private readonly SyncRecordMapperInterface $records,
		private readonly ObjectOperationsInterface $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The source's delete strategy; an empty or unknown one reads as `flag`.
	 *
	 * @param Source $source The source.
	 *
	 * @return string One of STRATEGIES.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public static function strategyOf(Source $source): string {
		$strategy = (string)($source->getDeleteStrategy() ?? '');
		if (in_array($strategy, self::STRATEGIES, true) === false) {
			return self::STRATEGY_FLAG;
		}

		return $strategy;
	}//end strategyOf()

	/**
	 * Tombstone what a complete run did not see and apply the delete strategy.
	 *
	 * @param Source              $source  The source.
	 * @param array<string, true> $seenIds External ids the run saw, as keys.
	 *
	 * @return array{tombstoned: int, deleted: int} How many records were tombstoned and objects deleted.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function apply(Source $source, array $seenIds): array {
		$strategy = self::strategyOf(source: $source);
		if ($strategy === self::STRATEGY_IGNORE) {
			return ['tombstoned' => 0, 'deleted' => 0];
		}

		$at         = new DateTime();
		$sourceId   = (int)$source->getId();
		$tombstoned = $this->records->markTombstones(sourceId: $sourceId, seenIds: $seenIds, at: $at);
		if ($tombstoned === 0 || $strategy === self::STRATEGY_FLAG) {
			return ['tombstoned' => $tombstoned, 'deleted' => 0];
		}

		$deleted = 0;
		foreach ($this->records->objectUuidsTombstonedAt(sourceId: $sourceId, at: $at) as $uuid) {
			if ($this->deleteObject(source: $source, uuid: $uuid, permanent: $strategy === self::STRATEGY_HARD_DELETE) === true) {
				$deleted++;
			}
		}

		return ['tombstoned' => $tombstoned, 'deleted' => $deleted];
	}//end apply()

	/**
	 * Delete one tombstoned object; a failure is logged and the pass goes on.
	 *
	 * @param Source $source    The source.
	 * @param string $uuid      The object.
	 * @param bool   $permanent Whether the delete is permanent.
	 *
	 * @return bool Whether the object was deleted.
	 */
	private function deleteObject(Source $source, string $uuid, bool $permanent): bool {
		try {
			return $this->objects->deleteObject(
				deletion: new ObjectDeletion(
					uuid: $uuid,
					target: new ObjectFindTarget(register: $source->getTargetRegister(), schema: $source->getTargetSchema()),
					permanent: $permanent
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[HarvestTombstonePass] Could not delete a tombstoned object: '.$e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'source' => $source->getId(), 'object' => $uuid]
			);
			return false;
		}
	}//end deleteObject()
}//end class
