<?php

/**
 * Golden test pinning the access outcomes of ChatController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Mcp\Service\ChatMessageRequest;
use Exception;
use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Notifications\Db\MessageMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ChatControllerFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins allowed and refused outcomes of the ChatController actions: who may
 * send to, read, clear and rate a conversation, and what reaches the mappers.
 */
class ChatControllerAccessGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private ChatService&MockObject $chatService;

	private ConversationMapper&MockObject $conversationMapper;

	private MessageMapper&MockObject $messageMapper;

	private FeedbackMapper&MockObject $feedbackMapper;

	private AgentMapper&MockObject $agentMapper;

	private OrganisationService&MockObject $organisationService;

	private LoggerInterface&MockObject $logger;

	private ChatController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request             = $this->createMock(IRequest::class);
		$this->chatService         = $this->createMock(ChatService::class);
		$this->conversationMapper  = $this->createMock(ConversationMapper::class);
		$this->messageMapper       = $this->createMock(MessageMapper::class);
		$this->feedbackMapper      = $this->createMock(FeedbackMapper::class);
		$this->agentMapper         = $this->createMock(AgentMapper::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->logger              = $this->createMock(LoggerInterface::class);
		$l10n                      = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:'.$text);

		$this->controller = ChatControllerFactory::create(
			$this->request,
			$this->chatService,
			$this->conversationMapper,
			$this->messageMapper,
			$this->feedbackMapper,
			$this->agentMapper,
			$this->organisationService,
			$this->createMock(IDBConnection::class),
			$this->logger,
			$l10n,
			'alice'
		);
	}

	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);
	}

	private function conversation(string $owner): Conversation {
		$conversation = new Conversation();
		$conversation->setId(11);
		$conversation->setUuid('conv-uuid');
		$conversation->setUserId($owner);
		$conversation->setAgentId(3);
		return $conversation;
	}

	public function testSendMessageAllowedForOwnerPassesNormalisedParams(): void {
		$this->params(['conversation' => 'conv-uuid', 'message' => 'hi', 'views' => 'x', 'tools' => ['t'], 'context' => 'bad', 'numSourcesFiles' => 2]);
		$this->conversationMapper->method('findByUuid')->willReturn($this->conversation('alice'));
		$this->chatService->expects($this->once())->method('processMessage')
			->with($this->callback(
				fn (ChatMessageRequest $request): bool => [
					$request->conversationId,
					$request->userId,
					$request->userMessage,
					$request->selectedViews,
					$request->selectedTools,
					$request->ragSettings,
					$request->cnAiContext,
				] === [11, 'alice', 'hi', [], ['t'], ['includeObjects' => true, 'includeFiles' => true, 'numSourcesFiles' => 2, 'numSourcesObjects' => 5], []]
			))
			->willReturn(['reply' => 'ok']);

		$response = $this->controller->sendMessage();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['reply' => 'ok', 'conversation' => 'conv-uuid'], $response->getData());
	}

	public function testSendMessageRefusedForOtherUser(): void {
		$this->params(['conversation' => 'conv-uuid', 'message' => 'hi']);
		$this->conversationMapper->method('findByUuid')->willReturn($this->conversation('bob'));
		$this->chatService->expects($this->never())->method('processMessage');

		$response = $this->controller->sendMessage();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'T:Access denied', 'message' => 'You do not have access to this conversation'], $response->getData());
	}

	public function testSendMessageNewConversationIsOwnedByCallerInActiveOrganisation(): void {
		$this->params(['agentUuid' => 'agent-uuid', 'message' => 'hi']);
		$org = new Organisation();
		$org->setUuid('org-a');
		$this->organisationService->method('getActiveOrganisation')->willReturn($org);
		$agent = new Agent();
		$agent->setId(3);
		$this->agentMapper->method('findByUuid')->willReturn($agent);
		$this->chatService->method('ensureUniqueTitle')->willReturn('New Conversation 2');
		$this->conversationMapper->expects($this->once())->method('insert')
			->with($this->callback(
				fn (Conversation $c): bool => $c->getUserId() === 'alice' && $c->getOrganisation() === 'org-a'
					&& $c->getAgentId() === 3 && $c->getTitle() === 'New Conversation 2'
			))
			->willReturnCallback(function (Conversation $c): Conversation {
				$c->setId(12);
				$c->setUuid('new-uuid');
				return $c;
			});
		$this->chatService->method('processMessage')->willReturn([]);

		$response = $this->controller->sendMessage();

		$this->assertSame(['conversation' => 'new-uuid'], $response->getData());
	}

	public function testSendMessageErrorStatuses(): void {
		$this->params(['message' => 'hi']);
		$this->assertSame(
			['error' => 'T:Missing conversation or agentUuid', 'message' => 'Either conversation or agentUuid is required'],
			$this->controller->sendMessage()->getData()
		);
	}

	public function testSendMessageUnknownCodeBecomes500(): void {
		$this->params(['conversation' => 'c', 'message' => 'hi']);
		$this->conversationMapper->method('findByUuid')->willReturn($this->conversation('alice'));
		$this->chatService->method('processMessage')->willThrowException(new Exception('boom', 302));

		$response = $this->controller->sendMessage();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'T:Failed to process message', 'message' => 'boom'], $response->getData());
	}

	public function testSendMessageMissingConversationAndAgent404s(): void {
		$this->params(['conversation' => 'c', 'message' => 'hi']);
		$this->conversationMapper->method('findByUuid')->willThrowException(new Exception('db'));

		$response = $this->controller->sendMessage();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'T:Conversation not found', 'message' => 'The conversation with UUID c does not exist'], $response->getData());
	}

	public function testSendMessageEmpty(): void {
		$this->params(['message' => '']);
		$response = $this->controller->sendMessage();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'T:Missing message', 'message' => 'T:message content is required'], $response->getData());
	}

	public function testHistoryAllowedAndRefused(): void {
		$this->params(['conversationId' => '11', 'limit' => '2', 'offset' => '1']);
		$this->conversationMapper->method('find')->willReturnOnConsecutiveCalls($this->conversation('alice'), $this->conversation('bob'));
		$this->messageMapper->expects($this->once())->method('findByConversation')->with(11, new ListQuery(limit: 2, offset: 1))->willReturn([]);
		$this->messageMapper->method('countByConversation')->willReturn(0);

		$allowed = $this->controller->getHistory();
		$refused = $this->controller->getHistory();

		$this->assertSame(['messages' => [], 'total' => 0, 'conversationId' => 11], $allowed->getData());
		$this->assertSame(403, $refused->getStatus());
		$this->assertSame(['error' => 'T:Access denied', 'message' => 'T:You do not have access to this conversation'], $refused->getData());
	}

	public function testHistoryMissingIdAndFailure(): void {
		$this->params([]);
		$missing = $this->controller->getHistory();
		$this->assertSame(400, $missing->getStatus());
		$this->assertSame(['error' => 'T:Missing conversationId', 'message' => 'T:conversationId is required'], $missing->getData());
	}

	public function testHistoryFailure(): void {
		$this->params(['conversationId' => '11']);
		$this->conversationMapper->method('find')->willThrowException(new Exception('db'));
		$response = $this->controller->getHistory();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'T:Failed to fetch conversation history', 'message' => 'db'], $response->getData());
	}

	public function testClearRefusedNeverDeletes(): void {
		$this->params(['conversationId' => '11']);
		$this->conversationMapper->method('find')->willReturn($this->conversation('bob'));
		$this->conversationMapper->expects($this->never())->method('softDelete');

		$response = $this->controller->clearHistory();

		$this->assertSame(403, $response->getStatus());
	}

	public function testClearAllowedAndMissingAndFailure(): void {
		$this->params(['conversationId' => '11']);
		$this->conversationMapper->method('find')->willReturn($this->conversation('alice'));
		$this->conversationMapper->expects($this->once())->method('softDelete')->with(11);

		$response = $this->controller->clearHistory();

		$this->assertSame(['message' => 'T:Conversation cleared successfully', 'conversationId' => 11], $response->getData());
	}

	public function testClearMissingAndFailure(): void {
		$this->params(['conversationId' => '0']);
		$this->assertSame(400, $this->controller->clearHistory()->getStatus());
	}

	public function testClearFailure(): void {
		$this->params(['conversationId' => '11']);
		$this->conversationMapper->method('find')->willThrowException(new Exception('db'));
		$this->assertSame(['error' => 'T:Failed to clear conversation', 'message' => 'db'], $this->controller->clearHistory()->getData());
	}

	public function testFeedbackRefusedForOtherUserNeverWrites(): void {
		$this->params(['type' => 'positive']);
		$this->conversationMapper->method('findByUuid')->willReturn($this->conversation('bob'));
		$this->feedbackMapper->expects($this->never())->method('insert');
		$this->feedbackMapper->expects($this->never())->method('update');

		$response = $this->controller->sendFeedback('conv-uuid', 5);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'T:Access denied', 'message' => 'T:You do not have access to this conversation'], $response->getData());
	}
}
