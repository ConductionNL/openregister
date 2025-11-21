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
 * ApplicationService
 *
 * Manages applications, handling business logic and validation.
 *
 * @package OCA\OpenRegister\Service
 */
class ApplicationService
{

    /**
     * Application mapper for database operations
     *
     * @var ApplicationMapper
     */
    private ApplicationMapper $applicationMapper;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor for ApplicationService
     *
     * @param ApplicationMapper $applicationMapper The application mapper
     * @param LoggerInterface   $logger            The logger instance
     */
    public function __construct(
        ApplicationMapper $applicationMapper,
        LoggerInterface $logger
    ) {
        $this->applicationMapper = $applicationMapper;
        $this->logger            = $logger;

    }//end __construct()


    /**
     * Get all applications
     *
     * @param int|null $limit   Maximum number of results
     * @param int|null $offset  Offset for pagination
     * @param array    $filters Filter conditions
     *
     * @return Application[] Array of applications
     */
    public function findAll(?int $limit=null, ?int $offset=null, array $filters=[]): array
    {
        return $this->applicationMapper->findAll($limit, $offset, $filters);

    }//end findAll()


    /**
     * Get a single application by ID
     *
     * @param int $id Application ID
     *
     * @return Application The application entity
     *
     * @throws DoesNotExistException If application not found
     */
    public function find(int $id): Application
    {
        return $this->applicationMapper->find($id);

    }//end find()


    /**
     * Get applications by organisation
     *
     * @param string $organisationId Organisation UUID
     * @param int    $limit          Maximum number of results
     * @param int    $offset         Offset for pagination
     *
     * @return Application[] Array of applications
     */
    public function findByOrganisation(string $organisationId, int $limit=50, int $offset=0): array
    {
        return $this->applicationMapper->findByOrganisation($organisationId, $limit, $offset);

    }//end findByOrganisation()


    /**
     * Create a new application
     *
     * @param array $data Application data
     *
     * @return Application The created application
     */
    public function create(array $data): Application
    {
        $this->logger->info('Creating new application', ['data' => $data]);

        $application = $this->applicationMapper->createFromArray($data);

        $this->logger->info('Application created successfully', ['id' => $application->getId()]);

        return $application;

    }//end create()


    /**
     * Update an existing application
     *
     * @param int   $id   Application ID
     * @param array $data Application data
     *
     * @return Application The updated application
     *
     * @throws DoesNotExistException If application not found
     */
    public function update(int $id, array $data): Application
    {
        $this->logger->info('Updating application', ['id' => $id, 'data' => $data]);

        $application = $this->applicationMapper->updateFromArray($id, $data);

        $this->logger->info('Application updated successfully', ['id' => $id]);

        return $application;

    }//end update()


    /**
     * Delete an application
     *
     * @param int $id Application ID
     *
     * @return void
     *
     * @throws DoesNotExistException If application not found
     */
    public function delete(int $id): void
    {
        $this->logger->info('Deleting application', ['id' => $id]);

        $application = $this->applicationMapper->find($id);
        $this->applicationMapper->delete($application);

        $this->logger->info('Application deleted successfully', ['id' => $id]);

    }//end delete()


    /**
     * Count applications by organisation
     *
     * @param string $organisationId Organisation UUID
     *
     * @return int Number of applications
     */
    public function countByOrganisation(string $organisationId): int
    {
        return $this->applicationMapper->countByOrganisation($organisationId);

    }//end countByOrganisation()


    /**
     * Count total applications
     *
     * @return int Total number of applications
     */
    public function countAll(): int
    {
        return $this->applicationMapper->countAll();

    }//end countAll()


}//end class
