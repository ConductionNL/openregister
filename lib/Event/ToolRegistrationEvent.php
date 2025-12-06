<?php
/**
 * OpenRegister Tool Registration Event
 *
 * Event dispatched to allow apps to register their tools with the ToolRegistry.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\ToolInterface;
use OCP\EventDispatcher\Event;

/**
 * Tool Registration Event
 *
 * This event is dispatched when OpenRegister is collecting available tools.
 * Other Nextcloud apps should listen to this event and register their tools.
 *
 * EXAMPLE USAGE IN YOUR APP:
 *
 * In your app's lib/AppInfo/Application.php:
 *
 * ```php
 * public function boot(IBootContext $context): void {
 *     $context->injectFn(function(IEventDispatcher $dispatcher) {
 *         $dispatcher->addListener(
 *             ToolRegistrationEvent::class,
 *             function(ToolRegistrationEvent $event) {
 *                 // Get your tool from DI container
 *                 $tool = \OC::$server->get(MyCMSTool::class);
 *
 *                 // Register it with metadata
 *                 $event->registerTool('myapp.cms', $tool, [
 *                     'name' => 'CMS Tool',
 *                     'description' => 'Manage website content',
 *                     'icon' => 'icon-category-office',
 *                     'app' => 'myapp'
 *                 ]);
 *             }
 *         );
 *     });
 * }
 * ```
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 */
class ToolRegistrationEvent extends Event
{

    /**
     * Tool registry
     *
     * @var ToolRegistry
     */
    private ToolRegistry $registry;


    /**
     * Constructor
     *
     * @param ToolRegistry $registry Tool registry to register tools with
     */
    public function __construct(ToolRegistry $registry)
    {
        parent::__construct();
        $this->registry = $registry;

    }//end __construct()


    /**
     * Register a tool
     *
     * Call this method from your event listener to register your tool.
     *
     * @param string        $id       Unique tool identifier (format: app_name.tool_name)
     * @param ToolInterface $tool     Your tool implementation
     * @param array         $metadata Tool metadata
     *                                - name (string): Human-readable name
     *                                - description (string): What the tool does
     *                                - icon (string): Nextcloud icon class or MDI icon
     *                                - app (string): Your app name
     *
     * @return void
     *
     * @throws \InvalidArgumentException If validation fails
     */
    public function registerTool(string $id, ToolInterface $tool, array $metadata): void
    {
        $this->registry->registerTool(id: $id, tool: $tool, metadata: $metadata);

    }//end registerTool()


}//end class
