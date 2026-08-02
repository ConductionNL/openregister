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
     * Request-scoped memo of resolved flow documents, keyed by flow id.
     *
     * A miss is memoised as `null` just like a hit: "no app owns this flow" is
     * an answer worth remembering, and it is the EXPENSIVE one — every resolver
     * in the chain has to look and fail to produce it.
     *
     * @var array<string, array|null>
     */
    private array $flowMemo = [];

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
     * Memoised for the request. Resolution asks every registered resolver in
     * turn, and each one that does not own the flow answers by looking the id
     * up in its own register — so the cost is paid per resolver, per call, and
     * an unowned flow pays the whole chain. `FlowTriggerService::fire()` calls
     * this once per queued run and a save that cascades fires the same triggers
     * again for each child, so the same id was being re-resolved several times
     * inside one object write. Measured 2026-07-29: flow resolution was the
     * bulk of the object-created dispatch, ~370-620ms per create.
     *
     * @param string $flowId The flow id.
     *
     * @return array|null The flow document.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
     */
    public function resolveFlow(string $flowId): ?array
    {
        if (array_key_exists($flowId, $this->flowMemo) === true) {
            return $this->flowMemo[$flowId];
        }

        $this->flowMemo[$flowId] = null;

        foreach ($this->all() as $resolver) {
            try {
                $flow = $resolver->resolveFlow($flowId);
                if ($flow !== null) {
                    $this->flowMemo[$flowId] = $flow;
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
     * The scheduled-flow candidates every contributing app owns.
     *
     * The scheduler used to read one hard-coded store, so a flow that declared
     * `trigger: schedule` in a leaf app's own register was invisible to it and
     * could never fire — the event triggers had gone through the resolvers
     * since day one, the schedule never had. This closes that: any app whose
     * resolver also implements {@see IScheduledFlowSource} contributes its
     * scheduled flows, exactly as it already contributes its event-triggered
     * ones.
     *
     * A resolver that does not implement the interface is skipped rather than
     * asked, so an app that owns only event-triggered flows costs nothing and
     * needs no change. A resolver that throws is logged and skipped, because
     * one broken app must not stop the whole instance's schedule.
     *
     * Candidates are de-duplicated by flow id, first source winning — the same
     * precedence `resolveFlow()` uses, so the app that would actually run the
     * flow is the one whose cron and owner are used.
     *
     * @return array<int, array{id: string, enabled: bool, trigger: string, cron: string, owner: string|null}>
     *         The candidate flows.
     *
     * @spec openspec/changes/or-flow-schedule-any-store/specs/flow-scheduled-trigger/spec.md
     */
    public function scheduledFlows(): array
    {
        $candidates = [];
        foreach ($this->all() as $resolver) {
            if (($resolver instanceof IScheduledFlowSource) === false) {
                continue;
            }

            try {
                foreach ($resolver->scheduledFlows() as $candidate) {
                    $id = (string) ($candidate['id'] ?? '');
                    if ($id === '' || array_key_exists($id, $candidates) === true) {
                        continue;
                    }

                    $candidates[$id] = [
                        'id'      => $id,
                        'enabled' => (bool) ($candidate['enabled'] ?? false),
                        'trigger' => (string) ($candidate['trigger'] ?? ''),
                        'cron'    => (string) ($candidate['cron'] ?? ''),
                        'owner'   => ($candidate['owner'] ?? null),
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[FlowResolverRegistry] A resolver threw listing scheduled flows: '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }//end try
        }//end foreach

        return array_values($candidates);

    }//end scheduledFlows()

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
