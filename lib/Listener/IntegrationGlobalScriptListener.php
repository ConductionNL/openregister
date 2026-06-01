<?php

/**
 * Listener that injects the integration registry global bootstrap script on every page.
 *
 * Loads openregister-integration-global.js unconditionally on every full-page render
 * so that window.OCA.OpenRegister.integrations is available on pages served by
 * consuming apps (e.g. OpenCatalogi), not only on OpenRegister's own pages.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/universal-shared-integration-registry/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Injects the OpenRegister integration registry bootstrap script on every page.
 *
 * This enables leaf apps (e.g. OpenConnector) to find a real registry on any
 * consuming-app page without requiring changes to those apps.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 *
 * @psalm-suppress UnusedClass
 */
class IntegrationGlobalScriptListener implements IEventListener
{
    /**
     * Handle the BeforeTemplateRenderedEvent.
     *
     * Injects openregister-integration-global.js as an init script so the
     * shared registry is installed before any other scripts run.
     *
     * @param Event $event The event instance.
     *
     * @return void
     *
     * @spec openspec/changes/universal-shared-integration-registry/tasks.md#task-4
     */
    public function handle(Event $event): void
    {
        if (($event instanceof BeforeTemplateRenderedEvent) === false) {
            return;
        }

        Util::addInitScript('openregister', 'openregister-integration-global');
    }//end handle()
}//end class
