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


    /**
     * Constructor
     *
     * @param AgentMapper     $agentMapper The agent mapper instance
     * @param LoggerInterface $logger      The logger instance
     */
    public function __construct(AgentMapper $agentMapper, LoggerInterface $logger)
    {
        $this->agentMapper = $agentMapper;
        $this->logger      = $logger;

    }//end __construct()


    /**
     * Export an agent to array format
     *
     * @param Agent $agent The agent to export
     *
     * @return array The exported agent data
     */
    public function export(Agent $agent): array
    {
        $agentArray = $agent->jsonSerialize();
        unset($agentArray['id'], $agentArray['uuid']);
        return $agentArray;

    }//end export()


    /**
     * Import an agent from configuration data
     *
     * @param array       $data  The agent data
     * @param string|null $owner The owner of the agent
     *
     * @return Agent|null The imported agent or null if skipped
     * @throws Exception If import fails
     */
    public function import(array $data, ?string $owner=null): ?Agent
    {
        try {
            unset($data['id'], $data['uuid']);

            // Check if agent already exists by name.
            $existingAgents = $this->agentMapper->findAll();
            $existingAgent  = null;
            foreach ($existingAgents as $agent) {
                if ($agent->getName() === $data['name']) {
                    $existingAgent = $agent;
                    break;
                }
            }

            if ($existingAgent !== null) {
                // Update existing agent.
                $existingAgent->hydrate($data);
                if ($owner !== null) {
                    $existingAgent->setOwner($owner);
                }

                return $this->agentMapper->update($existingAgent);
            }

            // Create new agent.
            $agent = new Agent();
            $agent->hydrate($data);
            if ($owner !== null) {
                $agent->setOwner($owner);
            }

            return $this->agentMapper->insert($agent);
        } catch (Exception $e) {
            $this->logger->error('Failed to import agent: '.$e->getMessage());
            throw $e;
        }//end try

    }//end import()


}//end class
