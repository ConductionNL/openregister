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

    // ── Index with deleted filter ──

    public function testIndexWithDeletedFilter(): void
    {
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->request->method('getParams')->willReturn(['_deleted' => 'true']);

        $conv = new Conversation();
        $this->conversationMapper->method('findDeletedByUser')->willReturn([$conv]);
        $this->conversationMapper->method('countDeletedByUser')->willReturn(1);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(1, $data['total']);
    }

    public function testIndexWithPagination(): void
    {
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->request->method('getParams')->willReturn(['limit' => '10', 'offset' => '5']);
        $this->conversationMapper->method('findByUser')->willReturn([]);
        $this->conversationMapper->method('countByUser')->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(10, $data['limit']);
        $this->assertEquals(5, $data['offset']);
    }

    // ── Show error paths ──

    public function testShowReturns500OnException(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->show('uuid-123');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to fetch conversation', $data['error']);
    }

    // ── Messages error paths ──

    public function testMessagesNotFound(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->messages('bad-uuid');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testMessagesReturns500OnException(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->messages('uuid-123');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to fetch messages', $data['error']);
    }

    public function testMessagesWithPagination(): void
    {
        $conv = new Conversation();
        $conv->setId(1);
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserAccessConversation')->willReturn(true);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->messageMapper->method('findByConversation')->willReturn([]);
        $this->messageMapper->method('countByConversation')->willReturn(0);
        $this->request->method('getParams')->willReturn(['_limit' => '25', '_offset' => '10']);

        $result = $this->controller->messages('uuid-123');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(25, $data['limit']);
        $this->assertEquals(10, $data['offset']);
    }

    // ── Create with agentUuid ──

    public function testCreateWithAgentUuid(): void
    {
        $agent = new \OCA\OpenRegister\Db\Agent();
        $agent->setId(42);

        $this->request->method('getParams')->willReturn(['agentUuid' => 'agent-uuid-1']);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->agentMapper->method('findByUuid')->willReturn($agent);
        $this->chatService->method('ensureUniqueTitle')->willReturn('New Conversation');

        $conv = new Conversation();
        $conv->setUuid('new-uuid');
        $this->conversationMapper->method('insert')->willReturn($conv);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateWithAgentUuidNotFound(): void
    {
        $this->request->method('getParams')->willReturn(['agentUuid' => 'bad-uuid']);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->agentMapper->method('findByUuid')
            ->willThrowException(new \Exception('Not found'));

        $conv = new Conversation();
        $conv->setUuid('new-uuid');
        $this->conversationMapper->method('insert')->willReturn($conv);

        // Should still create the conversation (agentId = null)
        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateWithAgentIdAutoTitle(): void
    {
        $this->request->method('getParams')->willReturn(['agentId' => 5]);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->chatService->method('ensureUniqueTitle')->willReturn('New Conversation (2)');

        $conv = new Conversation();
        $conv->setUuid('new-uuid');
        $this->conversationMapper->method('insert')->willReturn($conv);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    // ── Update edge cases ──

    public function testUpdateWithMetadata(): void
    {
        $conv = new Conversation();
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(true);
        $this->conversationMapper->method('update')->willReturn($conv);
        $this->request->method('getParams')->willReturn([
            'title' => 'New Title',
            'metadata' => ['key' => 'value'],
        ]);

        $result = $this->controller->update('uuid-123');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateReturns500OnException(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->update('uuid-123');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to update conversation', $data['error']);
    }

    // ── Destroy error paths ──

    public function testDestroyNotFound(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy('bad-uuid');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroyReturns500OnException(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->destroy('uuid-123');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to delete conversation', $data['error']);
    }

    // ── Restore error paths ──

    public function testRestoreAccessDenied(): void
    {
        $conv = new Conversation();
        $this->conversationMapper->method('findByUuid')->willReturn($conv);
        $this->conversationMapper->method('canUserModifyConversation')->willReturn(false);

        $result = $this->controller->restore('uuid-123');

        $this->assertEquals(403, $result->getStatus());
    }

    public function testRestoreReturns500OnException(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->restore('uuid-123');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to restore conversation', $data['error']);
    }

    // ── DestroyPermanent error paths ──

    public function testDestroyPermanentNotFound(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroyPermanent('bad-uuid');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroyPermanentReturns500OnException(): void
    {
        $this->conversationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->destroyPermanent('uuid-123');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to permanently delete conversation', $data['error']);
    }
}
