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
 * Uses Nextcloud's Entity magic getters/setters for all simple properties.
 * Only methods with custom logic are explicitly defined.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int|null getConversationId()
 * @method void setConversationId(?int $conversationId)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method string|null getContent()
 * @method void setContent(?string $content)
 * @method array|null getSources()
 * @method void setSources(?array $sources)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
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
     * @var integer|null Conversation ID this message belongs to
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
     * Check if message has RAG sources
     *
     * @return bool True if sources exist
     */
    public function hasSources(): bool
    {
        return !empty($this->sources);

    }//end hasSources()


    /**
     * Serialize the message to JSON
     *
     * @return (array|int|null|string)[] Serialized message
     *
     * @psalm-return array{
     *     id: int,
     *     uuid: null|string,
     *     conversationId: int|null,
     *     role: null|string,
     *     content: null|string,
     *     sources: array|null,
     *     created: null|string
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'conversationId' => $this->conversationId,
            'role'           => $this->role,
            'content'        => $this->content,
            'sources'        => $this->sources,
            'created'        => $this->created?->format('c'),
        ];

    }//end jsonSerialize()


}//end class
