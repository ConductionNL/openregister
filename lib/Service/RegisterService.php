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
     * Register mapper
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * File service
     *
     * @var FileService
     */
    private FileService $fileService;

    /**
     * Organisation service
     *
     * @var OrganisationService
     */
    private OrganisationService $organisationService;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param RegisterMapper      $registerMapper      Register mapper
     * @param FileService         $fileService         File service
     * @param OrganisationService $organisationService Organisation service
     * @param LoggerInterface     $logger              Logger
     */
    public function __construct(
        RegisterMapper $registerMapper,
        FileService $fileService,
        OrganisationService $organisationService,
        LoggerInterface $logger
    ) {
        $this->registerMapper      = $registerMapper;
        $this->fileService         = $fileService;
        $this->organisationService = $organisationService;
        $this->logger = $logger;

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
            limit: $limit,
            offset: $offset,
            filters: $filters,
            searchConditions: $searchConditions,
            searchParams: $searchParams,
            _extend: $extend
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
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Register $register): Register
    {
        return $this->registerMapper->delete($register);

    }//end delete()


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

                    $this->logger->info(message: "Created folder with ID {$folderNode->getId()} for register {$entity->getId()}");
                } else {
                    $this->logger->warning(message: "Failed to create folder for register {$entity->getId()}");
                }
            } catch (Exception $e) {
                // Log the error but don't fail the register creation/update.
                // The register can still function without a folder.
                $this->logger->error(message: "Failed to create folder for register {$entity->getId()}: ".$e->getMessage());
            }
        }//end if

    }//end ensureRegisterFolderExists()


}//end class
