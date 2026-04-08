<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Db\Agent;
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
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
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
            $this->createMock(\OCP\IL10N::class),
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

    // ── sendMessage additional paths ──

    public function testSendMessageException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversation', null, 'conv-uuid'],
                ['agentUuid', null, 'agent-uuid'],
                ['message', null, 'Hello'],
                ['views', null, null],
                ['tools', null, null],
                ['includeObjects', null, true],
                ['includeFiles', null, true],
                ['numSourcesFiles', null, 5],
                ['numSourcesObjects', null, 5],
            ]);

        $conv = new Conversation();
        $conv->setId(1);
        $conv->setUserId('testuser');
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->chatService->method('processMessage')
            ->willThrowException(new \Exception('AI error'));

        $result = $this->controller->sendMessage();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testSendMessageAccessDeniedException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversation', null, 'conv-uuid'],
                ['agentUuid', null, ''],
                ['message', null, 'Hello'],
                ['views', null, null],
                ['tools', null, null],
                ['includeObjects', null, true],
                ['includeFiles', null, true],
                ['numSourcesFiles', null, 5],
                ['numSourcesObjects', null, 5],
            ]);

        $conv = new Conversation();
        $conv->setId(1);
        $conv->setUserId('otheruser');
        $this->conversationMapper->method('findByUuid')->willReturn($conv);

        $result = $this->controller->sendMessage();

        $this->assertEquals(403, $result->getStatus());
    }

    // ── getHistory additional paths ──

    public function testGetHistoryException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversationId', null, 1],
                ['limit', null, 100],
                ['offset', null, 0],
            ]);
        $this->conversationMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getHistory();

        $this->assertEquals(500, $result->getStatus());
    }

    // ── clearHistory additional paths ──

    public function testClearHistoryException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversationId', null, 1],
            ]);
        $this->conversationMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->clearHistory();

        $this->assertEquals(500, $result->getStatus());
    }

    // ── sendFeedback additional paths ──

    public function testSendFeedbackSuccess(): void
    {
        $conversation = new Conversation();
        $conversation->setId(1);
        $conversation->setUserId('testuser');

        $message = new Message();
        $message->setConversationId(1);

        $feedback = new \OCA\OpenRegister\Db\Feedback();
        $feedback->setId(1);

        $this->request->method('getParam')
            ->willReturnMap([
                ['type', null, 'positive'],
                ['comment', '', 'Great answer!'],
            ]);
        $this->conversationMapper->method('findByUuid')->willReturn($conversation);
        $this->messageMapper->method('find')->willReturn($message);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->feedbackMapper->method('findByMessage')->willReturn(null);
        $this->feedbackMapper->method('insert')->willReturn($feedback);

        $result = $this->controller->sendFeedback('conv-uuid', 1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSendFeedbackUpdateExisting(): void
    {
        $conversation = new Conversation();
        $conversation->setId(1);
        $conversation->setUserId('testuser');

        $message = new Message();
        $message->setConversationId(1);

        $existingFeedback = new \OCA\OpenRegister\Db\Feedback();
        $existingFeedback->setId(1);
        $existingFeedback->setType('negative');

        $this->request->method('getParam')
            ->willReturnMap([
                ['type', null, 'positive'],
                ['comment', '', 'Updated comment'],
            ]);
        $this->conversationMapper->method('findByUuid')->willReturn($conversation);
        $this->messageMapper->method('find')->willReturn($message);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->feedbackMapper->method('findByMessage')->willReturn($existingFeedback);
        $this->feedbackMapper->method('update')->willReturn($existingFeedback);

        $result = $this->controller->sendFeedback('conv-uuid', 1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSendFeedbackMessageNotInConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setId(1);
        $conversation->setUserId('testuser');

        $message = new Message();
        $message->setConversationId(999);

        $this->request->method('getParam')
            ->willReturnMap([
                ['type', null, 'positive'],
                ['comment', '', ''],
            ]);
        $this->conversationMapper->method('findByUuid')->willReturn($conversation);
        $this->messageMapper->method('find')->willReturn($message);

        $result = $this->controller->sendFeedback('conv-uuid', 1);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testSendFeedbackException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['type', null, 'positive'],
                ['comment', '', ''],
            ]);
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->sendFeedback('conv-uuid', 1);

        $this->assertEquals(500, $result->getStatus());
    }

    // ── getChatStats success path ──

    public function testGetChatStatsSuccess(): void
    {
        // Build a mock result that returns a scalar count for fetchOne().
        $resultMock = $this->createMock(IResult::class);
        $resultMock->method('fetchOne')->willReturnOnConsecutiveCalls('3', '7', '42');

        // The func() helper must return something that can be passed to select().
        $funcMock = $this->createMock(IQueryFunction::class);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('func')->willReturn(
            new class($funcMock) {
                private $f;
                public function __construct($f) { $this->f = $f; }
                public function count(string $col, string $alias): mixed { return $this->f; }
            }
        );
        $qb->method('executeQuery')->willReturn($resultMock);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->controller->getChatStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('total_agents', $data);
        $this->assertArrayHasKey('total_conversations', $data);
        $this->assertArrayHasKey('total_messages', $data);
    }

    // ── sendMessage — new conversation via agentUuid ──

    public function testSendMessageCreatesNewConversation(): void
    {
        $agent = new Agent();
        $agent->setId(1);

        $conv = new Conversation();
        $conv->setId(5);
        $conv->setUserId('testuser');

        $this->request->method('getParam')
            ->willReturnMap([
                ['conversation', null, ''],
                ['agentUuid', null, 'agent-uuid-123'],
                ['message', null, 'Hello agent'],
                ['views', null, []],
                ['tools', null, []],
                ['includeObjects', null, true],
                ['includeFiles', null, true],
                ['numSourcesFiles', null, 5],
                ['numSourcesObjects', null, 5],
            ]);

        $this->agentMapper->method('findByUuid')->willReturn($agent);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->chatService->method('ensureUniqueTitle')->willReturn('New Conversation');
        $this->conversationMapper->method('insert')->willReturn($conv);
        $this->chatService->method('processMessage')->willReturn(['response' => 'Hi']);

        $result = $this->controller->sendMessage();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('conversation', $data);
    }

    // ── sendMessage — agentUuid not found ──

    public function testSendMessageAgentNotFound(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversation', null, ''],
                ['agentUuid', null, 'nonexistent-agent'],
                ['message', null, 'Hello'],
                ['views', null, null],
                ['tools', null, null],
                ['includeObjects', null, true],
                ['includeFiles', null, true],
                ['numSourcesFiles', null, 5],
                ['numSourcesObjects', null, 5],
            ]);

        $this->agentMapper->method('findByUuid')
            ->willThrowException(new \Exception('Agent not found'));

        $result = $this->controller->sendMessage();

        $this->assertEquals(404, $result->getStatus());
    }

    // ── sendMessage — conversation not found by UUID ──

    public function testSendMessageConversationNotFound(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['conversation', null, 'missing-uuid'],
                ['agentUuid', null, ''],
                ['message', null, 'Hello'],
                ['views', null, null],
                ['tools', null, null],
                ['includeObjects', null, true],
                ['includeFiles', null, true],
                ['numSourcesFiles', null, 5],
                ['numSourcesObjects', null, 5],
            ]);

        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->controller->sendMessage();

        $this->assertEquals(404, $result->getStatus());
    }

    // ── sendMessage — selectedViews and selectedTools as arrays ──

    public function testSendMessageWithViewsAndTools(): void
    {
        $conv = new Conversation();
        $conv->setId(1);
        $conv->setUserId('testuser');

        $this->request->method('getParam')
            ->willReturnMap([
                ['conversation', null, 'conv-uuid'],
                ['agentUuid', null, ''],
                ['message', null, 'Query with views'],
                ['views', null, ['view1', 'view2']],
                ['tools', null, ['tool1']],
                ['includeObjects', null, true],
                ['includeFiles', null, false],
                ['numSourcesFiles', null, 3],
                ['numSourcesObjects', null, 3],
            ]);

        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->chatService->method('processMessage')->willReturn(['response' => 'Done']);

        $result = $this->controller->sendMessage();

        $this->assertEquals(200, $result->getStatus());
    }
}
