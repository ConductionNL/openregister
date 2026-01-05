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
}//end class
