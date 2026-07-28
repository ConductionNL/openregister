<?php

/**
 * Holds the flow resolvers apps contributed, and asks each in turn.
 *
 * A flow id or a subject may be owned by any installed app, so resolution is
 * "ask every resolver until one answers". The first non-null wins. A run whose
 * flow no resolver owns cannot proceed — the worker records that, it does not
 * crash.
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

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Resolves a flow and a subject by asking every contributed resolver.
 */
class FlowResolverRegistry
{

    /**
     * Collected resolvers, or null before the first lookup.
     *
     * @var array<int, IFlowResolver>|null
     */
    private ?array $resolvers = null;

    /**
     * Constructor.
     *
     * @param IEventDispatcher $dispatcher Dispatches the contribution event.
     * @param LoggerInterface  $logger     The logger.
     */
    public function __construct(
        private readonly IEventDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * The flow document for an id, or null when no app owns it.
     *
     * @param string $flowId The flow id.
     *
     * @return array|null The flow document.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function resolveFlow(string $flowId): ?array
    {
        foreach ($this->all() as $resolver) {
            try {
                $flow = $resolver->resolveFlow($flowId);
                if ($flow !== null) {
                    return $flow;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[FlowResolverRegistry] A resolver threw resolving a flow: '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
                );
            }//end try
        }

        return null;

    }//end resolveFlow()

    /**
     * The subject for a uuid/register/schema, or null when it cannot be found.
     *
     * @param string $uuid     The subject uuid.
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     *
     * @return object|null The subject.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function resolveSubject(string $uuid, string $register, string $schema): ?object
    {
        foreach ($this->all() as $resolver) {
            try {
                $subject = $resolver->resolveSubject($uuid, $register, $schema);
                if ($subject !== null) {
                    return $subject;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[FlowResolverRegistry] A resolver threw resolving a subject: '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
                );
            }//end try
        }

        return null;

    }//end resolveSubject()

    /**
     * The ids of every flow that should run for an event, across all resolvers.
     *
     * @param string $event    The event id.
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     *
     * @return array<int, string> Flow ids, de-duplicated.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function flowsForTrigger(string $event, string $register, string $schema): array
    {
        $ids = [];
        foreach ($this->all() as $resolver) {
            try {
                foreach ($resolver->flowsForTrigger($event, $register, $schema) as $id) {
                    $ids[(string) $id] = true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[FlowResolverRegistry] A resolver threw listing triggered flows: '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__, 'event' => $event]
                );
            }//end try
        }

        return array_keys($ids);

    }//end flowsForTrigger()

    /**
     * Collect the contributed resolvers once per request.
     *
     * @return array<int, IFlowResolver> The resolvers.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    private function all(): array
    {
        if ($this->resolvers !== null) {
            return $this->resolvers;
        }

        $event = new RegisterFlowResolversEvent();
        $this->dispatcher->dispatchTyped($event);
        $this->resolvers = $event->getResolvers();

        return $this->resolvers;

    }//end all()
}//end class
