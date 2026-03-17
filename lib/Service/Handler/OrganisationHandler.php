<?php
/**
 * OpenRegister Organisation Handler
 *
 * This file contains the handler class for Organisation entity import/export operations.
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
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use Psr\Log\LoggerInterface;

/**
 * Class OrganisationHandler
 *
 * Handles import and export operations for Organisation entities.
 *
 * @package OCA\OpenRegister\Service\Handler
 */
class OrganisationHandler
{

    /**
     * Organisation mapper instance.
     *
     * @var OrganisationMapper The organisation mapper instance.
     */
    private OrganisationMapper $organisationMapper;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param OrganisationMapper $organisationMapper The organisation mapper instance
     * @param LoggerInterface    $logger             The logger instance
     */
    public function __construct(OrganisationMapper $organisationMapper, LoggerInterface $logger)
    {
        $this->organisationMapper = $organisationMapper;
        $this->logger             = $logger;

    }//end __construct()


    /**
     * Export an organisation to array format
     *
     * @param Organisation $organisation The organisation to export
     *
     * @return array The exported organisation data
     */
    public function export(Organisation $organisation): array
    {
        $organisationArray = $organisation->jsonSerialize();
        unset($organisationArray['id'], $organisationArray['uuid']);
        return $organisationArray;

    }//end export()


    /**
     * Import an organisation from configuration data
     *
     * @param array       $data  The organisation data
     * @param string|null $owner The owner of the organisation
     *
     * @return Organisation|null The imported organisation or null if skipped
     * @throws Exception If import fails
     */
    public function import(array $data, ?string $owner = null): ?Organisation
    {
        try {
            unset($data['id'], $data['uuid']);

            // Check if organisation already exists by title
            $existingOrganisations = $this->organisationMapper->findAll();
            $existingOrganisation  = null;
            foreach ($existingOrganisations as $organisation) {
                if ($organisation->getTitle() === $data['title']) {
                    $existingOrganisation = $organisation;
                    break;
                }
            }

            if ($existingOrganisation !== null) {
                // Update existing organisation
                $existingOrganisation->hydrate($data);
                if ($owner !== null) {
                    $existingOrganisation->setOwner($owner);
                }

                return $this->organisationMapper->update($existingOrganisation);
            }

            // Create new organisation
            $organisation = new Organisation();
            $organisation->hydrate($data);
            if ($owner !== null) {
                $organisation->setOwner($owner);
            }

            return $this->organisationMapper->insert($organisation);
        } catch (Exception $e) {
            $this->logger->error('Failed to import organisation: '.$e->getMessage());
            throw $e;
        }//end try

    }//end import()


}//end class


