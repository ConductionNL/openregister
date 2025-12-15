<?php
/**
 * OpenRegister Feedback Entity
 *
 * Feedback entity for storing user feedback on AI messages.
 *
 * @category Database
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
 * Feedback entity for storing user feedback on AI messages
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getMessageId()
 * @method void setMessageId(int $messageId)
 * @method int getConversationId()
 * @method void setConversationId(int $conversationId)
 * @method int getAgentId()
 * @method void setAgentId(int $agentId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 */
class Feedback extends Entity implements JsonSerializable
{

    /**
     * UUID.
     *
     * @var string
     */
    protected string $uuid = '';

    /**
     * Message ID.
     *
     * @var integer
     */
    protected int $messageId = 0;

    /**
     * Conversation ID.
     *
     * @var integer
     */
    protected int $conversationId = 0;

    /**
     * Agent ID.
     *
     * @var integer
     */
    protected int $agentId = 0;

    /**
     * User ID.
     *
     * @var string
     */
    protected string $userId = '';

    /**
     * Organisation.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Type ('positive' or 'negative').
     *
     * @var string
     */
    protected string $type = '';

    /**
     * Comment.
     *
     * @var string|null
     */
    protected ?string $comment = null;

    /**
     * Created timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Updated timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;


    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('messageId', 'integer');
        $this->addType('conversationId', 'integer');
        $this->addType('agentId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('organisation', 'string');
        $this->addType('type', 'string');
        $this->addType('comment', 'string');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');

    }//end __construct()


    /**
     * JSON serialization.
     *
     * @return (int|null|string)[]
     *
     * @psalm-return array{id: int, uuid: string, messageId: int, conversationId: int, agentId: int, userId: string, organisation: null|string, type: string, comment: null|string, created: null|string, updated: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'messageId'      => $this->messageId,
            'conversationId' => $this->conversationId,
            'agentId'        => $this->agentId,
            'userId'         => $this->userId,
            'organisation'   => $this->organisation,
            'type'           => $this->type,
            'comment'        => $this->comment,
            'created'        => $this->created?->format('c'),
            'updated'        => $this->updated?->format('c'),
        ];

    }//end jsonSerialize()


}//end class
