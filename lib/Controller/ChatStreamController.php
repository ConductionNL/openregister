<?php

/**
 * OpenRegister Chat Stream Controller
 *
 * SSE streaming endpoint for the AI chat companion widget. Wraps the
 * existing ChatService::processMessage pipeline (RAG + MCP fan-out + LLM)
 * and emits a Server-Sent Events stream conformant to the six-event envelope
 * defined in hydra ADR-034.
 *
 * v1 implementation degrades gracefully via the non-streaming-provider
 * clause of the envelope: it executes the synchronous LLM call, then emits
 * a single `final` event carrying the full text. True token-by-token
 * streaming via LLPhant's streaming hooks is a follow-up — the contract
 * already accommodates this degradation, so no client change is needed
 * when token streaming lands later.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-chat-companion-orchestrator/specs/chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Service\ChatService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * ChatStreamController
 *
 * Implements POST /api/chat/stream — Server-Sent Events streaming of an AI
 * response. The v1 controller emits the contractual envelope (`text/event-stream`
 * with `final` + optional `error` events) but does not yet stream tokens
 * incrementally; that arrives once LLPhant's streaming hooks are wired in.
 * Clients (the nextcloud-vue widget) handle both shapes without branching
 * because the non-streaming-provider clause is part of the contract.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 */
class ChatStreamController extends Controller
{

    /**
     * Chat service for processing messages.
     *
     * @var ChatService
     */
    private readonly ChatService $chatService;

    /**
     * Conversation mapper.
     *
     * @var ConversationMapper
     */
    private readonly ConversationMapper $conversationMapper;

    /**
     * Agent mapper.
     *
     * @var AgentMapper
     */
    private readonly AgentMapper $agentMapper;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * User session.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Constructor.
     *
     * @param string             $appName            Application name.
     * @param IRequest           $request            HTTP request.
     * @param ChatService        $chatService        Chat service.
     * @param ConversationMapper $conversationMapper Conversation mapper.
     * @param AgentMapper        $agentMapper        Agent mapper.
     * @param LoggerInterface    $logger             Logger.
     * @param IUserSession       $userSession        User session.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ChatService $chatService,
        ConversationMapper $conversationMapper,
        AgentMapper $agentMapper,
        LoggerInterface $logger,
        IUserSession $userSession
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->chatService        = $chatService;
        $this->conversationMapper = $conversationMapper;
        $this->agentMapper        = $agentMapper;
        $this->logger      = $logger;
        $this->userSession = $userSession;
    }//end __construct()

    /**
     * SSE streaming chat endpoint.
     *
     * Emits text/event-stream with the contractual envelope. v1 emits one
     * final event after the synchronous LLM call completes; token-by-token
     * streaming is a follow-up.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return Response Unused — the method emits SSE directly and exits.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function stream(): Response
    {
        // Hard requirement: clear every output buffer layer (PHP, NC framework,
        // any plugin) so the first echo is flushed immediately.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                $this->emitSseEvent(eventType: 'error', payload: ['code' => 'unauthenticated', 'message' => 'Authentication required']);
                exit;
            }

            $userId  = $user->getUID();
            $rawBody = file_get_contents('php://input');
            if ($rawBody === false || $rawBody === '') {
                $rawBody = '[]';
            }

            $body = json_decode($rawBody, associative: true) ?? [];

            $userMessage = trim((string) ($body['message'] ?? ''));
            if ($userMessage === '') {
                $this->emitSseEvent(eventType: 'error', payload: ['code' => 'missing_message', 'message' => 'message content is required']);
                exit;
            }

            // Resolve agent + conversation.
            $agentUuid        = (string) ($body['agentUuid'] ?? '');
            $conversationUuid = (string) ($body['conversationUuid'] ?? '');
            $context          = $body['context'] ?? null;

            // Widget UX: when the widget opens a fresh chat it doesn't know which
            // agent to use (no agent picker in v1). Fall back to the first agent
            // the user can access if neither agentUuid nor conversationUuid was
            // supplied. Matches the "one global thread per (user, agent)"
            // architectural decision in hydra ADR-034.
            if ($conversationUuid === '' && $agentUuid === '') {
                try {
                    $agents = $this->agentMapper->findAll(limit: 1);
                    if (count($agents) > 0) {
                        $agentUuid = $agents[0]->getUuid();
                    }
                } catch (\Throwable $e) {
                    // Fall through to the missing_agent error below.
                }
            }

            if ($conversationUuid === '' && $agentUuid === '') {
                $this->emitSseEvent(
                    eventType: 'error',
                    payload: [
                        'code'    => 'missing_agent',
                        'message' => 'No agent available; configure one in OpenRegister settings.',
                    ]
                );
                exit;
            }

            $conversation = $this->resolveConversation(conversationUuid: $conversationUuid, agentUuid: $agentUuid, userId: $userId);

            // Persist context on the next message before delegating to ChatService.
            // ChatService::processMessage will create the user-authored Message row;
            // the orchestrator's Message.context migration ensures the column exists
            // and ChatService writes the field. For now we pass context via the
            // ragSettings extension shim until ChatService grows a first-class
            // context parameter — wired in a follow-up.
            $ragSettings = ['__cn_ai_context__' => $context];

            // Emit a heartbeat right after headers so the client knows we're alive
            // (the synchronous LLM call may take >15s on cold-load).
            $this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);

            // Synchronous LLM call.
            $result = $this->chatService->processMessage(
                conversationId: $conversation->getId(),
                userId: $userId,
                userMessage: $userMessage,
                selectedViews: [],
                selectedTools: [],
                ragSettings: $ragSettings
            );

            // Emit final event with the full assistant text.
            $finalPayload = [
                'messageId'        => (string) ($result['messageId'] ?? ''),
                'conversationUuid' => $conversation->getUuid(),
                'fullText'         => (string) ($result['response'] ?? $result['message'] ?? ''),
                'context'          => $context,
            ];
            $this->emitSseEvent(eventType: 'final', payload: $finalPayload);
            exit;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ChatStreamController] Stream failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            $this->emitSseEvent(
                eventType: 'error',
                payload: [
                    'code'    => 'stream_failed',
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Stream failed',
                ]
            );
            exit;
        }//end try
    }//end stream()

    /**
     * Emit a single SSE frame and flush.
     *
     * @param string               $eventType Event type (token / tool_call /
     *                                        tool_result / heartbeat / final /
     *                                        error).
     * @param array<string, mixed> $payload   JSON-encodable payload.
     *
     * @return void
     */
    private function emitSseEvent(string $eventType, array $payload): void
    {
        echo 'event: '.$eventType."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
        flush();
    }//end emitSseEvent()

    /**
     * Resolve (load or create) the conversation referenced by the request.
     *
     * Mirrors ChatController::resolveConversation but kept local so this
     * controller does not couple to the existing chat controller's private
     * helpers.
     *
     * @param string $conversationUuid Conversation UUID or '' to create new.
     * @param string $agentUuid        Agent UUID (required when creating new).
     * @param string $userId           Current user ID.
     *
     * @return Conversation Resolved conversation.
     *
     * @throws Exception When neither uuid is sufficient to resolve a conversation.
     */
    private function resolveConversation(string $conversationUuid, string $agentUuid, string $userId): Conversation
    {
        if ($conversationUuid !== '') {
            return $this->conversationMapper->findByUuid(uuid: $conversationUuid);
        }

        // Need an agent to create a new conversation.
        $agent = $this->agentMapper->findByUuid(uuid: $agentUuid);

        $conversation = new Conversation();
        $conversation->setUserId($userId);
        $conversation->setAgentId($agent->getId());
        $conversation->setTitle('New conversation');

        return $this->conversationMapper->insert($conversation);
    }//end resolveConversation()
}//end class
