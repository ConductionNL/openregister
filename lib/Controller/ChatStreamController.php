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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/chat-ai/spec.md
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Mcp\Service\ChatMessageRequest;
use OCA\OpenRegister\Controller\Support\ChatStreamControllerServices;
use OCA\OpenRegister\Controller\Support\ChatStreamTurn;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Kernel\Contracts\ConversationMapperInterface;
use OCA\OpenRegister\Mcp\Service\Chat\StreamYieldChannels;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ChatStreamChannelInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

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
 * @psalm-suppress                                 UnusedClass
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) SSE controller composes ChatService +
 * ConversationMapper + AgentMapper + IUserSession + IDBConnection + LoggerInterface; each handles a
 * separate concern of the streaming pipeline (auth, DB commit safety, conversation routing, message
 * processing) and cannot be removed without losing functionality.
 */
class ChatStreamController extends Controller {

	/**
	 * Forbidden error sentinel used by resolveConversation() when the
	 * caller does not own the requested conversation.
	 *
	 * @var string
	 */
	private const ERROR_FORBIDDEN = 'cn_chat_stream_forbidden';

	/**
	 * Generic public-facing error message. We deliberately never leak
	 * exception messages over the SSE wire — they can contain DB
	 * connection strings, API key fragments or internal paths. The
	 * full $e->getMessage() is recorded in the logger instead.
	 *
	 * @var string
	 */
	private const PUBLIC_ERROR_MESSAGE = 'An internal error occurred.';

	/**
	 * Chat service for processing messages.
	 *
	 * @var ChatServiceInterface
	 */
	private readonly ChatServiceInterface $chatService;

	/**
	 * Conversation mapper.
	 *
	 * @var ConversationMapperInterface
	 */
	private readonly ConversationMapperInterface $conversationMapper;

	/**
	 * Agent mapper.
	 *
	 * @var AgentMapperInterface
	 */
	private readonly AgentMapperInterface $agentMapper;

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
	 * Database connection, used to commit any open transaction before
	 * the SSE handler bypasses the framework via exit; — preventing the
	 * connection-leak risk flagged in the design review.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Wall-clock timestamp (microtime float) of the most recently emitted
	 * SSE frame of any type. Used by the heartbeat-interleave pre-check
	 * inside the channel callbacks: when `now() - $lastEventAt >= 15.0`
	 * we emit a `heartbeat` frame before forwarding the next event.
	 *
	 * @var float
	 */
	private float $lastEventAt = 0.0;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param ChatStreamControllerServices $services The chat stream controller services.
	 */
	public function __construct(IRequest $request, ChatStreamControllerServices $services) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
		$this->chatService = $services->chat->chatService;
		$this->conversationMapper = $services->chat->conversationMapper;
		$this->agentMapper = $services->chat->agentMapper;
		$this->logger = $services->chat->logger;
		$this->userSession = $services->platform->userSession;
		$this->db = $services->platform->db;
	}//end __construct()

	/**
	 * SSE streaming chat endpoint.
	 *
	 * Emits text/event-stream with the contractual envelope. v1 emits one
	 * final event after the synchronous LLM call completes; token-by-token
	 * streaming is a follow-up.
	 *
	 * The method always terminates with exit; — it bypasses the NC response
	 * pipeline because the SSE framing must reach the wire before that
	 * pipeline would buffer it. The declared `: Response` return type is a
	 * formality required by the framework; cleanup before exit; is funnelled
	 * through emitAndExit() so DB transactions never leak under PHP-FPM.
	 *
	 * CSRF protection is REQUIRED. The endpoint is an authenticated POST that
	 * creates/updates Conversation + Message rows, so removing NC's CSRF
	 * middleware would expose every logged-in user to drive-by chat-thread
	 * forgery from a third-party site. The SSE "EventSource can't carry the
	 * requesttoken header" argument does not apply here: the client invokes
	 * this endpoint via `fetch()` (POST with a JSON body), not EventSource,
	 * and `fetch()` can attach the requesttoken header normally.
	 *
	 * @NoAdminRequired
	 *
	 * @return Response Never returned — emitAndExit() always terminates.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  stream() is the single SSE dispatch path: auth
	 * check, body parse, agent/conversation resolution, heartbeat, channel wiring, and error handling
	 * are sequential guards that cannot be split into sub-methods without leaking the exit-based flow
	 * control across multiple layers.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple independent early-exit paths (unauthenticated,
	 * missing message, missing agent, forbidden conversation, stream failure) must all terminate via
	 * emitAndExit(); collapsing them would hide the distinct SSE error codes.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The method coordinates the full SSE lifecycle in
	 * one readable sequence; extracting sub-stages would split the exit-based control flow across
	 * multiple methods and make the error-path analysis harder.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	#[NoAdminRequired]
	public function stream(): Response {
		// Hard requirement: clear every output buffer layer (PHP, NC framework,
		// any plugin) so the first echo is flushed immediately. Extracted to
		// its own method so unit tests can override and skip — closing
		// PHPUnit's output buffer trips its "risky" detector.
		$this->clearOutputBuffers();

		$this->emitSseHeaders();

		try {
			$this->runStream();
		} catch (Throwable $e) {
			$this->failStream(exception: $e);
		}

		// Unreachable — emitAndExit() never returns. Present so static
		// analysers do not complain about the declared `: Response` return
		// type. The SSE framing is already written to the wire by the time
		// we get anywhere near this line.
		return new Response();
	}//end stream()

	/**
	 * Read the turn, resolve its conversation, process it and emit the final frame.
	 *
	 * @return void
	 */
	private function runStream(): void {
		$turn = $this->readTurn();
		$this->resolveTurnAgent(turn: $turn);
		$turn->conversation = $this->turnConversation(turn: $turn);

		// Emit a heartbeat right after headers so the client knows we're alive
		// (the synchronous LLM call may take >15s on cold-load).
		$this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
		$this->lastEventAt = $this->now();

		$this->emitFinal(turn: $turn, result: $this->processTurn(turn: $turn));
	}//end runStream()

	/**
	 * Read the caller and the request body into a turn; stop when either is missing.
	 *
	 * @return ChatStreamTurn The turn.
	 */
	private function readTurn(): ChatStreamTurn {
		$user = $this->userSession->getUser();
		if ($user === null) {
			$this->emitAndExit(
				eventType: 'error',
				payload: ['code' => 'unauthenticated', 'message' => 'Authentication required']
			);
		}

		$turn         = new ChatStreamTurn();
		$turn->userId = $user->getUID();
		$body         = $this->readBody();
		$turn->message          = $this->requireMessage(body: $body);
		$turn->agentUuid        = (string)($body['agentUuid'] ?? '');
		$turn->conversationUuid = (string)($body['conversationUuid'] ?? '');
		$turn->context          = $body['context'] ?? null;
		return $turn;
	}//end readTurn()

	/**
	 * The decoded request body, [] when it is empty or not JSON.
	 *
	 * @return array The body.
	 */
	private function readBody(): array {
		$rawBody = file_get_contents('php://input');
		if ($rawBody === false || $rawBody === '') {
			$rawBody = '[]';
		}

		return json_decode($rawBody, associative: true) ?? [];
	}//end readBody()

	/**
	 * The trimmed message; stops the stream when it is empty.
	 *
	 * @param array $body The request body.
	 *
	 * @return string The message.
	 */
	private function requireMessage(array $body): string {
		$userMessage = trim((string)($body['message'] ?? ''));
		if ($userMessage === '') {
			$this->emitAndExit(
				eventType: 'error',
				payload: ['code' => 'missing_message', 'message' => 'message content is required']
			);
		}

		return $userMessage;
	}//end requireMessage()

	/**
	 * Pick a fallback agent for a fresh chat; stop when there is none.
	 *
	 * Widget UX: when the widget opens a fresh chat it doesn't know which
	 * agent to use (no agent picker in v1). Fall back to an agent the
	 * CURRENT USER can access (owner / non-private / invited), never the
	 * first agent in the table — that would cross tenant/user boundaries
	 * in a multi-user deployment.
	 *
	 * @param ChatStreamTurn $turn The turn.
	 *
	 * @return void
	 */
	private function resolveTurnAgent(ChatStreamTurn $turn): void {
		if ($turn->conversationUuid === '' && $turn->agentUuid === '') {
			$turn->agentUuid = $this->pickFallbackAgentForUser(userId: $turn->userId);
		}

		if ($turn->conversationUuid === '' && $turn->agentUuid === '') {
			$this->emitAndExit(
				eventType: 'error',
				payload: ['code' => 'missing_agent', 'message' => 'No agent available; configure one in OpenRegister settings.']
			);
		}
	}//end resolveTurnAgent()

	/**
	 * The turn's conversation; a conversation the caller does not own stops the stream.
	 *
	 * @param ChatStreamTurn $turn The turn.
	 *
	 * @return Conversation The conversation.
	 */
	private function turnConversation(ChatStreamTurn $turn): Conversation {
		try {
			return $this->resolveConversation(turn: $turn);
		} catch (RuntimeException $e) {
			if ($e->getMessage() === self::ERROR_FORBIDDEN) {
				$this->emitAndExit(eventType: 'error', payload: ['code' => 'forbidden', 'message' => 'Forbidden']);
			}

			throw $e;
		}
	}//end turnConversation()

	/**
	 * Process the turn through the chat service, streaming over a channel.
	 *
	 * Persist the CnAiContext snapshot on the user-authored Message row only
	 * when it is an array, so a bad client payload doesn't overflow the
	 * column or break the JSON encoding.
	 *
	 * @param ChatStreamTurn $turn The turn.
	 *
	 * @return array The chat service result.
	 */
	private function processTurn(ChatStreamTurn $turn): array {
		$context = [];
		if (is_array($turn->context) === true) {
			$context = $turn->context;
		}

		$request = new ChatMessageRequest(
			conversationId: $turn->conversation->getId(),
			userId: $turn->userId,
			userMessage: $turn->message
		);
		$request->cnAiContext = $context;
		$request->channel = $this->streamChannel();

		return $this->chatService->processMessage(request: $request);
	}//end processTurn()

	/**
	 * A channel whose token, tool call and tool result callbacks emit frames.
	 *
	 * Each callback funnels through `forwardWithHeartbeat()`, which interleaves
	 * a `heartbeat` frame when more than 15s have elapsed (design D3). There is
	 * deliberately no `onHeartbeat` registration; see the note at the foot of
	 * StreamYieldChannel.
	 *
	 * @return ChatStreamChannelInterface The channel.
	 */
	private function streamChannel(): ChatStreamChannelInterface {
		$channel = StreamYieldChannels::channel();
		$channel->onToken(fn (string $delta) => $this->forwardWithHeartbeat(eventType: 'token', payload: ['delta' => $delta]));
		$channel->onToolCall(fn (array $payload) => $this->forwardWithHeartbeat(eventType: 'tool_call', payload: $payload));
		$channel->onToolResult(fn (array $payload) => $this->forwardWithHeartbeat(eventType: 'tool_result', payload: $payload));
		return $channel;
	}//end streamChannel()

	/**
	 * Emit the final frame with the full assistant text.
	 *
	 * @param ChatStreamTurn $turn   The turn.
	 * @param array          $result The chat service result.
	 *
	 * @return void
	 */
	private function emitFinal(ChatStreamTurn $turn, array $result): void {
		$finalPayload = [
			'messageId' => (string)($result['messageId'] ?? ''),
			'conversationUuid' => $turn->conversation->getUuid(),
			'fullText' => (string)($result['response'] ?? $result['message'] ?? ''),
			'context' => $turn->context,
		];
		$this->emitAndExit(eventType: 'final', payload: $finalPayload);
	}//end emitFinal()

	/**
	 * Log the failure and emit the generic `stream_failed` error frame.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return void
	 */
	private function failStream(Throwable $exception): void {
		$this->logger->error(
			message: '[ChatStreamController] Stream failed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception, 'error' => $exception->getMessage()]
		);
		$this->emitAndExit(
			eventType: 'error',
			payload: ['code' => 'stream_failed', 'message' => self::PUBLIC_ERROR_MESSAGE]
		);
	}//end failStream()

	/**
	 * Emit a single SSE frame, release any open DB transaction, then exit.
	 *
	 * Centralising every termination point through this helper ensures that
	 * the connection-leak risk of bypassing NC's response pipeline (see the
	 * Stream design doc, "exit; under PHP-FPM" section) is mitigated: any
	 * in-flight transaction is finalised before the process is torn down.
	 * The error path rolls back; only the successful `final` path commits.
	 *
	 * @param string $eventType Event type. When 'error', any
	 *                          open transaction is rolled back
	 *                          instead of committed — partial
	 *                          writes from a failed
	 *                          ChatService::processMessage()
	 *                          call must not be persisted.
	 * @param array<string, mixed> $payload JSON-encodable payload.
	 *
	 * @return never
	 *
	 * @SuppressWarnings(PHPMD.ExitExpression) SSE streaming contract requires process termination after
	 * the final frame; exit cannot be replaced by a return because the calling loop in stream() must
	 * stop unconditionally.
	 *
	 * @spec exclude SSE-framing helper: emits one frame, finalises the DB transaction, and exits;
	 *              the streaming endpoint contract is owned by
	 *              ai-chat-companion-orchestrator/specs/chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream.
	 */
	protected function emitAndExit(string $eventType, array $payload): never {
		$this->emitSseEvent(eventType: $eventType, payload: $payload);
		$this->safeShutdown(rollback: $eventType === 'error');
		exit;
	}//end emitAndExit()

	/**
	 * Finalise any open DB transaction so the connection can be released
	 * cleanly when PHP shuts the process down after exit;.
	 *
	 * On the error path callers pass $rollback=true so partial writes left
	 * behind by a failed service call are discarded. The success path
	 * (the 'final' SSE frame) commits.
	 *
	 * Best-effort: swallow any exception so cleanup never masks the
	 * user-visible SSE frame we just emitted.
	 *
	 * @param bool $rollback True to roll back, false to commit.
	 *
	 * @return void
	 */
	private function safeShutdown(bool $rollback): void {
		try {
			if ($this->db->inTransaction() === true) {
				if ($rollback === true) {
					$this->db->rollBack();
					return;
				}

				$this->db->commit();
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ChatStreamController] Shutdown finalisation failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'rollback' => $rollback,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end safeShutdown()

	/**
	 * Emit a single SSE frame and flush.
	 *
	 * @param string $eventType Event type (token / tool_call /
	 *                          tool_result / heartbeat / final /
	 *                          error).
	 * @param array<string, mixed> $payload JSON-encodable payload.
	 *
	 * @return void
	 *
	 * @spec exclude SSE-framing helper: writes one `event:`/`data:` frame and flushes;
	 *              the streaming endpoint contract is owned by
	 *              ai-chat-companion-orchestrator/specs/chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream.
	 */
	protected function emitSseEvent(string $eventType, array $payload): void {
		echo 'event: ' . $eventType . "\n";
		echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
		flush();
	}//end emitSseEvent()

	/**
	 * Current wall-clock seconds as a float. Production returns
	 * `microtime(true)`; tests override to drive a fake clock.
	 *
	 * @return float Seconds since the Unix epoch.
	 *
	 * @spec exclude SSE-framing helper: test-overridable wall-clock used by the heartbeat interleave;
	 *              the streaming endpoint contract is owned by
	 *              ai-chat-companion-orchestrator/specs/chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream.
	 */
	protected function now(): float {
		return microtime(true);
	}//end now()

	/**
	 * Emit a payload as an SSE frame, interleaving a `heartbeat` frame
	 * first whenever ≥15s have elapsed since the last emit. Both the
	 * heartbeat (when emitted) and the incoming frame update
	 * `$this->lastEventAt` so back-to-back frames within the same yield
	 * cycle do not retrigger the interleave.
	 *
	 * The 15-second threshold is fixed per design D3. Pre-emit
	 * interleaving (rather than post-emit scheduling) keeps the contract
	 * exactly: "heartbeat appears immediately before the next outgoing
	 * frame when the wall-clock interval since the last event exceeds
	 * 15.0s".
	 *
	 * @param string $eventType Frame type to emit after the
	 *                          optional heartbeat.
	 * @param array<string, mixed> $payload Frame payload.
	 *
	 * @return void
	 *
	 * @spec exclude SSE-framing helper: interleaves a heartbeat frame before forwarding when >15s elapsed;
	 *              the streaming endpoint contract is owned by
	 *              ai-chat-companion-orchestrator/specs/chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream.
	 */
	protected function forwardWithHeartbeat(string $eventType, array $payload): void {
		$now = $this->now();
		if (($now - $this->lastEventAt) >= 15.0) {
			$this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
			$this->lastEventAt = $now;
		}

		$this->emitSseEvent(eventType: $eventType, payload: $payload);
		$this->lastEventAt = $this->now();
	}//end forwardWithHeartbeat()

	/**
	 * Clear every output buffer layer (PHP, NC framework, any plugin) so
	 * the first SSE echo is flushed immediately. Extracted from stream()
	 * so unit tests can override and skip — closing PHPUnit's output
	 * buffer trips its "risky" detector.
	 *
	 * @return void
	 *
	 * @spec exclude SSE-framing helper: clears output buffers so the first frame flushes immediately;
	 *              the streaming endpoint contract is owned by
	 *              ai-chat-companion-orchestrator/specs/chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream.
	 */
	protected function clearOutputBuffers(): void {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
	}//end clearOutputBuffers()

	/**
	 * Emit the three SSE response headers, plus the or-chat-proxy-deprecation
	 * compat-window headers. Extracted so tests can skip (header() emits a
	 * "headers already sent" warning under PHPUnit because the test runner
	 * has already written output).
	 *
	 * The three deprecation headers are added here — rather than relying on
	 * ChatCompatMiddleware::afterController() the way every other
	 * chat-family controller does — because this method always terminates
	 * via `exit;` (see stream()'s docblock) and bypasses the AppFramework
	 * Response/middleware pipeline entirely on the local-serving path.
	 * ChatCompatMiddleware still covers the *proxied* path (its
	 * beforeController() redirect short-circuits before stream() ever runs,
	 * so afterController() applies there as normal); this is only the
	 * local-serving fallback.
	 *
	 * @return void
	 *
	 * @spec exclude SSE-framing helper: emits the three text/event-stream response headers
	 *              plus the or-chat-proxy-deprecation compat headers; the streaming endpoint
	 *              contract is owned by
	 *              ai-chat-companion-orchestrator/specs/chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream.
	 */
	protected function emitSseHeaders(): void {
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('X-Accel-Buffering: no');
		// Or-chat-proxy-deprecation compat window — see ChatCompatMiddleware.
		header('Deprecation: Mon, 06 Jul 2026 00:00:00 GMT');
		header('Sunset: Wed, 06 Jan 2027 00:00:00 GMT');
		header('Link: </apps/hermiq/api/chat>; rel="successor-version"');
	}//end emitSseHeaders()

	/**
	 * Find an agent the current user is allowed to start a conversation with.
	 *
	 * Iterates agents (most-recent first) and returns the first uuid whose
	 * canUserAccessAgent() check passes — non-private OR owned-by-user OR
	 * invited. Falls back to '' when no accessible agent exists. NEVER
	 * returns "the first row of openregister_agents regardless of owner":
	 * that was the original implementation and caused cross-user data
	 * exposure in multi-user deployments.
	 *
	 * @param string $userId Nextcloud user id.
	 *
	 * @return string The accessible agent uuid, or '' when none is found.
	 */
	private function pickFallbackAgentForUser(string $userId): string {
		try {
			// Cheap cap — we only need the first match. Twenty rows is
			// enough headroom for any realistic instance.
			$agents = $this->agentMapper->findAll(limit: 20);
			foreach ($agents as $agent) {
				if ($this->agentMapper->canUserAccessAgent(agent: $agent, userId: $userId) === true) {
					return (string)$agent->getUuid();
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ChatStreamController] Agent fallback lookup failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return '';
	}//end pickFallbackAgentForUser()

	/**
	 * Resolve (load or create) the conversation referenced by the request.
	 *
	 * Mirrors ChatController::resolveConversation but kept local so this
	 * controller does not couple to the existing chat controller's private
	 * helpers. When a conversationUuid is supplied, ownership is verified
	 * against the calling user — preventing the IDOR described in the
	 * security review (any authed user could otherwise read/append to
	 * another user's thread by guessing the uuid).
	 *
	 * @param ChatStreamTurn $turn The turn: its conversation UUID ('' to create new),
	 *                             agent UUID (required when creating new) and caller.
	 *
	 * @return Conversation Resolved conversation.
	 *
	 * @throws RuntimeException When the caller does not own the conversation
	 *                          (message === self::ERROR_FORBIDDEN); the
	 *                          stream() entry point translates this into the
	 *                          `forbidden` SSE error.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function resolveConversation(ChatStreamTurn $turn): Conversation {
		$userId = $turn->userId;
		if ($turn->conversationUuid !== '') {
			$conversation = $this->conversationMapper->findByUuid(uuid: $turn->conversationUuid);

			// IDOR guard: findByUuid() does not scope by user, so we MUST
			// verify the conversation belongs to the caller. Without this
			// check any authed user could supply any conversationUuid and
			// hijack another user's thread.
			if ($conversation->getUserId() !== $userId) {
				throw new RuntimeException(self::ERROR_FORBIDDEN);
			}

			return $conversation;
		}

		// Need an agent to create a new conversation.
		$agent = $this->agentMapper->findByUuid(uuid: $turn->agentUuid);

		$conversation = new Conversation();
		$conversation->setUserId($userId);
		$conversation->setAgentId($agent->getId());
		$conversation->setTitle('New conversation');

		return $this->conversationMapper->insert($conversation);
	}//end resolveConversation()
}//end class
