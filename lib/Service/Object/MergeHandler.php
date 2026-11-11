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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use OCP\Files\File;
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
	 * The report statistic each file outcome counter goes into.
	 */
	private const FILE_STATISTICS = [
		'transferred' => 'filesTransferred',
		'deleted' => 'filesDeleted',
	];

	/**
	 * Report labels of a file transfer.
	 */
	private const FILE_TRANSFER = [
		'done' => 'transferred',
		'failed' => 'transfer_failed',
		'error' => 'Failed to transfer file ',
	];

	/**
	 * Report labels of a file delete.
	 */
	private const FILE_DELETE = [
		'done' => 'deleted',
		'failed' => 'delete_failed',
		'error' => 'Failed to delete file ',
	];
	/**
	 * Constructor for MergeHandler.
	 *
	 * @param MagicMapperInterface $objectEntityMapper Mapper for object entities.
	 * @param ObjectFilesInterface $fileService Service for file operations.
	 * @param IUserSession $userSession User session for tracking deletions.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectEntityMapper,
		private readonly ObjectFilesInterface $fileService,
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

		$run = new MergeRun(
			pair: $this->loadMergePair(sourceObjectId: $sourceObjectId, targetObjectId: $targetObjectId),
			options: $mergeData
		);
		$run->report['sourceObject'] = $run->source->jsonSerialize();
		$run->report['targetObject'] = $run->target->jsonSerialize();
		$this->assertSameRegisterAndSchema(run: $run);
		$this->applyMerge(run: $run);

		return $run->report;
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
		$this->mergeReferences(run: $run);
		$this->softDeleteMergedSource(run: $run);

		$run->report['success']      = true;
		$run->report['mergedObject'] = $updatedObject->jsonSerialize();
	}//end applyMerge()

	/**
	 * Point the objects that reference the source at the target, unless references are kept.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return void
	 */
	private function mergeReferences(MergeRun $run): void {
		$updatedReferences = [];
		if ($run->option(name: 'referenceAction', default: 'transfer') === 'transfer') {
			$updatedReferences = $this->redirectReferences(run: $run);
		}

		$run->report['statistics']['referencesUpdated'] = count($updatedReferences);
		$run->report['actions']['references']           = $updatedReferences;
	}//end mergeReferences()

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

		return ['source' => $sourceContext['object'], 'target' => $targetContext['object']]
			+ ['register' => $targetContext['register'], 'schema' => $targetContext['schema']];
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
				$changedProperties[]         = ['property' => $property, 'oldValue' => $oldValue, 'newValue' => $value];
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
			$result = $this->transferObjectFiles(sourceObject: $run->source, targetObject: $run->target);
			$this->reportFiles(run: $run, result: $result);
		}

		if ($fileAction === 'delete') {
			$result = $this->deleteObjectFiles(sourceObject: $run->source);
			$this->reportFiles(run: $run, result: $result);
		}
	}//end mergeFiles()

	/**
	 * Put the outcome of a file transfer or delete in the report; its errors become warnings.
	 *
	 * The outcome names its own counter (`transferred` or `deleted`), and
	 * FILE_STATISTICS names the statistic that counter goes into.
	 *
	 * @param MergeRun $run    The merge
	 * @param array    $result The outcome: files, the counter, errors
	 *
	 * @return void
	 */
	private function reportFiles(MergeRun $run, array $result): void {
		$counter = 'deleted';
		if (array_key_exists('transferred', $result) === true) {
			$counter = 'transferred';
		}

		$run->report['actions']['files'] = $result['files'];
		$run->report['statistics'][self::FILE_STATISTICS[$counter]] = $result[$counter];
		$run->report['warnings']               = array_merge($run->report['warnings'], $result['errors']);
	}//end reportFiles()

	/**
	 * Add the source's relations to the target, or report them as dropped.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return void
	 */
	private function mergeRelations(MergeRun $run): void {
		if ($run->option(name: 'relationAction', default: 'transfer') !== 'transfer') {
			$this->dropRelations(run: $run);
			return;
		}

		$targetRelations      = $run->target->getRelations();
		$transferredRelations = $this->appendMissingRelations(targetRelations: $targetRelations, sourceRelations: $run->source->getRelations() ?? []);
		$run->target->setRelations($targetRelations);
		$run->report['statistics']['relationsTransferred'] = count($transferredRelations);
		$run->report['actions']['relations'] = ['action' => 'transferred', 'relations' => $transferredRelations];
	}//end mergeRelations()

	/**
	 * Append each source relation the target does not hold yet; return the ones appended.
	 *
	 * @param array|null $targetRelations The target's relations, appended to in place.
	 * @param array      $sourceRelations The source's relations.
	 *
	 * @return array The relations appended.
	 */
	private function appendMissingRelations(?array &$targetRelations, array $sourceRelations): array {
		$transferred = [];
		foreach ($sourceRelations as $relation) {
			if (in_array($relation, $targetRelations) === false) {
				$targetRelations[] = $relation;
				$transferred[]     = $relation;
			}
		}

		return $transferred;
	}//end appendMissingRelations()

	/**
	 * Report the source's relations as dropped.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return void
	 */
	private function dropRelations(MergeRun $run): void {
		$run->report['actions']['relations'] = [
			'action' => 'dropped',
			'relations' => $run->source->getRelations(),
		];
		$run->report['statistics']['relationsDropped'] = count($run->source->getRelations());
	}//end dropRelations()

	/**
	 * Point every object that references the source at the target, and save the ones that changed.
	 *
	 * @param MergeRun $run The merge
	 *
	 * @return list<array{objectId: string|null, title: string|null}> The updated references
	 */
	private function redirectReferences(MergeRun $run): array {
		$sourceUuid = $run->source->getUuid();

		$updatedReferences = [];
		foreach ($this->objectEntityMapper->findByRelation(uuid: $sourceUuid, _search: $sourceUuid, _partialMatch: true) as $referencingObject) {
			if ($this->redirectReference(object: $referencingObject, run: $run) === false) {
				continue;
			}

			$updatedReferences[] = $this->saveRedirectedReference(uuid: (string)$referencingObject->getUuid(), run: $run);
		}

		return $updatedReferences;
	}//end redirectReferences()

	/**
	 * Redirect the stored row of a referencing object and save it in its own register and schema.
	 *
	 * The relation search fills only name and data, and an update writes the whole
	 * row, so the row that is written back is read here.
	 *
	 * @param string   $uuid The referencing object's uuid
	 * @param MergeRun $run  The merge
	 *
	 * @return array{objectId: string|null, title: string|null} The updated reference
	 */
	private function saveRedirectedReference(string $uuid, MergeRun $run): array {
		$refContext = $this->objectEntityMapper->findAcrossAllSources(identifier: $uuid, includeDeleted: false, _rbac: false, _multitenancy: false);
		$object     = $refContext['object'];
		$this->redirectReference(object: $object, run: $run);
		$this->objectEntityMapper->update(entity: $object, register: $refContext['register'], schema: $refContext['schema']);

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

		return $this->redirectData(object: $object, run: $run) || $relationsUpdated;
	}//end redirectReference()

	/**
	 * Replace the source uuid with the target uuid in one object's data.
	 *
	 * @param ObjectEntity $object The referencing object
	 * @param MergeRun     $run    The merge
	 *
	 * @return bool Whether the data changed
	 */
	private function redirectData(ObjectEntity $object, MergeRun $run): bool {
		$objectData = $object->getObject();
		if (is_array($objectData) === false || $this->replaceSourceUuid(values: $objectData, run: $run) === false) {
			return false;
		}

		$object->setObject($objectData);

		return true;
	}//end redirectData()

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
	 * @return array{files: list<array<string, mixed>>, transferred: int, errors: list<string>}
	 */
	private function transferObjectFiles(ObjectEntity $sourceObject, ObjectEntity $targetObject): array {
		$move    = new FileMove(source: $sourceObject, target: $targetObject);
		$outcome = $this->eachSourceFile(
			sourceObject: $sourceObject,
			run: new FileActionRun(
				labels: self::FILE_TRANSFER,
				apply: fn (File $file): string => $this->transferFile(file: $file, move: $move)
			)
		);

		return ['files' => $outcome['files'], 'transferred' => $outcome['count'], 'errors' => $outcome['errors']];
	}//end transferObjectFiles()

	/**
	 * Delete all files from an object.
	 *
	 * @param ObjectEntity $sourceObject The source object.
	 *
	 * @return array{files: list<array<string, mixed>>, deleted: int, errors: list<string>}
	 */
	private function deleteObjectFiles(ObjectEntity $sourceObject): array {
		$outcome = $this->eachSourceFile(
			sourceObject: $sourceObject,
			run: new FileActionRun(
				labels: self::FILE_DELETE,
				apply: fn (File $file): string => $this->deleteSourceFile(file: $file, sourceObject: $sourceObject)
			)
		);

		return ['files' => $outcome['files'], 'deleted' => $outcome['count'], 'errors' => $outcome['errors']];
	}//end deleteObjectFiles()

	/**
	 * Apply one file action to every file of the source, reporting each one.
	 *
	 * A failure to read the source's files is reported as one error.
	 *
	 * @param ObjectEntity  $sourceObject The source object.
	 * @param FileActionRun $run          The labels, the action, and the outcome it collects.
	 *
	 * @return array{files: list<array<string, mixed>>, count: int, errors: list<string>} The files, how many were done, the errors.
	 */
	private function eachSourceFile(ObjectEntity $sourceObject, FileActionRun $run): array {
		try {
			foreach ($this->fileService->getFiles($sourceObject) as $file) {
				if (($file instanceof File) === true) {
					$this->applyToFile(file: $file, run: $run);
				}
			}
		} catch (Exception $e) {
			$run->result['errors'][] = 'Failed to access source files: ' . $e->getMessage();
		}

		return $run->result;
	}//end eachSourceFile()

	/**
	 * Apply one file action to one file and report it as done or failed.
	 *
	 * @param File          $file The source file.
	 * @param FileActionRun $run  The labels, the action, and the outcome so far.
	 *
	 * @return void
	 */
	private function applyToFile(File $file, FileActionRun $run): void {
		$labels = $run->labels;
		try {
			$fileName = ($run->apply)($file);

			$run->result['files'][] = ['name' => $fileName, 'action' => $labels['done'], 'success' => true];
			$run->result['count']++;
		} catch (Exception $e) {
			$run->result['files'][]  = ['name' => $file->getName(), 'action' => $labels['failed'], 'success' => false, 'error' => $e->getMessage()];
			$run->result['errors'][] = $labels['error'] . $file->getName() . ': ' . $e->getMessage();
		}
	}//end applyToFile()

	/**
	 * Copy one file to the target, then delete it from the source.
	 *
	 * A source file that is still there afterwards (a frozen or archived source:
	 * the delete was aborted and Nextcloud does not say so) is not a transfer: the
	 * copy is taken back instead of reporting the file as moved.
	 *
	 * @param File     $file The source file.
	 * @param FileMove $move The source and the target object.
	 *
	 * @return string The file's name.
	 *
	 * @throws Exception When the file could not be copied or removed from the source.
	 */
	private function transferFile(File $file, FileMove $move): string {
		$sourceObject = $move->source;
		$targetObject = $move->target;
		$fileContent  = $file->getContent();
		$fileName    = $file->getName();

		$copy = $this->fileService->addFile(
			target: new FileTarget(object: $targetObject),
			upload: new FileUpload(fileName: $fileName, content: $fileContent, share: false)
		);
		if ($this->fileService->deleteFile(file: $file, object: $sourceObject) === false) {
			$this->fileService->deleteFile(file: $copy, object: $targetObject);
			throw new Exception('The file could not be removed from the source object');
		}

		return $fileName;
	}//end transferFile()

	/**
	 * Delete one file from the source; false from FileService means it is still there.
	 *
	 * @param File         $file         The source file.
	 * @param ObjectEntity $sourceObject The source object.
	 *
	 * @return string The file's name.
	 *
	 * @throws Exception When the file could not be deleted.
	 */
	private function deleteSourceFile(File $file, ObjectEntity $sourceObject): string {
		$fileName = $file->getName();
		if ($this->fileService->deleteFile(file: $file, object: $sourceObject) === false) {
			throw new Exception('The file could not be deleted');
		}

		return $fileName;
	}//end deleteSourceFile()
}//end class
