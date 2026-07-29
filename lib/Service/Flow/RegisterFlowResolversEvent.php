<?php

/**
 * Dispatched so apps can contribute a flow resolver.
 *
 * The same registration-event pattern the node registry and the MCP discovery
 * use, and the same one core's workflow engine uses for operations. An app that
 * stores flows (hermiq, with its `agentflow` objects) registers a resolver so
 * the worker can load and run them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Carries the resolvers apps contribute.
 */
class RegisterFlowResolversEvent extends Event
{

    /**
     * Contributed resolvers.
     *
     * @var array<int, IFlowResolver>
     */
    private array $resolvers = [];

    /**
     * Contribute a resolver.
     *
     * @param IFlowResolver $resolver The resolver.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function registerResolver(IFlowResolver $resolver): void
    {
        $this->resolvers[] = $resolver;

    }//end registerResolver()

    /**
     * Every contributed resolver.
     *
     * @return array<int, IFlowResolver> The resolvers.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function getResolvers(): array
    {
        return $this->resolvers;

    }//end getResolvers()
}//end class
