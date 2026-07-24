<?php

/**
 * One execution of a flow.
 *
 * A run is the durable half of `FlowEngine`. The engine walks a Petri net in
 * memory; without this, a run cannot outlive the request that started it, and
 * five things become impossible: a Wait step, a sub-flow, run history, retry,
 * and executing a flow off the request that triggered it.
 *
 * The marking is what makes resumption work. It is the Petri net's token
 * placement — which places currently hold a token — so a suspended run is
 * restored by handing the stored marking back to `symfony/workflow` rather than
 * by replaying anything.
 *
 * `items` is the data channel at the point the run stopped, so resuming carries
 * the records forward rather than re-deriving them from the subject.
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
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class FlowRun
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getFlowId()
 * @method void setFlowId(?string $flowId)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method array|null getMarking()
 * @method void setMarking(?array $marking)
 * @method array|null getItems()
 * @method void setItems(?array $items)
 * @method array|null getContext()
 * @method void setContext(?array $context)
 * @method array|null getLog()
 * @method void setLog(?array $log)
 * @method string|null getSubjectUuid()
 * @method void setSubjectUuid(?string $subjectUuid)
 * @method string|null getSubjectRegister()
 * @method void setSubjectRegister(?string $subjectRegister)
 * @method string|null getSubjectSchema()
 * @method void setSubjectSchema(?string $subjectSchema)
 * @method string|null getTriggeredBy()
 * @method void setTriggeredBy(?string $triggeredBy)
 * @method string|null getTrigger()
 * @method void setTrigger(?string $trigger)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method DateTime|null getResumeAt()
 * @method void setResumeAt(?DateTime $resumeAt)
 * @method string|null getParentRunUuid()
 * @method void setParentRunUuid(?string $parentRunUuid)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class FlowRun extends Entity implements JsonSerializable
{

    /**
     * Statuses a run can hold.
     *
     * `suspended` is the one the engine did not previously have: the run is
     * mid-graph, waiting for a timer or a child run, and will be resumed. It is
     * deliberately distinct from `stopped`, which is terminal.
     */
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const STATUS_FAILED = 'failed';

    /**
     * Statuses from which a run will never advance on its own.
     *
     * @var array<int, string>
     */
    public const TERMINAL = [
        self::STATUS_COMPLETED,
        self::STATUS_STOPPED,
        self::STATUS_DEAD_LETTER,
        self::STATUS_FAILED,
    ];

    /**
     * Public identifier for this run.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * The flow this run executes.
     *
     * @var string|null
     */
    protected ?string $flowId = null;

    /**
     * Current lifecycle status.
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * The Petri-net marking — which places hold a token.
     *
     * @var array|null
     */
    protected ?array $marking = null;

    /**
     * The item list as it stood when the run last stopped.
     *
     * @var array|null
     */
    protected ?array $items = null;

    /**
     * Run-level metadata handed to every step.
     *
     * @var array|null
     */
    protected ?array $context = null;

    /**
     * Append-only per-step trace.
     *
     * @var array|null
     */
    protected ?array $log = null;

    /**
     * UUID of the object the run is about.
     *
     * @var string|null
     */
    protected ?string $subjectUuid = null;

    /**
     * Register slug of the subject object.
     *
     * @var string|null
     */
    protected ?string $subjectRegister = null;

    /**
     * Schema slug of the subject object.
     *
     * @var string|null
     */
    protected ?string $subjectSchema = null;

    /**
     * The user whose action started this run.
     *
     * @var string|null
     */
    protected ?string $triggeredBy = null;

    /**
     * What started the run (an event id, `manual`, `nc-flow`, `sub-flow`).
     *
     * @var string|null
     */
    protected ?string $trigger = null;

    /**
     * Owning organisation.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Failure message, when the run did not complete.
     *
     * @var string|null
     */
    protected ?string $error = null;

    /**
     * When a suspended run becomes eligible to resume.
     *
     * @var DateTime|null
     */
    protected ?DateTime $resumeAt = null;

    /**
     * The run that started this one, for sub-flows.
     *
     * @var string|null
     */
    protected ?string $parentRunUuid = null;

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
        $this->addType(fieldName: 'flowId', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'marking', type: 'json');
        $this->addType(fieldName: 'items', type: 'json');
        $this->addType(fieldName: 'context', type: 'json');
        $this->addType(fieldName: 'log', type: 'json');
        $this->addType(fieldName: 'subjectUuid', type: 'string');
        $this->addType(fieldName: 'subjectRegister', type: 'string');
        $this->addType(fieldName: 'subjectSchema', type: 'string');
        $this->addType(fieldName: 'triggeredBy', type: 'string');
        $this->addType(fieldName: 'trigger', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'error', type: 'string');
        $this->addType(fieldName: 'resumeAt', type: 'datetime');
        $this->addType(fieldName: 'parentRunUuid', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');

    }//end __construct()

    /**
     * Whether this run has finished for good.
     *
     * @return boolean True when no further progress is possible.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);

    }//end isTerminal()

    /**
     * Serialise for the API.
     *
     * @return array<string, mixed> The run as plain data.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function jsonSerialize(): array
    {
        $resumeAt = null;
        if ($this->resumeAt !== null) {
            $resumeAt = $this->resumeAt->format('c');
        }

        $created = null;
        if ($this->created !== null) {
            $created = $this->created->format('c');
        }

        $updated = null;
        if ($this->updated !== null) {
            $updated = $this->updated->format('c');
        }

        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'flowId'          => $this->flowId,
            'status'          => $this->status,
            'marking'         => ($this->marking ?? []),
            'items'           => ($this->items ?? []),
            'context'         => ($this->context ?? []),
            'log'             => ($this->log ?? []),
            'subjectUuid'     => $this->subjectUuid,
            'subjectRegister' => $this->subjectRegister,
            'subjectSchema'   => $this->subjectSchema,
            'triggeredBy'     => $this->triggeredBy,
            'trigger'         => $this->trigger,
            'organisation'    => $this->organisation,
            'error'           => $this->error,
            'resumeAt'        => $resumeAt,
            'parentRunUuid'   => $this->parentRunUuid,
            'created'         => $created,
            'updated'         => $updated,
        ];

    }//end jsonSerialize()
}//end class
