<?php

/**
 * OpenRegister DeepLinkRegistrationEvent
 *
 * Event dispatched during OpenRegister boot to allow consuming apps
 * to register their deep link URL patterns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-9
     */
    public function getRegistry(): DeepLinkRegistryService
    {
        return $this->registry;
    }//end getRegistry()

    /**
     * Convenience method to register a deep link pattern directly on the event.
     *
     * @param string      $appId        The consuming app ID (e.g., "procest")
     * @param string      $registerSlug The register slug
     * @param string      $schemaSlug   The schema slug
     * @param string      $urlTemplate  URL template with placeholders (e.g., "/apps/procest/#/cases/{uuid}")
     * @param string      $icon         Optional icon identifier
     * @param string|null $displayName  Optional human-readable label for the app's
     *                                  unified-search results (defaults to null → app id)
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
     * @spec openspec/changes/unified-search-provider/specs/deep-link-registry/spec.md
     */
    public function register(
        string $appId,
        string $registerSlug,
        string $schemaSlug,
        string $urlTemplate,
        string $icon='',
        ?string $displayName=null
    ): void {
        $this->registry->register($appId, $registerSlug, $schemaSlug, $urlTemplate, $icon, $displayName);
    }//end register()
}//end class
