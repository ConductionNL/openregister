<?php

/**
 * OpenRegister Source Handler
 *
 * This file contains the handler class for Source entity import/export operations.
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
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use Psr\Log\LoggerInterface;

/**
 * Class SourceHandler
 *
 * Handles import and export operations for Source entities.
 *
 * @package OCA\OpenRegister\Service\Handler
 */
class SourceHandler
{
    /**
     * Source mapper instance.
     *
     * @var                                        SourceMapper The source mapper instance.
     * @SuppressWarnings(PHPMD.UnusedPrivateField)
     */
    private SourceMapper $sourceMapper;

    /**
     * Logger instance.
     *
     * @var                                        LoggerInterface The logger instance.
     * @SuppressWarnings(PHPMD.UnusedPrivateField)
     */
    private LoggerInterface $logger;
}//end class
