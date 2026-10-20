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

use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use RuntimeException;

/**
 * Shared resolution for the case steps.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */
abstract class CaseNodeBase implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy {

	/**
	 * Constructor.
	 *
	 * @param CasePlanService $plans The case layer's verbs.
	 * @param FlowRunAsScope $scope Runs the work as the run's identity.
	 * @param IUserSession $session The interactive user, when the run names nobody.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		protected readonly CasePlanService $plans,
		protected readonly FlowRunAsScope $scope,
		protected readonly IUserSession $session,
		protected readonly IL10N $l10n,
		protected readonly IURLGenerator $urls,
	) {

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
		$uid = trim((string)($context[FlowRunService::RUN_AS_CONTEXT_KEY] ?? ''));
		if ($uid === '') {
			$uid = trim((string)$this->session->getUser()?->getUID());
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
	 * @return array<string, array{uuid: string, register: int|null, schema: int|null}> By uuid.
	 *
	 * @throws RuntimeException When an item names no object.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate is the engine's
	 * stateless template renderer and every node calls it this way.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	protected function targets(array $items, array $config): array {
		$configured = ($config['uuid'] ?? null);
		$targets = [];
		foreach ($items as $item) {
			$json = ($item[FlowItems::JSON] ?? []);
			if (is_array($json) === false) {
				$json = [];
			}

			$own = trim((string)($json['uuid'] ?? ($json['id'] ?? '')));
			$uuid = $own;
			if (is_string($configured) === true && trim($configured) !== '') {
				$uuid = trim((string)FlowValueTemplate::render($configured, $json));
			}

			if ($uuid === '') {
				throw new RuntimeException($this->l10n->t('A case step could not work out which object the case is from the item it received.'));
			}

			$self = ($json['@self'] ?? []);
			if (is_array($self) === false) {
				$self = [];
			}

			// The register and schema in `@self` belong to the RECEIVED item. A configured `uuid` may name another
			// object (a parent, a related record); anchoring that object's plan to the item's register and schema
			// would make a later ensure refuse the anchor and every read that filters on them miss the rows, so
			// such a plan is anchored to neither.
			$ownObject = ($uuid === $own);
			$targets[$uuid] = [
				'uuid' => $uuid,
				'register' => $this->anchorId(value: ($self['register'] ?? null), ownObject: $ownObject),
				'schema' => $this->anchorId(value: ($self['schema'] ?? null), ownObject: $ownObject),
			];
		}//end foreach

		return $targets;
	}//end targets()

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
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true) {
			return (int)$value;
		}

		return null;
	}//end intOrNull()
}//end class
