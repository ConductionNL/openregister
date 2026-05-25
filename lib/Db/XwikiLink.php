<?php

/**
 * XwikiLink entity for tracking which remote xWiki pages are bound to an
 * OpenRegister object.
 *
 * Tier-2 schema for the `xwiki` external integration (AD-4 / ADR-019):
 * the link row records the OR object ↔ xWiki page-reference pairing plus
 * cached title/space/url/cached_at so the sidebar tab + picker can render
 * without a per-row roundtrip to the (potentially slow or unconfigured)
 * remote xWiki instance. The pages themselves live in remote xWiki and
 * are reached via OpenConnector — this entity is purely the local link
 * table that survives even when the upstream is down.
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
 * Class XwikiLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string getPageReference()
 * @method void setPageReference(string $pageReference)
 * @method string|null getSpace()
 * @method void setSpace(?string $space)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method DateTime|null getCachedAt()
 * @method void setCachedAt(?DateTime $cachedAt)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class XwikiLink extends Entity implements JsonSerializable
{

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
     * The canonical xWiki page reference (e.g. `Space.Page`).
     *
     * @var string|null
     */
    protected ?string $pageReference = null;

    /**
     * The owning xWiki space (cached at link time).
     *
     * @var string|null
     */
    protected ?string $space = null;

    /**
     * The page title (cached at link time).
     *
     * @var string|null
     */
    protected ?string $title = null;

    /**
     * The cached deep link to the page.
     *
     * @var string|null
     */
    protected ?string $url = null;

    /**
     * The timestamp the cached metadata was last refreshed.
     *
     * @var DateTime|null
     */
    protected ?DateTime $cachedAt = null;

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
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'pageReference', type: 'string');
        $this->addType(fieldName: 'space', type: 'string');
        $this->addType(fieldName: 'title', type: 'string');
        $this->addType(fieldName: 'url', type: 'string');
        $this->addType(fieldName: 'cachedAt', type: 'datetime');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'objectUuid'    => $this->objectUuid,
            'registerId'    => $this->registerId,
            'schemaId'      => $this->schemaId,
            'pageReference' => $this->pageReference,
            'space'         => $this->space,
            'title'         => $this->title,
            'url'           => $this->url,
            'cachedAt'      => $this->cachedAt?->format(DateTime::ATOM),
            'linkedBy'      => $this->linkedBy,
            'linkedAt'      => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
