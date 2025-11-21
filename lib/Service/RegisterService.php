<?php
/**
 * OpenRegister RegisterService
 *
 * Service class for managing registers in the OpenRegister application.
 *
 * This service acts as a facade for register operations,
 * coordinating between RegisterMapper and FileService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use Psr\Log\LoggerInterface;

/**
 * Service class for managing registers in the OpenRegister application.
 *
 * This service acts as a facade for register operations,
 * coordinating between RegisterMapper and FileService.
 */
class RegisterService
{


    /**
     * Constructor for RegisterService.
     *
     * @param RegisterMapper      $registerMapper      Mapper for register operations.
     * @param FileService         $fileService         Service for file operations.
     * @param LoggerInterface     $logger              Logger for error handling.
     * @param OrganisationService $organisationService Service for organisation operations.
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly FileService $fileService,
        private readonly LoggerInterface $logger,
        private readonly OrganisationService $organisationService
    ) {

    }//end __construct()


    /**
     * Find a register by ID with optional extensions.
     *
     * @param int|string $id     The ID of the register to find
     * @param array      $extend Optional array of extensions
     *
     * @return Register The found register
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If register not found
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple found
     * @throws \OCP\DB\Exception If database error occurs
     */
    public function find(int | string $id, array $extend=[]): Register
    {
        return $this->registerMapper->find($id, $extend);

    }//end find()


    /**
     * Find multiple registers by IDs.
     *
     * @param array $ids The IDs of the registers to find
     *
     * @return array Array of found registers
     */
    public function findMultiple(array $ids): array
    {
        return $this->registerMapper->findMultiple($ids);

    }//end findMultiple()


    /**
     * Find all registers with optional filters and extensions.
     *
     * @param int|null   $limit            The limit of results
     * @param int|null   $offset           The offset of results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions Array of search conditions
     * @param array|null $searchParams     Array of search parameters
     * @param array      $extend           Optional extensions
     *
     * @return Register[] Array of found registers
     *
     * @psalm-return array<Register>
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        ?array $extend=[]
    ): array {
        return $this->registerMapper->findAll(
            $limit,
            $offset,
            $filters,
            $searchConditions,
            $searchParams,
            $extend
        );

    }//end findAll()


    /**
     * Create a new register from array data.
     *
     * @param array $data The data to create the register from
     *
     * @return Register The created register
     *
     * @throws Exception If register creation fails
     */
    public function createFromArray(array $data): Register
    {
        // Create the register first.
        $register = $this->registerMapper->createFromArray($data);

        // Set organisation from active organisation for multi-tenancy (if not already set).
        if ($register->getOrganisation() === null || $register->getOrganisation() === '') {
            $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
            $register->setOrganisation($organisationUuid);
            $register = $this->registerMapper->update($register);
        }

        // Ensure folder exists for the new register.
        $this->ensureRegisterFolderExists($register);

        return $register;

    }//end createFromArray()


    /**
     * Update an existing register from array data.
     *
     * @param int   $id   The ID of the register to update
     * @param array $data The new data for the register
     *
     * @return Register The updated register
     *
     * @throws Exception If register update fails
     */
    public function updateFromArray(int $id, array $data): Register
    {
        // Update the register first.
        $register = $this->registerMapper->updateFromArray($id, $data);

        // Ensure folder exists for the updated register (handles legacy folder properties).
        $this->ensureRegisterFolderExists($register);

        return $register;

    }//end updateFromArray()


    /**
     * Delete a register.
     *
     * @param Register $register The register to delete
     *
     * @return Register The deleted register
     *
     * @throws Exception If register has attached objects or deletion fails
     */
    public function delete(Register $register): Register
    {
        return $this->registerMapper->delete($register);

    }//end delete()


    /**
     * Get schemas associated with a register.
     *
     * @param int $registerId The ID of the register
     *
     * @return array Array of schemas
     */
    public function getSchemasByRegisterId(int $registerId): array
    {
        return $this->registerMapper->getSchemasByRegisterId($registerId);

    }//end getSchemasByRegisterId()


    /**
     * Get first register with a specific schema.
     *
     * @param int $schemaId The ID of the schema
     *
     * @return int|null The register ID or null if not found
     */
    public function getFirstRegisterWithSchema(int $schemaId): ?int
    {
        return $this->registerMapper->getFirstRegisterWithSchema($schemaId);

    }//end getFirstRegisterWithSchema()


    /**
     * Check if a register has a schema with specific title.
     *
     * @param int    $registerId  The ID of the register
     * @param string $schemaTitle The title of the schema
     *
     * @return \OCA\OpenRegister\Db\Schema|null The schema if found
     */
    public function hasSchemaWithTitle(int $registerId, string $schemaTitle): ?\OCA\OpenRegister\Db\Schema
    {
        return $this->registerMapper->hasSchemaWithTitle($registerId, $schemaTitle);

    }//end hasSchemaWithTitle()


    /**
     * Get ID to slug mappings.
     *
     * @return string[] Array mapping IDs to slugs
     *
     * @psalm-return array<string, string>
     */
    public function getIdToSlugMap(): array
    {
        return $this->registerMapper->getIdToSlugMap();

    }//end getIdToSlugMap()


    /**
     * Get slug to ID mappings.
     *
     * @return string[] Array mapping slugs to IDs
     *
     * @psalm-return array<string, string>
     */
    public function getSlugToIdMap(): array
    {
        return $this->registerMapper->getSlugToIdMap();

    }//end getSlugToIdMap()


    /**
     * Ensure folder exists for a Register.
     *
     * This method checks if the register has a valid folder ID and creates one if needed.
     * It handles legacy cases where the folder property might be null, empty, or a string path.
     *
     * @param Register $entity The register entity to ensure folder for
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    private function ensureRegisterFolderExists(Register $entity): void
    {
        $folderProperty = $entity->getFolder();

        // Check if folder needs to be created (null, empty string, or legacy string path).
        if ($folderProperty === null || $folderProperty === '' || is_string($folderProperty) === true) {
            try {
                // Create folder and get the folder node.
                $folderNode = $this->fileService->createEntityFolder($entity);

                if ($folderNode !== null) {
                    // Update the entity with the folder ID.
                    $entity->setFolder((string) $folderNode->getId());

                    // Save the entity with the new folder ID.
                    $this->registerMapper->update($entity);

                    $this->logger->info("Created folder with ID {$folderNode->getId()} for register {$entity->getId()}");
                } else {
                    $this->logger->warning("Failed to create folder for register {$entity->getId()}");
                }
            } catch (Exception $e) {
                // Log the error but don't fail the register creation/update.
                // The register can still function without a folder.
                $this->logger->error("Failed to create folder for register {$entity->getId()}: ".$e->getMessage());
            }
        }//end if

    }//end ensureRegisterFolderExists()


}//end class
