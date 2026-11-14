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

use DateTime;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\ICallFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Sync\HarvestRun;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
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
	public const TYPE = 'openregister.harvest-source';

	/**
	 * The source is switched off.
	 */
	public const REASON_DISABLED = 'source-disabled';

	/**
	 * No fetcher is registered for the source's type (its app is disabled).
	 */
	public const REASON_FETCHER_MISSING = 'fetcher-missing';

	/**
	 * The source names no mapping.
	 */
	public const REASON_MAPPING_MISSING = 'mapping-missing';

	/**
	 * Another run of the same source holds its lock.
	 */
	public const REASON_ALREADY_RUNNING = 'already-running';

	/**
	 * Constructor.
	 *
	 * @param SourceMapperInterface           $sources  The sources.
	 * @param SourceFetcherLookupInterface    $fetchers The fetcher per source type.
	 * @param HarvestPipelineServiceInterface $pipeline The harvest pipeline.
	 * @param ILockingProvider                $locks    Nextcloud's locking provider, shared by every process.
	 * @param IL10N                           $l10n     Translations.
	 * @param IURLGenerator                   $urls     Builds the icon URL.
	 * @param LoggerInterface                 $logger   Logger.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function __construct(
		private readonly SourceMapperInterface $sources,
		private readonly SourceFetcherLookupInterface $fetchers,
		private readonly HarvestPipelineServiceInterface $pipeline,
		private readonly ILockingProvider $locks,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The lock key of a source.
	 *
	 * @param string $sourceUuid The source uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public static function lockKey(string $sourceUuid): string {
		return 'openregister/harvest/'.$sourceUuid;
	}//end lockKey()

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
		$source  = $this->sources->findForSystem(sourceId: trim((string)$call->config['sourceId']));
		$fetcher = $this->runnable(source: $source);

		$key = self::lockKey(sourceUuid: (string)$source->getUuid());
		try {
			$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new FlowStop(reason: self::REASON_ALREADY_RUNNING, isError: false);
		}

		try {
			return [$this->harvest(source: $source, fetcher: $fetcher, context: $call->context)];
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}//end execute()

	/**
	 * The source's fetcher, or the refusal that ends the run.
	 *
	 * @param Source|null $source The source, or null when it is gone.
	 *
	 * @return SourceFetcherInterface
	 *
	 * @throws FlowStop When the source is missing or disabled, has no fetcher or no mapping.
	 *
	 * @psalm-assert Source $source
	 */
	private function runnable(?Source $source): SourceFetcherInterface {
		if ($source === null || $source->getSyncEnabled() === false) {
			throw new FlowStop(reason: self::REASON_DISABLED, isError: true);
		}

		$fetcher = $this->fetchers->get((string)$source->getType());
		if ($fetcher === null) {
			throw new FlowStop(reason: self::REASON_FETCHER_MISSING, isError: true);
		}

		if ($source->getMappingId() === null) {
			throw new FlowStop(reason: self::REASON_MAPPING_MISSING, isError: true);
		}

		return $fetcher;
	}//end runnable()

	/**
	 * Run the pipeline and record its outcome on the source.
	 *
	 * @param Source                 $source  The source.
	 * @param SourceFetcherInterface $fetcher Its fetcher.
	 * @param array<string, mixed>   $context The run context.
	 *
	 * @return array<string, mixed> The run summary.
	 *
	 * @throws Throwable When the pipeline fails; the source records `failed` first.
	 */
	private function harvest(Source $source, SourceFetcherInterface $fetcher, array $context): array {
		$executionId = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($executionId === '') {
			$executionId = (string)Uuid::v4();
		}

		try {
			$summary = $this->pipeline->run(
				new HarvestRun(source: $source, fetcher: $fetcher, executionId: $executionId, since: $source->getLastSyncToken())
			);
		} catch (Throwable $e) {
			$this->record(source: $source, status: 'failed');
			throw $e;
		}

		$this->record(source: $source, status: (string)($summary['status'] ?? 'success'));

		return $summary;
	}//end harvest()

	/**
	 * Persist the run's outcome and moment on the source; the checkpoint was set by the pipeline.
	 *
	 * A failed write is logged, not thrown: the run itself, and its summary in
	 * the run log, already say how the harvest went.
	 *
	 * @param Source $source The source.
	 * @param string $status The outcome.
	 *
	 * @return void
	 */
	private function record(Source $source, string $status): void {
		$source->setLastSyncStatus($status);
		$source->setLastSyncDate(new DateTime());
		try {
			$this->sources->update($source);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[HarvestSourceNode] Could not record the last sync on source '.$source->getUuid().': '.$e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end record()

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
