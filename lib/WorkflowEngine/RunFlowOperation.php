<?php

/**
 * "Run an OpenRegister flow" — a native Nextcloud Flow operation.
 *
 * The second half of the two-way composition with the visual flow builder:
 * this registers OpenRegister flows as an operation *in* native Nextcloud Flow.
 * An administrator builds a Flow rule on an OpenRegister object event, adds the
 * engine's rich checks (e.g. "confidentiality is high", "register is X"), and
 * selects this operation with the name of a flow declared on the object's
 * schema. When the rule matches, the named flow's actions run against the
 * object.
 *
 * This is deliberately distinct from the always-on
 * {@see \OCA\OpenRegister\Service\Flow\FlowTriggerService}, which queues a
 * run of every flow whose trigger matches, unconditionally. Routing a flow through Nextcloud
 * Flow gates it behind the engine's check system, so the two systems compose
 * rather than duplicate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category WorkflowEngine
 * @package  OCA\OpenRegister\WorkflowEngine
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/visual-flow-builder/specs/integration-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;

/**
 * Runs a named OpenRegister flow when a matching Nextcloud Flow rule fires.
 */
class RunFlowOperation implements RunFlowOperationInterface {
	/**
	 * Constructor.
	 *
	 * @param FlowNodePresentation  $presentation Translations for the labels and the refusal, and the icon path.
	 * @param RunFlowOperationFlows $flowRuns     Resolves a flow by name and queues its run.
	 * @param LoggerInterface       $logger       Logs skipped/failed invocations.
	 */
	public function __construct(
		private readonly FlowNodePresentation $presentation,
		private readonly RunFlowOperationFlows $flowRuns,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Operation label in the Flow rule builder.
	 *
	 * @return string
	 */
	public function getDisplayName(): string {
		return $this->presentation->l10n->t('Run an OpenRegister flow');
	}//end getDisplayName()

	/**
	 * Operation description.
	 *
	 * @return string
	 */
	public function getDescription(): string {
		return $this->presentation->l10n->t('Run a flow declared on the object\'s schema (by name) when this rule matches.');
	}//end getDescription()

	/**
	 * Operation icon.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return $this->presentation->urls->imagePath('core', 'actions/play.svg');
	}//end getIcon()

	/**
	 * The entity this operation is scoped to (OpenRegister objects only).
	 *
	 * @return string
	 */
	public function getEntityId(): string {
		return RegisterObjectEntity::class;
	}//end getEntityId()

	/**
	 * Available at both admin and user rule scope.
	 *
	 * @param int $scope One of IManager::SCOPE_*.
	 *
	 * @return bool
	 */
	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN || $scope === IManager::SCOPE_USER;
	}//end isAvailableForScope()

	/**
	 * Validate the configured operation value (a non-empty flow name).
	 *
	 * @param string $name Rule name.
	 * @param array<int, mixed> $checks Configured checks.
	 * @param string $operation The flow name to run.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no flow name is provided.
	 */
	public function validateOperation(string $name, array $checks, string $operation): void {
		if (trim($operation) === '') {
			throw new UnexpectedValueException($this->presentation->l10n->t('Please provide the name of the OpenRegister flow to run.'));
		}
	}//end validateOperation()

	/**
	 * Run the configured flow(s) for the object the event carries.
	 *
	 * @param string $eventName The dispatched event class name.
	 * @param Event $event The dispatched event.
	 * @param IRuleMatcher $ruleMatcher Matcher exposing the configured operations.
	 *
	 * @return void
	 */
	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		$object = (new LifecycleEventObject())->of(event: $event);
		if ($object !== null) {
			$this->runMatchedFlows(ruleMatcher: $ruleMatcher, object: $object);
		}
	}//end onEvent()

	/**
	 * Run every flow the matched rules name for this object.
	 *
	 * @param IRuleMatcher $ruleMatcher Matcher exposing the configured operations.
	 * @param ObjectEntity $object      The object the event carried.
	 *
	 * @return void
	 */
	private function runMatchedFlows(IRuleMatcher $ruleMatcher, ObjectEntity $object): void {
		try {
			$flows = $ruleMatcher->getFlows(false);
		} catch (\Throwable $e) {
			$this->logger->debug('RunFlowOperation: no matching flows: ' . $e->getMessage());
			return;
		}

		foreach ($flows as $flow) {
			$flowName = trim((string)($flow['operation'] ?? ''));
			if ($flowName !== '') {
				$this->runFlowNamed(name: $flowName, object: $object);
			}
		}
	}//end runMatchedFlows()

	/**
	 * Queue an engine run of the flow with this name.
	 *
	 * A Flow rule names a flow by its LABEL, because that is what an
	 * administrator picked in the rule builder — not a uuid. Resolution is
	 * therefore by name, and an ambiguous name is refused rather than guessed
	 * at: two flows sharing a label is a configuration mistake, and picking one
	 * would make the rule silently run the wrong graph.
	 *
	 * The run is QUEUED, not executed. A Flow rule fires inside the dispatch of
	 * whatever matched it, and an arbitrary graph must not sit on that path.
	 *
	 * @param string $name The flow's name, as configured on the rule.
	 * @param ObjectEntity $object The object the event carried.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function runFlowNamed(string $name, ObjectEntity $object): void {
		try {
			$matches = array_values(
				array_filter(
					$this->flowRuns->flows->findAllFlows(query: new ListQuery(limit: 500)),
					static fn ($f): bool => $f->getName() === $name
				)
			);
		} catch (\Throwable $e) {
			$this->logger->warning('RunFlowOperation: could not resolve flow "' . $name . '": ' . $e->getMessage());
			return;
		}

		if (count($matches) !== 1) {
			$this->logger->warning(
				'RunFlowOperation: flow "' . $name . '" resolved to ' . count($matches)
				. ' flows; refusing to guess which one the rule meant.'
			);
			return;
		}

		$flow = $matches[0];
		if ($flow->canDispatch() === false) {
			$this->logger->warning(
				'RunFlowOperation: flow "' . $name . '" is disabled or has no owner, so it was not dispatched.'
			);
			return;
		}

		try {
			$this->flowRuns->runner->queueRun(
				flowId: (string)$flow->getUuid(),
				options: new FlowRunQueueOptions(
					subject: [
						'uuid' => $object->getUuid(),
						'register' => (string)$object->getRegister(),
						'schema' => (string)$object->getSchema(),
					],
					trigger: 'nextcloud-flow',
					user: $flow->getOwner()
				)
			);
		} catch (\Throwable $e) {
			$this->logger->warning('RunFlowOperation: could not queue flow "' . $name . '": ' . $e->getMessage());
		}

	}//end runFlowNamed()
}//end class
