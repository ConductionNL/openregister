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
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class BulkJob extends Entity implements JsonSerializable {

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
	public const STATE_CANCELLING = 'cancelling';
	public const STATE_CANCELLED = 'cancelled';
	public const STATE_COMPLETED = 'completed';
	public const STATE_FAILED = 'failed';

	/**
	 * The states in which a job still has work ahead of it.
	 *
	 * @var array<int, string>
	 */
	public const ACTIVE_STATES = [
		self::STATE_PREVIEWED,
		self::STATE_RUNNING,
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
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'action', type: 'string');
		$this->addType(fieldName: 'parameters', type: 'json');
		$this->addType(fieldName: 'selectionType', type: 'string');
		$this->addType(fieldName: 'selection', type: 'json');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'justification', type: 'string');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'total', type: 'integer');
		$this->addType(fieldName: 'processed', type: 'integer');
		$this->addType(fieldName: 'applied', type: 'integer');
		$this->addType(fieldName: 'skipped', type: 'integer');
		$this->addType(fieldName: 'refused', type: 'integer');
		$this->addType(fieldName: 'failed', type: 'integer');
		$this->addType(fieldName: 'cursor', type: 'integer');
		$this->addType(fieldName: 'report', type: 'json');
		$this->addType(fieldName: 'startedBy', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * The field names registered with the 'json' type.
	 *
	 * @return array<int, string> The json-typed field names.
	 */
	public function getJsonFields(): array {
		return array_keys(
			array_filter(
				$this->getFieldTypes(),
				static function ($field) {
					return $field === 'json';
				}
			)
		);
	}//end getJsonFields()

	/**
	 * Hydrate the entity from an array.
	 *
	 * @param array<string, mixed> $object The source data.
	 *
	 * @return static This entity, hydrated.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function hydrate(array $object): static {
		$jsonFields = $this->getJsonFields();

		foreach ($object as $key => $value) {
			if (in_array($key, $jsonFields, true) === true && $value === []) {
				$value = null;
			}

			$method = 'set'.ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Silently ignore invalid properties.
			}
		}

		return $this;
	}//end hydrate()

	/**
	 * Whether the job still has work ahead of it.
	 *
	 * @return bool True when the job is previewed, running or cancelling.
	 */
	public function isActive(): bool {
		return in_array($this->state, self::ACTIVE_STATES, true);
	}//end isActive()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised job.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'action' => $this->action,
			'parameters' => ($this->parameters ?? []),
			'selectionType' => $this->selectionType,
			'selection' => ($this->selection ?? []),
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'justification' => $this->justification,
			'state' => $this->state,
			'total' => $this->total,
			'processed' => $this->processed,
			'counts' => [
				'applied' => $this->applied,
				'skipped' => $this->skipped,
				'refused' => $this->refused,
				'failed' => $this->failed,
			],
			'cursor' => $this->cursor,
			'report' => ($this->report ?? []),
			'startedBy' => $this->startedBy,
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
