<?php

/**
 * OpenRegister Chat Controller
 *
 * Controller for handling AI chat API endpoints.
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
 * @spec openspec/specs/chat-ai/spec.md
 * @spec openspec/specs/chat-ai/spec.md
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Controller\Support\ChatControllerServices;
use OCA\OpenRegister\Controller\Support\ChatFamilyControllerInterface;
use OCA\OpenRegister\Db\Feedback;
use OCA\OpenRegister\Db\FeedbackMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ConversationMapperInterface;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ChatController handles AI chat API endpoints
 *
 * Controller for handling AI chat API endpoints with conversation-based chat system.
 * Provides endpoints for creating conversations, sending messages, managing agents,
 * and handling feedback.
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
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ChatController extends Controller implements ChatFamilyControllerInterface {

	/**
	 * Chat service
	 *
	 * Handles AI chat logic and LLM interactions.
	 *
	 * @var ChatServiceInterface Chat service instance
	 */
	private readonly ChatServiceInterface $chatService;

	/**
	 * Conversation mapper
	 *
	 * Handles database operations for conversation entities.
	 *
	 * @var ConversationMapperInterface Conversation mapper instance
	 */
	private readonly ConversationMapperInterface $conversationMapper;

	/**
	 * Message mapper
	 *
	 * Handles database operations for message entities.
	 *
	 * @var MessageMapperInterface Message mapper instance
	 */
	private readonly MessageMapperInterface $messageMapper;

	/**
	 * Feedback mapper
	 *
	 * Handles database operations for feedback entities.
	 *
	 * @var FeedbackMapperInterface Feedback mapper instance
	 */
	private readonly FeedbackMapperInterface $feedbackMapper;

	/**
	 * Agent mapper
	 *
	 * Handles database operations for agent entities.
	 *
	 * @var AgentMapperInterface Agent mapper instance
	 */
	private readonly AgentMapperInterface $agentMapper;

	/**
	 * Database connection
	 *
	 * Used for direct database operations when needed.
	 *
	 * @var IDBConnection Database connection instance
	 */
	private readonly IDBConnection $db;

	/**
	 * Organisation service
	 *
	 * Handles organisation-related operations and permissions.
	 *
	 * @var OrganisationServiceInterface Organisation service instance
	 */
	private readonly OrganisationServiceInterface $organisationService;

	/**
	 * Logger
	 *
	 * Used for logging chat operations, errors, and debug information.
	 *
	 * @var LoggerInterface Logger instance
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Localization service
	 *
	 * Used for translating user-facing messages.
	 *
	 * @var IL10N Localization instance
	 */
	private readonly IL10N $l10n;

	/**
	 * User ID
	 *
	 * Current user ID for chat context and permissions.
	 *
	 * @var string User ID
	 */
	private readonly string $userId;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for chat operations.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param IRequest $request HTTP request object
	 * @param ChatControllerServices $services The chat controller services.
	 * @param string $userId Current user ID for chat context
	 *
	 * @return void
	 */
	public function __construct(IRequest $request, ChatControllerServices $services, string $userId) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

		// Store dependencies for use in controller methods.
		$this->chatService = $services->domain->chatService;
		$this->conversationMapper = $services->mappers->conversationMapper;
		$this->messageMapper = $services->mappers->messageMapper;
		$this->feedbackMapper = $services->mappers->feedbackMapper;
		$this->agentMapper = $services->mappers->agentMapper;
		$this->organisationService = $services->domain->organisationService;
		// Store remaining dependencies.
		$this->db = $services->platform->db;
		$this->logger = $services->platform->logger;
		$this->l10n = $services->platform->l10n;
		$this->userId = $userId;
	}//end __construct()

	/**
	 * Extract and normalize request parameters for sending a message.
	 *
	 * Extracts conversation UUID, agent UUID, message content, selected views,
	 * selected tools, and RAG settings from the request.
	 *
	 * @return array Normalized request parameters
	 *
	 * @psalm-return   array{conversationUuid: string, agentUuid: string,
	 *     message: string, selectedViews: array, selectedTools: array,
	 *     ragSettings: array{includeObjects: bool|mixed, includeFiles: bool|mixed,
	 *     numSourcesFiles: int|mixed, numSourcesObjects: int|mixed}}
	 * @phpstan-return array{conversationUuid: string, agentUuid: string,
	 *     message: string, selectedViews: array, selectedTools: array,
	 *     ragSettings: array{includeObjects: bool|mixed, includeFiles: bool|mixed,
	 *     numSourcesFiles: int|mixed, numSourcesObjects: int|mixed}}
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function extractMessageRequestParams(): array {
		return [
			'conversationUuid' => (string)$this->request->getParam('conversation'),
			'agentUuid' => (string)$this->request->getParam('agentUuid'),
			'message' => (string)$this->request->getParam('message'),
			'selectedViews' => $this->arrayParam(name: 'views'),
			'selectedTools' => $this->arrayParam(name: 'tools'),
			'ragSettings' => $this->ragSettings(),
			// Optional CnAiContext snapshot (the user's current scope); anything
			// that is not an array is dropped — the column defaults to '{}'.
			'context' => $this->arrayParam(name: 'context'),
		];
	}//end extractMessageRequestParams()

	/**
	 * A request parameter that must be an array; anything else reads as empty.
	 *
	 * @param string $name The parameter name
	 *
	 * @return array The parameter, or [] when it is missing or not an array
	 */
	private function arrayParam(string $name): array {
		$value = $this->request->getParam($name);
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayParam()

	/**
	 * The RAG configuration of a message request, with its defaults.
	 *
	 * @return array{includeObjects: mixed, includeFiles: mixed, numSourcesFiles: mixed, numSourcesObjects: mixed}
	 */
	private function ragSettings(): array {
		return [
			'includeObjects' => $this->request->getParam('includeObjects') ?? true,
			'includeFiles' => $this->request->getParam('includeFiles') ?? true,
			'numSourcesFiles' => $this->request->getParam('numSourcesFiles') ?? 5,
			'numSourcesObjects' => $this->request->getParam('numSourcesObjects') ?? 5,
		];
	}//end ragSettings()

	/**
	 * Load an existing conversation by UUID.
	 *
	 * @param string $uuid Conversation UUID
	 *
	 * @return Conversation The conversation entity
	 *
	 * @throws Exception If conversation not found
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function loadExistingConversation(string $uuid): Conversation {
		try {
			return $this->conversationMapper->findByUuid($uuid);
		} catch (Exception $e) {
			throw new Exception('The conversation with UUID ' . $uuid . ' does not exist', 404);
		}
	}//end loadExistingConversation()

	/**
	 * Create a new conversation with the specified agent.
	 *
	 * @param string $agentUuid Agent UUID
	 *
	 * @return Conversation The newly created conversation
	 *
	 * @throws Exception If agent not found
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function createNewConversation(string $agentUuid): Conversation {
		$organisation = $this->organisationService->getActiveOrganisation();
		$agent        = $this->findAgent(agentUuid: $agentUuid);
		$defaultTitle = $this->chatService->ensureUniqueTitle(baseTitle: 'New Conversation', userId: $this->userId, agentId: $agent->getId());

		$conversation = new Conversation();
		$conversation->setUserId($this->userId);
		$conversation->setOrganisation($organisation?->getUuid());
		$conversation->setAgentId($agent->getId());
		$conversation->setTitle($defaultTitle);
		$conversation = $this->conversationMapper->insert($conversation);
		$this->logConversationCreated(conversation: $conversation, agentId: $agent->getId());

		return $conversation;
	}//end createNewConversation()

	/**
	 * Log a conversation created for a message.
	 *
	 * @param Conversation $conversation The inserted conversation
	 * @param int          $agentId      The agent it was created for
	 *
	 * @return void
	 */
	private function logConversationCreated(Conversation $conversation, int $agentId): void {
		$this->logger->info(
			message: '[ChatController] New conversation created',
			context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $conversation->getUuid(),
				'userId' => $this->userId, 'agentId' => $agentId, 'title' => $conversation->getTitle()]
		);
	}//end logConversationCreated()

	/**
	 * Look up an agent by UUID.
	 *
	 * @param string $agentUuid Agent UUID
	 *
	 * @return Entity The agent
	 *
	 * @throws Exception With code 404 when the agent does not exist
	 */
	private function findAgent(string $agentUuid): Entity {
		try {
			return $this->agentMapper->findByUuid($agentUuid);
		} catch (Exception $e) {
			throw new Exception('The agent with UUID ' . $agentUuid . ' does not exist', 404);
		}
	}//end findAgent()

	/**
	 * Resolve conversation from UUID or create new one with agent.
	 *
	 * @param string $conversationUuid Conversation UUID (empty if creating new)
	 * @param string $agentUuid Agent UUID (empty if using existing conversation)
	 *
	 * @return Conversation The resolved or created conversation
	 *
	 * @throws Exception If both parameters are empty or if entities not found
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function resolveConversation(string $conversationUuid, string $agentUuid): Conversation {
		// Load existing conversation by UUID.
		if (empty($conversationUuid) === false) {
			return $this->loadExistingConversation(uuid: $conversationUuid);
		}

		// Create new conversation with specified agent.
		if (empty($agentUuid) === false) {
			return $this->createNewConversation(agentUuid: $agentUuid);
		}

		// Neither parameter provided.
		throw new Exception('Either conversation or agentUuid is required', 400);
	}//end resolveConversation()

	/**
	 * Verify that the current user has access to the conversation.
	 *
	 * @param Conversation $conversation The conversation to check
	 *
	 * @return void
	 *
	 * @throws Exception If user does not have access (403)
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function verifyConversationAccess(Conversation $conversation): void {
		if ($conversation->getUserId() !== $this->userId) {
			throw new Exception('You do not have access to this conversation', 403);
		}
	}//end verifyConversationAccess()

	/**
	 * Send a chat message in a conversation and get AI response
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress InvalidReturnStatement
	 * @psalm-suppress InvalidReturnType
	 *
	 * @return JSONResponse JSON response with AI response or error
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function sendMessage(): JSONResponse {
		try {
			$params = $this->extractMessageRequestParams();
			if (empty($params['message']) === true) {
				return new JSONResponse(
					data: $this->errorBody(error: $this->l10n->t('Missing message'), message: $this->l10n->t('message content is required')),
					statusCode: 400
				);
			}

			$this->logReceived(params: $params);
			$conversation = $this->resolveConversation(conversationUuid: $params['conversationUuid'], agentUuid: $params['agentUuid']);
			$this->verifyConversationAccess(conversation: $conversation);

			return new JSONResponse(data: $this->processMessage(conversation: $conversation, params: $params), statusCode: 200);
		} catch (Exception $e) {
			return $this->sendMessageFailure(error: $e);
		}
	}//end sendMessage()

	/**
	 * Log the settings a message arrived with.
	 *
	 * @param array $params The normalised request (extractMessageRequestParams)
	 *
	 * @return void
	 */
	private function logReceived(array $params): void {
		$this->logger->info(
			message: '[ChatController] Received message with settings',
			context: ['file' => __FILE__, 'line' => __LINE__, 'views' => count($params['selectedViews']),
				'tools' => count($params['selectedTools']), 'ragSettings' => $params['ragSettings']]
		);
	}//end logReceived()

	/**
	 * Run a message through the ChatService.
	 *
	 * @param Conversation $conversation The conversation the caller may use
	 * @param array        $params       The normalised request (extractMessageRequestParams)
	 *
	 * @return array The ChatService result plus the conversation UUID
	 */
	private function processMessage(Conversation $conversation, array $params): array {
		$result = $this->chatService->processMessage(
			conversationId: $conversation->getId(),
			userId: $this->userId,
			userMessage: $params['message'],
			selectedViews: $params['selectedViews'],
			selectedTools: $params['selectedTools'],
			ragSettings: $params['ragSettings'],
			context: $params['context']
		);
		$result['conversation'] = $conversation->getUuid();

		return $result;
	}//end processMessage()

	/**
	 * The response to a failed sendMessage: the exception code when it is an
	 * HTTP error status, else 500.
	 *
	 * @param Exception $error The failure
	 *
	 * @return JSONResponse The error response
	 *
	 * @psalm-suppress InvalidArgument
	 */
	private function sendMessageFailure(Exception $error): JSONResponse {
		$statusCode = (int)$error->getCode();
		if ($statusCode < 400 || $statusCode >= 600) {
			$statusCode = 500;
		}

		$this->logFailure(message: 'Failed to send message', context: $this->traced(line: __LINE__, error: $error));

		return new JSONResponse(
			data: $this->errorBody(error: $this->sendMessageErrorType(statusCode: $statusCode), message: $error->getMessage()),
			statusCode: $statusCode
		);
	}//end sendMessageFailure()

	/**
	 * The error label of a failed sendMessage status.
	 *
	 * @param int $statusCode The HTTP status
	 *
	 * @return string The translated label
	 */
	private function sendMessageErrorType(int $statusCode): string {
		return match ($statusCode) {
			400 => $this->l10n->t('Missing conversation or agentUuid'),
			403 => $this->l10n->t('Access denied'),
			404 => $this->l10n->t('Conversation not found'),
			503 => $this->l10n->t('AI service not configured'),
			default => $this->l10n->t('Failed to process message'),
		};
	}//end sendMessageErrorType()

	/**
	 * Get conversation history (messages)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse A JSON response with conversation history or error
	 * @psalm-return JSONResponse<200|400|403|500,
	 *     array{error?: 'Access denied'|'Failed to fetch conversation history'|
	 *     'Missing conversationId', message?: string,
	 *     messages?: list<array{content: null|string, conversationId: int|null,
	 *     created: null|string, id: int, role: null|string, sources: array|null,
	 *     uuid: null|string}>, total?: int, conversationId?: int},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function getHistory(): JSONResponse {
		try {
			$conversationId = (int)$this->request->getParam('conversationId');
			$refused        = $this->refuseConversation(conversationId: $conversationId);
			if ($refused !== null) {
				return $refused;
			}

			return new JSONResponse(data: $this->historyPage(conversationId: $conversationId), statusCode: 200);
		} catch (Exception $e) {
			$this->logFailure(message: 'Failed to get history', context: $this->traced(line: __LINE__, error: $e));
			return new JSONResponse(
				data: $this->errorBody(error: $this->l10n->t('Failed to fetch conversation history'), message: $e->getMessage()),
				statusCode: 500
			);
		}
	}//end getHistory()

	/**
	 * One page of a conversation's messages (`limit`, default 100, and `offset`).
	 *
	 * @param int $conversationId The conversation id
	 *
	 * @return array{messages: array, total: int, conversationId: int}
	 */
	private function historyPage(int $conversationId): array {
		$messages = $this->messageMapper->findByConversation(
			conversationId: $conversationId,
			page: new ListQuery(limit: (int)($this->request->getParam('limit') ?? 100), offset: (int)($this->request->getParam('offset') ?? 0))
		);

		return [
			'messages' => array_map(fn ($msg) => $msg->jsonSerialize(), $messages),
			'total' => $this->messageMapper->countByConversation($conversationId),
			'conversationId' => $conversationId,
		];
	}//end historyPage()

	/**
	 * Refuse a request on a conversation id that is missing (400) or on a
	 * conversation the current user does not own (403).
	 *
	 * @param int $conversationId The requested conversation id
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed
	 *
	 * @throws Exception When the conversation cannot be loaded
	 */
	private function refuseConversation(int $conversationId): ?JSONResponse {
		if (empty($conversationId) === true) {
			return new JSONResponse(
				data: $this->errorBody(error: $this->l10n->t('Missing conversationId'), message: $this->l10n->t('conversationId is required')),
				statusCode: 400
			);
		}

		if ($this->conversationMapper->find($conversationId)->getUserId() !== $this->userId) {
			return $this->accessDenied();
		}

		return null;
	}//end refuseConversation()

	/**
	 * The 403 response for a conversation the current user does not own.
	 *
	 * @return JSONResponse The refusal
	 */
	private function accessDenied(): JSONResponse {
		return new JSONResponse(
			data: $this->errorBody(error: $this->l10n->t('Access denied'), message: $this->l10n->t('You do not have access to this conversation')),
			statusCode: 403
		);
	}//end accessDenied()

	/**
	 * Clear conversation history (soft delete)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse A JSON response confirming conversation clearing or error
	 * @psalm-return JSONResponse<200|400|403|500,
	 *     array{error?: 'Access denied'|'Failed to clear conversation'|
	 *     'Missing conversationId', message: string, conversationId?: int},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function clearHistory(): JSONResponse {
		try {
			$conversationId = (int)$this->request->getParam('conversationId');
			$refused        = $this->refuseConversation(conversationId: $conversationId);
			if ($refused !== null) {
				return $refused;
			}

			return $this->softDelete(conversationId: $conversationId);
		} catch (Exception $e) {
			$this->logFailure(message: 'Failed to clear history', context: $this->traced(line: __LINE__, error: $e));
			return new JSONResponse(
				data: $this->errorBody(error: $this->l10n->t('Failed to clear conversation'), message: $e->getMessage()),
				statusCode: 500
			);
		}
	}//end clearHistory()

	/**
	 * Soft delete a conversation the caller owns.
	 *
	 * @param int $conversationId The conversation id
	 *
	 * @return JSONResponse The success response
	 */
	private function softDelete(int $conversationId): JSONResponse {
		$this->conversationMapper->softDelete($conversationId);
		$this->logger->info(
			message: '[ChatController] Conversation cleared (soft deleted)',
			context: ['file' => __FILE__, 'line' => __LINE__, 'conversationId' => $conversationId, 'userId' => $this->userId]
		);

		return new JSONResponse(
			data: ['message' => $this->l10n->t('Conversation cleared successfully'), 'conversationId' => $conversationId],
			statusCode: 200
		);
	}//end softDelete()

	/**
	 * Submit or update feedback on a message
	 *
	 * Endpoint: POST /api/conversations/{conversationUuid}/messages/{messageId}/feedback
	 *
	 * @param string $conversationUuid Conversation UUID
	 * @param int $messageId Message ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with feedback confirmation or error
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function sendFeedback(string $conversationUuid, int $messageId): JSONResponse {
		try {
			return $this->recordFeedback(conversationUuid: $conversationUuid, messageId: $messageId);
		} catch (Exception $e) {
			return $this->feedbackFailure(error: $e, ids: ['conversationUuid' => $conversationUuid, 'messageId' => $messageId]);
		}
	}//end sendFeedback()

	/**
	 * Validate and save feedback on a message: the type must be positive or
	 * negative and the conversation must be the current user's.
	 *
	 * @param string $conversationUuid Conversation UUID
	 * @param int    $messageId        Message ID
	 *
	 * @return JSONResponse The saved feedback or the refusal
	 *
	 * @throws Exception When a lookup or the save fails
	 */
	private function recordFeedback(string $conversationUuid, int $messageId): JSONResponse {
		$type    = (string)$this->request->getParam('type');
		$comment = (string)$this->request->getParam('comment', '');
		if (in_array($type, ['positive', 'negative'], true) === false) {
			return $this->feedbackError(
				texts: [$this->l10n->t('Invalid feedback type'), $this->l10n->t('type must be "positive" or "negative"')],
				statusCode: 400
			);
		}

		$conversation = $this->conversationMapper->findByUuid($conversationUuid);
		if ($conversation->getUserId() !== $this->userId) {
			return $this->accessDenied();
		}

		return $this->saveFeedback(conversation: $conversation, input: ['messageId' => $messageId, 'type' => $type, 'comment' => $comment]);
	}//end recordFeedback()

	/**
	 * Create or update the current user's feedback on a message of a
	 * conversation they own.
	 *
	 * @param Conversation $conversation The conversation
	 * @param array        $input        The messageId, type (positive or negative) and comment
	 *
	 * @return JSONResponse The saved feedback, or 404 when the message is not in the conversation
	 */
	private function saveFeedback(Conversation $conversation, array $input): JSONResponse {
		if ($this->messageMapper->find($input['messageId'])->getConversationId() !== $conversation->getId()) {
			return $this->feedbackError(
				texts: [$this->l10n->t('Message not found'), $this->l10n->t('Message does not belong to this conversation')],
				statusCode: 404
			);
		}

		$input['organisation'] = $this->organisationService->getActiveOrganisation()?->getUuid();
		$existingFeedback      = $this->feedbackMapper->findByMessage(messageId: $input['messageId'], userId: $this->userId);
		if ($existingFeedback !== null) {
			return new JSONResponse(data: $this->updateFeedback(feedback: $existingFeedback, input: $input)->jsonSerialize(), statusCode: 200);
		}

		return new JSONResponse(data: $this->createFeedback(conversation: $conversation, input: $input)->jsonSerialize(), statusCode: 200);
	}//end saveFeedback()

	/**
	 * Build a feedback error response from a translated error and message.
	 *
	 * @param array{0: string, 1: string} $texts      The translated error and message.
	 * @param int                         $statusCode The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function feedbackError(array $texts, int $statusCode): JSONResponse {
		return new JSONResponse(data: $this->errorBody(error: $texts[0], message: $texts[1]), statusCode: $statusCode);
	}//end feedbackError()

	/**
	 * An error body with an error label and a message.
	 *
	 * @param string $error   The error label
	 * @param string $message The message
	 *
	 * @return array{error: string, message: string} The response body
	 */
	private function errorBody(string $error, string $message): array {
		return ['error' => $error, 'message' => $message];
	}//end errorBody()

	/**
	 * Log context for a failure: line, error message and trace.
	 *
	 * @param int       $line  The line the failure was caught on
	 * @param Exception $error The failure
	 *
	 * @return array<string, mixed> The context
	 */
	private function traced(int $line, Exception $error): array {
		return ['line' => $line, 'error' => $error->getMessage(), 'trace' => $error->getTraceAsString()];
	}//end traced()

	/**
	 * Log an error of this controller.
	 *
	 * @param string               $message The message, without the controller prefix
	 * @param array<string, mixed> $context The context, without the file
	 *
	 * @return void
	 */
	private function logFailure(string $message, array $context): void {
		$this->logger->error(message: '[ChatController] '.$message, context: ['file' => __FILE__] + $context);
	}//end logFailure()

	/**
	 * Update the acting user's existing feedback on a message.
	 *
	 * @param Feedback                                                                         $feedback The existing feedback.
	 * @param array{messageId: int, type: string, comment: string, organisation: string|null} $input    The submitted feedback.
	 *
	 * @return Feedback The updated feedback.
	 */
	private function updateFeedback(Feedback $feedback, array $input): Feedback {
		$feedback->setType($input['type']);
		$feedback->setComment($input['comment']);

		$feedback = $this->feedbackMapper->update($feedback);
		$this->logFeedbackSaved(feedback: $feedback, input: $input + ['action' => 'updated']);

		return $feedback;
	}//end updateFeedback()

	/**
	 * Create the acting user's feedback on a message.
	 *
	 * @param Conversation                                                                     $conversation The conversation the message belongs to.
	 * @param array{messageId: int, type: string, comment: string, organisation: string|null} $input        The submitted feedback.
	 *
	 * @return Feedback The created feedback.
	 */
	private function createFeedback(Conversation $conversation, array $input): Feedback {
		$feedback = new Feedback();
		$feedback->setMessageId($input['messageId']);
		$feedback->setConversationId($conversation->getId());
		$feedback->setAgentId($conversation->getAgentId() ?? 0);
		$feedback->setUserId($this->userId);
		$feedback->setOrganisation($input['organisation']);
		$feedback->setType($input['type']);
		$feedback->setComment($input['comment']);

		$feedback = $this->feedbackMapper->insert($feedback);
		$this->logFeedbackSaved(feedback: $feedback, input: $input + ['action' => 'created']);

		return $feedback;
	}//end createFeedback()

	/**
	 * Log that feedback was created or updated.
	 *
	 * @param Feedback                                                    $feedback The saved feedback.
	 * @param array{messageId: int, type: string, comment: string, action: string} $input    The submitted feedback and what was done with it.
	 *
	 * @return void
	 */
	private function logFeedbackSaved(Feedback $feedback, array $input): void {
		$this->logger->info(
			message: '[ChatController] Message feedback ' . $input['action'],
			context: ['file' => __FILE__, 'line' => __LINE__, 'feedbackId' => $feedback->getId(), 'messageId' => $input['messageId'],
				'type' => $input['type'], 'hasComment' => empty($input['comment']) === false]
		);
	}//end logFeedbackSaved()

	/**
	 * Log a failed feedback save and build its 500 response.
	 *
	 * @param Exception                                       $error The failure.
	 * @param array{conversationUuid: string, messageId: int} $ids   The requested conversation and message.
	 *
	 * @return JSONResponse
	 */
	private function feedbackFailure(Exception $error, array $ids): JSONResponse {
		$this->logFailure(message: 'Failed to save feedback', context: ['line' => __LINE__] + $ids + $this->traced(line: __LINE__, error: $error));
		return new JSONResponse(
			data: $this->errorBody(error: $this->l10n->t('Failed to save feedback'), message: $error->getMessage()),
			statusCode: 500
		);
	}//end feedbackFailure()

	/**
	 * Get chat statistics
	 *
	 * Statistics are scoped to the current session's active organisation so
	 * that one tenant never sees another tenant's agent/conversation/message
	 * counts. When there is no active organisation (e.g. a CLI/system
	 * context), no organisation filter is applied — the same "filter only
	 * if provided" convention used by ConversationMapper::countByUser() and
	 * ConversationMapper::findByUser() above.
	 *
	 * Messages have no organisation column of their own (see
	 * openregister_messages schema), so they are scoped indirectly via the
	 * conversation_id foreign key to the conversations already scoped to
	 * the active organisation.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body: scopes every count to the caller's active organisation (OrganisationService::getActiveOrganisation)
	 *   via an organisation = ? filter on agents and conversations; no caller-supplied object id.
	 *
	 * @return JSONResponse Chat statistics
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{error?: 'Failed to get chat statistics', message?: string,
	 *     total_agents?: int, total_conversations?: int, total_messages?: int},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function getChatStats(): JSONResponse {
		try {
			// Every count is scoped to the active organisation. Without one there
			// is nothing this caller may count: zeros, never instance-wide totals
			// (or-chat-engine-decommission REQ "Usage statistics are
			// organisation-scoped, never instance-wide").
			$organisationUuid = $this->organisationService->getActiveOrganisation()?->getUuid();
			if ($organisationUuid === null) {
				return new JSONResponse(data: ['total_agents' => 0, 'total_conversations' => 0, 'total_messages' => 0], statusCode: 200);
			}

			return new JSONResponse(data: $this->organisationStats(organisationUuid: $organisationUuid), statusCode: 200);
		} catch (Exception $e) {
			$this->logFailure(message: 'Failed to get chat stats', context: ['line' => __LINE__, 'error' => $e->getMessage()]);
			return new JSONResponse(
				data: $this->errorBody(error: $this->l10n->t('Failed to get chat statistics'), message: $e->getMessage()),
				statusCode: 500
			);
		}
	}//end getChatStats()

	/**
	 * The agent, conversation and message counts of one organisation.
	 *
	 * Messages have no organisation column of their own, so they are scoped
	 * via the conversation ids of the organisation.
	 *
	 * @param string $organisationUuid The organisation
	 *
	 * @return array{total_agents: int, total_conversations: int, total_messages: int}
	 */
	private function organisationStats(string $organisationUuid): array {
		return [
			'total_agents' => $this->countByOrganisation(table: 'openregister_agents', organisationUuid: $organisationUuid),
			'total_conversations' => $this->countByOrganisation(table: 'openregister_conversations', organisationUuid: $organisationUuid),
			'total_messages' => $this->countMessagesIn(
				conversationIds: $this->organisationConversationIds(organisationUuid: $organisationUuid)
			),
		];
	}//end organisationStats()

	/**
	 * Count the rows of a table that belong to an organisation.
	 *
	 * @param string $table            The table with an `organisation` column.
	 * @param string $organisationUuid The organisation UUID.
	 *
	 * @return int The number of rows.
	 */
	private function countByOrganisation(string $table, string $organisationUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'total'))
			->from($table)
			->where(
				$qb->expr()->eq('organisation', $qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR))
			);

		return (int)$qb->executeQuery()->fetchOne();
	}//end countByOrganisation()

	/**
	 * The ids of the conversations that belong to an organisation.
	 *
	 * @param string $organisationUuid The organisation UUID.
	 *
	 * @return array<int, int> The conversation ids.
	 */
	private function organisationConversationIds(string $organisationUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('openregister_conversations')
			->where(
				$qb->expr()->eq('organisation', $qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR))
			);

		$result = $qb->executeQuery();
		$conversationIds = [];
		while (($row = $result->fetch()) !== false) {
			$conversationIds[] = (int)$row['id'];
		}

		$result->closeCursor();

		return $conversationIds;
	}//end organisationConversationIds()

	/**
	 * Count the messages of the given conversations.
	 *
	 * An empty id list skips the query rather than issuing an invalid empty
	 * IN (...) clause.
	 *
	 * @param array<int, int> $conversationIds The conversation ids.
	 *
	 * @return int The number of messages.
	 */
	private function countMessagesIn(array $conversationIds): int {
		if (empty($conversationIds) === true) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'total'))
			->from('openregister_messages')
			->where(
				$qb->expr()->in(
					'conversation_id',
					$qb->createNamedParameter($conversationIds, IQueryBuilder::PARAM_INT_ARRAY)
				)
			);

		return (int)$qb->executeQuery()->fetchOne();
	}//end countMessagesIn()
}//end class
