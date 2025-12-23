<?php
/**
 * OpenRegister Conversation Controller
 *
 * Controller for handling AI conversation API endpoints.
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
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ChatService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use DateTime;
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
 */

class ConversationController extends Controller
{

    /**
     * Conversation mapper
     *
     * @var ConversationMapper
     */
    private ConversationMapper $conversationMapper;

    /**
     * Message mapper
     *
     * @var MessageMapper
     */
    private MessageMapper $messageMapper;

    /**
     * Feedback mapper
     *
     * @var FeedbackMapper
     */
    private FeedbackMapper $feedbackMapper;

    /**
     * Agent mapper
     *
     * @var AgentMapper
     */
    private AgentMapper $agentMapper;

    /**
     * Organisation service
     *
     * @var OrganisationService
     */
    private OrganisationService $organisationService;

    /**
     * Chat service
     *
     * @var ChatService
     */
    private ChatService $chatService;

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
     * @param string              $appName             Application name
     * @param IRequest            $request             Request object
     * @param ConversationMapper  $conversationMapper  Conversation mapper
     * @param MessageMapper       $messageMapper       Message mapper
     * @param FeedbackMapper      $feedbackMapper      Feedback mapper
     * @param AgentMapper         $agentMapper         Agent mapper
     * @param OrganisationService $organisationService Organisation service
     * @param ChatService         $chatService         Chat service
     * @param LoggerInterface     $logger              Logger
     * @param string              $userId              User ID
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        FeedbackMapper $feedbackMapper,
        AgentMapper $agentMapper,
        OrganisationService $organisationService,
        ChatService $chatService,
        LoggerInterface $logger,
        string $userId
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->conversationMapper  = $conversationMapper;
        $this->messageMapper       = $messageMapper;
        $this->feedbackMapper      = $feedbackMapper;
        $this->agentMapper         = $agentMapper;
        $this->organisationService = $organisationService;
        $this->chatService         = $chatService;
        $this->logger = $logger;
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
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to fetch conversations', message?: string, results?: list<array{agentId: int|null, created: null|string, deletedAt: null|string, id: int, metadata: array|null, organisation: null|string, title: null|string, updated: null|string, userId: null|string, uuid: null|string}>, total?: int, limit?: int, offset?: int}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        try {
            // Get active organisation.
            $organisation     = $this->organisationService->getActiveOrganisation();
            $organisationUuid = $organisation?->getUuid();

            // Get query parameters.
            $params      = $this->request->getParams();
            $limit       = (int) ($params['limit'] ?? $params['_limit'] ?? 50);
            $offset      = (int) ($params['offset'] ?? $params['_offset'] ?? 0);
            $showDeleted = filter_var($params['_deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);

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
            } else {
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
            }//end if

            return new JSONResponse(
                data: [
                    'results' => array_map(fn($conv) => $conv->jsonSerialize(), $conversations),
                    'total'   => $total,
                    'limit'   => $limit,
                    'offset'  => $offset,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[ConversationController] Failed to list conversations',
                    context: [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to fetch conversations',
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
     * @psalm-return JSONResponse<int, array{error?: 'Access denied'|'Conversation not found'|'Failed to fetch conversation', message?: string, id?: int, uuid?: null|string, title?: null|string, userId?: null|string, organisation?: null|string, agentId?: int|null, metadata?: array|null, deletedAt?: null|string, created?: null|string, updated?: null|string, messageCount?: int}, array<never, never>>
     */
    public function show(string $uuid): JSONResponse
    {
        try {
            // Find conversation.
            $conversation = $this->conversationMapper->findByUuid($uuid);

            // Get active organisation.
            $organisation     = $this->organisationService->getActiveOrganisation();
            $organisationUuid = $organisation?->getUuid();

            // Validate Check access rights using method.
            if ($this->conversationMapper->canUserAccessConversation(
                conversation: $conversation,
                userId: $this->userId,
                organisationUuid: $organisationUuid
            ) === false
            ) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Access denied',
                        'message' => 'You do not have access to this conversation',
                    ],
                    statusCode: 403
                );
            }

            // Build response without messages.
            $response = $conversation->jsonSerialize();
            // Get message count separately for efficiency.
            $response['messageCount'] = $this->messageMapper->countByConversation($conversation->getId());

            return new JSONResponse(data: $response, statusCode: 200);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error'   => 'Conversation not found',
                    'message' => 'The requested conversation does not exist',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[ConversationController] Failed to get conversation',
                    context: [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'error'   => 'Failed to fetch conversation',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
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
     * @psalm-return JSONResponse<int, array{error?: 'Access denied'|'Conversation not found'|'Failed to fetch messages', message?: string, results?: list<array{content: null|string, conversationId: int|null, created: null|string, id: int, role: null|string, sources: array|null, uuid: null|string}>, total?: int, limit?: int, offset?: int}, array<never, never>>
     */
    public function messages(string $uuid): JSONResponse
    {
        try {
            // Find conversation.
            $conversation = $this->conversationMapper->findByUuid($uuid);

            // Get active organisation.
            $organisation     = $this->organisationService->getActiveOrganisation();
            $organisationUuid = $organisation?->getUuid();

            // Validate Check access rights using method.
            if ($this->conversationMapper->canUserAccessConversation(
                conversation: $conversation,
                userId: $this->userId,
                organisationUuid: $organisationUuid
            ) === false
            ) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Access denied',
                        'message' => 'You do not have access to this conversation',
                    ],
                    statusCode: 403
                );
            }

            // Get query parameters for pagination.
            $params = $this->request->getParams();
            $limit  = (int) ($params['limit'] ?? $params['_limit'] ?? 50);
            $offset = (int) ($params['offset'] ?? $params['_offset'] ?? 0);

            // Get messages with pagination.
            $messages = $this->messageMapper->findByConversation(
                conversationId: $conversation->getId(),
                limit: $limit,
                offset: $offset
            );

            // Get total count.
            $total = $this->messageMapper->countByConversation($conversation->getId());

            return new JSONResponse(
                data: [
                    'results' => array_map(fn($msg) => $msg->jsonSerialize(), $messages),
                    'total'   => $total,
                    'limit'   => $limit,
                    'offset'  => $offset,
                ],
                statusCode: 200
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error'   => 'Conversation not found',
                    'message' => 'The requested conversation does not exist',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[ConversationController] Failed to get messages',
                    context: [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'error'   => 'Failed to fetch messages',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
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
     */
    public function create(): JSONResponse
    {
        try {
            // Get request data.
            $data = $this->request->getParams();

            // Get active organisation.
            $organisation = $this->organisationService->getActiveOrganisation();

            // Get agent ID (handle both agentId and agentUuid).
            $agentId = null;
            if (($data['agentId'] ?? null) !== null) {
                $agentId = $data['agentId'];
            } else if (($data['agentUuid'] ?? null) !== null) {
                // Look up agent by UUID to get ID.
                try {
                    $agent   = $this->agentMapper->findByUuid($data['agentUuid']);
                    $agentId = $agent->getId();
                } catch (\Exception $e) {
                    // If agent not found, log and continue with null agentId.
                    $this->logger->warning(
                            message: '[ConversationController] Agent UUID not found',
                            context: [
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
                    '[ConversationController] Conversation created',
                    [
                        'uuid'         => $conversation->getUuid(),
                        'userId'       => $this->userId,
                        'organisation' => $organisation?->getUuid(),
                    ]
                    );

            return new JSONResponse(data: $conversation->jsonSerialize(), statusCode: 201);
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[ConversationController] Failed to create conversation',
                    context: [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'error'   => 'Failed to create conversation',
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
     * @psalm-return JSONResponse<int, array{error?: 'Access denied'|'Conversation not found'|'Failed to update conversation', message?: string, id?: int, uuid?: null|string, title?: null|string, userId?: null|string, organisation?: null|string, agentId?: int|null, metadata?: array|null, deletedAt?: null|string, created?: null|string, updated?: null|string}, array<never, never>>
     */
    public function update(string $uuid): JSONResponse
    {
        try {
            // Find conversation.
            $conversation = $this->conversationMapper->findByUuid($uuid);

            // Check modify rights using mapper method.
            if ($this->conversationMapper->canUserModifyConversation(conversation: $conversation, userId: $this->userId) === false) {
                return new JSONResponse(
                        data: [
                            'error'   => 'Access denied',
                            'message' => 'You do not have permission to modify this conversation',
                        ],
                        statusCode: 403
                        );
            }

            // Get request data.
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

            // Save to database.
            $conversation = $this->conversationMapper->update($conversation);

            $this->logger->info(
                    '[ConversationController] Conversation updated',
                    [
                        'uuid' => $uuid,
                    ]
                    );

            return new JSONResponse(data: $conversation->jsonSerialize(), statusCode: 200);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error'   => 'Conversation not found',
                    'message' => 'The requested conversation does not exist',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[ConversationController] Failed to update conversation',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to update conversation',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
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
     * @psalm-return JSONResponse<int, array{error?: 'Access denied'|'Conversation not found'|'Failed to delete conversation', message: string, uuid?: string, archived?: true}, array<never, never>>
     */
    public function destroy(string $uuid): JSONResponse
    {
        try {
            // Find conversation.
            $conversation = $this->conversationMapper->findByUuid($uuid);

            // Check modify rights using mapper method.
            if ($this->conversationMapper->canUserModifyConversation(conversation: $conversation, userId: $this->userId) === false) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Access denied',
                        'message' => 'You do not have permission to delete this conversation',
                    ],
                    statusCode: 403
                );
            }

            // Check if already soft-deleted (archived).
            if ($conversation->getDeletedAt() !== null) {
                // Already archived - perform permanent delete.
                $this->logger->info(
                        '[ConversationController] Permanently deleting archived conversation',
                        [
                            'uuid' => $uuid,
                        ]
                        );

                // Delete feedback first.
                $this->feedbackMapper->deleteByConversation($conversation->getId());

                // Delete messages.
                $this->messageMapper->deleteByConversation($conversation->getId());

                // Delete conversation.
                $this->conversationMapper->delete($conversation);

                $this->logger->info(
                        '[ConversationController] Conversation permanently deleted',
                        [
                            'uuid' => $uuid,
                        ]
                        );

                return new JSONResponse(
                    data: [
                        'message' => 'Conversation permanently deleted',
                        'uuid'    => $uuid,
                    ],
                    statusCode: 200
                );
            } else {
                // First delete - perform soft delete (archive).
                $this->conversationMapper->softDelete($conversation->getId());

                $this->logger->info(
                        '[ConversationController] Conversation archived (soft deleted)',
                        [
                            'uuid' => $uuid,
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'message'  => 'Conversation archived successfully',
                            'uuid'     => $uuid,
                            'archived' => true,
                        ],
                        statusCode: 200
                    );
            }//end if
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error'   => 'Conversation not found',
                    'message' => 'The requested conversation does not exist',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[ConversationController] Failed to delete conversation',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to delete conversation',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
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
     * @psalm-return JSONResponse<int, array{error?: 'Access denied'|'Conversation not found'|'Failed to restore conversation', message?: string, id?: int, uuid?: null|string, title?: null|string, userId?: null|string, organisation?: null|string, agentId?: int|null, metadata?: array|null, deletedAt?: null|string, created?: null|string, updated?: null|string}, array<never, never>>
     */
    public function restore(string $uuid): JSONResponse
    {
        try {
            // Find conversation.
            $conversation = $this->conversationMapper->findByUuid($uuid);

            // Check modify rights using mapper method.
            if ($this->conversationMapper->canUserModifyConversation(conversation: $conversation, userId: $this->userId) === false) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Access denied',
                        'message' => 'You do not have permission to restore this conversation',
                    ],
                    statusCode: 403
                );
            }

            // Restore.
            $conversation = $this->conversationMapper->restore($conversation->getId());

            $this->logger->info(
                    '[ConversationController] Conversation restored',
                    [
                        'uuid' => $uuid,
                    ]
                    );

            return new JSONResponse(data: $conversation->jsonSerialize(), statusCode: 200);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error'   => 'Conversation not found',
                    'message' => 'The requested conversation does not exist',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[ConversationController] Failed to restore conversation',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to restore conversation',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
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
     * @psalm-return JSONResponse<int, array{error?: 'Access denied'|'Conversation not found'|'Failed to permanently delete conversation', message: string, uuid?: string}, array<never, never>>
     */
    public function destroyPermanent(string $uuid): JSONResponse
    {
        try {
            // Find conversation.
            $conversation = $this->conversationMapper->findByUuid($uuid);

            // Check modify rights using mapper method.
            if ($this->conversationMapper->canUserModifyConversation(conversation: $conversation, userId: $this->userId) === false) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Access denied',
                        'message' => 'You do not have permission to delete this conversation',
                    ],
                    statusCode: 403
                );
            }

            // Delete messages first.
            $this->messageMapper->deleteByConversation($conversation->getId());

            // Delete conversation.
            $this->conversationMapper->delete($conversation);

            $this->logger->info(
                    '[ConversationController] Conversation permanently deleted',
                    [
                        'uuid' => $uuid,
                    ]
                    );

            return new JSONResponse(
                data: [
                    'message' => 'Conversation permanently deleted',
                    'uuid'    => $uuid,
                ],
                statusCode: 200
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error'   => 'Conversation not found',
                    'message' => 'The requested conversation does not exist',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[ConversationController] Failed to permanently delete conversation',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to permanently delete conversation',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end destroyPermanent()
}//end class
