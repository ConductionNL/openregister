<?php

/**
 * OpenRegister HarvestSourceNode
 *
 * The flow step that runs one source's harvest pipeline.
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
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Kernel\Contracts\SourceHarvesterInterface;
use OCA\OpenRegister\Kernel\Contracts\SourceHarvestRefused;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\ICallFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

/**
 * Runs the harvest pipeline of the source its config names, once per firing.
 *
 * Everything but the source id is read from the source at run time, so
 * editing a source needs no flow rewrite. A disabled source, a type with no
 * registered fetcher and a source with no mapping end the run as failed with
 * that reason; a run that finds the source's lock held ends as skipped with
 * `already-running`. The step emits one item: the run summary.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
class HarvestSourceNode implements ICallFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy, HarvestSourceNodeInterface {

	/**
	 * The node type.
	 */
	public const TYPE = SourceHarvesterInterface::NODE_TYPE;

	/**
	 * The source is switched off.
	 */
	public const REASON_DISABLED = SourceHarvestRefused::DISABLED;

	/**
	 * No fetcher is registered for the source's type (its app is disabled).
	 */
	public const REASON_FETCHER_MISSING = SourceHarvestRefused::FETCHER_MISSING;

	/**
	 * The source names no mapping.
	 */
	public const REASON_MAPPING_MISSING = SourceHarvestRefused::MAPPING_MISSING;

	/**
	 * Another run of the same source holds its lock.
	 */
	public const REASON_ALREADY_RUNNING = SourceHarvestRefused::ALREADY_RUNNING;

	/**
	 * Constructor.
	 *
	 * @param SourceHarvesterInterface $harvester Runs the source's harvest (Integrations).
	 * @param IL10N                    $l10n      Translations.
	 * @param IURLGenerator            $urls      Builds the icon URL.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function __construct(
		private readonly SourceHarvesterInterface $harvester,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The node type.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * The palette name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Harvest a source');
	}//end getDisplayName()

	/**
	 * The palette description.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Read a source and bring its records into the register, with its mapping, conflict and delete settings.');
	}//end getDescription()

	/**
	 * The palette icon.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/download.svg');
	}//end getIcon()

	/**
	 * Available to administrators and users alike; the source is admin-managed.
	 *
	 * @param int $scope The workflow scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config keys.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function configKeys(): array {
		return ['sourceId'];
	}//end configKeys()

	/**
	 * The config form.
	 *
	 * @return list<array<string, string>>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'sourceId',
				'label' => $this->l10n->t('Source'),
				'type' => 'text',
				'help' => $this->l10n->t('The uuid or id of the source to harvest.'),
			],
		];
	}//end configForm()

	/**
	 * Refuse a config without a source.
	 *
	 * @param array<string, mixed> $config The config.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no source is named.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function validateConfig(array $config): void {
		$sourceId = ($config['sourceId'] ?? null);
		if ((is_string($sourceId) === false && is_int($sourceId) === false) || trim((string)$sourceId) === '') {
			throw new UnexpectedValueException($this->l10n->t('A harvest step needs the source it harvests.'));
		}
	}//end validateConfig()

	/**
	 * Run the source's harvest once and emit its summary.
	 *
	 * @param FlowNodeCall $call The items, config and run context.
	 *
	 * @return list<array<string, mixed>> One item, the run summary; none when no item arrived.
	 *
	 * @throws FlowStop When the source cannot be harvested, or another run holds it.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		if ($call->items === []) {
			return [];
		}

		$this->validateConfig(config: $call->config);

		$context     = $call->context;
		$executionId = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($executionId === '') {
			$executionId = (string)Uuid::v4();
		}

		try {
			return [$this->harvester->harvest(sourceId: trim((string)$call->config['sourceId']), executionId: $executionId)];
		} catch (SourceHarvestRefused $refused) {
			throw new FlowStop(reason: $refused->getMessage(), isError: $refused->isError());
		}
	}//end execute()

	/**
	 * The BPMN kind: a service task.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;
	}//end getKind()

	/**
	 * The palette category.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_INTEGRATIONS;
	}//end getCategory()
}//end class
