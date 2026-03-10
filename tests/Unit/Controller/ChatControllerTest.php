<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChatControllerTest extends TestCase
{
    private ChatController $controller;
    private IRequest&MockObject $request;
    private ChatService&MockObject $chatService;
    private ConversationMapper&MockObject $conversationMapper;
    private MessageMapper&MockObject $messageMapper;
    private FeedbackMapper&MockObject $feedbackMapper;
    private AgentMapper&MockObject $agentMapper;
    private OrganisationService&MockObject $organisationService;
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->chatService = $this->createMock(ChatService::class);
        $this->conversationMapper = $this->createMock(ConversationMapper::class);
        $this->messageMapper = $this->createMock(MessageMapper::class);
        $this->feedbackMapper = $this->createMock(FeedbackMapper::class);
        $this->agentMapper = $this->createMock(AgentMapper::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ChatController(
            'openregister',
            $this->request,
            $this->chatService,
            $this->conversationMapper,
            $this->messageMapper,
            $this->feedbackMapper,
            $this->agentMapper,
            $this->organisationService,
            $this->db,
            $this->logger,
            'testuser'
        );
    }

    public function testPage(): void
    {
        $result = $this->controller->page();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testSendMessageEmptyMessage(): void
    {
        $this->request->method('getParam')->willReturn('');

        $result = $this->controller->sendMessage();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Missing message', $data['error']);
    }

    public function testSendMessageMissingConversationAndAgent(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversation', null, ''],
                ['agentUuid', null, ''],
                ['message', null, 'Hello'],
                ['views', null, null],
                ['tools', null, null],
                ['includeObjects', null, true],
                ['includeFiles', null, true],
                ['numSourcesFiles', null, 5],
                ['numSourcesObjects', null, 5],
            ]);

        $result = $this->controller->sendMessage();

        $this->assertGreaterThanOrEqual(400, $result->getStatus());
    }

    public function testGetHistoryMissingConversationId(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversationId', null, 0],
                ['limit', null, 100],
                ['offset', null, 0],
            ]);

        $result = $this->controller->getHistory();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Missing conversationId', $data['error']);
    }

    public function testGetHistoryAccessDenied(): void
    {
        $conversation = new Conversation();
        $conversation->setUserId('otheruser');

        $this->request->method('getParam')
            ->willReturnMap([
                ['conversationId', null, 1],
                ['limit', null, 100],
                ['offset', null, 0],
            ]);
        $this->conversationMapper->method('find')->willReturn($conversation);

        $result = $this->controller->getHistory();

        $this->assertEquals(403, $result->getStatus());
    }

    public function testGetHistorySuccess(): void
    {
        $conversation = new Conversation();
        $conversation->setUserId('testuser');

        $message = new Message();

        $this->request->method('getParam')
            ->willReturnMap([
                ['conversationId', null, 1],
                ['limit', null, 100],
                ['offset', null, 0],
            ]);
        $this->conversationMapper->method('find')->willReturn($conversation);
        $this->messageMapper->method('findByConversation')->willReturn([$message]);
        $this->messageMapper->method('countByConversation')->willReturn(1);

        $result = $this->controller->getHistory();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('messages', $data);
        $this->assertEquals(1, $data['total']);
    }

    public function testClearHistoryMissingId(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversationId', null, 0],
            ]);

        $result = $this->controller->clearHistory();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testClearHistoryAccessDenied(): void
    {
        $conversation = new Conversation();
        $conversation->setUserId('otheruser');

        $this->request->method('getParam')
            ->willReturnMap([
                ['conversationId', null, 1],
            ]);
        $this->conversationMapper->method('find')->willReturn($conversation);

        $result = $this->controller->clearHistory();

        $this->assertEquals(403, $result->getStatus());
    }

    public function testClearHistorySuccess(): void
    {
        $conversation = new Conversation();
        $conversation->setUserId('testuser');

        $this->request->method('getParam')
            ->willReturnMap([
                ['conversationId', null, 1],
            ]);
        $this->conversationMapper->method('find')->willReturn($conversation);

        $result = $this->controller->clearHistory();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSendFeedbackInvalidType(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['type', null, 'invalid'],
                ['comment', '', ''],
            ]);

        $result = $this->controller->sendFeedback('conv-uuid', 1);

        $this->assertEquals(400, $result->getStatus());
    }

    public function testSendFeedbackAccessDenied(): void
    {
        $conversation = new Conversation();
        $conversation->setUserId('otheruser');

        $this->request->method('getParam')
            ->willReturnMap([
                ['type', null, 'positive'],
                ['comment', '', ''],
            ]);
        $this->conversationMapper->method('findByUuid')->willReturn($conversation);

        $result = $this->controller->sendFeedback('conv-uuid', 1);

        $this->assertEquals(403, $result->getStatus());
    }

    public function testGetChatStatsException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getChatStats();

        $this->assertEquals(500, $result->getStatus());
    }
}
