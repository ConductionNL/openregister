<?php

/**
 * CollectiveLink entity for linking Nextcloud Collectives pages to OpenRegister objects.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-1
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
 * @method string getCollectiveName()
 * @method void setCollectiveName(string $collectiveName)
 * @method int getPageId()
 * @method void setPageId(int $pageId)
 * @method string|null getPageTitle()
 * @method void setPageTitle(?string $pageTitle)
 * @method string|null getPageUrl()
 * @method void setPageUrl(?string $pageUrl)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class CollectiveLink extends Entity implements JsonSerializable
{

    /**
     * The object UUID.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The collective name (slug) in Collectives.
     *
     * @var string|null
     */
    protected ?string $collectiveName = null;

    /**
     * The page ID within the collective.
     *
     * @var integer|null
     */
    protected ?int $pageId = null;

    /**
     * The page title cached for display.
     *
     * @var string|null
     */
    protected ?string $pageTitle = null;

    /**
     * The URL to open the page in Collectives.
     *
     * @var string|null
     */
    protected ?string $pageUrl = null;

    /**
     * The user who created the link.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * When the link was created.
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
        $this->addType(fieldName: 'collectiveName', type: 'string');
        $this->addType(fieldName: 'pageId', type: 'integer');
        $this->addType(fieldName: 'pageTitle', type: 'string');
        $this->addType(fieldName: 'pageUrl', type: 'string');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'objectUuid'     => $this->objectUuid,
            'collectiveName' => $this->collectiveName,
            'pageId'         => $this->pageId,
            'pageTitle'      => $this->pageTitle,
            'pageUrl'        => $this->pageUrl,
            'linkedBy'       => $this->linkedBy,
            'linkedAt'       => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
