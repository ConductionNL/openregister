<?php

/**
 * OpenRegister Source Harvest Flow
 *
 * Writes, updates, disables and removes the one flow that runs a scheduled source.
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

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Kernel\Contracts\SourceHarvesterInterface;
use OCA\OpenRegister\Kernel\Contracts\StoredFlowWriterInterface;

/**
 * The flow of a scheduled source: schedule and manual triggers into the harvest node, into end.
 *
 * The flow's uuid is the source's uuid, so a second save updates the same
 * flow. Its owner is the administrator saving the source and its
 * organisation the source's; whose rights a run uses comes from the schedule
 * trigger's `runAs`, never from the owner.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
class SourceHarvestFlow {

	/**
	 * The app a source's flow belongs to when the source names none.
	 */
	public const DEFAULT_APP = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param StoredFlowWriterInterface $store Writes, republishes and removes the flow (Processes).
	 */
	public function __construct(
		private readonly StoredFlowWriterInterface $store,
	) {
	}//end __construct()

	/**
	 * Whether a source is run by a flow: it has a schedule and an acting identity.
	 *
	 * @param Source $source The source.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public static function wanted(Source $source): bool {
		return trim((string)$source->getSchedule()) !== '' && trim((string)$source->getRunAs()) !== '';
	}//end wanted()

	/**
	 * Write or update the source's flow, or remove it when the source no longer has a schedule.
	 *
	 * @param Source $source The stored source.
	 * @param string $owner  The user saving the source, who owns the flow definition.
	 *
	 * @return string|null The flow's uuid, or null when the source has no flow.
	 *
	 * @throws \InvalidArgumentException When the schedule trigger rejects the cron or runAs.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function sync(Source $source, string $owner): ?string {
		if (self::wanted(source: $source) === false) {
			$this->store->remove(uuid: (string)$source->getUuid());
			return null;
		}

		return $this->store->put(uuid: (string)$source->getUuid(), definition: self::definition(source: $source), owner: $owner);
	}//end sync()

	/**
	 * Remove the source's flow, if it has one. Its sync records stay as history.
	 *
	 * @param Source $source The source.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function remove(Source $source): void {
		$this->store->remove(uuid: (string)$source->getUuid());
	}//end remove()

	/**
	 * The nodes of a source's flow.
	 *
	 * @param Source $source The source.
	 *
	 * @return list<array{id: string, type: string, config: array<string, mixed>}>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public static function nodes(Source $source): array {
		return [
			[
				'id' => 'schedule',
				'type' => 'openregister.trigger-schedule',
				'config' => ['cron' => trim((string)$source->getSchedule()), 'runAs' => trim((string)$source->getRunAs())],
			],
			['id' => 'manual', 'type' => 'openregister.trigger-manual', 'config' => []],
			['id' => 'harvest', 'type' => SourceHarvesterInterface::NODE_TYPE, 'config' => ['sourceId' => (string)$source->getUuid()]],
			['id' => 'end', 'type' => 'openregister.end', 'config' => []],
		];
	}//end nodes()

	/**
	 * The edges of a source's flow.
	 *
	 * @return list<array{id: string, from: string, to: string}>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public static function edges(): array {
		return [
			['id' => 'e1', 'from' => 'schedule', 'to' => 'harvest'],
			['id' => 'e2', 'from' => 'manual', 'to' => 'harvest'],
			['id' => 'e3', 'from' => 'harvest', 'to' => 'end'],
		];
	}//end edges()

	/**
	 * The flow definition of a scheduled source.
	 *
	 * @param Source $source The source.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public static function definition(Source $source): array {
		$application = trim((string)$source->getApplication());
		if ($application === '') {
			$application = self::DEFAULT_APP;
		}

		return [
			'name' => 'Harvest: '.(string)($source->getTitle() ?? $source->getUuid()),
			'app' => $application,
			'cron' => trim((string)$source->getSchedule()),
			'nodes' => self::nodes(source: $source),
			'edges' => self::edges(),
			'enabled' => $source->getSyncEnabled() !== false,
			'organisation' => $source->getOrganisation(),
		];
	}//end definition()
}//end class
