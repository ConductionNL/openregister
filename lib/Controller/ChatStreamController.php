<?php

/**
 * OpenRegister Chat Stream Controller
 *
 * Server-Sent Events endpoint for streaming AI chat responses token by token.
 * Also exposes a public health endpoint so clients can verify LLM availability.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ChatStreamController
 *
 * Streams LLM token events as Server-Sent Events over POST /api/chat/stream.
 * Emits: heartbeat (initial + interleaved), token, tool_call, tool_result, final, error.
 *
 * The heartbeat interleave fires automatically when >15 s pass between events via
 * the protected now() hook — this hook is overridden in tests to drive a fake clock.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ChatStreamController extends Controller
{

    /**
     * Heartbeat interval in seconds — emit a heartbeat when no event fires for this long.
     *
     * @var float
     */
    private const HEARTBEAT_INTERVAL = 15.0;

    /**
     * Constructor.
     *
     * @param string              $appName             Application name.
     * @param IRequest            $request             HTTP request.
     * @param ChatService         $chatService         Chat orchestration service.
     * @param ConversationMapper  $conversationMapper  Conversation mapper.
     * @param AgentMapper         $agentMapper         Agent mapper.
     * @param OrganisationService $organisationService Organisation service.
     * @param SettingsService     $settingsService     Settings service for health check.
     * @param LoggerInterface     $logger              Logger.
     * @param string              $userId              Current user ID.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ChatService $chatService,
        private readonly ConversationMapper $conversationMapper,
        private readonly AgentMapper $agentMapper,
        private readonly OrganisationService $organisationService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly string $userId
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Current wall-clock time in seconds. Override in tests to drive a fake clock.
     *
     * @return float Monotonic timestamp (microtime).
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-5.1
     */
    protected function now(): float
    {
        return microtime(true);

    }//end now()

    /**
     * Stream an AI chat response as Server-Sent Events.
     *
     * Accepts the same request body as POST /api/chat/send. Responds with
     * text/event-stream and emits: heartbeat (initial + interleaved every 15s),
     * token (per LLM chunk), tool_call, tool_result, and final.
     * On error, emits an error event then terminates.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return Response Empty response (SSE output already sent via echo).
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-4
     */
    public function stream(): Response
    {
        // Set SSE headers before any output.
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Disable output buffering.
        if (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Track last-event timestamp for heartbeat interleave.
        $lastEventAt = $this->now();

        try {
            // Extract request parameters (same shape as ChatController::sendMessage).
            $conversationUuid = (string) $this->request->getParam('conversation');
            $agentUuid        = (string) $this->request->getParam('agentUuid');
            $message          = (string) $this->request->getParam('message');
            $selectedViews    = [];
            $viewsParam       = $this->request->getParam('views');
            if ($viewsParam !== null && is_array($viewsParam) === true) {
                $selectedViews = $viewsParam;
            }

            $selectedTools = [];
            $toolsParam    = $this->request->getParam('tools');
            if ($toolsParam !== null && is_array($toolsParam) === true) {
                $selectedTools = $toolsParam;
            }

            $ragSettings = [
                'includeObjects'    => $this->request->getParam('includeObjects') ?? true,
                'includeFiles'      => $this->request->getParam('includeFiles') ?? true,
                'numSourcesFiles'   => $this->request->getParam('numSourcesFiles') ?? 5,
                'numSourcesObjects' => $this->request->getParam('numSourcesObjects') ?? 5,
            ];

            if (empty($message) === true) {
                $this->emitSseEvent(eventType: 'error', payload: ['message' => 'message is required']);
                return new Response();
            }

            // Resolve or create conversation.
            $conversation = $this->resolveConversation(
                conversationUuid: $conversationUuid,
                agentUuid: $agentUuid
            );

            // Per-object authorization — IDOR guard (Rule 3 / ADR-005).
            if ($conversation->getUserId() !== $this->userId) {
                $this->emitSseEvent(eventType: 'error', payload: ['message' => 'Access denied']);
                return new Response();
            }

            // Initial heartbeat — confirms connection before the LLM call.
            $this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
            $lastEventAt = $this->now();

            // Build streaming channel and register SSE callbacks.
            $channel = new StreamYieldChannel();

            $channel->onToken(
                fn(string $delta) => $this->forwardWithHeartbeat(
                    eventType: 'token',
                    payload: ['delta' => $delta],
                    lastEventAt: $lastEventAt
                )
            );

            $channel->onToolCall(
                fn(array $data) => $this->forwardWithHeartbeat(
                    eventType: 'tool_call',
                    payload: $data,
                    lastEventAt: $lastEventAt
                )
            );

            $channel->onToolResult(
                fn(array $data) => $this->forwardWithHeartbeat(
                    eventType: 'tool_result',
                    payload: $data,
                    lastEventAt: $lastEventAt
                )
            );

            $channel->onHeartbeat(
                function () use (&$lastEventAt): void {
                    $this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
                    $lastEventAt = $this->now();
                }
            );

            // Process message — LLM call runs here; channel callbacks fire per token.
            $result = $this->chatService->processMessage(
                conversationId: $conversation->getId(),
                userId: $this->userId,
                userMessage: $message,
                selectedViews: $selectedViews,
                selectedTools: $selectedTools,
                ragSettings: $ragSettings,
                channel: $channel
            );

            // Emit final event with the persisted message data.
            $this->emitSseEvent(
                eventType: 'final',
                payload: [
                    'messageId'        => $result['messageId'] ?? null,
                    'conversationUuid' => $conversation->getUuid(),
                    'fullText'         => $result['message'],
                    'context'          => $result['sources'] ?? [],
                ]
            );
            $lastEventAt = $this->now();
        } catch (Exception $e) {
            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode >= 600) {
                $statusCode = 500;
            }

            $this->logger->error(
                message: '[ChatStreamController] Streaming error',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            $this->emitSseEvent(
                eventType: 'error',
                payload: [
                    'message' => $e->getMessage(),
                    'code'    => $statusCode,
                ]
            );
        }//end try

        return new Response();

    }//end stream()

    /**
     * LLM health check endpoint.
     *
     * Returns 200 with provider capabilities when a chat provider is configured,
     * 503 when not. Intentionally public — widgets need this before auth is set up.
     *
     * @PublicPage
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Health status and provider capabilities.
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-4
     */
    public function health(): JSONResponse
    {
        try {
            $llmConfig    = $this->settingsService->getLLMSettingsOnly();
            $chatProvider = $llmConfig['chatProvider'] ?? null;

            if (empty($chatProvider) === true) {
                return new JSONResponse(
                    data: [
                        'status'  => 'unavailable',
                        'message' => 'No chat provider configured',
                    ],
                    statusCode: 503
                );
            }

            return new JSONResponse(
                data: [
                    'status'       => 'ok',
                    'provider'     => $chatProvider,
                    'capabilities' => [
                        'streaming' => in_array($chatProvider, ['openai', 'ollama'], true),
                        'tools'     => in_array($chatProvider, ['openai', 'ollama'], true),
                    ],
                ],
                statusCode: 200
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['status' => 'error', 'message' => $e->getMessage()],
                statusCode: 503
            );
        }//end try

    }//end health()

    /**
     * Emit one SSE event frame to the client.
     *
     * Format: "event: {type}\ndata: {json}\n\n"
     *
     * @param string $eventType SSE event type label.
     * @param array  $payload   Event payload (JSON-encoded).
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-4.1
     */
    protected function emitSseEvent(string $eventType, array $payload): void
    {
        echo 'event: '.$eventType."\n";
        echo 'data: '.json_encode($payload)."\n\n";
        flush();

    }//end emitSseEvent()

    /**
     * Forward an event, interleaving a heartbeat first when >15 s have elapsed.
     *
     * Checks `$this->now() - $lastEventAt`. If the gap exceeds HEARTBEAT_INTERVAL,
     * a heartbeat frame is emitted and `$lastEventAt` is reset before forwarding the
     * real event. A 30-second stall triggers exactly one heartbeat; a 35-second stall
     * triggers two (the second fires when the next event arrives after the first
     * heartbeat was emitted, and the gap since that heartbeat is still >15 s).
     *
     * @param string $eventType   SSE event type.
     * @param array  $payload     Event payload.
     * @param float  $lastEventAt Reference to the last-event timestamp (updated in-place).
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-5.3
     */
    protected function forwardWithHeartbeat(string $eventType, array $payload, float &$lastEventAt): void
    {
        // Drain any accumulated 15-second windows before forwarding the real event.
        while (($this->now() - $lastEventAt) >= self::HEARTBEAT_INTERVAL) {
            $this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
            $lastEventAt = $this->now();
        }

        $this->emitSseEvent(eventType: $eventType, payload: $payload);
        $lastEventAt = $this->now();

    }//end forwardWithHeartbeat()

    /**
     * Resolve an existing conversation or create a new one.
     *
     * @param string $conversationUuid Existing conversation UUID (may be empty).
     * @param string $agentUuid        Agent UUID for new conversation (may be empty).
     *
     * @return Conversation Resolved or created conversation.
     *
     * @throws Exception If neither parameter is provided or entity not found.
     */
    private function resolveConversation(string $conversationUuid, string $agentUuid): Conversation
    {
        if (empty($conversationUuid) === false) {
            try {
                return $this->conversationMapper->findByUuid($conversationUuid);
            } catch (Exception $e) {
                throw new Exception('Conversation not found: '.$conversationUuid, 404);
            }
        }

        if (empty($agentUuid) === false) {
            try {
                $agent = $this->agentMapper->findByUuid($agentUuid);
            } catch (Exception $e) {
                throw new Exception('Agent not found: '.$agentUuid, 404);
            }

            $organisation = $this->organisationService->getActiveOrganisation();
            $defaultTitle = $this->chatService->ensureUniqueTitle(
                baseTitle: 'New Conversation',
                userId: $this->userId,
                agentId: $agent->getId()
            );

            $conversation = new Conversation();
            $conversation->setUserId($this->userId);
            $conversation->setOrganisation($organisation?->getUuid());
            $conversation->setAgentId($agent->getId());
            $conversation->setTitle($defaultTitle);

            return $this->conversationMapper->insert($conversation);
        }//end if

        throw new Exception('Either conversation or agentUuid is required', 400);

    }//end resolveConversation()
}//end class
