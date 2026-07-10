<?php
/**
 * OcmResourceTypeListener — advertise the `openregister` OCM resource type.
 *
 * Nextcloud builds its `/ocm-provider` discovery document by firing a
 * {@see ResourceTypeRegisterEvent}; apps that accept federated shares of their
 * own resource type register it here so remote instances know OpenRegister
 * accepts `openregister` shares (routed to OpenRegisterCloudFederationProvider).
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @template-implements IEventListener<ResourceTypeRegisterEvent>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\OCM\Events\ResourceTypeRegisterEvent;

/**
 * Registers the `openregister` resource type in the OCM discovery document.
 */
class OcmResourceTypeListener implements IEventListener
{

    /**
     * Handle the resource-type registration event.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ResourceTypeRegisterEvent) === false) {
            return;
        }

        $event->registerResourceType(
            'openregister',
            ['user', 'group'],
            ['openregister' => '/apps/openregister/api/federation']
        );
    }//end handle()
}//end class
