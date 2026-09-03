<?php

/**
 * File entity wrapping `openregister_files` rows.
 *
 * Holds the OR-side metadata for a single Nextcloud filecache row:
 * description / category / labels (file-actions metadata enrichment),
 * locked_by / locked_at / lock_expires (DB-backed locks when operators
 * want them), download_count (audit + analytics), and standard
 * created / updated timestamps. Operates as a sibling to NC's filecache
 * row identified by `file_id`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/file-actions/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;

/**
 * File entity for the `openregister_files` table.
 *
 * The `@method` tags below belong on the CLASS, not on the file docblock they
 * used to sit in. A static analyser resolves magic accessors from the class it
 * is analysing; a docblock above `declare(strict_types=1)` documents the file
 * and is invisible to it. That is why phpstan.neon carried counted ignore
 * entries for `File::setUpdated()` and friends, and why adding one accessor
 * made an unrelated ignore count wrong.
 *
 * @method int|null getFileId()
 * @method void setFileId(int $fileId)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method array|null getLabels()
 * @method void setLabels(?array $labels)
 * @method string|null getLockedBy()
 * @method void setLockedBy(?string $lockedBy)
 * @method \DateTime|null getLockedAt()
 * @method void setLockedAt(?\DateTime $lockedAt)
 * @method \DateTime|null getLockExpires()
 * @method void setLockExpires(?\DateTime $lockExpires)
 * @method int|null getDownloadCount()
 * @method void setDownloadCount(int $downloadCount)
 * @method \DateTime|null getCreated()
 * @method void setCreated(\DateTime $created)
 * @method \DateTime|null getUpdated()
 * @method void setUpdated(?\DateTime $updated)
 * @method \DateTime|null getPublished()
 * @method void setPublished(?\DateTime $published)
 * @method \DateTime|null getDepublished()
 * @method void setDepublished(?\DateTime $depublished)
 */
class File extends Entity {

	/**
	 * Nextcloud filecache file_id this row mirrors.
	 *
	 * @var integer|null
	 */
	protected ?int $fileId = null;

	/**
	 * Optional descriptive text supplied via file-actions metadata.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * Operator-defined category label.
	 *
	 * @var string|null
	 */
	protected ?string $category = null;

	/**
	 * Free-form label list for filtering and analytics.
	 *
	 * @var array<int, string>|null
	 */
	protected ?array $labels = null;

	/**
	 * UID of the user holding the soft lock, or null when unlocked.
	 *
	 * @var string|null
	 */
	protected ?string $lockedBy = null;

	/**
	 * Timestamp when the lock was acquired.
	 *
	 * @var \DateTime|null
	 */
	protected ?\DateTime $lockedAt = null;

	/**
	 * Timestamp when the lock expires automatically.
	 *
	 * @var \DateTime|null
	 */
	protected ?\DateTime $lockExpires = null;

	/**
	 * Cumulative download count for analytics and audit.
	 *
	 * @var integer
	 */
	protected int $downloadCount = 0;

	/**
	 * Creation timestamp for the metadata row.
	 *
	 * @var \DateTime|null
	 */
	protected ?\DateTime $created = null;

	/**
	 * Last-update timestamp for the metadata row.
	 *
	 * @var \DateTime|null
	 */
	protected ?\DateTime $updated = null;

	/**
	 * When this file becomes public.
	 *
	 * Null means it has never been published, which is why the key is nullable
	 * rather than defaulting to the creation time. A file that exists is not a
	 * file that was published, and reporting the creation time as a publication
	 * date is what this column exists to stop.
	 *
	 * @var \DateTime|null
	 */
	protected ?\DateTime $published = null;

	/**
	 * When this file stops being public.
	 *
	 * Null means no end date, not "already ended". The distinction matters: a
	 * published attachment with no depublication date stays public, which is the
	 * ordinary case.
	 *
	 * @var \DateTime|null
	 */
	protected ?\DateTime $depublished = null;

	/**
	 * Configure typed columns for the file metadata row.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'fileId', type: 'integer');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'category', type: 'string');
		$this->addType(fieldName: 'labels', type: 'json');
		$this->addType(fieldName: 'lockedBy', type: 'string');
		$this->addType(fieldName: 'lockedAt', type: 'datetime');
		$this->addType(fieldName: 'lockExpires', type: 'datetime');
		$this->addType(fieldName: 'downloadCount', type: 'integer');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
		$this->addType(fieldName: 'published', type: 'datetime');
		$this->addType(fieldName: 'depublished', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise to a flat array suitable for JSON responses or
	 * inclusion in `FileFormattingHandler::formatFile()` output.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'fileId' => $this->fileId,
			'description' => $this->description,
			'category' => $this->category,
			'labels' => ($this->labels ?? []),
			'lockedBy' => $this->lockedBy,
			'lockedAt' => $this->lockedAt?->format('c'),
			'lockExpires' => $this->lockExpires?->format('c'),
			'downloadCount' => $this->downloadCount,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
			'published' => $this->published?->format('c'),
			'depublished' => $this->depublished?->format('c'),
			'isPublished' => $this->isPublishedAt(),
		];
	}//end jsonSerialize()

	/**
	 * Whether this file is public at a given moment.
	 *
	 * The rule reads as three separate questions rather than one expression,
	 * because each null means something different:
	 *
	 * - No `published` at all means never published. This is NOT the same as
	 *   published long ago, and defaulting it to the creation time would make
	 *   every file that has ever existed look published.
	 * - A `published` in the future means not yet, which is the whole point of
	 *   being able to set one.
	 * - No `depublished` means no end date, not an end date in the past.
	 *
	 * The boundaries are inclusive at the start and exclusive at the end, so a
	 * file published and depublished at the same instant is not public.
	 *
	 * @param DateTime|null $now The moment to evaluate, defaulting to this one.
	 *
	 * @return bool True when the file is public at that moment.
	 *
	 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-file-carries-its-own-publication-window-req-fpw-101
	 */
	public function isPublishedAt(?DateTime $now = null): bool {
		if ($this->published === null) {
			return false;
		}

		if ($now === null) {
			$now = new DateTime();
		}

		if ($this->published > $now) {
			return false;
		}

		if ($this->depublished === null) {
			return true;
		}

		return ($this->depublished > $now);
	}//end isPublishedAt()
}//end class
