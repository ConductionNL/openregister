<?php

/**
 * MigrationHandler
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

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use Exception;

/**
 * Handles object migration between schemas and registers.
 *
 * This handler is responsible for:
 * - Migrating objects from one schema to another
 * - Moving objects between registers
 * - Preserving relationships during migration
 * - Batch migration operations
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class MigrationHandler
{
    /**
     * Constructor for MigrationHandler.
     *
     * @param ObjectEntityMapper      $objectMapper            Mapper for object entities.
     * @param SchemaMapper            $schemaMapper            Mapper for schema entities.
     * @param RegisterMapper          $registerMapper          Mapper for register entities.
     * @param SaveObject              $saveHandler             Handler for saving objects.
     * @param UtilityHandler          $utilityHandler          Handler for utility operations.
     * @param DataManipulationHandler $dataManipulationHandler Handler for data manipulation.
     * @param LoggerInterface         $logger                  Logger for logging operations.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SaveObject $saveHandler,
        private readonly UtilityHandler $utilityHandler,
        private readonly DataManipulationHandler $dataManipulationHandler,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Migrate objects between registers and/or schemas.
     *
     * This method migrates multiple objects from one register/schema combination
     * to another register/schema combination with property mapping.
     *
     * @param string|int $sourceRegister The source register ID or slug.
     * @param string|int $sourceSchema   The source schema ID or slug.
     * @param string|int $targetRegister The target register ID or slug.
     * @param string|int $targetSchema   The target schema ID or slug.
     * @param array      $objectIds      Array of object IDs to migrate.
     * @param array      $mapping        Simple mapping where keys are target properties, values are source properties.
     *
     * @return (((bool|mixed|null|string)[]|int|string)[]|bool)[] Migration report with success status, statistics, details, warnings, and errors.
     *
     * @throws OcpDoesNotExistException If register or schema not found.
     * @throws InvalidArgumentException If invalid parameters provided.
     *
     * @psalm-return array{success: bool,
     *     statistics: array{objectsMigrated: 0|1|2, objectsFailed: int,
     *     propertiesMapped: int<0, max>, propertiesDiscarded: int<min, max>},
     *     details: list<array{error: null|string, newObjectId?: null|string,
     *     objectId: mixed|null|string, objectTitle: null|string, success: bool}>,
     *     warnings: list<'Some objects failed to migrate. '.
     *     'Check details for specific errors.'>,
     *     errors: list<non-empty-string>}
     */
    public function migrateObjects(
        string|int $sourceRegister,
        string|int $sourceSchema,
        string|int $targetRegister,
        string|int $targetSchema,
        array $objectIds,
        array $mapping
    ): array {
        // Initialize migration report.
        $migrationReport = [
            'success'    => false,
            'statistics' => [
                'objectsMigrated'     => 0,
                'objectsFailed'       => 0,
                'propertiesMapped'    => 0,
                'propertiesDiscarded' => 0,
            ],
            'details'    => [],
            'warnings'   => [],
            'errors'     => [],
        ];

        try {
            // Load source and target registers/schemas.
            $sourceRegisterEntity = $this->utilityHandler->normalizeEntity(entity: $sourceRegister, type: 'register');
            $sourceSchemaEntity   = $this->utilityHandler->normalizeEntity(entity: $sourceSchema, type: 'schema');
            $targetRegisterEntity = $this->utilityHandler->normalizeEntity(entity: $targetRegister, type: 'register');
            $targetSchemaEntity   = $this->utilityHandler->normalizeEntity(entity: $targetSchema, type: 'schema');

            // Validate entities exist.
            if ($sourceRegisterEntity === null || $sourceSchemaEntity === null || $targetRegisterEntity === null || $targetSchemaEntity === null) {
                throw new OcpDoesNotExistException('One or more registers/schemas not found');
            }

            // Get all source objects at once using ObjectEntityMapper.
            $sourceObjects = $this->objectMapper->findMultiple($objectIds);

            // Keep track of remaining object IDs to find which ones weren't found.
            $remainingObjectIds = $objectIds;

            // Process each found source object.
            foreach ($sourceObjects as $sourceObject) {
                $objectId     = $sourceObject->getUuid();
                $objectDetail = [
                    'objectId'    => $objectId,
                    'objectTitle' => null,
                    'success'     => false,
                    'error'       => null,
                ];

                // Remove this object from the remaining list (it was found) - do this BEFORE try-catch.
                $remainingObjectIds = array_filter(
                    $remainingObjectIds,
                    function ($id) use ($sourceObject) {
                        return $id !== $sourceObject->getUuid() && $id !== $sourceObject->getId();
                    }
                );

                try {
                    $objectDetail['objectTitle'] = $sourceObject->getName() ?? $sourceObject->getUuid();

                    // Verify the source object belongs to the expected register/schema (cast to int for comparison).
                    if ((int) $sourceObject->getRegister() !== (int) $sourceRegister
                        || (int) $sourceObject->getSchema() !== (int) $sourceSchema
                    ) {
                        $actualRegister = $sourceObject->getRegister();
                        $actualSchema   = $sourceObject->getSchema();
                        $expected       = "register='{$sourceRegister}', schema='{$sourceSchema}'";
                        $actual         = "register='{$actualRegister}', schema='{$actualSchema}'";
                        $message        = sprintf(
                            "Object %s does not belong to the specified source register/schema. Expected: %s. Actual: %s",
                            $objectId,
                            $expected,
                            $actual
                        );
                        throw new InvalidArgumentException($message);
                    }

                    // Get source object data (the JSON object property).
                    $sourceData = $sourceObject->getObject();

                    // Map properties according to mapping configuration.
                    $mappedData = $this->dataManipulationHandler->mapObjectProperties(sourceData: $sourceData, mapping: $mapping);
                    $migrationReport['statistics']['propertiesMapped']    += count($mappedData);
                    $migrationReport['statistics']['propertiesDiscarded'] += (count($sourceData) - count($mappedData));

                    // Log the mapping result for debugging.
                    $this->logger->debug(
                        message: 'Object properties mapped',
                        context: [
                            'mappedData' => $mappedData,
                        ]
                    );

                    // Store original files and relations before altering the object.
                    $originalFiles     = $sourceObject->getFolder();
                    $originalRelations = $sourceObject->getRelations();

                    // Alter the existing object to migrate it to the target register/schema.
                    $sourceObject->setRegister($targetRegisterEntity->getId());

                    $sourceObject->setSchema($targetSchemaEntity->getId());

                    $sourceObject->setObject($mappedData);

                    // Update the object using the mapper.
                    $savedObject = $this->objectMapper->update($sourceObject);

                    // Handle file migration (files should already be attached to the object).
                    if ($originalFiles !== null) {
                        // Files are already associated with this object, no migration needed.
                    }

                    // Handle relations migration (relations are already on the object).
                    if (empty($originalRelations) === false) {
                        // Relations are preserved on the object, no additional migration needed.
                    }

                    $objectDetail['success']     = true;
                    $objectDetail['newObjectId'] = $savedObject->getUuid();
                    // Same UUID, but migrated.
                    $migrationReport['statistics']['objectsMigrated']++;
                } catch (Exception $e) {
                    $objectDetail['error'] = $e->getMessage();
                    $migrationReport['statistics']['objectsFailed']++;
                    $migrationReport['errors'][] = "Failed to migrate object {$objectId}: ".$e->getMessage();
                }//end try

                $migrationReport['details'][] = $objectDetail;
            }//end foreach

            // Handle objects that weren't found.
            foreach ($remainingObjectIds as $notFoundId) {
                $objectDetail = [
                    'objectId'    => $notFoundId,
                    'objectTitle' => null,
                    'success'     => false,
                    'error'       => "Object with ID {$notFoundId} not found",
                ];

                $migrationReport['details'][] = $objectDetail;
                $migrationReport['statistics']['objectsFailed']++;
                $migrationReport['errors'][] = "Failed to migrate object {$notFoundId}: Object not found";
            }

            // Set overall success if at least one object was migrated.
            $migrationReport['success'] = $migrationReport['statistics']['objectsMigrated'] > 0;

            // Add warnings if some objects failed.
            if ($migrationReport['statistics']['objectsFailed'] > 0) {
                $migrationReport['warnings'][] = "Some objects failed to migrate. Check details for specific errors.";
            }
        } catch (Exception $e) {
            $migrationReport['errors'][] = $e->getMessage();

            throw $e;
        }//end try

        return $migrationReport;
    }//end migrateObjects()
}//end class
