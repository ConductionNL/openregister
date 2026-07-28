<?php

/**
 * Registers OpenRegister's own flow resolver.
 *
 * OpenRegister contributes its resolver through the same event every consuming
 * app uses ({@see RegisterFlowResolversEvent}) rather than seeding the registry
 * directly. Same reasoning as the node listener: if the owner of the mechanism
 * does not use the mechanism, the mechanism rots.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-native-store/specs/flow-native-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Flow\OpenRegisterFlowResolver;
use OCA\OpenRegister\Service\Flow\RegisterFlowResolversEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Contributes the built-in flow resolver.
 *
 * @template-implements IEventListener<RegisterFlowResolversEvent>
 */
class FlowResolverRegistrationListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param OpenRegisterFlowResolver $resolver The built-in flow resolver.
     */
    public function __construct(private readonly OpenRegisterFlowResolver $resolver)
    {

    }//end __construct()

    /**
     * Register the built-in resolver.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-native-store/specs/flow-native-store/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof RegisterFlowResolversEvent) === false) {
            return;
        }

        $event->registerResolver(resolver: $this->resolver);

    }//end handle()
}//end class
