<?php

/**
 * Inline `steps` as a stored flow: checked at schema save, stored once per content.
 *
 * There is no second executor (ADR-065). An action's `steps` are lowered by
 * {@see FlowDefinitionBuilder::lowerSteps()} into an ordinary flow document,
 * stored once per content hash, published, and run by the one engine, so a
 * run of a steps action is a run like any other and can be opened at
 * `/api/flow-runs/{uuid}`.
 *
 * The stored flow is the system's, not a person's: it is disabled (no trigger
 * ever fires it) and ownerless (nobody adopted it). Only the action path runs
 * it, after the action's own right has been checked, and the run executes as
 * the person who pressed the button.
 *
 * `steps` is a straight line that never suspends, so a sync answer is always a
 * finished answer. A node that branches or waits belongs in a stored flow bound
 * with `flow`, and the save refusal says so.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowNodeCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Validates, lowers and stores the inline steps of declared actions.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() mints the flow's identity, as everywhere else.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
 */
class ObjectActionStepsFlow {

	/**
	 * Node types that branch or suspend, and so do not belong in `steps`.
	 *
	 * @var list<string>
	 */
	public const REFUSED_TYPES = [
		'openregister.switch',
		'openregister.route',
		'openregister.wait',
		'openregister.await-signal',
		'openregister.user-task',
		'openregister.portal-task',
		'openregister.sub-flow',
		'openregister.batch',
	];

	/**
	 * The refusal of a step that branches, waits or bounds the flow: action, position, type.
	 *
	 * @var string
	 */
	private const BENDS_THE_LINE = 'Action "%s": step %d (%s) branches, waits or bounds the flow, which "steps" cannot;'
		. ' declare a flow and bind it with "flow".';

	/**
	 * The name prefix of a stored steps flow.
	 *
	 * @var string
	 */
	public const NAME_PREFIX = 'action:';

	/**
	 * Constructor.
	 *
	 * @param FlowNodeCatalogueInterface $registry The node types steps may name.
	 * @param FlowDefinitionBuilder      $builder  Lowers steps to a flow document.
	 * @param FlowMapperInterface        $flows    Finds and stores the lowered flow.
	 * @param FlowVersionService         $versions Publishes the lowered flow.
	 */
	public function __construct(
		private readonly FlowNodeCatalogueInterface $registry,
		private readonly FlowDefinitionBuilder $builder,
		private readonly FlowMapperInterface $flows,
		private readonly FlowVersionService $versions,
	) {
	}//end __construct()

	/**
	 * Why the steps of the schema's declared actions may not be saved.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return list<string> The refusals, each naming the action and the step.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
	 */
	public function refusals(array $configuration): array {
		$refusals = [];
		foreach (ObjectActionDefinition::declarations(configuration: $configuration) as $key => $definition) {
			$steps = ($definition[ObjectActionDefinition::KIND_STEPS] ?? null);
			if (is_array($steps) === true) {
				$refusals = array_merge($refusals, $this->stepsRefusals(key: $key, steps: $steps));
			}
		}

		return $refusals;
	}//end refusals()

	/**
	 * The refusals the steps of one action earn, in step order.
	 *
	 * @param string       $key   The action key.
	 * @param array<mixed> $steps The declared steps.
	 *
	 * @return list<string> The refusals.
	 */
	private function stepsRefusals(string $key, array $steps): array {
		$refusals = [];
		foreach (array_values($steps) as $index => $step) {
			$refusal = $this->stepRefusal(key: $key, position: ($index + 1), step: (array)$step);
			if ($refusal !== null) {
				$refusals[] = $refusal;
			}
		}

		return $refusals;
	}//end stepsRefusals()

	/**
	 * The stored flow a steps action runs, made and published on first use.
	 *
	 * Identity is the declaring schema plus a hash of the lowered document, so
	 * changing the steps makes a new flow and leaves every earlier run pointing
	 * at the graph it actually walked.
	 *
	 * @param Schema                 $schema     The schema whose action this is.
	 * @param ObjectActionDefinition $definition The steps action.
	 *
	 * @return string The flow's uuid.
	 *
	 * @throws RuntimeException When the flow cannot be stored or published.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
	 */
	public function flowFor(Schema $schema, ObjectActionDefinition $definition): string {
		$document   = $this->builder->lowerSteps(steps: (array)$definition->body, next: $definition->next);
		$name       = self::NAME_PREFIX . $definition->key . ':' . substr(hash('sha256', (string)json_encode($document)), 0, 16);
		$schemaSlug = (string)$schema->getSlug();

		$stored = $this->flows->findDeclared(name: $name, schemaSlug: $schemaSlug);
		if ($stored !== null && (string)$stored->getLifecycleStatus() === FlowVersion::STATUS_PUBLISHED) {
			return (string)$stored->getUuid();
		}

		try {
			$stored ??= $this->flows->insert($this->newFlow(name: $name, schemaSlug: $schemaSlug, document: $document));

			return $this->publish(flow: $stored);
		} catch (Throwable $e) {
			throw $this->notStored(key: $definition->key, previous: $e);
		}
	}//end flowFor()

	/**
	 * The error for steps that could not be stored or published as a flow.
	 *
	 * @param string    $key      The action key.
	 * @param Throwable $previous What went wrong.
	 *
	 * @return RuntimeException The error.
	 */
	private function notStored(string $key, Throwable $previous): RuntimeException {
		return new RuntimeException(
			sprintf('The steps of action "%s" could not be stored as a flow: %s', $key, $previous->getMessage()),
			0,
			$previous
		);
	}//end notStored()

	/**
	 * Publish a stored flow as the system.
	 *
	 * @param Flow $flow The stored flow.
	 *
	 * @return string The flow's uuid.
	 */
	private function publish(Flow $flow): string {
		$this->versions->publish(flow: $flow, publishedBy: null);

		return (string)$flow->getUuid();
	}//end publish()

	/**
	 * The refusal one step earns, or null.
	 *
	 * @param string               $key      The action key.
	 * @param int                  $position The step's position, from 1.
	 * @param array<string, mixed> $step     The step.
	 *
	 * @return string|null The refusal.
	 */
	private function stepRefusal(string $key, int $position, array $step): ?string {
		$type = trim((string)($step['type'] ?? ''));
		if ($type === '') {
			return null;
		}

		$step['type'] = $type;
		if ($this->bendsTheLine(type: $type) === true) {
			return sprintf(self::BENDS_THE_LINE, $key, $position, $type);
		}

		return $this->nodeRefusal(key: $key, position: $position, step: $step);
	}//end stepRefusal()

	/**
	 * The refusal a step earns from the node it names: unknown, or refusing its config.
	 *
	 * @param string               $key      The action key.
	 * @param int                  $position The step's position, from 1.
	 * @param array<string, mixed> $step     The step, its `type` trimmed.
	 *
	 * @return string|null The refusal.
	 */
	private function nodeRefusal(string $key, int $position, array $step): ?string {
		$type = $step['type'];
		try {
			$node = $this->registry->get(type: $type);
		} catch (Throwable) {
			return sprintf('Action "%s": step %d names node "%s", which no installed app provides.', $key, $position, $type);
		}

		try {
			$node->validateConfig((array)($step['config'] ?? []));
		} catch (Throwable $e) {
			return sprintf('Action "%s": step %d (%s) is misconfigured: %s', $key, $position, $type, $e->getMessage());
		}

		return null;
	}//end nodeRefusal()

	/**
	 * Whether a node type branches, waits, triggers or ends, which a straight line of steps cannot.
	 *
	 * @param string $type The node type.
	 *
	 * @return bool True when the type is refused in steps.
	 */
	private function bendsTheLine(string $type): bool {
		return in_array($type, self::REFUSED_TYPES, true) === true
			|| $this->registry->isTrigger(type: $type) === true
			|| $this->registry->isEnd(type: $type) === true;
	}//end bendsTheLine()

	/**
	 * A new, inert, system-held flow for a lowered document.
	 *
	 * @param string                                                                  $name       The flow name.
	 * @param string                                                                  $schemaSlug The declaring schema.
	 * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, string>>} $document   The lowered document.
	 *
	 * @return Flow The unsaved flow.
	 */
	private function newFlow(string $name, string $schemaSlug, array $document): Flow {
		$flow = new Flow();
		$flow->setUuid((string)Uuid::v4());
		$flow->setApp('openregister');
		$flow->setName($name);
		$flow->setDescription('Inline steps of a declared action; run only through that action.');
		$flow->setTriggerSchema($schemaSlug);
		$flow->setExecutionMode(Flow::MODE_SYNC);
		$flow->setNodes($document['nodes']);
		$flow->setEdges($document['edges']);

		return $this->inert(flow: $flow);
	}//end newFlow()

	/**
	 * Mark a new flow disabled and unowned, so only its action runs it, and stamp it.
	 *
	 * @param Flow $flow The unsaved flow.
	 *
	 * @return Flow The same flow.
	 */
	private function inert(Flow $flow): Flow {
		$flow->setEnabled(false);
		$flow->setOwner(null);
		$flow->setCreated(new DateTime());
		$flow->setUpdated(new DateTime());

		return $flow;
	}//end inert()
}//end class
