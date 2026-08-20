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
 * {@see \OCA\OpenRegister\Listener\FlowActionListener}, which runs every flow
 * whose trigger matches unconditionally. Routing a flow through Nextcloud Flow
 * gates it behind the engine's check system, so the two systems compose rather
 * than duplicate.
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

use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Runs a named OpenRegister flow when a matching Nextcloud Flow rule fires.
 */
class RunFlowOperation implements ISpecificOperation {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator Icon path resolver.
	 * @param FlowMapper $flows Resolves a flow by the name the rule configured.
	 * @param FlowRunService $runner Queues the run.
	 * @param LoggerInterface $logger Logs skipped/failed invocations.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly FlowMapper $flows,
		private readonly FlowRunService $runner,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Operation label in the Flow rule builder.
	 *
	 * @return string
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Run an OpenRegister flow');
	}//end getDisplayName()

	/**
	 * Operation description.
	 *
	 * @return string
	 */
	public function getDescription(): string {
		return $this->l10n->t('Run a flow declared on the object\'s schema (by name) when this rule matches.');
	}//end getDescription()

	/**
	 * Operation icon.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/play.svg');
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
			throw new UnexpectedValueException($this->l10n->t('Please provide the name of the OpenRegister flow to run.'));
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
		$object = $this->objectFromEvent(event: $event);
		if ($object === null) {
			return;
		}

		try {
			$flows = $ruleMatcher->getFlows(false);
		} catch (\Throwable $e) {
			$this->logger->debug('RunFlowOperation: no matching flows: ' . $e->getMessage());
			return;
		}

		foreach ($flows as $flow) {
			$flowName = trim((string)($flow['operation'] ?? ''));
			if ($flowName === '') {
				continue;
			}

			$this->runFlowNamed(name: $flowName, object: $object);
		}
	}//end onEvent()

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
					$this->flows->findAllFlows(limit: 500),
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
			$this->runner->queue(
				flowId: (string)$flow->getUuid(),
				subject: [
					'uuid' => $object->getUuid(),
					'register' => (string)$object->getRegister(),
					'schema' => (string)$object->getSchema(),
				],
				trigger: 'nextcloud-flow',
				user: $flow->getOwner()
			);
		} catch (\Throwable $e) {
			$this->logger->warning('RunFlowOperation: could not queue flow "' . $name . '": ' . $e->getMessage());
		}

	}//end runFlowNamed()

	/**
	 * Resolve the OpenRegister object from a lifecycle event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return ObjectEntity|null
	 */
	private function objectFromEvent(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatedEvent
			|| $event instanceof ObjectUpdatedEvent
			|| $event instanceof ObjectDeletedEvent
		) {
			return $event->getObject();
		}

		return null;
	}//end objectFromEvent()
}//end class
