<?php

/**
 * BookmarkLink entity for linking Nextcloud Bookmarks to OpenRegister
 * objects.
 *
 * Tier-2 schema: carries register_id, schema_id, title, url, description,
 * tags, added_at so the link row alone can hydrate the sidebar tab +
 * picker UX without a per-bookmark roundtrip to NC Bookmarks. Replaces
 * the original BookmarksProvider's tag-marker convention
 * (`or:{uuid}` tag on the bookmark) with a proper persistence layer.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class BookmarkLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getBookmarkId()
 * @method void setBookmarkId(int $bookmarkId)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getTags()
 * @method void setTags(?array $tags)
 * @method DateTime|null getAddedAt()
 * @method void setAddedAt(?DateTime $addedAt)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class BookmarkLink extends Entity implements JsonSerializable {

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
	 * The NC Bookmarks bookmark id (primary key in `oc_bookmarks`).
	 *
	 * @var integer|null
	 */
	protected ?int $bookmarkId = null;

	/**
	 * The bookmark title (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * The bookmark URL (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $url = null;

	/**
	 * The bookmark description (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * Bookmarks-side tags (cached at link time, `or:*` markers stripped).
	 *
	 * @var array<int,string>|null
	 */
	protected ?array $tags = null;

	/**
	 * When the bookmark was originally saved in NC Bookmarks.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $addedAt = null;

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
		$this->addType(fieldName: 'bookmarkId', type: 'integer');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'url', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'tags', type: 'json');
		$this->addType(fieldName: 'addedAt', type: 'datetime');
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
			'bookmarkId' => $this->bookmarkId,
			'title' => $this->title,
			'url' => $this->url,
			'description' => $this->description,
			'tags' => ($this->tags ?? []),
			'addedAt' => $this->addedAt?->format(DateTime::ATOM),
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
