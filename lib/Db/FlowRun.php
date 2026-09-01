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
 * @method integer|null getFlowVersion()
 * @method void setFlowVersion(?int $flowVersion)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method array|null getMarking()
 * @method array|null getPlaceItems()
 * @method void setPlaceItems(?array $placeItems)
 * @method integer|null getFirings()
 * @method void setFirings(?int $firings)
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
 * @method string|null getRunAs()
 * @method void setRunAs(?string $runAs)
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
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One property per column of
 * `openregister_flow_runs`. An entity's field count IS its table's column
 * count; "redesigning to keep fields under 15" would mean dropping columns the
 * run needs to be resumable, not simplifying anything.
 */
class FlowRun extends Entity implements JsonSerializable {

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
	 * Waiting for a person to allow the delegation this run needs.
	 *
	 * DISTINCT FROM `suspended`, on purpose. A suspended run is waiting on a
	 * timer, a child run or a webhook — machinery, which arrives or does not.
	 * This one is waiting on a DECISION BY A NAMED HUMAN, and the difference is
	 * the whole reason it gets its own state: an operator asking "why is this
	 * stuck" must be answered "waiting for X to allow Y to act as them" from the
	 * run itself, not from correlating a run against a grant table nobody thought
	 * to look at.
	 *
	 * Folding it into `suspended` would also put it in front of the resume
	 * sweeps, which resume on a clock. A consent has no `resume_at` — asking
	 * again in five minutes is not how a person decides — so it would land in
	 * the abandoned-signal reaper and be failed as if nobody had answered, while
	 * the prompt was still sitting unread in somebody's inbox.
	 *
	 * @var string
	 */
	public const STATUS_AWAITING_CONSENT = 'awaiting_consent';

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
	 * Statuses a run will still advance from — the complement of TERMINAL.
	 *
	 * This is what "currently running" means to a person watching a dashboard.
	 * `running` on its own is nearly useless as a filter: a run holds it only
	 * while a worker pass is executing it, so a poll almost always misses it,
	 * while `queued` and `suspended` are where a live run actually waits.
	 *
	 * @var array<int, string>
	 */
	public const ACTIVE = [
		self::STATUS_QUEUED,
		self::STATUS_RUNNING,
		self::STATUS_SUSPENDED,
		// ACTIVE, not terminal. A parked run is still going to happen — it is
		// waiting on an answer, and one answer releases it. Omitting it here
		// would hide it from every "currently running" surface, which is exactly
		// where somebody would go to find out why their work has not run.
		self::STATUS_AWAITING_CONSENT,
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
	 * The flow version this run is pinned to.
	 *
	 * 🔴 THIS ALONE DECIDES WHICH GRAPH THE RUN WALKS. The advancer resolves
	 * flow + this number to a version row, and that row to an immutable
	 * definition. It is never re-read from the flow's head and never advanced
	 * to a newer version, because the run's marking, its taken decisions and
	 * its log all belong to the version it started on.
	 *
	 * Nullable at the type level only so the column can exist before the
	 * repair step fills it. A run that can still move and has no version is a
	 * defect, not a supported state — the advancer fails it by name.
	 *
	 * @var integer|null
	 */
	protected ?int $flowVersion = null;

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
	 * The items sitting on each marked place, `place => items`. Written by
	 * the same transaction as the marking so a marking can never name a
	 * place whose items were not written; null for a run that predates the
	 * column, which then seeds from the flat `items` on first read.
	 *
	 * @var array|null
	 */
	protected ?array $placeItems = null;

	/**
	 * Committed firings across ALL streams and passes — the durable input to
	 * the transition ceiling, incremented inside each firing's commit.
	 *
	 * @var int|null
	 */
	protected ?int $firings = 0;

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
	 * The user whose action started this run — PROVENANCE, not authorization.
	 *
	 * Answers "who caused this". It is immutable once written and MUST NOT be
	 * consulted to decide access: see {@see $runAs}, which answers the other
	 * question. Conflating the two is what let a scheduled run execute as
	 * whoever happened to author the flow.
	 *
	 * @var string|null
	 */
	protected ?string $triggeredBy = null;

	/**
	 * The user whose RIGHTS this run executes with — the authorization subject.
	 *
	 * The only value an access decision reads. It differs from
	 * {@see $triggeredBy} whenever the cause is not a person: a scheduled run is
	 * caused by a schedule and acts as the user its trigger node declares.
	 *
	 * Resolved when the run is queued and RE-RESOLVED at every resume, because
	 * rights are a property of the moment work runs, not of the moment it was
	 * requested. A run parked for three weeks must answer to the rights its
	 * subject holds on resumption.
	 *
	 * Never null on a queued run: a dispatch that cannot resolve one is refused
	 * rather than recorded, so the failure names the missing identity once
	 * instead of surfacing as a per-node permission error later.
	 *
	 * @var string|null
	 */
	protected ?string $runAs = null;

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
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'flowId', type: 'string');
		$this->addType(fieldName: 'flowVersion', type: 'integer');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'marking', type: 'json');
		$this->addType(fieldName: 'placeItems', type: 'json');
		$this->addType(fieldName: 'firings', type: 'integer');
		$this->addType(fieldName: 'items', type: 'json');
		$this->addType(fieldName: 'context', type: 'json');
		$this->addType(fieldName: 'log', type: 'json');
		$this->addType(fieldName: 'subjectUuid', type: 'string');
		$this->addType(fieldName: 'subjectRegister', type: 'string');
		$this->addType(fieldName: 'subjectSchema', type: 'string');
		$this->addType(fieldName: 'triggeredBy', type: 'string');
		$this->addType(fieldName: 'runAs', type: 'string');
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
	public function isTerminal(): bool {
		return in_array($this->status, self::TERMINAL, true);
	}//end isTerminal()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The run as plain data.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function jsonSerialize(): array {
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
			'id' => $this->id,
			'uuid' => $this->uuid,
			'flowId' => $this->flowId,
			'flowVersion' => $this->flowVersion,
			'status' => $this->status,
			'marking' => ($this->marking ?? []),
			'placeItems' => $this->placeItems,
			'firings' => (int)($this->firings ?? 0),
			'items' => ($this->items ?? []),
			'context' => ($this->context ?? []),
			'log' => ($this->log ?? []),
			'subjectUuid' => $this->subjectUuid,
			'subjectRegister' => $this->subjectRegister,
			'subjectSchema' => $this->subjectSchema,
			'triggeredBy' => $this->triggeredBy,
			'runAs' => $this->runAs,
			'trigger' => $this->trigger,
			'organisation' => $this->organisation,
			'error' => $this->error,
			'resumeAt' => $resumeAt,
			'parentRunUuid' => $this->parentRunUuid,
			'created' => $created,
			'updated' => $updated,
		];

	}//end jsonSerialize()
}//end class
