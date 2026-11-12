<?php

/**
 * BulkJob entity — one bulk act over a selection of objects.
 *
 * A bulk job is a first-class, resumable record: it carries the action being
 * run, the selection it runs over, the actor who created it, the
 * justification they typed, the state-machine state, progress counters, a
 * resumable cursor and a summary report. The per-object outcome lives in a
 * side table ({@see BulkJobMember}) so a job row stays small whether the
 * selection holds four objects or four thousand.
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
use OCA\OpenRegister\Db\BulkJob\BulkJobFieldTypes;
use OCA\OpenRegister\Db\BulkJob\BulkJobJson;
use OCP\AppFramework\Db\Entity;

/**
 * Class BulkJob
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getAction()
 * @method void setAction(?string $action)
 * @method array|null getParameters()
 * @method void setParameters(?array $parameters)
 * @method string|null getSelectionType()
 * @method void setSelectionType(?string $selectionType)
 * @method array|null getSelection()
 * @method void setSelection(?array $selection)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getJustification()
 * @method void setJustification(?string $justification)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method int getTotal()
 * @method void setTotal(int $total)
 * @method int getProcessed()
 * @method void setProcessed(int $processed)
 * @method int getApplied()
 * @method void setApplied(int $applied)
 * @method int getSkipped()
 * @method void setSkipped(int $skipped)
 * @method int getRefused()
 * @method void setRefused(int $refused)
 * @method int getFailed()
 * @method void setFailed(int $failed)
 * @method int getCursor()
 * @method void setCursor(int $cursor)
 * @method array|null getReport()
 * @method void setReport(?array $report)
 * @method string|null getStartedBy()
 * @method void setStartedBy(?string $startedBy)
 * @method int|null getReversalWindow()
 * @method void setReversalWindow(?int $reversalWindow)
 * @method DateTime|null getReversibleUntil()
 * @method void setReversibleUntil(?DateTime $reversibleUntil)
 * @method int|null getReversesJobId()
 * @method void setReversesJobId(?int $reversesJobId)
 * @method int|null getReversedByJobId()
 * @method void setReversedByJobId(?int $reversedByJobId)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @SuppressWarnings(PHPMD.TooManyFields) A job record. Every field is one
 * column of one row, and moving the counters to a second table to get under
 * the threshold would cost a join on every progress poll.
 */
class BulkJob extends Entity implements JsonSerializable {
	use JsonFieldsTrait;
	use HydrateEmptyJsonAsNullTrait;

	/**
	 * The selection is an explicit list of object uuids.
	 *
	 * @var string
	 */
	public const SELECTION_IDS = 'ids';

	/**
	 * The selection is a query, re-resolved at commit.
	 *
	 * @var string
	 */
	public const SELECTION_QUERY = 'query';

	/**
	 * Job state constants.
	 *
	 * @var string
	 */
	public const STATE_PREVIEWED = 'previewed';
	public const STATE_RUNNING = 'running';
	public const STATE_PAUSED = 'paused';
	public const STATE_CANCELLING = 'cancelling';
	public const STATE_CANCELLED = 'cancelled';
	public const STATE_COMPLETED = 'completed';
	public const STATE_FAILED = 'failed';

	/**
	 * The states in which a job still has work ahead of it.
	 *
	 * A paused job belongs here: its members are unwalked and its cursor is
	 * kept, so it has work ahead in exactly the sense a cancelled one does
	 * not. Only `running` re-enqueues, which is what makes the pause hold.
	 *
	 * @var array<int, string>
	 */
	public const ACTIVE_STATES = [
		self::STATE_PREVIEWED,
		self::STATE_RUNNING,
		self::STATE_PAUSED,
		self::STATE_CANCELLING,
	];

	/**
	 * Stable UUID.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The registered action this job runs.
	 *
	 * @var string|null
	 */
	protected ?string $action = null;

	/**
	 * The parameters handed to the action.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $parameters = null;

	/**
	 * Whether the selection is an id list or a query (D-2).
	 *
	 * @var string|null
	 */
	protected ?string $selectionType = null;

	/**
	 * The selection itself: `{"ids": [...]}` or `{"query": {...}}`.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $selection = null;

	/**
	 * The register the selection lives in.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema the selection lives in.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The free-text reason the operator typed (D-7).
	 *
	 * @var string|null
	 */
	protected ?string $justification = null;

	/**
	 * The job state.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * The member count at creation.
	 *
	 * @var integer
	 */
	protected int $total = 0;

	/**
	 * Members the commit has walked.
	 *
	 * @var integer
	 */
	protected int $processed = 0;

	/**
	 * Members the action applied to.
	 *
	 * @var integer
	 */
	protected int $applied = 0;

	/**
	 * Members the action did not apply to, each with a reason.
	 *
	 * @var integer
	 */
	protected int $skipped = 0;

	/**
	 * Members the actor may not write.
	 *
	 * @var integer
	 */
	protected int $refused = 0;

	/**
	 * Members whose write threw.
	 *
	 * @var integer
	 */
	protected int $failed = 0;

	/**
	 * Resumable cursor: the highest member id already walked.
	 *
	 * @var integer
	 */
	protected int $cursor = 0;

	/**
	 * Summary report: counts, the selection delta, the cancel boundary.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $report = null;

	/**
	 * The user who created the job.
	 *
	 * @var string|null
	 */
	protected ?string $startedBy = null;

	/**
	 * How long this job stays undoable, in seconds.
	 *
	 * Null means the action did not declare itself reversible: no prior value
	 * was recorded and the reversal route refuses rather than appearing to
	 * work (D-4).
	 *
	 * @var integer|null
	 */
	protected ?int $reversalWindow = null;

	/**
	 * The deadline the reversal window ends at.
	 *
	 * Stamped provisionally at creation so the preview can name it, and again
	 * when the job reaches a terminal state, because the window runs from when
	 * the job actually wrote rather than from when somebody opened the dialog.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $reversibleUntil = null;

	/**
	 * The job this one undoes, when this job is a reversal (D-1).
	 *
	 * @var integer|null
	 */
	protected ?int $reversesJobId = null;

	/**
	 * The reversal of this job, once one has been created.
	 *
	 * @var integer|null
	 */
	protected ?int $reversedByJobId = null;

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
	public function __construct() {
		foreach (BulkJobFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Whether the job still has work ahead of it.
	 *
	 * @return bool True when the job is previewed, running or cancelling.
	 */
	public function isActive(): bool {
		return in_array($this->state, self::ACTIVE_STATES, true);
	}//end isActive()

	/**
	 * Whether this job recorded what it would take to go back.
	 *
	 * Reversibility is a property of the ACTION, declared beside it, and this
	 * column is where the job froze that declaration at creation. Asking the
	 * registry again at reversal time would let an action that stopped being
	 * reversible strand jobs that did record their prior values.
	 *
	 * @return bool True when the job is undoable in principle.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function isReversible(): bool {
		return ($this->reversalWindow !== null && $this->reversalWindow > 0);
	}//end isReversible()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised job.
	 */
	public function jsonSerialize(): array {
		$values = get_object_vars($this);
		$values['fieldTypes'] = $this->getFieldTypes();

		return (new BulkJobJson())->serialise(values: $values, reversible: $this->isReversible());
	}//end jsonSerialize()
}//end class
