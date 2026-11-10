<?php

/**
 * OpenRegister Conversation Controller
 *
 * Controller for handling AI conversation API endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Controller\Support\ChatFamilyControllerInterface;
use OCA\OpenRegister\Controller\Support\ConversationControllerServices;
use OCA\OpenRegister\Controller\Support\ConversationChange;
use OCA\OpenRegister\Controller\Support\ConversationFailure;
use OCA\OpenRegister\Db\FeedbackMapperInterface;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ConversationMapperInterface;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationScope;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ConversationController
 *
 * Controller for handling AI conversation API endpoints.
 * Provides CRUD operations for conversations with organisation-based filtering.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConversationController extends Controller implements ChatFamilyControllerInterface {

	/**
	 * Conversation mapper
	 *
	 * @var ConversationMapperInterface
	 */
	private ConversationMapperInterface $conversationMapper;

	/**
	 * Message mapper
	 *
	 * @var MessageMapperInterface
	 */
	private MessageMapperInterface $messageMapper;

	/**
	 * Feedback mapper
	 *
	 * @var FeedbackMapperInterface
	 */
	private FeedbackMapperInterface $feedbackMapper;

	/**
	 * Agent mapper
	 *
	 * @var AgentMapperInterface
	 */
	private AgentMapperInterface $agentMapper;

	/**
	 * Organisation service
	 *
	 * @var OrganisationServiceInterface
	 */
	private OrganisationServiceInterface $organisationService;

	/**
	 * Chat service
	 *
	 * @var ChatServiceInterface
	 */
	private ChatServiceInterface $chatService;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * User ID
	 *
	 * @var string
	 */
	private string $userId;

	/**
	 * Constructor
	 *
	 * @param IRequest $request Request object
	 * @param ConversationControllerServices $services The conversation controller services.
	 * @param string $userId User ID
	 */
	public function __construct(IRequest $request, ConversationControllerServices $services, string $userId) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
		$this->conversationMapper = $services->mappers->conversationMapper;
		$this->messageMapper = $services->mappers->messageMapper;
		$this->feedbackMapper = $services->mappers->feedbackMapper;
		$this->agentMapper = $services->mappers->agentMapper;
		$this->organisationService = $services->domain->organisationService;
		$this->chatService = $services->domain->chatService;
		$this->logger = $services->platform->logger;
		$this->userId = $userId;
	}//end __construct()

	/**
	 * List conversations for the current user
	 *
	 * Supports filtering with query parameters:
	 * - _deleted: boolean (true = archived/deleted conversations, false/default = active conversations)
	 * - limit: int (default: 50)
	 * - offset: int (default: 0)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with list of conversations
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{error?: 'Failed to fetch conversations', message?: string,
	 *     results?: list<array{agentId: int|null, created: null|string,
	 *     deletedAt: null|string, id: int, metadata: array|null,
	 *     organisation: null|string, title: null|string, updated: null|string,
	 *     userId: null|string, uuid: null|string}>,
	 *     total?: int, limit?: int, offset?: int}, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function index(): JSONResponse {
		try {
			$organisationUuid = $this->organisationService->getActiveOrganisation()?->getUuid();
			$params = $this->request->getParams();
			$page   = $this->paging(params: $params);
			if (filter_var($params['_deleted'] ?? false, FILTER_VALIDATE_BOOLEAN) === true) {
				$list = $this->listDeletedConversations(organisationUuid: $organisationUuid, page: $page);
				return new JSONResponse(data: $list + $page, statusCode: 200);
			}

			$list = $this->listActiveConversations(organisationUuid: $organisationUuid, page: $page);
			return new JSONResponse(data: $list + $page, statusCode: 200);
		} catch (\Exception $e) {
			$this->logFailure(log: 'Failed to list conversations', e: $e);
			return $this->failure(error: 'Failed to fetch conversations', e: $e);
		}
	}//end index()

	/**
	 * The `limit` (default 50) and `offset` of a list request; the
	 * underscored spellings are read when the plain ones are absent.
	 *
	 * @param array<string, mixed> $params The request parameters
	 *
	 * @return array{limit: int, offset: int}
	 */
	private function paging(array $params): array {
		return [
			'limit' => (int)($params['limit'] ?? $params['_limit'] ?? 50),
			'offset' => (int)($params['offset'] ?? $params['_offset'] ?? 0),
		];
	}//end paging()

	/**
	 * One page of the current user's archived (soft-deleted) conversations in an organisation.
	 *
	 * @param string|null                   $organisationUuid The active organisation
	 * @param array{limit: int, offset: int} $page             The page
	 *
	 * @return array{results: array, total: int}
	 */
	private function listDeletedConversations(?string $organisationUuid, array $page): array {
		$scope         = new ConversationScope(userId: $this->userId, organisation: $organisationUuid);
		$conversations = $this->conversationMapper->findDeletedByUser(
			scope: $scope,
			page: new ListQuery(limit: $page['limit'], offset: $page['offset'])
		);
		$total         = $this->conversationMapper->countDeletedByUser(scope: $scope);
		return ['results' => $this->serialized(conversations: $conversations), 'total' => $total];
	}//end listDeletedConversations()

	/**
	 * One page of the current user's active conversations in an organisation.
	 *
	 * @param string|null                   $organisationUuid The active organisation
	 * @param array{limit: int, offset: int} $page             The page
	 *
	 * @return array{results: array, total: int}
	 */
	private function listActiveConversations(?string $organisationUuid, array $page): array {
		$scope         = new ConversationScope(userId: $this->userId, organisation: $organisationUuid);
		$conversations = $this->conversationMapper->findByUser(
			scope: $scope,
			page: new ListQuery(limit: $page['limit'], offset: $page['offset'])
		);
		$total         = $this->conversationMapper->countByUser(scope: $scope);

		return ['results' => $this->serialized(conversations: $conversations), 'total' => $total];
	}//end listActiveConversations()

	/**
	 * Serialize a list of conversations.
	 *
	 * @param array<Conversation> $conversations The conversations
	 *
	 * @return array The serialized conversations
	 */
	private function serialized(array $conversations): array {
		return array_map(fn (Conversation $conv) => $conv->jsonSerialize(), $conversations);
	}//end serialized()

	/**
	 * Get a single conversation (without messages)
	 *
	 * RBAC check is handled in the mapper layer.
	 *
	 * @param string $uuid Conversation UUID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with conversation details
	 *
	 * @psalm-return JSONResponse<200|403|404|500,
	 *     array{error?: 'Access denied'|'Conversation not found'|
	 *     'Failed to fetch conversation', message?: string, id?: int,
	 *     uuid?: null|string, title?: null|string, userId?: null|string,
	 *     organisation?: null|string, agentId?: int|null, metadata?: array|null,
	 *     deletedAt?: null|string, created?: null|string, updated?: null|string,
	 *     messageCount?: int}, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function show(string $uuid): JSONResponse {
		$failure = new ConversationFailure(uuid: $uuid, error: 'Failed to fetch conversation');
		return $this->reading(
			failure: $failure->loggedAs(message: 'Failed to get conversation'),
			action: function (Conversation $conversation): JSONResponse {
				// Without messages; the message count is read separately for efficiency.
				$response = $conversation->jsonSerialize();
				$response['messageCount'] = $this->messageMapper->countByConversation($conversation->getId());
				return new JSONResponse(data: $response, statusCode: 200);
			}
		);
	}//end show()

	/**
	 * Get messages for a conversation
	 *
	 * RBAC check is handled in the mapper layer.
	 *
	 * @param string $uuid Conversation UUID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with conversation messages
	 *
	 * @psalm-return JSONResponse<200|403|404|500,
	 *     array{error?: 'Access denied'|'Conversation not found'|
	 *     'Failed to fetch messages', message?: string,
	 *     results?: list<array{content: null|string, conversationId: int|null,
	 *     created: null|string, id: int, role: null|string, sources: array|null,
	 *     uuid: null|string}>, total?: int, limit?: int, offset?: int},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function messages(string $uuid): JSONResponse {
		$failure = new ConversationFailure(uuid: $uuid, error: 'Failed to fetch messages');
		return $this->reading(
			failure: $failure->loggedAs(message: 'Failed to get messages'),
			action: fn (Conversation $conversation): JSONResponse => $this->messagePage(conversation: $conversation)
		);
	}//end messages()

	/**
	 * One page of a conversation's messages.
	 *
	 * @param Conversation $conversation A conversation the caller may read
	 *
	 * @return JSONResponse The page
	 */
	private function messagePage(Conversation $conversation): JSONResponse {
		$page     = $this->paging(params: $this->request->getParams());
		$messages = $this->messageMapper->findByConversation(
			conversationId: $conversation->getId(),
			page: new ListQuery(limit: $page['limit'], offset: $page['offset'])
		);
		$total    = $this->messageMapper->countByConversation($conversation->getId());

		return new JSONResponse(
			data: ['results' => array_map(fn ($msg) => $msg->jsonSerialize(), $messages), 'total' => $total] + $page,
			statusCode: 200
		);
	}//end messagePage()

	/**
	 * Run a read action on a conversation the caller may read.
	 *
	 * @param ConversationFailure $failure The failure to report when the action throws
	 * @param callable            $action  The action, given the conversation
	 *
	 * @return JSONResponse The action's response, a refusal (403), not found (404) or the failure (500)
	 */
	private function reading(ConversationFailure $failure, callable $action): JSONResponse {
		try {
			$conversation = $this->conversationMapper->findByUuid($failure->uuid);
			$denied       = $this->readDenied(conversation: $conversation);
			if ($denied !== null) {
				return $denied;
			}

			return $action($conversation);
		} catch (DoesNotExistException $e) {
			return $this->conversationNotFound();
		} catch (\Exception $e) {
			return $this->failed(failure: $failure, e: $e);
		}
	}//end reading()

	/**
	 * Run a write action on a conversation the caller may modify.
	 *
	 * @param ConversationChange $change The conversation, the verb its refusal names and the error its failure reports
	 * @param callable           $action The action, given the conversation
	 *
	 * @return JSONResponse The action's response, a refusal (403), not found (404) or the failure (500)
	 */
	private function modifying(ConversationChange $change, callable $action): JSONResponse {
		try {
			$conversation = $this->conversationMapper->findByUuid($change->uuid);
			$denied       = $this->modifyDenied(conversation: $conversation, message: $change->deniedMessage());
			if ($denied !== null) {
				return $denied;
			}

			return $action($conversation);
		} catch (DoesNotExistException $e) {
			return $this->conversationNotFound();
		} catch (\Exception $e) {
			return $this->failed(failure: $change->failure(), e: $e);
		}
	}//end modifying()

	/**
	 * Create a new conversation
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Created conversation
	 *
	 * @psalm-return JSONResponse<
	 *     201|500,
	 *     array{
	 *         error?: 'Failed to create conversation',
	 *         message?: string,
	 *         id?: int,
	 *         uuid?: null|string,
	 *         title?: null|string,
	 *         userId?: null|string,
	 *         organisation?: null|string,
	 *         agentId?: int|null,
	 *         metadata?: array|null,
	 *         deletedAt?: null|string,
	 *         created?: null|string,
	 *         updated?: null|string
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		try {
			$data             = $this->request->getParams();
			$organisationUuid = $this->organisationService->getActiveOrganisation()?->getUuid();
			$conversation     = $this->conversationMapper->insert($this->newConversation(data: $data, organisationUuid: $organisationUuid));
			$this->logger->info(
				message: '[ConversationController] Conversation created',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $conversation->getUuid(),
					'userId' => $this->userId, 'organisation' => $organisationUuid]
			);

			return new JSONResponse(data: $conversation->jsonSerialize(), statusCode: 201);
		} catch (\Exception $e) {
			$this->logFailure(log: 'Failed to create conversation', e: $e);
			return $this->failure(error: 'Failed to create conversation', e: $e);
		}
	}//end create()

	/**
	 * A new conversation owned by the current user in the active
	 * organisation; the request cannot set either.
	 *
	 * @param array<string, mixed> $data             The request parameters
	 * @param string|null          $organisationUuid The active organisation
	 *
	 * @return Conversation The conversation, not yet inserted
	 */
	private function newConversation(array $data, ?string $organisationUuid): Conversation {
		$agentId      = $this->requestedAgentId(data: $data);
		$conversation = new Conversation();
		$conversation->setUuid(Uuid::v4()->toRfc4122());
		$conversation->setUserId($this->userId);
		$conversation->setOrganisation($organisationUuid);
		$conversation->setAgentId($agentId);
		$conversation->setTitle($this->titleFor(data: $data, agentId: $agentId));
		$conversation->setMetadata($data['metadata'] ?? []);
		$conversation->setCreated(new DateTime());
		$conversation->setUpdated(new DateTime());

		return $conversation;
	}//end newConversation()

	/**
	 * The agent of a new conversation: `agentId`, else the id of the agent
	 * `agentUuid` names, else none.
	 *
	 * @param array<string, mixed> $data The request parameters
	 *
	 * @return mixed The agent id, or null
	 */
	private function requestedAgentId(array $data): mixed {
		if (($data['agentId'] ?? null) !== null) {
			return $data['agentId'];
		}

		if (($data['agentUuid'] ?? null) === null) {
			return null;
		}

		return $this->agentIdByUuid(agentUuid: $data['agentUuid']);
	}//end requestedAgentId()

	/**
	 * The id of the agent with a UUID; an unknown agent is logged and gives null.
	 *
	 * @param mixed $agentUuid The agent UUID
	 *
	 * @return int|null The agent id
	 */
	private function agentIdByUuid(mixed $agentUuid): ?int {
		try {
			return $this->agentMapper->findByUuid($agentUuid)->getId();
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ConversationController] Agent UUID not found',
				context: ['file' => __FILE__, 'line' => __LINE__, 'agentUuid' => $agentUuid]
			);
			return null;
		}
	}//end agentIdByUuid()

	/**
	 * The title of a new conversation: the requested one, else a unique
	 * default when there is an agent, else none.
	 *
	 * @param array<string, mixed> $data    The request parameters
	 * @param mixed                $agentId The agent id, or null
	 *
	 * @return mixed The title, or null
	 */
	private function titleFor(array $data, mixed $agentId): mixed {
		$title = $data['title'] ?? null;
		if ($title !== null || $agentId === null) {
			return $title;
		}

		return $this->chatService->ensureUniqueTitle(baseTitle: 'New Conversation', userId: $this->userId, agentId: $agentId);
	}//end titleFor()

	/**
	 * Update a conversation (e.g., rename)
	 *
	 * RBAC check is handled in the mapper layer.
	 *
	 * @param string $uuid Conversation UUID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated conversation
	 *
	 * @psalm-return JSONResponse<200|403|404|500,
	 *     array{error?: 'Access denied'|'Conversation not found'|
	 *     'Failed to update conversation', message?: string, id?: int,
	 *     uuid?: null|string, title?: null|string, userId?: null|string,
	 *     organisation?: null|string, agentId?: int|null, metadata?: array|null,
	 *     deletedAt?: null|string, created?: null|string, updated?: null|string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function update(string $uuid): JSONResponse {
		return $this->modifying(
			change: new ConversationChange(uuid: $uuid, verb: 'modify', error: 'Failed to update conversation'),
			action: fn (Conversation $conversation): JSONResponse => $this->saveUpdate(conversation: $conversation, uuid: $uuid)
		);
	}//end update()

	/**
	 * Apply the allowed fields of the request to a conversation and save it.
	 *
	 * SECURITY: only title and metadata change; organisation, owner, userId,
	 * agentId and created are immutable.
	 *
	 * @param Conversation $conversation A conversation the caller may modify
	 * @param string       $uuid         The conversation UUID, for the log
	 *
	 * @return JSONResponse The saved conversation
	 */
	private function saveUpdate(Conversation $conversation, string $uuid): JSONResponse {
		$data = $this->request->getParams();
		if (($data['title'] ?? null) !== null) {
			$conversation->setTitle($data['title']);
		}

		if (($data['metadata'] ?? null) !== null) {
			$conversation->setMetadata($data['metadata']);
		}

		$conversation->setUpdated(new DateTime());
		$conversation = $this->conversationMapper->update($conversation);
		$this->logged(message: 'Conversation updated', uuid: $uuid);

		return new JSONResponse(data: $conversation->jsonSerialize(), statusCode: 200);
	}//end saveUpdate()

	/**
	 * Soft delete a conversation
	 *
	 * RBAC check is handled in the mapper layer.
	 *
	 * @param string $uuid Conversation UUID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response confirming conversation deletion
	 *
	 * @psalm-return JSONResponse<200|403|404|500,
	 *     array{error?: 'Access denied'|'Conversation not found'|
	 *     'Failed to delete conversation', message: string, uuid?: string,
	 *     archived?: true}, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function destroy(string $uuid): JSONResponse {
		return $this->modifying(
			change: new ConversationChange(uuid: $uuid, verb: 'delete', error: 'Failed to delete conversation'),
			action: function (Conversation $conversation) use ($uuid): JSONResponse {
				// An already archived (soft-deleted) conversation is deleted permanently.
				if ($conversation->getDeletedAt() !== null) {
					$this->logged(message: 'Permanently deleting archived conversation', uuid: $uuid);
					return $this->purge(conversation: $conversation, uuid: $uuid);
				}

				return $this->archive(conversation: $conversation, uuid: $uuid);
			}
		);
	}//end destroy()

	/**
	 * Restore a soft-deleted conversation
	 *
	 * RBAC check is handled in the mapper layer.
	 *
	 * @param string $uuid Conversation UUID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with restored conversation
	 *
	 * @psalm-return JSONResponse<200|403|404|500,
	 *     array{error?: 'Access denied'|'Conversation not found'|
	 *     'Failed to restore conversation', message?: string, id?: int,
	 *     uuid?: null|string, title?: null|string, userId?: null|string,
	 *     organisation?: null|string, agentId?: int|null, metadata?: array|null,
	 *     deletedAt?: null|string, created?: null|string, updated?: null|string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function restore(string $uuid): JSONResponse {
		return $this->modifying(
			change: new ConversationChange(uuid: $uuid, verb: 'restore', error: 'Failed to restore conversation'),
			action: function (Conversation $conversation) use ($uuid): JSONResponse {
				$conversation = $this->conversationMapper->restore($conversation->getId());
				$this->logged(message: 'Conversation restored', uuid: $uuid);
				return new JSONResponse(data: $conversation->jsonSerialize(), statusCode: 200);
			}
		);
	}//end restore()

	/**
	 * Hard delete a conversation permanently
	 *
	 * RBAC check is handled in the mapper layer.
	 *
	 * @param string $uuid Conversation UUID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response confirming permanent deletion
	 *
	 * @psalm-return JSONResponse<200|403|404|500,
	 *     array{error?: 'Access denied'|'Conversation not found'|
	 *     'Failed to permanently delete conversation', message: string,
	 *     uuid?: string}, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function destroyPermanent(string $uuid): JSONResponse {
		return $this->modifying(
			change: new ConversationChange(uuid: $uuid, verb: 'delete', error: 'Failed to permanently delete conversation'),
			action: fn (Conversation $conversation): JSONResponse => $this->purge(conversation: $conversation, uuid: $uuid)
		);
	}//end destroyPermanent()

	/**
	 * Delete a conversation with its feedback and messages.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param string       $uuid         Its UUID, for the log and the response.
	 *
	 * @return JSONResponse The deletion confirmation.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function purge(Conversation $conversation, string $uuid): JSONResponse {
		$this->feedbackMapper->deleteByConversation($conversation->getId());
		$this->messageMapper->deleteByConversation($conversation->getId());
		$this->conversationMapper->delete($conversation);
		$this->logged(message: 'Conversation permanently deleted', uuid: $uuid);

		return new JSONResponse(
			data: [
				'message' => 'Conversation permanently deleted',
				'uuid' => $uuid,
			],
			statusCode: 200
		);
	}//end purge()

	/**
	 * Archive (soft delete) a conversation.
	 *
	 * @param Conversation $conversation The conversation to archive.
	 * @param string       $uuid         The requested conversation UUID.
	 *
	 * @return JSONResponse The archive confirmation.
	 */
	private function archive(Conversation $conversation, string $uuid): JSONResponse {
		$this->conversationMapper->softDelete($conversation->getId());
		$this->logged(message: 'Conversation archived (soft deleted)', uuid: $uuid);

		return new JSONResponse(
			data: [
				'message' => 'Conversation archived successfully',
				'uuid' => $uuid,
				'archived' => true,
			],
			statusCode: 200
		);
	}//end archive()

	/**
	 * 403 response for a user without modify rights on the conversation.
	 *
	 * @param string $message The explanation for the caller.
	 *
	 * @return JSONResponse The 403 response.
	 */
	private function accessDenied(string $message): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Access denied',
				'message' => $message,
			],
			statusCode: 403
		);
	}//end accessDenied()

	/**
	 * 404 response for an unknown conversation.
	 *
	 * @return JSONResponse The 404 response.
	 */
	private function conversationNotFound(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Conversation not found',
				'message' => 'The requested conversation does not exist',
			],
			statusCode: 404
		);
	}//end conversationNotFound()
	/**
	 * The refusal for a caller who may not read the conversation, in the active organisation.
	 *
	 * @param Conversation $conversation The conversation.
	 *
	 * @return JSONResponse|null A 403, or null when the caller may read it.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function readDenied(Conversation $conversation): ?JSONResponse {
		$organisation = $this->organisationService->getActiveOrganisation();
		$mayRead = $this->conversationMapper->canUserAccessConversation(
			conversation: $conversation,
			scope: new ConversationScope(userId: $this->userId, organisation: $organisation?->getUuid())
		);
		if ($mayRead === false) {
			return $this->accessDenied(message: 'You do not have access to this conversation');
		}

		return null;
	}//end readDenied()

	/**
	 * The refusal for a caller who may not change the conversation.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param string       $message      The refusal text.
	 *
	 * @return JSONResponse|null A 403, or null when the caller may change it.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function modifyDenied(Conversation $conversation, string $message): ?JSONResponse {
		$mayModify = $this->conversationMapper->canUserModifyConversation(conversation: $conversation, userId: $this->userId);
		if ($mayModify === false) {
			return $this->accessDenied(message: $message);
		}

		return null;
	}//end modifyDenied()

	/**
	 * Log an action on a conversation at info level.
	 *
	 * @param string $message The message, without the controller prefix.
	 * @param string $uuid    The conversation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function logged(string $message, string $uuid): void {
		$this->logger->info(
			message: '[ConversationController] ' . $message,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $uuid,
			]
		);
	}//end logged()

	/**
	 * Log a failed action on a conversation and answer it with a 500.
	 *
	 * @param ConversationFailure $failure How the failure is logged and answered.
	 * @param \Exception          $e       The failure.
	 *
	 * @return JSONResponse The 500 answer.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function failed(ConversationFailure $failure, \Exception $e): JSONResponse {
		$reason = $e->getMessage();
		$context = ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $failure->uuid, 'error' => $reason, 'trace' => $e->getTraceAsString()];
		$this->logger->error(message: '[ConversationController] ' . $failure->logMessage(), context: $context);

		return new JSONResponse(data: ['error' => $failure->error, 'message' => $reason], statusCode: 500);
	}//end failed()

	/**
	 * Log a failed action that has no conversation.
	 *
	 * @param string     $log The log message, without the controller prefix
	 * @param \Exception $e   The failure
	 *
	 * @return void
	 */
	private function logFailure(string $log, \Exception $e): void {
		$context = ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
		$this->logger->error(message: '[ConversationController] ' . $log, context: $context);
	}//end logFailure()

	/**
	 * The 500 for a failed action that has no conversation; the caller logged it.
	 *
	 * @param string     $error The error of the response
	 * @param \Exception $e     The failure
	 *
	 * @return JSONResponse The error response
	 */
	private function failure(string $error, \Exception $e): JSONResponse {
		return new JSONResponse(data: ['error' => $error, 'message' => $e->getMessage()], statusCode: 500);
	}//end failure()
}//end class
