<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ConversationController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConversationControllerTest extends TestCase
{
    private ConversationController $controller;
    private IRequest&MockObject $request;
    private ConversationMapper&MockObject $conversationMapper;
    private MessageMapper&MockObject $messageMapper;
    private FeedbackMapper&MockObject $feedbackMapper;
    private AgentMapper&MockObject $agentMapper;
    private OrganisationService&MockObject $organisationService;
    private ChatService&MockObject $chatService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->conversationMapper = $this->createMock(ConversationMapper::class);
        $this->messageMapper = $this->createMock(MessageMapper::class);
        $this->feedbackMapper = $this->createMock(FeedbackMapper::class);
        $this->agentMapper = $this->createMock(AgentMapper::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->chatService = $this->createMock(ChatService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ConversationController(
            'openregister',
            $this->request,
            $this->conversationMapper,
            $this->messageMapper,
            $this->feedbackMapper,
            $this->agentMapper,
            $this->organisationService,
            $this->chatService,
            $this->logger,
            'testuser'
        );
    }

    public function testIndexSuccess(): void
    {
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->request->method('getParams')->willReturn([]);

        $conv = new Conversation();
        $this->conversationMapper->method('findByUser')->willReturn([$conv]);
        $this->conversationMapper->method('countByUser')->willReturn(1);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals(1, $data['total']);
    }

    public function testIndexException(): void
    {
        $this->organisationService->method('getActiveOrganisation')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testShowSuccess(): void
    {
        $conv = new Conversation();
        $conv->setId(1);
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserAccessConversation')->willReturn(true);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->messageMapper->method('countByConversation')->willReturn(5);

        $result = $this->controller->show('uuid-123');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowAccessDenied(): void
    {
        $conv = new Conversation();
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserAccessConversation')->willReturn(false);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $result = $this->controller->show('uuid-123');

        $this->assertEquals(403, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show('bad-uuid');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testMessagesSuccess(): void
    {
        $conv = new Conversation();
        $conv->setId(1);
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserAccessConversation')->willReturn(true);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $msg = new Message();
        $this->messageMapper->method('findByConversation')->willReturn([$msg]);
        $this->messageMapper->method('countByConversation')->willReturn(1);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->messages('uuid-123');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testMessagesAccessDenied(): void
    {
        $conv = new Conversation();
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserAccessConversation')->willReturn(false);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $result = $this->controller->messages('uuid-123');

        $this->assertEquals(403, $result->getStatus());
    }

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test Conv']);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $conv = new Conversation();
        $conv->setUuid('new-uuid');
        $this->conversationMapper->method('insert')->willReturn($conv);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->organisationService->method('getActiveOrganisation')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->create();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateSuccess(): void
    {
        $conv = new Conversation();
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(true);
        $this->conversationMapper->method('update')->willReturn($conv);
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);

        $result = $this->controller->update('uuid-123');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateAccessDenied(): void
    {
        $conv = new Conversation();
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(false);

        $result = $this->controller->update('uuid-123');

        $this->assertEquals(403, $result->getStatus());
    }

    public function testUpdateNotFound(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->update('bad-uuid');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroySoftDelete(): void
    {
        $conv = new Conversation();
        $conv->setDeletedAt(null);
        $conv->setId(1);
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(true);

        $result = $this->controller->destroy('uuid-123');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['archived']);
    }

    public function testDestroyPermanentWhenAlreadyArchived(): void
    {
        $conv = new Conversation();
        $conv->setDeletedAt(new \DateTime('2024-01-01'));
        $conv->setId(1);
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(true);

        $result = $this->controller->destroy('uuid-123');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('permanently deleted', $data['message']);
    }

    public function testDestroyAccessDenied(): void
    {
        $conv = new Conversation();
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(false);

        $result = $this->controller->destroy('uuid-123');

        $this->assertEquals(403, $result->getStatus());
    }

    public function testRestoreSuccess(): void
    {
        $conv = new Conversation();
        $conv->setId(1);
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(true);
        $this->conversationMapper->method('restore')->willReturn($conv);

        $result = $this->controller->restore('uuid-123');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testRestoreNotFound(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->restore('bad-uuid');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroyPermanentSuccess(): void
    {
        $conv = new Conversation();
        $conv->setId(1);
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(true);

        $result = $this->controller->destroyPermanent('uuid-123');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDestroyPermanentAccessDenied(): void
    {
        $conv = new Conversation();
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(false);

        $result = $this->controller->destroyPermanent('uuid-123');

        $this->assertEquals(403, $result->getStatus());
    }
}
