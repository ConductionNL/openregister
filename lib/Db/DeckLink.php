<?php

/**
 * DeckLink entity for linking Nextcloud Deck cards to OpenRegister objects.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
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
 * Class DeckLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getStackId()
 * @method void setStackId(int $stackId)
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method string|null getCardTitle()
 * @method void setCardTitle(?string $cardTitle)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class DeckLink extends Entity implements JsonSerializable
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
     * The board id.
     *
     * @var integer|null
     */
    protected ?int $boardId = null;

    /**
     * The stack id.
     *
     * @var integer|null
     */
    protected ?int $stackId = null;

    /**
     * The card id.
     *
     * @var integer|null
     */
    protected ?int $cardId = null;

    /**
     * The card title.
     *
     * @var string|null
     */
    protected ?string $cardTitle = null;

    /**
     * The linked by.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * The linked at.
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
        $this->addType(fieldName: 'boardId', type: 'integer');
        $this->addType(fieldName: 'stackId', type: 'integer');
        $this->addType(fieldName: 'cardId', type: 'integer');
        $this->addType(fieldName: 'cardTitle', type: 'string');
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
            'id'         => $this->id,
            'objectUuid' => $this->objectUuid,
            'registerId' => $this->registerId,
            'boardId'    => $this->boardId,
            'stackId'    => $this->stackId,
            'cardId'     => $this->cardId,
            'cardTitle'  => $this->cardTitle,
            'linkedBy'   => $this->linkedBy,
            'linkedAt'   => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
