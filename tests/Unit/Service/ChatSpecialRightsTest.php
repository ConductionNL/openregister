<?php

/**
 * A chat message needs llm_use, and agent_use when the conversation runs an agent.
 *
 * Decision 239. Driven from the caller's side: `POST /api/chat/send` is
 * ChatController::sendMessage(), an ordinary Controller (no OCS envelope), over
 * the real ChatService and the real reader of special rights. Only the
 * mappers, the user directory and the model handlers are doubles, so a refusal
 * is asserted as the status the caller gets and as a model that was never
 * called.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Chat\ContextRetrievalHandler;
use OCA\OpenRegister\Service\Chat\ConversationManagementHandler;
use OCA\OpenRegister\Service\Chat\MessageHistoryHandler;
use OCA\OpenRegister\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\AppFramework\Controller;
use OCP\AppFramework\OCSController;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\ChatService
 * @covers \OCA\OpenRegister\Controller\ChatController
 * @uses   \OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Conversation
 * @uses   \OCA\OpenRegister\Db\Agent
 * @uses   \OCA\OpenRegister\Db\Message
 *
 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
 */
class ChatSpecialRightsTest extends TestCase {

	/**
	 * Authorization blocks per organisation uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $blocks = [];

	/**
	 * Group ids per user.
	 *
	 * @var array<string, list<string>>
	 */
	private array $groups = ['alice' => ['ai-users'], 'bob' => ['staff'], 'admin' => ['admin']];

	private ConversationMapper&MockObject $conversations;

	private AgentMapper&MockObject $agents;

	private ResponseGenerationHandler&MockObject $model;

	private MessageHistoryHandler&MockObject $history;

	private ?Agent $agent = null;

	private Conversation $conversation;

	protected function setUp(): void {
		parent::setUp();

		$this->conversation = new Conversation();
		$this->conversation->setId(1);
		$this->conversation->setUuid('conv-1');
		$this->conversation->setTitle('Existing');
		$this->conversation->setOrganisation('org-chat');

		$this->conversations = $this->createMock(ConversationMapper::class);
		$this->conversations->method('find')->willReturnCallback(fn (): Conversation => $this->conversation);
		$this->conversations->method('findByUuid')->willReturnCallback(fn (): Conversation => $this->conversation);

		$this->agents = $this->createMock(AgentMapper::class);
		$this->agents->method('find')->willReturnCallback(fn (): ?Agent => $this->agent);

		$this->model = $this->createMock(ResponseGenerationHandler::class);
		$this->history = $this->createMock(MessageHistoryHandler::class);
		$this->history->method('buildMessageHistory')->willReturn([]);
		$stored = new Message();
		$stored->setId(9);
		$this->history->method('storeMessage')->willReturn($stored);
	}//end setUp()

	/**
	 * ChatController::sendMessage() as user `$userId`, through the real ChatService and reader.
	 *
	 * @param string $userId The caller.
	 *
	 * @return array{0: int, 1: array<string, mixed>} The status and the body the caller gets.
	 */
	private function send(string $userId): array {
		$this->conversation->setUserId($userId);

		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findByUuid')->willReturnCallback(function (string $uuid): Organisation {
			$organisation = new Organisation();
			$organisation->setUuid($uuid);
			$organisation->setAuthorization($this->blocks[$uuid] ?? []);
			return $organisation;
		});
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $group): bool => in_array($group, $this->groups[$uid] ?? [], true)
		);
		$rights = new OrganisationSpecialRights(
			$organisations,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			new NullLogger(),
			$groupManager
		);

		$context = $this->createMock(ContextRetrievalHandler::class);
		$context->method('retrieveContext')->willReturn(['text' => '', 'sources' => []]);
		$messages = $this->createMock(MessageMapper::class);
		$messages->method('countByConversation')->willReturn(5);

		$service = new ChatService(
			$this->conversations,
			$messages,
			$this->agents,
			$context,
			$this->model,
			$this->createMock(ConversationManagementHandler::class),
			$this->history,
			$this->createMock(ToolManagementHandler::class),
			new NullLogger(),
			$rights
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key): mixed => ['conversation' => 'conv-1', 'message' => 'Hello'][$key] ?? null
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new ChatController(
			'openregister',
			$request,
			$service,
			$this->conversations,
			$messages,
			$this->createMock(FeedbackMapper::class),
			$this->agents,
			$this->createMock(OrganisationService::class),
			$this->createMock(IDBConnection::class),
			new NullLogger(),
			$l10n,
			$userId
		);

		$response = $controller->sendMessage();

		return [$response->getStatus(), $response->getData()];
	}//end send()

	/**
	 * An agent of organisation `org-agent`.
	 *
	 * @return void
	 */
	private function withAgent(): void {
		$this->agent = new Agent();
		$this->agent->setId(5);
		$this->agent->setOrganisation('org-agent');
		$this->conversation->setAgentId(5);
	}//end withAgent()

	public function testTheEndpointIsAPlainControllerSoA403ReachesTheCaller(): void {
		// OCSMiddleware rewrites the status of an OCSController's response to 200;
		// it leaves an ordinary Controller's status as it is.
		$this->assertTrue(is_subclass_of(ChatController::class, Controller::class));
		$this->assertFalse(is_subclass_of(ChatController::class, OCSController::class));
	}//end testTheEndpointIsAPlainControllerSoA403ReachesTheCaller()

	public function testAHolderOfLlmUseGetsAnAnswer(): void {
		$this->blocks['org-chat'] = [OrganisationSpecialRights::LLM_USE => ['ai-users']];
		$this->model->expects($this->once())->method('generateResponse')->willReturn('Hi');

		[$status, $body] = $this->send('alice');

		$this->assertSame(200, $status);
		$this->assertSame('Hi', $body['message']);
	}//end testAHolderOfLlmUseGetsAnAnswer()

	public function testANonHolderOfLlmUseIsRefusedWith403AndNoModelIsCalled(): void {
		$this->blocks['org-chat'] = [OrganisationSpecialRights::LLM_USE => ['ai-users']];
		$this->model->expects($this->never())->method('generateResponse');
		$this->history->expects($this->never())->method('storeMessage');

		[$status, $body] = $this->send('bob');

		$this->assertSame(403, $status);
		$this->assertStringContainsString('llm_use', $body['message']);
	}//end testANonHolderOfLlmUseIsRefusedWith403AndNoModelIsCalled()

	public function testAnAdministratorIsNotRefused(): void {
		$this->blocks['org-chat'] = [OrganisationSpecialRights::LLM_USE => []];
		$this->withAgent();
		$this->blocks['org-agent'] = [OrganisationSpecialRights::AGENT_USE => []];
		$this->model->expects($this->once())->method('generateResponse')->willReturn('Hi');

		[$status] = $this->send('admin');

		$this->assertSame(200, $status);
	}//end testAnAdministratorIsNotRefused()

	public function testAgentUseIsReadFromTheAgentsOrganisation(): void {
		$this->blocks['org-chat'] = [OrganisationSpecialRights::LLM_USE => ['*']];
		$this->withAgent();
		$this->blocks['org-agent'] = [OrganisationSpecialRights::AGENT_USE => ['ai-users']];
		$this->model->expects($this->once())->method('generateResponse')->willReturn('Hi');

		[$holder] = $this->send('alice');

		$this->assertSame(200, $holder);
	}//end testAgentUseIsReadFromTheAgentsOrganisation()

	public function testANonHolderOfAgentUseIsRefusedWith403(): void {
		$this->blocks['org-chat'] = [OrganisationSpecialRights::LLM_USE => ['*']];
		$this->withAgent();
		$this->blocks['org-agent'] = [OrganisationSpecialRights::AGENT_USE => ['ai-users']];
		$this->model->expects($this->never())->method('generateResponse');

		[$status, $body] = $this->send('bob');

		$this->assertSame(403, $status);
		$this->assertStringContainsString('agent_use', $body['message']);
	}//end testANonHolderOfAgentUseIsRefusedWith403()

	public function testAnOrganisationAfterTheUpgradeRefusesNobody(): void {
		// What OpenSpecialRightsToEveryone leaves behind: `*` beside the backfilled admin group.
		$this->blocks['org-chat'] = [OrganisationSpecialRights::LLM_USE => ['admin', '*']];
		$this->withAgent();
		$this->blocks['org-agent'] = [OrganisationSpecialRights::AGENT_USE => ['admin', '*']];
		$this->groups['bob'] = [];
		$this->model->expects($this->once())->method('generateResponse')->willReturn('Hi');

		[$status] = $this->send('bob');

		$this->assertSame(200, $status);
	}//end testAnOrganisationAfterTheUpgradeRefusesNobody()
}//end class
