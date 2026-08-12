<?php

/**
 * CollectiveLink entity for linking NC Collectives (Knowledge) pages to
 * OpenRegister objects.
 *
 * Tier-2 schema: carries register_id, schema_id, collective_id,
 * collective_name, page_title, slug, emoji, last_modified and url so the
 * link row alone can hydrate the sidebar tab + picker UX without a
 * per-page roundtrip to NC Collectives. Replaces the Tier-1
 * `CollectivesProvider`'s `[or:{uuid}]` slug-marker convention with a
 * proper persistence layer that survives page renames + moves.
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
 * Class CollectiveLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getPageId()
 * @method void setPageId(int $pageId)
 * @method int getCollectiveId()
 * @method void setCollectiveId(int $collectiveId)
 * @method string getCollectiveName()
 * @method void setCollectiveName(string $collectiveName)
 * @method string getPageTitle()
 * @method void setPageTitle(string $pageTitle)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getEmoji()
 * @method void setEmoji(?string $emoji)
 * @method DateTime|null getLastModified()
 * @method void setLastModified(?DateTime $lastModified)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class CollectiveLink extends Entity implements JsonSerializable {

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
	 * The NC Collectives page id (primary key in `oc_collectives_pages`).
	 *
	 * @var integer|null
	 */
	protected ?int $pageId = null;

	/**
	 * The owning collective id.
	 *
	 * @var integer|null
	 */
	protected ?int $collectiveId = null;

	/**
	 * The collective display name (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $collectiveName = null;

	/**
	 * The page title (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $pageTitle = null;

	/**
	 * The cached page slug.
	 *
	 * @var string|null
	 */
	protected ?string $slug = null;

	/**
	 * The cached page emoji.
	 *
	 * @var string|null
	 */
	protected ?string $emoji = null;

	/**
	 * The cached page last-modified timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastModified = null;

	/**
	 * The cached deep link to the page.
	 *
	 * @var string|null
	 */
	protected ?string $url = null;

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
		$this->addType(fieldName: 'pageId', type: 'integer');
		$this->addType(fieldName: 'collectiveId', type: 'integer');
		$this->addType(fieldName: 'collectiveName', type: 'string');
		$this->addType(fieldName: 'pageTitle', type: 'string');
		$this->addType(fieldName: 'slug', type: 'string');
		$this->addType(fieldName: 'emoji', type: 'string');
		$this->addType(fieldName: 'lastModified', type: 'datetime');
		$this->addType(fieldName: 'url', type: 'string');
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
			'pageId' => $this->pageId,
			'collectiveId' => $this->collectiveId,
			'collectiveName' => $this->collectiveName,
			'pageTitle' => $this->pageTitle,
			'slug' => $this->slug,
			'emoji' => $this->emoji,
			'lastModified' => $this->lastModified?->format(DateTime::ATOM),
			'url' => $this->url,
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
