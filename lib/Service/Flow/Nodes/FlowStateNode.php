<?php

/**
 * Reads and writes the state a flow keeps between runs.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * The node that makes flow state usable from a graph.
 *
 * A scheduled flow starts blank on every tick. `FlowStateHandle` gives it
 * somewhere to remember things; this node is how a graph reaches that without
 * writing PHP.
 *
 * The interesting operation is `claim`. A capacity cap — hydra's "at most ten
 * pipelines at once", a booking, a lease — is a map of named slots where a free
 * one must be taken by exactly one holder. Expressed with `get` and `set` that
 * is a read-modify-write, and two runs doing it concurrently lose one of the
 * claims. `claim` does the whole thing in one step, inside a single run, and
 * emits `claimed: false` rather than pretending it succeeded.
 *
 * ⚠️ What makes that SAFE is that a scheduled flow never overlaps itself
 * (`FlowScheduleService::fireDueFlows()` skips a tick whose previous run is
 * still going). That is the same property the shell orchestrator this replaces
 * relies on — one supervisor holding a flock, whose own slot bookkeeping is a
 * plain check-then-write and is safe only because nothing runs beside it.
 * This node is NOT a cross-flow lock: for that, write an object with
 * `onConflict: fail`, where the database arbitrates.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class FlowStateNode extends LogicNodeBase implements IFlowNode, FlowStateNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy, IFlowNodeConfigForm {

	/**
	 * Read one key into the item.
	 *
	 * @var string
	 */
	private const OP_GET = 'get';

	/**
	 * Write one key.
	 *
	 * @var string
	 */
	private const OP_SET = 'set';

	/**
	 * Remove one key.
	 *
	 * @var string
	 */
	private const OP_FORGET = 'forget';

	/**
	 * Take a free slot from a map, or report that none was free.
	 *
	 * @var string
	 */
	private const OP_CLAIM = 'claim';

	/**
	 * Give a held slot back.
	 *
	 * @var string
	 */
	private const OP_RELEASE = 'release';

	/**
	 * Accepted operations.
	 *
	 * @var string[]
	 */
	private const OPERATIONS = [
		self::OP_GET,
		self::OP_SET,
		self::OP_FORGET,
		self::OP_CLAIM,
		self::OP_RELEASE,
	];

	/**
	 * The config vocabulary, across all five operations.
	 *
	 * @var string[]
	 */
	private const CONFIG_KEYS = [
		'operation',
		'key',
		'as',
		'default',
		'value',
		'from',
		'record',
		'slots',
		'capacity',
		'leaseSeconds',
		'holder',
		'slot',
	];

	/**
	 * The slot-pool half of the node: `claim` and `release`.
	 *
	 * @var FlowStateSlots
	 */
	private FlowStateSlots $slots;

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls Icon paths.
	 *
	 * @return void
	 */
	public function __construct(
		IL10N $l10n,
		IURLGenerator $urls,
	) {
		parent::__construct(l10n: $l10n, urls: $urls);
		$this->slots = new FlowStateSlots();
	}//end __construct()

	/**
	 * The type identifier.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'openregister.flow-state';
	}//end getId()

	/**
	 * The display name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Flow state');
	}//end getDisplayName()

	/**
	 * The description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Remember something between runs of this flow — a counter, a cursor, or a table of slots.');
	}//end getDescription()

	/**
	 * The icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/history.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of a flow-state step, across all five operations.
	 *
	 * Deliberately the UNION rather than a per-operation set: which keys are
	 * required for which operation is `validateConfig()`'s question, and it
	 * answers it with a message naming the operation. This method answers only
	 * "could this node ever read that key".
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return self::CONFIG_KEYS;

	}//end configKeys()

	/**
	 * The scalar keys of a flow-state step, across its five operations.
	 *
	 * `default` and `value` take any JSON value and `record` is a list of field
	 * names; a text field would turn the first two into strings and cannot hold
	 * the third, so all three stay in the JSON pane.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/flow-node-config-forms/tasks.md
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'operation',
				'label' => $this->l10n->t('Operation'),
				'type' => 'text',
				'help' => $this->l10n->t('One of: get, set, forget, claim, release. Which other fields apply depends on it.'),
				'required' => true,
			],
			[
				'key' => 'key',
				'label' => $this->l10n->t('State key'),
				'type' => 'text',
				'help' => $this->l10n->t('The name the value is kept under. Needed for get, set and forget.'),
			],
			[
				'key' => 'as',
				'label' => $this->l10n->t('Read into field'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'get only: the item field the value lands in. Defaults to the state key. The value used when the key '
						. 'is unset ("default") is edited in the JSON pane.'
				),
			],
			[
				'key' => 'from',
				'label' => $this->l10n->t('Store the item field'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'set only: the item field whose value is stored. Leave empty to store the fixed "value", which is '
						. 'edited in the JSON pane.'
				),
			],
			[
				'key' => 'slots',
				'label' => $this->l10n->t('Slot table'),
				'type' => 'text',
				'help' => $this->l10n->t('claim and release: the state key of the table of slots.'),
			],
			[
				'key' => 'capacity',
				'label' => $this->l10n->t('Capacity'),
				'type' => 'number',
				'help' => $this->l10n->t('claim only: how many slots the table has. At least one.'),
			],
			[
				'key' => 'leaseSeconds',
				'label' => $this->l10n->t('Lease in seconds'),
				'type' => 'number',
				'help' => $this->l10n->t('claim only: after how long a held slot counts as free again. Zero or empty keeps it until it is released.'),
			],
			[
				'key' => 'holder',
				'label' => $this->l10n->t('Holder field'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'claim and release: the item field that names who holds the slot. Defaults to "holder". Extra fields '
						. 'to keep on the slot ("record") are edited in the JSON pane.'
				),
			],
			[
				'key' => 'slot',
				'label' => $this->l10n->t('Slot field'),
				'type' => 'text',
				'help' => $this->l10n->t('release only: the item field that names the slot to give back. Defaults to "slot".'),
			],
		];
	}//end configForm()

	/**
	 * Validate the authored configuration.
	 *
	 * @param array $config The step's configuration.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the configuration cannot run.
	 */
	public function validateConfig(array $config): void {
		$operation = (string)($config['operation'] ?? '');
		$this->validateOperation(operation: $operation);

		if ($operation === self::OP_CLAIM || $operation === self::OP_RELEASE) {
			$this->validateSlotConfig(config: $config, operation: $operation);
			return;
		}

		if (trim((string)($config['key'] ?? '')) === '') {
			throw new InvalidArgumentException('key is required');
		}

	}//end validateConfig()

	/**
	 * Refuse an operation the node does not know.
	 *
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the operation is unknown.
	 */
	private function validateOperation(string $operation): void {
		if (in_array($operation, self::OPERATIONS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('operation must be one of: %s', implode(', ', self::OPERATIONS))
			);
		}

	}//end validateOperation()

	/**
	 * Validate the configuration of a slot operation, `claim` or `release`.
	 *
	 * @param array  $config    The step's configuration.
	 * @param string $operation The operation, already known to be a slot one.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the configuration cannot run.
	 */
	private function validateSlotConfig(array $config, string $operation): void {
		if (trim((string)($config['slots'] ?? '')) === '') {
			throw new InvalidArgumentException('slots is required for claim and release');
		}

		if ($operation === self::OP_CLAIM && (int)($config['capacity'] ?? 0) < 1) {
			throw new InvalidArgumentException('capacity must be at least 1 for claim');
		}

	}//end validateSlotConfig()

	/**
	 * Run the step.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The output items.
	 *
	 * @throws InvalidArgumentException When flow state is unavailable.
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;
		$step = new FlowStateStep(state: $this->stateOf(context: $context), config: $config);

		return $this->applyEach(step: $step, items: $items);
	}//end execute()

	/**
	 * Apply the operation to every item, in order.
	 *
	 * @param FlowStateStep $step  The state handle and configuration.
	 * @param array         $items The input items.
	 *
	 * @return array The output items.
	 */
	private function applyEach(FlowStateStep $step, array $items): array {
		$output = [];
		foreach ($items as $index => $item) {
			$output[] = FlowItems::item(
				json: $this->apply(step: $step, json: (array)($item['json'] ?? [])),
				options: new FlowItemOptions(binary: (array)($item['binary'] ?? []), fromItemIndex: (int)$index)
			);
		}

		return $output;
	}//end applyEach()

	/**
	 * The run's flow-state handle.
	 *
	 * A run with no flow id has no state to keep. Failing loudly beats silently
	 * behaving as though every key were empty, which would make a capacity cap
	 * wave everything through.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return FlowStateHandle The handle.
	 *
	 * @throws InvalidArgumentException When flow state is unavailable.
	 */
	private function stateOf(array $context): FlowStateHandle {
		$state = ($context[FlowStateHandle::CONTEXT_KEY] ?? null);
		if (($state instanceof FlowStateHandle) === false) {
			throw new InvalidArgumentException('This run has no flow state — flow state needs a flow-scoped run.');
		}

		return $state;
	}//end stateOf()

	/**
	 * Apply the configured operation to one item.
	 *
	 * @param FlowStateStep $step The state handle and configuration.
	 * @param array         $json The item's json.
	 *
	 * @return array The item's json.
	 */
	private function apply(FlowStateStep $step, array $json): array {
		return match ((string)($step->config['operation'] ?? '')) {
			self::OP_GET => $this->doGet(step: $step, json: $json),
			self::OP_SET => $this->doSet(step: $step, json: $json),
			self::OP_FORGET => $this->doForget(step: $step, json: $json),
			self::OP_CLAIM => $this->slots->claim(step: $step, json: $json),
			self::OP_RELEASE => $this->slots->release(step: $step, json: $json),
			default => $json,
		};
	}//end apply()

	/**
	 * Read one key into the item.
	 *
	 * @param FlowStateStep $step The state handle and configuration.
	 * @param array         $json The item's json.
	 *
	 * @return array The item's json.
	 */
	private function doGet(FlowStateStep $step, array $json): array {
		$key = (string)$step->config['key'];
		$as = (string)($step->config['as'] ?? $key);
		$json[$as] = $step->state->get($key, ($step->config['default'] ?? null));

		return $json;
	}//end doGet()

	/**
	 * Write one key.
	 *
	 * `from` reads the value off the item, so a step can store something it
	 * just computed; `value` is a literal for authored constants.
	 *
	 * @param FlowStateStep $step The state handle and configuration.
	 * @param array         $json The item's json.
	 *
	 * @return array The item's json.
	 */
	private function doSet(FlowStateStep $step, array $json): array {
		$value = ($step->config['value'] ?? null);
		if (isset($step->config['from']) === true) {
			$value = ($json[(string)$step->config['from']] ?? null);
		}

		$step->state->set((string)$step->config['key'], $value);

		return $json;
	}//end doSet()

	/**
	 * Remove one key.
	 *
	 * @param FlowStateStep $step The state handle and configuration.
	 * @param array         $json The item's json.
	 *
	 * @return array The item's json.
	 */
	private function doForget(FlowStateStep $step, array $json): array {
		$step->state->forget((string)$step->config['key']);

		return $json;
	}//end doForget()

	/**
	 * What kind of step this is. Writes the run its own state.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SCRIPT_TASK;

	}//end getKind()
}//end class
