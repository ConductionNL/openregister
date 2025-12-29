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
     * @var                                        OrganisationMapper The organisation mapper instance.
     * @SuppressWarnings(PHPMD.UnusedPrivateField)
     */
    private OrganisationMapper $organisationMapper;

    /**
     * Logger instance.
     *
     * @var                                        LoggerInterface The logger instance.
     * @SuppressWarnings(PHPMD.UnusedPrivateField)
     */
    private LoggerInterface $logger;
}//end class
