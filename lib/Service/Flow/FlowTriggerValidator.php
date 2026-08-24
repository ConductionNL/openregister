<?php

/**
 * Validates a flow's TRIGGER nodes before the flow is written.
 *
 * 🔴 THIS CLOSES AN ORPHANED CAPABILITY, NOT A NEW RULE.
 *
 * Every trigger node has implemented `validateConfig()` since it was written.
 * `TriggerScheduleNode` refuses a missing or malformed cron expression;
 * `TriggerObjectNode` refuses a trigger with no subject. Nothing ever called
 * either on the save path: {@see FlowNodePreflight} invokes `validateConfig()`
 * only for STEPS, reading `$edge['config']`, and a trigger is not a step.
 *
 * Measured on the dev instance 2026-08-24: a schedule trigger posted with
 * `config: {}` — no cron, no identity — was stored with HTTP 201, and all three
 * live schedule-triggered flows carry exactly that shape. The validator was
 * written, unit-tested and unreachable, so the defect it existed to prevent
 * happened anyway. A test that calls `validateConfig()` directly passes while
 * the behaviour is absent from every real request.
 *
 * WHY TRIGGERS AND NOT EVERY NODE
 *
 * `flow-engine` requires that saving a half-wired flow SUCCEEDS and warns: an
 * editor cannot demand authors build a graph in an order that is never
 * disconnected. That rule is about WIRING, and an unconnected node mid-authoring
 * is normal.
 *
 * A trigger's own required fields are a different thing. A schedule with no cron
 * never fires; a schedule with no `runAs` has no identity to fire as. Neither is
 * a stage of authoring — both are a flow that is finished and broken, and both
 * fail silently at a time and place far from the author. So connectivity keeps
 * warning, and a trigger's own vocabulary is enforced here, before the write.
 *
 * Split out of {@see FlowService} because that class was already at its
 * complexity ceiling — the same reason the other `Flow*` collaborators in this
 * directory exist.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Asks every trigger node in a flow whether it accepts its own config.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowTriggerValidator {

	/**
	 * Constructor.
	 *
	 * The registry is resolved lazily through the container rather than injected,
	 * matching the other collaborators here: this runs on a request path that
	 * already holds the container, and several tests build the surrounding
	 * service by hand.
	 *
	 * @param ContainerInterface $container Resolves the node registry.
	 * @param LoggerInterface    $logger    Records a registry that would not build.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Let every resolvable trigger node reject its own config.
	 *
	 * Node types this instance cannot resolve are SKIPPED, not refused: a leaf
	 * app's trigger is not OpenRegister's to validate, and guessing would reject
	 * correct flows authored against a fuller instance.
	 *
	 * @param Flow $flow The flow about to be written.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When a trigger node rejects its config.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function validate(Flow $flow): void {
		$nodes = ($flow->getNodes() ?? []);
		if (is_array($nodes) === false) {
			return;
		}

		$registry = $this->registry();
		if ($registry === null) {
			return;
		}

		foreach ($nodes as $node) {
			$this->validateNode(registry: $registry, node: $node);
		}

	}//end validate()

	/**
	 * Ask one node to reject its config, when it is a trigger this instance knows.
	 *
	 * @param object $registry The node registry.
	 * @param mixed  $node     One entry from the flow's node list.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the node rejects its config.
	 */
	private function validateNode(object $registry, mixed $node): void {
		if (is_array($node) === false) {
			return;
		}

		$type = trim((string)($node['type'] ?? ''));
		if ($type === '') {
			return;
		}

		try {
			$resolved = $registry->get($type);
		} catch (UnexpectedValueException $e) {
			// An unknown node type is not this class's verdict to give. It is a
			// leaf app's node that is not installed here, or a typo the preflight
			// already reports — either way refusing the save would make this
			// instance unable to store a flow authored against a fuller one.
			return;
		}

		if (($resolved instanceof IFlowTriggerNode) === false) {
			return;
		}

		$config = ($node['config'] ?? []);
		if (is_array($config) === false) {
			$config = [];
		}

		// The node's own message goes through unchanged: it names the key and
		// says why, which is the point of asking the node rather than
		// re-implementing its rules out here.
		$resolved->validateConfig($config);

	}//end validateNode()

	/**
	 * The node registry, or null when it cannot be built.
	 *
	 * A resolution failure is not a validation verdict. Refusing every save
	 * because the registry would not build turns an infrastructure fault into
	 * data loss for whoever was editing.
	 *
	 * @return object|null The registry, or null.
	 */
	private function registry(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\Flow\FlowNodeRegistry');
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowTriggerValidator] Could not resolve the node registry: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return null;
		}
	}//end registry()
}//end class
