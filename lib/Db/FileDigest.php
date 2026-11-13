<?php

/**
 * FileDigest entity: the SHA-256 of a file at the moment it became public.
 *
 * One row per publication. Publishing a file again writes a new row and closes
 * the one before it; unpublishing closes the open row. A closed row stays, so
 * the digest a file carried while it was public can always be shown.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class FileDigest
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getSha256()
 * @method void setSha256(?string $sha256)
 * @method int|null getSize()
 * @method void setSize(?int $size)
 * @method string|null getEtag()
 * @method void setEtag(?string $etag)
 * @method DateTime|null getPublishedAt()
 * @method void setPublishedAt(?DateTime $publishedAt)
 * @method DateTime|null getUnpublishedAt()
 * @method void setUnpublishedAt(?DateTime $unpublishedAt)
 * @method string|null getAuditTrailUuid()
 * @method void setAuditTrailUuid(?string $auditTrailUuid)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */
class FileDigest extends Entity implements JsonSerializable {

	/**
	 * Stable UUID of this record.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The Nextcloud file id (filecache fileid).
	 *
	 * @var integer|null
	 */
	protected ?int $fileId = null;

	/**
	 * The uuid of the object the file belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The SHA-256 of the file's bytes, lowercase hex.
	 *
	 * @var string|null
	 */
	protected ?string $sha256 = null;

	/**
	 * The number of bytes the digest covers.
	 *
	 * @var integer|null
	 */
	protected ?int $size = null;

	/**
	 * The file's etag when the digest was taken; a read compares against it.
	 *
	 * @var string|null
	 */
	protected ?string $etag = null;

	/**
	 * When the file became public.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $publishedAt = null;

	/**
	 * When the record stopped being valid: the file was unpublished or published again.
	 *
	 * Null means the record is the open one.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $unpublishedAt = null;

	/**
	 * The uuid of the `file.published` audit row that seals this record.
	 *
	 * @var string|null
	 */
	protected ?string $auditTrailUuid = null;

	/**
	 * Constructor: registers the field types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'fileId', type: 'integer');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'sha256', type: 'string');
		$this->addType(fieldName: 'size', type: 'integer');
		$this->addType(fieldName: 'etag', type: 'string');
		$this->addType(fieldName: 'publishedAt', type: 'datetime');
		$this->addType(fieldName: 'unpublishedAt', type: 'datetime');
		$this->addType(fieldName: 'auditTrailUuid', type: 'string');

	}//end __construct()

	/**
	 * JSON serialisation, dates as ISO 8601.
	 *
	 * @return array<string, mixed> The serialised record.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
	 */
	public function jsonSerialize(): array {
		return (new TypedFieldsJson())->serialise(fieldTypes: $this->getFieldTypes(), values: get_object_vars($this));

	}//end jsonSerialize()
}//end class
