<?php

/**
 * OpenRegister DeepLinkRegistrationEvent
 *
 * Event dispatched during OpenRegister boot to allow consuming apps
 * to register their deep link URL patterns.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched during OpenRegister boot to allow consuming apps
 * to register their deep link URL patterns.
 *
 * Consumer apps listen for this event and call register() on the
 * provided DeepLinkRegistryService to claim schemas.
 */
class DeepLinkRegistrationEvent extends Event
{
    /**
     * Constructor for DeepLinkRegistrationEvent.
     *
     * @param DeepLinkRegistryService $registry The deep link registry service
     *
     * @return void
     */
    public function __construct(
        private readonly DeepLinkRegistryService $registry,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the deep link registry service to register URL patterns.
     *
     * @return DeepLinkRegistryService The registry service
     */
    public function getRegistry(): DeepLinkRegistryService
    {
        return $this->registry;
    }//end getRegistry()

    /**
     * Convenience method to register a deep link pattern directly on the event.
     *
     * @param string $appId        The consuming app ID (e.g., "procest")
     * @param string $registerSlug The register slug
     * @param string $schemaSlug   The schema slug
     * @param string $urlTemplate  URL template with placeholders (e.g., "/apps/procest/#/cases/{uuid}")
     * @param string $icon         Optional icon identifier
     *
     * @return void
     * @spec   openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
     */
    public function register(
        string $appId,
        string $registerSlug,
        string $schemaSlug,
        string $urlTemplate,
        string $icon=''
    ): void {
        $this->registry->register($appId, $registerSlug, $schemaSlug, $urlTemplate, $icon);
    }//end register()
}//end class
