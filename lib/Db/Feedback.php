<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Feedback entity for storing user feedback on AI messages
 *
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
 * @method \DateTime|null getCreated()
 * @method void setCreated(?\DateTime $created)
 * @method \DateTime|null getUpdated()
 * @method void setUpdated(?\DateTime $updated)
 */
class Feedback extends Entity implements JsonSerializable
{

    protected string $uuid = '';

    protected int $messageId = 0;

    protected int $conversationId = 0;

    protected int $agentId = 0;

    protected string $userId = '';

    protected ?string $organisation = null;

    protected string $type = '';

    // 'positive' or 'negative'.
    protected ?string $comment = null;

    protected ?\DateTime $created = null;

    protected ?\DateTime $updated = null;


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
