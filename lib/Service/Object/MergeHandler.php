<?php

/**
 * MergeHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\MagicMapperInterface;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use OCP\IUserSession;

/**
 * Handles object merging operations for ObjectService.
 *
 * This handler is responsible for:
 * - Merging two objects (properties, files, relations)
 * - Transferring files between objects
 * - Deleting object files
 * - Updating references to merged objects
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class MergeHandler {
	/**
	 * Constructor for MergeHandler.
	 *
	 * @param MagicMapperInterface<ObjectEntity, Register, Schema> $objectEntityMapper Mapper for object entities.
	 * @param FileService $fileService Service for file operations.
	 * @param IUserSession $userSession User session for tracking deletions.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectEntityMapper,
		private readonly FileService $fileService,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Merge two objects together.
	 *
	 * This method merges a source object into a target object, handling:
	 * - Property merging
	 * - File transfer or deletion
	 * - Relation transfer or dropping
	 * - Reference updates in other objects
	 * - Soft deletion of source object
	 *
	 * @param string $sourceObjectId The ID of the source object to merge from.
	 * @param array $mergeData Merge configuration containing:
	 *                         - target: Target object ID
	 *                         - object: Properties to merge
	 *                         - fileAction: 'transfer' or 'delete'
	 *                         - relationAction: 'transfer' or 'drop'
	 *
	 * @return array Merge report with source, target, merged objects, actions, statistics, warnings, errors.
	 *
	 * @throws OcpDoesNotExistException If source or target object not found.
	 * @throws InvalidArgumentException If objects are in different register/schema.
	 *
	 * @psalm-param array<string, mixed> $mergeData
	 *
	 * @phpstan-param array<string, mixed> $mergeData
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function mergeObjects(string $sourceObjectId, array $mergeData): array {
		$targetObjectId = $mergeData['target'] ?? null;
		if ($targetObjectId === null || $targetObjectId === '') {
			throw new InvalidArgumentException('Target object ID is required');
		}

		$mergeReport = MergeRun::EMPTY_REPORT;

		try {
			$run = new MergeRun(
				pair: $this->loadMergePair(sourceObjectId: $sourceObjectId, targetObjectId: $targetObjectId),
				options: $mergeData
			);
			$run->report['sourceObject'] = $run->source->jsonSerialize();
			$run->report['targetObject'] = $run->target->jsonSerialize();
			$this->assertSameRegisterAndSchema(run: $run);

			$this->applyMerge(run: $run);
			$mergeReport = $run->report;
		} catch (Exception $e) {
			$mergeReport['errors'][] = 'Merge failed: ' . $e->getMessage();
			$mergeReport['errors'][] = $e->getMessage();
			throw $e;
		}//end try

		return $mergeReport;
	}//end mergeObjects()

	/**
	 * Merge the source into the target: properties, files, relations, references, and
	 * the soft delete of the source.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return void
	 */
	private function applyMerge(MergeRun $run): void {
		$targetObjectData = $this->mergeProperties(run: $run);
		$this->mergeFiles(run: $run);
		$this->mergeRelations(run: $run);

		$run->target->setObject($targetObjectData);
		$updatedObject = $this->objectEntityMapper->update(entity: $run->target, register: $run->register, schema: $run->schema);

		$updatedReferences = [];
		if ($run->option(name: 'referenceAction', default: 'transfer') === 'transfer') {
			$updatedReferences = $this->redirectReferences(run: $run);
		}

		$run->report['statistics']['referencesUpdated'] = count($updatedReferences);
		$run->report['actions']['references']           = $updatedReferences;

		$this->softDeleteMergedSource(run: $run);

		$run->report['success']      = true;
		$run->report['mergedObject'] = $updatedObject->jsonSerialize();
	}//end applyMerge()

	/**
	 * Find the source and the target across all sources, without RBAC or multitenancy.
	 *
	 * Both are looked up before either is checked.
	 *
	 * @param string $sourceObjectId The source object id
	 * @param mixed  $targetObjectId The target object id
	 *
	 * @return array{source: ObjectEntity, target: ObjectEntity, register: mixed, schema: mixed}
	 *
	 * @throws OcpDoesNotExistException If the source or the target is not found
	 */
	private function loadMergePair(string $sourceObjectId, mixed $targetObjectId): array {
		$sourceContext = $this->findMergeContext(identifier: $sourceObjectId);
		$targetContext = $this->findMergeContext(identifier: $targetObjectId);

		if ($sourceContext === null) {
			throw new OcpDoesNotExistException('Source object not found');
		}

		if ($targetContext === null) {
			throw new OcpDoesNotExistException('Target object not found');
		}

		return [
			'source'   => $sourceContext['object'],
			'target'   => $targetContext['object'],
			'register' => $targetContext['register'],
			'schema'   => $targetContext['schema'],
		];
	}//end loadMergePair()

	/**
	 * An object with its register and schema, or null when it cannot be found.
	 *
	 * @param mixed $identifier The object id
	 *
	 * @return array|null
	 */
	private function findMergeContext(mixed $identifier): ?array {
		try {
			return $this->objectEntityMapper->findAcrossAllSources(
				identifier: $identifier,
				includeDeleted: false,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Exception $e) {
			return null;
		}
	}//end findMergeContext()

	/**
	 * Refuse a merge across registers or schemas.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the objects are in different registers or schemas
	 */
	private function assertSameRegisterAndSchema(MergeRun $run): void {
		if ($run->source->getRegister() !== $run->target->getRegister()) {
			throw new InvalidArgumentException('Objects must be in the same register');
		}

		if ($run->source->getSchema() !== $run->target->getSchema()) {
			throw new InvalidArgumentException('Objects must conform to the same schema');
		}
	}//end assertSameRegisterAndSchema()

	/**
	 * The target's data with the merged properties applied; each change is reported.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return array|null The target's merged data
	 */
	private function mergeProperties(MergeRun $run): ?array {
		$targetObjectData  = $run->target->getObject();
		$changedProperties = [];

		foreach ($run->option(name: 'object', default: []) as $property => $value) {
			$oldValue = $targetObjectData[$property] ?? null;

			if ($oldValue !== $value) {
				$targetObjectData[$property] = $value;
				$changedProperties[]         = [
					'property' => $property,
					'oldValue' => $oldValue,
					'newValue' => $value,
				];
			}
		}

		$run->report['statistics']['propertiesChanged'] = count($changedProperties);
		$run->report['actions']['properties']           = $changedProperties;

		return $targetObjectData;
	}//end mergeProperties()

	/**
	 * Transfer or delete the source's files, when it has a folder.
	 *
	 * Both helpers catch every exception themselves and report it as an error, which
	 * becomes a warning here.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return void
	 */
	private function mergeFiles(MergeRun $run): void {
		if ($run->source->getFolder() === null) {
			return;
		}

		$fileAction = $run->option(name: 'fileAction', default: 'transfer');
		if ($fileAction === 'transfer') {
			$fileResult = $this->transferObjectFiles(sourceObject: $run->source, targetObject: $run->target);
			$run->report['actions']['files'] = $fileResult['files'];
			$run->report['statistics']['filesTransferred'] = $fileResult['transferred'];
			$run->report['warnings'] = array_merge($run->report['warnings'], $fileResult['errors']);
		}

		if ($fileAction === 'delete') {
			$deleteResult = $this->deleteObjectFiles(sourceObject: $run->source);
			$run->report['actions']['files'] = $deleteResult['files'];
			$run->report['statistics']['filesDeleted'] = $deleteResult['deleted'];
			$run->report['warnings'] = array_merge($run->report['warnings'], $deleteResult['errors']);
		}
	}//end mergeFiles()

	/**
	 * Add the source's relations to the target, or report them as dropped.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return void
	 */
	private function mergeRelations(MergeRun $run): void {
		if ($run->option(name: 'relationAction', default: 'transfer') !== 'transfer') {
			$run->report['actions']['relations'] = [
				'action' => 'dropped',
				'relations' => $run->source->getRelations(),
			];
			$run->report['statistics']['relationsDropped'] = count($run->source->getRelations());

			return;
		}

		$targetRelations      = $run->target->getRelations();
		$transferredRelations = [];
		foreach ($run->source->getRelations() ?? [] as $relation) {
			if (in_array($relation, $targetRelations) === false) {
				$targetRelations[]      = $relation;
				$transferredRelations[] = $relation;
			}
		}

		$run->target->setRelations($targetRelations);
		$run->report['statistics']['relationsTransferred'] = count($transferredRelations);
		$run->report['actions']['relations'] = [
			'action' => 'transferred',
			'relations' => $transferredRelations,
		];
	}//end mergeRelations()

	/**
	 * Point every object that references the source at the target, and save the ones that changed.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return list<array{objectId: string|null, title: string|null}> The updated references
	 */
	private function redirectReferences(MergeRun $run): array {
		$sourceUuid = $run->source->getUuid();

		$referencingObjects = $this->objectEntityMapper->findByRelation(
			uuid: $sourceUuid,
			_search: $sourceUuid,
			_partialMatch: true
		);

		$updatedReferences = [];
		foreach ($referencingObjects as $referencingObject) {
			if ($this->redirectReference(object: $referencingObject, run: $run) === false) {
				continue;
			}

			$updatedReferences[] = $this->saveRedirectedReference(object: $referencingObject);
		}

		return $updatedReferences;
	}//end redirectReferences()

	/**
	 * Save a redirected referencing object in its own register and schema.
	 *
	 * @param ObjectEntity $object The referencing object
	 *
	 * @return array{objectId: string|null, title: string|null} The updated reference
	 */
	private function saveRedirectedReference(ObjectEntity $object): array {
		$refContext = $this->objectEntityMapper->findAcrossAllSources(
			identifier: $object->getUuid(),
			includeDeleted: false,
			_rbac: false,
			_multitenancy: false
		);
		$this->objectEntityMapper->update(
			entity: $object,
			register: $refContext['register'],
			schema: $refContext['schema']
		);

		return [
			'objectId' => $object->getUuid(),
			'title' => $object->getName() ?? $object->getUuid(),
		];
	}//end saveRedirectedReference()

	/**
	 * Replace the source uuid with the target uuid in one object's relations and data.
	 *
	 * @param ObjectEntity $object The referencing object
	 * @param MergeRun     $run    The merge
	 *
	 * @return bool Whether the relations or the data changed
	 */
	private function redirectReference(ObjectEntity $object, MergeRun $run): bool {
		$relations        = $object->getRelations();
		$relationsUpdated = $this->replaceSourceUuid(values: $relations, run: $run);
		if ($relationsUpdated === true) {
			$object->setRelations($relations);
		}

		$objectData = $object->getObject();
		if (is_array($objectData) === false) {
			return $relationsUpdated;
		}

		if ($this->replaceSourceUuid(values: $objectData, run: $run) === false) {
			return $relationsUpdated;
		}

		$object->setObject($objectData);

		return true;
	}//end redirectReference()

	/**
	 * Replace every leaf equal to the source uuid with the target uuid, at any depth.
	 *
	 * @param array    $values The values, changed in place
	 * @param MergeRun $run    The merge
	 *
	 * @return bool Whether anything was replaced
	 */
	private function replaceSourceUuid(array &$values, MergeRun $run): bool {
		$from     = $run->source->getUuid();
		$to       = $run->target->getUuid();
		$replaced = false;
		array_walk_recursive(
			$values,
			function (&$value) use ($from, $to, &$replaced) {
				if ($value === $from) {
					$value    = $to;
					$replaced = true;
				}
			}
		);

		return $replaced;
	}//end replaceSourceUuid()

	/**
	 * Soft delete the source as merged into the target, in the target's register and schema.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return void
	 */
	private function softDeleteMergedSource(MergeRun $run): void {
		$run->source->delete(
			userSession: $this->userSession,
			deletedReason: 'Merged into object ' . $run->target->getUuid()
		);
		$this->objectEntityMapper->update(
			entity: $run->source,
			register: $run->register,
			schema: $run->schema
		);
	}//end softDeleteMergedSource()

	/**
	 * Transfer files from source object to target object.
	 *
	 * Files are copied to the target object and then deleted from the source.
	 *
	 * @param ObjectEntity $sourceObject The source object.
	 * @param ObjectEntity $targetObject The target object.
	 *
	 * @return (((bool|string)[]|string)[]|int)[]
	 *
	 * @psalm-return   array{files: list<array{action: 'transfer_failed'|'transferred',
	 *     error?: string, name: string, success: bool}>,
	 *     transferred: 0|1|2, errors: list<non-falsy-string>}
	 * @phpstan-return array{files: list<array<string, mixed>>, transferred: int,
	 *     errors: list<string>}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) File transfer with error handling requires multiple conditions
	 */
	private function transferObjectFiles(ObjectEntity $sourceObject, ObjectEntity $targetObject): array {
		$result = [
			'files' => [],
			'transferred' => 0,
			'errors' => [],
		];

		try {
			// Get files from source folder.
			$sourceFiles = $this->fileService->getFiles($sourceObject);

			foreach ($sourceFiles as $file) {
				try {
					// Skip if not a file.
					if (($file instanceof \OCP\Files\File) === false) {
						continue;
					}

					// Get file content and create new file in target object.
					$fileContent = $file->getContent();
					$fileName = $file->getName();

					// Create new file in target object folder.
					$copy = $this->fileService->addFile(
						objectEntity: $targetObject,
						fileName: $fileName,
						content: $fileContent,
						share: false,
						tags: []
					);

					// Delete original file from source. A source file that is still
					// there afterwards (a frozen or archived source: the delete was
					// aborted and Nextcloud does not say so) is not a transfer: the
					// copy is taken back instead of reporting the file as moved.
					if ($this->fileService->deleteFile(file: $file, object: $sourceObject) === false) {
						$this->fileService->deleteFile(file: $copy, object: $targetObject);
						throw new Exception('The file could not be removed from the source object');
					}

					$result['files'][] = [
						'name' => $fileName,
						'action' => 'transferred',
						'success' => true,
					];
					$result['transferred']++;
				} catch (Exception $e) {
					$result['files'][] = [
						'name' => $file->getName(),
						'action' => 'transfer_failed',
						'success' => false,
						'error' => $e->getMessage(),
					];
					$result['errors'][] = 'Failed to transfer file ' . $file->getName() . ': ' . $e->getMessage();
				}//end try
			}//end foreach
		} catch (Exception $e) {
			$result['errors'][] = 'Failed to access source files: ' . $e->getMessage();
		}//end try

		return $result;
	}//end transferObjectFiles()

	/**
	 * Delete all files from an object.
	 *
	 * @param ObjectEntity $sourceObject The source object.
	 *
	 * @return (((bool|string)[]|string)[]|int)[]
	 *
	 * @psalm-return   array{files: list<array{action: 'delete_failed'|'deleted',
	 *     error?: string, name: string, success: bool}>,
	 *     deleted: 0|1|2, errors: list<non-falsy-string>}
	 * @phpstan-return array{files: list<array<string, mixed>>, deleted: int,
	 *     errors: list<string>}
	 */
	private function deleteObjectFiles(ObjectEntity $sourceObject): array {
		$result = [
			'files' => [],
			'deleted' => 0,
			'errors' => [],
		];

		try {
			// Get files from source folder.
			$sourceFiles = $this->fileService->getFiles($sourceObject);

			foreach ($sourceFiles as $file) {
				try {
					// Skip if not a file.
					if (($file instanceof \OCP\Files\File) === false) {
						continue;
					}

					$fileName = $file->getName();

					// Delete the file using FileService; false means it is still there.
					if ($this->fileService->deleteFile(file: $file, object: $sourceObject) === false) {
						throw new Exception('The file could not be deleted');
					}

					$result['files'][] = [
						'name' => $fileName,
						'action' => 'deleted',
						'success' => true,
					];
					$result['deleted']++;
				} catch (Exception $e) {
					$result['files'][] = [
						'name' => $file->getName(),
						'action' => 'delete_failed',
						'success' => false,
						'error' => $e->getMessage(),
					];
					$result['errors'][] = 'Failed to delete file ' . $file->getName() . ': ' . $e->getMessage();
				}//end try
			}//end foreach
		} catch (Exception $e) {
			$result['errors'][] = 'Failed to access source files: ' . $e->getMessage();
		}//end try

		return $result;
	}//end deleteObjectFiles()
}//end class
