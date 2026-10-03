<?php

/**
 * ExportRun - the record of a copy of the register leaving the building
 *
 * An export used to be a response body and nothing else. After it was written
 * this platform stopped knowing anything about it: who took it, how often, and
 * whether it is still sitting in somebody's Files folder two years later. An
 * administrator asked "who holds an export of this register" could not answer,
 * and neither could a functional administrator answering a data subject.
 *
 * 🔴 THE EXPIRY IS A COLUMN, AND THE RUN CARRIES THE ONE IT WAS PRODUCED
 * UNDER. Two reasons, and the second is the expensive one.
 *
 * First, the proposal asks for it: editing a profile's retention later must not
 * move the deadline of a file somebody already has, so the deadline lives on
 * the run rather than being recomputed from the profile at sweep time.
 *
 * Second, this fleet has already shipped the other shape. One component wrote a
 * deterministic file timestamp, a purge job read that timestamp to decide
 * expiry, and every export was born 22.5 million seconds expired. Both halves
 * had unit tests and both passed, because each was right about its own half.
 * So the writer sets `expiresAt`, the sweep selects on `expires_at`, and the
 * test that matters exercises the writer and the sweep together against one
 * moving clock.
 *
 * A run with NO expiry is a deliberate declaration, not a missing value. It is
 * never swept, and `retentionSeconds` is null on it so a reader can tell the
 * two apart.
 *
 * THE ROW OUTLIVES THE FILE. A sweep deletes the file and keeps the run,
 * because the fact that an export happened outlives the copy it made.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One produced export.
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One property per column, and the columns are the
 *     record the proposal asks for: what it was, who made it, what it read, how big it was,
 *     what file it produced, how often it went out and when it stops existing. Grouping any
 *     of them into a blob would put them out of reach of the area's own filters.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string|null getProfile()
 * @method void setProfile(?string $profile)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getRegisterName()
 * @method void setRegisterName(?string $registerName)
 * @method string|null getSchemaName()
 * @method void setSchemaName(?string $schemaName)
 * @method string|null getFormat()
 * @method void setFormat(?string $format)
 * @method string|null getFilename()
 * @method void setFilename(?string $filename)
 * @method int|null getRowCount()
 * @method void setRowCount(?int $rowCount)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string|null getFilePath()
 * @method void setFilePath(?string $filePath)
 * @method int|null getDownloadCount()
 * @method void setDownloadCount(?int $downloadCount)
 * @method int|null getRetentionSeconds()
 * @method void setRetentionSeconds(?int $retentionSeconds)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method DateTime|null getProducedAt()
 * @method void setProducedAt(?DateTime $producedAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 */
class ExportRun extends Entity implements JsonSerializable {

	/**
	 * The file is there and the run is inside its retention.
	 *
	 * @var string
	 */
	public const STATUS_AVAILABLE = 'available';

	/**
	 * The retention passed and the sweep removed the file. The row stays.
	 *
	 * @var string
	 */
	public const STATUS_EXPIRED = 'expired';

	/**
	 * The run produced no file of its own: the bytes went straight to the
	 * caller. There is nothing for a sweep to delete.
	 *
	 * @var string
	 */
	public const STATUS_SERVED = 'served';

	/**
	 * The uuid the area names a run by.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * What produced it, for example `scheduled-report` or `export-profile`.
	 *
	 * @var string|null
	 */
	protected ?string $source = null;

	/**
	 * The export profile or report it came from.
	 *
	 * @var string|null
	 */
	protected ?string $profile = null;

	/**
	 * Who asked for it.
	 *
	 * @var string|null
	 */
	protected ?string $actor = null;

	/**
	 * The register it read.
	 *
	 * @var string|null
	 */
	protected ?string $registerName = null;

	/**
	 * The schema it read.
	 *
	 * @var string|null
	 */
	protected ?string $schemaName = null;

	/**
	 * csv, json and so on.
	 *
	 * @var string|null
	 */
	protected ?string $format = null;

	/**
	 * The name the file was written or served under.
	 *
	 * @var string|null
	 */
	protected ?string $filename = null;

	/**
	 * How many rows went out.
	 *
	 * @var int|null
	 */
	protected ?int $rowCount = 0;

	/**
	 * The Nextcloud file it produced, when it produced one.
	 *
	 * @var int|null
	 */
	protected ?int $fileId = null;

	/**
	 * Where that file was written.
	 *
	 * @var string|null
	 */
	protected ?string $filePath = null;

	/**
	 * How often the register served it.
	 *
	 * @var int|null
	 */
	protected ?int $downloadCount = 0;

	/**
	 * The retention it was produced under, or null when it was produced to be
	 * kept. Null is a declaration, not a missing value.
	 *
	 * @var int|null
	 */
	protected ?int $retentionSeconds = null;

	/**
	 * available, expired or served.
	 *
	 * @var string|null
	 */
	protected ?string $status = self::STATUS_AVAILABLE;

	/**
	 * When it was produced.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $producedAt = null;

	/**
	 * When its file stops existing. Null means it is kept.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * When the row was written.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When the row last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Declare the field types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'source', type: 'string');
		$this->addType(fieldName: 'profile', type: 'string');
		$this->addType(fieldName: 'actor', type: 'string');
		$this->addType(fieldName: 'registerName', type: 'string');
		$this->addType(fieldName: 'schemaName', type: 'string');
		$this->addType(fieldName: 'format', type: 'string');
		$this->addType(fieldName: 'filename', type: 'string');
		$this->addType(fieldName: 'rowCount', type: 'integer');
		$this->addType(fieldName: 'fileId', type: 'integer');
		$this->addType(fieldName: 'filePath', type: 'string');
		$this->addType(fieldName: 'downloadCount', type: 'integer');
		$this->addType(fieldName: 'retentionSeconds', type: 'integer');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'producedAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
	}//end __construct()

	/**
	 * Whether this run's file is past its stored expiry at the given moment.
	 *
	 * ONE READING OF EXPIRY, FOR EVERY CALLER. The listing's `expired` flag
	 * and the sweep both come through here, so they cannot disagree. A run
	 * with no expiry never expires, which is said once here rather than
	 * assumed at each call site.
	 *
	 * @param DateTime $now The moment to judge against.
	 *
	 * @return bool True when the retention has passed.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function isExpiredAt(DateTime $now): bool {
		if ($this->expiresAt === null) {
			return false;
		}

		return $this->expiresAt <= $now;
	}//end isExpiredAt()

	/**
	 * Whether this run was produced to be kept.
	 *
	 * @return bool True when no retention was declared for it.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function isKept(): bool {
		return $this->expiresAt === null;
	}//end isKept()

	/**
	 * The run as the area renders it.
	 *
	 * @return array<string, mixed> The record.
	 */
	public function jsonSerialize(): array {
		$format = static function (?DateTime $value): ?string {
			if ($value === null) {
				return null;
			}

			return $value->format('c');
		};

		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'source' => $this->source,
			'profile' => $this->profile,
			'actor' => $this->actor,
			'register' => $this->registerName,
			'schema' => $this->schemaName,
			'format' => $this->format,
			'filename' => $this->filename,
			'rowCount' => $this->rowCount,
			'fileId' => $this->fileId,
			'filePath' => $this->filePath,
			'downloadCount' => $this->downloadCount,
			'retentionSeconds' => $this->retentionSeconds,
			'kept' => $this->isKept(),
			'status' => $this->status,
			'producedAt' => $format($this->producedAt),
			'expiresAt' => $format($this->expiresAt),
			'created' => $format($this->created),
			'updated' => $format($this->updated),
		];
	}//end jsonSerialize()
}//end class
