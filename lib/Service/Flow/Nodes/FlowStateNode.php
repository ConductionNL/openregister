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
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

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
class FlowStateNode implements IFlowNode, FlowStateNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy {

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
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
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
	 * Availability.
	 *
	 * @param integer $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

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
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return self::CONFIG_KEYS;

	}//end configKeys()

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
	 * @param array $items The input items.
	 * @param array $config The step's configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @throws InvalidArgumentException When flow state is unavailable.
	 */
	public function execute(array $items, array $config, array $context): array {
		$step = new FlowStateStep(state: $this->stateOf(context: $context), config: $config);

		$output = [];
		foreach ($items as $index => $item) {
			$output[] = FlowItems::item(
				json: $this->apply(step: $step, json: (array)($item['json'] ?? [])),
				binary: (array)($item['binary'] ?? []),
				fromItemIndex: (int)$index
			);
		}

		return $output;
	}//end execute()

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

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_LOGIC;

	}//end getCategory()
}//end class
