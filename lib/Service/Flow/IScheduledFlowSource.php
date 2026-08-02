<?php

/**
 * Lets an app that owns flows expose the ones that run on a schedule.
 *
 * The event triggers already work for any app: an object write asks every
 * contributed {@see IFlowResolver} which of its flows are wired to the event, so
 * a flow living in a leaf app's own store fires exactly like one living in
 * OpenRegister's. A schedule had no such path. {@see FlowScheduleService} read
 * one hard-coded store — the `flow_register`/`flow_schema` pair — so a flow that
 * declared `trigger: schedule` anywhere else was simply invisible to the
 * scheduler and could never fire. hermiq's agentflows were the first casualty:
 * correct flows, a correct cron, and nothing to notice them.
 *
 * This is the missing half of the resolver contract. It is deliberately a
 * SEPARATE interface rather than three more methods on `IFlowResolver`, so an
 * existing resolver keeps compiling and an app that owns only event-triggered
 * flows never has to answer a question it has no answer to.
 *
 * A source reports CANDIDATES, not decisions. It says "these are the flows I own
 * that mention a schedule", and the scheduler decides which of them may run:
 * `enabled` is re-checked centrally, the trigger is re-checked centrally, the
 * cron is re-parsed centrally, and the no-overlap guarantee (openregister#2218)
 * is applied centrally. A source that got its own filtering wrong therefore
 * cannot make a disabled flow run.
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
 * @spec openspec/changes/or-flow-schedule-any-store/specs/flow-scheduled-trigger/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Contract for listing the scheduled flows an app owns.
 */
interface IScheduledFlowSource
{
    /**
     * The flows this app owns that declare a schedule.
     *
     * Implementations SHOULD narrow to flows whose `trigger` is `schedule`,
     * because the scheduler ticks every five minutes and listing a whole flow
     * store each time is wasted work. They MUST report `enabled` honestly
     * rather than silently omitting disabled flows — the scheduler needs the
     * flag to make the decision, and reporting it keeps the "a disabled flow
     * never runs" rule in one place.
     *
     * The return is typed loosely on purpose. An implementation lives in
     * ANOTHER app, so its shape is a contract rather than a guarantee, and the
     * registry reads every key defensively. Each entry SHOULD carry:
     *
     * - `id`      — the flow id the engine runs (what `resolveFlow()` accepts)
     * - `enabled` — boolean, reported rather than filtered on
     * - `trigger` — the flow's trigger, normally `schedule`
     * - `cron`    — the cron expression, or an empty string
     * - `owner`   — the user the run is attributed to, or null
     *
     * @return array<int, array<string, mixed>> One entry per candidate flow.
     *
     * @spec openspec/changes/or-flow-schedule-any-store/specs/flow-scheduled-trigger/spec.md
     */
    public function scheduledFlows(): array;
}//end interface
