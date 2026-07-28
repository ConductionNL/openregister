<?php

/**
 * OpenRegister Tool Registry
 *
 * Central registry for managing LLphant function tools from all apps.
 * Allows other Nextcloud apps to register their own tools for agents to use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Tool\ToolInterface;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCP\EventDispatcher\IEventDispatcher;
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
     *
     * @spec openspec/specs/ai-mcp/spec.md
     */
    public function __construct(
        IEventDispatcher $eventDispatcher,
        LoggerInterface $logger
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
     *
     * @spec openspec/specs/ai-mcp/spec.md
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
     *
     * @spec openspec/specs/ai-mcp/spec.md
     */
    public function registerTool(string $id, ToolInterface $tool, array $metadata): void
    {
        // Validate ID format (should be app_name.tool_name, or
        // app_name.schema.verb for ADR-063 chain-2 schema-derived tools —
        // e.g. `pipelinq.lead.search`). MCP tool ids commonly use camelCase
        // on the right side (e.g. `openbuild.createApp`,
        // `decidesk.listRecentMeetings`) so every segment after the first
        // accepts both cases. The left-hand (app id) segment stays
        // lowercase since it maps to a Nextcloud app id.
        if (preg_match('/^[a-z0-9_]+(\.[a-zA-Z0-9_]+)+$/', $id) === 0) {
            throw new InvalidArgumentException(
                "Invalid tool ID format: {$id}. Must be 'app_name.tool_name' (dot-separated segments)"
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
     *
     * @spec openspec/specs/ai-mcp/spec.md
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
     *
     * @spec openspec/specs/ai-mcp/spec.md
     * @spec openspec/specs/chat-ai/spec.md
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
     *
     * @spec openspec/specs/ai-mcp/spec.md
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
