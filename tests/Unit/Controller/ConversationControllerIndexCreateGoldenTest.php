<?php

/**
 * Golden test pinning the tenancy and ownership outcomes of
 * ConversationController::index() and ::create().
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

use OCA\OpenRegister\Mcp\Db\ConversationScope;
use OCA\OpenRegister\Db\ListQuery;
use Exception;
use OCA\OpenRegister\Controller\ConversationController;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Notifications\Db\MessageMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ConversationControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * index() lists only the caller's conversations in the active organisation;
 * create() always makes the caller the owner in the active organisation.
 */
class ConversationControllerIndexCreateGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private ConversationMapper&MockObject $conversationMapper;

	private AgentMapper&MockObject $agentMapper;

	private OrganisationService&MockObject $organisationService;

	private ChatService&MockObject $chatService;

	private ConversationController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request             = $this->createMock(IRequest::class);
		$this->conversationMapper  = $this->createMock(ConversationMapper::class);
		$this->agentMapper         = $this->createMock(AgentMapper::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->chatService         = $this->createMock(ChatService::class);
		$this->controller          = ConversationControllerFactory::create(
			$this->request,
			$this->conversationMapper,
			$this->createMock(MessageMapper::class),
			$this->createMock(FeedbackMapper::class),
			$this->agentMapper,
			$this->organisationService,
			$this->chatService,
			$this->createMock(LoggerInterface::class),
			'alice'
		);
	}

	private function activeOrganisation(?string $uuid): void {
		$org = null;
		if ($uuid !== null) {
			$org = new Organisation();
			$org->setUuid($uuid);
		}

		$this->organisationService->method('getActiveOrganisation')->willReturn($org);
	}

	public function testIndexActiveConversationsAreScopedToCallerAndOrganisation(): void {
		$this->activeOrganisation('org-a');
		$this->request->method('getParams')->willReturn(['_limit' => '4', 'offset' => '8', '_offset' => '1']);
		$this->conversationMapper->expects($this->never())->method('findDeletedByUser');
		$this->conversationMapper->expects($this->once())->method('findByUser')
			->with(new ConversationScope(userId: 'alice', organisation: 'org-a'), new ListQuery(limit: 4, offset: 8))->willReturn([]);
		$this->conversationMapper->expects($this->once())->method('countByUser')
			->with(new ConversationScope(userId: 'alice', organisation: 'org-a'))->willReturn(3);

		$response = $this->controller->index();

		$this->assertSame(['results' => [], 'total' => 3, 'limit' => 4, 'offset' => 8], $response->getData());
	}

	public function testIndexArchivedConversationsAreScopedToCallerAndOrganisation(): void {
		$this->activeOrganisation(null);
		$this->request->method('getParams')->willReturn(['_deleted' => 'true', 'limit' => '2']);
		$this->conversationMapper->expects($this->never())->method('findByUser');
		$this->conversationMapper->expects($this->once())->method('findDeletedByUser')
			->with(new ConversationScope(userId: 'alice'), new ListQuery(limit: 2, offset: 0))->willReturn([]);
		$this->conversationMapper->expects($this->once())->method('countDeletedByUser')
			->with(new ConversationScope(userId: 'alice'))->willReturn(1);

		$response = $this->controller->index();

		$this->assertSame(['results' => [], 'total' => 1, 'limit' => 2, 'offset' => 0], $response->getData());
	}

	public function testIndexFailure(): void {
		$this->organisationService->method('getActiveOrganisation')->willThrowException(new Exception('db'));

		$response = $this->controller->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to fetch conversations', 'message' => 'db'], $response->getData());
	}

	private function expectInsert(callable $check): void {
		$this->conversationMapper->expects($this->once())->method('insert')
			->with($this->callback($check))
			->willReturnArgument(0);
	}

	public function testCreateForcesCallerAndOrganisation(): void {
		$this->activeOrganisation('org-a');
		$this->request->method('getParams')->willReturn(
			['agentId' => 5, 'agentUuid' => 'ignored', 'title' => 'T', 'userId' => 'mallory', 'organisation' => 'evil', 'metadata' => ['m' => 1]]
		);
		$this->agentMapper->expects($this->never())->method('findByUuid');
		$this->chatService->expects($this->never())->method('ensureUniqueTitle');
		$this->expectInsert(
			fn (Conversation $c): bool => $c->getUserId() === 'alice' && $c->getOrganisation() === 'org-a'
				&& $c->getAgentId() === 5 && $c->getTitle() === 'T' && $c->getMetadata() === ['m' => 1]
				&& $c->getUuid() !== null && $c->getCreated() !== null && $c->getUpdated() !== null
		);

		$response = $this->controller->create();

		$this->assertSame(201, $response->getStatus());
	}

	public function testCreateResolvesAgentUuidAndTitles(): void {
		$this->activeOrganisation(null);
		$this->request->method('getParams')->willReturn(['agentUuid' => 'agent-uuid']);
		$agent = new Agent();
		$agent->setId(9);
		$this->agentMapper->method('findByUuid')->with('agent-uuid')->willReturn($agent);
		$this->chatService->expects($this->once())->method('ensureUniqueTitle')
			->with('New Conversation', 'alice', 9)->willReturn('New Conversation 3');
		$this->expectInsert(
			fn (Conversation $c): bool => $c->getAgentId() === 9 && $c->getTitle() === 'New Conversation 3'
				&& $c->getOrganisation() === null && $c->getMetadata() === []
		);

		$this->assertSame(201, $this->controller->create()->getStatus());
	}

	public function testCreateWithUnknownAgentUuidHasNoAgentAndNoTitle(): void {
		$this->activeOrganisation('org-a');
		$this->request->method('getParams')->willReturn(['agentUuid' => 'nope']);
		$this->agentMapper->method('findByUuid')->willThrowException(new Exception('missing'));
		$this->chatService->expects($this->never())->method('ensureUniqueTitle');
		$this->expectInsert(fn (Conversation $c): bool => $c->getAgentId() === null && $c->getTitle() === null);

		$this->assertSame(201, $this->controller->create()->getStatus());
	}

	public function testCreateFailure(): void {
		$this->activeOrganisation('org-a');
		$this->request->method('getParams')->willReturn([]);
		$this->conversationMapper->method('insert')->willThrowException(new Exception('db'));

		$response = $this->controller->create();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to create conversation', 'message' => 'db'], $response->getData());
	}
}
