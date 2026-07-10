<?php

/**
 * OpenRegister declarative flow engine.
 *
 * Reads `x-openregister-flows` from a schema's configuration and runs the
 * declared actions when a matching object-lifecycle event fires (created /
 * updated / deleted). This is the "Nextcloud Flow" integration: simple business
 * logic declared in the register config (next to registers and schemas) that
 * hooks the object lifecycle. Actions reuse existing Nextcloud surfaces
 * (Calendar events as agenda tasks, email via IMailer); the set is extensible.
 *
 * Flows never throw into the save path: a failing action is logged and the
 * remaining actions/flows still run, so business logic can never corrupt a
 * write.
 *
 * The `agent` action is the ADR-041 cross-app command exception: it dispatches
 * an `AgentRunRequestedEvent` via `IEventDispatcher` instead of invoking an agent
 * runtime inline — OpenRegister never calls Hermiq (or any agent-runtime app)
 * directly. See lib/Event/AgentRunRequestedEvent.php.
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-agent-action/specs/flow-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\Mail\IMailer;
use OCA\OpenRegister\Service\FederationShareService;
use Psr\Log\LoggerInterface;

/**
 * Executes declarative flows attached to a schema via `x-openregister-flows`.
 *
 * @spec openspec/changes/flow-agent-action/tasks.md#task-2-2
 */
class FlowActionService
{
    /**
     * Dispatch modes the `agent` action supports in v1. Sync inline execution is
     * explicitly out of scope (SPECTR-NEXTCLOUD-PLAN.md §5.2 point 5) — a config
     * requesting any other mode is malformed and the action is skipped.
     *
     * @var array<int, string>
     */
    private const SUPPORTED_AGENT_MODES = ['async'];

    /**
     * Constructor.
     *
     * @param SchemaMapper           $schemaMapper           Resolves a schema by id.
     * @param CalendarEventService   $calendarEventService   Creates calendar events (agenda tasks).
     * @param IMailer                $mailer                 Sends email notifications.
     * @param IConfig                $config                 Reads the instance mail-from address.
     * @param LoggerInterface        $logger                 Logs flow execution + failures.
     * @param IEventDispatcher       $eventDispatcher        Dispatches AgentRunRequestedEvent (ADR-041).
     * @param FederationShareService $federationShareService Creates federated shares (federate-share action).
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly CalendarEventService $calendarEventService,
        private readonly IMailer $mailer,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly FederationShareService $federationShareService
    ) {
    }//end __construct()

    /**
     * Run every flow on the object's schema whose trigger matches.
     *
     * @param ObjectEntity $object  The object the lifecycle event fired on.
     * @param string       $trigger One of 'created' | 'updated' | 'deleted'.
     *
     * @return void
     *
     * @spec exclude declarative-flow engine ships without a formal openspec change; spec to be added in a follow-up ADR
     */
    public function run(ObjectEntity $object, string $trigger): void
    {
        try {
            $schema = $this->resolveSchema(object: $object);
        } catch (\Throwable $e) {
            return;
        }

        if ($schema === null) {
            return;
        }

        $flows = $this->flowsForSchema(schema: $schema);
        if (empty($flows) === true) {
            return;
        }

        $data = $this->buildContext(object: $object);

        foreach ($flows as $flow) {
            if (is_array($flow) === false) {
                continue;
            }

            $flowTrigger = (string) ($flow['trigger'] ?? 'created');
            if ($flowTrigger !== $trigger) {
                continue;
            }

            $actions = ($flow['actions'] ?? []);
            if (is_array($actions) === false) {
                continue;
            }

            foreach ($actions as $action) {
                if (is_array($action) === false) {
                    continue;
                }

                $this->runAction(
                    action: $action,
                    object: $object,
                    data: $data,
                    flowName: (string) ($flow['name'] ?? 'flow')
                );
            }
        }//end foreach
    }//end run()

    /**
     * Resolve the object's schema (internal lookup, no RBAC/multitenancy).
     *
     * @param ObjectEntity $object The object.
     *
     * @return Schema|null The resolved schema or null when unresolvable.
     */
    private function resolveSchema(ObjectEntity $object): ?Schema
    {
        $schemaId = $object->getSchema();
        if ($schemaId === null || $schemaId === '') {
            return null;
        }

        return $this->schemaMapper->find(id: (int) $schemaId, _rbac: false, _multitenancy: false);
    }//end resolveSchema()

    /**
     * Read the `x-openregister-flows` array from a schema's configuration.
     *
     * @param Schema $schema The schema.
     *
     * @return array<int, array> The declared flows (possibly empty).
     */
    private function flowsForSchema(Schema $schema): array
    {
        $config = ($schema->getConfiguration() ?? []);
        $flows  = ($config['x-openregister-flows'] ?? null);
        if (is_array($flows) === false) {
            return [];
        }

        // Accept either a list of flows or a single flow object.
        if (array_is_list($flows) === false) {
            return [$flows];
        }

        return $flows;
    }//end flowsForSchema()

    /**
     * Build the template context from the object's data plus @self metadata.
     *
     * @param ObjectEntity $object The object.
     *
     * @return array<string, mixed> Flat map of placeholder => value.
     */
    private function buildContext(ObjectEntity $object): array
    {
        $data = $object->getObject();
        if (is_array($data) === false) {
            $data = [];
        }

        $data['@id']   = $object->getUuid();
        $data['@uuid'] = $object->getUuid();
        $data['@name'] = $object->getName();
        return $data;
    }//end buildContext()

    /**
     * Render `{{ field }}` placeholders in a string against the context.
     *
     * @param mixed                $template The template string (non-strings pass through).
     * @param array<string, mixed> $data     The context map.
     *
     * @return string The rendered string.
     */
    private function render(mixed $template, array $data): string
    {
        if (is_string($template) === false) {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/',
            function (array $m) use ($data): string {
                $value = ($data[$m[1]] ?? '');
                if (is_array($value) === true) {
                    return implode(', ', array_map('strval', $value));
                }

                return (string) $value;
            },
            $template
        );
    }//end render()

    /**
     * Dispatch a single action by its declared type.
     *
     * @param array<string, mixed> $action   The action config.
     * @param ObjectEntity         $object   The triggering object.
     * @param array<string, mixed> $data     The template context.
     * @param string               $flowName The owning flow name (for logging).
     *
     * @return void
     */
    private function runAction(array $action, ObjectEntity $object, array $data, string $flowName): void
    {
        $type = (string) ($action['type'] ?? '');
        try {
            switch ($type) {
                case 'calendar-event':
                case 'agenda-task':
                    $this->runCalendarEvent(action: $action, object: $object, data: $data);
                    break;
                case 'email':
                case 'mail':
                    $this->runEmail(action: $action, data: $data);
                    break;
                case 'agent':
                    $this->runAgent(action: $action, object: $object, data: $data, flowName: $flowName);
                    break;
                case 'federate-share':
                    $this->runFederateShare(action: $action, object: $object);
                    break;
                default:
                    $this->logger->warning(
                        message: '[FlowActionService] Unknown flow action type',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'type' => $type, 'flow' => $flowName]
                    );
                    return;
            }//end switch

            $this->logger->info(
                message: '[FlowActionService] Flow action executed',
                context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowName, 'type' => $type, 'object' => $object->getUuid()]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[FlowActionService] Flow action failed',
                context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowName, 'type' => $type, 'error' => $e->getMessage()]
            );
        }//end try
    }//end runAction()

    /**
     * Share the triggering object with a federated organisation (rule-based).
     *
     * Config keys: `sharedWith` (slug@host, required), `permissions`
     * (read|read-write, default read). Idempotent — one object-scope share per
     * object per target — so it can fire on every matching save. The flow's own
     * conditions (`x-openregister-flows`) decide WHICH objects qualify (e.g.
     * `confidentiality == public && status == published`).
     *
     * @param array<string, mixed> $action The action config.
     * @param ObjectEntity         $object The triggering object.
     *
     * @return void
     */
    private function runFederateShare(array $action, ObjectEntity $object): void
    {
        $sharedWith = trim((string) ($action['sharedWith'] ?? ''));
        if ($sharedWith === '') {
            $this->logger->warning(
                message: '[FlowActionService] federate-share action missing sharedWith',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        $permissions = (string) ($action['permissions'] ?? 'read');
        $objectUri   = (string) ($object->getUri() ?? $object->getUuid());

        $this->federationShareService->ensureObjectShare(
            objectUri: $objectUri,
            register: (string) $object->getRegister(),
            schema: (string) $object->getSchema(),
            sharedWith: $sharedWith,
            permissions: $permissions
        );
    }//end runFederateShare()

    /**
     * Create a calendar event (agenda task) linked to the object.
     *
     * Config keys: summary, description, location, offsetDays (default 1),
     * durationMinutes (default 30).
     *
     * @param array<string, mixed> $action The action config.
     * @param ObjectEntity         $object The triggering object.
     * @param array<string, mixed> $data   The template context.
     *
     * @return void
     */
    private function runCalendarEvent(array $action, ObjectEntity $object, array $data): void
    {
        $offsetDays = (int) ($action['offsetDays'] ?? 1);
        $duration   = (int) ($action['durationMinutes'] ?? 30);

        $start = new \DateTime('now');
        $start->modify('+'.$offsetDays.' day');
        $start->setTime(hour: 9, minute: 0);
        $end = (clone $start)->modify('+'.$duration.' minute');

        $eventData = [
            'summary'     => $this->render(template: ($action['summary'] ?? 'Task'), data: $data),
            'description' => $this->render(template: ($action['description'] ?? ''), data: $data),
            'location'    => $this->render(template: ($action['location'] ?? ''), data: $data),
            'dtstart'     => $start->format('Y-m-d\TH:i:s'),
            'dtend'       => $end->format('Y-m-d\TH:i:s'),
        ];

        $this->calendarEventService->createEvent(
            registerId: (int) $object->getRegister(),
            schemaId: (int) $object->getSchema(),
            objectUuid: (string) $object->getUuid(),
            objectTitle: (string) ($object->getName() ?? $object->getUuid()),
            data: $eventData
        );
    }//end runCalendarEvent()

    /**
     * Send an email notification.
     *
     * Config keys: to (required, templated), subject, body.
     *
     * @param array<string, mixed> $action The action config.
     * @param array<string, mixed> $data   The template context.
     *
     * @return void
     */
    private function runEmail(array $action, array $data): void
    {
        $to = trim($this->render(template: ($action['to'] ?? ''), data: $data));
        if ($to === '') {
            return;
        }

        $subject = $this->render(template: ($action['subject'] ?? 'Notification'), data: $data);
        $body    = $this->render(template: ($action['body'] ?? ''), data: $data);

        $message = $this->mailer->createMessage();
        $message->setTo([$to]);
        $message->setSubject($subject);
        $message->setPlainBody($body);

        $from   = $this->config->getSystemValue('mail_from_address', 'no-reply');
        $domain = $this->config->getSystemValue('mail_domain', 'localhost');
        $message->setFrom([$from.'@'.$domain => 'OpenRegister']);

        $this->mailer->send($message);
    }//end runEmail()

    /**
     * Dispatch an `AgentRunRequestedEvent` requesting a governed agent run.
     *
     * OpenRegister never invokes an agent runtime inline and never calls the
     * consuming app (e.g. Hermiq) directly — this is the ADR-041 cross-app
     * command recipe. A consumer app registers an `IEventListener` for
     * `AgentRunRequestedEvent` and performs the governed run (kill-switch,
     * human-approval gate, redacted audit, the agent turn, and the result
     * write-back) through its own services. If no listener is installed the
     * dispatch is a silent no-op — objects keep flowing (SPECTR-NEXTCLOUD-PLAN.md
     * §5.2 point 4).
     *
     * Config keys: agent (required, UUID), skill (optional slug), prompt
     * (required, templated), resultField (required), requiresApproval
     * (optional, default false), mode (optional, default "async" — the only
     * supported value in v1).
     *
     * @param array<string, mixed> $action   The action config.
     * @param ObjectEntity         $object   The triggering object.
     * @param array<string, mixed> $data     The template context.
     * @param string               $flowName The owning flow name (for logging).
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-action/tasks.md#task-2-2
     */
    private function runAgent(array $action, ObjectEntity $object, array $data, string $flowName): void
    {
        $agent = trim((string) ($action['agent'] ?? ''));
        if ($agent === '') {
            $this->logger->warning(
                message: '[FlowActionService] Malformed agent flow action: missing "agent" reference',
                context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowName, 'object' => $object->getUuid()]
            );
            return;
        }

        $resultField = trim((string) ($action['resultField'] ?? ''));
        if ($resultField === '') {
            $this->logger->warning(
                message: '[FlowActionService] Malformed agent flow action: missing "resultField"',
                context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowName, 'object' => $object->getUuid()]
            );
            return;
        }

        $mode = (string) ($action['mode'] ?? 'async');
        if (in_array($mode, self::SUPPORTED_AGENT_MODES, true) === false) {
            $this->logger->warning(
                message: '[FlowActionService] Malformed agent flow action: unsupported mode (only "async" is supported in v1)',
                context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowName, 'mode' => $mode, 'object' => $object->getUuid()]
            );
            return;
        }

        $skill = null;
        if (isset($action['skill']) === true && trim((string) $action['skill']) !== '') {
            $skill = trim((string) $action['skill']);
        }

        $prompt = $this->render(template: ($action['prompt'] ?? ''), data: $data);

        $event = new AgentRunRequestedEvent(
            subjectUuid: (string) $object->getUuid(),
            subjectRegister: (string) $object->getRegister(),
            subjectSchema: (string) $object->getSchema(),
            agent: $agent,
            skill: $skill,
            prompt: $prompt,
            resultField: $resultField,
            requiresApproval: (bool) ($action['requiresApproval'] ?? false),
            mode: $mode,
            flowName: $flowName
        );

        $this->eventDispatcher->dispatchTyped($event);
    }//end runAgent()
}//end class
