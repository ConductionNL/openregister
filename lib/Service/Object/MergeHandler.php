<?php

/**
 * MergeHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\FileService;
use OCP\IUserSession;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use InvalidArgumentException;
use Exception;

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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class MergeHandler
{


    /**
     * Constructor for MergeHandler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entities.
     * @param FileService        $fileService        Service for file operations.
     * @param IUserSession       $userSession        User session for tracking deletions.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession
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
     * @param array  $mergeData      Merge configuration containing:
     *                               - target: Target object ID
     *                               - object: Properties to merge
     *                               - fileAction: 'transfer' or 'delete'
     *                               - relationAction: 'transfer' or 'drop'
     *
     * @return array Merge report with success status, statistics, and details.
     *
     * @throws OcpDoesNotExistException If source or target object not found.
     * @throws InvalidArgumentException If objects are in different register/schema.
     *
     * @psalm-param    array<string, mixed> $mergeData
     * @phpstan-param  array<string, mixed> $mergeData
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function mergeObjects(string $sourceObjectId, array $mergeData): array
    {
        // Extract parameters from merge data.
        $targetObjectId = $mergeData['target'] ?? null;
        $mergedData     = $mergeData['object'] ?? [];
        $fileAction     = $mergeData['fileAction'] ?? 'transfer';
        $relationAction = $mergeData['relationAction'] ?? 'transfer';

        if ($targetObjectId === null || $targetObjectId === '') {
            throw new InvalidArgumentException('Target object ID is required');
        }

        // Initialize merge report.
        $mergeReport = [
            'success'      => false,
            'sourceObject' => null,
            'targetObject' => null,
            'mergedObject' => null,
            'actions'      => [
                'properties' => [],
                'files'      => [],
                'relations'  => [],
                'references' => [],
            ],
            'statistics'   => [
                'propertiesChanged'    => 0,
                'filesTransferred'     => 0,
                'filesDeleted'         => 0,
                'relationsTransferred' => 0,
                'relationsDropped'     => 0,
                'referencesUpdated'    => 0,
            ],
            'warnings'     => [],
            'errors'       => [],
        ];

        try {
            // Fetch both objects directly from mapper for updating (not rendered).
            try {
                $sourceObject = $this->objectEntityMapper->find($sourceObjectId);
            } catch (Exception $e) {
                $sourceObject = null;
            }

            try {
                $targetObject = $this->objectEntityMapper->find($targetObjectId);
            } catch (Exception $e) {
                $targetObject = null;
            }

            if ($sourceObject === null) {
                throw new OcpDoesNotExistException('Source object not found');
            }

            if ($targetObject === null) {
                throw new OcpDoesNotExistException('Target object not found');
            }

            // Store original objects in report.
            $mergeReport['sourceObject'] = $sourceObject->jsonSerialize();
            $mergeReport['targetObject'] = $targetObject->jsonSerialize();

            // Validate objects are in same register and schema.
            if ($sourceObject->getRegister() !== $targetObject->getRegister()) {
                throw new InvalidArgumentException('Objects must be in the same register');
            }

            if ($sourceObject->getSchema() !== $targetObject->getSchema()) {
                throw new InvalidArgumentException('Objects must conform to the same schema');
            }

            // Merge properties.
            $targetObjectData  = $targetObject->getObject();
            $changedProperties = [];

            foreach ($mergedData as $property => $value) {
                $oldValue = $targetObjectData[$property] ?? null;

                if ($oldValue !== $value) {
                    $targetObjectData[$property] = $value;
                    $changedProperties[]         = [
                        'property' => $property,
                        'oldValue' => $oldValue,
                        'newValue' => $value,
                    ];
                    $mergeReport['statistics']['propertiesChanged']++;
                }
            }

            $mergeReport['actions']['properties'] = $changedProperties;

            // Handle files.
            if ($fileAction === 'transfer' && $sourceObject->getFolder() !== null) {
                try {
                    $fileResult = $this->transferObjectFiles(sourceObject: $sourceObject, targetObject: $targetObject);
                    $mergeReport['actions']['files'] = $fileResult['files'];
                    $mergeReport['statistics']['filesTransferred'] = $fileResult['transferred'];

                    if (empty($fileResult['errors']) === false) {
                        $mergeReport['warnings'] = array_merge($mergeReport['warnings'], $fileResult['errors']);
                    }
                } catch (Exception $e) {
                    $mergeReport['warnings'][] = 'Failed to transfer files: '.$e->getMessage();
                }
            } else if ($fileAction === 'delete' && $sourceObject->getFolder() !== null) {
                try {
                    $deleteResult = $this->deleteObjectFiles($sourceObject);
                    $mergeReport['actions']['files']           = $deleteResult['files'];
                    $mergeReport['statistics']['filesDeleted'] = $deleteResult['deleted'];

                    if (empty($deleteResult['errors']) === false) {
                        $mergeReport['warnings'] = array_merge($mergeReport['warnings'], $deleteResult['errors']);
                    }
                } catch (Exception $e) {
                    $mergeReport['warnings'][] = 'Failed to delete files: '.$e->getMessage();
                }
            }//end if

            // Handle relations.
            if ($relationAction === 'transfer') {
                $sourceRelations = $sourceObject->getRelations();
                $targetRelations = $targetObject->getRelations();

                $transferredRelations = [];
                foreach ($sourceRelations ?? [] as $relation) {
                    if (in_array($relation, $targetRelations) === false) {
                        $targetRelations[]      = $relation;
                        $transferredRelations[] = $relation;
                        $mergeReport['statistics']['relationsTransferred']++;
                    }
                }

                $targetObject->setRelations($targetRelations);
                $mergeReport['actions']['relations'] = [
                    'action'    => 'transferred',
                    'relations' => $transferredRelations,
                ];
            } else {
                $mergeReport['actions']['relations']           = [
                    'action'    => 'dropped',
                    'relations' => $sourceObject->getRelations(),
                ];
                $mergeReport['statistics']['relationsDropped'] = count($sourceObject->getRelations());
            }//end if

            // Update target object with merged data.
            $targetObject->setObject($targetObjectData);
            $updatedObject = $this->objectEntityMapper->update($targetObject);

            // Update references to source object.
            $referencingObjects = $this->objectEntityMapper->findByRelation(
                search: $sourceObject->getUuid(),
                partialMatch: true
            );
            $updatedReferences  = [];

            foreach ($referencingObjects as $referencingObject) {
                $relations     = $referencingObject->getRelations();
                $updated       = false;
                $relationCount = count($relations);

                for ($i = 0; $i < $relationCount; $i++) {
                    if ($relations[$i] === $sourceObject->getUuid()) {
                        $relations[$i] = $targetObject->getUuid();
                        $updated       = true;
                        $mergeReport['statistics']['referencesUpdated']++;
                    }
                }

                if ($updated === true) {
                    $referencingObject->setRelations($relations);
                    $this->objectEntityMapper->update($referencingObject);
                    $updatedReferences[] = [
                        'objectId' => $referencingObject->getUuid(),
                        'title'    => $referencingObject->getTitle() ?? $referencingObject->getUuid(),
                    ];
                }
            }//end foreach

            $mergeReport['actions']['references'] = $updatedReferences;

            // Soft delete source object using the entity's delete method.
            $sourceObject->delete(
                userSession: $this->userSession,
                deletedReason: 'Merged into object '.$targetObject->getUuid()
            );
            $this->objectEntityMapper->update($sourceObject);

            // Set success and add merged object to report.
            $mergeReport['success']      = true;
            $mergeReport['mergedObject'] = $updatedObject->jsonSerialize();

            // Merge completed successfully.
        } catch (Exception $e) {
            // Handle merge error.
            $mergeReport['errors'][] = "Merge failed: ".$e->getMessage();
            $mergeReport['errors'][] = $e->getMessage();
            throw $e;
        }//end try

        return $mergeReport;

    }//end mergeObjects()


    /**
     * Transfer files from source object to target object.
     *
     * Files are copied to the target object and then deleted from the source.
     *
     * @param ObjectEntity $sourceObject The source object.
     * @param ObjectEntity $targetObject The target object.
     *
     * @return array Transfer result with files, transferred count, and errors.
     *
     * @psalm-return   array{files: list<array<string, mixed>>, transferred: int, errors: list<string>}
     * @phpstan-return array{files: list<array<string, mixed>>, transferred: int, errors: list<string>}
     */
    private function transferObjectFiles(ObjectEntity $sourceObject, ObjectEntity $targetObject): array
    {
        $result = [
            'files'       => [],
            'transferred' => 0,
            'errors'      => [],
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
                    $fileName    = $file->getName();

                    // Create new file in target object folder.
                    $this->fileService->addFile(
                        objectEntity: $targetObject,
                        fileName: $fileName,
                        content: $fileContent,
                        share: false,
                        tags: []
                    );

                    // Delete original file from source.
                    $this->fileService->deleteFile(file: $file, object: $sourceObject);

                    $result['files'][] = [
                        'name'    => $fileName,
                        'action'  => 'transferred',
                        'success' => true,
                    ];
                    $result['transferred']++;
                } catch (Exception $e) {
                    $result['files'][]  = [
                        'name'    => $file->getName(),
                        'action'  => 'transfer_failed',
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ];
                    $result['errors'][] = 'Failed to transfer file '.$file->getName().': '.$e->getMessage();
                }//end try
            }//end foreach
        } catch (Exception $e) {
            $result['errors'][] = 'Failed to access source files: '.$e->getMessage();
        }//end try

        return $result;

    }//end transferObjectFiles()


    /**
     * Delete all files from an object.
     *
     * @param ObjectEntity $sourceObject The source object.
     *
     * @return array Deletion result with files, deleted count, and errors.
     *
     * @psalm-return   array{files: list<array<string, mixed>>, deleted: int, errors: list<string>}
     * @phpstan-return array{files: list<array<string, mixed>>, deleted: int, errors: list<string>}
     */
    private function deleteObjectFiles(ObjectEntity $sourceObject): array
    {
        $result = [
            'files'   => [],
            'deleted' => 0,
            'errors'  => [],
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

                    // Delete the file using FileService.
                    $this->fileService->deleteFile(file: $file, object: $sourceObject);

                    $result['files'][] = [
                        'name'    => $fileName,
                        'action'  => 'deleted',
                        'success' => true,
                    ];
                    $result['deleted']++;
                } catch (Exception $e) {
                    $result['files'][]  = [
                        'name'    => $file->getName(),
                        'action'  => 'delete_failed',
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ];
                    $result['errors'][] = 'Failed to delete file '.$file->getName().': '.$e->getMessage();
                }//end try
            }//end foreach
        } catch (Exception $e) {
            $result['errors'][] = 'Failed to access source files: '.$e->getMessage();
        }//end try

        return $result;

    }//end deleteObjectFiles()


}//end class
