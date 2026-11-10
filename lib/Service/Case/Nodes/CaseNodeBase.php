<?php

/**
 * What the two case steps share: where the case is, and who acts.
 *
 * A flow step that opens or advances a case acts on the object the step
 * receives (its `uuid`, or the configured `uuid` template), as the run's
 * acting identity (`runAs`, or the interactive user when the run names
 * nobody), inside {@see FlowRunAsScope} so every read the case layer makes
 * is checked against that identity. It goes through the same
 * {@see CasePlanService} verbs, validation and authorization as any other
 * caller: one engine, no side door.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case\Nodes;

use OCA\OpenRegister\Service\Case\CaseAnchor;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;

/**
 * Shared resolution for the case steps.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */
abstract class CaseNodeBase implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy {

	/**
	 * Translations.
	 *
	 * @var IL10N
	 */
	protected readonly IL10N $l10n;

	/**
	 * For the palette icon.
	 *
	 * @var IURLGenerator
	 */
	protected readonly IURLGenerator $urls;

	/**
	 * Constructor.
	 *
	 * @param CaseStepRunner       $steps        The case layer's verbs, the run-as scope and the interactive user.
	 * @param FlowNodePresentation $presentation Translations and the palette icon.
	 */
	public function __construct(
		protected readonly CaseStepRunner $steps,
		FlowNodePresentation $presentation,
	) {
		$this->l10n = $presentation->l10n;
		$this->urls = $presentation->urls;
	}//end __construct()

	/**
	 * The palette icon.
	 *
	 * @return string The icon path.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/checkmark.svg');
	}//end getIcon()

	/**
	 * Available in both flow scopes.
	 *
	 * @param int $scope The workflow engine scope.
	 *
	 * @return bool True when available.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * A case step calls the register's case layer.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;
	}//end getKind()

	/**
	 * Listed with the object steps: a case IS an object.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_OBJECTS;
	}//end getCategory()

	/**
	 * The identity the case verb acts as.
	 *
	 * @param array $context The run context.
	 *
	 * @return string The uid.
	 *
	 * @throws RuntimeException When nobody can be named.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	protected function actingUid(array $context): string {
		$uid = trim((string)($context[FlowRunServiceInterface::RUN_AS_CONTEXT_KEY] ?? ''));
		if ($uid === '') {
			$uid = trim((string)$this->steps->session->getUser()?->getUID());
		}

		if ($uid === '') {
			throw new RuntimeException(
				$this->l10n->t('A case step must act as someone, and this run names nobody (runAs) and has no signed-in user.')
			);
		}

		return $uid;
	}//end actingUid()

	/**
	 * The distinct objects the step acts on, with the register and schema
	 * the received item names in its `@self` block, when numeric and when the
	 * target is that item's own object (a configured `uuid` naming another
	 * object carries none).
	 *
	 * @param array $items The incoming items.
	 * @param array $config The step configuration.
	 *
	 * @return array<string, CaseAnchor> The targets, by uuid.
	 *
	 * @throws RuntimeException When an item names no object.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	protected function targets(array $items, array $config): array {
		$configured = ($config['uuid'] ?? null);
		$targets = [];
		foreach ($items as $item) {
			$target = $this->targetOf(item: $item, configured: $configured);
			$targets[$target->objectUuid] = $target;
		}

		return $targets;
	}//end targets()

	/**
	 * The object one received item makes the step act on.
	 *
	 * @param mixed $item The incoming item.
	 * @param mixed $configured The configured `uuid` template, when any.
	 *
	 * @return CaseAnchor The target, with the register and schema only when they are its own.
	 *
	 * @throws RuntimeException When the item names no object.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function targetOf(mixed $item, mixed $configured): CaseAnchor {
		$json = self::arrayOrEmpty(value: ($item[FlowItems::JSON] ?? []));
		$own = self::ownUuid(json: $json);
		$uuid = self::resolveUuid(configured: $configured, json: $json);
		if ($uuid === '') {
			throw new RuntimeException($this->l10n->t('A case step could not work out which object the case is from the item it received.'));
		}

		// The register and schema in `@self` belong to the RECEIVED item. A configured `uuid` may name another
		// object (a parent, a related record); anchoring that object's plan to the item's register and schema
		// would make a later ensure refuse the anchor and every read that filters on them miss the rows, so
		// such a plan is anchored to neither.
		$self = self::arrayOrEmpty(value: ($json['@self'] ?? []));
		$ownObject = ($uuid === $own);

		return new CaseAnchor(
			objectUuid: $uuid,
			registerId: $this->anchorId(value: ($self['register'] ?? null), ownObject: $ownObject),
			schemaId: $this->anchorId(value: ($self['schema'] ?? null), ownObject: $ownObject)
		);
	}//end targetOf()

	/**
	 * The object uuid: the configured template rendered over the item, or the item's own.
	 *
	 * @param mixed $configured The configured `uuid` template, when any.
	 * @param array $json       The item's data.
	 *
	 * @return string The trimmed uuid, possibly ''.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate is the engine's
	 * stateless template renderer and every node calls it this way.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private static function resolveUuid(mixed $configured, array $json): string {
		if (is_string($configured) === true && trim($configured) !== '') {
			return trim((string)FlowValueTemplate::render($configured, $json));
		}

		return self::ownUuid(json: $json);
	}//end resolveUuid()

	/**
	 * The received item's own uuid (or id), trimmed; '' when it names none.
	 *
	 * @param array<string, mixed> $json The item's data.
	 *
	 * @return string The uuid.
	 */
	private static function ownUuid(array $json): string {
		return trim((string)($json['uuid'] ?? ($json['id'] ?? '')));
	}//end ownUuid()

	/**
	 * Run a case verb once per distinct object, as the run's identity, and
	 * hand each item the result for its object. An empty batch passes
	 * through untouched; otherwise the configuration is validated first.
	 *
	 * @param CaseStepRun $run  The incoming items, the step configuration and the run context.
	 * @param callable    $work Called as `$work(CaseStepCall $call): array` for each distinct object.
	 *
	 * @return array The items, each carrying `case`.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	protected function perTarget(CaseStepRun $run, callable $work): array {
		if ($run->items === []) {
			return $run->items;
		}

		$this->validateConfig(config: $run->config);
		$uid = $this->actingUid(context: $run->context);
		$results = [];
		foreach ($this->targets(items: $run->items, config: $run->config) as $uuid => $target) {
			$call = new CaseStepCall(target: $target, uid: $uid);
			$results[$uuid] = $this->steps->scope->call(context: $run->context, operation: fn (): array => $work($call));
		}

		return $this->withResults(run: $run, results: $results);
	}//end perTarget()

	/**
	 * Put each distinct object's result on every item that targets it, as `case`.
	 *
	 * @param CaseStepRun                         $run     The incoming items and the step configuration.
	 * @param array<string, array<string, mixed>> $results The result per object uuid.
	 *
	 * @return array The items, each carrying `case`.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function withResults(CaseStepRun $run, array $results): array {
		$out = [];
		foreach ($run->items as $index => $item) {
			$json = ($item[FlowItems::JSON] ?? []);
			$json['case'] = $results[(string)array_key_first($this->targets(items: [$item], config: $run->config))];
			$item[FlowItems::JSON] = $json;
			$out[$index] = $item;
		}

		return $out;
	}//end withResults()

	/**
	 * The editor field that names the case object, shared by every case step.
	 *
	 * @return array<string, mixed> The field.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	protected function objectField(): array {
		return [
			'key' => 'uuid',
			'label' => $this->l10n->t('Object'),
			'type' => 'text',
			'help' => $this->l10n->t('Which object the case is. Leave empty to use the object the step receives.'),
		];
	}//end objectField()

	/**
	 * An array value, or an empty one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array The value when it is an array, otherwise [].
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private static function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOrEmpty()

	/**
	 * The register or schema id to anchor a plan to: the item's own, only for the item's own object.
	 *
	 * @param mixed $value The `@self` value.
	 * @param bool $ownObject Whether the target is the object the item is.
	 *
	 * @return int|null The id, or null when it is not numeric or the target is another object.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function anchorId(mixed $value, bool $ownObject): ?int {
		if ($ownObject === false) {
			return null;
		}

		return $this->intOrNull(value: $value);
	}//end anchorId()

	/**
	 * A numeric id, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The id.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function intOrNull(mixed $value): ?int {
		if (is_string($value) === true && ctype_digit($value) === true) {
			return (int)$value;
		}

		if (is_int($value) === false) {
			return null;
		}

		return $value;
	}//end intOrNull()
}//end class
