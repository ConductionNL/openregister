<?php
/**
 * OpenRegister Application Service
 *
 * This file contains the service class for managing applications.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * ApplicationService handles application management operations
 *
 * Service for managing applications, handling business logic, validation,
 * and database operations for application entities.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git-id>
 *
 * @link     https://OpenRegister.app
 */
class ApplicationService
{

    /**
     * Application mapper for database operations
     *
     * Handles all database CRUD operations for application entities.
     *
     * @var ApplicationMapper Application mapper instance
     */
    private readonly ApplicationMapper $applicationMapper;

    /**
     * Logger instance
     *
     * Used for logging application operations, errors, and debug information.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * Initializes service with required dependencies for application operations.
     *
     * @param ApplicationMapper $applicationMapper Application mapper for database operations
     * @param LoggerInterface   $logger            Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        ApplicationMapper $applicationMapper,
        LoggerInterface $logger
    ) {
        // Store dependencies for use in service methods.
        $this->applicationMapper = $applicationMapper;
        $this->logger            = $logger;
    }//end __construct()


    /**
     * Get all applications
     *
     * Retrieves a list of all applications with optional pagination and filtering.
     * Supports limit/offset pagination and custom filter conditions.
     *
     * @param int|null $limit   Maximum number of results to return (null for all)
     * @param int|null $offset  Number of results to skip for pagination (null for no offset)
     * @param array    $filters Filter conditions as key-value pairs (default: empty array)
     *
     * @return Application[] Array of application entities
     *
     * @psalm-return array<int, Application>
     */
    public function findAll(?int $limit = null, ?int $offset = null, array $filters = []): array
    {
        // Delegate to mapper to retrieve applications with pagination and filters.
        return $this->applicationMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters
        );
    }//end findAll()


    /**
     * Get a single application by ID
     *
     * Retrieves a specific application entity by its database ID.
     * Throws exception if application does not exist.
     *
     * @param int $id Application database ID
     *
     * @return Application The application entity
     *
     * @throws DoesNotExistException If application not found with the given ID
     *
     * @psalm-return Application
     */
    public function find(int $id): Application
    {
        // Delegate to mapper to find application by ID.
        return $this->applicationMapper->find($id);
    }//end find()


    /**
     * Create a new application
     *
     * Creates a new application entity from the provided data array.
     * Logs the creation process for audit and debugging purposes.
     *
     * @param array<string, mixed> $data Application data as key-value pairs
     *
     * @return Application The created application entity with assigned ID
     *
     * @psalm-return Application
     */
    public function create(array $data): Application
    {
        // Log creation attempt with provided data.
        $this->logger->info(
            message: 'Creating new application',
            context: ['data' => $data]
        );

        // Create application entity from data array using mapper.
        $application = $this->applicationMapper->createFromArray(data: $data);

        // Log successful creation with assigned ID.
        $this->logger->info(
            message: 'Application created successfully',
            context: ['id' => $application->getId()]
        );

        return $application;
    }//end create()


    /**
     * Update an existing application
     *
     * Updates an existing application entity with new data.
     * Throws exception if application does not exist.
     * Logs the update process for audit and debugging purposes.
     *
     * @param int                $id   Application database ID
     * @param array<string, mixed> $data Application data as key-value pairs to update
     *
     * @return Application The updated application entity
     *
     * @throws DoesNotExistException If application not found with the given ID
     *
     * @psalm-return Application
     */
    public function update(int $id, array $data): Application
    {
        // Log update attempt with ID and data.
        $this->logger->info(
            message: 'Updating application',
            context: ['id' => $id, 'data' => $data]
        );

        // Update application entity using mapper.
        $application = $this->applicationMapper->updateFromArray(id: $id, data: $data);

        // Log successful update.
        $this->logger->info(
            message: 'Application updated successfully',
            context: ['id' => $id]
        );

        return $application;
    }//end update()


    /**
     * Delete an application
     *
     * Deletes an application entity by ID.
     * First retrieves the entity to ensure it exists, then deletes it.
     * Throws exception if application does not exist.
     * Logs the deletion process for audit purposes.
     *
     * @param int $id Application database ID
     *
     * @return void
     *
     * @throws DoesNotExistException If application not found with the given ID
     */
    public function delete(int $id): void
    {
        // Log deletion attempt.
        $this->logger->info(
            message: 'Deleting application',
            context: ['id' => $id]
        );

        // Find application to ensure it exists (throws exception if not found).
        $application = $this->applicationMapper->find($id);

        // Delete the application entity.
        $this->applicationMapper->delete($application);

        // Log successful deletion.
        $this->logger->info(
            message: 'Application deleted successfully',
            context: ['id' => $id]
        );
    }//end delete()


    /**
     * Count total applications
     *
     * Returns the total number of applications in the system.
     * Useful for pagination calculations and statistics.
     *
     * @return int Total number of applications
     *
     * @psalm-return int
     */
    public function countAll(): int
    {
        // Delegate to mapper to count all applications.
        return $this->applicationMapper->countAll();
    }//end countAll()


}//end class
