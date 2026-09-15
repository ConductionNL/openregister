<?php

/**
 * ImportPreview entity — one previewed import, before anything is written.
 *
 * A preview is a first-class record: it carries the target register and
 * schema, the conflict policy the operator declared, the match key that
 * policy is applied against, the identity of the source file, the state
 * machine, the four counts an operator reads before committing, and a
 * summary report. The per-row decision lives in a side table
 * ({@see ImportPreviewRow}) so a preview row stays small whether the file
 * holds twelve rows or twelve thousand.
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
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ImportPreview
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getPackSlug()
 * @method void setPackSlug(?string $packSlug)
 * @method string|null getPolicy()
 * @method void setPolicy(?string $policy)
 * @method array|null getMatchKey()
 * @method void setMatchKey(?array $matchKey)
 * @method string|null getSourceName()
 * @method void setSourceName(?string $sourceName)
 * @method string|null getSourceFormat()
 * @method void setSourceFormat(?string $sourceFormat)
 * @method string|null getSourceHash()
 * @method void setSourceHash(?string $sourceHash)
 * @method string|null getSourcePath()
 * @method void setSourcePath(?string $sourcePath)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method int getTotal()
 * @method void setTotal(int $total)
 * @method int getProcessed()
 * @method void setProcessed(int $processed)
 * @method int getToCreate()
 * @method void setToCreate(int $toCreate)
 * @method int getToUpdate()
 * @method void setToUpdate(int $toUpdate)
 * @method int getToSkip()
 * @method void setToSkip(int $toSkip)
 * @method int getToRefuse()
 * @method void setToRefuse(int $toRefuse)
 * @method int getApplied()
 * @method void setApplied(int $applied)
 * @method int getFailed()
 * @method void setFailed(int $failed)
 * @method array|null getReport()
 * @method void setReport(?array $report)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @SuppressWarnings(PHPMD.TooManyFields) A preview record. Every field is one
 * column of one row, and moving the counts to a second table to get under the
 * threshold would cost a join on every progress poll.
 */
class ImportPreview extends Entity implements JsonSerializable {

	/**
	 * The preview has been created but not walked yet.
	 *
	 * @var string
	 */
	public const STATE_PENDING = 'pending';

	/**
	 * The preview is deciding rows.
	 *
	 * @var string
	 */
	public const STATE_PREVIEWING = 'previewing';

	/**
	 * Every row has a decision and nothing has been written.
	 *
	 * @var string
	 */
	public const STATE_PREVIEWED = 'previewed';

	/**
	 * The decisions are being applied.
	 *
	 * @var string
	 */
	public const STATE_COMMITTING = 'committing';

	/**
	 * Every decision has been applied.
	 *
	 * @var string
	 */
	public const STATE_COMMITTED = 'committed';

	/**
	 * The preview or the commit threw.
	 *
	 * @var string
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * Stable UUID.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The register the rows land in.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema the rows are mapped onto.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The saved column mapping this preview was taken through, if any.
	 *
	 * @var string|null
	 */
	protected ?string $packSlug = null;

	/**
	 * The declared conflict policy (D-2).
	 *
	 * @var string|null
	 */
	protected ?string $policy = null;

	/**
	 * The properties a row is matched on.
	 *
	 * @var array<int, string>|null
	 */
	protected ?array $matchKey = null;

	/**
	 * The uploaded file's name, for the operator to recognise.
	 *
	 * @var string|null
	 */
	protected ?string $sourceName = null;

	/**
	 * The source format: csv, excel or json.
	 *
	 * @var string|null
	 */
	protected ?string $sourceFormat = null;

	/**
	 * The sha256 of the file the decisions describe (D-1).
	 *
	 * @var string|null
	 */
	protected ?string $sourceHash = null;

	/**
	 * Where the file is parked while a background preview walks it.
	 *
	 * @var string|null
	 */
	protected ?string $sourcePath = null;

	/**
	 * The preview state.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * The row count the reader found.
	 *
	 * @var integer
	 */
	protected int $total = 0;

	/**
	 * Rows that have a decision.
	 *
	 * @var integer
	 */
	protected int $processed = 0;

	/**
	 * Rows that would create an object.
	 *
	 * @var integer
	 */
	protected int $toCreate = 0;

	/**
	 * Rows that would update an object.
	 *
	 * @var integer
	 */
	protected int $toUpdate = 0;

	/**
	 * Rows the policy leaves alone.
	 *
	 * @var integer
	 */
	protected int $toSkip = 0;

	/**
	 * Rows the policy or the data refuses, each with a reason.
	 *
	 * @var integer
	 */
	protected int $toRefuse = 0;

	/**
	 * Rows the commit actually wrote.
	 *
	 * @var integer
	 */
	protected int $applied = 0;

	/**
	 * Rows whose write threw.
	 *
	 * @var integer
	 */
	protected int $failed = 0;

	/**
	 * Summary report: the counts, the refusal reasons and the commit outcome.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $report = null;

	/**
	 * The user who took the preview.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

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
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'packSlug', type: 'string');
		$this->addType(fieldName: 'policy', type: 'string');
		$this->addType(fieldName: 'matchKey', type: 'json');
		$this->addType(fieldName: 'sourceName', type: 'string');
		$this->addType(fieldName: 'sourceFormat', type: 'string');
		$this->addType(fieldName: 'sourceHash', type: 'string');
		$this->addType(fieldName: 'sourcePath', type: 'string');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'total', type: 'integer');
		$this->addType(fieldName: 'processed', type: 'integer');
		$this->addType(fieldName: 'toCreate', type: 'integer');
		$this->addType(fieldName: 'toUpdate', type: 'integer');
		$this->addType(fieldName: 'toSkip', type: 'integer');
		$this->addType(fieldName: 'toRefuse', type: 'integer');
		$this->addType(fieldName: 'applied', type: 'integer');
		$this->addType(fieldName: 'failed', type: 'integer');
		$this->addType(fieldName: 'report', type: 'json');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * Whether the decisions may still be committed.
	 *
	 * @return bool True when the preview is complete and not yet committed.
	 */
	public function isCommittable(): bool {
		return $this->state === self::STATE_PREVIEWED;
	}//end isCommittable()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised preview.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'packSlug' => $this->packSlug,
			'policy' => $this->policy,
			'matchKey' => ($this->matchKey ?? []),
			'sourceName' => $this->sourceName,
			'sourceFormat' => $this->sourceFormat,
			'sourceHash' => $this->sourceHash,
			'state' => $this->state,
			'total' => $this->total,
			'processed' => $this->processed,
			'counts' => [
				'created' => $this->toCreate,
				'updated' => $this->toUpdate,
				'skipped' => $this->toSkip,
				'refused' => $this->toRefuse,
			],
			'applied' => $this->applied,
			'failed' => $this->failed,
			'report' => ($this->report ?? []),
			'createdBy' => $this->createdBy,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
		];
	}//end jsonSerialize()
}//end class
