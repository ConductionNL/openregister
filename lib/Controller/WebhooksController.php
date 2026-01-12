<?php

/**
 * OpenRegister Webhooks Controller
 *
 * Controller for handling webhook management operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * WebhooksController handles webhook management operations
 *
 * @category Controller
 * @package  OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class WebhooksController extends Controller
{

    /**
     * Webhook mapper
     *
     * @var WebhookMapper
     */
    private WebhookMapper $webhookMapper;

    /**
     * Webhook service
     *
     * @var WebhookService
     */
    private WebhookService $webhookService;

    /**
     * Webhook log mapper
     *
     * @var WebhookLogMapper
     */
    private WebhookLogMapper $webhookLogMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param string           $appName          Application name
     * @param IRequest         $request          HTTP request
     * @param WebhookMapper    $webhookMapper    Webhook mapper
     * @param WebhookLogMapper $webhookLogMapper Webhook log mapper
     * @param WebhookService   $webhookService   Webhook service
     * @param LoggerInterface  $logger           Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        WebhookMapper $webhookMapper,
        WebhookLogMapper $webhookLogMapper,
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->webhookMapper    = $webhookMapper;
        $this->webhookLogMapper = $webhookLogMapper;
        $this->webhookService   = $webhookService;
        $this->logger           = $logger;
    }//end __construct()

    /**
     * List all webhooks
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         error?: 'Failed to list webhooks',
     *         results?: array<\OCA\OpenRegister\Db\Webhook>,
     *         total?: int<0, max>
     *     },
     *     array<never, never>
     * >
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        try {
            $webhooks = $this->webhookMapper->findAll();

            return new JSONResponse(
                data: [
                    'results' => $webhooks,
                    'total'   => count($webhooks),
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error listing webhooks: '.$e->getMessage(),
                context: [
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to list webhooks',
                ],
                statusCode: 500
            );
        }//end try
    }//end index()

    /**
     * Get a single webhook
     *
     * @param int $id Webhook ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200,
     *     \OCA\OpenRegister\Db\Webhook,
     *     array<never, never>
     * >|JSONResponse<
     *     404|500,
     *     array{error: 'Failed to retrieve webhook'|'Webhook not found'},
     *     array<never, never>
     * >
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): JSONResponse
    {
        try {
            $webhook = $this->webhookMapper->find($id);

            return new JSONResponse(data: $webhook);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Webhook not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving webhook: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve webhook',
                ],
                statusCode: 500
            );
        }//end try
    }//end show()

    /**
     * Create a new webhook
     *
     * @return JSONResponse JSON response with created webhook
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Validate required fields.
            if (empty($data['name']) === true || empty($data['url']) === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'Name and URL are required',
                    ],
                    statusCode: 400
                );
            }

            $webhook = $this->webhookMapper->createFromArray($data);

            $this->logger->info(
                message: 'Webhook created',
                context: [
                    'id'   => $webhook->getId(),
                    'name' => $webhook->getName(),
                    'url'  => $webhook->getUrl(),
                ]
            );

            return new JSONResponse(data: $webhook, statusCode: 201);
        } catch (\Exception $e) {
            $this->logger->error(
                'Error creating webhook: '.$e->getMessage(),
                [
                    'data'  => $this->request->getParams(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to create webhook: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end create()

    /**
     * Update an existing webhook
     *
     * @param int $id Webhook ID
     *
     * @return JSONResponse JSON response with updated webhook
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(int $id): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Remove ID from data if present.
            unset($data['id']);

            $webhook = $this->webhookMapper->updateFromArray(id: $id, data: $data);

            $this->logger->info(
                message: 'Webhook updated',
                context: [
                    'id'   => $webhook->getId(),
                    'name' => $webhook->getName(),
                ]
            );

            return new JSONResponse(data: $webhook);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Webhook not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error updating webhook: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'data'  => $this->request->getParams(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to update webhook: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end update()

    /**
     * Delete a webhook
     *
     * @param int $id Webhook ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     204,
     *     null,
     *     array<never, never>
     * >|JSONResponse<
     *     404|500,
     *     array{error: 'Failed to delete webhook'|'Webhook not found'},
     *     array<never, never>
     * >
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(int $id): JSONResponse
    {
        try {
            $webhook = $this->webhookMapper->find($id);
            $this->webhookMapper->delete($webhook);

            $this->logger->info(
                message: 'Webhook deleted',
                context: [
                    'id'   => $webhook->getId(),
                    'name' => $webhook->getName(),
                ]
            );

            return new JSONResponse(data: null, statusCode: 204);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Webhook not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error deleting webhook: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to delete webhook',
                ],
                statusCode: 500
            );
        }//end try
    }//end destroy()

    /**
     * Test a webhook by sending a test payload
     *
     * @param int $id Webhook ID
     *
     * @return JSONResponse JSON response with test result
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function test(int $id): JSONResponse
    {
        try {
            $webhook = $this->webhookMapper->find($id);

            $testPayload = [
                'test'      => true,
                'message'   => 'This is a test webhook from OpenRegister',
                'timestamp' => date('c'),
            ];

            $success = $this->webhookService->deliverWebhook(
                webhook: $webhook,
                eventName: 'OCA\OpenRegister\Event\TestEvent',
                payload: $testPayload,
                attempt: 1
            );

            if ($success === true) {
                return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Test webhook delivered successfully',
                    ]
                );
            }

            // Get the latest log entry to retrieve error details.
            $latestLogs   = $this->webhookLogMapper->findByWebhook(webhookId: $id, limit: 1, offset: 0);
            $errorMessage = 'Test webhook delivery failed';
            $errorDetails = null;

            if (empty($latestLogs) === false) {
                $latestLog = $latestLogs[0];
                if ($latestLog->getErrorMessage() !== null) {
                    $errorMessage = $latestLog->getErrorMessage();
                }

                if ($latestLog->getStatusCode() !== null) {
                    $errorDetails = [
                        'status_code'   => $latestLog->getStatusCode(),
                        'response_body' => $latestLog->getResponseBody(),
                    ];
                }
            }

            $responseData = [
                'success' => false,
                'message' => $errorMessage,
            ];

            if ($errorDetails !== null) {
                $responseData['error_details'] = $errorDetails;
            }

            return new JSONResponse(
                data: $responseData,
                statusCode: 500
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Webhook not found',
                ],
                statusCode: 404
            );
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Error testing webhook: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Test webhook delivery failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error testing webhook: '.$e->getMessage(),
                [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to test webhook: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end test()

    /**
     * List available events with metadata
     *
     * @return JSONResponse JSON response with available events
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function events(): JSONResponse
    {
        $events = [
            // Object events - Before events (ing).
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectCreatingEvent',
                'name'        => 'Object Creating',
                'description' => 'Triggered before an object is created',
                'category'    => 'Object',
                'type'        => 'before',
                'properties'  => ['object'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectUpdatingEvent',
                'name'        => 'Object Updating',
                'description' => 'Triggered before an object is updated',
                'category'    => 'Object',
                'type'        => 'before',
                'properties'  => ['newObject', 'oldObject'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectDeletingEvent',
                'name'        => 'Object Deleting',
                'description' => 'Triggered before an object is deleted',
                'category'    => 'Object',
                'type'        => 'before',
                'properties'  => ['object'],
            ],
            // Object events - After events (ed).
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectCreatedEvent',
                'name'        => 'Object Created',
                'description' => 'Triggered after an object is created',
                'category'    => 'Object',
                'type'        => 'after',
                'properties'  => ['object'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectUpdatedEvent',
                'name'        => 'Object Updated',
                'description' => 'Triggered after an object is updated',
                'category'    => 'Object',
                'type'        => 'after',
                'properties'  => ['newObject', 'oldObject'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectDeletedEvent',
                'name'        => 'Object Deleted',
                'description' => 'Triggered after an object is deleted',
                'category'    => 'Object',
                'type'        => 'after',
                'properties'  => ['object'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectLockedEvent',
                'name'        => 'Object Locked',
                'description' => 'Triggered when an object is locked',
                'category'    => 'Object',
                'type'        => 'after',
                'properties'  => ['object'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectUnlockedEvent',
                'name'        => 'Object Unlocked',
                'description' => 'Triggered when an object is unlocked',
                'category'    => 'Object',
                'type'        => 'after',
                'properties'  => ['object'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ObjectRevertedEvent',
                'name'        => 'Object Reverted',
                'description' => 'Triggered when an object is reverted',
                'category'    => 'Object',
                'type'        => 'after',
                'properties'  => ['object', 'revertPoint'],
            ],

            // Register events.
            [
                'class'       => 'OCA\OpenRegister\Event\RegisterCreatedEvent',
                'name'        => 'Register Created',
                'description' => 'Triggered after a register is created',
                'category'    => 'Register',
                'type'        => 'after',
                'properties'  => ['register'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\RegisterUpdatedEvent',
                'name'        => 'Register Updated',
                'description' => 'Triggered after a register is updated',
                'category'    => 'Register',
                'type'        => 'after',
                'properties'  => ['newRegister', 'oldRegister'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\RegisterDeletedEvent',
                'name'        => 'Register Deleted',
                'description' => 'Triggered after a register is deleted',
                'category'    => 'Register',
                'type'        => 'after',
                'properties'  => ['register'],
            ],

            // Schema events.
            [
                'class'       => 'OCA\OpenRegister\Event\SchemaCreatedEvent',
                'name'        => 'Schema Created',
                'description' => 'Triggered after a schema is created',
                'category'    => 'Schema',
                'type'        => 'after',
                'properties'  => ['schema'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\SchemaUpdatedEvent',
                'name'        => 'Schema Updated',
                'description' => 'Triggered after a schema is updated',
                'category'    => 'Schema',
                'type'        => 'after',
                'properties'  => ['newSchema', 'oldSchema'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\SchemaDeletedEvent',
                'name'        => 'Schema Deleted',
                'description' => 'Triggered after a schema is deleted',
                'category'    => 'Schema',
                'type'        => 'after',
                'properties'  => ['schema'],
            ],

            // Application events.
            [
                'class'       => 'OCA\OpenRegister\Event\ApplicationCreatedEvent',
                'name'        => 'Application Created',
                'description' => 'Triggered after an application is created',
                'category'    => 'Application',
                'type'        => 'after',
                'properties'  => ['application'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ApplicationUpdatedEvent',
                'name'        => 'Application Updated',
                'description' => 'Triggered after an application is updated',
                'category'    => 'Application',
                'type'        => 'after',
                'properties'  => ['newApplication', 'oldApplication'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ApplicationDeletedEvent',
                'name'        => 'Application Deleted',
                'description' => 'Triggered after an application is deleted',
                'category'    => 'Application',
                'type'        => 'after',
                'properties'  => ['application'],
            ],

            // Agent events.
            [
                'class'       => 'OCA\OpenRegister\Event\AgentCreatedEvent',
                'name'        => 'Agent Created',
                'description' => 'Triggered after an agent is created',
                'category'    => 'Agent',
                'type'        => 'after',
                'properties'  => ['agent'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\AgentUpdatedEvent',
                'name'        => 'Agent Updated',
                'description' => 'Triggered after an agent is updated',
                'category'    => 'Agent',
                'type'        => 'after',
                'properties'  => ['newAgent', 'oldAgent'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\AgentDeletedEvent',
                'name'        => 'Agent Deleted',
                'description' => 'Triggered after an agent is deleted',
                'category'    => 'Agent',
                'type'        => 'after',
                'properties'  => ['agent'],
            ],

            // Source events.
            [
                'class'       => 'OCA\OpenRegister\Event\SourceCreatedEvent',
                'name'        => 'Source Created',
                'description' => 'Triggered after a source is created',
                'category'    => 'Source',
                'type'        => 'after',
                'properties'  => ['source'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\SourceUpdatedEvent',
                'name'        => 'Source Updated',
                'description' => 'Triggered after a source is updated',
                'category'    => 'Source',
                'type'        => 'after',
                'properties'  => ['newSource', 'oldSource'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\SourceDeletedEvent',
                'name'        => 'Source Deleted',
                'description' => 'Triggered after a source is deleted',
                'category'    => 'Source',
                'type'        => 'after',
                'properties'  => ['source'],
            ],

            // Configuration events.
            [
                'class'       => 'OCA\OpenRegister\Event\ConfigurationCreatedEvent',
                'name'        => 'Configuration Created',
                'description' => 'Triggered after a configuration is created',
                'category'    => 'Configuration',
                'type'        => 'after',
                'properties'  => ['configuration'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ConfigurationUpdatedEvent',
                'name'        => 'Configuration Updated',
                'description' => 'Triggered after a configuration is updated',
                'category'    => 'Configuration',
                'type'        => 'after',
                'properties'  => ['newConfiguration', 'oldConfiguration'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ConfigurationDeletedEvent',
                'name'        => 'Configuration Deleted',
                'description' => 'Triggered after a configuration is deleted',
                'category'    => 'Configuration',
                'type'        => 'after',
                'properties'  => ['configuration'],
            ],

            // View events.
            [
                'class'       => 'OCA\OpenRegister\Event\ViewCreatedEvent',
                'name'        => 'View Created',
                'description' => 'Triggered after a view is created',
                'category'    => 'View',
                'type'        => 'after',
                'properties'  => ['view'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ViewUpdatedEvent',
                'name'        => 'View Updated',
                'description' => 'Triggered after a view is updated',
                'category'    => 'View',
                'type'        => 'after',
                'properties'  => ['newView', 'oldView'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ViewDeletedEvent',
                'name'        => 'View Deleted',
                'description' => 'Triggered after a view is deleted',
                'category'    => 'View',
                'type'        => 'after',
                'properties'  => ['view'],
            ],

            // Conversation events.
            [
                'class'       => 'OCA\OpenRegister\Event\ConversationCreatedEvent',
                'name'        => 'Conversation Created',
                'description' => 'Triggered after a conversation is created',
                'category'    => 'Conversation',
                'type'        => 'after',
                'properties'  => ['conversation'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ConversationUpdatedEvent',
                'name'        => 'Conversation Updated',
                'description' => 'Triggered after a conversation is updated',
                'category'    => 'Conversation',
                'type'        => 'after',
                'properties'  => ['newConversation', 'oldConversation'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\ConversationDeletedEvent',
                'name'        => 'Conversation Deleted',
                'description' => 'Triggered after a conversation is deleted',
                'category'    => 'Conversation',
                'type'        => 'after',
                'properties'  => ['conversation'],
            ],

            // Organisation events.
            [
                'class'       => 'OCA\OpenRegister\Event\OrganisationCreatedEvent',
                'name'        => 'Organisation Created',
                'description' => 'Triggered after an organisation is created',
                'category'    => 'Organisation',
                'type'        => 'after',
                'properties'  => ['organisation'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\OrganisationUpdatedEvent',
                'name'        => 'Organisation Updated',
                'description' => 'Triggered after an organisation is updated',
                'category'    => 'Organisation',
                'type'        => 'after',
                'properties'  => ['newOrganisation', 'oldOrganisation'],
            ],
            [
                'class'       => 'OCA\OpenRegister\Event\OrganisationDeletedEvent',
                'name'        => 'Organisation Deleted',
                'description' => 'Triggered after an organisation is deleted',
                'category'    => 'Organisation',
                'type'        => 'after',
                'properties'  => ['organisation'],
            ],
        ];

        return new JSONResponse(
            data: [
                'events' => $events,
                'total'  => count($events),
            ]
        );
    }//end events()

    /**
     * Get logs for a specific webhook
     *
     * @param int $id Webhook ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|404|500,
     *     array{
     *         error?: 'Failed to retrieve webhook logs'|'Webhook not found',
     *         results?: list<\OCA\OpenRegister\Db\WebhookLog>,
     *         total?: int<0, max>
     *     },
     *     array<never, never>
     * >
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function logs(int $id): JSONResponse
    {
        try {
            // Validate webhook exists by attempting to find it.
            $this->webhookMapper->find($id);

            $limit  = (int) ($this->request->getParam('limit') ?? 50);
            $offset = (int) ($this->request->getParam('offset') ?? 0);

            $logs = $this->webhookLogMapper->findByWebhook(webhookId: $id, limit: $limit, offset: $offset);

            return new JSONResponse(
                data: [
                    'results' => $logs,
                    'total'   => count($logs),
                ],
                statusCode: 200
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Webhook not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving webhook logs: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve webhook logs',
                ],
                statusCode: 500
            );
        }//end try
    }//end logs()

    /**
     * Get statistics for a specific webhook
     *
     * @param int $id Webhook ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404|500,
     *     array{error?: 'Failed to retrieve webhook log statistics'|
     *     'Webhook not found', total?: int, successful?: int, failed?: int,
     *     pendingRetries?: int<0, max>}, array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function logStats(int $id): JSONResponse
    {
        try {
            // Validate webhook exists by attempting to find it.
            $this->webhookMapper->find($id);
            $stats = $this->webhookLogMapper->getStatistics($id);

            // Count pending retries.
            $now            = new DateTime();
            $pendingRetries = count($this->webhookLogMapper->findFailedForRetry($now));

            $stats['pendingRetries'] = $pendingRetries;

            return new JSONResponse(data: $stats);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Webhook not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving webhook log statistics: '.$e->getMessage(),
                context: [
                    'id'    => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve webhook log statistics',
                ],
                statusCode: 500
            );
        }//end try
    }//end logStats()

    /**
     * Get all webhook logs with optional filtering
     *
     * @return JSONResponse JSON response with webhook logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function allLogs(): JSONResponse
    {
        try {
            $webhookId = $this->request->getParam('webhook_id');
            $limit     = (int) ($this->request->getParam('limit') ?? 50);
            $offset    = (int) ($this->request->getParam('offset') ?? 0);
            $success   = $this->request->getParam('success');

            // Get all logs by default.
            $logs = $this->webhookLogMapper->findAll(limit: $limit, offset: $offset);
            // Get total count for all logs.
            $allLogs = $this->webhookLogMapper->findAll(limit: null, offset: null);
            $total   = count($allLogs);

            // If webhook_id is provided and valid, use findByWebhook method instead.
            if ($webhookId !== null && $webhookId !== '' && $webhookId !== '0') {
                $webhookIdInt = (int) $webhookId;
                $logs         = $this->webhookLogMapper->findByWebhook(
                    webhookId: $webhookIdInt,
                    limit: $limit,
                    offset: $offset
                );
                // Get total count for this webhook.
                $allLogsForWebhook = $this->webhookLogMapper->findByWebhook(
                    webhookId: $webhookIdInt,
                    limit: null,
                    offset: null
                );
                $total = count($allLogsForWebhook);
            }

            // Filter by success status if provided.
            if ($success !== null && $success !== ''
                && ($success === 'true' || $success === '1' || $success === 'false' || $success === '0')
            ) {
                $successBool  = $success === 'true' || $success === '1';
                $filteredLogs = array_filter(
                    $logs,
                    function ($log) use ($successBool) {
                        return $log->getSuccess() === $successBool;
                    }
                );
                $logs         = array_values($filteredLogs);
                // Re-index array.
                // Recalculate total if filtering by success.
                $allLogs = $this->webhookLogMapper->findAll(limit: null, offset: null);
                $total   = count(
                    array_filter(
                        $allLogs,
                        function ($log) use ($successBool) {
                            return $log->getSuccess() === $successBool;
                        }
                    )
                );

                if ($webhookId !== null && $webhookId !== '' && $webhookId !== '0') {
                    $webhookIdInt      = (int) $webhookId;
                    $allLogsForWebhook = $this->webhookLogMapper->findByWebhook(
                        webhookId: $webhookIdInt,
                        limit: null,
                        offset: null
                    );
                    $total = count(
                        array_filter(
                            $allLogsForWebhook,
                            function ($log) use ($successBool) {
                                return $log->getSuccess() === $successBool;
                            }
                        )
                    );
                }
            }//end if

            return new JSONResponse(
                data: [
                    'results' => $logs,
                    'total'   => $total,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving webhook logs: '.$e->getMessage(),
                context: [
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve webhook logs: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end allLogs()

    /**
     * Retry a failed webhook delivery
     *
     * @param int $logId Log entry ID
     *
     * @return JSONResponse JSON response with retry result
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function retry(int $logId): JSONResponse
    {
        try {
            // Get the log entry.
            $log = $this->webhookLogMapper->find($logId);

            // Only allow retry for failed webhooks.
            if ($log->getSuccess() === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'Cannot retry a successful webhook delivery',
                    ],
                    statusCode: 400
                );
            }

            // Get the webhook.
            $webhook = $this->webhookMapper->find($log->getWebhookId());

            // Extract payload from request body if available, otherwise use stored payload.
            $payload = [];
            if ($log->getRequestBody() !== null) {
                $decoded = json_decode($log->getRequestBody() ?? '{}', true);
                if ($decoded !== null) {
                    $payload = $decoded;
                }
            } else if ($log->getPayload() !== null) {
                $payload = $log->getPayloadArray();
            }

            // If no payload found, return error.
            if (empty($payload) === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'No payload available for retry',
                    ],
                    statusCode: 400
                );
            }

            // Extract original event data from payload if available.
            $eventName       = $log->getEventClass();
            $originalPayload = $payload['data'] ?? $payload;

            // Retry the webhook delivery.
            $success = $this->webhookService->deliverWebhook(
                webhook: $webhook,
                eventName: $eventName,
                payload: $originalPayload,
                attempt: $log->getAttempt() + 1
            );

            if ($success === true) {
                return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Webhook retry delivered successfully',
                    ]
                );
            }

            // Get the latest log entry to retrieve error details.
            $latestLogs   = $this->webhookLogMapper->findByWebhook(webhookId: $webhook->getId(), limit: 1, offset: 0);
            $errorMessage = 'Webhook retry delivery failed';
            $errorDetails = null;

            if (empty($latestLogs) === false) {
                $latestLog = $latestLogs[0];
                if ($latestLog->getErrorMessage() !== null) {
                    $errorMessage = $latestLog->getErrorMessage();
                }

                if ($latestLog->getStatusCode() !== null) {
                    $errorDetails = [
                        'status_code'   => $latestLog->getStatusCode(),
                        'response_body' => $latestLog->getResponseBody(),
                    ];
                }
            }

            $responseData = [
                'success' => false,
                'message' => $errorMessage,
            ];

            if ($errorDetails !== null) {
                $responseData['error_details'] = $errorDetails;
            }

            return new JSONResponse(
                data: $responseData,
                statusCode: 500
            );
        } catch (DoesNotExistException $e) {
            $this->logger->error(
                message: 'Webhook log not found for retry: '.$e->getMessage(),
                context: [
                    'log_id' => $logId,
                    'trace'  => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Webhook log not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrying webhook: '.$e->getMessage(),
                context: [
                    'log_id' => $logId,
                    'trace'  => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retry webhook',
                ],
                statusCode: 500
            );
        }//end try
    }//end retry()
}//end class
