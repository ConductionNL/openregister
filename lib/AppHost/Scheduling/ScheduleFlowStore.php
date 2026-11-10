<?php

/**
 * OpenRegister AppHost: stores compiled schedule flows.
 *
 * Writes through the mapper, not FlowService::save(): that stamps the caller's
 * active organisation, and a compiled flow belongs to the application's. The
 * trigger validator and the version service around it are the same ones.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidatorInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowPublishOptions;

/**
 * Reads, writes and removes the flows compiled from one application's schedules.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
class ScheduleFlowStore implements ScheduleFlowStoreInterface {
	/**
	 * More schedules than one manifest declares.
	 */
	private const PAGE = 1000;

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface $mapper The flow table.
	 * @param FlowVersionServiceInterface $versions Publishes a flow's head.
	 * @param FlowServiceInterface $flows Deletes a flow with its runs and versions.
	 * @param FlowTriggerValidatorInterface $validator Validates the trigger under the publisher's session.
	 */
	public function __construct(
		private readonly FlowMapperInterface $mapper,
		private readonly FlowVersionServiceInterface $versions,
		private readonly FlowServiceInterface $flows,
		private readonly FlowTriggerValidatorInterface $validator,
	) {
	}//end __construct()

	/**
	 * The compiled flows of an application, by uuid.
	 *
	 * @param string $application The application uuid, which the flows carry as their `applicationSlug`.
	 *
	 * @return array<string, Flow> The flows. A flow an author drew is never among them.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function ofApplication(string $application): array {
		$flows = [];
		$found = $this->mapper->findAllFlows(
			app: Flow::APP_MANIFEST_SCHEDULE,
			applicationSlug: $application,
			limit: self::PAGE
		);
		foreach ($found as $flow) {
			$flows[(string)$flow->getUuid()] = $flow;
		}

		return $flows;
	}//end ofApplication()

	/**
	 * Whether any compiled flow carries the name of an application with this slug.
	 *
	 * For reporting only: it says nothing about which organisation published.
	 *
	 * @param string $slug The application slug.
	 *
	 * @return bool True when one does.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function hasFlowsNamedFor(string $slug): bool {
		foreach ($this->mapper->findAllFlows(app: Flow::APP_MANIFEST_SCHEDULE, limit: self::PAGE) as $flow) {
			if (str_starts_with((string)$flow->getName(), $slug . ': ') === true) {
				return true;
			}
		}

		return false;
	}//end hasFlowsNamedFor()

	/**
	 * Write a compiled flow, creating it or updating the stored one.
	 *
	 * @param CompiledScheduleFlow $compiled The flow.
	 * @param Flow|null $stored The stored flow, or null to create.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the trigger rejects its config.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function write(CompiledScheduleFlow $compiled, ?Flow $stored): void {
		if ($stored === null) {
			$this->create(compiled: $compiled);
			return;
		}

		if ($compiled->graphMatches(flow: $stored) === true) {
			$compiled->applyTo(flow: $stored);
			$this->mapper->update($stored);
			return;
		}

		$this->republish(compiled: $compiled, stored: $stored);
	}//end write()

	/**
	 * Remove a compiled flow with its runs and versions.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function remove(Flow $flow): void {
		$this->flows->deleteStored(flow: $flow);
	}//end remove()

	/**
	 * Insert a compiled flow and publish its first version.
	 *
	 * @param CompiledScheduleFlow $compiled The flow.
	 *
	 * @return void
	 */
	private function create(CompiledScheduleFlow $compiled): void {
		$flow = new Flow();
		$flow->setUuid($compiled->uuid());
		$flow->setCreated(new DateTime());
		$compiled->applyTo(flow: $flow);
		$this->validator->validate(flow: $flow);

		$stored = $this->mapper->insert($flow);
		$this->versions->publish(flow: $stored, options: new FlowPublishOptions(publishedBy: $compiled->release->publisher));
	}//end create()

	/**
	 * Write a changed graph as the flow's next published version.
	 *
	 * @param CompiledScheduleFlow $compiled The flow.
	 * @param Flow $stored The stored flow.
	 *
	 * @return void
	 */
	private function republish(CompiledScheduleFlow $compiled, Flow $stored): void {
		if ($stored->getLifecycleStatus() === FlowVersion::STATUS_PUBLISHED) {
			$this->versions->createDraft(flow: $stored);
		}

		$compiled->applyTo(flow: $stored);
		$this->validator->validate(flow: $stored);
		$this->mapper->update($stored);
		$this->versions->publish(flow: $stored, options: new FlowPublishOptions(publishedBy: $compiled->release->publisher));
	}//end republish()
}//end class
