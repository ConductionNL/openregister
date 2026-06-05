<?php

/**
 * FileLink entity for linking Nextcloud files to OpenRegister objects.
 *
 * Shared by both Files and Photos integrations. Photos is a filtered view
 * of this table (image/* MIME types only) with lazy-extracted EXIF metadata.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class FileLink
 *
 * @method string  getObjectUuid()
 * @method void    setObjectUuid(string $objectUuid)
 * @method int     getFileId()
 * @method void    setFileId(int $fileId)
 * @method string  getFileName()
 * @method void    setFileName(string $fileName)
 * @method string  getMimeType()
 * @method void    setMimeType(string $mimeType)
 * @method int     getFileSize()
 * @method void    setFileSize(int $fileSize)
 * @method string  getLinkedBy()
 * @method void    setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void    setLinkedAt(DateTime $linkedAt)
 * @method string|null getExifMetadata()
 * @method void    setExifMetadata(?string $exifMetadata)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class FileLink extends Entity implements JsonSerializable
{

    /**
     * The OR object UUID.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The Nextcloud file ID (from oc_filecache).
     *
     * @var integer|null
     */
    protected ?int $fileId = null;

    /**
     * The file name.
     *
     * @var string|null
     */
    protected ?string $fileName = null;

    /**
     * MIME type of the linked file.
     *
     * @var string|null
     */
    protected ?string $mimeType = null;

    /**
     * File size in bytes.
     *
     * @var integer|null
     */
    protected ?int $fileSize = null;

    /**
     * User ID who created the link.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * Timestamp when the link was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $linkedAt = null;

    /**
     * JSON-encoded EXIF metadata (lazy-extracted for photos).
     *
     * @var string|null
     */
    protected ?string $exifMetadata = null;

    /**
     * Constructor — registers field types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'fileId', type: 'integer');
        $this->addType(fieldName: 'fileName', type: 'string');
        $this->addType(fieldName: 'mimeType', type: 'string');
        $this->addType(fieldName: 'fileSize', type: 'integer');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
        $this->addType(fieldName: 'exifMetadata', type: 'string');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->id,
            'objectUuid'   => $this->objectUuid,
            'fileId'       => $this->fileId,
            'fileName'     => $this->fileName,
            'mimeType'     => $this->mimeType,
            'fileSize'     => $this->fileSize,
            'linkedBy'     => $this->linkedBy,
            'linkedAt'     => $this->linkedAt?->format(DateTime::ATOM),
            'exifMetadata' => $this->exifMetadata !== null ? json_decode($this->exifMetadata, true) : null,
        ];
    }//end jsonSerialize()
}//end class
