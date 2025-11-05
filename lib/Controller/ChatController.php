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
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\MessageMapper;
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
     * @param string             $appName             Application name
     * @param IRequest           $request             Request object
     * @param ChatService        $chatService         Chat service
     * @param ConversationMapper $conversationMapper  Conversation mapper
     * @param MessageMapper      $messageMapper       Message mapper
     * @param LoggerInterface    $logger              Logger
     * @param string             $userId              User ID
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ChatService $chatService,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        LoggerInterface $logger,
        string $userId
    ) {
        parent::__construct($appName, $request);
        $this->chatService = $chatService;
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper = $messageMapper;
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
            $conversationId = (int) $this->request->getParam('conversationId');
            $message = (string) $this->request->getParam('message');

            if (empty($conversationId)) {
                return new JSONResponse([
                    'error' => 'Missing conversationId',
                    'message' => 'conversationId is required',
                ], 400);
            }

            if (empty($message)) {
                return new JSONResponse([
                    'error' => 'Missing message',
                    'message' => 'message content is required',
                ], 400);
            }

            // Process message through ChatService
            $result = $this->chatService->processMessage(
                $conversationId,
                $this->userId,
                $message
            );

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
     * Send feedback for a message
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Success message
     */
    public function sendFeedback(): JSONResponse
    {
        try {
            // Get request parameters
            $messageId = (int) $this->request->getParam('messageId');
            $feedback = (string) $this->request->getParam('feedback');

            if (empty($messageId)) {
                return new JSONResponse([
                    'error' => 'Missing messageId',
                    'message' => 'messageId is required',
                ], 400);
            }

            if (!in_array($feedback, ['positive', 'negative'], true)) {
                return new JSONResponse([
                    'error' => 'Invalid feedback',
                    'message' => 'feedback must be "positive" or "negative"',
                ], 400);
            }

            // Get message
            $message = $this->messageMapper->find($messageId);

            // Get conversation to verify ownership
            $conversation = $this->conversationMapper->find($message->getConversationId());

            if ($conversation->getUserId() !== $this->userId) {
                return new JSONResponse([
                    'error' => 'Access denied',
                    'message' => 'You do not have access to this message',
                ], 403);
            }

            // TODO: Store feedback in a separate feedback table
            // For now, we'll just log it
            $this->logger->info('[ChatController] Message feedback received', [
                'messageId' => $messageId,
                'feedback' => $feedback,
                'userId' => $this->userId,
            ]);

            return new JSONResponse([
                'message' => 'Feedback recorded successfully',
                'messageId' => $messageId,
                'feedback' => $feedback,
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('[ChatController] Failed to send feedback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JSONResponse([
                'error' => 'Failed to record feedback',
                'message' => $e->getMessage(),
            ], 500);
        }//end try

    }//end sendFeedback()


}//end class

