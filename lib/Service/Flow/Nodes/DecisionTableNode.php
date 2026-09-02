<?php

/**
 * Evaluate a decision table against every item.
 *
 * The flow engine's rule step: the table travels inline in the step's
 * configuration, the shared {@see DecisionTableEvaluator} does the deciding,
 * and the outputs land on the item so the next node can route on them. This
 * node never suspends and never asks anybody anything: a decision that needs
 * a person is `openregister.user-task`, not a rule.
 *
 * The configuration vocabulary (`inputMapping`/`outputMapping` with a
 * same-name default) is dossiq's `evaluateDecision` handler's, kept verbatim
 * so retiring the app-side copy is a mechanical rewrite rather than a
 * translation.
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
 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Decides each item by its inline decision table.
 */
class DecisionTableNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openregister.decision-table';

	/**
	 * Constructor.
	 *
	 * @param DecisionTableEvaluator $engine The shared evaluator; all deciding is delegated to it.
	 * @param DecisionTableValidator $validator Refuses a table the evaluator could not execute.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly DecisionTableEvaluator $engine,
		private readonly DecisionTableValidator $validator,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-decision-table-step-evaluates-its-table-against-every-item
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-the-node-describes-its-own-form-and-writes-an-optional-evaluation-record
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Evaluate a decision table');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-the-node-describes-its-own-form-and-writes-an-optional-evaluation-record
	 */
	public function getDescription(): string {
		return $this->l10n->t('Apply a table of rules to each item and write the outcome onto it.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-the-node-describes-its-own-form-and-writes-an-optional-evaluation-record
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/checkmark.svg');
	}//end getIcon()

	/**
	 * Available in both scopes. Deciding over data the run already carries
	 * grants no privilege; what may be read and written is governed where it
	 * always is, at the object nodes.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-decision-table-step-evaluates-its-table-against-every-item
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a decision-table step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-the-node-describes-its-own-form-and-writes-an-optional-evaluation-record
	 */
	public function configKeys(): array {
		return ['table', 'inputMapping', 'outputMapping', 'defaultOutputs', 'resultKey'];
	}//end configKeys()

	/**
	 * The fields this node is edited through.
	 *
	 * The table itself is edited as JSON for now; the raw pane is the honest
	 * fallback until the canvas grows a table editor, and the save path
	 * refuses anything the evaluator could not execute either way.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-the-node-describes-its-own-form-and-writes-an-optional-evaluation-record
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'table',
				'label' => $this->l10n->t('Decision table'),
				'type' => 'textarea',
				'help' => $this->l10n->t('The table as JSON: hit policy, inputs, outputs and rules. Saving refuses a table the engine cannot run.'),
				'required' => true,
			],
			[
				'key' => 'inputMapping',
				'label' => $this->l10n->t('Input fields'),
				'type' => 'textarea',
				'help' => $this->l10n->t(
					'Where each table input is read from, as input name to field path. An input without an entry reads the field with its own name.'
				),
			],
			[
				'key' => 'outputMapping',
				'label' => $this->l10n->t('Output fields'),
				'type' => 'textarea',
				'help' => $this->l10n->t(
					'Where each table output is written, as output name to field path. An output without an entry writes the field with its own name.'
				),
			],
			[
				'key' => 'defaultOutputs',
				'label' => $this->l10n->t('When no rule matches'),
				'type' => 'textarea',
				'help' => $this->l10n->t('Values to write when no rule matches, one per output. Leave empty to fail the step instead.'),
			],
			[
				'key' => 'resultKey',
				'label' => $this->l10n->t('Record the evaluation under'),
				'type' => 'text',
				'help' => $this->l10n->t('Optional field path for the evaluation record: which rules matched and under which hit policy.'),
			],
		];
	}//end configForm()

	/**
	 * Refuse a configuration the evaluator could not execute, when the flow
	 * is saved or imported rather than when a run reaches the step.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the table or the mappings are refused.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	public function validateConfig(array $config): void {
		$table = ($config['table'] ?? null);
		if (is_array($table) === false || $table === []) {
			throw new UnexpectedValueException(
				$this->l10n->t('A decision-table step needs a table.')
			);
		}

		$problems = $this->validator->validate(table: $table);
		if ($problems !== []) {
			throw new UnexpectedValueException(
				$this->l10n->t('The decision table cannot be run: %s.', [implode('; ', $problems)])
			);
		}

		$inputNames = $this->columnNames(columns: (array)($table['inputs'] ?? []));
		$outputNames = $this->columnNames(columns: (array)($table['outputs'] ?? []));

		$this->assertMapping(config: $config, key: 'inputMapping', declared: $inputNames);
		$this->assertMapping(config: $config, key: 'outputMapping', declared: $outputNames);
		$this->assertDefaults(config: $config, table: $table, outputNames: $outputNames);

		if (array_key_exists('resultKey', $config) === true) {
			$this->assertPath(path: $config['resultKey'], where: 'resultKey');
		}

	}//end validateConfig()

	/**
	 * Decide every item: map the inputs in, evaluate, map the outputs back.
	 *
	 * Deterministic by construction: no I/O, no clock, no state. The same
	 * item and the same configuration decide the same way every firing, and
	 * the node never suspends: a rule step completes in the firing that
	 * reached it.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata (unused: the decision reads only the item).
	 *
	 * @return array The output items, one per input item.
	 *
	 * @throws UnexpectedValueException When the configuration is refused.
	 * @throws DecisionEvaluationException When an item cannot be decided and no default row is configured.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-the-step-is-deterministic-and-never-suspends
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$context` is part of the
	 * node contract; a deterministic rule step deliberately reads none of it.
	 * @SuppressWarnings(PHPMD.StaticAccess) `FlowItems::item()` and
	 * `FlowValueTemplate::render()` are the engine's canonical constructors.
	 */
	public function execute(array $items, array $config, array $context): array {
		if ($items === []) {
			return [];
		}

		// A flow written by hand or imported past the editor must meet the
		// same bar as one saved through it, so the refusals run here too —
		// the same belt-and-braces ObjectReadNode wears.
		$this->validateConfig(config: $config);

		$table = (array)$config['table'];
		$inputMapping = (array)($config['inputMapping'] ?? []);
		$outputMapping = (array)($config['outputMapping'] ?? []);
		$resultKey = trim((string)($config['resultKey'] ?? ''));

		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);

			$result = $this->decide(table: $table, config: $config, json: $json, inputMapping: $inputMapping);

			foreach ($this->columnNames(columns: (array)($table['outputs'] ?? [])) as $name) {
				self::assign(
					json: $json,
					path: (string)($outputMapping[$name] ?? $name),
					value: ($result['outputs'][$name] ?? null)
				);
			}

			if ($resultKey !== '') {
				self::assign(
					json: $json,
					path: $resultKey,
					value: [
						'hitPolicy' => $result['hitPolicy'],
						'matchedRuleIds' => $result['matchedRuleIds'],
						'defaulted' => $result['defaulted'],
						'tableName' => (string)($table['name'] ?? ''),
						'tableKey' => (string)($table['key'] ?? ''),
					]
				);
			}

			$out[] = FlowItems::item(
				json: $json,
				binary: (array)($item[FlowItems::BINARY] ?? []),
				fromItemIndex: $index
			);
		}//end foreach

		return $out;
	}//end execute()

	/**
	 * Decide one item: build the inputs, run the evaluator, apply the
	 * author's no-match choice.
	 *
	 * @param array<string, mixed> $table The table definition.
	 * @param array<string, mixed> $config The step configuration.
	 * @param array<string, mixed> $json The item's record.
	 * @param array<string, mixed> $inputMapping Declared input name to field path.
	 *
	 * @return array{outputs: array<string, mixed>, matchedRuleIds: array<int, string>, hitPolicy: string, defaulted: bool}
	 *
	 * @throws DecisionEvaluationException When the item cannot be decided and no default row exists.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-no-match-takes-the-authors-explicit-default-or-fails-loudly
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `FlowValueTemplate::render()` is the
	 * engine's canonical dotted-path read.
	 */
	private function decide(array $table, array $config, array $json, array $inputMapping): array {
		$inputs = [];
		foreach ($this->columnNames(columns: (array)($table['inputs'] ?? [])) as $name) {
			$path = (string)($inputMapping[$name] ?? $name);
			// A value that is exactly one placeholder keeps its type, so a
			// number stays a number on its way into the coercion.
			$inputs[$name] = FlowValueTemplate::render(value: ('{{' . $path . '}}'), json: $json);
		}

		try {
			$result = $this->engine->evaluate(decisionTable: $table, inputs: $inputs);

			return [
				'outputs' => $result['outputs'],
				'matchedRuleIds' => $result['matchedRuleIds'],
				'hitPolicy' => $result['hitPolicy'],
				'defaulted' => false,
			];
		} catch (DecisionEvaluationException $e) {
			$defaults = ($config['defaultOutputs'] ?? null);
			if ($e->getErrorCode() !== 'no_rule_matched' || is_array($defaults) === false) {
				// Everything else — a missing input, a type mismatch, a hit
				// policy violation — is a fault, not a no-match, and the
				// step's onError policy is the place that decides what a
				// fault costs. Catching it here would report a completed
				// decision that never happened.
				throw $e;
			}

			return [
				'outputs' => $defaults,
				'matchedRuleIds' => [],
				'hitPolicy' => strtoupper((string)($table['hitPolicy'] ?? 'UNIQUE')),
				'defaulted' => true,
			];
		}//end try
	}//end decide()

	/**
	 * The declared column names, in declaration order.
	 *
	 * @param array<int, mixed> $columns The declared `inputs` or `outputs`.
	 *
	 * @return array<int, string> The names.
	 */
	private function columnNames(array $columns): array {
		$names = [];
		foreach ($columns as $column) {
			if (is_array($column) === false) {
				continue;
			}

			$name = trim((string)($column['name'] ?? ''));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end columnNames()

	/**
	 * Refuse a mapping the step would otherwise silently ignore.
	 *
	 * A mapping entry for a name the table does not declare is configuration
	 * that looks like behaviour and is not; a templated path would let item
	 * data choose the write position on a RULE step, which no migrating
	 * table needs and which is refused rather than half-supported.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param string $key `inputMapping` or `outputMapping`.
	 * @param array<int, string> $declared The declared names for that side.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the mapping is refused.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function assertMapping(array $config, string $key, array $declared): void {
		if (array_key_exists($key, $config) === false) {
			return;
		}

		if (is_array($config[$key]) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('The %s must be an object of name to field path.', [$key])
			);
		}

		foreach ($config[$key] as $name => $path) {
			if (in_array((string)$name, $declared, true) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('The %1$s names "%2$s", which the table does not declare. The step would silently ignore it.', [$key, (string)$name])
				);
			}

			$this->assertPath(path: $path, where: ($key . ' "' . (string)$name . '"'));
		}

	}//end assertMapping()

	/**
	 * Refuse the author's default row unless it is complete, and refuse it
	 * entirely on COLLECT.
	 *
	 * A partial default writes half a decision and nulls for the rest, which
	 * is the silent shape this node exists to refuse. On COLLECT the empty
	 * list is a real answer, so a default would be unreachable configuration
	 * that looks like behaviour.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array<string, mixed> $table The table definition.
	 * @param array<int, string> $outputNames The declared output names.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the defaults are refused.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-no-match-takes-the-authors-explicit-default-or-fails-loudly
	 */
	private function assertDefaults(array $config, array $table, array $outputNames): void {
		if (array_key_exists('defaultOutputs', $config) === false) {
			return;
		}

		if (is_array($config['defaultOutputs']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('The defaultOutputs must be an object of output name to value.')
			);
		}

		if (strtoupper(trim((string)($table['hitPolicy'] ?? 'UNIQUE'))) === 'COLLECT') {
			throw new UnexpectedValueException(
				$this->l10n->t('A COLLECT table never fails to match: its empty list is the answer. Remove defaultOutputs.')
			);
		}

		foreach ($outputNames as $name) {
			if (array_key_exists($name, $config['defaultOutputs']) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('The defaultOutputs are missing a value for output "%s". A partial default writes half a decision.', [$name])
				);
			}
		}

		foreach (array_keys($config['defaultOutputs']) as $name) {
			if (in_array((string)$name, $outputNames, true) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('The defaultOutputs name "%s", which the table does not declare. The step would silently ignore it.', [(string)$name])
				);
			}
		}

	}//end assertDefaults()

	/**
	 * Refuse a field path that is empty, not a string, or templated.
	 *
	 * @param mixed $path The configured path.
	 * @param string $where Which configuration entry it came from, for the message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the path is refused.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function assertPath(mixed $path, string $where): void {
		if (is_string($path) === false || trim($path) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('The field path for %s is empty. A position is never empty.', [$where])
			);
		}

		if (str_contains($path, '{{') === true) {
			throw new UnexpectedValueException(
				$this->l10n->t('The field path for %s is templated. A rule step reads and writes the positions its author named, literally.', [$where])
			);
		}

	}//end assertPath()

	/**
	 * Write a value at a dotted path, creating the containers it needs.
	 *
	 * Same semantics as `openregister.set-fields` gives a literal path: the
	 * structure is a property of the configuration. A segment whose current
	 * value is not an array is replaced by a container, because merging into
	 * a scalar has no meaning and skipping silently would be the invisible
	 * no-op this node refuses everywhere else.
	 *
	 * @param array $json The item's record, modified in place.
	 * @param string $path The field path, optionally dotted.
	 * @param mixed $value The value to write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-decision-table-step-evaluates-its-table-against-every-item
	 */
	private static function assign(array &$json, string $path, mixed $value): void {
		if (str_contains($path, '.') === false) {
			$json[$path] = $value;

			return;
		}

		$segments = explode('.', $path);
		$last = array_pop($segments);
		$cursor = &$json;

		foreach ($segments as $segment) {
			if (isset($cursor[$segment]) === false || is_array($cursor[$segment]) === false) {
				$cursor[$segment] = [];
			}

			$cursor = &$cursor[$segment];
		}

		$cursor[$last] = $value;
		unset($cursor);

	}//end assign()
}//end class
