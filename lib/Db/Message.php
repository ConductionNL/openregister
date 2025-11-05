<?php
/**
 * OpenRegister Message Entity
 *
 * This file contains the Message entity class for the OpenRegister application.
 * Messages represent individual chat messages within conversations.
 *
 * @category Entity
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * Message entity class
 *
 * Represents a chat message within a conversation.
 * Messages have a role (user or assistant), content, and optional sources (for RAG).
 *
 * @package OCA\OpenRegister\Db
 */
class Message extends Entity implements JsonSerializable
{

    /**
     * Message role: User message
     */
    public const ROLE_USER = 'user';

    /**
     * Message role: Assistant/AI message
     */
    public const ROLE_ASSISTANT = 'assistant';

    /**
     * Unique identifier for the message
     *
     * @var string|null UUID of the message
     */
    protected ?string $uuid = null;

    /**
     * Conversation ID
     *
     * @var int|null Conversation ID this message belongs to
     */
    protected ?int $conversationId = null;

    /**
     * Message role
     *
     * @var string|null Either 'user' or 'assistant'
     */
    protected ?string $role = null;

    /**
     * Message content
     *
     * @var string|null The message text
     */
    protected ?string $content = null;

    /**
     * RAG sources (JSON)
     *
     * Array of sources used to generate the response (for assistant messages).
     * Format: [
     *   {
     *     "id": "uuid",
     *     "type": "file|object",
     *     "name": "source name",
     *     "similarity": 0.95,
     *     "text": "relevant excerpt"
     *   }
     * ]
     *
     * @var array|null Sources array
     */
    protected ?array $sources = null;

    /**
     * Creation timestamp
     *
     * @var DateTime|null Created timestamp
     */
    protected ?DateTime $created = null;


    /**
     * Message constructor
     *
     * Sets up the entity type mappings for proper database handling.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('conversationId', 'integer');
        $this->addType('role', 'string');
        $this->addType('content', 'string');
        $this->addType('sources', 'json');
        $this->addType('created', 'datetime');

    }//end __construct()


    /**
     * Validate UUID format
     *
     * @param string $uuid The UUID to validate
     *
     * @return bool True if UUID format is valid
     */
    public static function isValidUuid(string $uuid): bool
    {
        try {
            Uuid::fromString($uuid);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }

    }//end isValidUuid()


    /**
     * Get the UUID of the message
     *
     * @return string|null The message UUID
     */
    public function getUuid(): ?string
    {
        return $this->uuid;

    }//end getUuid()


    /**
     * Set the UUID of the message
     *
     * @param string $uuid The UUID
     *
     * @return self
     */
    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;

    }//end setUuid()


    /**
     * Get the conversation ID
     *
     * @return int|null The conversation ID
     */
    public function getConversationId(): ?int
    {
        return $this->conversationId;

    }//end getConversationId()


    /**
     * Set the conversation ID
     *
     * @param int $conversationId The conversation ID
     *
     * @return self
     */
    public function setConversationId(int $conversationId): self
    {
        $this->conversationId = $conversationId;
        return $this;

    }//end setConversationId()


    /**
     * Get the message role
     *
     * @return string|null The message role
     */
    public function getRole(): ?string
    {
        return $this->role;

    }//end getRole()


    /**
     * Set the message role
     *
     * @param string $role The message role (user or assistant)
     *
     * @return self
     */
    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;

    }//end setRole()


    /**
     * Check if message is from user
     *
     * @return bool True if user message
     */
    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;

    }//end isUser()


    /**
     * Check if message is from assistant
     *
     * @return bool True if assistant message
     */
    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;

    }//end isAssistant()


    /**
     * Get the message content
     *
     * @return string|null The message content
     */
    public function getContent(): ?string
    {
        return $this->content;

    }//end getContent()


    /**
     * Set the message content
     *
     * @param string $content The message content
     *
     * @return self
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;

    }//end setContent()


    /**
     * Get the RAG sources
     *
     * @return array|null The sources array
     */
    public function getSources(): ?array
    {
        return $this->sources;

    }//end getSources()


    /**
     * Set the RAG sources
     *
     * @param array|null $sources The sources array
     *
     * @return self
     */
    public function setSources(?array $sources): self
    {
        $this->sources = $sources;
        return $this;

    }//end setSources()


    /**
     * Check if message has sources
     *
     * @return bool True if sources are present
     */
    public function hasSources(): bool
    {
        return !empty($this->sources);

    }//end hasSources()


    /**
     * Get the creation timestamp
     *
     * @return DateTime|null The creation timestamp
     */
    public function getCreated(): ?DateTime
    {
        return $this->created;

    }//end getCreated()


    /**
     * Set the creation timestamp
     *
     * @param DateTime $created The creation timestamp
     *
     * @return self
     */
    public function setCreated(DateTime $created): self
    {
        $this->created = $created;
        return $this;

    }//end setCreated()


    /**
     * Serialize the message to JSON
     *
     * @return array Serialized message
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'conversationId' => $this->conversationId,
            'role' => $this->role,
            'content' => $this->content,
            'sources' => $this->sources,
            'created' => $this->created?->format('c'),
        ];

    }//end jsonSerialize()


}//end class


