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

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Flow\FlowPublishOptions;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidatorInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\HarvestSourceNode;
use OCP\AppFramework\Db\DoesNotExistException;

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
	 * @param FlowMapperInterface           $mapper    The flow table.
	 * @param FlowVersionServiceInterface   $versions  Publishes a flow's head.
	 * @param FlowServiceInterface          $flows     Deletes a flow with its runs and versions.
	 * @param FlowTriggerValidatorInterface $validator Validates the schedule trigger (cron and runAs).
	 */
	public function __construct(
		private readonly FlowMapperInterface $mapper,
		private readonly FlowVersionServiceInterface $versions,
		private readonly FlowServiceInterface $flows,
		private readonly FlowTriggerValidatorInterface $validator,
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
		$stored = $this->stored(source: $source);
		if (self::wanted(source: $source) === false) {
			if ($stored !== null) {
				$this->flows->deleteStored(flow: $stored);
			}

			return null;
		}

		if ($stored === null) {
			return $this->create(source: $source, owner: $owner);
		}

		if ($this->graphMatches(source: $source, flow: $stored) === true) {
			$this->apply(source: $source, flow: $stored, owner: (string)($stored->getOwner() ?? $owner));
			$this->mapper->update($stored);
			return (string)$stored->getUuid();
		}

		$this->republish(source: $source, flow: $stored, owner: (string)($stored->getOwner() ?? $owner));

		return (string)$stored->getUuid();
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
		$stored = $this->stored(source: $source);
		if ($stored !== null) {
			$this->flows->deleteStored(flow: $stored);
		}
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
			['id' => 'harvest', 'type' => HarvestSourceNode::TYPE, 'config' => ['sourceId' => (string)$source->getUuid()]],
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
	 * The source's stored flow, or null.
	 *
	 * @param Source $source The source.
	 *
	 * @return Flow|null
	 */
	private function stored(Source $source): ?Flow {
		$uuid = (string)$source->getUuid();
		if ($uuid === '') {
			return null;
		}

		try {
			return $this->mapper->findByUuid(uuid: $uuid);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end stored()

	/**
	 * Whether the stored flow already runs this graph.
	 *
	 * @param Source $source The source.
	 * @param Flow   $flow   The stored flow.
	 *
	 * @return bool
	 */
	private function graphMatches(Source $source, Flow $flow): bool {
		return $flow->getNodes() === self::nodes(source: $source)
			&& $flow->getEdges() === self::edges()
			&& $flow->getCron() === trim((string)$source->getSchedule());
	}//end graphMatches()

	/**
	 * Copy the source onto a flow.
	 *
	 * @param Source $source The source.
	 * @param Flow   $flow   The flow.
	 * @param string $owner  The flow's owner.
	 *
	 * @return void
	 */
	private function apply(Source $source, Flow $flow, string $owner): void {
		$application = trim((string)$source->getApplication());
		if ($application === '') {
			$application = self::DEFAULT_APP;
		}

		$flow->setName('Harvest: '.(string)($source->getTitle() ?? $source->getUuid()));
		$flow->setApp($application);
		$flow->setTrigger(Flow::TRIGGER_SCHEDULE);
		$flow->setCron(trim((string)$source->getSchedule()));
		$flow->setNodes(self::nodes(source: $source));
		$flow->setEdges(self::edges());
		$flow->setEnabled($source->getSyncEnabled() !== false);
		$flow->setOwner($owner);
		$flow->setOrganisation($source->getOrganisation());
		$flow->setUpdated(new DateTime());
	}//end apply()

	/**
	 * Insert the source's flow and publish its first version.
	 *
	 * @param Source $source The source.
	 * @param string $owner  The flow's owner.
	 *
	 * @return string The flow's uuid.
	 */
	private function create(Source $source, string $owner): string {
		$flow = new Flow();
		$flow->setUuid((string)$source->getUuid());
		$flow->setCreated(new DateTime());
		$this->apply(source: $source, flow: $flow, owner: $owner);
		$this->validator->validate(flow: $flow);

		$stored = $this->mapper->insert($flow);
		$this->versions->publish(flow: $stored, options: new FlowPublishOptions(publishedBy: $owner));

		return (string)$flow->getUuid();
	}//end create()

	/**
	 * Write a changed graph as the flow's next published version.
	 *
	 * @param Source $source The source.
	 * @param Flow   $flow   The stored flow.
	 * @param string $owner  The flow's owner.
	 *
	 * @return void
	 */
	private function republish(Source $source, Flow $flow, string $owner): void {
		if ($flow->getLifecycleStatus() === FlowVersion::STATUS_PUBLISHED) {
			$this->versions->createDraft(flow: $flow);
		}

		$this->apply(source: $source, flow: $flow, owner: $owner);
		$this->validator->validate(flow: $flow);
		$this->mapper->update($flow);
		$this->versions->publish(flow: $flow, options: new FlowPublishOptions(publishedBy: $owner));
	}//end republish()
}//end class
