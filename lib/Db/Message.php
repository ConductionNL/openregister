<?php

/**
 * OpenRegister Message Entity
 *
 * This file contains the Message entity class for the OpenRegister application.
 * Messages represent individual chat messages within conversations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
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
     * AI Chat Companion context snapshot
     *
     * Free-form structured snapshot the frontend sends with each user
     * message so the LLM can ground its answer in the user's current
     * scope (selected register, schema, object id, recent search query,
     * etc.). Persisted as a TEXT column with JSON contents via the
     * `json` addType binding below; explicit `getContext()` and
     * `setContext()` wrappers below normalise null → [] for callers.
     *
     * Added by Version1Date20260511130000 per ai-chat-companion-orchestrator §7.
     *
     * @var array|null Context snapshot, JSON-encoded on disk
     */
    protected ?array $context = null;

    /**
     * Message constructor
     *
     * Sets up the entity type mappings for proper database handling.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'conversationId', type: 'integer');
        $this->addType(fieldName: 'role', type: 'string');
        $this->addType(fieldName: 'content', type: 'string');
        $this->addType(fieldName: 'sources', type: 'json');
        $this->addType(fieldName: 'context', type: 'json');
        $this->addType(fieldName: 'created', type: 'datetime');
    }//end __construct()

    /**
     * Get the context snapshot, normalised to an array.
     *
     * Shadows the magic `getContext()` getter to guarantee callers get
     * a real array even when the row has NULL or an empty default.
     *
     * @return array<string,mixed>
     */
    public function getContext(): array
    {
        return $this->context ?? [];

    }//end getContext()

    /**
     * Set the context snapshot.
     *
     * Shadows the magic setter to enforce non-null array semantics.
     * The `json` addType binding handles JSON-encoding on persist.
     *
     * @param array<string,mixed> $context Context snapshot
     *
     * @return void
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
        $this->markFieldUpdated(attribute: 'context');

    }//end setContext()

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
            'context'        => $this->getContext(),
            'created'        => $this->created?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
