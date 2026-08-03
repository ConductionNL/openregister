<?php

/**
 * A flow definition — the single, native flow store.
 *
 * A flow is a graph of nodes and edges the engine walks. This entity is
 * deliberately NOT an OpenRegister object: definitions used to live in a
 * register/schema, which meant every app that owned flows needed its own
 * register, its own resolver and (in practice) its own executor. `app` replaces
 * all of that — one table, one engine, one row per flow, scoped by the id of the
 * Nextcloud app that owns it.
 *
 * Two fields are load-bearing rather than descriptive:
 *
 * - `owner` is the identity a run executes as. A trigger fires with no acting
 *   user, so without an owner there is nobody to attribute or authorise the run
 *   to. A flow with no owner MUST NOT dispatch — see `canDispatch()`.
 * - `limits` bounds the walk (`maxNodes`, `maxIterations`). It is what makes a
 *   cyclic graph terminate instead of looping without bound.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class Flow
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getApp()
 * @method void setApp(?string $app)
 * @method boolean|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 * @method string|null getTrigger()
 * @method void setTrigger(?string $trigger)
 * @method string|null getTriggerRegister()
 * @method void setTriggerRegister(?string $triggerRegister)
 * @method string|null getTriggerSchema()
 * @method void setTriggerSchema(?string $triggerSchema)
 * @method string|null getCron()
 * @method void setCron(?string $cron)
 * @method string|null getExecutionMode()
 * @method void setExecutionMode(?string $executionMode)
 * @method array|null getNodes()
 * @method void setNodes(?array $nodes)
 * @method array|null getEdges()
 * @method void setEdges(?array $edges)
 * @method array|null getLimits()
 * @method void setLimits(?array $limits)
 * @method integer|null getRetentionDays()
 * @method void setRetentionDays(?int $retentionDays)
 * @method boolean|null getAuditEnabled()
 * @method void setAuditEnabled(?bool $auditEnabled)
 * @method boolean|null getOversightEnabled()
 * @method void setOversightEnabled(?bool $oversightEnabled)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class Flow extends Entity implements JsonSerializable
{
    /**
     * Execution mode: queue the run for the background worker.
     *
     * @var string
     */
    public const MODE_ASYNC = 'async';

    /**
     * Execution mode: run inside the triggering action.
     *
     * @var string
     */
    public const MODE_SYNC = 'sync';

    /**
     * Trigger value for a flow that only runs on request.
     *
     * @var string
     */
    public const TRIGGER_MANUAL = 'manual';

    /**
     * Trigger value for a flow driven by its cron expression.
     *
     * @var string
     */
    public const TRIGGER_SCHEDULE = 'schedule';

    /**
     * Public identifier, used everywhere outside the database.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Human-readable name of the flow.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * What the flow does.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * The Nextcloud app id that owns this flow.
     *
     * The per-app scoping key: OpenConnector's index lists `openconnector`,
     * hermiq's lists `hermiq`, and OpenRegister's lists everything.
     *
     * @var string|null
     */
    protected ?string $app = null;

    /**
     * Whether the flow is active. A trigger only fires an enabled flow.
     *
     * @var boolean|null
     */
    protected ?bool $enabled = false;

    /**
     * The event that starts the flow (a catalog trigger id, `manual`, or
     * `schedule`).
     *
     * @var string|null
     */
    protected ?string $trigger = null;

    /**
     * Restrict an object-event trigger to one register (empty = any).
     *
     * @var string|null
     */
    protected ?string $triggerRegister = null;

    /**
     * Restrict an object-event trigger to one schema (empty = any).
     *
     * @var string|null
     */
    protected ?string $triggerSchema = null;

    /**
     * For a `schedule` trigger: the cron expression deciding when it runs.
     *
     * @var string|null
     */
    protected ?string $cron = null;

    /**
     * When to run relative to what triggered it (`async` or `sync`).
     *
     * @var string|null
     */
    protected ?string $executionMode = self::MODE_ASYNC;

    /**
     * The graph's nodes.
     *
     * @var array|null
     */
    protected ?array $nodes = null;

    /**
     * The graph's edges. Each edge carries the step to run.
     *
     * @var array|null
     */
    protected ?array $edges = null;

    /**
     * Execution bounds (`maxNodes`, `maxIterations`).
     *
     * @var array|null
     */
    protected ?array $limits = null;

    /**
     * Per-flow run-history retention, in days.
     *
     * NULL means "follow the administrator setting" and must STAY null:
     * materialising the current default here would silently exempt this flow
     * from any later change to that default.
     *
     * @var integer|null
     */
    protected ?int $retentionDays = null;

    /**
     * Whether each hop writes an audit-trail entry.
     *
     * NULL means "follow the administrator setting", for the same reason
     * `retentionDays` does.
     *
     * @var boolean|null
     */
    protected ?bool $auditEnabled = null;

    /**
     * Whether the oversight gate runs before each hop.
     *
     * NULL means "follow the administrator setting".
     *
     * @var boolean|null
     */
    protected ?bool $oversightEnabled = null;

    /**
     * Nextcloud UID of the person who authored and activated this flow.
     *
     * @var string|null
     */
    protected ?string $owner = null;

    /**
     * The organisation this flow belongs to; every query is scoped by it.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Free-form working notes. Not read by the engine.
     *
     * @var string|null
     */
    protected ?string $notes = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Last-modified timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Constructor: declare field types so the mapper hydrates them correctly.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'app', type: 'string');
        $this->addType(fieldName: 'enabled', type: 'boolean');
        $this->addType(fieldName: 'trigger', type: 'string');
        $this->addType(fieldName: 'triggerRegister', type: 'string');
        $this->addType(fieldName: 'triggerSchema', type: 'string');
        $this->addType(fieldName: 'cron', type: 'string');
        $this->addType(fieldName: 'executionMode', type: 'string');
        $this->addType(fieldName: 'nodes', type: 'json');
        $this->addType(fieldName: 'edges', type: 'json');
        $this->addType(fieldName: 'limits', type: 'json');
        $this->addType(fieldName: 'retentionDays', type: 'integer');
        $this->addType(fieldName: 'auditEnabled', type: 'boolean');
        $this->addType(fieldName: 'oversightEnabled', type: 'boolean');
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'notes', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');

    }//end __construct()

    /**
     * Whether a trigger or schedule may dispatch this flow.
     *
     * Enabled alone is not enough. A trigger fires with no acting user, so a
     * run needs an owner to be attributed to and to execute as; dispatching an
     * ownerless flow would mean picking an identity for it, and every available
     * choice (empty, system, admin) is a privilege decision nobody made.
     * Refusing is the only safe answer.
     *
     * @return boolean True when the flow may be dispatched.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function canDispatch(): bool
    {
        if ($this->enabled !== true) {
            return false;
        }

        return $this->owner !== null && trim($this->owner) !== '';

    }//end canDispatch()

    /**
     * Whether this flow belongs to the given organisation.
     *
     * The per-flow authorisation predicate. `findByUuid()` deliberately does
     * not scope, so that "no such flow" stays distinguishable from "not yours";
     * this is the check every caller that acts on a flow it was handed by id
     * must apply. A flow with no organisation belongs to nobody and is refused
     * rather than treated as public — an unattributed row is the shape a
     * pre-scoping or forged record takes, and defaulting it open is how the
     * `retry()` IDOR (or#2290) happened in this same subsystem.
     *
     * @param string|null $organisation The acting user's organisation uuid.
     *
     * @return boolean True when the caller's organisation owns this flow.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function belongsTo(?string $organisation): bool
    {
        if ($this->organisation === null || trim($this->organisation) === '') {
            return false;
        }

        if ($organisation === null || trim($organisation) === '') {
            return false;
        }

        return $this->organisation === $organisation;

    }//end belongsTo()

    /**
     * The effective run-history retention for this flow, in days.
     *
     * A per-flow override wins over the administrator default in BOTH
     * directions — a flow may deliberately keep less history than the instance
     * default (noisy, high-frequency) or more (audited, low-frequency). Only a
     * null or non-positive override falls back.
     *
     * @param integer $defaultDays The administrator-configured default.
     *
     * @return integer The retention period to apply to this flow's runs.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    public function effectiveRetentionDays(int $defaultDays): int
    {
        if ($this->retentionDays !== null && $this->retentionDays > 0) {
            return $this->retentionDays;
        }

        return $defaultDays;

    }//end effectiveRetentionDays()

    /**
     * Whether this flow writes an audit-trail entry per hop.
     *
     * Off by default at the instance level: one entry per node per run is real
     * write volume, and most flows do not need a compliance record of every
     * hop — the step rows already carry the operational history. A flow that
     * does need it opts in, and a regulated instance can flip the default and
     * have every flow that has not deliberately opted out follow.
     *
     * @param boolean $instanceDefault The administrator-configured default.
     *
     * @return boolean True when each hop should be audit-trailed.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    public function auditsEachHop(bool $instanceDefault): bool
    {
        if ($this->auditEnabled !== null) {
            return $this->auditEnabled;
        }

        return $instanceDefault;

    }//end auditsEachHop()

    /**
     * Whether the oversight gate runs before each hop of this flow.
     *
     * On by default, and deliberately the opposite default to auditing: this is
     * the check that stops a running flow when the kill switch is thrown or a
     * registered budget is exhausted. A safety rail that defaults to off
     * protects only the flows someone remembered to configure.
     *
     * An explicit `false` is a real opt-out and is honoured — a flow may be
     * exempted — but it has to be chosen, not inherited from an unset column.
     *
     * @param boolean $instanceDefault The administrator-configured default.
     *
     * @return boolean True when oversight checks should gate each hop.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    public function gatesEachHop(bool $instanceDefault): bool
    {
        if ($this->oversightEnabled !== null) {
            return $this->oversightEnabled;
        }

        return $instanceDefault;

    }//end gatesEachHop()

    /**
     * Serialise for the API.
     *
     * @return array<string, mixed> The flow as plain data.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function jsonSerialize(): array
    {
        $created = null;
        if ($this->created !== null) {
            $created = $this->created->format('c');
        }

        $updated = null;
        if ($this->updated !== null) {
            $updated = $this->updated->format('c');
        }

        return [
            'id'               => $this->uuid,
            'uuid'             => $this->uuid,
            'name'             => $this->name,
            'description'      => $this->description,
            'app'              => $this->app,
            'enabled'          => (bool) $this->enabled,
            'trigger'          => $this->trigger,
            'triggerRegister'  => $this->triggerRegister,
            'triggerSchema'    => $this->triggerSchema,
            'cron'             => $this->cron,
            'executionMode'    => ($this->executionMode ?? self::MODE_ASYNC),
            'nodes'            => ($this->nodes ?? []),
            'edges'            => ($this->edges ?? []),
            'limits'           => ($this->limits ?? []),
            'retentionDays'    => $this->retentionDays,
            'auditEnabled'     => $this->auditEnabled,
            'oversightEnabled' => $this->oversightEnabled,
            'owner'            => $this->owner,
            'organisation'     => $this->organisation,
            'notes'            => $this->notes,
            'created'          => $created,
            'updated'          => $updated,
        ];

    }//end jsonSerialize()
}//end class
