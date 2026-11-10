<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Notifications\Db\Message;
use OCA\OpenRegister\Notifications\Db\MessageMapper;
use OCA\OpenRegister\Mcp\Service\Chat\ContextRetrievalHandler;
use OCA\OpenRegister\Mcp\Service\Chat\ConversationManagementHandler;
use OCA\OpenRegister\Mcp\Service\Chat\MessageHistoryHandler;
use OCA\OpenRegister\Mcp\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Mcp\Service\Chat\StreamYieldChannel;
use OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Mcp\Service\ChatService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Mcp\Service\Chat\ChatServiceStores;
use OCA\OpenRegister\Mcp\Service\Chat\ChatServiceHandlers;
use OCA\OpenRegister\Mcp\Service\ChatMessageRequest;
use OCA\OpenRegister\Mcp\Service\Chat\ChatReply;
use OCA\OpenRegister\Mcp\Service\Chat\ReplyGrounding;
use OCA\OpenRegister\Mcp\Service\Chat\TitleClaim;

/**
 * Records every collaborator call processMessage() makes, in order, and its result,
 * against fixtures/chat-process-message-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Mcp\Service\ChatService
 * @uses   \OCA\OpenRegister\Mcp\Service\ChatMessageRequest
 * @uses   \OCA\OpenRegister\Mcp\Db\Conversation
 * @uses   \OCA\OpenRegister\Notifications\Db\Message
 * @uses   \OCA\OpenRegister\Mcp\Db\Agent
 */
class ChatServiceProcessMessageGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service over recording doubles for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return ChatService
	 */
	private function service(array $s): ChatService {
		$conversation = new Conversation();
		$conversation->setUserId($s['owner']);
		$conversation->setAgentId($s['agentId']);
		$conversation->setTitle($s['title']);

		$conversationMapper = $this->createMock(ConversationMapper::class);
		$conversationMapper->method('find')->willReturnCallback(function (int $id) use ($conversation) {
			$this->calls[] = ['conversation.find', $id];
			return $conversation;
		});
		$conversationMapper->method('update')->willReturnCallback(function ($c) {
			$this->calls[] = ['conversation.update', $c->getTitle(), $c->getUpdated() instanceof DateTime];
			return $c;
		});

		$agentMapper = $this->createMock(AgentMapper::class);
		$agentMapper->method('find')->willReturnCallback(function (int $id) {
			$this->calls[] = ['agent.find', $id];
			$agent = new Agent();
			$agent->setName('agent-' . $id);
			return $agent;
		});

		$messageMapper = $this->createMock(MessageMapper::class);
		$messageMapper->method('countByConversation')->willReturnCallback(function (int $id) use ($s) {
			$this->calls[] = ['message.count', $id];
			return $s['count'];
		});

		$history = $this->createMock(MessageHistoryHandler::class);
		$history->method('storeUserMessage')->willReturnCallback(
			function (ChatMessageRequest $request) {
				$this->calls[] = ['history.store', $request->conversationId, Message::ROLE_USER, $request->userMessage, null, $request->cnAiContext];
				return new Message();
			}
		);
		$history->method('storeAssistantMessage')->willReturnCallback(
			function (int $conversationId, ChatReply $reply) use ($s) {
				$this->calls[] = ['history.store', $conversationId, Message::ROLE_ASSISTANT, $reply->content, $reply->sources, null];
				$message = new Message();
				if ($s['storedId'] !== null) {
					$message->setId($s['storedId']);
				}
				return $message;
			}
		);
		$history->method('buildMessageHistory')->willReturnCallback(function (int $id) {
			$this->calls[] = ['history.build', $id];
			return [['role' => 'user', 'content' => 'earlier']];
		});

		$conversationHandler = $this->createMock(ConversationManagementHandler::class);
		$conversationHandler->method('checkAndSummarize')->willReturnCallback(function ($c) {
			$this->calls[] = ['summarize', $c->getUserId()];
		});

		$response = $this->createMock(ResponseGenerationHandler::class);
		$response->lastUsage = ['tokens' => 7];
		$conversationHandler->method('generateConversationTitle')->willReturnCallback(function (string $m) use ($response) {
			$this->calls[] = ['title.generate', $m];
			$response->lastUsage = ['tokens' => 99];
			return 'Title for ' . $m;
		});
		$conversationHandler->method('ensureUniqueTitle')->willReturnCallback(function (TitleClaim $claim) {
			$this->calls[] = ['title.unique', $claim->baseTitle, $claim->userId, $claim->agentId];
			return $claim->baseTitle . ' (2)';
		});

		$contextHandler = $this->createMock(ContextRetrievalHandler::class);
		$contextHandler->method('retrieveContext')->willReturnCallback(
			function (ChatMessageRequest $request, ?Agent $agent) use ($s) {
				$this->calls[] = ['context', $request->userMessage, $agent?->getName(), $request->selectedViews, $request->ragSettings];
				if ($s['fail'] === true) {
					throw new Exception('context down');
				}
				return ['text' => 'ctx', 'sources' => [['id' => 's1']]];
			}
		);
		$response->method('generateResponse')->willReturnCallback(
			function (ChatMessageRequest $request, ReplyGrounding $grounding) {
				$this->calls[] = ['llm', $request->userMessage, $grounding->context, $grounding->messageHistory, $grounding->agent?->getName(), $request->selectedTools, $request->channel !== null, $request->cnAiContext];
				return 'answer to ' . $request->userMessage;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function ($message, array $context = []) {
			unset($context['line'], $context['file']);
			$this->calls[] = ['log.info', $message, $context];
		});
		$logger->method('error')->willReturnCallback(function ($message, array $context = []) {
			unset($context['line'], $context['file']);
			$this->calls[] = ['log.error', $message, $context];
		});

		return new ChatService(
			new ChatServiceStores(
				$conversationMapper,
				$messageMapper,
				$agentMapper,
			),
			new ChatServiceHandlers(
				$contextHandler,
				$response,
				$conversationHandler,
				$history,
			),
			$logger,
		);
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$base = ['owner' => 'u1', 'agentId' => null, 'title' => null, 'count' => 2, 'storedId' => 41, 'fail' => false, 'full' => false];
		return [
			'minimal new conversation' => [$base],
			'agent and unique title' => [['agentId' => 5] + $base],
			'title starting New Conversation' => [['title' => 'New Conversation 3'] + $base],
			'existing title' => [['title' => 'Kept', 'agentId' => 5] + $base],
			'third message' => [['count' => 3] + $base],
			'no stored id' => [['storedId' => null] + $base],
			'all options' => [['full' => true, 'agentId' => 8, 'title' => 'x New Conversation'] + $base],
			'other owner' => [['owner' => 'u2'] + $base],
			'context failure' => [['fail' => true] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testProcessMessageGolden(array $s): void {
		$service = $this->service($s);
		$request = new ChatMessageRequest(7, 'u1', 'Hi there');
		if ($s['full'] === true) {
			$request->selectedViews = ['v1'];
			$request->selectedTools = ['t1'];
			$request->ragSettings = ['k' => 1];
			$request->cnAiContext = ['app' => 'dossiq'];
			$request->channel = new StreamYieldChannel();
		}

		try {
			$result = $service->processMessage($request);
			foreach ($result['timings'] as $key => $value) {
				$this->assertMatchesRegularExpression('/^[0-9.E-]+s$/', $value, $key);
			}
			$this->assertSame(['context', 'history', 'llm', 'total'], array_keys($result['timings']));
			unset($result['timings']);
			$this->calls[] = ['result', $result];
		} catch (Exception $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage()];
		}

		$file = __DIR__ . '/fixtures/chat-process-message-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $this->calls;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
		$this->assertSame($all[$name], json_decode(json_encode($this->calls), true));
	}
}
