<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,PSR1.Classes.ClassDeclaration.MultipleClasses -- test doubles live beside the test.

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Controller\ChatStreamController;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\Chat\StreamYieldChannel;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ChatStreamControllerFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Serves a fixed request body on php://input.
 */
class ChatStreamGoldenInput {
	/** @var string The body php://input returns. */
	public static string $body = '';

	/** @var resource|null Set by PHP for stream wrappers. */
	public $context;

	private int $position = 0;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return $path === 'php://input';
	}

	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}

	/** @return array<int|string, int> */
	public function stream_stat(): array {
		return [];
	}
}

/**
 * Thrown in place of exit.
 */
class ChatStreamGoldenStop extends RuntimeException {
}

/**
 * Captures frames instead of writing them and throws instead of exiting.
 */
class GoldenChatStreamController extends ChatStreamController {
	/** @var list<array<string, mixed>> */
	public array $frames = [];

	protected function emitSseEvent(string $eventType, array $payload): void {
		if (isset($payload['ts']) === true) {
			$payload['ts'] = 'TS';
		}

		$this->frames[] = ['type' => $eventType, 'payload' => $payload];
	}

	protected function emitAndExit(string $eventType, array $payload): never {
		$this->emitSseEvent(eventType: $eventType, payload: $payload);
		throw new ChatStreamGoldenStop('exit ' . $eventType);
	}

	protected function now(): float {
		return 1000.0;
	}

	protected function clearOutputBuffers(): void {
	}

	protected function emitSseHeaders(): void {
	}
}

/**
 * Golden test for ChatStreamController::stream().
 *
 * Each case serves a request body on php://input, records the SSE frames,
 * the collaborator calls, the log lines and the exception that leaves
 * stream(), and compares the recording with
 * fixtures/chat-stream-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Controller\ChatStreamController
 * @uses \OCA\OpenRegister\Mcp\Service\Chat\StreamYieldChannel
 * @uses \OCA\OpenRegister\Mcp\Db\Conversation
 * @uses \OCA\OpenRegister\Mcp\Db\Agent
 * @uses \OCA\OpenRegister\Controller\Support\ChatStreamChat
 * @uses \OCA\OpenRegister\Controller\Support\ChatStreamControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\ChatStreamPlatform
 */
class ChatStreamControllerStreamGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/chat-stream-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	protected function setUp(): void {
		parent::setUp();
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', ChatStreamGoldenInput::class);
	}

	protected function tearDown(): void {
		stream_wrapper_restore('php');
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$own = ['conversation' => ['uuid' => 'c-1', 'userId' => 'alice']];
		return [
			'no user' => [['user' => null, 'body' => '{"message":"hi"}']],
			'empty body' => [['body' => '']],
			'invalid json' => [['body' => '{nope']],
			'blank message' => [['body' => '{"message":"   "}']],
			'non-string message' => [['body' => '{"message":42,"agentUuid":"a-1"}', 'result' => ['response' => 'ok']]],
			'fallback picks second accessible agent' => [[
				'body' => '{"message":" hello "}',
				'agents' => [['uuid' => 'a-x', 'access' => false], ['uuid' => 'a-y', 'access' => true]],
				'result' => ['messageId' => 7, 'response' => 'answer'],
			]],
			'fallback finds none' => [['body' => '{"message":"hi"}', 'agents' => [['uuid' => 'a-x', 'access' => false]]]],
			'fallback lookup throws' => [['body' => '{"message":"hi"}', 'agentsThrow' => true]],
			'explicit agent creates conversation' => [[
				'body' => '{"message":"hi","agentUuid":"a-2","context":{"page":"x"}}',
				'result' => ['message' => 'from message key'],
			]],
			'explicit agent missing' => [['body' => '{"message":"hi","agentUuid":"a-gone"}', 'agentMissing' => true]],
			'own conversation with scalar context' => [[
				'body' => '{"message":"hi","conversationUuid":"c-1","context":"flat"}',
				'result' => ['response' => 'r', 'message' => 'm'],
			] + $own],
			'own conversation with list context' => [[
				'body' => '{"message":"hi","conversationUuid":"c-1","context":[1,2]}',
				'result' => [],
			] + $own],
			'foreign conversation' => [[
				'body' => '{"message":"hi","conversationUuid":"c-1"}',
				'conversation' => ['uuid' => 'c-1', 'userId' => 'mallory'],
			]],
			'conversation lookup fails' => [['body' => '{"message":"hi","conversationUuid":"c-9"}', 'conversationThrow' => 'not found']],
			'processing fails' => [['body' => '{"message":"hi","agentUuid":"a-2"}', 'processThrow' => true] + $own],
			'channel streams frames' => [[
				'body' => '{"message":"hi","agentUuid":"a-2"}',
				'stream' => true,
				'result' => ['messageId' => 'm-3', 'response' => 'done'],
			]],
		];
	}

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testStream(array $case): void {
		ChatStreamGoldenInput::$body = $case['body'];
		$controller = $this->controller(case: $case);
		$escaped = null;
		try {
			$controller->stream();
		} catch (Throwable $e) {
			$escaped = get_class($e) . ': ' . $e->getMessage();
		}

		$this->compare(
			key: (string)$this->dataName(),
			actual: ['frames' => $controller->frames, 'calls' => $this->calls, 'escaped' => $escaped]
		);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function controller(array $case): GoldenChatStreamController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (array_key_exists('user', $case) === false) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = ['log' => $level, 'message' => $message, 'error' => $context['error'] ?? null];
				}
			);
		}

		return new GoldenChatStreamController(...ChatStreamControllerFactory::args(
			request: $this->createMock(IRequest::class),
			chatService: $this->chatService(case: $case),
			conversationMapper: $this->conversationMapper(case: $case),
			agentMapper: $this->agentMapper(case: $case),
			logger: $logger,
			userSession: $session,
			db: $this->createMock(IDBConnection::class)
		));
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function chatService(array $case): ChatService {
		$service = $this->createMock(ChatService::class);
		$service->method('processMessage')->willReturnCallback(
			function (...$args) use ($case): array {
				$channel = array_pop($args);
				$this->calls[] = ['processMessage' => $args, 'channel' => $channel instanceof StreamYieldChannel];
				if (($case['processThrow'] ?? false) === true) {
					throw new RuntimeException('llm down');
				}

				if (($case['stream'] ?? false) === true) {
					$channel->emitToken('Hel');
					$channel->emitToolCall(['name' => 'search']);
					$channel->emitToolResult(['name' => 'search', 'ok' => true]);
				}

				return $case['result'] ?? [];
			}
		);
		return $service;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function conversationMapper(array $case): ConversationMapper {
		$mapper = $this->createMock(ConversationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid) use ($case): Conversation {
				$this->calls[] = ['findConversation' => $uuid];
				if (isset($case['conversationThrow']) === true) {
					throw new RuntimeException($case['conversationThrow']);
				}

				$conversation = new Conversation();
				$conversation->setId(11);
				$conversation->setUuid($case['conversation']['uuid']);
				$conversation->setUserId($case['conversation']['userId']);
				return $conversation;
			}
		);
		$mapper->method('insert')->willReturnCallback(
			function (Conversation $conversation): Conversation {
				$this->calls[] = ['insert' => [$conversation->getUserId(), $conversation->getAgentId(), $conversation->getTitle()]];
				$conversation->setId(12);
				$conversation->setUuid('c-new');
				return $conversation;
			}
		);
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function agentMapper(array $case): AgentMapper {
		$mapper = $this->createMock(AgentMapper::class);
		$agents = [];
		foreach ($case['agents'] ?? [] as $row) {
			$agent = new Agent();
			$agent->setUuid($row['uuid']);
			$agents[] = $agent;
		}

		$mapper->method('findAll')->willReturnCallback(
			function (ListQuery $query) use ($case, $agents): array {
				$this->calls[] = ['findAllAgents' => [$query->limitOrNull(), $query->offset, $query->filters, $query->sort]];
				if (($case['agentsThrow'] ?? false) === true) {
					throw new RuntimeException('agents table gone');
				}

				return $agents;
			}
		);
		$mapper->method('canUserAccessAgent')->willReturnCallback(
			function (Agent $agent, string $userId) use ($case): bool {
				$this->calls[] = ['canAccess' => [$agent->getUuid(), $userId]];
				foreach ($case['agents'] as $row) {
					if ($row['uuid'] === $agent->getUuid()) {
						return $row['access'];
					}
				}

				return false;
			}
		);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid) use ($case): Agent {
				$this->calls[] = ['findAgent' => $uuid];
				if (($case['agentMissing'] ?? false) === true) {
					throw new RuntimeException('agent not found');
				}

				$agent = new Agent();
				$agent->setId(5);
				$agent->setUuid($uuid);
				return $agent;
			}
		);
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
