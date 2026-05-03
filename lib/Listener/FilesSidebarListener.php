<?php

/**
 * OpenRegister Files Sidebar Listener
 *
 * Injects the sidebar tab JavaScript bundle when the Files app is loaded.
 * This listener uses the standard Nextcloud pattern for loading scripts
 * into the Files app context via LoadAdditionalScriptsEvent.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * FilesSidebarListener
 *
 * Listens for the Files app LoadAdditionalScriptsEvent and injects
 * the OpenRegister sidebar tab bundle into the page.
 *
 * @category  Listener
 * @package   OCA\OpenRegister\Listener
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @template-implements IEventListener<Event>
 */
class FilesSidebarListener implements IEventListener
{
    /**
     * Handle the LoadAdditionalScriptsEvent from the Files app.
     *
     * Injects the sidebar tab JavaScript bundle so that the OpenRegister
     * tabs appear in the Files app sidebar.
     *
     * @param Event $event The event instance.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-20
     */
    public function handle(Event $event): void
    {
        // Only handle LoadAdditionalScriptsEvent from the Files app.
        // We check by class name string to avoid a hard dependency on the Files app.
        if (get_class($event) !== 'OCA\Files\Event\LoadAdditionalScriptsEvent') {
            return;
        }

        Util::addScript('openregister', 'openregister-filesSidebar');
    }//end handle()
}//end class
