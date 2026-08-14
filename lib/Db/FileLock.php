<?php

/**
 * OpenRegister FileLock Entity
 *
 * This file contains the class for the FileLock entity.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * FileLock entity class.
 *
 * Persists advisory file-level locks (see FileLockHandler) so that a lock
 * survives beyond the PHP request/worker that created it. Nextcloud's
 * `openregister_files` table was deprecated and dropped in
 * Version1Date20250430083916, so lock state lives in its own small table
 * rather than being tacked onto a table that no longer exists.
 *
 * @method int|null getFileId()
 * @method void setFileId(int $fileId)
 * @method string|null getLockedBy()
 * @method void setLockedBy(string $lockedBy)
 * @method DateTime|null getLockedAt()
 * @method void setLockedAt(DateTime $lockedAt)
 * @method DateTime|null getLockExpires()
 * @method void setLockExpires(DateTime $lockExpires)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class FileLock extends Entity implements JsonSerializable
{

    /**
     * The Nextcloud filecache file ID this lock applies to.
     *
     * @var int|null
     */
    protected ?int $fileId = null;

    /**
     * The user ID that holds the lock.
     *
     * @var string|null
     */
    protected ?string $lockedBy = null;

    /**
     * When the lock was acquired.
     *
     * @var DateTime|null
     */
    protected ?DateTime $lockedAt = null;

    /**
     * When the lock expires (TTL).
     *
     * @var DateTime|null
     */
    protected ?DateTime $lockExpires = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'fileId', type: 'integer');
        $this->addType(fieldName: 'lockedBy', type: 'string');
        $this->addType(fieldName: 'lockedAt', type: 'datetime');
        $this->addType(fieldName: 'lockExpires', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return (int|null|string)[]
     *
     * @psalm-return array{id: int, fileId: int|null, lockedBy: null|string,
     *     lockedAt: null|string, lockExpires: null|string}
     */
    public function jsonSerialize(): array
    {
        $lockedAt = null;
        if ($this->lockedAt !== null) {
            $lockedAt = $this->lockedAt->format('c');
        }

        $lockExpires = null;
        if ($this->lockExpires !== null) {
            $lockExpires = $this->lockExpires->format('c');
        }

        return [
            'id'          => $this->id,
            'fileId'      => $this->fileId,
            'lockedBy'    => $this->lockedBy,
            'lockedAt'    => $lockedAt,
            'lockExpires' => $lockExpires,
        ];
    }//end jsonSerialize()
}//end class
