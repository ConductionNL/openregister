<?php

/**
 * Pins how ChatStreamController resolves the conversation of a turn.
 *
 * A caller who owns the conversation gets it back, a caller who does not is
 * stopped with a forbidden frame, and a turn without a conversation starts a
 * new one owned by the caller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ai-chat-companion-orchestrator/tasks.md#9
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ChatStreamController;
use OCA\OpenRegister\Controller\Support\ChatStreamTurn;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ChatStreamControllerFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Stops the stream with an exception instead of exit.
 */
class OwnershipStopSignal extends RuntimeException {
}//end class

/**
 * Captures the frame that would stop the stream.
 */
class OwnershipChatStreamController extends ChatStreamController {

	/**
	 * @var array<int, array{type: string, payload: array}>
	 */
	public array $capturedEvents = [];

	protected function emitAndExit(string $eventType, array $payload): never {
		$this->capturedEvents[] = ['type' => $eventType, 'payload' => $payload];
		throw new OwnershipStopSignal($eventType);
	}//end emitAndExit()
}//end class

class ChatStreamControllerConversationOwnershipTest extends TestCase {

	/**
	 * @var ConversationMapper&MockObject
	 */
	private ConversationMapper $conversationMapper;

	/**
	 * @var AgentMapper&MockObject
	 */
	private AgentMapper $agentMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->conversationMapper = $this->createMock(ConversationMapper::class);
		$this->agentMapper        = $this->createMock(AgentMapper::class);
	}//end setUp()

	public function testTheOwnerGetsTheConversation(): void {
		$conversation = $this->conversationOwnedBy('alice');
		$this->conversationMapper->method('findByUuid')->willReturn($conversation);
		$this->conversationMapper->expects($this->never())->method('insert');

		$controller = $this->controller();
		$result     = $this->turnConversation($controller, $this->turn('alice', 'conv-1', ''));

		$this->assertSame($conversation, $result);
		$this->assertSame([], $controller->capturedEvents);
	}//end testTheOwnerGetsTheConversation()

	public function testAnotherUserIsStoppedWithForbidden(): void {
		$this->conversationMapper->method('findByUuid')->willReturn($this->conversationOwnedBy('alice'));
		$this->conversationMapper->expects($this->never())->method('insert');

		$controller = $this->controller();
		try {
			$this->turnConversation($controller, $this->turn('mallory', 'conv-1', ''));
			$this->fail('A conversation of another user must stop the stream.');
		} catch (OwnershipStopSignal $e) {
			$this->assertSame('error', $e->getMessage());
		}

		$this->assertSame(
			[['type' => 'error', 'payload' => ['code' => 'forbidden', 'message' => 'Forbidden']]],
			$controller->capturedEvents
		);
	}//end testAnotherUserIsStoppedWithForbidden()

	public function testAnEmptyOwnerIsRefusedForANamedUser(): void {
		$this->conversationMapper->method('findByUuid')->willReturn($this->conversationOwnedBy(null));

		$this->expectException(OwnershipStopSignal::class);
		$this->turnConversation($this->controller(), $this->turn('alice', 'conv-1', ''));
	}//end testAnEmptyOwnerIsRefusedForANamedUser()

	public function testATurnWithoutConversationStartsOneOwnedByTheCaller(): void {
		$agent = new Agent();
		$agent->setId(7);
		$this->agentMapper->expects($this->once())->method('findByUuid')->with('agent-1')->willReturn($agent);
		$this->conversationMapper->expects($this->never())->method('findByUuid');
		$this->conversationMapper->expects($this->once())->method('insert')->willReturnCallback(
			static fn (Conversation $conversation): Conversation => $conversation
		);

		$result = $this->turnConversation($this->controller(), $this->turn('alice', '', 'agent-1'));

		$this->assertSame('alice', $result->getUserId());
		$this->assertSame(7, $result->getAgentId());
		$this->assertSame('New conversation', $result->getTitle());
	}//end testATurnWithoutConversationStartsOneOwnedByTheCaller()

	private function controller(): OwnershipChatStreamController {
		return new OwnershipChatStreamController(...ChatStreamControllerFactory::args(
			request: $this->createMock(IRequest::class),
			chatService: $this->createMock(ChatService::class),
			conversationMapper: $this->conversationMapper,
			agentMapper: $this->agentMapper,
			logger: $this->createMock(LoggerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			db: $this->createMock(IDBConnection::class)
		));
	}//end controller()

	private function turnConversation(ChatStreamController $controller, ChatStreamTurn $turn): Conversation {
		$method = new ReflectionMethod(ChatStreamController::class, 'turnConversation');

		return $method->invoke($controller, $turn);
	}//end turnConversation()

	private function turn(string $userId, string $conversationUuid, string $agentUuid): ChatStreamTurn {
		$turn                   = new ChatStreamTurn();
		$turn->userId           = $userId;
		$turn->conversationUuid = $conversationUuid;
		$turn->agentUuid        = $agentUuid;

		return $turn;
	}//end turn()

	private function conversationOwnedBy(?string $userId): Conversation {
		$conversation = new Conversation();
		$conversation->setUserId($userId);

		return $conversation;
	}//end conversationOwnedBy()
}//end class
