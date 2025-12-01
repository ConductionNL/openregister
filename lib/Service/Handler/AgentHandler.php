<?php
/**
 * OpenRegister Agent Handler
 *
 * This file contains the handler class for Agent entity import/export operations.
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
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use Psr\Log\LoggerInterface;

/**
 * Class AgentHandler
 *
 * Handles import and export operations for Agent entities.
 *
 * @package OCA\OpenRegister\Service\Handler
 */
class AgentHandler
{

    /**
     * Agent mapper instance.
     *
     * @var AgentMapper The agent mapper instance.
     */
    private AgentMapper $agentMapper;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;





}//end class
