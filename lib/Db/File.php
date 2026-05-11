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
 *
 * @method int|null      getFileId()
 * @method void          setFileId(int $fileId)
 * @method string|null   getDescription()
 * @method void          setDescription(?string $description)
 * @method string|null   getCategory()
 * @method void          setCategory(?string $category)
 * @method array|null    getLabels()
 * @method void          setLabels(?array $labels)
 * @method string|null   getLockedBy()
 * @method void          setLockedBy(?string $lockedBy)
 * @method \DateTime|null getLockedAt()
 * @method void          setLockedAt(?\DateTime $lockedAt)
 * @method \DateTime|null getLockExpires()
 * @method void          setLockExpires(?\DateTime $lockExpires)
 * @method int|null      getDownloadCount()
 * @method void          setDownloadCount(int $downloadCount)
 * @method \DateTime|null getCreated()
 * @method void          setCreated(\DateTime $created)
 * @method \DateTime|null getUpdated()
 * @method void          setUpdated(?\DateTime $updated)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * File entity for the `openregister_files` table.
 */
class File extends Entity
{

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
     * Configure typed columns for the file metadata row.
     *
     * @return void
     */
    public function __construct()
    {
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

    }//end __construct()

    /**
     * Serialise to a flat array suitable for JSON responses or
     * inclusion in `FileFormattingHandler::formatFile()` output.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'fileId'        => $this->fileId,
            'description'   => $this->description,
            'category'      => $this->category,
            'labels'        => ($this->labels ?? []),
            'lockedBy'      => $this->lockedBy,
            'lockedAt'      => $this->lockedAt?->format('c'),
            'lockExpires'   => $this->lockExpires?->format('c'),
            'downloadCount' => $this->downloadCount,
            'created'       => $this->created?->format('c'),
            'updated'       => $this->updated?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
