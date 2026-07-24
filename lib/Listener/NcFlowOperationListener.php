<?php

/**
 * Offers "Run an OpenRegister flow" in the Nextcloud Flow rule editor.
 *
 * Registers {@see RunFlowOperation} on core's workflow engine using core's own
 * registration event, so an admin sees it beside every other Flow operation
 * with no indication that it came from a different app. That is the intended
 * result: the two systems compose.
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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Flow\RunFlowOperation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

/**
 * Contributes OpenRegister's operation to Nextcloud Flow.
 *
 * @template-implements IEventListener<RegisterOperationsEvent>
 */
class NcFlowOperationListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param RunFlowOperation $operation The operation to offer.
     */
    public function __construct(private readonly RunFlowOperation $operation)
    {

    }//end __construct()

    /**
     * Register the operation.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof RegisterOperationsEvent) === false) {
            return;
        }

        $event->registerOperation($this->operation);

    }//end handle()
}//end class
