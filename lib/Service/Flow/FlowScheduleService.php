<?php

/**
 * Fires flows that are due on a schedule — n8n's Schedule / Cron trigger.
 *
 * The event triggers (object, file, user, share, tag) run a flow when something
 * happens. A schedule runs it when a time arrives. There is no event to listen
 * for, so this is driven by a worker ({@see FlowScheduleWorker}) that ticks
 * periodically and asks this service which scheduled flows are now due.
 *
 * A scheduled flow is an OpenRegister flow (in the flow store) whose `trigger`
 * is `schedule` and which carries a `cron` expression. "Due" is decided per
 * flow: the last time it fired is remembered (in app config, keyed by the flow's
 * uuid — so the flow object itself is never rewritten), and a flow is due when a
 * cron occurrence has passed since then. A flow seen for the first time has no
 * last-fire, so it fires once on the next tick and then follows its cron.
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
 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues runs for the scheduled flows that are due.
 */
class FlowScheduleService
{

    /**
     * The app id its config lives under.
     */
    private const APP_ID = 'openregister';

    /**
     * Config-key prefix for a flow's last-fire timestamp (per flow uuid).
     */
    private const LAST_FIRE_KEY = 'flow_sched_last_';

    /**
     * Config keys (and defaults) naming the register and schema flows live in.
     */
    private const REGISTER_KEY = 'flow_register';

    private const REGISTER_DEFAULT = 'flows';

    private const SCHEMA_KEY = 'flow_schema';

    private const SCHEMA_DEFAULT = 'flow';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService Lists the scheduled flows.
     * @param FlowRunService  $runs          Queues a run for a due flow.
     * @param IAppConfig      $appConfig     Names the store; remembers last-fire.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly FlowRunService $runs,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Queue a run for every scheduled flow that is due at $now.
     *
     * @param DateTimeInterface $now The moment to evaluate against.
     *
     * @return array<int, string> The uuids of the flows that fired.
     *
     * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
     */
    public function fireDueFlows(DateTimeInterface $now): array
    {
        try {
            $flows = $this->objectService->findAll(
                config: ['filters' => ['register' => $this->flowRegister(), 'schema' => $this->flowSchema()]],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            // No flow store yet — nothing scheduled.
            return [];
        }

        $fired = [];
        foreach ($flows as $flow) {
            if (($flow instanceof ObjectEntity) === false) {
                continue;
            }

            $cron = $this->scheduleOf(flow: $flow);
            if ($cron === null) {
                continue;
            }

            $uuid = (string) $flow->getUuid();
            if ($this->isDue(cron: $cron, uuid: $uuid, now: $now) === false) {
                continue;
            }

            // SINGLETON: never overlap a flow with itself.
            //
            // A scheduled flow can be slower than its own interval — a pipeline
            // poll on a five-minute cron easily is — and without this guard
            // tick N+1 starts while tick N is still going. Two runs of one flow
            // then race on whatever that flow is bookkeeping, which is exactly
            // the failure openregister#2212 documented at the object layer.
            //
            // This is the property that makes the shell orchestrator being
            // replaced safe today: `hydra-supervisor.sh` holds an exclusive
            // flock, so exactly one supervisor exists and its check-then-write
            // slot bookkeeping never races because nothing runs beside it. A
            // scheduled flow gets the same guarantee here, and with it most
            // flow state needs no locking at all.
            //
            // The last-fire marker is deliberately NOT advanced when we skip:
            // the flow stays due, so it starts on the first tick after the
            // previous run finishes rather than waiting a whole extra interval.
            if ($this->runs->hasActiveRun(flowId: $uuid) === true) {
                $this->logger->info(
                    message: '[FlowSchedule] Skipping due flow — previous run has not finished',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'flow' => $uuid,
                    ]
                );
                continue;
            }

            $this->fire(uuid: $uuid, now: $now, owner: $flow->getOwner());
            $fired[] = $uuid;
        }//end foreach

        return $fired;

    }//end fireDueFlows()

    /**
     * The cron expression of a flow that is an enabled schedule, or null.
     *
     * @param ObjectEntity $flow The flow object.
     *
     * @return string|null The cron expression, or null when the flow is not a
     *                     valid enabled schedule.
     */
    private function scheduleOf(ObjectEntity $flow): ?string
    {
        $data = $flow->getObject();
        if (($data['enabled'] ?? false) !== true) {
            return null;
        }

        if ((string) ($data['trigger'] ?? '') !== 'schedule') {
            return null;
        }

        $cron = trim((string) ($data['cron'] ?? ''));
        if ($cron === '' || CronExpression::isValidExpression($cron) === false) {
            return null;
        }

        return $cron;

    }//end scheduleOf()

    /**
     * Whether a cron occurrence has passed since the flow last fired.
     *
     * A flow with no recorded last-fire is treated as never run, so its next
     * occurrence after the epoch is in the past and it fires once on this tick.
     *
     * @param string            $cron The cron expression.
     * @param string            $uuid The flow uuid.
     * @param DateTimeInterface $now  The moment to evaluate against.
     *
     * @return boolean Whether the flow is due.
     */
    private function isDue(string $cron, string $uuid, DateTimeInterface $now): bool
    {
        $lastRaw = $this->appConfig->getValueString(self::APP_ID, self::LAST_FIRE_KEY.$uuid, '');

        // A flow never fired has an epoch baseline, so its next occurrence is in
        // the past and it fires once on this tick.
        $lastStamp = '@0';
        if ($lastRaw !== '') {
            $lastStamp = $lastRaw;
        }

        try {
            $next = (new CronExpression($cron))->getNextRunDate(new DateTimeImmutable($lastStamp));
        } catch (Throwable $e) {
            return false;
        }

        return $next <= $now;

    }//end isDue()

    /**
     * Queue a run for a due flow and record that it fired.
     *
     * @param string            $uuid  The flow uuid.
     * @param DateTimeInterface $now   The moment it fired.
     * @param string|null       $owner The user the run is attributed to, if known.
     *
     * @return void
     */
    private function fire(string $uuid, DateTimeInterface $now, ?string $owner=null): void
    {
        // A scheduled run has no session, so the owner comes from the flow
        // object itself — the person who created and enabled it. Without this
        // the run is ownerless, context['triggeredBy'] is null, and every
        // attribution-requiring node refuses: ObjectWriteNode returns "this
        // flow run has no owner". That made every natively-scheduled flow
        // silently incapable of writing anything. Fourth instance of the
        // or#2158 defect class, after FlowMcpToolProvider::runFlow(),
        // FlowRunService::execute() and FlowRunController::test().
        $this->runs->queue(
            flowId: $uuid,
            subject: [],
            trigger: 'schedule',
            context: ['payload' => ['flowId' => $uuid, 'scheduledAt' => $now->format(DATE_ATOM)]],
            user: $owner
        );

        // Remember the fire time so the next occurrence is measured from here,
        // not from the epoch — otherwise the flow would fire every tick.
        $this->appConfig->setValueString(self::APP_ID, self::LAST_FIRE_KEY.$uuid, $now->format(DATE_ATOM));

    }//end fire()

    /**
     * The register flows are stored in.
     *
     * @return string The register slug.
     */
    private function flowRegister(): string
    {
        return $this->appConfig->getValueString(self::APP_ID, self::REGISTER_KEY, self::REGISTER_DEFAULT);

    }//end flowRegister()

    /**
     * The schema flows are stored under.
     *
     * @return string The schema slug.
     */
    private function flowSchema(): string
    {
        return $this->appConfig->getValueString(self::APP_ID, self::SCHEMA_KEY, self::SCHEMA_DEFAULT);

    }//end flowSchema()
}//end class
