<?php

/**
 * ConfigurationFlowTransfer: the flow engine's side of configuration export and import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Kernel\Contracts\ConfigurationFlowTransferInterface;
use Psr\Log\LoggerInterface;

/**
 * Exports a configuration's flows by uuid and imports `components.flows` entries,
 * for the Configuration area's ExportHandler and ImportHandler.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class ConfigurationFlowTransfer implements ConfigurationFlowTransferInterface {

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface     $flowMapper         Resolves, inserts and updates flows.
	 * @param LoggerInterface         $logger             Logs skipped and failed flows.
	 * @param FlowVersionService|null $flowVersionService Publishes an imported flow; null leaves it a draft.
	 */
	public function __construct(
		private readonly FlowMapperInterface $flowMapper,
		private readonly LoggerInterface $logger,
		private readonly ?FlowVersionService $flowVersionService = null,
	) {
	}//end __construct()

	/**
	 * The flows behind the given uuids, keyed by uuid, without instance-specific properties.
	 *
	 * Unlike mappings, a flow has no slug: `uuid` is kept (not stripped) so
	 * the importer can resolve an existing flow on the target instance via
	 * {@see \OCA\OpenRegister\Db\FlowMapper::findByUuid()}.
	 *
	 * A flow that cannot be loaded is logged and left out.
	 *
	 * @param array<int|string, mixed> $flowUuids The uuids a configuration lists.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function exportFlows(array $flowUuids): array {
		$flows = [];
		foreach ($flowUuids as $flowUuid) {
			try {
				$flow = $this->flowMapper->findByUuid($flowUuid);
				$flowArray = $flow->jsonSerialize();
				unset(
					$flowArray['id'],
					$flowArray['owner'],
					$flowArray['organisation']
				);

				$flows[$flow->getUuid()] = $flowArray;
			} catch (Exception $e) {
				$this->logger->warning(
					message: '[ExportHandler] Failed to export flow',
					context: [
						'flowUuid' => $flowUuid,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $flows;
	}//end exportFlows()

	/**
	 * Import a single flow from configuration data.
	 *
	 * Resolves by UUID, not slug: a flow has no slug field, which is why
	 * {@see self::exportFlows()}
	 * keeps `uuid` rather than stripping it the way exportMappings() does.
	 *
	 * This does NOT version_compare a semver string the way importMapping()
	 * does. A flow's `version` is the TARGET instance's own definition-version
	 * ordinal, derived by {@see FlowVersionService}
	 * from its own version history; an ordinal exported from the source
	 * instance means nothing here and must never be written verbatim. Instead
	 * an existing flow is only touched when its graph actually differs from
	 * the import, or $force is set — the same signal
	 * {@see \OCA\OpenRegister\Service\Flow\FlowService::save()} uses to decide
	 * whether a definition changed.
	 *
	 * @param array       $data         The flow data from the JSON config.
	 * @param string|null $owner        The owner stamped on a newly created flow.
	 * @param string|null $organisation The organisation stamped on a newly created flow.
	 * @param bool        $force        Re-publish even when the graph is unchanged.
	 *
	 * @return Flow|null The imported flow entity, or null if skipped.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag to override the unchanged-graph skip
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importFlow(array $data, ?string $owner = null, ?string $organisation = null, bool $force = false): ?Flow {
		$uuid = trim((string)($data['uuid'] ?? ''));
		if ($uuid === '') {
			$this->logger->warning(
				message: '[ConfigurationFlowTransfer] Flow has no uuid — skipping',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		$existingFlow = $this->findFlowByUuid(uuid: $uuid);
		if ($existingFlow !== null
			&& $force === false
			&& $this->flowGraphUnchanged(existing: $existingFlow, data: $data) === true
		) {
			return $existingFlow;
		}

		$flow = $this->flowToMutate(existingFlow: $existingFlow, uuid: $uuid, owner: $owner, organisation: $organisation);
		$this->applyImportedFlowFields(flow: $flow, data: $data);
		$flow->setUpdated(new DateTime());

		return $this->publishImportedFlow(flow: $this->persistFlow(flow: $flow, isNew: ($existingFlow === null)));
	}//end importFlow()

	/**
	 * The flow an import writes fields onto: a fresh one, or the caller's existing one opened for editing.
	 *
	 * Extracted so the create/update branch is two returns rather than an
	 * if/else, the same shape {@see \OCA\OpenRegister\Service\Flow\FlowService::persistFlow()}
	 * uses and for the same reason: the `ElseExpression` rule is on in phpmd.xml.
	 *
	 * @param Flow|null $existingFlow The flow already stored under this uuid, or null to create.
	 * @param string $uuid The uuid a new flow is stamped with; ignored for an existing flow.
	 * @param string|null $owner The owner stamped on a newly created flow.
	 * @param string|null $organisation The organisation stamped on a newly created flow.
	 *
	 * @return Flow The flow to apply imported fields to.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function flowToMutate(?Flow $existingFlow, string $uuid, ?string $owner, ?string $organisation): Flow {
		if ($existingFlow === null) {
			$flow = new Flow();
			$flow->setUuid($uuid);
			$flow->setOwner($owner);
			$flow->setOrganisation($organisation);
			$flow->setCreated(new DateTime());
			return $flow;
		}

		return $this->ensureFlowIsDraft(flow: $existingFlow);
	}//end flowToMutate()

	/**
	 * Insert a new flow, or update the existing one.
	 *
	 * @param Flow $flow The flow to write.
	 * @param bool $isNew True to insert, false to update.
	 *
	 * @return Flow The stored flow, as the mapper returned it.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors FlowService::persistFlow(); see its docblock.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function persistFlow(Flow $flow, bool $isNew): Flow {
		if ($isNew === true) {
			return $this->flowMapper->insert(entity: $flow);
		}

		return $this->flowMapper->update(entity: $flow);
	}//end persistFlow()

	/**
	 * Resolve an existing flow by uuid, or null when there is none.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow|null The flow, or null when not found.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function findFlowByUuid(string $uuid): ?Flow {
		try {
			return $this->flowMapper->findByUuid(uuid: $uuid);
		} catch (Exception $e) {
			$this->logger->debug(
				message: '[ConfigurationFlowTransfer] No existing flow for uuid, will create new',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end findFlowByUuid()

	/**
	 * Whether an existing flow's graph already matches the imported data.
	 *
	 * Compares the same four keys {@see \OCA\OpenRegister\Service\Flow\FlowService}'s
	 * own `graphSignature()` compares, so "nothing to do" here means the same
	 * thing it means on the authoring API.
	 *
	 * @param Flow $existing The stored flow.
	 * @param array $data The imported flow data.
	 *
	 * @return bool True when the graph is unchanged.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function flowGraphUnchanged(Flow $existing, array $data): bool {
		$signature = static function (array $nodes, array $edges, array $limits, string $executionMode): string {
			return (string)json_encode([
				'nodes' => $nodes,
				'edges' => $edges,
				'limits' => $limits,
				'executionMode' => $executionMode,
			]);
		};

		$existingSignature = $signature(
			(array)($existing->getNodes() ?? []),
			(array)($existing->getEdges() ?? []),
			(array)($existing->getLimits() ?? []),
			(string)($existing->getExecutionMode() ?? Flow::MODE_ASYNC)
		);

		$importedSignature = $signature(
			(array)($data['nodes'] ?? []),
			(array)($data['edges'] ?? []),
			(array)($data['limits'] ?? []),
			(string)($data['executionMode'] ?? Flow::MODE_ASYNC)
		);

		return $existingSignature === $importedSignature;
	}//end flowGraphUnchanged()

	/**
	 * Open a draft on a published flow so its definition can be edited.
	 *
	 * A flow already on a draft head is left alone: calling createDraft()
	 * again would open a second draft layer on top of unpublished changes
	 * instead of editing them.
	 *
	 * @param Flow $flow The flow to prepare for editing.
	 *
	 * @return Flow The flow, with a draft head.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function ensureFlowIsDraft(Flow $flow): Flow {
		if ($flow->getLifecycleStatus() === FlowVersion::STATUS_DRAFT) {
			return $flow;
		}

		if ($this->flowVersionService === null) {
			$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);
			return $flow;
		}

		try {
			$this->flowVersionService->createDraft(flow: $flow);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ConfigurationFlowTransfer] Could not open a draft for flow "' . $flow->getUuid() . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return $flow;
	}//end ensureFlowIsDraft()

	/**
	 * Copy the importable fields from JSON config data onto a flow.
	 *
	 * Mirrors {@see \OCA\OpenRegister\Service\Flow\FlowService}'s own
	 * `applyEditableFields()` allowlist, plus `app` and `annotations` (graph
	 * metadata an import needs to carry that the authoring API does not
	 * expose as an edit). `owner`, `organisation` and `uuid` are deliberately
	 * absent here too — they are set once, on creation, in importFlow().
	 *
	 * @param Flow $flow The flow to mutate.
	 * @param array $data The imported flow data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function applyImportedFlowFields(Flow $flow, array $data): void {
		$strings = [
			'name' => 'setName',
			'description' => 'setDescription',
			'app' => 'setApp',
			'applicationSlug' => 'setApplicationSlug',
			'trigger' => 'setTrigger',
			'triggerRegister' => 'setTriggerRegister',
			'triggerSchema' => 'setTriggerSchema',
			'cron' => 'setCron',
			'executionMode' => 'setExecutionMode',
			'notes' => 'setNotes',
			'comment' => 'setComment',
		];

		foreach ($strings as $key => $setter) {
			if (array_key_exists($key, $data) === true) {
				$value = $data[$key];
				if ($value === null) {
					$flow->$setter(null);
					continue;
				}

				$flow->$setter((string)$value);
			}
		}

		$arrays = [
			'nodes' => 'setNodes',
			'edges' => 'setEdges',
			'annotations' => 'setAnnotations',
			'limits' => 'setLimits',
		];

		foreach ($arrays as $key => $setter) {
			if (array_key_exists($key, $data) === true) {
				$flow->$setter((array)($data[$key] ?? []));
			}
		}

		if (array_key_exists('enabled', $data) === true) {
			$flow->setEnabled((bool)$data['enabled']);
		}

		// Mirrors FlowService::applyInheritableSettings(): null is stored, not
		// skipped, since it means "inherit the instance default" rather than
		// "field absent".
		$inheritable = [
			'retentionDays' => ['setRetentionDays', static fn ($value) => max(1, (int)$value)],
			'auditEnabled' => ['setAuditEnabled', static fn ($value) => (bool)$value],
		];

		foreach ($inheritable as $key => [$setter, $cast]) {
			if (array_key_exists($key, $data) === false) {
				continue;
			}

			$value = $data[$key];
			if ($value === null) {
				$flow->$setter(null);
				continue;
			}

			$flow->$setter($cast($value));
		}
	}//end applyImportedFlowFields()

	/**
	 * Publish an imported flow's draft head through the validated publish path.
	 *
	 * A flow the publish guard refuses (a dead-end node, or any other refusal)
	 * stays a draft and the reason is logged; an import never marks a flow
	 * published past that guard.
	 *
	 * @param Flow $flow The flow to publish.
	 *
	 * @return Flow The flow, published when the guard accepts it, otherwise still a draft.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function publishImportedFlow(Flow $flow): Flow {
		if ($this->flowVersionService === null) {
			return $flow;
		}

		try {
			$this->flowVersionService->publish(flow: $flow);
		} catch (FlowLifecycleRefused $e) {
			$this->logger->warning(
				message: '[ConfigurationFlowTransfer] Imported flow "' . $flow->getUuid() . '" stays a draft; publish refused: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'reason' => $e->getReason()]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ConfigurationFlowTransfer] Publishing imported flow "' . $flow->getUuid() . '" failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return $flow;
	}//end publishImportedFlow()
}//end class
