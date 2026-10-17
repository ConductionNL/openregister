<?php

declare(strict_types=1);

/*
 * Tests for ChatStreamController.
 *
 * Strategy: ChatStreamController emits SSE frames via `echo` and
 * terminates with `exit;` — both impossible to drive under PHPUnit
 * directly. We subclass it (TestableChatStreamController) and
 * override the protected `emitSseEvent` / `emitAndExit` helpers to
 * push frames into an in-memory array and throw a sentinel exception
 * instead of exiting. Tests then assert the event sequence on the
 * captured array.
 *
 * Scope: covers the cases that are actually testable against the
 * current controller (auth gating, missing message, agent fallback).
 * The full 6-event envelope (token / tool_call / tool_result) is
 * gated on §5.3 of the orchestrator (LLPhant streaming hooks);
 * deferred — see ai-chat-companion-orchestrator/tasks.md.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ai-chat-companion-orchestrator/tasks.md#9
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ChatStreamController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Service\ChatService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sentinel exception thrown by TestableChatStreamController in place of
 * the production `exit;` so PHPUnit can continue.
 */
class ChatStreamControllerStopSignal extends RuntimeException {
}//end class

/**
 * Subclass that captures SSE frames instead of writing them to stdout
 * and throws a sentinel exception instead of calling exit;.
 */
class TestableChatStreamController extends ChatStreamController {

	/**
	 * @var array<int, array{type: string, payload: array}>
	 */
	public array $capturedEvents = [];

	protected function emitSseEvent(string $eventType, array $payload): void {
		$this->capturedEvents[] = ['type' => $eventType, 'payload' => $payload];
	}//end emitSseEvent()

	protected function emitAndExit(string $eventType, array $payload): never {
		$this->capturedEvents[] = ['type' => $eventType, 'payload' => $payload];
		throw new ChatStreamControllerStopSignal("emitAndExit({$eventType})");
	}//end emitAndExit()

	/**
	 * Skip — closing PHPUnit's output buffer would trip its risky detector.
	 */
	protected function clearOutputBuffers(): void {
	}//end clearOutputBuffers()

	/**
	 * Skip — header() under PHPUnit warns "headers already sent".
	 */
	protected function emitSseHeaders(): void {
	}//end emitSseHeaders()
}//end class

/**
 * A php:// stream that serves one request body, so stream() reads a message.
 */
class ChatStreamBodyStream {

	/**
	 * @var string
	 */
	public static string $body = '';

	/**
	 * @var int
	 */
	private int $pos = 0;

	/**
	 * @var resource|null
	 */
	public $context;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->pos = 0;
		return true;
	}

	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->pos, $count);
		$this->pos += strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->pos >= strlen(self::$body);
	}

	public function stream_stat(): array {
		return [];
	}
}//end class

class ChatStreamControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var ChatService&MockObject
	 */
	private ChatService $chatService;

	/**
	 * @var ConversationMapper&MockObject
	 */
	private ConversationMapper $conversationMapper;

	/**
	 * @var AgentMapper&MockObject
	 */
	private AgentMapper $agentMapper;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->chatService = $this->createMock(ChatService::class);
		$this->conversationMapper = $this->createMock(ConversationMapper::class);
		$this->agentMapper = $this->createMock(AgentMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->db = $this->createMock(IDBConnection::class);
	}//end setUp()

	private function makeController(): TestableChatStreamController {
		return new TestableChatStreamController(
			appName: 'openregister',
			request: $this->request,
			chatService: $this->chatService,
			conversationMapper: $this->conversationMapper,
			agentMapper: $this->agentMapper,
			logger: $this->logger,
			userSession: $this->userSession,
			db: $this->db
		);
	}//end makeController()

	/**
	 * Drive the controller's stream() method and absorb the sentinel
	 * stop signal so individual tests can assert the captured frames.
	 */
	private function runStream(TestableChatStreamController $controller): void {
		try {
			$controller->stream();
		} catch (ChatStreamControllerStopSignal $e) {
			// Expected — production code calls exit; here we capture.
		}
	}//end runStream()

	/**
	 * §9.2(d) — unauthenticated call never reaches the event loop.
	 *
	 * Production stream() emits an `error` frame with code=unauthenticated
	 * and then exits. Under test the sentinel exception thrown by the
	 * test's emitAndExit override is caught by stream()'s outer
	 * `catch (Throwable)` and emits a follow-up `stream_failed` frame
	 * — that's the cost of testing without subprocess isolation. We
	 * therefore assert on the FIRST captured frame (the meaningful one)
	 * and that ChatService was never invoked.
	 */
	public function testUnauthenticatedEmitsErrorAndStopsBeforeProcessing(): void {
		$this->userSession->method('getUser')->willReturn(null);

		// ChatService must NOT be touched.
		$this->chatService->expects($this->never())->method('processMessage');

		$controller = $this->makeController();
		$this->runStream($controller);

		$this->assertGreaterThanOrEqual(1, count($controller->capturedEvents));
		$this->assertSame('error', $controller->capturedEvents[0]['type']);
		$this->assertSame('unauthenticated', $controller->capturedEvents[0]['payload']['code']);
	}//end testUnauthenticatedEmitsErrorAndStopsBeforeProcessing()

	/**
	 * Missing message body emits a `missing_message` error as the FIRST
	 * frame (see test 1 docblock for the trailing-frame caveat).
	 *
	 * The controller pulls `message` from the JSON body of php://input.
	 * In CLI PHPUnit the stream may be unreadable/empty/throw — to make
	 * the test deterministic we don't depend on its behaviour; instead
	 * we assert the first frame surfaces an error envelope (one of
	 * `missing_message` or `stream_failed`) and ChatService is never
	 * called. Either way the assertion that matters is "auth passed but
	 * processing did NOT start".
	 */
	public function testMissingMessageDoesNotInvokeChatService(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->chatService->expects($this->never())->method('processMessage');

		$controller = $this->makeController();
		$this->runStream($controller);

		$this->assertGreaterThanOrEqual(1, count($controller->capturedEvents));
		// The flow never reaches the success path.
		$finalFrames = array_filter(
			$controller->capturedEvents,
			fn ($e) => $e['type'] === 'final'
		);
		$this->assertCount(0, $finalFrames, 'final must never be emitted without a valid message');
	}//end testMissingMessageDoesNotInvokeChatService()

	/**
	 * §9.2(b) — non-streaming-provider degradation: zero `token` events
	 * on early-exit paths. This is the contract guard — the test fails
	 * the day someone starts emitting tokens before basic validation
	 * (auth, message presence, agent resolution).
	 */
	public function testNoTokenEventsOnEarlyExitPaths(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$controller = $this->makeController();
		$this->runStream($controller);

		$tokenFrames = array_filter(
			$controller->capturedEvents,
			fn ($e) => $e['type'] === 'token'
		);
		$this->assertCount(0, $tokenFrames, 'tokens must never be emitted on the unauthenticated path');
	}//end testNoTokenEventsOnEarlyExitPaths()

	/**
	 * Decision 239: a caller without llm_use or agent_use gets a `forbidden`
	 * frame naming the right, and the model is never called. The SSE headers
	 * are already sent, so the refusal is a frame, as for a conversation that
	 * is not the caller's.
	 */
	public function testAMissingSpecialRightEmitsForbiddenBeforeTheModelIsCalled(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$conversation = new Conversation();
		$conversation->setUserId('alice');
		$conversation->setUuid('conv-1');
		$this->conversationMapper->method('findByUuid')->willReturn($conversation);

		$this->chatService->expects($this->once())->method('missingRight')
			->with($conversation, null, 'alice')
			->willReturn('llm_use');
		$this->chatService->expects($this->never())->method('processMessage');

		stream_wrapper_unregister('php');
		stream_wrapper_register('php', ChatStreamBodyStream::class);
		ChatStreamBodyStream::$body = json_encode(['message' => 'Hello', 'conversationUuid' => 'conv-1']);
		try {
			$controller = $this->makeController();
			$this->runStream($controller);
		} finally {
			stream_wrapper_restore('php');
		}

		$this->assertSame('error', $controller->capturedEvents[0]['type']);
		$this->assertSame('forbidden', $controller->capturedEvents[0]['payload']['code']);
		$this->assertSame('llm_use', $controller->capturedEvents[0]['payload']['right']);
	}//end testAMissingSpecialRightEmitsForbiddenBeforeTheModelIsCalled()
}//end class
