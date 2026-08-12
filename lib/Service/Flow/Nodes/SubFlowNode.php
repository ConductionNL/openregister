<?php

/**
 * Runs another flow as one step of this one.
 *
 * The equivalent of n8n's "Execute Sub-workflow" node, with the same two shapes:
 *  - Wait (the default): run the named flow now, seeded with this step's items,
 *    and bring its output items back — the sub-flow behaves like a function the
 *    parent calls and reads the result of.
 *  - Fire-and-forget: queue the named flow against the run's subject and carry
 *    on, so a long or independent flow does not sit on the parent's path.
 *
 * A flow is data, so "which flow" is just an id the author picks — the same
 * resolver the trigger side uses turns it into a document. That means a
 * sub-flow can live in any consuming app: openconnector calls a hermiq
 * agentflow, procest calls an openconnector flow, all through this one node.
 *
 * Because a flow can call a flow, it can call itself — directly or round a
 * cycle. The run carries the stack of flow ids it is inside; a sub-flow already
 * on that stack is refused, and a depth ceiling backstops a chain that grows
 * without repeating. Neither is a business rule: both are guards against a graph
 * that would never settle.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-sub-flow/specs/flow-sub-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowToken;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * Executes a named flow as a step, optionally waiting for its result.
 */
class SubFlowNode implements IFlowNode, IFlowNodeConfigKeys {

	/**
	 * How deep a chain of sub-flows may go before it is refused.
	 *
	 * A flow calling a flow calling a flow is legitimate; a chain hundreds deep
	 * is a runaway. This is the backstop for a chain that grows without ever
	 * repeating an id (which the stack check would otherwise catch).
	 *
	 * @var integer
	 */
	private const MAX_DEPTH = 16;

	/**
	 * The context key holding the stack of flow ids the run is inside.
	 *
	 * @var string
	 */
	private const STACK_KEY = 'flowStack';

	/**
	 * Constructor.
	 *
	 * @param FlowLocator $resolvers Turns a flow id into a document.
	 * @param FlowRunService $runs Queues and executes the sub-run.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly FlowLocator $resolvers,
		private readonly FlowRunService $runs,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'openregister.sub-flow';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Run a flow');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Run another flow as a step — wait for its result, or start it and carry on.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/external.svg');
	}//end getIcon()

	/**
	 * Running a sub-flow grants no privilege of its own; the sub-run enforces
	 * whatever its own steps require.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a sub-flow step.
	 *
	 * `input` and `output` are NOT here, and their absence is the point. This
	 * node hands the child its items whole and returns the child's items
	 * whole; there is no mapping layer to configure. A step declaring them
	 * required only `flow`, so it saved, ran, and the author's intended
	 * mapping simply never happened — measured, in hydra#489.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['flow', 'flowId', 'wait'];
	}//end configKeys()

	/**
	 * Reject a sub-flow step that names no flow.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no flow is named.
	 */
	public function validateConfig(array $config): void {
		if ($this->flowIdFrom(config: $config) === '') {
			throw new UnexpectedValueException($this->l10n->t('A sub-flow step needs a flow to run.'));
		}

	}//end validateConfig()

	/**
	 * Run (or queue) the named flow.
	 *
	 * With `wait` (the default) the sub-flow runs now, seeded with these items,
	 * and its output items become this step's output — a terminal sub-run that
	 * did not complete cleanly raises, so the parent's `onError` policy decides
	 * what happens. Without `wait` the sub-flow is queued against the run's
	 * subject and these items pass through untouched.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata (carries the sub-flow stack).
	 *
	 * @return array The sub-flow's output items, or the input items when not waiting.
	 *
	 * @throws UnexpectedValueException When the named flow cannot be resolved,
	 *                                  or a sub-flow cycle or depth limit is hit.
	 * @throws RuntimeException When a waited-on sub-run does not complete.
	 */
	public function execute(array $items, array $config, array $context): array {
		$flowId = $this->flowIdFrom(config: $config);
		if ($flowId === '') {
			return $items;
		}

		$stack = $this->guardRecursion(flowId: $flowId, context: $context);

		$flow = $this->resolvers->resolveFlow(flowId: $flowId);
		if ($flow === null) {
			throw new UnexpectedValueException(
				$this->l10n->t('The sub-flow "%s" could not be found.', [$flowId])
			);
		}

		$subject = $this->subjectDescriptor(context: $context);
		$childCtx = $context;
		$childCtx[self::STACK_KEY] = $stack;

		// The child gets its OWN token, seeded with the parent's values rather
		// than the parent's instance. Sharing one instance would let a
		// fire-and-forget child write into a parent that has already moved on,
		// and nothing orders those two writes. Seeding as plain values is also
		// what the child's run persists and rehydrates, so the child sees a
		// token identical in shape to any other run's.
		$parentToken = ($context[FlowToken::CONTEXT_KEY] ?? null);
		if ($parentToken instanceof FlowToken === false) {
			$parentToken = FlowToken::fromArray($parentToken);
		}

		$childCtx[FlowToken::CONTEXT_KEY] = $parentToken->all();

		// Fire-and-forget: queue against the subject and carry on. The queued
		// run starts from its subject, not from these items — an independent
		// flow, kicked off, not a function whose result we read.
		if (($config['wait'] ?? true) !== true) {
			$this->runs->queue(
				flowId: $flowId,
				subject: $subject,
				trigger: 'sub-flow',
				context: $childCtx,
				user: ($context['triggeredBy'] ?? null)
			);

			return $items;
		}

		// Wait: run the sub-flow now, seeded with these items, and return what
		// it produced. It gets its own persisted run and trace, so a sub-flow
		// is as inspectable as a top-level one.
		$run = $this->runs->queue(
			flowId: $flowId,
			subject: $subject,
			trigger: 'sub-flow',
			context: $childCtx,
			user: ($context['triggeredBy'] ?? null)
		);

		$run = $this->runs->execute(
			run: $run,
			flow: $flow,
			subject: new stdClass(),
			seedItems: $items
		);

		// The child ran to a stop; hand what it gathered back to the parent.
		// `$parentToken` is the parent's own instance, so merging here is what
		// the parent's later steps read. The child wins on a conflicting key —
		// it ran later and is the more specific writer.
		$parentToken->merge((array)(($run->getContext() ?? [])[FlowToken::CONTEXT_KEY] ?? []));

		return $this->itemsFrom(run: $run, flowId: $flowId);
	}//end execute()

	/**
	 * Refuse a sub-flow that would recurse, and return the stack it should push.
	 *
	 * @param string $flowId The sub-flow's id.
	 * @param array $context The run context.
	 *
	 * @return array<int, string> The stack to hand the sub-run (this id pushed).
	 *
	 * @throws UnexpectedValueException When the id is already on the stack, or
	 *                                  the stack is at the depth ceiling.
	 */
	private function guardRecursion(string $flowId, array $context): array {
		$stack = array_values((array)($context[self::STACK_KEY] ?? []));

		if (in_array($flowId, $stack, true) === true) {
			throw new UnexpectedValueException(
				$this->l10n->t('The flow "%s" is already running as a parent of this step; a flow cannot call itself.', [$flowId])
			);
		}

		if (count($stack) >= self::MAX_DEPTH) {
			throw new UnexpectedValueException(
				$this->l10n->t('Sub-flows are nested too deeply (more than %s levels).', [(string)self::MAX_DEPTH])
			);
		}

		$stack[] = $flowId;

		return $stack;
	}//end guardRecursion()

	/**
	 * Read the sub-run's output items, raising when it did not complete cleanly.
	 *
	 * A completed sub-run hands its items back to the parent. A stopped, failed
	 * or dead-lettered one raises, so it reaches the parent as a step failure
	 * and is handled by the parent step's `onError` policy — exactly as if the
	 * failing work had been inline. A suspended sub-run is treated the same: a
	 * synchronous caller cannot wait indefinitely for a paused branch.
	 *
	 * @param FlowRun $run The finished sub-run.
	 * @param string $flowId The sub-flow id (for the message).
	 *
	 * @return array<int, array> The sub-run's output items.
	 *
	 * @throws RuntimeException When the sub-run did not complete.
	 */
	private function itemsFrom(FlowRun $run, string $flowId): array {
		// `stopped` is a SUCCESS terminal state, not a failure: it is what an
		// End node does — it halts the token deliberately. Accepting only
		// `completed` made a well-formed child unusable as a sub-flow, and the
		// engine REQUIRES an end node on every flow, so the two rules
		// contradicted each other: any child that satisfied the connectivity
		// check failed here with "did not complete (status: stopped)". Measured
		// on a paginated sync whose per-page child ended, correctly, at an End.
		$status = (string)$run->getStatus();
		$finished = [FlowRun::STATUS_COMPLETED, FlowRun::STATUS_STOPPED];
		if (in_array($status, $finished, true) === false) {
			$detail = '';
			if ($run->getError() !== null) {
				$detail = ': ' . $run->getError();
			}

			throw new RuntimeException(
				sprintf('Sub-flow "%s" did not complete (status: %s%s).', $flowId, $status, $detail)
			);
		}

		return FlowItems::normalise(value: ($run->getItems() ?? []));
	}//end itemsFrom()

	/**
	 * The subject descriptor to run the sub-flow against.
	 *
	 * A sub-flow is about the same object the parent run is about, so the
	 * parent's subject descriptor is carried through. Absent one (a flow with no
	 * subject), an empty descriptor is fine: a waited sub-flow is seeded with
	 * items, not the subject.
	 *
	 * @param array $context The run context.
	 *
	 * @return array<string, string|null> `{uuid, register, schema}`.
	 */
	private function subjectDescriptor(array $context): array {
		$subject = (array)($context['subject'] ?? []);

		return [
			'uuid' => ($subject['uuid'] ?? null),
			'register' => ($subject['register'] ?? null),
			'schema' => ($subject['schema'] ?? null),
		];

	}//end subjectDescriptor()

	/**
	 * Read the configured flow id, accepting `flowId` or `flow`.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string The flow id, or an empty string when none is set.
	 */
	private function flowIdFrom(array $config): string {
		return trim((string)($config['flowId'] ?? ($config['flow'] ?? '')));
	}//end flowIdFrom()
}//end class
