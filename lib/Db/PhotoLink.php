<?php

/**
 * PhotoLink entity for linking NC Photos albums to OpenRegister objects.
 *
 * Tier-2 schema: carries register_id, schema_id, album_name,
 * cover_photo_url, photo_count and last_edited so the link row alone can
 * hydrate the sidebar tab + picker UX without a per-album roundtrip to
 * NC Photos. Replaces the Tier-1 `PhotosProvider`'s `[or:{uuid}]`
 * album-name marker convention with a proper persistence layer that
 * survives album renames.
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
 * Class PhotoLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getAlbumId()
 * @method void setAlbumId(int $albumId)
 * @method string getAlbumName()
 * @method void setAlbumName(string $albumName)
 * @method string|null getCoverPhotoUrl()
 * @method void setCoverPhotoUrl(?string $coverPhotoUrl)
 * @method int|null getPhotoCount()
 * @method void setPhotoCount(?int $photoCount)
 * @method DateTime|null getLastEdited()
 * @method void setLastEdited(?DateTime $lastEdited)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class PhotoLink extends Entity implements JsonSerializable {

	/**
	 * The object uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register id.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema id.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The NC Photos album id (primary key in `oc_photos_albums`).
	 *
	 * @var integer|null
	 */
	protected ?int $albumId = null;

	/**
	 * The album display name (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $albumName = null;

	/**
	 * The cached cover photo thumbnail href.
	 *
	 * @var string|null
	 */
	protected ?string $coverPhotoUrl = null;

	/**
	 * The cached photo count.
	 *
	 * @var integer|null
	 */
	protected ?int $photoCount = null;

	/**
	 * The cached album last-edited (last-added-photo) timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastEdited = null;

	/**
	 * The linked by uid.
	 *
	 * @var string|null
	 */
	protected ?string $linkedBy = null;

	/**
	 * The linked at timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $linkedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'albumId', type: 'integer');
		$this->addType(fieldName: 'albumName', type: 'string');
		$this->addType(fieldName: 'coverPhotoUrl', type: 'string');
		$this->addType(fieldName: 'photoCount', type: 'integer');
		$this->addType(fieldName: 'lastEdited', type: 'datetime');
		$this->addType(fieldName: 'linkedBy', type: 'string');
		$this->addType(fieldName: 'linkedAt', type: 'datetime');
	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'albumId' => $this->albumId,
			'albumName' => $this->albumName,
			'coverPhotoUrl' => $this->coverPhotoUrl,
			'photoCount' => $this->photoCount,
			'lastEdited' => $this->lastEdited?->format(DateTime::ATOM),
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
