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
     * @var bool
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
        LoggerInterface $logger
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
    }

    /**
     * Load all tools by dispatching registration event
     *
     * This is called lazily the first time tools are accessed.
     *
     * @return void
     */
    private function loadTools(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->logger->info('[ToolRegistry] Loading tools from all apps');

        $event = new ToolRegistrationEvent($this);
        $this->eventDispatcher->dispatchTyped($event);

        $this->loaded = true;

        $this->logger->info('[ToolRegistry] Loaded tools', [
            'count' => count($this->tools),
            'tools' => array_keys($this->tools),
        ]);
    }

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
     */
    public function registerTool(string $id, ToolInterface $tool, array $metadata): void
    {
        // Validate ID format (should be app_name.tool_name)
        if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $id)) {
            throw new \InvalidArgumentException(
                "Invalid tool ID format: {$id}. Must be 'app_name.tool_name'"
            );
        }

        // Check if already registered
        if (isset($this->tools[$id])) {
            throw new \InvalidArgumentException("Tool already registered: {$id}");
        }

        // Validate required metadata
        $required = ['name', 'description', 'icon', 'app'];
        foreach ($required as $field) {
            if (!isset($metadata[$field])) {
                throw new \InvalidArgumentException("Missing required metadata field: {$field}");
            }
        }

        // Register the tool
        $this->tools[$id] = [
            'tool' => $tool,
            'metadata' => $metadata,
        ];

        $this->logger->info('[ToolRegistry] Tool registered', [
            'id' => $id,
            'name' => $metadata['name'],
            'app' => $metadata['app'],
        ]);
    }

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

        if (!isset($this->tools[$id])) {
            return null;
        }

        return $this->tools[$id]['tool'];
    }

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
    }

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
            if (isset($this->tools[$id])) {
                $result[$id] = $this->tools[$id]['tool'];
            } else {
                $this->logger->warning('[ToolRegistry] Tool not found', ['id' => $id]);
            }
        }

        return $result;
    }

    /**
     * Check if a tool exists
     *
     * @param string $id Tool identifier
     *
     * @return bool True if tool exists
     */
    public function hasTool(string $id): bool
    {
        $this->loadTools();
        return isset($this->tools[$id]);
    }

    /**
     * Get tools by app
     *
     * Returns all tools registered by a specific app.
     *
     * @param string $appName App name
     *
     * @return array Array of tool IDs and metadata
     */
    public function getToolsByApp(string $appName): array
    {
        $this->loadTools();

        $result = [];
        foreach ($this->tools as $id => $data) {
            if ($data['metadata']['app'] === $appName) {
                $result[$id] = $data['metadata'];
            }
        }

        return $result;
    }
}

