<?php

/**
 * OpenRegister Tool Registration Listener
 *
 * Listens to ToolRegistrationEvent and registers OpenRegister's built-in tools.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Tool\AgentTool;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCA\OpenRegister\Tool\McpProviderBridge;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

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
     * @param McpToolsService $mcpToolsService MCP tools service used to register MCP-sourced tools.
     * @param LoggerInterface $logger          PSR logger.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-20
     */
    public function __construct(
        RegisterTool $registerTool,
        SchemaTool $schemaTool,
        ObjectsTool $objectsTool,
        ApplicationTool $applicationTool,
        AgentTool $agentTool,
        private readonly McpToolsService $mcpToolsService,
        private readonly LoggerInterface $logger
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-20
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ToolRegistrationEvent) === false) {
            return;
        }

        $this->registerBuiltinTools(event: $event);
        $this->bridgeMcpProviderTools(event: $event);
    }//end handle()

    /**
     * Register the five built-in OpenRegister tools into the ToolRegistrationEvent.
     *
     * @param ToolRegistrationEvent $event The tool registration event.
     *
     * @return void
     */
    private function registerBuiltinTools(ToolRegistrationEvent $event): void
    {
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
    }//end registerBuiltinTools()

    /**
     * Bridge every per-app IMcpToolProvider's function into the ToolRegistrationEvent.
     *
     * ToolRegistry enforces the id format `app_name.tool_name` so we register ONE
     * bridge instance per (provider, function) pair under its full MCP id (e.g.
     * `openbuild.createApp`). The bridge is configured via setOnlyMcpId() so each
     * entry's getFunctions() returns just that one descriptor — preventing the LLM
     * from seeing the same provider's tool list duplicated across N registry entries.
     *
     * @param ToolRegistrationEvent $event The tool registration event.
     *
     * @return void
     */
    private function bridgeMcpProviderTools(ToolRegistrationEvent $event): void
    {
        foreach ($this->mcpToolsService->getProviders() as $provider) {
            $appId = $provider->getAppId();
            // Skip the built-in MCP providers (registers/schemas/objects);
            // their functionality is already covered by the 5 hardcoded
            // ToolRegistry entries. Avoids the LLM seeing two parallel sets
            // with subtly different shapes.
            if (in_array($appId, ['registers', 'schemas', 'objects', 'openregister'], true) === true) {
                continue;
            }

            try {
                $this->bridgeProviderDescriptors(event: $event, provider: $provider, appId: $appId);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[ToolRegistrationListener] Failed to bridge MCP provider',
                    ['appId' => $appId, 'error' => $e->getMessage()]
                );
            }//end try
        }//end foreach
    }//end bridgeMcpProviderTools()

    /**
     * Iterate a single provider's tool descriptors and register each as a bridge.
     *
     * @param ToolRegistrationEvent $event    The tool registration event.
     * @param object                $provider The MCP tool provider.
     * @param string                $appId    The provider's app identifier.
     *
     * @return void
     */
    private function bridgeProviderDescriptors(ToolRegistrationEvent $event, object $provider, string $appId): void
    {
        foreach ($provider->getTools() as $descriptor) {
            $mcpId = (string) ($descriptor['id'] ?? '');
            if ($mcpId === '' || preg_match('/^[a-z0-9_]+(\.[a-zA-Z0-9_]+)+$/', $mcpId) === 0) {
                // Tool id is non-conforming (camelCase or missing dot). Skip
                // — the LLM-visible id MUST match the ToolRegistry regex
                // (app_name.tool_name, or app_name.schema.verb for ADR-063
                // chain-2 schema-derived tools), and the agent's tools array
                // stores MCP ids verbatim.
                continue;
            }

            $bridge = new McpProviderBridge(provider: $provider, logger: $this->logger);
            $bridge->setOnlyMcpId($mcpId);

            $event->registerTool(
                id: $mcpId,
                tool: $bridge,
                metadata: [
                    'name'        => (string) ($descriptor['name'] ?? $mcpId),
                    'description' => (string) ($descriptor['description'] ?? ''),
                    'icon'        => 'icon-category-integration',
                    'app'         => $appId,
                ]
            );
        }//end foreach
    }//end bridgeProviderDescriptors()
}//end class
