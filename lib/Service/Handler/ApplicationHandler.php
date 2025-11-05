<?php
/**
 * OpenRegister Application Handler
 *
 * This file contains the handler class for Application entity import/export operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handler
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Handler;

use Exception;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use Psr\Log\LoggerInterface;

/**
 * Class ApplicationHandler
 *
 * Handles import and export operations for Application entities.
 *
 * @package OCA\OpenRegister\Service\Handler
 */
class ApplicationHandler
{

    /**
     * Application mapper instance.
     *
     * @var ApplicationMapper The application mapper instance.
     */
    private ApplicationMapper $applicationMapper;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param ApplicationMapper $applicationMapper The application mapper instance
     * @param LoggerInterface   $logger            The logger instance
     */
    public function __construct(ApplicationMapper $applicationMapper, LoggerInterface $logger)
    {
        $this->applicationMapper = $applicationMapper;
        $this->logger            = $logger;

    }//end __construct()


    /**
     * Export an application to array format
     *
     * @param Application $application The application to export
     *
     * @return array The exported application data
     */
    public function export(Application $application): array
    {
        $applicationArray = $application->jsonSerialize();
        unset($applicationArray['id'], $applicationArray['uuid']);
        return $applicationArray;

    }//end export()


    /**
     * Import an application from configuration data
     *
     * @param array       $data  The application data
     * @param string|null $owner The owner of the application
     *
     * @return Application|null The imported application or null if skipped
     * @throws Exception If import fails
     */
    public function import(array $data, ?string $owner = null): ?Application
    {
        try {
            unset($data['id'], $data['uuid']);

            // Check if application already exists by name
            $existingApplications = $this->applicationMapper->findAll();
            $existingApplication  = null;
            foreach ($existingApplications as $application) {
                if ($application->getName() === $data['name']) {
                    $existingApplication = $application;
                    break;
                }
            }

            if ($existingApplication !== null) {
                // Update existing application
                $existingApplication->hydrate($data);
                if ($owner !== null) {
                    $existingApplication->setOwner($owner);
                }

                return $this->applicationMapper->update($existingApplication);
            }

            // Create new application
            $application = new Application();
            $application->hydrate($data);
            if ($owner !== null) {
                $application->setOwner($owner);
            }

            return $this->applicationMapper->insert($application);
        } catch (Exception $e) {
            $this->logger->error('Failed to import application: '.$e->getMessage());
            throw $e;
        }//end try

    }//end import()


}//end class


