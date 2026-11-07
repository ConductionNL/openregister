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
use OCA\OpenRegister\Controller\Support\ConversationControllerServices;
use OCA\OpenRegister\Controller\Support\ConversationFailure;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Kernel\Contracts\ConversationMapperInterface;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
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
class ConversationController extends Controller {

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
	 * @var FeedbackMapper
	 */
	private FeedbackMapper $feedbackMapper;

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
	 * @param string $appName Application name
	 * @param IRequest $request Request object
	 * @param ConversationControllerServices $services The conversation controller services.
	 * @param string $userId User ID
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ConversationControllerServices $services,
		string $userId,
	) {
		parent::__construct(appName: $appName, request: $request);
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
			// Get active organisation.
			$organisation = $this->organisationService->getActiveOrganisation();
			$organisationUuid = $organisation?->getUuid();

			// Get query parameters.
			$params = $this->request->getParams();
			$limit = (int)($params['limit'] ?? $params['_limit'] ?? 50);
			$offset = (int)($params['offset'] ?? $params['_offset'] ?? 0);
			$showDeleted = filter_var($params['_deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);

			// Initialize variables before conditional assignment.
			$conversations = [];
			$total = 0;

			// Fetch conversations based on deleted filter.
			if ($showDeleted === true) {
				// Fetch only deleted/archived conversations.
				$conversations = $this->conversationMapper->findDeletedByUser(
					userId: $this->userId,
					organisation: $organisationUuid,
					limit: $limit,
					offset: $offset
				);

				// Count total archived conversations.
				$total = $this->conversationMapper->countDeletedByUser(
					userId: $this->userId,
					organisation: $organisationUuid
				);
			}

			if ($showDeleted === false) {
				// Fetch only active (non-deleted) conversations.
				$conversations = $this->conversationMapper->findByUser(
					userId: $this->userId,
					organisation: $organisationUuid,
					includeDeleted: false,
					limit: $limit,
					offset: $offset
				);

				// Count total active conversations.
				$total = $this->conversationMapper->countByUser(
					userId: $this->userId,
					organisation: $organisationUuid,
					includeDeleted: false
				);
			}

			return new JSONResponse(
				data: [
					'results' => array_map(fn (Conversation $conv) => $conv->jsonSerialize(), $conversations),
					'total' => $total,
					'limit' => $limit,
					'offset' => $offset,
				],
				statusCode: 200
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ConversationController] Failed to list conversations',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to fetch conversations',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end index()

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
		try {
			$conversation = $this->conversationMapper->findByUuid($uuid);
			$denied = $this->readDenied(conversation: $conversation);
			if ($denied !== null) {
				return $denied;
			}

			// Build response without messages; the message count is read separately for efficiency.
			$response = $conversation->jsonSerialize();
			$response['messageCount'] = $this->messageMapper->countByConversation($conversation->getId());

			return new JSONResponse(data: $response, statusCode: 200);
		} catch (DoesNotExistException $e) {
			return $this->conversationNotFound();
		} catch (\Exception $e) {
			$failure = new ConversationFailure(uuid: $uuid, error: 'Failed to fetch conversation');
			return $this->failed(failure: $failure->loggedAs(message: 'Failed to get conversation'), e: $e);
		}//end try
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
		try {
			$conversation = $this->conversationMapper->findByUuid($uuid);
			$denied = $this->readDenied(conversation: $conversation);
			if ($denied !== null) {
				return $denied;
			}

			// Get query parameters for pagination.
			$params = $this->request->getParams();
			$limit = (int)($params['limit'] ?? $params['_limit'] ?? 50);
			$offset = (int)($params['offset'] ?? $params['_offset'] ?? 0);

			$messages = $this->messageMapper->findByConversation(
				conversationId: $conversation->getId(),
				limit: $limit,
				offset: $offset
			);
			$total = $this->messageMapper->countByConversation($conversation->getId());

			return new JSONResponse(
				data: [
					'results' => array_map(fn ($msg) => $msg->jsonSerialize(), $messages),
					'total' => $total,
					'limit' => $limit,
					'offset' => $offset,
				],
				statusCode: 200
			);
		} catch (DoesNotExistException $e) {
			return $this->conversationNotFound();
		} catch (\Exception $e) {
			$failure = new ConversationFailure(uuid: $uuid, error: 'Failed to fetch messages');
			return $this->failed(failure: $failure->loggedAs(message: 'Failed to get messages'), e: $e);
		}//end try
	}//end messages()

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
			// Get request data.
			$data = $this->request->getParams();

			// Get active organisation.
			$organisation = $this->organisationService->getActiveOrganisation();

			// Get agent ID (handle both agentId and agentUuid).
			$agentId = null;
			if (($data['agentId'] ?? null) !== null) {
				$agentId = $data['agentId'];
			} elseif (($data['agentUuid'] ?? null) !== null) {
				// Look up agent by UUID to get ID.
				try {
					$agent = $this->agentMapper->findByUuid($data['agentUuid']);
					$agentId = $agent->getId();
				} catch (\Exception $e) {
					// If agent not found, log and continue with null agentId.
					$this->logger->warning(
						message: '[ConversationController] Agent UUID not found',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'agentUuid' => $data['agentUuid'],
						]
					);
				}
			}

			// Generate unique title if not provided.
			$title = $data['title'] ?? null;
			if ($title === null && $agentId !== null) {
				$title = $this->chatService->ensureUniqueTitle(
					baseTitle: 'New Conversation',
					userId: $this->userId,
					agentId: $agentId
				);
			}

			// Create new conversation.
			$conversation = new Conversation();
			$conversation->setUuid(Uuid::v4()->toRfc4122());
			$conversation->setUserId($this->userId);
			$conversation->setOrganisation($organisation?->getUuid());
			$conversation->setAgentId($agentId);
			$conversation->setTitle($title);
			$conversation->setMetadata($data['metadata'] ?? []);
			$conversation->setCreated(new DateTime());
			$conversation->setUpdated(new DateTime());

			// Save to database.
			$conversation = $this->conversationMapper->insert($conversation);

			$this->logger->info(
				message: '[ConversationController] Conversation created',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $conversation->getUuid(),
					'userId' => $this->userId,
					'organisation' => $organisation?->getUuid(),
				]
			);

			return new JSONResponse(data: $conversation->jsonSerialize(), statusCode: 201);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ConversationController] Failed to create conversation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to create conversation',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end create()

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
		try {
			$conversation = $this->conversationMapper->findByUuid($uuid);
			$denied = $this->modifyDenied(conversation: $conversation, message: 'You do not have permission to modify this conversation');
			if ($denied !== null) {
				return $denied;
			}

			$data = $this->request->getParams();

			// SECURITY: Only update allowed fields to prevent tampering with immutable fields.
			// Immutable fields (organisation, owner, userId, agentId, created) are NOT updated.
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
		} catch (DoesNotExistException $e) {
			return $this->conversationNotFound();
		} catch (\Exception $e) {
			return $this->failed(failure: new ConversationFailure(uuid: $uuid, error: 'Failed to update conversation'), e: $e);
		}//end try
	}//end update()

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
		try {
			$conversation = $this->conversationMapper->findByUuid($uuid);
			$denied = $this->modifyDenied(conversation: $conversation, message: 'You do not have permission to delete this conversation');
			if ($denied !== null) {
				return $denied;
			}

			// An already archived (soft-deleted) conversation is deleted permanently.
			if ($conversation->getDeletedAt() !== null) {
				$this->logged(message: 'Permanently deleting archived conversation', uuid: $uuid);
				return $this->purge(conversation: $conversation, uuid: $uuid);
			}

			return $this->archive(conversation: $conversation, uuid: $uuid);
		} catch (DoesNotExistException $e) {
			return $this->conversationNotFound();
		} catch (\Exception $e) {
			return $this->failed(failure: new ConversationFailure(uuid: $uuid, error: 'Failed to delete conversation'), e: $e);
		}//end try
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
		try {
			$conversation = $this->conversationMapper->findByUuid($uuid);
			$denied = $this->modifyDenied(conversation: $conversation, message: 'You do not have permission to restore this conversation');
			if ($denied !== null) {
				return $denied;
			}

			$conversation = $this->conversationMapper->restore($conversation->getId());
			$this->logged(message: 'Conversation restored', uuid: $uuid);

			return new JSONResponse(data: $conversation->jsonSerialize(), statusCode: 200);
		} catch (DoesNotExistException $e) {
			return $this->conversationNotFound();
		} catch (\Exception $e) {
			return $this->failed(failure: new ConversationFailure(uuid: $uuid, error: 'Failed to restore conversation'), e: $e);
		}//end try
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
		try {
			$conversation = $this->conversationMapper->findByUuid($uuid);
			$denied = $this->modifyDenied(conversation: $conversation, message: 'You do not have permission to delete this conversation');
			if ($denied !== null) {
				return $denied;
			}

			return $this->purge(conversation: $conversation, uuid: $uuid);
		} catch (DoesNotExistException $e) {
			return $this->conversationNotFound();
		} catch (\Exception $e) {
			return $this->failed(failure: new ConversationFailure(uuid: $uuid, error: 'Failed to permanently delete conversation'), e: $e);
		}//end try
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
			userId: $this->userId,
			organisationUuid: $organisation?->getUuid()
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
}//end class
