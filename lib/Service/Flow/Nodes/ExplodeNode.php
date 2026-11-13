<?php

/**
 * The "Explode" flow node — one item per element of an array field.
 *
 * The item model's missing primitive. Every node here already acts once per
 * ITEM, so the engine fans out naturally over a collection — but only when the
 * collection already IS the item list. Nothing turned an ARRAY SITTING ON an
 * item into items, and until now nothing could.
 *
 * That gap is not academic. It is the difference between a flow that can read a
 * list and one that can act on it:
 *
 *   - `object-read` fans out over OBJECTS it fetched (`fanOut: true`), not over
 *     data already on the item;
 *   - `loop` batches the EXISTING items into slices; it does not create any;
 *   - `filter` selects items; `switch` routes them; `merge` combines them.
 *
 * So a step that produced `{findings: [a, b, c]}` could be followed only by
 * something acting on the whole array at once. hydra's reviewer is exactly that
 * shape — it emits findings and the pipeline must file ONE ISSUE PER FINDING —
 * and expressing it needed either this node or a bespoke loop in shell, which
 * is the thing the flow port exists to remove.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\IFlowSideEffectFree;
use UnexpectedValueException;

/**
 * Turns an array field into one item per element.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
class ExplodeNode extends LogicNodeBase implements IFlowNode, ExplodeNodeInterface, IFlowNodeConfigKeys,
	IFlowNodeConfigForm, IFlowNodeTaxonomy, IFlowSideEffectFree
{
	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getId(): string {
		return 'openregister.explode';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Explode');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Turn a list on the item into one item per entry, so later steps act on each.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('openregister', 'app-dark.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of an explode step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['path', 'as', 'keepRecord'];
	}//end configKeys()

	/**
	 * The list to split into items, the field each element lands under, and whether the rest of the item comes along.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'path',
				'label' => $this->l10n->t('List field'),
				'type' => 'text',
				'help' => $this->l10n->t('The field on each item that holds a list, like "findings". Every element becomes an item of its own.'),
				'required' => true,
			],
			[
				'key' => 'as',
				'label' => $this->l10n->t('Name each element'),
				'type' => 'text',
				'help' => $this->l10n->t('The field each new item carries its element under. Defaults to "item".'),
			],
			[
				'key' => 'keepRecord',
				'label' => $this->l10n->t('Keep the rest of the item'),
				'type' => 'boolean',
				'help' => $this->l10n->t('On by default: each new item also carries the fields of the item it came from.'),
			],
		];
	}//end configForm()

	/**
	 * Reject an explode that names no path.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no path is named.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['path'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('An explode step needs the path of a list.'));
		}

	}//end validateConfig()

	/**
	 * Emit one item per element of the named list.
	 *
	 * The element is placed under `as` (default `item`) on a COPY of the
	 * original record, so an exploded entry keeps the context it came from —
	 * a finding still knows its repository and its run. Dropping the rest would
	 * make the node unusable for anything except a list of complete records.
	 *
	 * An item whose path holds no list contributes NOTHING and is not an error:
	 * "the reviewer found nothing" is the ordinary case, and failing the step on
	 * an empty list would turn a clean review into a broken run. An item whose
	 * path holds a SCALAR is a different matter — that is a mis-authored flow,
	 * and it fails.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array One item per element, across all input items.
	 *
	 * @throws UnexpectedValueException When the path holds a scalar.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		// `validateConfig()` runs only when a flow is SAVED, and a seeded or
		// imported flow reaches here unvalidated — at which point returning the
		// items unchanged would make this a silent pass-through that looks
		// exactly like a list of one.
		$this->validateConfig(config: $config);

		return $this->explode(items: $items, config: $config);
	}//end execute()

	/**
	 * One item per element of the named list, across all input items.
	 *
	 * @param array $items  The input items.
	 * @param array $config The validated step configuration.
	 *
	 * @return array The exploded items.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `FlowItems::item()` is the item
	 *   constructor every node uses; injecting a factory for it would add a
	 *   dependency to describe a shape.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function explode(array $items, array $config): array {
		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			foreach ($this->recordsFrom(json: $json, config: $config) as $record) {
				$out[] = FlowItems::item(json: $record, options: new FlowItemOptions(fromItemIndex: $index));
			}
		}

		return $out;
	}//end explode()

	/**
	 * The records one item explodes into: a copy of it (or nothing) with the element under `as`.
	 *
	 * @param array $json   The item's record.
	 * @param array $config The validated step configuration.
	 *
	 * @return array<int, array> One record per list element, possibly none.
	 *
	 * @throws UnexpectedValueException When the path holds a scalar.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function recordsFrom(array $json, array $config): array {
		$under = (string)($config['as'] ?? 'item');
		$base = [];
		if (($config['keepRecord'] ?? true) !== false) {
			$base = $json;
		}

		$records = [];
		foreach ($this->listAt(json: $json, path: trim((string)$config['path'])) as $entry) {
			$record = $base;
			$record[$under] = $entry;
			$records[] = $record;
		}

		return $records;
	}//end recordsFrom()

	/**
	 * The list at the path: empty when the path is absent, refused when it is not a list.
	 *
	 * @param array  $json The item's record.
	 * @param string $path The dotted path.
	 *
	 * @return array The entries to explode into, possibly none.
	 *
	 * @throws UnexpectedValueException When the path holds something other than a list.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function listAt(array $json, string $path): array {
		$list = $this->valueAt(json: $json, path: $path);

		if ($list === null) {
			return [];
		}

		if (is_array($list) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('The explode path "%s" is not a list.', [$path])
			);
		}

		return $list;
	}//end listAt()

	/**
	 * The value at a dotted path, or null when absent.
	 *
	 * @param array $json The item's record.
	 * @param string $path The dotted path.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function valueAt(array $json, string $path) {
		$value = $json;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return null;
			}

			$value = $value[$segment];
		}

		return $value;
	}//end valueAt()

	/**
	 * What kind of step this is. Reshapes the item stream by splitting items.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SCRIPT_TASK;

	}//end getKind()
}//end class
