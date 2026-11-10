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
use OCA\OpenRegister\Service\Flow\FlowExecution;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunOrigin;
use OCA\OpenRegister\Service\Flow\FlowRunPass;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * Executes a named flow as a step, optionally waiting for its result.
 */
class SubFlowNode extends LogicNodeBase implements IFlowNode, SubFlowNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy {

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
	 * Turns a flow id into a document.
	 *
	 * @var FlowLocator
	 */
	private readonly FlowLocator $resolvers;

	/**
	 * Queues and executes the sub-run.
	 *
	 * @var FlowRunServiceInterface
	 */
	private readonly FlowRunServiceInterface $runs;

	/**
	 * Constructor.
	 *
	 * @param FlowExecution        $execution    Turns a flow id into a document; queues and executes the sub-run.
	 * @param FlowNodePresentation $presentation Translations and the palette icon.
	 */
	public function __construct(FlowExecution $execution, FlowNodePresentation $presentation) {
		$this->resolvers = $execution->resolvers;
		$this->runs      = $execution->runner;
		parent::__construct(l10n: $presentation->l10n, urls: $presentation->urls);
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
		return ['flow', 'flowId', 'wait', 'fanOut'];
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

		if (array_key_exists('fanOut', $config) === true && is_bool($config['fanOut']) === false) {
			throw new UnexpectedValueException($this->l10n->t('The "fanOut" field must be true or false.'));
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
		$target = $this->targetFor(config: $config, context: $context);
		if ($target === null) {
			return $items;
		}

		return $this->dispatch(run: new SubFlowRun(target: $target, config: $config, context: $context), items: $items);
	}//end execute()

	/**
	 * The sub-flow to call: resolved, and with this flow pushed on the recursion stack.
	 *
	 * @param array<string, mixed> $config  The step configuration.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return SubFlowTarget|null Null when the step names no flow, which passes the items through.
	 *
	 * @throws UnexpectedValueException When the flow is already a parent, nested too deeply, or cannot be resolved.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function targetFor(array $config, array $context): ?SubFlowTarget {
		$flowId = $this->flowIdFrom(config: $config);
		if ($flowId === '') {
			return null;
		}

		$stack = $this->guardRecursion(flowId: $flowId, context: $context);

		$flow = $this->resolvers->resolveFlow(flowId: $flowId);
		if ($flow === null) {
			throw new UnexpectedValueException(
				$this->l10n->t('The sub-flow "%s" could not be found.', [$flowId])
			);
		}

		return new SubFlowTarget(flow: $flow, flowId: $flowId, stack: $stack);
	}//end targetFor()

	/**
	 * Call the sub-flow once for all items, or once per item.
	 *
	 * FAN OUT: one child RUN per item, rather than one run holding every
	 * item. Without it a crawler that loops pages gets a sub-flow per PAGE —
	 * measured on the demo: 100 records paged in, and the sync sub-flow
	 * invoked 11 times, not 100. The per-record work happened inside the
	 * child because object-write iterates items itself, which is fine until
	 * you want per-record isolation, per-record retry, or a trace that shows
	 * one run per object.
	 *
	 * @param SubFlowRun $run   The step's call: target, configuration and run context.
	 * @param array      $items The input items.
	 *
	 * @return array The sub-flow's output items, or the input items when not waiting.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function dispatch(SubFlowRun $run, array $items): array {
		if ($run->fansOut() === true) {
			return $this->fanOut(run: $run, items: $items);
		}

		return $this->invoke(run: $run, items: $items);
	}//end dispatch()

	/**
	 * Run the sub-flow once per item, and return everything they produced.
	 *
	 * An item that fails takes the step down with it, exactly as a single
	 * invocation would: swallowing one child's failure would report the parent
	 * step completed while some records were silently not processed, and the
	 * count of child runs would be the only surviving evidence.
	 *
	 * @param SubFlowRun $run   The step's call: target, configuration and run context.
	 * @param array      $items The input items.
	 *
	 * @return array Everything the children produced, in item order.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function fanOut(SubFlowRun $run, array $items): array {
		$out = [];
		foreach ($items as $item) {
			$produced = $this->invoke(run: $run, items: [$item]);

			foreach ($produced as $one) {
				$out[] = $one;
			}
		}

		return $out;
	}//end fanOut()

	/**
	 * One invocation of the sub-flow with the given items.
	 *
	 * @param SubFlowRun $run   The step's call: target, configuration and run context.
	 * @param array      $items The items to seed the child with.
	 *
	 * @return array What the child produced, or the input when fire-and-forget.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function invoke(SubFlowRun $run, array $items): array {
		$child = $this->queueChild(run: $run, childContext: $this->childContext(run: $run, items: $items));

		// Fire-and-forget: the child is queued against the subject and the step
		// carries on. The queued run starts from its subject, not from these
		// items — an independent flow, kicked off, not a function whose result we read.
		if (($run->config['wait'] ?? true) !== true) {
			return $items;
		}

		// Wait: run the sub-flow now, seeded with these items, and return what
		// it produced. It gets its own persisted run and trace, so a sub-flow
		// is as inspectable as a top-level one.
		$child = $this->runs->executePass(
			pass: new FlowRunPass(run: $child, flow: $run->target->flow, subject: new stdClass()),
			origin: new FlowRunOrigin(items: $items, startAt: null)
		);

		// The child ran to a stop; hand what it gathered back to the parent.
		// The parent's own token instance is merged into, so this is what
		// the parent's later steps read. The child wins on a conflicting key —
		// it ran later and is the more specific writer.
		$run->absorb(child: $child);

		return $this->itemsFrom(run: $child, flowId: $run->target->flowId);
	}//end invoke()

	/**
	 * The context a child run starts with: the recursion stack, the parent's
	 * token as values, and — fanned out — its one record as the seed payload.
	 *
	 * @param SubFlowRun $run   The step's call: target, configuration and run context.
	 * @param array      $items The items the child is invoked for.
	 *
	 * @return array<string, mixed> The child run's context.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function childContext(SubFlowRun $run, array $items): array {
		$childCtx = $run->context;
		$childCtx[self::STACK_KEY] = $run->target->stack;

		// The child gets its OWN token, seeded with the parent's values rather
		// than the parent's instance. Sharing one instance would let a
		// fire-and-forget child write into a parent that has already moved on,
		// and nothing orders those two writes. Seeding as plain values is also
		// what the child's run persists and rehydrates, so the child sees a
		// token identical in shape to any other run's.
		$childCtx = $run->withChildToken(context: $childCtx);

		// Fanned out and NOT waiting: the queued child starts from its subject,
		// not from these items, so without this a per-object fan-out would queue
		// N identical runs that never see their object. `payload` is the seeding
		// path a subjectless run already uses, so the child reads its record as
		// the first item exactly as a waiting child would.
		if ($run->fansOut() === true && count($items) === 1) {
			$childCtx['payload'] = (array)($items[0][FlowItems::JSON] ?? []);
		}

		return $childCtx;
	}//end childContext()

	/**
	 * Queue the child run against the parent's subject.
	 *
	 * @param SubFlowRun           $run          The step's call: target and run context.
	 * @param array<string, mixed> $childContext The child run's context.
	 *
	 * @return FlowRun The queued child run.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function queueChild(SubFlowRun $run, array $childContext): FlowRun {
		return $this->runs->queueRun(
			flowId: $run->target->flowId,
			options: new FlowRunQueueOptions(
				subject: $this->subjectDescriptor(context: $run->context),
				trigger: 'sub-flow',
				context: $childContext,
				// The child inherits the parent's ACTING identity, not its
				// provenance. Identity narrows along an invocation chain and never
				// widens (ADR-099): a sub-flow may do what its caller could do, and
				// nothing more. Passing `triggeredBy` here would hand the child the
				// identity that CAUSED the parent, which for a scheduled run is a
				// different — and possibly more privileged — user than the one the
				// parent was actually executing as.
				user: ($run->context['runAs'] ?? null)
			)
		);
	}//end queueChild()

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

	/**
	 * What kind of step this is. A step that is itself a process.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SUB_PROCESS;

	}//end getKind()
}//end class
