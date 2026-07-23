<?php

/**
 * OpenRegister FlowEngineRegistrationListener
 *
 * Registers OpenRegister's native Nextcloud Flow (workflowengine) surfaces:
 * the {@see \OCA\OpenRegister\WorkflowEngine\RegisterObjectEntity} entity (so
 * Flow rules can trigger on object events) and the
 * {@see \OCA\OpenRegister\WorkflowEngine\RunFlowOperation} operation (so a Flow
 * rule can run a named OpenRegister flow). One listener handles both the
 * RegisterEntitiesEvent and RegisterOperationsEvent; both resolve their target
 * through the DI container so their own dependencies are injected.
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
 * @spec openspec/changes/visual-flow-builder/specs/integration-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\WorkflowEngine\RegisterObjectEntity;
use OCA\OpenRegister\WorkflowEngine\RunFlowOperation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use Psr\Container\ContainerInterface;

/**
 * Registers the OpenRegister entity + operation with Nextcloud Flow.
 *
 * @template-implements IEventListener<Event>
 */
class FlowEngineRegistrationListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container Resolves the entity/operation with DI.
     */
    public function __construct(
        private readonly ContainerInterface $container
    ) {
    }//end __construct()

    /**
     * Register the entity on RegisterEntitiesEvent and the operation on
     * RegisterOperationsEvent.
     *
     * @param Event $event The dispatched registration event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof RegisterEntitiesEvent) {
            $event->registerEntity($this->container->get(RegisterObjectEntity::class));
            return;
        }

        if ($event instanceof RegisterOperationsEvent) {
            $event->registerOperation($this->container->get(RunFlowOperation::class));
            return;
        }
    }//end handle()
}//end class
