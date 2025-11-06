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
 * ChatController
 *
 * Controller for handling AI chat API endpoints.
 * Works with conversation-based chat system.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class ChatController extends Controller
{
    /**
     * Chat service
     *
     * @var ChatService
     */
    private ChatService $chatService;

    /**
     * Conversation mapper
     *
     * @var ConversationMapper
     */
    private ConversationMapper $conversationMapper;

    /**
     * Message mapper
     *
     * @var MessageMapper
     */
    private MessageMapper $messageMapper;

    /**
     * Feedback mapper
     *
     * @var FeedbackMapper
     */
    private FeedbackMapper $feedbackMapper;

    /**
     * Agent mapper
     *
     * @var AgentMapper
     */
    private AgentMapper $agentMapper;

    /**
     * Organisation service
     *
     * @var OrganisationService
     */
    private OrganisationService $organisationService;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * User ID
     *
     * @var string
     */
    private string $userId;

    /**
     * Constructor
     *
     * @param string                $appName              Application name
     * @param IRequest              $request              Request object
     * @param ChatService           $chatService          Chat service
     * @param ConversationMapper    $conversationMapper   Conversation mapper
     * @param MessageMapper         $messageMapper        Message mapper
     * @param AgentMapper           $agentMapper          Agent mapper
     * @param OrganisationService   $organisationService  Organisation service
     * @param LoggerInterface       $logger               Logger
     * @param string                $userId               User ID
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
        LoggerInterface $logger,
        string $userId
    ) {
        parent::__construct($appName, $request);
        $this->chatService = $chatService;
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper = $messageMapper;
        $this->feedbackMapper = $feedbackMapper;
        $this->agentMapper = $agentMapper;
        $this->organisationService = $organisationService;
        $this->logger = $logger;
        $this->userId = $userId;

    }//end __construct()


    /**
     * This returns the template of the main app's page
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openregister',
            'index',
            []
        );

    }//end page()


    /**
     * Send a chat message in a conversation and get AI response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Response with message and sources
     */
    public function sendMessage(): JSONResponse
    {
        try {
            // Get request parameters
            $conversationUuid = (string) $this->request->getParam('conversation');
            $agentUuid = (string) $this->request->getParam('agentUuid');
            $message = (string) $this->request->getParam('message');

            if (empty($message)) {
                return new JSONResponse([
                    'error' => 'Missing message',
                    'message' => 'message content is required',
                ], 400);
            }

            // Load or create conversation
            $conversation = null;
            
            if (!empty($conversationUuid)) {
                // Load existing conversation by UUID
                try {
                    $conversation = $this->conversationMapper->findByUuid($conversationUuid);
                } catch (\Exception $e) {
                    return new JSONResponse([
                        'error' => 'Conversation not found',
                        'message' => 'The conversation with UUID ' . $conversationUuid . ' does not exist',
                    ], 404);
                }
            } elseif (!empty($agentUuid)) {
                // Create new conversation with specified agent
                $organisation = $this->organisationService->getActiveOrganisation();
                
                // Look up agent by UUID
                try {
                    $agent = $this->agentMapper->findByUuid($agentUuid);
                } catch (\Exception $e) {
                    return new JSONResponse([
                        'error' => 'Agent not found',
                        'message' => 'The agent with UUID ' . $agentUuid . ' does not exist',
                    ], 404);
                }
                
                // Generate unique default title
                $defaultTitle = $this->chatService->ensureUniqueTitle(
                    'New Conversation',
                    $this->userId,
                    $agent->getId()
                );
                
                $conversation = new Conversation();
                $conversation->setUserId($this->userId);
                $conversation->setOrganisation($organisation?->getUuid());
                $conversation->setAgentId($agent->getId());
                $conversation->setTitle($defaultTitle);
                $conversation = $this->conversationMapper->insert($conversation);

                $this->logger->info('[ChatController] New conversation created', [
                    'uuid' => $conversation->getUuid(),
                    'userId' => $this->userId,
                    'agentId' => $agent->getId(),
                    'title' => $defaultTitle,
                ]);
            } else {
                return new JSONResponse([
                    'error' => 'Missing conversation or agentUuid',
                    'message' => 'Either conversation or agentUuid is required',
                ], 400);
            }
            
            // Verify user has access
            if ($conversation->getUserId() !== $this->userId) {
                return new JSONResponse([
                    'error' => 'Access denied',
                    'message' => 'You do not have access to this conversation',
                ], 403);
            }

            // Process message through ChatService
            $result = $this->chatService->processMessage(
                $conversation->getId(),
                $this->userId,
                $message
            );

            // Add conversation UUID to result for frontend
            $result['conversation'] = $conversation->getUuid();

            return new JSONResponse($result, 200);

        } catch (\Exception $e) {
            $this->logger->error('[ChatController] Failed to send message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JSONResponse([
                'error' => 'Failed to process message',
                'message' => $e->getMessage(),
            ], 500);
        }//end try

    }//end sendMessage()


    /**
     * Get conversation history (messages)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Message history
     */
    public function getHistory(): JSONResponse
    {
        try {
            // Get conversation ID from request
            $conversationId = (int) $this->request->getParam('conversationId');

            if (empty($conversationId)) {
                return new JSONResponse([
                    'error' => 'Missing conversationId',
                    'message' => 'conversationId is required',
                ], 400);
            }

            // Get conversation
            $conversation = $this->conversationMapper->find($conversationId);

            // Verify ownership
            if ($conversation->getUserId() !== $this->userId) {
                return new JSONResponse([
                    'error' => 'Access denied',
                    'message' => 'You do not have access to this conversation',
                ], 403);
            }

            // Get messages
            $limit = (int) ($this->request->getParam('limit') ?? 100);
            $offset = (int) ($this->request->getParam('offset') ?? 0);

            $messages = $this->messageMapper->findByConversation(
                $conversationId,
                $limit,
                $offset
            );

            return new JSONResponse([
                'messages' => array_map(fn($msg) => $msg->jsonSerialize(), $messages),
                'total' => $this->messageMapper->countByConversation($conversationId),
                'conversationId' => $conversationId,
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('[ChatController] Failed to get history', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JSONResponse([
                'error' => 'Failed to fetch conversation history',
                'message' => $e->getMessage(),
            ], 500);
        }//end try

    }//end getHistory()


    /**
     * Clear conversation history (soft delete)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Success message
     */
    public function clearHistory(): JSONResponse
    {
        try {
            // Get conversation ID from request
            $conversationId = (int) $this->request->getParam('conversationId');

            if (empty($conversationId)) {
                return new JSONResponse([
                    'error' => 'Missing conversationId',
                    'message' => 'conversationId is required',
                ], 400);
            }

            // Get conversation
            $conversation = $this->conversationMapper->find($conversationId);

            // Verify ownership
            if ($conversation->getUserId() !== $this->userId) {
                return new JSONResponse([
                    'error' => 'Access denied',
                    'message' => 'You do not have access to this conversation',
                ], 403);
            }

            // Soft delete conversation
            $this->conversationMapper->softDelete($conversationId);

            $this->logger->info('[ChatController] Conversation cleared (soft deleted)', [
                'conversationId' => $conversationId,
                'userId' => $this->userId,
            ]);

            return new JSONResponse([
                'message' => 'Conversation cleared successfully',
                'conversationId' => $conversationId,
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('[ChatController] Failed to clear history', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JSONResponse([
                'error' => 'Failed to clear conversation',
                'message' => $e->getMessage(),
            ], 500);
        }//end try

    }//end clearHistory()


    /**
     * Submit or update feedback on a message
     *
     * Endpoint: POST /api/conversations/{conversationUuid}/messages/{messageId}/feedback
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $conversationUuid Conversation UUID
     * @param int    $messageId        Message ID
     *
     * @return JSONResponse Feedback data
     */
    public function sendFeedback(string $conversationUuid, int $messageId): JSONResponse
    {
        try {
            // Get request parameters
            $type = (string) $this->request->getParam('type');
            $comment = (string) $this->request->getParam('comment', '');

            // Validate feedback type
            if (!in_array($type, ['positive', 'negative'], true)) {
                return new JSONResponse([
                    'error' => 'Invalid feedback type',
                    'message' => 'type must be "positive" or "negative"',
                ], 400);
            }

            // Get conversation by UUID
            $conversation = $this->conversationMapper->findByUuid($conversationUuid);

            // Verify user has access to this conversation
            if ($conversation->getUserId() !== $this->userId) {
                return new JSONResponse([
                    'error' => 'Access denied',
                    'message' => 'You do not have access to this conversation',
                ], 403);
            }

            // Get message and verify it belongs to this conversation
            $message = $this->messageMapper->find($messageId);
            
            if ($message->getConversationId() !== $conversation->getId()) {
                return new JSONResponse([
                    'error' => 'Message not found',
                    'message' => 'Message does not belong to this conversation',
                ], 404);
            }

            // Get active organisation
            $organisation = $this->organisationService->getActiveOrganisation();
            $organisationUuid = $organisation?->getUuid();

            // Check if feedback already exists for this message
            $existingFeedback = $this->feedbackMapper->findByMessage($messageId, $this->userId);

            if ($existingFeedback !== null) {
                // Update existing feedback
                $existingFeedback->setType($type);
                $existingFeedback->setComment($comment);
                
                $feedback = $this->feedbackMapper->update($existingFeedback);
                
                $this->logger->info('[ChatController] Message feedback updated', [
                    'feedbackId' => $feedback->getId(),
                    'messageId' => $messageId,
                    'type' => $type,
                    'hasComment' => !empty($comment),
                ]);
            } else {
                // Create new feedback
                $feedback = new Feedback();
                $feedback->setMessageId($messageId);
                $feedback->setConversationId($conversation->getId());
                $feedback->setAgentId($conversation->getAgentId());
                $feedback->setUserId($this->userId);
                $feedback->setOrganisation($organisationUuid);
                $feedback->setType($type);
                $feedback->setComment($comment);
                
                $feedback = $this->feedbackMapper->insert($feedback);
                
                $this->logger->info('[ChatController] Message feedback created', [
                    'feedbackId' => $feedback->getId(),
                    'messageId' => $messageId,
                    'type' => $type,
                    'hasComment' => !empty($comment),
                ]);
            }

            return new JSONResponse($feedback->jsonSerialize(), 200);

        } catch (\Exception $e) {
            $this->logger->error('[ChatController] Failed to save feedback', [
                'conversationUuid' => $conversationUuid ?? null,
                'messageId' => $messageId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JSONResponse([
                'error' => 'Failed to save feedback',
                'message' => $e->getMessage(),
            ], 500);
        }//end try

    }//end sendFeedback()


}//end class

