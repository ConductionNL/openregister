<?php

/**
 * OpenRegister Chat Controller
 *
 * Controller for handling AI chat API endpoints.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\MessageMapper;
use OCP\IDBConnection;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\Feedback;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ChatController handles AI chat API endpoints
 *
 * Controller for handling AI chat API endpoints with conversation-based chat system.
 * Provides endpoints for creating conversations, sending messages, managing agents,
 * and handling feedback.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @psalm-suppress UnusedClass
 */
class ChatController extends Controller
{

    /**
     * Chat service
     *
     * Handles AI chat logic and LLM interactions.
     *
     * @var ChatService Chat service instance
     */
    private readonly ChatService $chatService;

    /**
     * Conversation mapper
     *
     * Handles database operations for conversation entities.
     *
     * @var ConversationMapper Conversation mapper instance
     */
    private readonly ConversationMapper $conversationMapper;

    /**
     * Message mapper
     *
     * Handles database operations for message entities.
     *
     * @var MessageMapper Message mapper instance
     */
    private readonly MessageMapper $messageMapper;

    /**
     * Feedback mapper
     *
     * Handles database operations for feedback entities.
     *
     * @var FeedbackMapper Feedback mapper instance
     */
    private readonly FeedbackMapper $feedbackMapper;

    /**
     * Agent mapper
     *
     * Handles database operations for agent entities.
     *
     * @var AgentMapper Agent mapper instance
     */
    private readonly AgentMapper $agentMapper;

    /**
     * Database connection
     *
     * Used for direct database operations when needed.
     *
     * @var IDBConnection Database connection instance
     */
    private readonly IDBConnection $db;

    /**
     * Organisation service
     *
     * Handles organisation-related operations and permissions.
     *
     * @var OrganisationService Organisation service instance
     */
    private readonly OrganisationService $organisationService;

    /**
     * Logger
     *
     * Used for logging chat operations, errors, and debug information.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * User ID
     *
     * Current user ID for chat context and permissions.
     *
     * @var string User ID
     */
    private readonly string $userId;

    /**
     * Constructor
     *
     * Initializes controller with required dependencies for chat operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string              $appName             Application name
     * @param IRequest            $request             HTTP request object
     * @param ChatService         $chatService         Chat service for AI interactions
     * @param ConversationMapper  $conversationMapper  Conversation mapper for database operations
     * @param MessageMapper       $messageMapper       Message mapper for database operations
     * @param FeedbackMapper      $feedbackMapper      Feedback mapper for database operations
     * @param AgentMapper         $agentMapper         Agent mapper for database operations
     * @param OrganisationService $organisationService Organisation service for permissions
     * @param IDBConnection       $db                  Database connection for direct queries
     * @param LoggerInterface     $logger              Logger for error tracking
     * @param string              $userId              Current user ID for chat context
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ChatService $chatService,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        FeedbackMapper $feedbackMapper,
        AgentMapper $agentMapper,
        OrganisationService $organisationService,
        IDBConnection $db,
        LoggerInterface $logger,
        string $userId
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

        // Store dependencies for use in controller methods.
        $this->chatService         = $chatService;
        $this->conversationMapper  = $conversationMapper;
        $this->messageMapper       = $messageMapper;
        $this->feedbackMapper      = $feedbackMapper;
        $this->agentMapper         = $agentMapper;
        $this->organisationService = $organisationService;
        // Store remaining dependencies.
        $this->db     = $db;
        $this->logger = $logger;
        $this->userId = $userId;
    }//end __construct()

    /**
     * Extract and normalize request parameters for sending a message.
     *
     * Extracts conversation UUID, agent UUID, message content, selected views,
     * selected tools, and RAG settings from the request.
     *
     * @return array Normalized request parameters
     *
     * @psalm-return array{conversationUuid: string, agentUuid: string,
     *     message: string, selectedViews: array, selectedTools: array,
     *     ragSettings: array{includeObjects: bool|mixed, includeFiles: bool|mixed,
     *     numSourcesFiles: int|mixed, numSourcesObjects: int|mixed}}
     * @phpstan-return array{conversationUuid: string, agentUuid: string,
     *     message: string, selectedViews: array, selectedTools: array,
     *     ragSettings: array{includeObjects: bool|mixed, includeFiles: bool|mixed,
     *     numSourcesFiles: int|mixed, numSourcesObjects: int|mixed}}
     */
    private function extractMessageRequestParams(): array
    {
        // Extract basic parameters.
        $conversationUuid = (string) $this->request->getParam('conversation');
        $agentUuid        = (string) $this->request->getParam('agentUuid');
        $message          = (string) $this->request->getParam('message');

        // Extract selectedViews array.
        $viewsParam    = $this->request->getParam('views');
        $selectedViews = ($viewsParam !== null && is_array($viewsParam) === true) ? $viewsParam : [];

        // Extract selectedTools array.
        $toolsParam    = $this->request->getParam('tools');
        $selectedTools = ($toolsParam !== null && is_array($toolsParam) === true) ? $toolsParam : [];

        // Extract RAG configuration settings.
        $ragSettings = [
            'includeObjects'    => $this->request->getParam('includeObjects') ?? true,
            'includeFiles'      => $this->request->getParam('includeFiles') ?? true,
            'numSourcesFiles'   => $this->request->getParam('numSourcesFiles') ?? 5,
            'numSourcesObjects' => $this->request->getParam('numSourcesObjects') ?? 5,
        ];

        return [
            'conversationUuid' => $conversationUuid,
            'agentUuid'        => $agentUuid,
            'message'          => $message,
            'selectedViews'    => $selectedViews,
            'selectedTools'    => $selectedTools,
            'ragSettings'      => $ragSettings,
        ];
    }//end extractMessageRequestParams()

    /**
     * Load an existing conversation by UUID.
     *
     * @param string $uuid Conversation UUID
     *
     * @return Conversation The conversation entity
     *
     * @throws \Exception If conversation not found
     */
    private function loadExistingConversation(string $uuid): Conversation
    {
        try {
            return $this->conversationMapper->findByUuid($uuid);
        } catch (\Exception $e) {
            throw new \Exception('The conversation with UUID '.$uuid.' does not exist', 404);
        }
    }//end loadExistingConversation()

    /**
     * Create a new conversation with the specified agent.
     *
     * @param string $agentUuid Agent UUID
     *
     * @return Conversation The newly created conversation
     *
     * @throws \Exception If agent not found
     */
    private function createNewConversation(string $agentUuid): Conversation
    {
        // Get active organisation.
        $organisation = $this->organisationService->getActiveOrganisation();

        // Look up agent by UUID.
        try {
            $agent = $this->agentMapper->findByUuid($agentUuid);
        } catch (\Exception $e) {
            throw new \Exception('The agent with UUID '.$agentUuid.' does not exist', 404);
        }

        // Generate unique default title.
        $defaultTitle = $this->chatService->ensureUniqueTitle(
            baseTitle: 'New Conversation',
            userId: $this->userId,
            agentId: $agent->getId()
        );

        // Create and insert new conversation.
        $conversation = new Conversation();
        $conversation->setUserId($this->userId);
        $conversation->setOrganisation($organisation?->getUuid());
        $conversation->setAgentId($agent->getId());
        $conversation->setTitle($defaultTitle);
        $conversation = $this->conversationMapper->insert($conversation);

        // Log conversation creation.
        $this->logger->info(
            message: '[ChatController] New conversation created',
            context: [
                'uuid'    => $conversation->getUuid(),
                'userId'  => $this->userId,
                'agentId' => $agent->getId(),
                'title'   => $defaultTitle,
            ]
        );

        return $conversation;
    }//end createNewConversation()

    /**
     * Resolve conversation from UUID or create new one with agent.
     *
     * @param string $conversationUuid Conversation UUID (empty if creating new)
     * @param string $agentUuid        Agent UUID (empty if using existing conversation)
     *
     * @return Conversation The resolved or created conversation
     *
     * @throws \Exception If both parameters are empty or if entities not found
     */
    private function resolveConversation(string $conversationUuid, string $agentUuid): Conversation
    {
        // Load existing conversation by UUID.
        if (empty($conversationUuid) === false) {
            return $this->loadExistingConversation($conversationUuid);
        }

        // Create new conversation with specified agent.
        if (empty($agentUuid) === false) {
            return $this->createNewConversation($agentUuid);
        }

        // Neither parameter provided.
        throw new \Exception('Either conversation or agentUuid is required', 400);
    }//end resolveConversation()

    /**
     * Verify that the current user has access to the conversation.
     *
     * @param Conversation $conversation The conversation to check
     *
     * @return void
     *
     * @throws \Exception If user does not have access (403)
     */
    private function verifyConversationAccess(Conversation $conversation): void
    {
        if ($conversation->getUserId() !== $this->userId) {
            throw new \Exception('You do not have access to this conversation', 403);
        }
    }//end verifyConversationAccess()

    /**
     * Returns the template of the main chat page
     *
     * Renders the Single Page Application template for the chat interface.
     * All routing is handled client-side by the SPA.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse Template response for chat SPA
     *
     * @psalm-return TemplateResponse<200, array<never, never>>
     */
    public function page(): TemplateResponse
    {
        // Return SPA template response (routing handled client-side).
        return new TemplateResponse(
            appName: 'openregister',
            templateName: 'index',
            params: []
        );
    }//end page()

    /**
     * Send a chat message in a conversation and get AI response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return       JSONResponse A JSON response with the chat response or error
     * @psalm-return JSONResponse<int,
     *     array{error?: string, message: string, sources?: list<array>,
     *     timings?: array, conversation?: null|string}, array<never, never>>
     */
    public function sendMessage(): JSONResponse
    {
        try {
            // Extract and normalize request parameters.
            $params = $this->extractMessageRequestParams();

            // Validate message is not empty.
            if (empty($params['message']) === true) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Missing message',
                        'message' => 'message content is required',
                    ],
                    statusCode: 400
                );
            }

            // Log request with settings.
            $this->logger->info(
                message: '[ChatController] Received message with settings',
                context: [
                    'views'       => count($params['selectedViews']),
                    'tools'       => count($params['selectedTools']),
                    'ragSettings' => $params['ragSettings'],
                ]
            );

            // Resolve conversation (load existing or create new).
            $conversation = $this->resolveConversation(
                conversationUuid: $params['conversationUuid'],
                agentUuid: $params['agentUuid']
            );

            // Verify user has access to conversation.
            $this->verifyConversationAccess($conversation);

            // Process message through ChatService.
            $result = $this->chatService->processMessage(
                conversationId: $conversation->getId(),
                userId: $this->userId,
                userMessage: $params['message'],
                selectedViews: $params['selectedViews'],
                selectedTools: $params['selectedTools'],
                ragSettings: $params['ragSettings']
            );

            // Add conversation UUID to result for frontend.
            $result['conversation'] = $conversation->getUuid();

            return new JSONResponse(data: $result, statusCode: 200);
        } catch (\Exception $e) {
            // Determine status code from exception or default to 500.
            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode >= 600) {
                $statusCode = 500;
            }

            // Log error with trace.
            $this->logger->error(
                message: '[ChatController] Failed to send message',
                context: [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Determine appropriate error message based on status code.
            $errorType = match ($statusCode) {
                400     => 'Missing conversation or agentUuid',
                403     => 'Access denied',
                404     => 'Conversation not found',
                default => 'Failed to process message',
            };

            return new JSONResponse(
                data: [
                    'error'   => $errorType,
                    'message' => $e->getMessage(),
                ],
                statusCode: $statusCode
            );
        }//end try
    }//end sendMessage()

    /**
     * Get conversation history (messages)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return       JSONResponse A JSON response with conversation history or error
     * @psalm-return JSONResponse<int,
     *     array{error?: 'Access denied'|'Failed to fetch conversation history'|
     *     'Missing conversationId', message?: string,
     *     messages?: list<array{content: null|string, conversationId: int|null,
     *     created: null|string, id: int, role: null|string, sources: array|null,
     *     uuid: null|string}>, total?: int, conversationId?: int},
     *     array<never, never>>
     */
    public function getHistory(): JSONResponse
    {
        try {
            // Get conversation ID from request.
            $conversationId = (int) $this->request->getParam('conversationId');

            if (empty($conversationId) === true) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Missing conversationId',
                        'message' => 'conversationId is required',
                    ],
                    statusCode: 400
                );
            }

            // Get conversation.
            $conversation = $this->conversationMapper->find($conversationId);

            // Verify ownership.
            if ($conversation->getUserId() !== $this->userId) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Access denied',
                        'message' => 'You do not have access to this conversation',
                    ],
                    statusCode: 403
                );
            }

            // Get messages.
            $limit  = (int) ($this->request->getParam('limit') ?? 100);
            $offset = (int) ($this->request->getParam('offset') ?? 0);

            $messages = $this->messageMapper->findByConversation(
                conversationId: $conversationId,
                limit: $limit,
                offset: $offset
            );

            $serializedMessages = array_map(
                function ($msg) {
                    return $msg->jsonSerialize();
                },
                $messages
            );

            return new JSONResponse(
                data: [
                    'messages'       => $serializedMessages,
                    'total'          => $this->messageMapper->countByConversation($conversationId),
                    'conversationId' => $conversationId,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[ChatController] Failed to get history',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to fetch conversation history',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getHistory()

    /**
     * Clear conversation history (soft delete)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return       JSONResponse A JSON response confirming conversation clearing or error
     * @psalm-return JSONResponse<int,
     *     array{error?: 'Access denied'|'Failed to clear conversation'|
     *     'Missing conversationId', message: string, conversationId?: int},
     *     array<never, never>>
     */
    public function clearHistory(): JSONResponse
    {
        try {
            // Get conversation ID from request.
            $conversationId = (int) $this->request->getParam('conversationId');

            if (empty($conversationId) === true) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Missing conversationId',
                        'message' => 'conversationId is required',
                    ],
                    statusCode: 400
                );
            }

            // Get conversation.
            $conversation = $this->conversationMapper->find($conversationId);

            // Verify ownership.
            if ($conversation->getUserId() !== $this->userId) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Access denied',
                        'message' => 'You do not have access to this conversation',
                    ],
                    statusCode: 403
                );
            }

            // Soft delete conversation.
            $this->conversationMapper->softDelete($conversationId);

            $this->logger->info(
                message: '[ChatController] Conversation cleared (soft deleted)',
                context: [
                    'conversationId' => $conversationId,
                    'userId'         => $this->userId,
                ]
            );

            return new JSONResponse(
                data: [
                    'message'        => 'Conversation cleared successfully',
                    'conversationId' => $conversationId,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[ChatController] Failed to clear history',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to clear conversation',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end clearHistory()

    /**
     * Submit or update feedback on a message
     *
     * Endpoint: POST /api/conversations/{conversationUuid}/messages/{messageId}/feedback
     *
     * @param string $conversationUuid Conversation UUID
     * @param int    $messageId        Message ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return       JSONResponse A JSON response with the conversation list or error
     * @psalm-return JSONResponse<int,
     *     array{error?: string, message?: string, id?: int, uuid?: string,
     *     messageId?: int, conversationId?: int, agentId?: int, userId?: string,
     *     organisation?: null|string, type?: string, comment?: null|string,
     *     created?: null|string, updated?: null|string}, array<never, never>>
     */
    public function sendFeedback(string $conversationUuid, int $messageId): JSONResponse
    {
        try {
            // Get request parameters.
            $type    = (string) $this->request->getParam('type');
            $comment = (string) $this->request->getParam('comment', '');

            // Validate feedback type.
            if (in_array($type, ['positive', 'negative'], true) === false) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Invalid feedback type',
                        'message' => 'type must be "positive" or "negative"',
                    ],
                    statusCode: 400
                );
            }

            // Get conversation by UUID.
            $conversation = $this->conversationMapper->findByUuid($conversationUuid);

            // Verify user has access to this conversation.
            if ($conversation->getUserId() !== $this->userId) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Access denied',
                        'message' => 'You do not have access to this conversation',
                    ],
                    statusCode: 403
                );
            }

            // Get message and verify it belongs to this conversation.
            $message = $this->messageMapper->find($messageId);

            if ($message->getConversationId() !== $conversation->getId()) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Message not found',
                        'message' => 'Message does not belong to this conversation',
                    ],
                    statusCode: 404
                );
            }

            // Get active organisation.
            $organisation     = $this->organisationService->getActiveOrganisation();
            $organisationUuid = $organisation?->getUuid();

            // Check if feedback already exists for this message.
            $existingFeedback = $this->feedbackMapper->findByMessage(messageId: $messageId, userId: $this->userId);

            if ($existingFeedback !== null) {
                // Update existing feedback.
                $existingFeedback->setType($type);
                $existingFeedback->setComment($comment);

                $feedback = $this->feedbackMapper->update($existingFeedback);

                $this->logger->info(
                    '[ChatController] Message feedback updated',
                    [
                        'feedbackId' => $feedback->getId(),
                        'messageId'  => $messageId,
                        'type'       => $type,
                        'hasComment' => empty($comment) === false,
                    ]
                );
            } else {
                // Create new feedback.
                $feedback = new Feedback();
                $feedback->setMessageId($messageId);
                $feedback->setConversationId($conversation->getId());
                $feedback->setAgentId($conversation->getAgentId() ?? 0);
                $feedback->setUserId($this->userId);
                $feedback->setOrganisation($organisationUuid);
                $feedback->setType($type);
                $feedback->setComment($comment);

                $feedback = $this->feedbackMapper->insert($feedback);

                $this->logger->info(
                    '[ChatController] Message feedback created',
                    [
                        'feedbackId' => $feedback->getId(),
                        'messageId'  => $messageId,
                        'type'       => $type,
                        'hasComment' => empty($comment) === false,
                    ]
                );
            }//end if

            return new JSONResponse(data: $feedback->jsonSerialize(), statusCode: 200);
        } catch (\Exception $e) {
            $this->logger->error(
                '[ChatController] Failed to save feedback',
                [
                    'conversationUuid' => $conversationUuid ?? null,
                    'messageId'        => $messageId ?? null,
                    'error'            => $e->getMessage(),
                    'trace'            => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to save feedback',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end sendFeedback()

    /**
     * Get chat statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Chat statistics
     *
     * @psalm-return JSONResponse<200|500,
     *     array{error?: 'Failed to get chat statistics', message?: string,
     *     total_agents?: int, total_conversations?: int, total_messages?: int},
     *     array<never, never>>
     */
    public function getChatStats(): JSONResponse
    {
        try {
            // Get agent count.
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('id', 'total'))
                ->from('openregister_agents');
            $totalAgents = (int) $qb->executeQuery()->fetchOne();

            // Get conversation count.
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('id', 'total'))
                ->from('openregister_conversations');
            $totalConversations = (int) $qb->executeQuery()->fetchOne();

            // Get message count.
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('id', 'total'))
                ->from('openregister_messages');
            $totalMessages = (int) $qb->executeQuery()->fetchOne();

            return new JSONResponse(
                data: [
                    'total_agents'        => $totalAgents,
                    'total_conversations' => $totalConversations,
                    'total_messages'      => $totalMessages,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[ChatController] Failed to get chat stats',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to get chat statistics',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getChatStats()
}//end class
