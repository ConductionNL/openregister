<?php

/**
 * OpenRegister Chat Message History Handler
 *
 * Handler for message storage and history management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Chat;

use DateTime;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\ConversationMapper;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\Message as LLPhantMessage;
use Symfony\Component\Uid\Uuid;

/**
 * MessageHistoryHandler
 *
 * Handles message storage and conversation history building.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class MessageHistoryHandler
{
    /**
     * Number of recent messages to keep in context
     *
     * @var int
     */
    private const RECENT_MESSAGES_COUNT = 10;

    /**
     * Message mapper
     *
     * @var MessageMapper
     */
    private MessageMapper $messageMapper;

    /**
     * Conversation mapper
     *
     * @var ConversationMapper
     */
    private ConversationMapper $conversationMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param MessageMapper      $messageMapper      Message mapper.
     * @param ConversationMapper $conversationMapper Conversation mapper.
     * @param LoggerInterface    $logger             Logger.
     *
     * @return void
     */
    public function __construct(
        MessageMapper $messageMapper,
        ConversationMapper $conversationMapper,
        LoggerInterface $logger
    ) {
        $this->messageMapper      = $messageMapper;
        $this->conversationMapper = $conversationMapper;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Build message history array for LLM
     *
     * Converts recent Message entities to LLPhantMessage format for LLM context.
     *
     * @param int $conversationId Conversation ID.
     *
     * @return array Array of LLPhantMessage objects
     *
     * @psalm-return list<LLPhantMessage>
     */
    public function buildMessageHistory(int $conversationId): array
    {
        // Get recent messages.
        $messages = $this->messageMapper->findRecentByConversation(
            $conversationId,
            self::RECENT_MESSAGES_COUNT
        );

        $this->logger->debug(
            message: '[ChatService] Building message history',
            context: [
                'conversationId' => $conversationId,
                'messageCount'   => count($messages),
            ]
        );

        $history = [];
        foreach ($messages as $message) {
            $content = $message->getContent();
            $role    = $message->getRole();

            $this->logger->debug(
                message: '[ChatService] Adding message to history',
                context: [
                    'role'          => $role,
                    'contentLength' => strlen($content ?? ''),
                    'hasContent'    => empty($content) === false,
                    'hasRole'       => empty($role) === false,
                ]
            );

            // Only add messages that have both role and content.
            if (empty($role) === false && empty($content) === false) {
                // Use static factory methods based on role.
                if ($role === 'user') {
                    $history[] = LLPhantMessage::user($content);
                } else if ($role === 'assistant') {
                    $history[] = LLPhantMessage::assistant($content);
                } else if ($role === 'system') {
                    $history[] = LLPhantMessage::system($content);
                } else {
                    $this->logger->warning(
                        message: '[ChatService] Unknown message role',
                        context: [
                            'role' => $role,
                        ]
                    );
                }
            } else {
                $this->logger->warning(
                    message: '[ChatService] Skipping message with missing role or content',
                    context: [
                        'hasRole'    => empty($role) === false,
                        'hasContent' => empty($content) === false,
                    ]
                );
            }//end if
        }//end foreach

        $this->logger->info(
            message: '[ChatService] Message history built',
            context: [
                'historyCount' => count($history),
            ]
        );

        return $history;
    }//end buildMessageHistory()

    /**
     * Store a message in the database
     *
     * Persists a chat message with optional RAG sources metadata.
     *
     * @param int        $conversationId Conversation ID.
     * @param string     $role           Message role (user or assistant).
     * @param string     $content        Message content.
     * @param array|null $sources        Optional RAG sources.
     *
     * @return Message Stored message entity
     */
    public function storeMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $sources=null
    ): Message {
        $message = new Message();
        $message->setUuid(Uuid::v4()->toRfc4122());
        $message->setConversationId($conversationId);
        $message->setRole($role);
        $message->setContent($content);
        $message->setCreated(new DateTime());

        // Add sources metadata if provided.
        if ($sources !== null && empty($sources) === false) {
            $message->setMetadata(['sources' => $sources]);
        }

        $this->messageMapper->insert($message);

        $this->logger->debug(
            message: '[ChatService] Message stored',
            context: [
                'messageId'      => $message->getId(),
                'conversationId' => $conversationId,
                'role'           => $role,
                'hasSources'     => $sources !== null && empty($sources) === false,
            ]
        );

        return $message;
    }//end storeMessage()
}//end class
