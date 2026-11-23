<?php

/**
 * OpenRegister StoredFlowWriter
 *
 * Writes, updates, republishes and removes one scheduled flow by uuid.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Kernel\Contracts\StoredFlowWriterInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Keeps one schedule-triggered flow equal to its owner's definition.
 *
 * A new flow is inserted, validated and published. A stored flow whose nodes, edges and cron
 * are unchanged is updated in place (enabled flag, name, organisation); otherwise a published
 * flow gets a draft first, then is validated, updated and published again. A stored flow keeps
 * its owner.
 */
class StoredFlowWriter implements StoredFlowWriterInterface {

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface           $mapper    The flows.
	 * @param FlowVersionServiceInterface   $versions  Drafts and publishes versions.
	 * @param FlowServiceInterface          $flows     Deletes a flow with its registrations.
	 * @param FlowTriggerValidatorInterface $validator Validates the trigger before a write.
	 */
	public function __construct(
		private readonly FlowMapperInterface $mapper,
		private readonly FlowVersionServiceInterface $versions,
		private readonly FlowServiceInterface $flows,
		private readonly FlowTriggerValidatorInterface $validator,
	) {
	}//end __construct()

	/**
	 * Create, update in place or republish the flow.
	 *
	 * @param string               $uuid       The flow uuid.
	 * @param array<string, mixed> $definition The flow definition.
	 * @param string               $owner      The owner of a new flow.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function put(string $uuid, array $definition, string $owner): string {
		$stored = $this->stored(uuid: $uuid);
		if ($stored === null) {
			return $this->create(uuid: $uuid, definition: $definition, owner: $owner);
		}

		if ($this->graphMatches(definition: $definition, flow: $stored) === true) {
			$this->apply(definition: $definition, flow: $stored, owner: (string)($stored->getOwner() ?? $owner));
			$this->mapper->update($stored);
			return (string)$stored->getUuid();
		}

		$this->republish(definition: $definition, flow: $stored, owner: (string)($stored->getOwner() ?? $owner));

		return (string)$stored->getUuid();
	}//end put()

	/**
	 * Delete the flow when one is stored.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function remove(string $uuid): void {
		$stored = $this->stored(uuid: $uuid);
		if ($stored !== null) {
			$this->flows->deleteStored(flow: $stored);
		}
	}//end remove()

	/**
	 * The stored flow, or null.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow|null
	 */
	private function stored(string $uuid): ?Flow {
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
	 * Whether the stored graph and cron equal the definition's.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param Flow                 $flow       The stored flow.
	 *
	 * @return bool
	 */
	private function graphMatches(array $definition, Flow $flow): bool {
		return $flow->getNodes() === $definition['nodes']
			&& $flow->getEdges() === $definition['edges']
			&& $flow->getCron() === $definition['cron'];
	}//end graphMatches()

	/**
	 * Copy the definition onto the flow.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param Flow                 $flow       The flow.
	 * @param string               $owner      The owner.
	 *
	 * @return void
	 */
	private function apply(array $definition, Flow $flow, string $owner): void {
		$flow->setName((string)$definition['name']);
		$flow->setApp((string)$definition['app']);
		$flow->setTrigger(Flow::TRIGGER_SCHEDULE);
		$flow->setCron((string)$definition['cron']);
		$flow->setNodes($definition['nodes']);
		$flow->setEdges($definition['edges']);
		$flow->setEnabled((bool)$definition['enabled']);
		$flow->setOwner($owner);
		$flow->setOrganisation($definition['organisation']);
		$flow->setUpdated(new DateTime());
	}//end apply()

	/**
	 * Insert and publish a new flow.
	 *
	 * @param string               $uuid       The flow uuid.
	 * @param array<string, mixed> $definition The definition.
	 * @param string               $owner      The owner.
	 *
	 * @return string
	 */
	private function create(string $uuid, array $definition, string $owner): string {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setCreated(new DateTime());
		$this->apply(definition: $definition, flow: $flow, owner: $owner);
		$this->validator->validate(flow: $flow);

		$stored = $this->mapper->insert($flow);
		$this->versions->publish(flow: $stored, options: new FlowPublishOptions(publishedBy: $owner));

		return (string)$flow->getUuid();
	}//end create()

	/**
	 * Draft a published flow, then validate, update and publish it again.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param Flow                 $flow       The stored flow.
	 * @param string               $owner      The owner.
	 *
	 * @return void
	 */
	private function republish(array $definition, Flow $flow, string $owner): void {
		if ($flow->getLifecycleStatus() === FlowVersion::STATUS_PUBLISHED) {
			$this->versions->createDraft(flow: $flow);
		}

		$this->apply(definition: $definition, flow: $flow, owner: $owner);
		$this->validator->validate(flow: $flow);
		$this->mapper->update($flow);
		$this->versions->publish(flow: $flow, options: new FlowPublishOptions(publishedBy: $owner));
	}//end republish()
}//end class
