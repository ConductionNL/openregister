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
use OCA\OpenRegister\Service\ObjectService;
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
     * @param EventCatalogService    $eventCatalog           Resolves trigger aliases (catalog id ⇄ legacy).
     * @param ObjectService          $objectService          Reads and writes objects for the object-CRUD actions.
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly CalendarEventService $calendarEventService,
        private readonly IMailer $mailer,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly FederationShareService $federationShareService,
        private readonly EventCatalogService $eventCatalog,
        private readonly ObjectService $objectService
    ) {
    }//end __construct()

    /**
     * UUIDs of objects a flow is currently acting on, guarding against
     * unbounded re-entry when an object-CRUD action writes an object that
     * re-dispatches a lifecycle event into this same service.
     *
     * @var array<string, true>
     */
    private array $activeObjects = [];

    /**
     * Run every flow on the object's schema whose trigger matches.
     *
     * @param ObjectEntity $object  The object the lifecycle event fired on.
     * @param string       $trigger A catalog trigger id (e.g. 'object.created', 'object.transitioned')
     *                              or a legacy alias ('created'|'updated'|'deleted'); the two forms are
     *                              interchangeable via EventCatalogService::aliasesFor().
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

        // Recursion guard: an object-CRUD action may write this very object and
        // re-dispatch a lifecycle event back into run(); skip the re-entry.
        $guardKey = (string) $object->getUuid();
        if ($guardKey !== '' && isset($this->activeObjects[$guardKey]) === true) {
            return;
        }

        if ($guardKey !== '') {
            $this->activeObjects[$guardKey] = true;
        }

        try {
            $data = $this->buildContext(object: $object);

            foreach ($flows as $flow) {
                if (is_array($flow) === false) {
                    continue;
                }

                $flowTrigger = (string) ($flow['trigger'] ?? 'created');
                if (in_array($flowTrigger, $this->eventCatalog->aliasesFor($trigger), true) === false) {
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

                    // A false return (a condition that failed) halts the rest
                    // of this flow's actions.
                    if ($this->runAction(
                        action: $action,
                        object: $object,
                        data: $data,
                        flowName: (string) ($flow['name'] ?? 'flow')
                    ) === false
                    ) {
                        break;
                    }
                }
            }//end foreach
        } finally {
            if ($guardKey !== '') {
                unset($this->activeObjects[$guardKey]);
            }
        }//end try
    }//end run()

    /**
     * Run a single named flow's actions against an object, ignoring the flow's
     * own trigger.
     *
     * Used by the native Nextcloud Flow operation
     * ({@see \OCA\OpenRegister\WorkflowEngine\RunFlowOperation}) so an admin can
     * gate a specific flow behind Flow's check system. The flow is looked up by
     * `name` on the object's schema; its actions run regardless of the flow's
     * declared `trigger`, because the matching Flow rule already decided it
     * should fire.
     *
     * @param ObjectEntity $object   The object the rule matched.
     * @param string       $flowName The `name` of the flow to run.
     *
     * @return void
     *
     * @spec openspec/changes/visual-flow-builder/specs/integration-flow/spec.md
     */
    public function runNamedFlow(ObjectEntity $object, string $flowName): void
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

        $guardKey = (string) $object->getUuid();
        if ($guardKey !== '' && isset($this->activeObjects[$guardKey]) === true) {
            return;
        }

        if ($guardKey !== '') {
            $this->activeObjects[$guardKey] = true;
        }

        try {
            $data = $this->buildContext(object: $object);

            foreach ($flows as $flow) {
                if (is_array($flow) === false) {
                    continue;
                }

                if ((string) ($flow['name'] ?? '') !== $flowName) {
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

                    if ($this->runAction(
                        action: $action,
                        object: $object,
                        data: $data,
                        flowName: $flowName
                    ) === false
                    ) {
                        break;
                    }
                }
            }//end foreach
        } finally {
            if ($guardKey !== '') {
                unset($this->activeObjects[$guardKey]);
            }
        }//end try
    }//end runNamedFlow()

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

        $data['@id']       = $object->getUuid();
        $data['@uuid']     = $object->getUuid();
        $data['@name']     = $object->getName();
        $data['@register'] = $object->getRegister();
        $data['@schema']   = $object->getSchema();
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
    private function runAction(array $action, ObjectEntity $object, array $data, string $flowName): bool
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
                case 'condition':
                    // A guard: when the condition is false, halt the flow so no
                    // subsequent actions run.
                    if ($this->evaluateCondition(action: $action, data: $data) === false) {
                        $this->logger->info(
                            message: '[FlowActionService] Flow halted by condition',
                            context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowName]
                        );
                        return false;
                    }
                    return true;
                case 'object.set-field':
                case 'object.update':
                    $this->runObjectSetField(action: $action, object: $object, data: $data);
                    break;
                case 'object.create':
                    $this->runObjectCreate(action: $action, data: $data);
                    break;
                case 'object.delete':
                    $this->runObjectDelete(action: $action, object: $object, data: $data);
                    break;
                default:
                    $this->logger->warning(
                        message: '[FlowActionService] Unknown flow action type',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'type' => $type, 'flow' => $flowName]
                    );
                    return true;
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

        return true;
    }//end runAction()

    /**
     * Evaluate a `condition` guard against the flow context.
     *
     * Config keys: `field` (context key), `operator` (one of eq, ne, empty,
     * notEmpty, contains; default eq), `value` (compared value, templated).
     * An unknown operator or missing field is treated as false (fail closed).
     *
     * @param array<string, mixed> $action The action config.
     * @param array<string, mixed> $data   The template context.
     *
     * @return bool True when the condition holds and the flow may continue.
     */
    private function evaluateCondition(array $action, array $data): bool
    {
        $field    = (string) ($action['field'] ?? '');
        $operator = (string) ($action['operator'] ?? 'eq');
        $expected = $this->render(template: (string) ($action['value'] ?? ''), data: $data);
        $actual   = ($data[$field] ?? null);
        if (is_array($actual) === true) {
            $actual = implode(', ', array_map('strval', $actual));
        }

        $actualStr = '';
        if ($actual !== null) {
            $actualStr = (string) $actual;
        }

        switch ($operator) {
            case 'eq':
                return $actualStr === $expected;
            case 'ne':
                return $actualStr !== $expected;
            case 'empty':
                return $actualStr === '';
            case 'notEmpty':
                return $actualStr !== '';
            case 'contains':
                return $expected !== '' && str_contains($actualStr, $expected);
            default:
                return false;
        }
    }//end evaluateCondition()

    /**
     * Apply `object.set-field` / `object.update`: merge templated fields onto the
     * triggering object and persist it (PUT-semantic — all existing fields are
     * carried forward so unrelated data is never dropped).
     *
     * Config keys: `fields` (map of field => value template) and/or `field` +
     * `value` for a single field.
     *
     * @param array<string, mixed> $action The action config.
     * @param ObjectEntity         $object The triggering object.
     * @param array<string, mixed> $data   The template context.
     *
     * @return void
     */
    private function runObjectSetField(array $action, ObjectEntity $object, array $data): void
    {
        $updates = $this->resolveFields(action: $action, data: $data);
        if (empty($updates) === true) {
            return;
        }

        $current = $object->getObject();
        if (is_array($current) === false) {
            $current = [];
        }

        $merged = array_merge($current, $updates);

        $this->objectService->saveObject(
            object: $merged,
            register: $object->getRegister(),
            schema: $object->getSchema(),
            uuid: (string) $object->getUuid()
        );
    }//end runObjectSetField()

    /**
     * Apply `object.create`: create a new object with templated fields.
     *
     * Config keys: `register` + `schema` (target; default the triggering
     * object's), and `fields` (map of field => value template).
     *
     * @param array<string, mixed> $action The action config.
     * @param array<string, mixed> $data   The template context.
     *
     * @return void
     */
    private function runObjectCreate(array $action, array $data): void
    {
        $fields = $this->resolveFields(action: $action, data: $data);

        $register = ($action['register'] ?? ($data['@register'] ?? null));
        $schema   = ($action['schema'] ?? ($data['@schema'] ?? null));
        if ($register === null || $schema === null || $register === '' || $schema === '') {
            $this->logger->warning(
                message: '[FlowActionService] object.create missing register/schema',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        $this->objectService->saveObject(
            object: $fields,
            register: $register,
            schema: $schema
        );
    }//end runObjectCreate()

    /**
     * Apply `object.delete`: delete the triggering object, or a `target` uuid.
     *
     * @param array<string, mixed> $action The action config.
     * @param ObjectEntity         $object The triggering object.
     * @param array<string, mixed> $data   The template context.
     *
     * @return void
     */
    private function runObjectDelete(array $action, ObjectEntity $object, array $data): void
    {
        $target = trim($this->render(template: (string) ($action['target'] ?? ''), data: $data));
        $uuid   = (string) $object->getUuid();
        if ($target !== '') {
            $uuid = $target;
        }

        if ($uuid === '') {
            return;
        }

        $this->objectService->deleteObject(
            uuid: $uuid,
            register: $object->getRegister(),
            schema: $object->getSchema()
        );
    }//end runObjectDelete()

    /**
     * Resolve an action's field map, rendering each value against the context.
     *
     * Accepts `fields` (map) and/or a single `field` + `value` pair.
     *
     * @param array<string, mixed> $action The action config.
     * @param array<string, mixed> $data   The template context.
     *
     * @return array<string, string> The resolved field => value map.
     */
    private function resolveFields(array $action, array $data): array
    {
        $out    = [];
        $fields = ($action['fields'] ?? null);
        if (is_array($fields) === true) {
            foreach ($fields as $key => $tpl) {
                if (is_string($key) === true && $key !== '') {
                    $out[$key] = $this->render(template: (string) $tpl, data: $data);
                }
            }
        }

        $single = (string) ($action['field'] ?? '');
        if ($single !== '') {
            $out[$single] = $this->render(template: (string) ($action['value'] ?? ''), data: $data);
        }

        return $out;
    }//end resolveFields()

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
