<?php

/**
 * OpenRegister Tool Registry
 *
 * Central registry for managing LLphant function tools from all apps.
 * Allows other Nextcloud apps to register their own tools for agents to use.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Tool\McpProviderBridge;
use OCA\OpenRegister\Tool\ToolInterface;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tool Registry Service
 *
 * Central registry that manages all available tools for agents.
 * Other Nextcloud apps can register their own tools by listening to
 * the ToolRegistrationEvent.
 *
 * ARCHITECTURE:
 * - Tools are registered during app initialization
 * - Each tool has a unique identifier (app_name.tool_name)
 * - Tools include metadata: name, description, icon, app
 * - Frontend fetches available tools via API
 *
 * USAGE:
 * In your app's Application.php:
 * ```php
 * $eventDispatcher->addListener(
 *     ToolRegistrationEvent::class,
 *     function(ToolRegistrationEvent $event) {
 *         $tool = \OC::$server->get(MyCustomTool::class);
 *         $event->registerTool('myapp.customtool', $tool, [
 *             'name' => 'Custom Tool',
 *             'description' => 'Does custom things',
 *             'icon' => 'icon-class-name',
 *             'app' => 'myapp'
 *         ]);
 *     }
 * );
 * ```
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class ToolRegistry
{

    /**
     * Registered tools
     *
     * Format: ['tool_id' => ['tool' => ToolInterface, 'metadata' => [...]]]
     *
     * @var array
     */
    private array $tools = [];

    /**
     * Event dispatcher
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Whether tools have been loaded
     *
     * @var boolean
     */
    private bool $loaded = false;

    /**
     * Constructor
     *
     * @param IEventDispatcher $eventDispatcher Event dispatcher
     * @param LoggerInterface  $logger          Logger
     */
    public function __construct(
        IEventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        private readonly ?ContainerInterface $container=null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->logger          = $logger;
    }//end __construct()

    /**
     * Load all tools by dispatching registration event
     *
     * This is called lazily the first time tools are accessed.
     *
     * @return void
     */
    private function loadTools(): void
    {
        if ($this->loaded === true) {
            return;
        }

        $this->logger->info(
            message: '[ToolRegistry] Loading tools from all apps',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        $event = new ToolRegistrationEvent(registry: $this);
        $this->eventDispatcher->dispatchTyped($event);

        // Bridge: enumerate IMcpToolProvider implementations and register
        // one McpProviderBridge per app. The bridge wraps each provider so
        // its MCP tool descriptors become LLphant function definitions the
        // chat orchestrator can pass to the LLM.
        $this->loadMcpBridgedTools();

        $this->loaded = true;

        $this->logger->info(
            message: '[ToolRegistry] Loaded tools',
            context: [
                'file'  => __FILE__,
                'line'  => __LINE__,
                'count' => count($this->tools),
                'tools' => array_keys($this->tools),
            ]
        );
    }//end loadTools()

    /**
     * Enumerate per-app IMcpToolProvider implementations and register one
     * McpProviderBridge per provider with the registry. Each tool descriptor
     * from a provider becomes a function on its bridge — the LLM sees one
     * tool per app whose getFunctions() lists every MCP descriptor.
     */
    private function loadMcpBridgedTools(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $mcp = $this->container->get(McpToolsService::class);
        } catch (\Throwable $e) {
            $this->logger->info('[ToolRegistry] McpToolsService unavailable: '.$e->getMessage());
            return;
        }

        // McpToolsService doesn't expose its providers list directly; pull
        // tools/list and group by app id (everything before the first dot).
        $listing = $mcp->listTools();
        $tools   = is_array($listing) === true ? ($listing['tools'] ?? []) : [];
        if (empty($tools) === true) {
            return;
        }

        $byApp = [];
        foreach ($tools as $descriptor) {
            $id = (string) ($descriptor['id'] ?? '');
            $dot = strpos($id, '.');
            if ($dot === false) {
                continue;
            }

            $appId           = substr($id, 0, $dot);
            $byApp[$appId][] = $descriptor;
        }

        // Skip built-in OR tools — those are already registered by
        // ToolRegistrationListener as real ToolInterface impls.
        unset($byApp['openregister']);

        foreach ($byApp as $appId => $descriptors) {
            // Resolve the actual IMcpToolProvider so the bridge can call
            // invokeTool() directly (cheaper than going back through MCP).
            $providerCandidates = [
                'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::'.$appId,
                'OCA\\'.ucfirst($appId).'\\Mcp\\'.ucfirst($appId).'ToolProvider',
            ];

            $provider = null;
            foreach ($providerCandidates as $key) {
                try {
                    if (str_contains($key, '\\') === true && str_contains($key, '::') === false && class_exists($key) === false) {
                        continue;
                    }

                    $candidate = $this->container->get($key);
                    if ($candidate instanceof IMcpToolProvider) {
                        $provider = $candidate;
                        break;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if ($provider === null) {
                $this->logger->info(
                    '[ToolRegistry] No IMcpToolProvider resolvable for app — skipping bridge',
                    ['appId' => $appId]
                );
                continue;
            }

            $bridge = new McpProviderBridge($provider, $this->logger);
            $bridgeId = $appId.'.mcp_bridge';
            try {
                $this->registerTool($bridgeId, $bridge, [
                    'name'        => ucfirst($appId).' tools',
                    'description' => 'MCP-bridged tools from the '.$appId.' app.',
                    'icon'        => 'icon-category-app-bundles',
                    'app'         => $appId,
                ]);

                // Also register one alias tool ID per MCP descriptor so
                // agents whose `tools` JSON lists IDs like
                // "decidesk.createMeeting" can match without changing format.
                foreach ($descriptors as $descriptor) {
                    $rawId = (string) ($descriptor['id'] ?? '');
                    if ($rawId === '' || $rawId === $bridgeId) {
                        continue;
                    }

                    // Same bridge serves all descriptors of this app; we
                    // just multiplex by ID so getAgentTools() finds them
                    // when the agent's tools array names them directly.
                    if (($this->tools[$rawId] ?? null) === null) {
                        $this->tools[$rawId] = [
                            'tool'     => $bridge,
                            'metadata' => [
                                'name'        => (string) ($descriptor['name'] ?? $rawId),
                                'description' => (string) ($descriptor['description'] ?? ''),
                                'icon'        => 'icon-category-app-bundles',
                                'app'         => $appId,
                            ],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[ToolRegistry] Failed to register MCP bridge',
                    ['appId' => $appId, 'error' => $e->getMessage()]
                );
            }
        }
    }//end loadMcpBridgedTools()

    /**
     * Register a tool
     *
     * Called by other apps during the ToolRegistrationEvent.
     *
     * @param string        $id       Unique tool identifier (format: app_name.tool_name)
     * @param ToolInterface $tool     Tool instance
     * @param array         $metadata Tool metadata (name, description, icon, app)
     *
     * @return void
     *
     * @throws \InvalidArgumentException If tool ID is invalid or already registered
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple validation checks required
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple validation paths with exceptions
     */
    public function registerTool(string $id, ToolInterface $tool, array $metadata): void
    {
        // Validate ID format (should be app_name.tool_name).
        // Permitted: app_name.tool_name OR app_name.toolName (camelCase
        // accommodates MCP tool descriptors bridged via McpProviderBridge).
        if (preg_match('/^[a-z0-9_]+\.[a-zA-Z0-9_]+$/', $id) === 0) {
            throw new InvalidArgumentException(
                "Invalid tool ID format: {$id}. Must be 'app_name.tool_name' (camelCase tool names allowed)"
            );
        }

        // Check if already registered.
        if (($this->tools[$id] ?? null) !== null) {
            throw new InvalidArgumentException("Tool already registered: {$id}");
        }

        // Validate required metadata.
        $required = ['name', 'description', 'icon', 'app'];
        foreach ($required as $field) {
            if (isset($metadata[$field]) === false) {
                throw new InvalidArgumentException("Missing required metadata field: {$field}");
            }
        }

        // Register the tool.
        $this->tools[$id] = [
            'tool'     => $tool,
            'metadata' => $metadata,
        ];

        $this->logger->info(
            message: '[ToolRegistry] Tool registered',
            context: [
                'file' => __FILE__,
                'line' => __LINE__,
                'id'   => $id,
                'name' => $metadata['name'],
                'app'  => $metadata['app'],
            ]
        );
    }//end registerTool()

    /**
     * Get a tool by ID
     *
     * @param string $id Tool identifier
     *
     * @return ToolInterface|null Tool instance or null if not found
     */
    public function getTool(string $id): ?ToolInterface
    {
        $this->loadTools();

        if (isset($this->tools[$id]) === false) {
            return null;
        }

        return $this->tools[$id]['tool'];
    }//end getTool()

    /**
     * Get all registered tools
     *
     * @return array Array of tool IDs and their metadata
     */
    public function getAllTools(): array
    {
        $this->loadTools();

        $result = [];
        foreach ($this->tools as $id => $data) {
            $result[$id] = $data['metadata'];
        }

        return $result;
    }//end getAllTools()

    /**
     * Get tools by their IDs
     *
     * Used by agents to load their enabled tools.
     *
     * @param array $ids Array of tool IDs
     *
     * @return array Array of ToolInterface instances (key: id, value: tool)
     */
    public function getTools(array $ids): array
    {
        $this->loadTools();

        $result = [];
        foreach ($ids as $id) {
            if (($this->tools[$id] ?? null) === null) {
                $this->logger->warning(
                    message: '[ToolRegistry] Tool not found',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id]
                );
                continue;
            }

            $result[$id] = $this->tools[$id]['tool'];
        }

        return $result;
    }//end getTools()
}//end class
