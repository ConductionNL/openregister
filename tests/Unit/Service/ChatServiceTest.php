<?php

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Service\Chat\ContextRetrievalHandler;
use OCA\OpenRegister\Service\Chat\ConversationManagementHandler;
use OCA\OpenRegister\Service\Chat\MessageHistoryHandler;
use OCA\OpenRegister\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Service\ChatService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class ChatServiceTest extends TestCase
{

    /**
     * @var ConversationMapper&MockObject
     */
    private ConversationMapper $conversationMapper;

    /**
     * @var MessageMapper&MockObject
     */
    private MessageMapper $messageMapper;

    /**
     * @var AgentMapper&MockObject
     */
    private AgentMapper $agentMapper;

    /**
     * @var ContextRetrievalHandler&MockObject
     */
    private ContextRetrievalHandler $contextHandler;

    /**
     * @var ResponseGenerationHandler&MockObject
     */
    private ResponseGenerationHandler $responseHandler;

    /**
     * @var ConversationManagementHandler&MockObject
     */
    private ConversationManagementHandler $conversationHandler;

    /**
     * @var MessageHistoryHandler&MockObject
     */
    private MessageHistoryHandler $historyHandler;

    /**
     * @var ToolManagementHandler&MockObject
     */
    private ToolManagementHandler $toolHandler;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    private ChatService $service;

    protected function setUp(): void
    {
        $this->conversationMapper = $this->createMock(ConversationMapper::class);
        $this->messageMapper = $this->createMock(MessageMapper::class);
        $this->agentMapper = $this->createMock(AgentMapper::class);
        $this->contextHandler = $this->createMock(ContextRetrievalHandler::class);
        $this->responseHandler = $this->createMock(ResponseGenerationHandler::class);
        $this->conversationHandler = $this->createMock(ConversationManagementHandler::class);
        $this->historyHandler = $this->createMock(MessageHistoryHandler::class);
        $this->toolHandler = $this->createMock(ToolManagementHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ChatService(
            $this->conversationMapper,
            $this->messageMapper,
            $this->agentMapper,
            $this->contextHandler,
            $this->responseHandler,
            $this->conversationHandler,
            $this->historyHandler,
            $this->toolHandler,
            $this->logger
        );
    }

    // --- processMessage ---

    public function testProcessMessageSuccess(): void
    {
        $conversation = new Conversation();
        $reflection = new \ReflectionClass($conversation);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($conversation, 1);
        $conversation->setUserId('user1');
        $conversation->setTitle('Existing Title');

        $this->conversationMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($conversation);

        $this->historyHandler
            ->expects($this->exactly(2))
            ->method('storeMessage');

        $this->conversationHandler
            ->expects($this->once())
            ->method('checkAndSummarize');

        $this->contextHandler
            ->expects($this->once())
            ->method('retrieveContext')
            ->willReturn(['sources' => [['title' => 'src1']], 'context' => 'some context']);

        $this->historyHandler
            ->expects($this->once())
            ->method('buildMessageHistory')
            ->willReturn([]);

        $this->responseHandler
            ->expects($this->once())
            ->method('generateResponse')
            ->willReturn('AI says hello');

        $this->messageMapper
            ->expects($this->once())
            ->method('countByConversation')
            ->with(1)
            ->willReturn(5);

        $result = $this->service->processMessage(1, 'user1', 'Hello');

        $this->assertSame('AI says hello', $result['message']);
        $this->assertCount(1, $result['sources']);
        $this->assertArrayHasKey('timings', $result);
        $this->assertArrayHasKey('context', $result['timings']);
        $this->assertArrayHasKey('llm', $result['timings']);
    }

    public function testProcessMessageDeniesAccessToOtherUserConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setUserId('otheruser');

        $this->conversationMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($conversation);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Access denied');

        $this->service->processMessage(1, 'user1', 'Hello');
    }

    public function testProcessMessageWithAgentConfigured(): void
    {
        $conversation = new Conversation();
        $reflection = new \ReflectionClass($conversation);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($conversation, 1);
        $conversation->setUserId('user1');
        $conversation->setAgentId(5);
        $conversation->setTitle('Existing Title');

        $agent = new Agent();

        $this->conversationMapper
            ->expects($this->once())
            ->method('find')
            ->willReturn($conversation);

        $this->agentMapper
            ->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($agent);

        $this->contextHandler
            ->method('retrieveContext')
            ->willReturn(['sources' => [], 'context' => '']);

        $this->historyHandler
            ->method('buildMessageHistory')
            ->willReturn([]);

        $this->responseHandler
            ->method('generateResponse')
            ->willReturn('Response');

        $this->messageMapper
            ->method('countByConversation')
            ->willReturn(5);

        $result = $this->service->processMessage(1, 'user1', 'Hello');

        $this->assertSame('Response', $result['message']);
    }

    public function testProcessMessageGeneratesTitleForNewConversation(): void
    {
        $conversation = new Conversation();
        $reflection = new \ReflectionClass($conversation);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($conversation, 1);
        $conversation->setUserId('user1');
        $conversation->setTitle('New Conversation');

        $this->conversationMapper
            ->expects($this->once())
            ->method('find')
            ->willReturn($conversation);

        $this->contextHandler
            ->method('retrieveContext')
            ->willReturn(['sources' => [], 'context' => '']);

        $this->historyHandler
            ->method('buildMessageHistory')
            ->willReturn([]);

        $this->responseHandler
            ->method('generateResponse')
            ->willReturn('Response');

        // messageCount = 2 and title starts with "New Conversation" -> should generate.
        $this->messageMapper
            ->expects($this->once())
            ->method('countByConversation')
            ->willReturn(2);

        $this->conversationHandler
            ->expects($this->once())
            ->method('generateConversationTitle')
            ->with('Hello')
            ->willReturn('Generated Title');

        $this->conversationMapper
            ->expects($this->once())
            ->method('update');

        $result = $this->service->processMessage(1, 'user1', 'Hello');

        $this->assertSame('Response', $result['message']);
    }

    public function testProcessMessageRethrowsExceptions(): void
    {
        $this->conversationMapper
            ->expects($this->once())
            ->method('find')
            ->willThrowException(new Exception('DB error'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DB error');

        $this->service->processMessage(1, 'user1', 'Hello');
    }

    // --- generateConversationTitle ---

    public function testGenerateConversationTitleDelegatesToHandler(): void
    {
        $this->conversationHandler
            ->expects($this->once())
            ->method('generateConversationTitle')
            ->with('My first message')
            ->willReturn('Summary Title');

        $result = $this->service->generateConversationTitle('My first message');

        $this->assertSame('Summary Title', $result);
    }

    // --- ensureUniqueTitle ---

    public function testEnsureUniqueTitleDelegatesToHandler(): void
    {
        $this->conversationHandler
            ->expects($this->once())
            ->method('ensureUniqueTitle')
            ->with('Title', 'user1', 5)
            ->willReturn('Title (2)');

        $result = $this->service->ensureUniqueTitle('Title', 'user1', 5);

        $this->assertSame('Title (2)', $result);
    }

    // --- testChat ---

    public function testTestChatReturnsSuccess(): void
    {
        $result = $this->service->testChat('openai', ['model' => 'gpt-4']);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testTestChatWithCustomMessage(): void
    {
        $result = $this->service->testChat('ollama', ['model' => 'llama2'], 'Custom test');

        $this->assertTrue($result['success']);
    }
}
