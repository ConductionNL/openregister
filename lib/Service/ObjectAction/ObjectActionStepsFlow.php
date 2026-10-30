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
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
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
	 * The name prefix of a stored steps flow.
	 *
	 * @var string
	 */
	public const NAME_PREFIX = 'action:';

	/**
	 * Constructor.
	 *
	 * @param FlowNodeRegistry      $registry The node types steps may name.
	 * @param FlowDefinitionBuilder $builder  Lowers steps to a flow document.
	 * @param FlowMapper            $flows    Finds and stores the lowered flow.
	 * @param FlowVersionService    $versions Publishes the lowered flow.
	 */
	public function __construct(
		private readonly FlowNodeRegistry $registry,
		private readonly FlowDefinitionBuilder $builder,
		private readonly FlowMapper $flows,
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
			if (is_array($steps) === false) {
				continue;
			}

			foreach (array_values($steps) as $index => $step) {
				$refusal = $this->stepRefusal(key: $key, position: ($index + 1), step: (array)$step);
				if ($refusal !== null) {
					$refusals[] = $refusal;
				}
			}
		}

		return $refusals;
	}//end refusals()

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
		$document = $this->builder->lowerSteps(steps: (array)$definition->body, next: $definition->next);
		$name = self::NAME_PREFIX . $definition->key . ':' . substr(hash('sha256', (string)json_encode($document)), 0, 16);
		$schemaSlug = (string)$schema->getSlug();

		$stored = $this->flows->findDeclared(name: $name, schemaSlug: $schemaSlug);
		if ($stored !== null && (string)$stored->getLifecycleStatus() === FlowVersion::STATUS_PUBLISHED) {
			return (string)$stored->getUuid();
		}

		try {
			$stored ??= $this->flows->insert($this->newFlow(name: $name, schemaSlug: $schemaSlug, document: $document));
			$this->versions->publish(flow: $stored, publishedBy: null);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('The steps of action "%s" could not be stored as a flow: %s', $definition->key, $e->getMessage()),
				0,
				$e
			);
		}

		return (string)$stored->getUuid();
	}//end flowFor()

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

		if (in_array($type, self::REFUSED_TYPES, true) === true
			|| $this->registry->isTrigger(type: $type) === true
			|| $this->registry->isEnd(type: $type) === true
		) {
			return sprintf(
				'Action "%s": step %d (%s) branches, waits or bounds the flow, which "steps" cannot; declare a flow and bind it with "flow".',
				$key,
				$position,
				$type
			);
		}

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
	}//end stepRefusal()

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
		$flow->setEnabled(false);
		$flow->setOwner(null);
		$flow->setCreated(new DateTime());
		$flow->setUpdated(new DateTime());

		return $flow;
	}//end newFlow()
}//end class
