<?php
/**
 * OpenRegister Tool Registration Listener
 *
 * Listens to ToolRegistrationEvent and registers OpenRegister's built-in tools.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCA\OpenRegister\Tool\AgentTool;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Tool Registration Listener
 *
 * Registers OpenRegister's built-in tools when the ToolRegistrationEvent is dispatched.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @template-implements IEventListener<ToolRegistrationEvent>
 */
class ToolRegistrationListener implements IEventListener
{

    /**
     * Register tool
     *
     * @var RegisterTool
     */
    private RegisterTool $registerTool;

    /**
     * Schema tool
     *
     * @var SchemaTool
     */
    private SchemaTool $schemaTool;

    /**
     * Objects tool
     *
     * @var ObjectsTool
     */
    private ObjectsTool $objectsTool;

    /**
     * Application tool
     *
     * @var ApplicationTool
     */
    private ApplicationTool $applicationTool;

    /**
     * Agent tool
     *
     * @var AgentTool
     */
    private AgentTool $agentTool;


    /**
     * Constructor
     *
     * @param RegisterTool    $registerTool    Register tool.
     * @param SchemaTool      $schemaTool      Schema tool.
     * @param ObjectsTool     $objectsTool     Objects tool.
     * @param ApplicationTool $applicationTool Application tool.
     * @param AgentTool       $agentTool       Agent tool.
     */
    public function __construct(
        RegisterTool $registerTool,
        SchemaTool $schemaTool,
        ObjectsTool $objectsTool,
        ApplicationTool $applicationTool,
        AgentTool $agentTool
    ) {
        $this->registerTool    = $registerTool;
        $this->schemaTool      = $schemaTool;
        $this->objectsTool     = $objectsTool;
        $this->applicationTool = $applicationTool;
        $this->agentTool       = $agentTool;

    }//end __construct()


    /**
     * Handle the event
     *
     * @param Event $event The event
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ToolRegistrationEvent) === false) {
            return;
        }

        // Register built-in OpenRegister tools.
        // Using tool's getName() and getDescription() to avoid duplication.
        $event->registerTool(
                id: 'openregister.register',
                tool: $this->registerTool,
                metadata: [
                    'name'        => $this->registerTool->getName(),
                    'description' => $this->registerTool->getDescription(),
                    'icon'        => 'icon-category-office',
                    'app'         => 'openregister',
                ]
                );

        $event->registerTool(
                id: 'openregister.schema',
                tool: $this->schemaTool,
                metadata: [
                    'name'        => $this->schemaTool->getName(),
                    'description' => $this->schemaTool->getDescription(),
                    'icon'        => 'icon-category-customization',
                    'app'         => 'openregister',
                ]
                );

        $event->registerTool(
                id: 'openregister.objects',
                tool: $this->objectsTool,
                metadata: [
                    'name'        => $this->objectsTool->getName(),
                    'description' => $this->objectsTool->getDescription(),
                    'icon'        => 'icon-category-organization',
                    'app'         => 'openregister',
                ]
                );

        $event->registerTool(
                id: 'openregister.application',
                tool: $this->applicationTool,
                metadata: [
                    'name'        => $this->applicationTool->getName(),
                    'description' => $this->applicationTool->getDescription(),
                    'icon'        => 'icon-category-integration',
                    'app'         => 'openregister',
                ]
                );

        $event->registerTool(
                id: 'openregister.agent',
                tool: $this->agentTool,
                metadata: [
                    'name'        => $this->agentTool->getName(),
                    'description' => $this->agentTool->getDescription(),
                    'icon'        => 'icon-category-monitoring',
                    'app'         => 'openregister',
                ]
                );

    }//end handle()


}//end class
