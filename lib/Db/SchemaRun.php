<?php

/**
 * SchemaRun entity — a revalidation, migration or rollback run over a
 * schema's object population.
 *
 * A run is a first-class, resumable record: it carries the run type, the
 * state-machine state, an optional proposed-definition snapshot (for
 * dry-runs of an edit), the migration plan, progress counters, a resumable
 * cursor, a summary report, and the starting user. Large per-object report
 * entries live in a side table ({@see SchemaRunEntry}) so a run row stays
 * small regardless of population size.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class SchemaRun
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int getSchemaId()
 * @method void setSchemaId(int $schemaId)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method array|null getProposedDefinition()
 * @method void setProposedDefinition(?array $proposedDefinition)
 * @method array|null getPlan()
 * @method void setPlan(?array $plan)
 * @method array|null getOptions()
 * @method void setOptions(?array $options)
 * @method int getProcessed()
 * @method void setProcessed(int $processed)
 * @method int getTotal()
 * @method void setTotal(int $total)
 * @method int getCursor()
 * @method void setCursor(int $cursor)
 * @method array|null getReport()
 * @method void setReport(?array $report)
 * @method string|null getStartedBy()
 * @method void setStartedBy(?string $startedBy)
 * @method int|null getRolledBackFrom()
 * @method void setRolledBackFrom(?int $rolledBackFrom)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class SchemaRun extends Entity implements JsonSerializable
{

    /**
     * Run type constants.
     *
     * @var string
     */
    public const TYPE_REVALIDATION = 'revalidation';
    public const TYPE_MIGRATION    = 'migration';
    public const TYPE_ROLLBACK     = 'rollback';

    /**
     * Run state constants.
     *
     * @var string
     */
    public const STATE_DRAFT       = 'draft';
    public const STATE_PREVIEWED   = 'previewed';
    public const STATE_RUNNING     = 'running';
    public const STATE_COMPLETED   = 'completed';
    public const STATE_FAILED      = 'failed';
    public const STATE_ROLLED_BACK = 'rolled-back';

    /**
     * The states that count as "active" (block a second concurrent run).
     *
     * @var array<int, string>
     */
    public const ACTIVE_STATES = [
        self::STATE_DRAFT,
        self::STATE_PREVIEWED,
        self::STATE_RUNNING,
    ];

    /**
     * Stable UUID.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * The schema this run targets.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * The register the schema's objects live in (magic-mapper routing).
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The run type (revalidation | migration | rollback).
     *
     * @var string|null
     */
    protected ?string $type = null;

    /**
     * The run state.
     *
     * @var string|null
     */
    protected ?string $state = null;

    /**
     * A proposed definition to validate against (dry-run of an edit).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $proposedDefinition = null;

    /**
     * The migration transform plan.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $plan = null;

    /**
     * Run options (e.g. stopOnError, batchSize).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $options = null;

    /**
     * Number of processed objects.
     *
     * @var integer
     */
    protected int $processed = 0;

    /**
     * Total number of objects in scope.
     *
     * @var integer
     */
    protected int $total = 0;

    /**
     * Resumable cursor (last processed object id).
     *
     * @var integer
     */
    protected int $cursor = 0;

    /**
     * Summary report (counts; per-object entries live in the side table).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $report = null;

    /**
     * The user who started the run.
     *
     * @var string|null
     */
    protected ?string $startedBy = null;

    /**
     * For a rollback run, the migration run id it rolls back.
     *
     * @var integer|null
     */
    protected ?int $rolledBackFrom = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Last-update timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Constructor — registers field types for hydration.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'type', type: 'string');
        $this->addType(fieldName: 'state', type: 'string');
        $this->addType(fieldName: 'proposedDefinition', type: 'json');
        $this->addType(fieldName: 'plan', type: 'json');
        $this->addType(fieldName: 'options', type: 'json');
        $this->addType(fieldName: 'processed', type: 'integer');
        $this->addType(fieldName: 'total', type: 'integer');
        $this->addType(fieldName: 'cursor', type: 'integer');
        $this->addType(fieldName: 'report', type: 'json');
        $this->addType(fieldName: 'startedBy', type: 'string');
        $this->addType(fieldName: 'rolledBackFrom', type: 'integer');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');

    }//end __construct()

    /**
     * Whether the run is in an active (blocking) state.
     *
     * @return bool True when active.
     */
    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE_STATES, true);

    }//end isActive()

    /**
     * JSON serialisation.
     *
     * @return array<string, mixed> The serialised run.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'schemaId'           => $this->schemaId,
            'registerId'         => $this->registerId,
            'type'               => $this->type,
            'state'              => $this->state,
            'proposedDefinition' => $this->proposedDefinition,
            'plan'               => ($this->plan ?? []),
            'options'            => ($this->options ?? []),
            'processed'          => $this->processed,
            'total'              => $this->total,
            'cursor'             => $this->cursor,
            'report'             => ($this->report ?? []),
            'startedBy'          => $this->startedBy,
            'rolledBackFrom'     => $this->rolledBackFrom,
            'created'            => $this->created?->format(DateTime::ATOM),
            'updated'            => $this->updated?->format(DateTime::ATOM),
        ];

    }//end jsonSerialize()
}//end class
