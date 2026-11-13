<?php

/**
 * Set, rename and remove fields on every item.
 *
 * The equivalent of n8n's "Edit Fields (Set)", and the node that proves the
 * item model end to end: it is configured once and applied per item, without
 * the author drawing a loop.
 *
 * Also the reference implementation of {@see IFlowNode}. An app contributing a
 * node type has this to copy, and needs nothing else — no dispatcher, no
 * engine, no graph walking.
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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowExpressionDataOptions;
use OCA\OpenRegister\Service\Flow\FieldAssignment;
use OCA\OpenRegister\Service\Flow\FlowFieldPath;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\IFlowSideEffectFree;
use UnexpectedValueException;

/**
 * Reshapes each item's record.
 */
class SetFieldsNode extends LogicNodeBase implements IFlowNode, SetFieldsNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy, IFlowSideEffectFree {

	/**
	 * The config keys of which at least one must be non-empty.
	 *
	 * @var string[]
	 */
	private const RESHAPING_KEYS = ['set', 'compute', 'rename', 'remove'];

	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'openregister.set-fields';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Edit fields');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Set, rename or remove fields on every item passing through.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/rename.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of a set-fields step.
	 *
	 * `fields` is NOT here. It is the plausible-looking name an author reaches
	 * for, it is what `openregister.object-write` calls its payload, and
	 * hydra-analyze-verdicts shipped a step using it that set nothing at all.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['set', 'compute', 'rename', 'remove', 'keepOnlySet'];
	}//end configKeys()

	/**
	 * Reject a configuration that would do nothing.
	 *
	 * A step that silently does nothing is worse than one that refuses to
	 * save: the author believes their flow reshapes data and it does not.
	 *
	 * An empty or blank path is refused when the flow is SAVED. It can only
	 * write to a key nothing reads, and the author should not have to find
	 * that out from an item that came back looking fine.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When nothing is configured.
	 */
	public function validateConfig(array $config): void {
		$this->assertSomethingConfigured(config: $config);

		$compute = (array)($config['compute'] ?? []);
		$this->assertPathsAreNamed(bag: (array)($config['set'] ?? []), key: 'set');
		$this->assertPathsAreNamed(bag: $compute, key: 'compute');
		$this->assertExpressionsAreValid(compute: $compute);

	}//end validateConfig()

	/**
	 * Refuse a configuration in which every reshaping mapping is empty.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When nothing is configured.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertSomethingConfigured(array $config): void {
		foreach (self::RESHAPING_KEYS as $key) {
			if ((array)($config[$key] ?? []) !== []) {
				return;
			}
		}

		throw new UnexpectedValueException(
			$this->l10n->t('Choose at least one field to set, compute, rename or remove.')
		);

	}//end assertSomethingConfigured()

	/**
	 * Refuse a `compute` expression that cannot be evaluated.
	 *
	 * A malformed expression is caught HERE, when the flow is saved, rather
	 * than evaluating to null on every item at 03:00 — `FlowExpression`
	 * swallows a broken rule and returns null, which is indistinguishable
	 * from a field that legitimately resolved to nothing.
	 *
	 * @param array $compute The configured expression mapping.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When an expression is not valid.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertExpressionsAreValid(array $compute): void {
		foreach ($compute as $field => $logic) {
			if (FlowExpression::isValid(logic: $logic) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('The expression for computed field "%s" is not valid.', [(string)$field])
				);
			}
		}

	}//end assertExpressionsAreValid()

	/**
	 * Apply the configured reshaping to every item.
	 *
	 * `keepOnlySet` mirrors n8n's option of the same name: drop everything the
	 * step did not explicitly set, for building a clean payload.
	 *
	 * Provenance: each output item carries its own input's index, one in and
	 * one out, so the chain back to the input that caused it stays intact.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The output items, one per input item.
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;
		$plan = new SetFieldsPlan(config: $config, context: $context, itemCount: count($items));

		return $this->reshapeEach(plan: $plan, items: $items);
	}//end execute()

	/**
	 * Apply the plan to every item, one in and one out.
	 *
	 * @param SetFieldsPlan $plan  The step's plan for this run.
	 * @param array         $items The input items.
	 *
	 * @return array The output items, one per input item.
	 */
	private function reshapeEach(SetFieldsPlan $plan, array $items): array {
		$out = [];
		foreach ($items as $index => $item) {
			$out[] = FlowItems::item(
				json: $this->reshape(json: (array)($item[FlowItems::JSON] ?? []), plan: $plan->forItem(itemIndex: (int)$index)),
				options: new FlowItemOptions(binary: (array)($item[FlowItems::BINARY] ?? []), fromItemIndex: $index)
			);
		}

		return $out;
	}//end reshapeEach()

	/**
	 * Apply the plan to one item's record.
	 *
	 * Order is remove, then rename, then set, then compute. Rename before set
	 * so setting a field the author also renamed away is not silently undone,
	 * and remove first so a rename can legitimately reuse a removed name.
	 * Compute last so a derived value can build on one just substituted.
	 *
	 * @param array         $json The item's record.
	 * @param SetFieldsPlan $plan The step's reshaping, positioned at this item.
	 *
	 * @return array The reshaped record.
	 *
	 * @throws UnexpectedValueException When a path segment cannot be rendered.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function reshape(array $json, SetFieldsPlan $plan): array {
		foreach ($plan->remove as $field) {
			unset($json[(string)$field]);
		}

		$json = $this->applyRename(json: $json, rename: $plan->rename);
		if ($plan->keepOnlySet === true) {
			$json = [];
		}

		$this->applySet(json: $json, set: $plan->set);
		$this->applyCompute(json: $json, plan: $plan);

		return $json;
	}//end reshape()

	/**
	 * Apply the `rename` mapping to one item's record.
	 *
	 * A pair with an empty name on either side, or naming a key the record
	 * does not have, is skipped.
	 *
	 * @param array $json   The item's record.
	 * @param array $rename The configured old-to-new mapping.
	 *
	 * @return array The record with the keys renamed.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function applyRename(array $json, array $rename): array {
		foreach ($rename as $from => $to) {
			$from = (string)$from;
			$to = (string)$to;
			if ($from !== '' && $to !== '' && array_key_exists($from, $json) === true) {
				$json[$to] = $json[$from];
				unset($json[$from]);
			}
		}

		return $json;
	}//end applyRename()

	/**
	 * Refuse a configured field path that names nothing.
	 *
	 * @param array $bag The `set` or `compute` mapping.
	 * @param string $key The configuration key, for the message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a path is empty or blank.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertPathsAreNamed(array $bag, string $key): void {
		foreach (array_keys($bag) as $field) {
			if (trim((string)$field) === '') {
				throw new UnexpectedValueException(
					$this->l10n->t('A "%s" entry has an empty field path.', [$key])
				);
			}
		}

	}//end assertPathsAreNamed()

	/**
	 * Apply the `set` mapping to one item's record.
	 *
	 * Values are rendered against the item, the way every other node resolves
	 * its authored config — `source-call` its endpoint and body, `agent-step`
	 * its prompt, `object-write` its fields.
	 *
	 * This node could previously only write CONSTANTS: `{{retries}}` was
	 * stored as the literal seven characters. That makes the one node whose
	 * entire job is setting fields the only one that cannot refer to the item
	 * it is setting them on — and it is also the reference implementation
	 * other node authors copy.
	 *
	 * A value that is exactly one placeholder keeps its TYPE, so a counter
	 * stays a number and a list stays a list. The PATH is templated as well as
	 * the value. Without this the one node whose job is setting fields could
	 * refer to the item in its values but not in its positions, so no flow
	 * could write to a place the run decides — `stages.{{stage}}.status`
	 * created a key literally called `stages.{{stage}}.status`.
	 *
	 * Both halves of a pair are resolved against the record as it stands, and
	 * the record is mutated as we go, so a later entry can read what an earlier
	 * one wrote. That ordering is part of the contract.
	 *
	 * @param array $json The item's record, modified in place.
	 * @param array $set The configured field mapping.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a path segment cannot be rendered.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function applySet(array &$json, array $set): void {
		foreach ($set as $field => $value) {
			FlowFieldPath::assign(
				json: $json,
				write: new FieldAssignment(
					path: $this->renderPath(path: (string)$field, json: $json),
					value: FlowValueTemplate::render(value: $value, json: $json)
				)
			);
		}

	}//end applySet()

	/**
	 * Apply the `compute` mapping to one item's record.
	 *
	 * `compute` is the same idea as `set` for values that have to be DERIVED
	 * rather than substituted. `{{retries}}` can copy a counter; nothing could
	 * add one to it, because a template substitutes and does not calculate.
	 * That gap is what stopped hydra's retry/escalation flow: read the count,
	 * increment, store — the `+ 1` was not expressible, so the counter sat at
	 * 0 and a run "retried" forever.
	 *
	 * The expression language is the one the routers already use
	 * (`FlowExpression`), so an author who can write a branch condition can
	 * write this. Paths get the same treatment as `set`, deliberately: an
	 * author who learned the path rules from `set` must not get a silently
	 * different result from the node's other half.
	 *
	 * @param array         $json The item's record, modified in place.
	 * @param SetFieldsPlan $plan The step's reshaping, positioned at this item.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a path segment cannot be rendered.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function applyCompute(array &$json, SetFieldsPlan $plan): void {
		foreach ($plan->compute as $field => $logic) {
			FlowFieldPath::assign(
				json: $json,
				write: new FieldAssignment(
					path: $this->renderPath(path: (string)$field, json: $json),
					value: FlowExpression::evaluate(logic: $logic, data: $this->expressionData(json: $json, plan: $plan))
				)
			);
		}

	}//end applyCompute()

	/**
	 * The data a `compute` expression is evaluated against.
	 *
	 * @param array         $json The item's record as it stands.
	 * @param SetFieldsPlan $plan The step's reshaping, positioned at this item.
	 *
	 * @return array The expression data.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function expressionData(array $json, SetFieldsPlan $plan): array {
		return FlowExpression::documentFor(
			item: [FlowItems::JSON => $json],
			options: new FlowExpressionDataOptions(
				itemIndex: $plan->itemIndex(),
				itemCount: $plan->itemCount,
				context: $plan->context
			)
		);

	}//end expressionData()

	/**
	 * Render a configured path against the item, one segment at a time.
	 *
	 * A path is a POSITION, not a value, so it cannot be rendered the way a
	 * value is. Rendering the whole string and then splitting it would let item
	 * data decide the SHAPE of the path: a `{{stage}}` that happened to contain
	 * a dot would silently become two levels of nesting, writing somewhere the
	 * author never named. That is a data-controlled write position, and it is
	 * the sort of thing that is discovered much later and from the wrong end.
	 *
	 * So the literal path is split on `.` FIRST, and each segment is rendered
	 * on its own. That makes the structure a property of the CONFIGURATION and
	 * only the segment names a property of the data.
	 *
	 * A path containing no placeholder renders to itself, so every existing
	 * configuration is unaffected.
	 *
	 * @param string $path The configured path, optionally dotted and templated.
	 * @param array $json The item's record, for placeholder resolution.
	 *
	 * @return string The rendered path.
	 *
	 * @throws UnexpectedValueException When a segment renders empty or introduces a dot.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function renderPath(string $path, array $json): string {
		if (str_contains($path, '{{') === false) {
			return $path;
		}

		$rendered = [];
		foreach (explode('.', $path) as $segment) {
			$rendered[] = $this->renderSegment(segment: $segment, json: $json);
		}

		return implode('.', $rendered);
	}//end renderPath()

	/**
	 * Render one path segment against the item.
	 *
	 * @param string $segment The configured segment.
	 * @param array  $json    The item's record, for placeholder resolution.
	 *
	 * @return string The rendered segment.
	 *
	 * @throws UnexpectedValueException When the segment renders empty or introduces a dot.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function renderSegment(string $segment, array $json): string {
		if (str_contains($segment, '{{') === false) {
			return $segment;
		}

		$value = FlowValueTemplate::render(value: $segment, json: $json);
		$this->assertNamesAField(segment: $segment, value: $value);

		$name = trim((string)$value);
		$this->assertOneField(segment: $segment, name: $name);

		return $name;
	}//end renderSegment()

	/**
	 * Refuse a rendered segment value that cannot be a key at all.
	 *
	 * An unresolved placeholder renders to null. That is a BROKEN path, not a
	 * path to a field called "": say so in those terms, because the author's
	 * mistake is the missing value, not the empty name. A container has no
	 * meaning as a key, and casting one would give a key like "Array" or a
	 * JSON blob.
	 *
	 * @param string $segment The configured segment, for the message.
	 * @param mixed  $value   What the segment rendered to.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value is null or not scalar.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertNamesAField(string $segment, mixed $value): void {
		if ($value === null) {
			throw $this->emptySegment(segment: $segment);
		}

		if (is_scalar($value) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('The path segment "%s" resolved to a value that cannot name a field.', [$segment])
			);
		}

	}//end assertNamesAField()

	/**
	 * Refuse a rendered segment name that is empty or nests.
	 *
	 * Two decisions taken explicitly rather than by accident:
	 *
	 *  - **A dot in a rendered segment is REFUSED, not escaped.** There is no
	 *    escape syntax anywhere in this node — `assign()` splits on `.`
	 *    unconditionally — so an escaped segment would produce a key no other
	 *    node could address, including this one's own `rename` and `remove`.
	 *    Inventing half an escape mechanism here would be worse than saying no.
	 *  - **A segment that renders EMPTY is REFUSED.** Writing to `""` is a
	 *    silent write to a key nothing reads, which is exactly the failure this
	 *    change exists to remove.
	 *
	 * @param string $segment The configured segment, for the message.
	 * @param string $name    The rendered, trimmed segment.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the name is empty or contains a dot.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertOneField(string $segment, string $name): void {
		if ($name === '') {
			throw $this->emptySegment(segment: $segment);
		}

		$this->assertNoNesting(segment: $segment, name: $name);

	}//end assertOneField()

	/**
	 * Refuse a rendered segment name that contains a dot.
	 *
	 * @param string $segment The configured segment, for the message.
	 * @param string $name    The rendered, trimmed segment.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the name contains a dot.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertNoNesting(string $segment, string $name): void {
		if (str_contains($name, '.') === true) {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'The path segment "%1$s" resolved to "%2$s", which contains a dot. '
					. 'A rendered segment names one field and cannot introduce nesting.',
					[$segment, $name]
				)
			);
		}

	}//end assertNoNesting()

	/**
	 * The refusal for a segment that resolved to nothing.
	 *
	 * @param string $segment The configured segment.
	 *
	 * @return UnexpectedValueException The refusal.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function emptySegment(string $segment): UnexpectedValueException {
		return new UnexpectedValueException(
			$this->l10n->t('The path segment "%s" resolved to nothing; a field position is never empty.', [$segment])
		);

	}//end emptySegment()

	/**
	 * What kind of step this is. Reshapes each item.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SCRIPT_TASK;

	}//end getKind()
}//end class
