<?php

/**
 * OpenRegister Sync Record Resolver
 *
 * The conflict queue of a source and a person's resolution of each conflict.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
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

namespace OCA\OpenRegister\Service\Sync;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use Throwable;

/**
 * Lists a source's records and resolves its conflicts under the caller's own rights.
 *
 * `mappedData` is the harvested item through the source's mapping with the
 * protected fields removed and the provenance set: exactly what
 * `use-harvested` would write. It is computed when the queue is read, so it
 * follows a mapping edited after the item was queued. A resolution writes as
 * the caller, so the audit trail names the person who decided, and appends
 * itself to the record's `resolutions`.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
class SyncRecordResolver {

	/**
	 * Keep the local object; the record becomes shadowed.
	 */
	public const ACTION_KEEP_LOCAL = 'keep-local';

	/**
	 * Write the harvested item; the record becomes imported.
	 */
	public const ACTION_USE_HARVESTED = 'use-harvested';

	/**
	 * Take each named field from the local or the harvested side; the record becomes imported.
	 */
	public const ACTION_MERGE = 'merge';

	/**
	 * Drop the harvested item; the record becomes rejected.
	 */
	public const ACTION_DISCARD = 'discard';

	/**
	 * Every resolution action.
	 */
	public const ACTIONS = [self::ACTION_KEEP_LOCAL, self::ACTION_USE_HARVESTED, self::ACTION_MERGE, self::ACTION_DISCARD];

	/**
	 * Constructor.
	 *
	 * @param SyncRecordMapperInterface  $records     The sync records.
	 * @param HarvestImportServices      $import      The mapping lookup and the object service.
	 * @param HarvestPipelineService     $pipeline    Maps and guards a harvested item exactly as the import does.
	 * @param PermissionHandlerInterface $permissions Answers whether the caller may change an object.
	 * @param SchemaMapperInterface      $schemas     The schema an object's rights are read from.
	 */
	public function __construct(
		private readonly SyncRecordMapperInterface $records,
		private readonly HarvestImportServices $import,
		private readonly HarvestPipelineService $pipeline,
		private readonly PermissionHandlerInterface $permissions,
		private readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()

	/**
	 * The source's latest record per external id, with mappedData on every conflict.
	 *
	 * @param Source      $source  The source.
	 * @param string|null $status  Only records in this status, or null for all.
	 * @param bool        $isAdmin Whether the caller sees every record; otherwise only those whose object they may update.
	 *
	 * @return list<array<string, mixed>> The records.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function queue(Source $source, ?string $status, bool $isAdmin): array {
		$rows = [];
		foreach ($this->records->findLatestBySource(sourceId: (int)$source->getId(), status: $status) as $record) {
			if ($isAdmin === false && $this->mayUpdateLinked(source: $source, record: $record) === false) {
				continue;
			}

			$row = $record->jsonSerialize();
			if ($record->getStatus() === SyncRecordStatus::CONFLICT) {
				$row = array_replace($row, $this->mappedOf(source: $source, record: $record));
			}

			$rows[] = $row;
		}

		return $rows;
	}//end queue()

	/**
	 * The harvested item as use-harvested would write it, or the mapping's failure.
	 *
	 * @param Source     $source The source.
	 * @param SyncRecord $record The record.
	 *
	 * @return array{mappedData: array<string, mixed>|null, mappingError?: string}
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function mappedOf(Source $source, SyncRecord $record): array {
		try {
			$mapping = null;
			if ($source->getMappingId() !== null) {
				$mapping = $this->import->mappingMapper->find($source->getMappingId());
			}

			return [
				'mappedData' => $this->pipeline->writablePayload(
					source: $source,
					mapping: $mapping,
					raw: ($record->getRawData() ?? []),
					externalId: (string)$record->getExternalId()
				),
			];
		} catch (Throwable $e) {
			return ['mappedData' => null, 'mappingError' => $e->getMessage()];
		}
	}//end mappedOf()

	/**
	 * Resolve one conflict as the caller.
	 *
	 * @param Source               $source  The source.
	 * @param SyncRecord           $record  The conflict.
	 * @param array<string, mixed> $request `action`, and `fields` (property => local|harvested) for a merge.
	 * @param string               $userId  The caller, recorded as the resolver.
	 * @param bool                 $bulk    Whether this is one item of a bulk resolution.
	 *
	 * @return SyncRecord The settled record.
	 *
	 * @throws ResolutionRefused 409 when the record is no conflict, 422 for a bad action, 403 when the caller may not change the object.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function resolve(Source $source, SyncRecord $record, array $request, string $userId, bool $bulk=false): SyncRecord {
		if ($record->getStatus() !== SyncRecordStatus::CONFLICT) {
			throw new ResolutionRefused(status: 409, message: 'Only a conflict can be resolved; this record is '.$record->getStatus());
		}

		$action = (string)($request['action'] ?? '');
		if (in_array($action, self::ACTIONS, true) === false) {
			throw new ResolutionRefused(status: 422, message: 'action must be one of '.implode(', ', self::ACTIONS));
		}

		$fields = self::mergeFields(action: $action, fields: ($request['fields'] ?? []));
		$local  = $this->localObject(source: $source, record: $record);
		if ($this->mayChange(source: $source, local: $local) === false) {
			throw new ResolutionRefused(status: 403, message: 'You may not change the object this record is linked to');
		}

		$status = $this->apply(source: $source, record: $record, local: $local, action: $action, fields: $fields);

		$record->setResolutions([
			...($record->getResolutions() ?? []),
			[
				'resolvedBy' => $userId,
				'resolvedAt' => (new DateTime())->format(DATE_ATOM),
				'action' => $action,
				'fields' => $fields,
				'objectUuid' => $record->getObjectUuid(),
				'bulk' => $bulk,
			],
		]);

		return $this->records->transitionStatus(record: $record, newStatus: $status);
	}//end resolve()

	/**
	 * A merge's field choices; empty for every other action.
	 *
	 * @param string $action The action.
	 * @param mixed  $fields The requested fields.
	 *
	 * @return array<string, string> Property => local|harvested.
	 *
	 * @throws ResolutionRefused 422 when a merge names no fields or a side that is neither.
	 */
	private static function mergeFields(string $action, mixed $fields): array {
		if ($action !== self::ACTION_MERGE) {
			return [];
		}

		if (is_array($fields) === false || $fields === []) {
			throw new ResolutionRefused(status: 422, message: 'A merge needs fields: property => local or harvested');
		}

		$choices = [];
		foreach ($fields as $property => $side) {
			if (in_array($side, ['local', 'harvested'], true) === false) {
				throw new ResolutionRefused(status: 422, message: 'fields.'.$property.' must be local or harvested');
			}

			$choices[(string)$property] = $side;
		}

		return $choices;
	}//end mergeFields()

	/**
	 * Carry out the action and return the status the record settles in.
	 *
	 * @param Source                $source The source.
	 * @param SyncRecord            $record The conflict.
	 * @param ObjectEntity|null     $local  The linked local object, or null when there is none.
	 * @param string                $action The action.
	 * @param array<string, string> $fields A merge's field choices.
	 *
	 * @return string The new status.
	 *
	 * @throws ResolutionRefused 422 when the harvested item cannot be mapped.
	 */
	private function apply(Source $source, SyncRecord $record, ?ObjectEntity $local, string $action, array $fields): string {
		if ($action === self::ACTION_KEEP_LOCAL) {
			return SyncRecordStatus::SHADOWED;
		}

		if ($action === self::ACTION_DISCARD) {
			$record->setRawData(null);
			return SyncRecordStatus::REJECTED;
		}

		$mapped = $this->mappedOf(source: $source, record: $record);
		if ($mapped['mappedData'] === null) {
			throw new ResolutionRefused(status: 422, message: 'The harvested item cannot be mapped: '.($mapped['mappingError'] ?? ''));
		}

		$payload = $mapped['mappedData'];
		if ($action === self::ACTION_MERGE) {
			$payload = $this->merged(source: $source, local: $local, mapped: $payload, fields: $fields, externalId: (string)$record->getExternalId());
		}

		$saved = $this->import->objectService->saveObject(
			object: $payload,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $source->getTargetRegister(), schema: $source->getTargetSchema()),
				uuid: $local?->getUuid()
			)
		);

		$record->setObjectUuid((string)($saved->getUuid() ?? $local?->getUuid()));
		$record->setLastAppliedAt(new DateTime());
		$record->setConflictReason(null);
		$record->setRawData(null);

		return SyncRecordStatus::IMPORTED;
	}//end apply()

	/**
	 * The local object with the fields marked harvested taken from the mapped item.
	 *
	 * Protected fields and the provenance properties never come from the
	 * harvested side: a protected field keeps its local value, and the
	 * provenance properties take the source's own values.
	 *
	 * @param Source                $source     The source.
	 * @param ObjectEntity|null     $local      The local object.
	 * @param array<string, mixed>  $mapped     The mapped, guarded item.
	 * @param array<string, string> $fields     Property => local|harvested.
	 * @param string                $externalId The external id.
	 *
	 * @return array<string, mixed> The payload to write.
	 */
	private function merged(Source $source, ?ObjectEntity $local, array $mapped, array $fields, string $externalId): array {
		$payload = ($local?->getObject() ?? []);
		unset($payload['@self'], $payload['id']);

		$protected = HarvestPipelineService::protectedFieldsOf(source: $source);
		foreach ($fields as $property => $side) {
			if ($side !== 'harvested' || in_array($property, $protected, true) === true) {
				continue;
			}

			if (array_key_exists($property, $mapped) === true) {
				$payload[$property] = $mapped[$property];
				continue;
			}

			unset($payload[$property]);
		}

		// The provenance properties only: guarded() over an empty payload yields
		// just those, so the local values of protected fields stay as they are.
		return array_replace($payload, HarvestPipelineService::guarded(source: $source, payload: [], externalId: $externalId));
	}//end merged()

	/**
	 * The record's local object, read without an audit entry; null when it has none or it is gone.
	 *
	 * @param Source     $source The source.
	 * @param SyncRecord $record The record.
	 *
	 * @return ObjectEntity|null
	 */
	private function localObject(Source $source, SyncRecord $record): ?ObjectEntity {
		$uuid = trim((string)$record->getObjectUuid());
		if ($uuid === '') {
			return null;
		}

		try {
			return $this->import->objectService->find(
				id: $uuid,
				options: new ObjectFindOptions(register: $source->getTargetRegister(), schema: $source->getTargetSchema(), audit: false)
			);
		} catch (Throwable) {
			return null;
		}
	}//end localObject()

	/**
	 * Whether the record links an object the caller may update; a record without one is not theirs to see.
	 *
	 * @param Source     $source The source.
	 * @param SyncRecord $record The record.
	 *
	 * @return bool
	 */
	private function mayUpdateLinked(Source $source, SyncRecord $record): bool {
		$local = $this->localObject(source: $source, record: $record);

		return $local !== null && $this->mayChange(source: $source, local: $local);
	}//end mayUpdateLinked()

	/**
	 * Whether the caller may update the local object, or create one in the target schema when there is none.
	 *
	 * Fails closed: a right that cannot be asked is a right not held.
	 *
	 * @param Source            $source The source.
	 * @param ObjectEntity|null $local  The local object.
	 *
	 * @return bool
	 */
	private function mayChange(Source $source, ?ObjectEntity $local): bool {
		try {
			if ($local === null) {
				return $this->permissions->hasPermission(schema: $this->schemas->find(id: (string)$source->getTargetSchema()), action: 'create');
			}

			return $this->permissions->hasPermission(
				schema: $this->schemas->find(id: (int)$local->getSchema()),
				action: 'update',
				objectOwner: $local->getOwner(),
				object: $local
			);
		} catch (Throwable) {
			return false;
		}
	}//end mayChange()
}//end class
