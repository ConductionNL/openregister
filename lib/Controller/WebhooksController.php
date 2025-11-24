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

use GuzzleHttp\Exception\GuzzleException;
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
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/openregister
 *
 * @psalm-suppress UnusedClass - This controller is registered via routes.php and used by Nextcloud's routing system
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
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param string          $appName        Application name
     * @param IRequest        $request        HTTP request
     * @param WebhookMapper   $webhookMapper  Webhook mapper
     * @param WebhookService  $webhookService Webhook service
     * @param LoggerInterface $logger         Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        WebhookMapper $webhookMapper,
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->webhookMapper  = $webhookMapper;
        $this->webhookService = $webhookService;
        $this->logger         = $logger;

    }//end __construct()


    /**
     * List all webhooks
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
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
     * @NoCSRFRequired
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
     * @return JSONResponse
     *
     * @NoAdminRequired
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
     * @return JSONResponse
     *
     * @NoAdminRequired
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
     * @NoCSRFRequired
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
     * @return JSONResponse
     *
     * @NoAdminRequired
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
            } else {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Test webhook delivery failed',
                    ],
                    statusCode: 500
                );
            }//end if
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
                    'error' => 'Failed to test webhook',
                ],
                statusCode: 500
            );
        }//end try

    }//end test()


    /**
     * List available events
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function events(): JSONResponse
    {
        $events = [
            // Object events.
            'OCA\OpenRegister\Event\ObjectCreatedEvent',
            'OCA\OpenRegister\Event\ObjectUpdatedEvent',
            'OCA\OpenRegister\Event\ObjectDeletedEvent',
            'OCA\OpenRegister\Event\ObjectLockedEvent',
            'OCA\OpenRegister\Event\ObjectUnlockedEvent',
            'OCA\OpenRegister\Event\ObjectRevertedEvent',

            // Register events.
            'OCA\OpenRegister\Event\RegisterCreatedEvent',
            'OCA\OpenRegister\Event\RegisterUpdatedEvent',
            'OCA\OpenRegister\Event\RegisterDeletedEvent',

            // Schema events.
            'OCA\OpenRegister\Event\SchemaCreatedEvent',
            'OCA\OpenRegister\Event\SchemaUpdatedEvent',
            'OCA\OpenRegister\Event\SchemaDeletedEvent',

            // Application events.
            'OCA\OpenRegister\Event\ApplicationCreatedEvent',
            'OCA\OpenRegister\Event\ApplicationUpdatedEvent',
            'OCA\OpenRegister\Event\ApplicationDeletedEvent',

            // Agent events.
            'OCA\OpenRegister\Event\AgentCreatedEvent',
            'OCA\OpenRegister\Event\AgentUpdatedEvent',
            'OCA\OpenRegister\Event\AgentDeletedEvent',

            // Source events.
            'OCA\OpenRegister\Event\SourceCreatedEvent',
            'OCA\OpenRegister\Event\SourceUpdatedEvent',
            'OCA\OpenRegister\Event\SourceDeletedEvent',

            // Configuration events.
            'OCA\OpenRegister\Event\ConfigurationCreatedEvent',
            'OCA\OpenRegister\Event\ConfigurationUpdatedEvent',
            'OCA\OpenRegister\Event\ConfigurationDeletedEvent',

            // View events.
            'OCA\OpenRegister\Event\ViewCreatedEvent',
            'OCA\OpenRegister\Event\ViewUpdatedEvent',
            'OCA\OpenRegister\Event\ViewDeletedEvent',

            // Conversation events.
            'OCA\OpenRegister\Event\ConversationCreatedEvent',
            'OCA\OpenRegister\Event\ConversationUpdatedEvent',
            'OCA\OpenRegister\Event\ConversationDeletedEvent',

            // Organisation events.
            'OCA\OpenRegister\Event\OrganisationCreatedEvent',
            'OCA\OpenRegister\Event\OrganisationUpdatedEvent',
            'OCA\OpenRegister\Event\OrganisationDeletedEvent',
        ];

        return new JSONResponse(
            data: [
                'events' => $events,
                'total'  => count($events),
            ]
        );

    }//end events()


}//end class
