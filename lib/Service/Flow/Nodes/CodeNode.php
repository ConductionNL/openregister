<?php

/**
 * CodeNode: a step of authored JavaScript, run in the flow-code-runner ExApp.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\CodeRun;
use OCA\OpenRegister\Service\Flow\CodeRunnerClientInterface;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use UnexpectedValueException;

/**
 * Runs a step of authored JavaScript on the step's items.
 *
 * The code never runs in Nextcloud: the node sends it with the items and the
 * limits to the `flow-code-runner` ExApp and reads items back. Without the
 * runner the node is not offered, and a flow that holds it is refused at
 * save, publish and run, naming the runner (validateConfig() is what the
 * preflight calls). A failing call throws, so the step's `onError` decides.
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */
class CodeNode extends LogicNodeBase implements IFlowNode, CodeNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy {

	/**
	 * The only language so far.
	 */
	public const LANGUAGE = 'javascript';

	/**
	 * Run the source once per item, or once on all items.
	 */
	public const MODES = ['perItem', 'allItems'];

	/**
	 * The time limit when the step names none, in milliseconds.
	 */
	public const DEFAULT_TIMEOUT_MS = 5000;

	/**
	 * The memory limit when the step names none, in megabytes.
	 */
	public const DEFAULT_MEMORY_MB = 64;

	/**
	 * The app whose config holds the ceilings.
	 */
	private const APP_ID = 'openregister';

	/**
	 * App config key of the instance's time ceiling.
	 */
	public const CEILING_TIMEOUT_KEY = 'flow_code_max_timeout_ms';

	/**
	 * App config key of the instance's memory ceiling.
	 */
	public const CEILING_MEMORY_KEY = 'flow_code_max_memory_mb';

	/**
	 * The time ceiling when the administrator set none.
	 */
	private const DEFAULT_CEILING_TIMEOUT_MS = 30000;

	/**
	 * The memory ceiling when the administrator set none.
	 */
	private const DEFAULT_CEILING_MEMORY_MB = 256;

	/**
	 * Host name suffixes that name this network rather than a public host.
	 */
	private const PRIVATE_SUFFIXES = ['.local', '.localhost', '.internal', '.lan', '.home.arpa'];

	/**
	 * Constructor.
	 *
	 * @param IL10N                     $l10n      Translations for palette text and refusals.
	 * @param IURLGenerator             $urls      For the palette icon.
	 * @param CodeRunnerClientInterface $runner    The flow-code-runner ExApp.
	 * @param IAppConfig                $appConfig The instance ceilings.
	 */
	public function __construct(
		IL10N $l10n,
		IURLGenerator $urls,
		private readonly CodeRunnerClientInterface $runner,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct(l10n: $l10n, urls: $urls);

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function getId(): string {
		return 'openregister.code';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Code');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function getDescription(): string {
		return $this->l10n->t('Run your own JavaScript on the items, in a separate runner.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'filetypes/text-code.svg');
	}//end getIcon()

	/**
	 * Offered only where the workflow scope allows and the runner answers.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether the node is available.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-without-the-runner-the-code-step-is-hidden-and-refused
	 */
	public function isAvailableForScope(int $scope): bool {
		return parent::isAvailableForScope(scope: $scope) === true && $this->runner->isAvailable() === true;
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a code step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function configKeys(): array {
		return ['language', 'source', 'mode', 'timeoutMs', 'memoryMb', 'egress'];
	}//end configKeys()

	/**
	 * Refuse a code step that cannot run as written, or cannot run here.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the config is unusable or the runner is absent.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function validateConfig(array $config): void {
		$language = ($config['language'] ?? self::LANGUAGE);
		if ($language !== self::LANGUAGE) {
			throw new UnexpectedValueException(
				$this->l10n->t('A code step runs JavaScript; "{language}" is not supported.', ['language' => (string)json_encode($language)])
			);
		}

		$source = ($config['source'] ?? '');
		if (is_string($source) === false || trim($source) === '') {
			throw new UnexpectedValueException($this->l10n->t('A code step needs source code.'));
		}

		if (in_array(($config['mode'] ?? 'perItem'), self::MODES, true) === false) {
			throw new UnexpectedValueException($this->l10n->t('A code step runs per item or on all items; mode must be perItem or allItems.'));
		}

		$this->limit(config: $config, key: 'timeoutMs', default: self::DEFAULT_TIMEOUT_MS, ceiling: $this->timeoutCeiling());
		$this->limit(config: $config, key: 'memoryMb', default: self::DEFAULT_MEMORY_MB, ceiling: $this->memoryCeiling());
		$this->egress(config: $config);

		if ($this->runner->isAvailable() === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('A code step needs the {app} ExApp, which is not installed or not answering.', ['app' => CodeRunnerClientInterface::APP_ID])
			);
		}

	}//end validateConfig()

	/**
	 * Send the items to the runner and return what the code made of them.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The output items.
	 *
	 * @throws \OCA\OpenRegister\Service\Flow\CodeRunFailedException When the runner is absent or the code fails.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function execute(FlowNodeCall $call): array {
		$config = $call->config;
		$mode = (string)($config['mode'] ?? 'perItem');
		$inputs = [];
		foreach (array_values($call->items) as $item) {
			$inputs[] = ['json' => (array)($item[FlowItems::JSON] ?? [])];
		}

		$answer = $this->runner->run(
			new CodeRun(
				source: (string)($config['source'] ?? ''),
				mode: $mode,
				items: $inputs,
				timeoutMs: (int)($config['timeoutMs'] ?? self::DEFAULT_TIMEOUT_MS),
				memoryMb: (int)($config['memoryMb'] ?? self::DEFAULT_MEMORY_MB),
				egress: $this->hosts(config: $config)
			)
		);

		// Per item with one answer per input, each output keeps its input's
		// index, so provenance survives the step as it does through a filter.
		$paired = ($mode === 'perItem' && count($answer->items) === count($inputs));
		$outputs = [];
		foreach ($answer->items as $index => $item) {
			$json = $item;
			if (is_array($item[FlowItems::JSON] ?? null) === true) {
				$json = $item[FlowItems::JSON];
			}

			$options = new FlowItemOptions();
			if ($paired === true) {
				$options = new FlowItemOptions(fromItemIndex: $index);
			}

			$outputs[] = FlowItems::item(json: $json, options: $options);
		}

		return $outputs;

	}//end execute()

	/**
	 * What kind of step this is. Runs a script on the items.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SCRIPT_TASK;
	}//end getKind()

	/**
	 * Refuse a limit that is not a positive whole number or exceeds the instance ceiling.
	 *
	 * @param array  $config  The step configuration.
	 * @param string $key     `timeoutMs` or `memoryMb`.
	 * @param int    $default The limit when the step names none.
	 * @param int    $ceiling The instance ceiling.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the limit is unusable.
	 */
	private function limit(array $config, string $key, int $default, int $ceiling): void {
		$value = ($config[$key] ?? $default);
		if (is_int($value) === false || $value < 1) {
			throw new UnexpectedValueException($this->l10n->t('{key} must be a whole number above zero.', ['key' => $key]));
		}

		if ($value > $ceiling) {
			throw new UnexpectedValueException(
				$this->l10n->t('{key} of {value} is above this instance\'s ceiling of {ceiling}.', ['key' => $key, 'value' => $value, 'ceiling' => $ceiling])
			);
		}

	}//end limit()

	/**
	 * Refuse an egress list that is not a list of public host names.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When an entry is not a public host name.
	 */
	private function egress(array $config): void {
		$egress = ($config['egress'] ?? []);
		if (is_array($egress) === false || array_is_list($egress) === false) {
			throw new UnexpectedValueException($this->l10n->t('egress must be a list of host names.'));
		}

		foreach ($egress as $host) {
			if (is_string($host) === false || self::isPublicHostName(host: $host) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('"{host}" is not a public host name the code may call.', ['host' => (string)json_encode($host)])
				);
			}
		}

	}//end egress()

	/**
	 * Whether a value is a public DNS host name: no scheme, port, path or IP literal, and not a local name.
	 *
	 * @param string $host The entry.
	 *
	 * @return bool Whether the code may be allowed to call it.
	 */
	private static function isPublicHostName(string $host): bool {
		$host = strtolower($host);
		if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
			return false;
		}

		foreach (self::PRIVATE_SUFFIXES as $suffix) {
			if (str_ends_with($host, $suffix) === true) {
				return false;
			}
		}

		return true;

	}//end isPublicHostName()

	/**
	 * The egress hosts, lower-cased.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return array<int, string> The hosts.
	 */
	private function hosts(array $config): array {
		$hosts = [];
		foreach ((array)($config['egress'] ?? []) as $host) {
			$hosts[] = strtolower((string)$host);
		}

		return $hosts;

	}//end hosts()

	/**
	 * The instance's time ceiling.
	 *
	 * @return int Milliseconds.
	 */
	private function timeoutCeiling(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::CEILING_TIMEOUT_KEY, self::DEFAULT_CEILING_TIMEOUT_MS);
	}//end timeoutCeiling()

	/**
	 * The instance's memory ceiling.
	 *
	 * @return int Megabytes.
	 */
	private function memoryCeiling(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::CEILING_MEMORY_KEY, self::DEFAULT_CEILING_MEMORY_MB);
	}//end memoryCeiling()
}//end class
