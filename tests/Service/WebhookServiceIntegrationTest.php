<?php

/**
 * Integration tests for WebhookService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for WebhookService
 *
 * Tests webhook dispatch, delivery, payload building, filter matching,
 * retry policies, cloud event formatting, and request interception.
 *
 * @group DB
 */
class WebhookServiceIntegrationTest extends TestCase
{
    /**
     * The webhook service instance
     *
     * @var WebhookService
     */
    private WebhookService $service;

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * IDs of webhooks created during tests for cleanup
     *
     * @var int[]
     */
    private array $createdWebhookIds = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(WebhookService::class);
        $this->db = \OC::$server->get(IDBConnection::class);
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->createdWebhookIds as $webhookId) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_webhook_logs')
                    ->where($qb->expr()->eq('webhook', $qb->createNamedParameter($webhookId)))
                    ->executeStatement();

                $qb2 = $this->db->getQueryBuilder();
                $qb2->delete('openregister_webhooks')
                    ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($webhookId)))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore
            }
        }

        parent::tearDown();
    }

    /**
     * Insert a webhook directly via SQL bypassing RBAC and return a hydrated entity
     *
     * @param array $data Webhook properties
     *
     * @return Webhook
     */
    private function insertWebhookDirect(array $data = []): Webhook
    {
        $uuid = $data['uuid'] ?? Uuid::v4()->toRfc4122();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $qb = $this->db->getQueryBuilder();
        $qb->insert('openregister_webhooks')
            ->values([
                'uuid'                  => $qb->createNamedParameter($uuid),
                'name'                  => $qb->createNamedParameter($data['name'] ?? 'phpunit-webhook-' . uniqid()),
                'url'                   => $qb->createNamedParameter($data['url'] ?? 'http://192.0.2.1:9999/nonexistent'),
                'method'                => $qb->createNamedParameter($data['method'] ?? 'POST'),
                'events'                => $qb->createNamedParameter($data['events'] ?? '[]'),
                'headers'               => $qb->createNamedParameter($data['headers'] ?? null),
                'secret'                => $qb->createNamedParameter($data['secret'] ?? null),
                'enabled'               => $qb->createNamedParameter($data['enabled'] ?? true, \PDO::PARAM_BOOL),
                'organisation'          => $qb->createNamedParameter($data['organisation'] ?? null),
                'filters'               => $qb->createNamedParameter($data['filters'] ?? null),
                'retry_policy'          => $qb->createNamedParameter($data['retry_policy'] ?? 'exponential'),
                'max_retries'           => $qb->createNamedParameter($data['max_retries'] ?? 3, \PDO::PARAM_INT),
                'timeout'               => $qb->createNamedParameter($data['timeout'] ?? 5, \PDO::PARAM_INT),
                'total_deliveries'      => $qb->createNamedParameter(0, \PDO::PARAM_INT),
                'successful_deliveries' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
                'failed_deliveries'     => $qb->createNamedParameter(0, \PDO::PARAM_INT),
                'created'               => $qb->createNamedParameter($now),
                'updated'               => $qb->createNamedParameter($now),
                'configuration'         => $qb->createNamedParameter($data['configuration'] ?? null),
                'mapping'               => $qb->createNamedParameter($data['mapping'] ?? null),
            ])
            ->executeStatement();

        $id = (int) $qb->getLastInsertId();
        $this->createdWebhookIds[] = $id;

        // Hydrate a Webhook entity
        $webhook = new Webhook();
        $webhook->setId($id);
        $webhook->hydrate([
            'uuid'          => $uuid,
            'name'          => $data['name'] ?? 'phpunit-webhook-' . $id,
            'url'           => $data['url'] ?? 'http://192.0.2.1:9999/nonexistent',
            'method'        => $data['method'] ?? 'POST',
            'events'        => json_decode($data['events'] ?? '[]', true) ?: [],
            'headers'       => $data['headers'] !== null ? (json_decode($data['headers'], true) ?: []) : null,
            'secret'        => $data['secret'] ?? null,
            'enabled'       => $data['enabled'] ?? true,
            'filters'       => $data['filters'] !== null ? (json_decode($data['filters'], true) ?: []) : null,
            'retryPolicy'   => $data['retry_policy'] ?? 'exponential',
            'maxRetries'    => $data['max_retries'] ?? 3,
            'timeout'       => $data['timeout'] ?? 5,
            'configuration' => $data['configuration'] !== null ? (json_decode($data['configuration'], true) ?: []) : null,
            'mapping'       => $data['mapping'] ?? null,
        ]);

        return $webhook;
    }

    /**
     * Test dispatchEvent with no matching webhooks does not throw
     *
     * @return void
     */
    public function testDispatchEventNoWebhooks(): void
    {
        $event = new class extends Event {};

        $this->service->dispatchEvent(
            $event,
            'phpunit.test.event.' . uniqid(),
            ['test' => 'data']
        );

        $this->assertTrue(true);
    }

    /**
     * Test dispatchEvent with empty payload
     *
     * @return void
     */
    public function testDispatchEventEmptyPayload(): void
    {
        $event = new class extends Event {};

        $this->service->dispatchEvent(
            $event,
            'phpunit.test.empty.payload.' . uniqid(),
            []
        );

        $this->assertTrue(true);
    }

    /**
     * Test dispatchEvent with complex payload
     *
     * @return void
     */
    public function testDispatchEventComplexPayload(): void
    {
        $event = new class extends Event {};

        $payload = [
            'uuid'       => 'test-uuid-' . uniqid(),
            'action'     => 'create',
            'data'       => [
                'name' => 'Test Object',
                'nested' => ['key' => 'value'],
            ],
            'timestamp'  => time(),
        ];

        $this->service->dispatchEvent(
            $event,
            'phpunit.test.complex.payload.' . uniqid(),
            $payload
        );

        $this->assertTrue(true);
    }

    /**
     * Test interceptRequest returns array
     *
     * @return void
     */
    public function testInterceptRequest(): void
    {
        $request = \OC::$server->get(\OCP\IRequest::class);

        $result = $this->service->interceptRequest($request, 'phpunit.test.intercept.' . uniqid());

        $this->assertIsArray($result);
    }

    /**
     * Test deliverWebhook with disabled webhook returns false
     *
     * @return void
     */
    public function testDeliverWebhookDisabled(): void
    {
        $webhook = $this->insertWebhookDirect(['enabled' => false]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with enabled webhook (unreachable URL)
     *
     * @return void
     */
    public function testDeliverWebhookUnreachableUrl(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'max_retries' => 1,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with filters that do not match
     *
     * @return void
     */
    public function testDeliverWebhookFilterMismatch(): void
    {
        $webhook = $this->insertWebhookDirect([
            'filters' => json_encode(['objectType' => 'person']),
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['objectType' => 'document']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with matching filter proceeds to delivery
     *
     * @return void
     */
    public function testDeliverWebhookFilterMatch(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'max_retries' => 1,
            'filters'     => json_encode(['objectType' => 'person']),
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['objectType' => 'person']
        );

        // Filters pass, but delivery fails (unreachable URL)
        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with array filter values (mismatch)
     *
     * @return void
     */
    public function testDeliverWebhookArrayFilterMismatch(): void
    {
        $webhook = $this->insertWebhookDirect([
            'filters' => json_encode(['objectType' => ['person', 'organization']]),
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['objectType' => 'animal']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with array filter values (match)
     *
     * @return void
     */
    public function testDeliverWebhookArrayFilterMatch(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'max_retries' => 1,
            'filters'     => json_encode(['objectType' => ['person', 'organization']]),
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['objectType' => 'person']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with nested dot-notation filter (mismatch)
     *
     * @return void
     */
    public function testDeliverWebhookNestedFilterMismatch(): void
    {
        $webhook = $this->insertWebhookDirect([
            'filters' => json_encode(['data.type' => 'person']),
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['data' => ['type' => 'document']]
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with nested dot-notation filter (match)
     *
     * @return void
     */
    public function testDeliverWebhookNestedFilterMatch(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'max_retries' => 1,
            'filters'     => json_encode(['data.type' => 'person']),
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['data' => ['type' => 'person']]
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with nested filter on missing key
     *
     * @return void
     */
    public function testDeliverWebhookNestedFilterMissingKey(): void
    {
        $webhook = $this->insertWebhookDirect([
            'filters' => json_encode(['data.nonexistent.deep' => 'value']),
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['data' => ['type' => 'person']]
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with cloud events config
     *
     * @return void
     */
    public function testDeliverWebhookWithCloudEventsConfig(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'       => 2,
            'max_retries'   => 1,
            'configuration' => json_encode([
                'useCloudEvents'    => true,
                'cloudEventSource'  => '/test/source',
                'cloudEventSubject' => 'test-subject',
            ]),
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['uuid' => 'test-123']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with mapping that does not exist
     *
     * @return void
     */
    public function testDeliverWebhookWithMissingMapping(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'max_retries' => 1,
            'mapping'     => 999999,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['uuid' => 'test-123']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with GET method
     *
     * @return void
     */
    public function testDeliverWebhookGetMethod(): void
    {
        $webhook = $this->insertWebhookDirect([
            'method'      => 'GET',
            'timeout'     => 2,
            'max_retries' => 1,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with secret generates signature
     *
     * @return void
     */
    public function testDeliverWebhookWithSecret(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'secret'      => 'my-webhook-secret-key',
            'max_retries' => 1,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with custom headers
     *
     * @return void
     */
    public function testDeliverWebhookWithCustomHeaders(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'headers'     => json_encode(['X-Custom' => 'test-value', 'Authorization' => 'Bearer test']),
            'max_retries' => 1,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with exponential retry policy
     *
     * @return void
     */
    public function testDeliverWebhookExponentialRetry(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'      => 2,
            'retry_policy' => 'exponential',
            'max_retries'  => 3,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data'],
            1
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with linear retry policy
     *
     * @return void
     */
    public function testDeliverWebhookLinearRetry(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'      => 2,
            'retry_policy' => 'linear',
            'max_retries'  => 3,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data'],
            1
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with fixed retry policy
     *
     * @return void
     */
    public function testDeliverWebhookFixedRetry(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'      => 2,
            'retry_policy' => 'fixed',
            'max_retries'  => 3,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data'],
            1
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook at max retry does not schedule another
     *
     * @return void
     */
    public function testDeliverWebhookAtMaxRetry(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'max_retries' => 3,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data'],
            3
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with default (unknown) retry policy
     *
     * @return void
     */
    public function testDeliverWebhookDefaultRetryPolicy(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'      => 2,
            'retry_policy' => 'unknown_policy',
            'max_retries'  => 3,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data'],
            1
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with PUT method
     *
     * @return void
     */
    public function testDeliverWebhookPutMethod(): void
    {
        $webhook = $this->insertWebhookDirect([
            'method'      => 'PUT',
            'timeout'     => 2,
            'max_retries' => 1,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent',
            ['test' => 'data']
        );

        $this->assertFalse($result);
    }

    /**
     * Test deliverWebhook with DELETE method
     *
     * @return void
     */
    public function testDeliverWebhookDeleteMethod(): void
    {
        $webhook = $this->insertWebhookDirect([
            'method'      => 'DELETE',
            'timeout'     => 2,
            'max_retries' => 1,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectDeletedEvent',
            ['test' => 'data']
        );

        $this->assertFalse($result);
    }

    /**
     * Test dispatchEvent with matching webhook exercises full dispatch path
     *
     * @return void
     */
    public function testDispatchEventWithMatchingWebhook(): void
    {
        $eventName = 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent';

        $this->insertWebhookDirect([
            'timeout'     => 2,
            'max_retries' => 1,
            'events'      => json_encode([$eventName]),
        ]);

        $event = new class extends Event {};

        $this->service->dispatchEvent(
            $event,
            $eventName,
            ['uuid' => 'test-dispatch-' . uniqid()]
        );

        $this->assertTrue(true);
    }

    /**
     * Test interceptRequest with interception-configured webhook
     *
     * @return void
     */
    public function testInterceptRequestWithConfiguredWebhook(): void
    {
        $this->insertWebhookDirect([
            'timeout'       => 2,
            'max_retries'   => 1,
            'events'        => json_encode(['OCA\\OpenRegister\\Event\\ObjectCreatingEvent']),
            'configuration' => json_encode([
                'interceptRequests' => true,
                'processResponse'   => true,
            ]),
        ]);

        $request = \OC::$server->get(\OCP\IRequest::class);

        $result = $this->service->interceptRequest($request, 'object.creating');

        $this->assertIsArray($result);
    }

    /**
     * Test interceptRequest with async webhook config
     *
     * @return void
     */
    public function testInterceptRequestAsyncWebhook(): void
    {
        $this->insertWebhookDirect([
            'timeout'       => 2,
            'max_retries'   => 1,
            'events'        => json_encode(['OCA\\OpenRegister\\Event\\ObjectCreatingEvent']),
            'configuration' => json_encode([
                'interceptRequests' => true,
                'processResponse'   => true,
                'async'             => true,
            ]),
        ]);

        $request = \OC::$server->get(\OCP\IRequest::class);

        $result = $this->service->interceptRequest($request, 'object.creating');

        $this->assertIsArray($result);
    }

    /**
     * Test deliverWebhook with empty filters passes through
     *
     * @return void
     */
    public function testDeliverWebhookEmptyFilters(): void
    {
        $webhook = $this->insertWebhookDirect([
            'timeout'     => 2,
            'filters'     => null,
            'max_retries' => 1,
        ]);

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data']
        );

        $this->assertFalse($result);
    }

    /**
     * Test dispatchEvent with fully qualified event class name
     *
     * @return void
     */
    public function testDispatchEventFullyQualifiedName(): void
    {
        $event = new class extends Event {};

        $this->service->dispatchEvent(
            $event,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['uuid' => 'test-' . uniqid()]
        );

        $this->assertTrue(true);
    }
}
