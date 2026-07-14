<?php

/**
 * WebhookService Unit Tests
 *
 * Tests for webhook payload mapping transformation, including mapping
 * transforms payload, missing mapping fallback, mapping error fallback,
 * null mapping uses existing behavior, and mapping precedence over CloudEvents.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Test class requires many collaborator mocks
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   Comprehensive coverage requires many test methods
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WebhookService webhook payload mapping
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */
class WebhookServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var WebhookService
     */
    private WebhookService $service;

    /**
     * Mock webhook mapper.
     *
     * @var WebhookMapper&MockObject
     */
    private WebhookMapper $webhookMapper;

    /**
     * Mock webhook log mapper.
     *
     * @var WebhookLogMapper&MockObject
     */
    private WebhookLogMapper $webhookLogMapper;

    /**
     * Mock mapping service.
     *
     * @var MappingService&MockObject
     */
    private MappingService $mappingService;

    /**
     * Mock mapping mapper.
     *
     * @var MappingMapper&MockObject
     */
    private MappingMapper $mappingMapper;

    /**
     * @var IJobList&MockObject
     */
    private IJobList $jobList;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Reflection for accessing private members.
     *
     * @var \ReflectionClass
     */
    private \ReflectionClass $reflection;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->webhookMapper    = $this->createMock(WebhookMapper::class);
        $this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
        $this->mappingService   = $this->createMock(MappingService::class);
        $this->mappingMapper    = $this->createMock(MappingMapper::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->jobList          = $this->createMock(IJobList::class);

        $this->service = new WebhookService(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger,
            webhookLogMapper: $this->webhookLogMapper,
            mappingService: $this->mappingService,
            mappingMapper: $this->mappingMapper,
            jobList: $this->jobList
        );

        $this->reflection = new \ReflectionClass($this->service);
    }//end setUp()

    // ─── Helper methods ──────────────────────────────────────────────

    /**
     * Create a real Webhook entity with given values.
     *
     * Uses real Webhook instances (not mocks) because Nextcloud Entity
     * uses __call magic for getters/setters which PHPUnit cannot mock.
     *
     * @param int|null    $id            Webhook ID.
     * @param string      $name          Webhook name.
     * @param string      $url           Webhook URL.
     * @param int|null    $mapping       Mapping entity ID reference.
     * @param bool        $enabled       Whether webhook is enabled.
     * @param string|null $secret        HMAC secret.
     * @param string|null $configuration JSON configuration string.
     *
     * @return Webhook
     */
    private function createTestWebhook(
        ?int $id=1,
        string $name='Test Webhook',
        string $url='https://example.com/hook',
        ?int $mapping=null,
        bool $enabled=true,
        ?string $secret=null,
        ?string $configuration=null
    ): Webhook {
        $webhook = new Webhook();
        if ($id !== null) {
            $webhook->setId($id);
        }

        $webhook->setUuid('webhook-uuid-1');
        $webhook->setName($name);
        $webhook->setUrl($url);
        $webhook->setEnabled($enabled);
        if ($mapping !== null) {
            $webhook->setMapping($mapping);
        }

        if ($secret !== null) {
            $webhook->setSecret($secret);
        }

        if ($configuration !== null) {
            $webhook->setConfiguration($configuration);
        }

        return $webhook;
    }//end createTestWebhook()

    /**
     * Create a real Mapping entity with given values.
     *
     * @param int    $id      Mapping ID.
     * @param string $name    Mapping name.
     * @param array  $mapping Mapping configuration array.
     *
     * @return Mapping
     */
    private function createTestMapping(
        int $id=1,
        string $name='ZGW Notification Mapping',
        array $mapping=[]
    ): Mapping {
        $mappingEntity = new Mapping();
        $mappingEntity->setId($id);
        $mappingEntity->setName($name);
        $mappingEntity->setMapping($mapping);
        return $mappingEntity;
    }//end createTestMapping()

    /**
     * Invoke a private method on the service via reflection.
     *
     * @param string $methodName Method to invoke.
     * @param array  $args       Named arguments.
     *
     * @return mixed
     */
    private function invokePrivateMethod(string $methodName, array $args): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->service, $args);
    }//end invokePrivateMethod()

    // ─── buildPayload tests ──────────────────────────────────────────

    /**
     * Test that a webhook with a mapping transforms the payload.
     *
     * @return void
     */
    public function testBuildPayloadWithMappingTransformsPayload(): void
    {
        $webhook = $this->createTestWebhook(mapping: 42);
        $payload = [
            'objectType' => 'object',
            'action'     => 'create',
            'object'     => ['uuid' => 'obj-1', 'title' => 'Test'],
        ];

        $mappingEntity = $this->createTestMapping(
            id: 42,
            mapping: ['kanaal' => '{{ action }}', 'resource' => '{{ objectType }}']
        );

        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($mappingEntity);

        $transformedPayload = ['kanaal' => 'create', 'resource' => 'object'];
        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->willReturn($transformedPayload);

        $result = $this->invokePrivateMethod(
            'buildPayload',
            [
                'webhook'   => $webhook,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => $payload,
                'attempt'   => 1,
            ]
        );

        $this->assertSame($transformedPayload, $result);
    }//end testBuildPayloadWithMappingTransformsPayload()

    /**
     * Test that a missing mapping falls back to standard format.
     *
     * @return void
     */
    public function testBuildPayloadWithMissingMappingFallsBackToStandard(): void
    {
        $webhook = $this->createTestWebhook(mapping: 999);
        $payload = ['objectType' => 'object', 'action' => 'create'];

        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('missing mapping'),
                $this->anything()
            );

        $result = $this->invokePrivateMethod(
            'buildPayload',
            [
                'webhook'   => $webhook,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => $payload,
                'attempt'   => 1,
            ]
        );

        // Should fall back to standard format.
        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('webhook', $result);
    }//end testBuildPayloadWithMissingMappingFallsBackToStandard()

    /**
     * Test that a mapping execution error falls back to standard format with warning.
     *
     * @return void
     */
    public function testBuildPayloadWithMappingErrorFallsBackWithWarning(): void
    {
        $webhook = $this->createTestWebhook(mapping: 42);
        $payload = ['objectType' => 'object', 'action' => 'create'];

        $mappingEntity = $this->createTestMapping(id: 42);
        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($mappingEntity);

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->willThrowException(new \RuntimeException('Twig rendering failed'));

        // Should log a warning about transformation failure.
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Mapping transformation failed'),
                $this->anything()
            );

        $result = $this->invokePrivateMethod(
            'buildPayload',
            [
                'webhook'   => $webhook,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => $payload,
                'attempt'   => 1,
            ]
        );

        // Should fall back to standard format.
        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('data', $result);
    }//end testBuildPayloadWithMappingErrorFallsBackWithWarning()

    /**
     * Test that null mapping uses standard format (existing behavior).
     *
     * @return void
     */
    public function testBuildPayloadWithNullMappingUsesStandardFormat(): void
    {
        $webhook = $this->createTestWebhook(mapping: null);
        $payload = ['objectType' => 'object', 'action' => 'create'];

        // MappingMapper should NOT be called.
        $this->mappingMapper->expects($this->never())->method('find');

        $result = $this->invokePrivateMethod(
            'buildPayload',
            [
                'webhook'   => $webhook,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => $payload,
                'attempt'   => 1,
            ]
        );

        $this->assertSame('OCA\\OpenRegister\\Event\\ObjectCreatedEvent', $result['event']);
        $this->assertSame($payload, $result['data']);
        $this->assertSame('webhook-uuid-1', $result['webhook']['id']);
        $this->assertSame('Test Webhook', $result['webhook']['name']);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertSame(1, $result['attempt']);
    }//end testBuildPayloadWithNullMappingUsesStandardFormat()

    /**
     * Test that mapping takes precedence over CloudEvents configuration.
     *
     * @return void
     */
    public function testBuildPayloadMappingTakesPrecedenceOverCloudEvents(): void
    {
        // Create service WITH CloudEventFormatter.
        $cloudEventFormatter = $this->createMock(CloudEventFormatter::class);
        $service = new WebhookService(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger,
            webhookLogMapper: $this->webhookLogMapper,
            mappingService: $this->mappingService,
            mappingMapper: $this->mappingMapper,
            jobList: $this->jobList,
            cloudEventFormatter: $cloudEventFormatter
        );

        $reflection = new \ReflectionClass($service);

        // Webhook has BOTH mapping AND CloudEvents configured.
        $webhook = $this->createTestWebhook(
            mapping: 42,
            configuration: json_encode(['useCloudEvents' => true])
        );
        $payload = ['objectType' => 'object', 'action' => 'create'];

        $mappingEntity = $this->createTestMapping(id: 42);
        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($mappingEntity);

        $transformedPayload = ['kanaal' => 'create'];
        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->willReturn($transformedPayload);

        // CloudEventFormatter should NOT be called.
        $cloudEventFormatter->expects($this->never())->method('formatAsCloudEvent');

        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);
        $result = $method->invokeArgs(
            $service,
            [
                'webhook'   => $webhook,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => $payload,
                'attempt'   => 1,
            ]
        );

        $this->assertSame($transformedPayload, $result);
    }//end testBuildPayloadMappingTakesPrecedenceOverCloudEvents()

    // ─── applyMappingTransformation tests ────────────────────────────

    /**
     * Test that applyMappingTransformation enriches input with event and timestamp.
     *
     * @return void
     */
    public function testApplyMappingTransformationEnrichesInput(): void
    {
        $webhook       = $this->createTestWebhook(mapping: 42);
        $mappingEntity = $this->createTestMapping(id: 42);

        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($mappingEntity);

        $payload = ['objectType' => 'object', 'action' => 'create'];

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->with(
                $this->identicalTo($mappingEntity),
                $this->callback(
                    function ($input) {
                        // Verify that the mapping input includes enriched context.
                        return isset($input['event'])
                        && $input['event'] === 'ObjectCreatedEvent'
                        && isset($input['timestamp'])
                        && isset($input['objectType'])
                        && $input['objectType'] === 'object';
                    }
                )
            )
            ->willReturn(['mapped' => true]);

        $result = $this->invokePrivateMethod(
            'applyMappingTransformation',
            [
                'mappingId' => 42,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => $payload,
                'webhook'   => $webhook,
            ]
        );

        $this->assertSame(['mapped' => true], $result);
    }//end testApplyMappingTransformationEnrichesInput()

    /**
     * Test that applyMappingTransformation returns null on DoesNotExistException.
     *
     * @return void
     */
    public function testApplyMappingTransformationReturnsNullOnMissingMapping(): void
    {
        $webhook = $this->createTestWebhook(mapping: 999);

        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->invokePrivateMethod(
            'applyMappingTransformation',
            [
                'mappingId' => 999,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => ['objectType' => 'object'],
                'webhook'   => $webhook,
            ]
        );

        $this->assertNull($result);
    }//end testApplyMappingTransformationReturnsNullOnMissingMapping()

    /**
     * Test that applyMappingTransformation returns null on mapping execution error.
     *
     * @return void
     */
    public function testApplyMappingTransformationReturnsNullOnExecutionError(): void
    {
        $webhook       = $this->createTestWebhook(mapping: 42);
        $mappingEntity = $this->createTestMapping(id: 42);

        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($mappingEntity);

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->willThrowException(new \RuntimeException('Twig error'));

        $result = $this->invokePrivateMethod(
            'applyMappingTransformation',
            [
                'mappingId' => 42,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => ['objectType' => 'object'],
                'webhook'   => $webhook,
            ]
        );

        $this->assertNull($result);
    }//end testApplyMappingTransformationReturnsNullOnExecutionError()

    // ─── getShortEventName tests ─────────────────────────────────────

    /**
     * Test getShortEventName extracts the class name from a fully qualified name.
     *
     * @return void
     */
    public function testGetShortEventNameExtractsClassName(): void
    {
        $result = $this->invokePrivateMethod(
            'getShortEventName',
            [
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ]
        );

        $this->assertSame('ObjectCreatedEvent', $result);
    }//end testGetShortEventNameExtractsClassName()

    /**
     * Test getShortEventName with a simple class name (no namespace).
     *
     * @return void
     */
    public function testGetShortEventNameWithSimpleName(): void
    {
        $result = $this->invokePrivateMethod(
            'getShortEventName',
            [
                'eventName' => 'ObjectCreatedEvent',
            ]
        );

        $this->assertSame('ObjectCreatedEvent', $result);
    }//end testGetShortEventNameWithSimpleName()

    // ─── CloudEvents fallback test ───────────────────────────────────

    /**
     * Test that null mapping with CloudEvents configured uses CloudEvents format.
     *
     * @return void
     */
    public function testBuildPayloadWithNullMappingUsesCloudEventsWhenConfigured(): void
    {
        $cloudEventFormatter = $this->createMock(CloudEventFormatter::class);
        $service = new WebhookService(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger,
            webhookLogMapper: $this->webhookLogMapper,
            mappingService: $this->mappingService,
            mappingMapper: $this->mappingMapper,
            jobList: $this->jobList,
            cloudEventFormatter: $cloudEventFormatter
        );

        $reflection = new \ReflectionClass($service);

        $webhook = $this->createTestWebhook(
            mapping: null,
            configuration: json_encode(['useCloudEvents' => true])
        );
        $payload = ['objectType' => 'object', 'action' => 'create'];

        $cloudEventPayload = ['specversion' => '1.0', 'type' => 'ObjectCreatedEvent', 'data' => $payload];
        $cloudEventFormatter->expects($this->once())
            ->method('formatAsCloudEvent')
            ->willReturn($cloudEventPayload);

        // MappingMapper should NOT be called.
        $this->mappingMapper->expects($this->never())->method('find');

        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);
        $result = $method->invokeArgs(
            $service,
            [
                'webhook'   => $webhook,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => $payload,
                'attempt'   => 1,
            ]
        );

        $this->assertSame($cloudEventPayload, $result);
    }//end testBuildPayloadWithNullMappingUsesCloudEventsWhenConfigured()

    // ─── dispatchEvent tests ─────────────────────────────────────────

    public function testDispatchEventWithNoMatchingWebhooks(): void
    {
        $event = $this->createMock(\OCP\EventDispatcher\Event::class);

        $this->webhookMapper->method('findForEvent')
            ->willReturn([]);

        // No delivery should happen
        $this->webhookLogMapper->expects($this->never())->method('insert');

        $this->service->dispatchEvent($event, 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent', ['test' => 'data']);
    }

    public function testDispatchEventWithExceptionOnFindForEvent(): void
    {
        $event = $this->createMock(\OCP\EventDispatcher\Event::class);

        $this->webhookMapper->method('findForEvent')
            ->willThrowException(new \Exception('Table not found'));

        // Should silently skip
        $this->webhookLogMapper->expects($this->never())->method('insert');

        $this->service->dispatchEvent($event, 'SomeEvent', ['test' => 'data']);
    }

    public function testDispatchEventEnqueuesDeliveryJobPerWebhookAndDoesNotDeliverSynchronously(): void
    {
        $event = $this->createMock(\OCP\EventDispatcher\Event::class);

        // Webhook is an NC Entity (uses __call magic getters/setters), which
        // createMock() cannot stub — use real instances with an id set.
        $webhook1 = $this->createTestWebhook(id: 1);
        $webhook2 = $this->createTestWebhook(id: 2);

        $this->webhookMapper->method('findForEvent')->willReturn([$webhook1, $webhook2]);

        // The write must NOT block on delivery: no synchronous HTTP log insert.
        $this->webhookLogMapper->expects($this->never())->method('insert');

        // Instead, one delivery job is enqueued per matching webhook.
        $enqueued = [];
        $this->jobList->expects($this->exactly(2))
            ->method('add')
            ->willReturnCallback(function (string $job, array $arg) use (&$enqueued) {
                $enqueued[] = [$job, $arg];
            });

        $this->service->dispatchEvent(
            $event,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['test' => 'data']
        );

        $this->assertCount(2, $enqueued);
        $this->assertSame(WebhookDeliveryJob::class, $enqueued[0][0]);
        $this->assertSame(1, $enqueued[0][1]['webhook_id']);
        $this->assertSame('OCA\\OpenRegister\\Event\\ObjectCreatedEvent', $enqueued[0][1]['event_name']);
        $this->assertSame(2, $enqueued[1][1]['webhook_id']);
    }

    // ─── deliverWebhook tests ────────────────────────────────────────

    public function testDeliverWebhookReturnsFalseWhenDisabled(): void
    {
        $webhook = $this->createTestWebhook(enabled: false);

        $result = $this->service->deliverWebhook($webhook, 'SomeEvent', ['data' => 'test']);

        $this->assertFalse($result);
    }

    public function testDeliverWebhookReturnsFalseWhenFiltersDontMatch(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        // Set filters as JSON string (Entity expects string type)
        $webhook->setFilters(json_encode(['action' => 'delete']));

        $result = $this->service->deliverWebhook($webhook, 'SomeEvent', ['action' => 'create']);

        $this->assertFalse($result);
    }

    // ─── passesFilters tests (via reflection) ────────────────────────

    public function testPassesFiltersReturnsTrueWithNoFilters(): void
    {
        $webhook = $this->createTestWebhook();

        $result = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['any' => 'data'],
        ]);

        $this->assertTrue($result);
    }

    public function testPassesFiltersReturnsTrueWhenAllFiltersMatch(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setFilters(json_encode(['action' => 'create', 'objectType' => 'object']));

        $result = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['action' => 'create', 'objectType' => 'object', 'extra' => 'field'],
        ]);

        $this->assertTrue($result);
    }

    public function testPassesFiltersReturnsFalseWhenFilterDoesNotMatch(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setFilters(json_encode(['action' => 'delete']));

        $result = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['action' => 'create'],
        ]);

        $this->assertFalse($result);
    }

    public function testPassesFiltersWithArrayFilterValues(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setFilters(json_encode(['action' => ['create', 'update']]));

        $resultMatch = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['action' => 'create'],
        ]);
        $this->assertTrue($resultMatch);

        $resultNoMatch = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['action' => 'delete'],
        ]);
        $this->assertFalse($resultNoMatch);
    }

    // ─── getNestedValue tests (via reflection) ───────────────────────

    public function testGetNestedValueSimpleKey(): void
    {
        $result = $this->invokePrivateMethod('getNestedValue', [
            'array' => ['name' => 'test'],
            'key' => 'name',
        ]);

        $this->assertSame('test', $result);
    }

    public function testGetNestedValueDotNotation(): void
    {
        $result = $this->invokePrivateMethod('getNestedValue', [
            'array' => ['object' => ['type' => 'person']],
            'key' => 'object.type',
        ]);

        $this->assertSame('person', $result);
    }

    public function testGetNestedValueReturnsNullForMissingKey(): void
    {
        $result = $this->invokePrivateMethod('getNestedValue', [
            'array' => ['name' => 'test'],
            'key' => 'missing.key',
        ]);

        $this->assertNull($result);
    }

    public function testGetNestedValueDeepNesting(): void
    {
        $result = $this->invokePrivateMethod('getNestedValue', [
            'array' => ['a' => ['b' => ['c' => 'deep']]],
            'key' => 'a.b.c',
        ]);

        $this->assertSame('deep', $result);
    }

    // ─── calculateRetryDelay tests (via reflection) ──────────────────

    public function testCalculateRetryDelayExponential(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('exponential');

        $delay = $this->invokePrivateMethod('calculateRetryDelay', [
            'webhook' => $webhook,
            'attempt' => 1,
        ]);
        $this->assertSame(120, $delay); // 2^1 * 60

        $delay2 = $this->invokePrivateMethod('calculateRetryDelay', [
            'webhook' => $webhook,
            'attempt' => 3,
        ]);
        $this->assertSame(480, $delay2); // 2^3 * 60
    }

    public function testCalculateRetryDelayLinear(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('linear');

        $delay = $this->invokePrivateMethod('calculateRetryDelay', [
            'webhook' => $webhook,
            'attempt' => 2,
        ]);
        $this->assertSame(600, $delay); // 2 * 300
    }

    public function testCalculateRetryDelayFixed(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('fixed');

        $delay = $this->invokePrivateMethod('calculateRetryDelay', [
            'webhook' => $webhook,
            'attempt' => 5,
        ]);
        $this->assertSame(300, $delay);
    }

    public function testCalculateRetryDelayDefaultPolicy(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('unknown_policy');

        $delay = $this->invokePrivateMethod('calculateRetryDelay', [
            'webhook' => $webhook,
            'attempt' => 1,
        ]);
        $this->assertSame(300, $delay);
    }

    // ─── calculateNextRetryTime tests ────────────────────────────────

    public function testCalculateNextRetryTimeIsInFuture(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('fixed');

        $before = new \DateTime();
        $result = $this->invokePrivateMethod('calculateNextRetryTime', [
            'webhook' => $webhook,
            'attempt' => 1,
        ]);

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertGreaterThan($before, $result);
    }

    // ─── eventTypeToEventClass tests ─────────────────────────────────

    public function testEventTypeToEventClass(): void
    {
        $result = $this->invokePrivateMethod('eventTypeToEventClass', [
            'eventType' => 'object.creating',
        ]);
        $this->assertSame('OCA\\OpenRegister\\Event\\ObjectCreatingEvent', $result);
    }

    public function testEventTypeToEventClassWithSinglePart(): void
    {
        $result = $this->invokePrivateMethod('eventTypeToEventClass', [
            'eventType' => 'schema',
        ]);
        $this->assertSame('OCA\\OpenRegister\\Event\\SchemaCreatedEvent', $result);
    }

    // ─── shouldProcessResponse tests ─────────────────────────────────

    public function testShouldProcessResponseReturnsFalseByDefault(): void
    {
        $webhook = $this->createTestWebhook();

        $result = $this->invokePrivateMethod('shouldProcessResponse', [
            'webhook' => $webhook,
        ]);

        $this->assertFalse($result);
    }

    public function testShouldProcessResponseReturnsTrueWhenConfigured(): void
    {
        $webhook = $this->createTestWebhook(
            configuration: json_encode(['processResponse' => true, 'async' => false])
        );

        $result = $this->invokePrivateMethod('shouldProcessResponse', [
            'webhook' => $webhook,
        ]);

        $this->assertTrue($result);
    }

    public function testShouldProcessResponseReturnsFalseWhenAsync(): void
    {
        $webhook = $this->createTestWebhook(
            configuration: json_encode(['processResponse' => true, 'async' => true])
        );

        $result = $this->invokePrivateMethod('shouldProcessResponse', [
            'webhook' => $webhook,
        ]);

        $this->assertFalse($result);
    }

    // ─── generateSignature tests ─────────────────────────────────────

    public function testGenerateSignatureReturnsHmac(): void
    {
        $payload = ['key' => 'value'];
        $secret = 'my-secret';

        $result = $this->invokePrivateMethod('generateSignature', [
            'payload' => $payload,
            'secret' => $secret,
        ]);

        $expected = hash_hmac('sha256', json_encode($payload), $secret);
        $this->assertSame($expected, $result);
    }

    public function testGenerateSignatureDifferentSecretsProduceDifferentResults(): void
    {
        $payload = ['key' => 'value'];

        $sig1 = $this->invokePrivateMethod('generateSignature', [
            'payload' => $payload,
            'secret' => 'secret1',
        ]);
        $sig2 = $this->invokePrivateMethod('generateSignature', [
            'payload' => $payload,
            'secret' => 'secret2',
        ]);

        $this->assertNotSame($sig1, $sig2);
    }

    // ─── interceptRequest tests ──────────────────────────────────────

    public function testInterceptRequestReturnsParamsWhenNoWebhooks(): void
    {
        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getParams')->willReturn(['key' => 'value']);

        $this->webhookMapper->method('findEnabled')->willReturn([]);

        $result = $this->service->interceptRequest($request, 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }

    // ─── Helper: inject mock Guzzle client via reflection ───────────

    /**
     * Inject a mock GuzzleClient into the service via reflection.
     *
     * @param GuzzleClient&MockObject $mockClient Mock Guzzle client.
     * @param WebhookService|null     $service    Service instance (defaults to $this->service).
     *
     * @return void
     */
    private function injectMockClient(GuzzleClient $mockClient, ?WebhookService $service = null): void
    {
        $target     = $service ?? $this->service;
        $reflection = new \ReflectionClass($target);
        $property   = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($target, $mockClient);
    }//end injectMockClient()

    // ─── sendRequest tests (via reflection) ─────────────────────────

    /**
     * Test sendRequest sends POST with JSON body and correct headers.
     *
     * @return void
     */
    public function testSendRequestPostWithJsonBody(): void
    {
        $webhook = $this->createTestWebhook(url: 'https://example.com/hook');
        $webhook->setMethod('POST');

        $mockResponse = new GuzzleResponse(200, [], '{"ok":true}');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->identicalTo('POST'),
                $this->identicalTo('https://example.com/hook'),
                $this->callback(function ($options) {
                    return isset($options['json'])
                        && $options['json'] === ['key' => 'value']
                        && isset($options['headers']['Content-Type'])
                        && $options['headers']['Content-Type'] === 'application/json';
                })
            )
            ->willReturn($mockResponse);

        $this->injectMockClient($mockClient);

        $result = $this->invokePrivateMethod('sendRequest', [
            'webhook' => $webhook,
            'payload' => ['key' => 'value'],
        ]);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('{"ok":true}', $result['body']);
    }//end testSendRequestPostWithJsonBody()

    /**
     * Test sendRequest sends GET with query parameters instead of body.
     *
     * @return void
     */
    public function testSendRequestGetUsesQueryParams(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setMethod('GET');

        $mockResponse = new GuzzleResponse(200, [], 'ok');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->identicalTo('GET'),
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['query'])
                        && !isset($options['json']);
                })
            )
            ->willReturn($mockResponse);

        $this->injectMockClient($mockClient);

        $result = $this->invokePrivateMethod('sendRequest', [
            'webhook' => $webhook,
            'payload' => ['q' => 'search'],
        ]);

        $this->assertSame(200, $result['status_code']);
    }//end testSendRequestGetUsesQueryParams()

    /**
     * Test sendRequest adds HMAC signature header when secret is set.
     *
     * @return void
     */
    public function testSendRequestAddsSignatureWhenSecretSet(): void
    {
        $webhook = $this->createTestWebhook(secret: 'my-secret');
        $webhook->setMethod('POST');

        $payload           = ['data' => 'test'];
        $expectedSignature = hash_hmac('sha256', json_encode($payload), 'my-secret');

        $mockResponse = new GuzzleResponse(200);
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($options) use ($expectedSignature) {
                    return isset($options['headers']['X-Webhook-Signature'])
                        && $options['headers']['X-Webhook-Signature'] === $expectedSignature;
                })
            )
            ->willReturn($mockResponse);

        $this->injectMockClient($mockClient);

        $this->invokePrivateMethod('sendRequest', [
            'webhook' => $webhook,
            'payload' => $payload,
        ]);
    }//end testSendRequestAddsSignatureWhenSecretSet()

    /**
     * Test sendRequest does not add signature header when no secret.
     *
     * @return void
     */
    public function testSendRequestNoSignatureWithoutSecret(): void
    {
        $webhook = $this->createTestWebhook(secret: null);
        $webhook->setMethod('POST');

        $mockResponse = new GuzzleResponse(200);
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($options) {
                    return !isset($options['headers']['X-Webhook-Signature']);
                })
            )
            ->willReturn($mockResponse);

        $this->injectMockClient($mockClient);

        $this->invokePrivateMethod('sendRequest', [
            'webhook' => $webhook,
            'payload' => ['data' => 'test'],
        ]);
    }//end testSendRequestNoSignatureWithoutSecret()

    /**
     * Test sendRequest merges custom headers from webhook entity.
     *
     * @return void
     */
    public function testSendRequestMergesCustomHeaders(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setMethod('POST');
        $webhook->setHeaders(json_encode(['X-Custom' => 'header-value']));

        $mockResponse = new GuzzleResponse(200);
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['headers']['X-Custom'])
                        && $options['headers']['X-Custom'] === 'header-value'
                        && isset($options['headers']['User-Agent'])
                        && $options['headers']['User-Agent'] === 'OpenRegister-Webhooks/1.0';
                })
            )
            ->willReturn($mockResponse);

        $this->injectMockClient($mockClient);

        $this->invokePrivateMethod('sendRequest', [
            'webhook' => $webhook,
            'payload' => ['data' => 'test'],
        ]);
    }//end testSendRequestMergesCustomHeaders()

    /**
     * Test sendRequest uses webhook timeout value.
     *
     * @return void
     */
    public function testSendRequestUsesWebhookTimeout(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setMethod('POST');
        $webhook->setTimeout(60);

        $mockResponse = new GuzzleResponse(200);
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['timeout'])
                        && $options['timeout'] === 60;
                })
            )
            ->willReturn($mockResponse);

        $this->injectMockClient($mockClient);

        $this->invokePrivateMethod('sendRequest', [
            'webhook' => $webhook,
            'payload' => [],
        ]);
    }//end testSendRequestUsesWebhookTimeout()

    // ─── deliverWebhook success and failure paths ───────────────────

    /**
     * Test deliverWebhook returns true on successful HTTP delivery.
     *
     * @return void
     */
    public function testDeliverWebhookReturnsTrueOnSuccess(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');

        $mockResponse = new GuzzleResponse(200, [], '{"status":"ok"}');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturn($mockResponse);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->expects($this->once())
            ->method('updateStatistics')
            ->with($this->identicalTo($webhook), $this->isTrue());

        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getSuccess() === true
                    && $log->getStatusCode() === 200
                    && $log->getResponseBody() === '{"status":"ok"}';
            }));

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['objectType' => 'object']
        );

        $this->assertTrue($result);
    }//end testDeliverWebhookReturnsTrueOnSuccess()

    /**
     * Test deliverWebhook returns false and logs error on RequestException with response.
     *
     * @return void
     */
    public function testDeliverWebhookReturnsFalseOnRequestExceptionWithResponse(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');
        $webhook->setMaxRetries(1);

        $guzzleRequest  = new GuzzleRequest('POST', 'https://example.com/hook');
        $guzzleResponse = new GuzzleResponse(500, [], '{"error":"Internal Server Error"}');
        $exception      = new RequestException('Server error', $guzzleRequest, $guzzleResponse);

        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willThrowException($exception);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->expects($this->once())
            ->method('updateStatistics')
            ->with($this->identicalTo($webhook), $this->isFalse());

        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getSuccess() === false
                    && $log->getStatusCode() === 500
                    && $log->getResponseBody() === '{"error":"Internal Server Error"}'
                    && str_contains($log->getErrorMessage(), 'Server error');
            }));

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['objectType' => 'object']
        );

        $this->assertFalse($result);
    }//end testDeliverWebhookReturnsFalseOnRequestExceptionWithResponse()

    /**
     * Test deliverWebhook returns false on RequestException without response (connection error).
     *
     * @return void
     */
    public function testDeliverWebhookReturnsFalseOnConnectionError(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');
        $webhook->setMaxRetries(1);

        $guzzleRequest = new GuzzleRequest('POST', 'https://example.com/hook');
        $exception     = new RequestException('Connection refused', $guzzleRequest, null);

        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willThrowException($exception);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->expects($this->once())
            ->method('updateStatistics')
            ->with($this->identicalTo($webhook), $this->isFalse());

        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getSuccess() === false
                    && $log->getStatusCode() === null
                    && str_contains($log->getErrorMessage(), 'Connection refused');
            }));

        $result = $this->service->deliverWebhook(
            $webhook,
            'SomeEvent',
            ['data' => 'test']
        );

        $this->assertFalse($result);
    }//end testDeliverWebhookReturnsFalseOnConnectionError()

    /**
     * Test deliverWebhook returns false on unexpected (non-Request) exception.
     *
     * @return void
     */
    public function testDeliverWebhookReturnsFalseOnUnexpectedException(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');

        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')
            ->willThrowException(new \RuntimeException('Unexpected error'));
        $this->injectMockClient($mockClient);

        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getSuccess() === false
                    && $log->getErrorMessage() === 'Unexpected error';
            }));

        $result = $this->service->deliverWebhook(
            $webhook,
            'SomeEvent',
            ['data' => 'test']
        );

        $this->assertFalse($result);
    }//end testDeliverWebhookReturnsFalseOnUnexpectedException()

    /**
     * Test deliverWebhook schedules retry when attempt is below max retries.
     *
     * @return void
     */
    public function testDeliverWebhookSchedulesRetryOnFailure(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');
        $webhook->setMaxRetries(5);
        $webhook->setRetryPolicy('fixed');

        $guzzleRequest = new GuzzleRequest('POST', 'https://example.com/hook');
        $exception     = new RequestException('Timeout', $guzzleRequest, null);

        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willThrowException($exception);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->method('updateStatistics');

        // Log should have nextRetryAt set.
        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getNextRetryAt() instanceof \DateTime
                    && $log->getRequestBody() !== null;
            }));

        // Logger should log retry scheduling.
        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->logicalOr(
                    $this->stringContains('Scheduling webhook retry'),
                    $this->anything()
                ),
                $this->anything()
            );

        $this->service->deliverWebhook(
            $webhook,
            'SomeEvent',
            ['data' => 'test'],
            2
        );
    }//end testDeliverWebhookSchedulesRetryOnFailure()

    /**
     * Test deliverWebhook does not schedule retry when attempt equals max retries.
     *
     * @return void
     */
    public function testDeliverWebhookDoesNotRetryAtMaxAttempts(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');
        $webhook->setMaxRetries(3);

        $guzzleRequest = new GuzzleRequest('POST', 'https://example.com/hook');
        $exception     = new RequestException('Error', $guzzleRequest, null);

        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willThrowException($exception);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->method('updateStatistics');

        // Log should NOT have nextRetryAt set (attempt 3 >= maxRetries 3).
        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getNextRetryAt() === null;
            }));

        $this->service->deliverWebhook(
            $webhook,
            'SomeEvent',
            ['data' => 'test'],
            3
        );
    }//end testDeliverWebhookDoesNotRetryAtMaxAttempts()

    /**
     * Test deliverWebhook extracts JSON message from error response body.
     *
     * @return void
     */
    public function testDeliverWebhookExtractsJsonMessageFromErrorResponse(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');
        $webhook->setMaxRetries(1);

        $guzzleRequest  = new GuzzleRequest('POST', 'https://example.com/hook');
        $guzzleResponse = new GuzzleResponse(400, [], '{"message":"Bad request format"}');
        $exception      = new RequestException('Client error', $guzzleRequest, $guzzleResponse);

        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willThrowException($exception);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->method('updateStatistics');

        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return str_contains($log->getErrorMessage(), 'Bad request format');
            }));

        $this->service->deliverWebhook(
            $webhook,
            'SomeEvent',
            ['data' => 'test']
        );
    }//end testDeliverWebhookExtractsJsonMessageFromErrorResponse()

    /**
     * Test deliverWebhook extracts JSON error key from error response body.
     *
     * @return void
     */
    public function testDeliverWebhookExtractsJsonErrorKeyFromResponse(): void
    {
        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');
        $webhook->setMaxRetries(1);

        $guzzleRequest  = new GuzzleRequest('POST', 'https://example.com/hook');
        $guzzleResponse = new GuzzleResponse(403, [], '{"error":"Forbidden"}');
        $exception      = new RequestException('Client error', $guzzleRequest, $guzzleResponse);

        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willThrowException($exception);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->method('updateStatistics');

        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return str_contains($log->getErrorMessage(), 'Forbidden');
            }));

        $this->service->deliverWebhook(
            $webhook,
            'SomeEvent',
            ['data' => 'test']
        );
    }//end testDeliverWebhookExtractsJsonErrorKeyFromResponse()

    // ─── scheduleRetry tests (via reflection) ───────────────────────

    /**
     * Test scheduleRetry logs info about retry scheduling.
     *
     * @return void
     */
    public function testScheduleRetryLogsInfo(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('fixed');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Scheduling webhook retry'),
                $this->callback(function ($context) {
                    return $context['attempt'] === 2
                        && $context['delay'] === 300;
                })
            );

        $this->invokePrivateMethod('scheduleRetry', [
            'webhook'   => $webhook,
            'eventName' => 'SomeEvent',
            '_payload'  => ['data' => 'test'],
            'attempt'   => 2,
        ]);
    }//end testScheduleRetryLogsInfo()

    // ─── findWebhooksForInterception tests (via reflection) ─────────

    /**
     * Test findWebhooksForInterception returns empty when no enabled webhooks.
     *
     * @return void
     */
    public function testFindWebhooksForInterceptionReturnsEmptyWhenNone(): void
    {
        $this->webhookMapper->method('findEnabled')->willReturn([]);

        $result = $this->invokePrivateMethod('findWebhooksForInterception', [
            'eventType' => 'object.creating',
        ]);

        $this->assertEmpty($result);
    }//end testFindWebhooksForInterceptionReturnsEmptyWhenNone()

    /**
     * Test findWebhooksForInterception filters out non-interception webhooks.
     *
     * @return void
     */
    public function testFindWebhooksForInterceptionFiltersNonInterceptWebhooks(): void
    {
        $webhook1 = $this->createTestWebhook(id: 1);
        // No interceptRequests config.

        $webhook2 = $this->createTestWebhook(id: 2);
        $webhook2->setConfiguration(json_encode(['interceptRequests' => false]));

        $this->webhookMapper->method('findEnabled')->willReturn([$webhook1, $webhook2]);

        $result = $this->invokePrivateMethod('findWebhooksForInterception', [
            'eventType' => 'object.creating',
        ]);

        $this->assertEmpty($result);
    }//end testFindWebhooksForInterceptionFiltersNonInterceptWebhooks()

    /**
     * Test findWebhooksForInterception returns matching interception webhooks.
     *
     * @return void
     */
    public function testFindWebhooksForInterceptionReturnsMatchingWebhooks(): void
    {
        // Webhook configured for interception with no event filter (matches all).
        $webhook = $this->createTestWebhook(id: 1);
        $webhook->setConfiguration(json_encode(['interceptRequests' => true]));

        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);

        $result = $this->invokePrivateMethod('findWebhooksForInterception', [
            'eventType' => 'object.creating',
        ]);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]->getId());
    }//end testFindWebhooksForInterceptionReturnsMatchingWebhooks()

    /**
     * Test findWebhooksForInterception filters by event type.
     *
     * @return void
     */
    public function testFindWebhooksForInterceptionFiltersByEventType(): void
    {
        // Webhook configured for interception but listens to different event.
        $webhook = $this->createTestWebhook(id: 1);
        $webhook->setConfiguration(json_encode(['interceptRequests' => true]));
        $webhook->setEvents(json_encode(['OCA\\OpenRegister\\Event\\SchemaCreatedEvent']));

        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);

        $result = $this->invokePrivateMethod('findWebhooksForInterception', [
            'eventType' => 'object.creating',
        ]);

        // Should not match because event class does not match.
        $this->assertEmpty($result);
    }//end testFindWebhooksForInterceptionFiltersByEventType()

    // ─── dispatchEvent with matching webhooks ───────────────────────
    // Note: synchronous delivery from dispatchEvent was removed in
    // async-webhook-delivery — dispatchEvent now enqueues a WebhookDeliveryJob
    // per matching webhook (see testDispatchEventEnqueuesDeliveryJobPerWebhook...).
    // updateStatistics / log insert happen in the job, not synchronously here.

    /**
     * Test dispatchEvent logs debug when no webhooks found.
     *
     * @return void
     */
    public function testDispatchEventLogsDebugForNoWebhooks(): void
    {
        $event = $this->createMock(\OCP\EventDispatcher\Event::class);

        $this->webhookMapper->method('findForEvent')->willReturn([]);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->stringContains('No webhooks configured'),
                $this->anything()
            );

        $this->service->dispatchEvent($event, 'SomeEvent', ['data' => 'test']);
    }//end testDispatchEventLogsDebugForNoWebhooks()

    /**
     * Test dispatchEvent logs info when webhooks are found.
     *
     * @return void
     */
    public function testDispatchEventLogsInfoWhenWebhooksFound(): void
    {
        $event = $this->createMock(\OCP\EventDispatcher\Event::class);

        $webhook = $this->createTestWebhook(enabled: false);
        $this->webhookMapper->method('findForEvent')->willReturn([$webhook]);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->stringContains('Dispatching event to webhooks'),
                $this->callback(function ($context) {
                    return $context['webhook_count'] === 1;
                })
            );

        $this->service->dispatchEvent($event, 'SomeEvent', ['data' => 'test']);
    }//end testDispatchEventLogsInfoWhenWebhooksFound()

    // ─── interceptRequest with CloudEventFormatter ──────────────────

    /**
     * Test interceptRequest with CloudEventFormatter formats the request.
     *
     * @return void
     */
    public function testInterceptRequestWithCloudEventFormatter(): void
    {
        $cloudEventFormatter = $this->createMock(CloudEventFormatter::class);
        $service = new WebhookService(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger,
            webhookLogMapper: $this->webhookLogMapper,
            mappingService: $this->mappingService,
            mappingMapper: $this->mappingMapper,
            jobList: $this->jobList,
            cloudEventFormatter: $cloudEventFormatter
        );

        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getParams')->willReturn(['key' => 'value']);

        // No interception webhooks.
        $this->webhookMapper->method('findEnabled')->willReturn([]);

        $result = $service->interceptRequest($request, 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testInterceptRequestWithCloudEventFormatter()

    /**
     * Test interceptRequest with matching webhooks delivers and returns data.
     *
     * @return void
     */
    public function testInterceptRequestWithMatchingWebhooks(): void
    {
        $webhook = $this->createTestWebhook(id: 1, enabled: true);
        $webhook->setConfiguration(json_encode(['interceptRequests' => true]));
        $webhook->setMethod('POST');

        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);
        // Tenant-agnostic fast-path scan must also see the webhook.
        $this->webhookMapper->method('findEnabledForInterceptionScan')->willReturn([$webhook]);

        // Inject mock client for delivery.
        $mockResponse = new GuzzleResponse(200, [], '{"ok":true}');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturn($mockResponse);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->method('updateStatistics');
        $this->webhookLogMapper->method('insert');

        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getParams')->willReturn(['key' => 'value']);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getPathInfo')->willReturn('/api/objects/1/2');

        $result = $this->service->interceptRequest($request, 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testInterceptRequestWithMatchingWebhooks()

    /**
     * Test interceptRequest continues processing on delivery exception.
     *
     * @return void
     */
    public function testInterceptRequestContinuesOnDeliveryException(): void
    {
        $webhook = $this->createTestWebhook(id: 1, enabled: true);
        $webhook->setConfiguration(json_encode(['interceptRequests' => true]));
        $webhook->setMethod('POST');

        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);
        // Tenant-agnostic fast-path scan must also see the webhook.
        $this->webhookMapper->method('findEnabledForInterceptionScan')->willReturn([$webhook]);

        // Inject mock client that throws.
        $mockClient = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')
            ->willThrowException(new \RuntimeException('Connection failed'));
        $this->injectMockClient($mockClient);

        $this->webhookLogMapper->method('insert');

        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getParams')->willReturn(['key' => 'value']);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getPathInfo')->willReturn('/api/objects');

        // Should not throw, should return original data.
        $result = $this->service->interceptRequest($request, 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testInterceptRequestContinuesOnDeliveryException()

    /**
     * Test interceptRequest uses fallback format when no CloudEventFormatter.
     *
     * @return void
     */
    public function testInterceptRequestFallbackFormatWithoutFormatter(): void
    {
        // Service created without CloudEventFormatter (default setUp).
        $webhook = $this->createTestWebhook(id: 1, enabled: true);
        $webhook->setConfiguration(json_encode(['interceptRequests' => true]));
        $webhook->setMethod('POST');

        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);
        // Tenant-agnostic fast-path scan must also see the webhook.
        $this->webhookMapper->method('findEnabledForInterceptionScan')->willReturn([$webhook]);

        $mockResponse = new GuzzleResponse(200, [], '{}');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturn($mockResponse);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->method('updateStatistics');
        $this->webhookLogMapper->method('insert');

        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getParams')->willReturn(['key' => 'value']);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getPathInfo')->willReturn('/api/objects/1/2');

        $result = $this->service->interceptRequest($request, 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testInterceptRequestFallbackFormatWithoutFormatter()

    // ─── applyMappingTransformation generic exception test ──────────

    /**
     * Test applyMappingTransformation returns null on generic find exception.
     *
     * @return void
     */
    public function testApplyMappingTransformationReturnsNullOnGenericFindException(): void
    {
        $webhook = $this->createTestWebhook(mapping: 42);

        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willThrowException(new \RuntimeException('Database connection error'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to load mapping entity'),
                $this->anything()
            );

        $result = $this->invokePrivateMethod(
            'applyMappingTransformation',
            [
                'mappingId' => 42,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => ['objectType' => 'object'],
                'webhook'   => $webhook,
            ]
        );

        $this->assertNull($result);
    }//end testApplyMappingTransformationReturnsNullOnGenericFindException()

    // ─── buildPayload: CloudEvents configured but no formatter ──────

    /**
     * Test buildPayload falls back to standard when CloudEvents configured but no formatter.
     *
     * @return void
     */
    public function testBuildPayloadCloudEventsConfiguredButNoFormatter(): void
    {
        // Service without CloudEventFormatter (default setUp).
        $webhook = $this->createTestWebhook(
            mapping: null,
            configuration: json_encode(['useCloudEvents' => true])
        );
        $payload = ['objectType' => 'object', 'action' => 'create'];

        $result = $this->invokePrivateMethod(
            'buildPayload',
            [
                'webhook'   => $webhook,
                'eventName' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'payload'   => $payload,
                'attempt'   => 1,
            ]
        );

        // Should fall back to standard format since formatter is null.
        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('webhook', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('attempt', $result);
        $this->assertSame(1, $result['attempt']);
    }//end testBuildPayloadCloudEventsConfiguredButNoFormatter()

    /**
     * Test buildPayload standard format includes correct attempt number.
     *
     * @return void
     */
    public function testBuildPayloadStandardFormatWithHigherAttempt(): void
    {
        $webhook = $this->createTestWebhook(mapping: null);
        $payload = ['objectType' => 'object'];

        $result = $this->invokePrivateMethod(
            'buildPayload',
            [
                'webhook'   => $webhook,
                'eventName' => 'SomeEvent',
                'payload'   => $payload,
                'attempt'   => 5,
            ]
        );

        $this->assertSame(5, $result['attempt']);
        $this->assertSame('SomeEvent', $result['event']);
    }//end testBuildPayloadStandardFormatWithHigherAttempt()

    // ─── passesFilters: filter key missing from payload ─────────────

    /**
     * Test passesFilters returns false when filter key is missing from payload.
     *
     * @return void
     */
    public function testPassesFiltersReturnsFalseWhenKeyMissing(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setFilters(json_encode(['nonexistent' => 'value']));

        $result = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['action' => 'create'],
        ]);

        $this->assertFalse($result);
    }//end testPassesFiltersReturnsFalseWhenKeyMissing()

    /**
     * Test passesFilters with dot-notation filter key.
     *
     * @return void
     */
    public function testPassesFiltersWithDotNotationKey(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setFilters(json_encode(['object.type' => 'person']));

        $resultMatch = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['object' => ['type' => 'person']],
        ]);
        $this->assertTrue($resultMatch);

        $resultNoMatch = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['object' => ['type' => 'organisation']],
        ]);
        $this->assertFalse($resultNoMatch);
    }//end testPassesFiltersWithDotNotationKey()

    /**
     * Test passesFilters with array filter and dot-notation key.
     *
     * @return void
     */
    public function testPassesFiltersArrayFilterWithDotNotation(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setFilters(json_encode(['object.type' => ['person', 'organisation']]));

        $result = $this->invokePrivateMethod('passesFilters', [
            'webhook' => $webhook,
            'payload' => ['object' => ['type' => 'organisation']],
        ]);

        $this->assertTrue($result);
    }//end testPassesFiltersArrayFilterWithDotNotation()

    // ─── eventTypeToEventClass additional cases ─────────────────────

    /**
     * Test eventTypeToEventClass with various event types.
     *
     * @return void
     */
    public function testEventTypeToEventClassUpdated(): void
    {
        $result = $this->invokePrivateMethod('eventTypeToEventClass', [
            'eventType' => 'object.updated',
        ]);
        $this->assertSame('OCA\\OpenRegister\\Event\\ObjectUpdatedEvent', $result);
    }//end testEventTypeToEventClassUpdated()

    /**
     * Test eventTypeToEventClass with schema.deleted event type.
     *
     * @return void
     */
    public function testEventTypeToEventClassSchemaDeleted(): void
    {
        $result = $this->invokePrivateMethod('eventTypeToEventClass', [
            'eventType' => 'schema.deleted',
        ]);
        $this->assertSame('OCA\\OpenRegister\\Event\\SchemaDeletedEvent', $result);
    }//end testEventTypeToEventClassSchemaDeleted()

    /**
     * Test eventTypeToEventClass with register.updating event type.
     *
     * @return void
     */
    public function testEventTypeToEventClassRegisterUpdating(): void
    {
        $result = $this->invokePrivateMethod('eventTypeToEventClass', [
            'eventType' => 'register.updating',
        ]);
        $this->assertSame('OCA\\OpenRegister\\Event\\RegisterUpdatingEvent', $result);
    }//end testEventTypeToEventClassRegisterUpdating()

    // ─── calculateRetryDelay edge cases ─────────────────────────────

    /**
     * Test calculateRetryDelay exponential at attempt 0.
     *
     * @return void
     */
    public function testCalculateRetryDelayExponentialAttemptZero(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('exponential');

        $delay = $this->invokePrivateMethod('calculateRetryDelay', [
            'webhook' => $webhook,
            'attempt' => 0,
        ]);

        $this->assertSame(60, $delay); // 2^0 * 60 = 60
    }//end testCalculateRetryDelayExponentialAttemptZero()

    /**
     * Test calculateRetryDelay linear at attempt 1.
     *
     * @return void
     */
    public function testCalculateRetryDelayLinearAttemptOne(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('linear');

        $delay = $this->invokePrivateMethod('calculateRetryDelay', [
            'webhook' => $webhook,
            'attempt' => 1,
        ]);

        $this->assertSame(300, $delay); // 1 * 300
    }//end testCalculateRetryDelayLinearAttemptOne()

    // ─── calculateNextRetryTime policy-specific tests ───────────────

    /**
     * Test calculateNextRetryTime with exponential policy produces correct delay.
     *
     * @return void
     */
    public function testCalculateNextRetryTimeExponentialDelay(): void
    {
        $webhook = $this->createTestWebhook();
        $webhook->setRetryPolicy('exponential');

        $before = new \DateTime();
        $result = $this->invokePrivateMethod('calculateNextRetryTime', [
            'webhook' => $webhook,
            'attempt' => 2,
        ]);

        // Should be at least 240 seconds (2^2 * 60) in the future.
        $expectedMin = clone $before;
        $expectedMin->modify('+240 seconds');

        $this->assertGreaterThanOrEqual($expectedMin, $result);
    }//end testCalculateNextRetryTimeExponentialDelay()

    // ─── generateSignature edge cases ───────────────────────────────

    /**
     * Test generateSignature with empty payload.
     *
     * @return void
     */
    public function testGenerateSignatureWithEmptyPayload(): void
    {
        $result = $this->invokePrivateMethod('generateSignature', [
            'payload' => [],
            'secret' => 'secret',
        ]);

        $expected = hash_hmac('sha256', json_encode([]), 'secret');
        $this->assertSame($expected, $result);
    }//end testGenerateSignatureWithEmptyPayload()

    /**
     * Test generateSignature with nested payload.
     *
     * @return void
     */
    public function testGenerateSignatureWithNestedPayload(): void
    {
        $payload = ['a' => ['b' => 'c'], 'd' => [1, 2, 3]];
        $result  = $this->invokePrivateMethod('generateSignature', [
            'payload' => $payload,
            'secret'  => 'secret',
        ]);

        $expected = hash_hmac('sha256', json_encode($payload), 'secret');
        $this->assertSame($expected, $result);
    }//end testGenerateSignatureWithNestedPayload()

    // ─── shouldProcessResponse additional cases ─────────────────────

    /**
     * Test shouldProcessResponse with processResponse false in config.
     *
     * @return void
     */
    public function testShouldProcessResponseFalseInConfig(): void
    {
        $webhook = $this->createTestWebhook(
            configuration: json_encode(['processResponse' => false, 'async' => false])
        );

        $result = $this->invokePrivateMethod('shouldProcessResponse', [
            'webhook' => $webhook,
        ]);

        $this->assertFalse($result);
    }//end testShouldProcessResponseFalseInConfig()

    /**
     * Test shouldProcessResponse with only processResponse set (no async key).
     *
     * @return void
     */
    public function testShouldProcessResponseWithoutAsyncKey(): void
    {
        $webhook = $this->createTestWebhook(
            configuration: json_encode(['processResponse' => true])
        );

        $result = $this->invokePrivateMethod('shouldProcessResponse', [
            'webhook' => $webhook,
        ]);

        // async defaults to false, so should return true.
        $this->assertTrue($result);
    }//end testShouldProcessResponseWithoutAsyncKey()

    // ─── deliverWebhook with custom attempt number ──────────────────

    /**
     * Test deliverWebhook logs correct attempt number.
     *
     * @return void
     */
    public function testDeliverWebhookPassesAttemptToBuildPayload(): void
    {
        $webhook = $this->createTestWebhook(enabled: true, mapping: null);
        $webhook->setMethod('POST');

        $mockResponse = new GuzzleResponse(200, [], '{}');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturn($mockResponse);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->method('updateStatistics');

        $this->webhookLogMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getAttempt() === 3;
            }));

        $this->service->deliverWebhook(
            $webhook,
            'SomeEvent',
            ['data' => 'test'],
            3
        );
    }//end testDeliverWebhookPassesAttemptToBuildPayload()

    // ─── getNestedValue additional edge cases ───────────────────────

    /**
     * Test getNestedValue with non-array intermediate value.
     *
     * @return void
     */
    public function testGetNestedValueWithNonArrayIntermediate(): void
    {
        $result = $this->invokePrivateMethod('getNestedValue', [
            'array' => ['a' => 'string-value'],
            'key'   => 'a.b',
        ]);

        // PHP's isset on a string with key returns false, so should return null.
        $this->assertNull($result);
    }//end testGetNestedValueWithNonArrayIntermediate()

    /**
     * Test getNestedValue with empty key returns null.
     *
     * @return void
     */
    public function testGetNestedValueWithEmptyStringKey(): void
    {
        $result = $this->invokePrivateMethod('getNestedValue', [
            'array' => ['name' => 'test'],
            'key'   => '',
        ]);

        $this->assertNull($result);
    }//end testGetNestedValueWithEmptyStringKey()

    // ─── interceptRequest with CloudEventFormatter and webhooks ─────

    /**
     * Test interceptRequest with CloudEventFormatter and matching interception webhook.
     *
     * @return void
     */
    public function testInterceptRequestUsesCloudEventFormatterWhenAvailable(): void
    {
        $cloudEventFormatter = $this->createMock(CloudEventFormatter::class);
        $service = new WebhookService(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger,
            webhookLogMapper: $this->webhookLogMapper,
            mappingService: $this->mappingService,
            mappingMapper: $this->mappingMapper,
            jobList: $this->jobList,
            cloudEventFormatter: $cloudEventFormatter
        );

        $webhook = $this->createTestWebhook(id: 1, enabled: true);
        $webhook->setConfiguration(json_encode(['interceptRequests' => true]));
        $webhook->setMethod('POST');

        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);
        // Tenant-agnostic fast-path scan must also see the webhook.
        $this->webhookMapper->method('findEnabledForInterceptionScan')->willReturn([$webhook]);

        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getParams')->willReturn(['key' => 'value']);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getPathInfo')->willReturn('/api/objects');

        $cloudEventFormatter->expects($this->once())
            ->method('formatRequestAsCloudEvent')
            ->with(
                $this->identicalTo($request),
                $this->identicalTo('object.creating')
            )
            ->willReturn(['specversion' => '1.0', 'type' => 'object.creating']);

        // Inject mock client for delivery.
        $mockResponse = new GuzzleResponse(200, [], '{}');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturn($mockResponse);
        $this->injectMockClient($mockClient, $service);

        $this->webhookMapper->method('updateStatistics');
        $this->webhookLogMapper->method('insert');

        $result = $service->interceptRequest($request, 'object.creating');

        $this->assertSame(['key' => 'value'], $result);
    }//end testInterceptRequestUsesCloudEventFormatterWhenAvailable()

    // ─── deliverWebhook with mapping transformation ─────────────────

    /**
     * Test deliverWebhook uses mapping transformation for payload.
     *
     * @return void
     */
    public function testDeliverWebhookWithMappingTransformation(): void
    {
        $webhook = $this->createTestWebhook(enabled: true, mapping: 42);
        $webhook->setMethod('POST');

        $mappingEntity = $this->createTestMapping(id: 42);
        $this->mappingMapper->method('find')->with(42)->willReturn($mappingEntity);

        $transformedPayload = ['kanaal' => 'create', 'resource' => 'object'];
        $this->mappingService->method('executeMapping')->willReturn($transformedPayload);

        $mockResponse = new GuzzleResponse(200, [], '{"ok":true}');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($options) {
                    // Verify the transformed payload is sent.
                    return isset($options['json'])
                        && $options['json']['kanaal'] === 'create'
                        && $options['json']['resource'] === 'object';
                })
            )
            ->willReturn($mockResponse);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->method('updateStatistics');
        $this->webhookLogMapper->method('insert');

        $result = $this->service->deliverWebhook(
            $webhook,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['objectType' => 'object', 'action' => 'create']
        );

        $this->assertTrue($result);
    }//end testDeliverWebhookWithMappingTransformation()


    // ─── Wave-3 C9: SSRF / TLS / response-body cap tests ────────────────
    //
    // The webhook HTTP client used to ship with `verify: false`,
    // `allow_redirects: true` (no allowlist), no body cap, and full-body
    // logging. That gave any admin with webhook-create rights an SSRF
    // primitive that could probe internal services through TLS-error
    // suppression and exfiltrate via response-body echo. The fix:
    //   1. TLS verification ON.
    //   2. Initial URL passes assertSafeWebhookUri(); redirects re-validated.
    //   3. Persisted body capped at 1 MB; logged preview capped at 1 KB and
    //      redacted entirely when the target host is private/internal.
    //
    // These tests exercise the visible parts of that contract via the
    // private helpers (reflection) and via the public client config.

    /**
     * The Guzzle client must be initialised with TLS verification enabled.
     *
     * Verifies the C9 fix: `verify: true` (was `false`).
     */
    public function testGuzzleClientHasTlsVerificationEnabled(): void
    {
        $clientProp = $this->reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $client = $clientProp->getValue($this->service);

        $this->assertInstanceOf(GuzzleClient::class, $client);
        $this->assertTrue(
            $client->getConfig('verify'),
            'Webhook HTTP client must enable TLS verification (C9).'
        );
    }//end testGuzzleClientHasTlsVerificationEnabled()

    /**
     * The Guzzle client must not blindly follow redirects.
     *
     * The `allow_redirects` config must be an array (configured with an
     * `on_redirect` callback that re-validates each Location), not the
     * boolean `true` that the old config used.
     */
    public function testGuzzleClientRedirectsUseAllowlistCallback(): void
    {
        $clientProp = $this->reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $client = $clientProp->getValue($this->service);

        $redirectsConfig = $client->getConfig('allow_redirects');
        $this->assertIsArray(
            $redirectsConfig,
            'allow_redirects must be configured as an array with an on_redirect callback (C9).'
        );
        $this->assertArrayHasKey('on_redirect', $redirectsConfig);
        $this->assertIsCallable($redirectsConfig['on_redirect']);
    }//end testGuzzleClientRedirectsUseAllowlistCallback()

    /**
     * Provider: URLs that the SSRF guard must reject.
     *
     * Covers each blocked range from assertSafeWebhookUri() plus an
     * unsupported scheme (file://, used here to verify the scheme check
     * runs before the DNS check).
     *
     * @return array<string, array{0: string}>
     */
    public static function blockedWebhookUrls(): array
    {
        return [
            'loopback IPv4'              => ['http://127.0.0.1/webhook'],
            'loopback hostname'          => ['http://localhost/webhook'],
            'RFC-1918 10/8'              => ['https://10.0.0.5/webhook'],
            'RFC-1918 172.16/12'         => ['https://172.16.0.1/webhook'],
            'RFC-1918 192.168/16'        => ['http://192.168.1.1/webhook'],
            'AWS cloud metadata (169.254)' => ['http://169.254.169.254/latest/'],
            'file scheme (unsupported)'  => ['file:///etc/passwd'],
        ];
    }//end blockedWebhookUrls()

    /**
     * The private SSRF guard must reject internal/private/loopback/metadata URLs.
     *
     * @param string $url URL to validate.
     *
     * @dataProvider blockedWebhookUrls
     */
    public function testAssertSafeWebhookUriBlocksInternalRanges(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invokePrivateMethod('assertSafeWebhookUri', ['uri' => $url]);
    }//end testAssertSafeWebhookUriBlocksInternalRanges()

    /**
     * A normal external HTTPS URL must pass the SSRF guard.
     */
    public function testAssertSafeWebhookUriAllowsPublicHttps(): void
    {
        // No exception expected.
        $this->invokePrivateMethod(
            'assertSafeWebhookUri',
            ['uri' => 'https://example.com/webhook']
        );
        $this->addToAssertionCount(1);
    }//end testAssertSafeWebhookUriAllowsPublicHttps()

    /**
     * Response-body cap (MAX_RESPONSE_BODY_BYTES = 1 MB) for persistent storage.
     *
     * Bodies under the cap must pass through unchanged.
     */
    public function testCapResponseBodyKeepsShortBodies(): void
    {
        $body = 'short response';
        $result = $this->invokePrivateMethod('capResponseBody', ['body' => $body]);
        $this->assertSame($body, $result);
    }//end testCapResponseBodyKeepsShortBodies()

    /**
     * Bodies over the 1 MB cap must be truncated.
     */
    public function testCapResponseBodyTruncatesOversize(): void
    {
        // 1.5 MB body.
        $body = str_repeat('A', 1572864);
        $result = $this->invokePrivateMethod('capResponseBody', ['body' => $body]);

        // Truncated to 1 MB + suffix.
        $this->assertLessThan(strlen($body), strlen($result));
        $this->assertStringEndsWith('[truncated]', $result);
        $this->assertStringStartsWith('AAAA', $result);
    }//end testCapResponseBodyTruncatesOversize()

    /**
     * The log preview must redact entirely when the target is on a private network.
     *
     * Prevents the SSRF probe → response-echo-into-logs exfiltration channel.
     */
    public function testPreviewResponseBodyRedactsPrivateTargets(): void
    {
        $body = 'internal-secret-data';
        $result = $this->invokePrivateMethod(
            'previewResponseBody',
            ['body' => $body, 'url' => 'http://127.0.0.1/probe']
        );

        $this->assertStringContainsString('redacted', $result);
        $this->assertStringNotContainsString('internal-secret-data', $result);
    }//end testPreviewResponseBodyRedactsPrivateTargets()

    /**
     * The log preview keeps content for public targets, but truncates >1 KB.
     */
    public function testPreviewResponseBodyTruncatesLongPublicBodies(): void
    {
        // 2 KB body.
        $body = str_repeat('B', 2048);
        $result = $this->invokePrivateMethod(
            'previewResponseBody',
            ['body' => $body, 'url' => 'https://example.com/hook']
        );

        $this->assertLessThan(strlen($body), strlen($result));
        $this->assertStringEndsWith('[truncated]', $result);
    }//end testPreviewResponseBodyTruncatesLongPublicBodies()

    // ─── Wave-6 IPv6 SSRF guard tests ───────────────────────────────────
    //
    // The C9 fix from wave-3 covered IPv4 only. gethostbyname() + ip2long()
    // never return IPv6 data, so http://[::1]/ and http://[fd00::1]/ would
    // silently bypass the guard. Wave-6 adds:
    //   1. IPv6 literal detection via filter_var(FILTER_FLAG_IPV6) / colon heuristic.
    //   2. AAAA DNS look-up via dns_get_record().
    //   3. blockedIpv6Reason() covering ::1/128, ::/128, fc00::/7, fe80::/10,
    //      2001:db8::/32, and ::ffff:0:0/96 (with embedded-IPv4 re-validation).

    /**
     * Provider: IPv6 URLs that the SSRF guard must reject as literals.
     *
     * @return array<string, array{0: string}>
     */
    public static function blockedIpv6WebhookUrls(): array
    {
        return [
            'IPv6 loopback ::1'                       => ['http://[::1]/webhook'],
            'IPv6 unspecified ::'                     => ['http://[::]/webhook'],
            'IPv6 unique-local fd00::1'               => ['http://[fd00::1]/webhook'],
            'IPv6 unique-local fc00::1'               => ['http://[fc00::1]/webhook'],
            'IPv6 link-local fe80::1'                 => ['http://[fe80::1]/webhook'],
            'IPv6 documentation 2001:db8::1'          => ['http://[2001:db8::1]/webhook'],
            'IPv6 IPv4-mapped loopback ::ffff:127.0.0.1' => ['http://[::ffff:127.0.0.1]/webhook'],
            'IPv6 IPv4-mapped RFC-1918 ::ffff:10.0.0.1' => ['http://[::ffff:10.0.0.1]/webhook'],
            'IPv6 IPv4-mapped RFC-1918 ::ffff:192.168.1.1' => ['http://[::ffff:192.168.1.1]/webhook'],
            'IPv6 IPv4-mapped link-local ::ffff:169.254.169.254' => ['http://[::ffff:169.254.169.254]/webhook'],
        ];
    }//end blockedIpv6WebhookUrls()

    /**
     * assertSafeWebhookUri must reject IPv6 literal addresses in all blocked ranges.
     *
     * @param string $url URL to validate.
     *
     * @dataProvider blockedIpv6WebhookUrls
     */
    public function testAssertSafeWebhookUriBlocksIpv6Literals(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invokePrivateMethod('assertSafeWebhookUri', ['uri' => $url]);
    }//end testAssertSafeWebhookUriBlocksIpv6Literals()

    /**
     * A global-scope IPv6 URL (2001:db8::/32 excluded; a genuine public
     * address such as 2606:4700::1 — Cloudflare DNS) must pass the SSRF guard.
     */
    public function testAssertSafeWebhookUriAllowsPublicIpv6(): void
    {
        // 2606:4700::1 is a real Cloudflare anycast address (public, not reserved).
        $this->invokePrivateMethod(
            'assertSafeWebhookUri',
            ['uri' => 'https://[2606:4700::1]/webhook']
        );
        $this->addToAssertionCount(1);
    }//end testAssertSafeWebhookUriAllowsPublicIpv6()

    // ─── Per-hook allowPrivateTargets opt-in (SSRF bypass) ──────────────
    //
    // configuration.allowPrivateTargets lets an admin deliberately opt a single
    // webhook out of the IP-range checks for local testing (e.g.
    // http://localhost:8000). The scheme check stays enforced; the default
    // (flag absent / false) keeps full blocking.

    /**
     * Provider: private/loopback URLs that are normally blocked.
     *
     * @return array<string, array{0: string}>
     */
    public static function privateWebhookUrls(): array
    {
        return [
            'loopback IPv4 with port'   => ['http://127.0.0.1:8000/webhook'],
            'localhost hostname'        => ['http://localhost:8000/webhook'],
            'RFC-1918 10/8'             => ['https://10.0.0.5/webhook'],
            'RFC-1918 192.168/16'       => ['http://192.168.1.1/webhook'],
            'IPv6 loopback ::1'         => ['http://[::1]:8000/webhook'],
            'IPv6 unique-local fd00::1' => ['http://[fd00::1]/webhook'],
        ];
    }//end privateWebhookUrls()

    /**
     * With allowPrivate=true the guard permits private/loopback targets.
     *
     * @param string $url URL to validate.
     *
     * @dataProvider privateWebhookUrls
     */
    public function testAssertSafeWebhookUriAllowsPrivateWhenOptedIn(string $url): void
    {
        // No exception expected when the per-hook flag is set.
        $this->invokePrivateMethod(
            'assertSafeWebhookUri',
            ['uri' => $url, 'allowPrivate' => true]
        );
        $this->addToAssertionCount(1);
    }//end testAssertSafeWebhookUriAllowsPrivateWhenOptedIn()

    /**
     * Regression: with allowPrivate=false (the default) private targets stay blocked.
     *
     * @param string $url URL to validate.
     *
     * @dataProvider privateWebhookUrls
     */
    public function testAssertSafeWebhookUriBlocksPrivateWhenFlagFalse(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invokePrivateMethod(
            'assertSafeWebhookUri',
            ['uri' => $url, 'allowPrivate' => false]
        );
    }//end testAssertSafeWebhookUriBlocksPrivateWhenFlagFalse()

    /**
     * The scheme check stays enforced even when allowPrivate is set: a
     * non-http(s) scheme is still rejected.
     */
    public function testAssertSafeWebhookUriStillRejectsNonHttpSchemeWhenOptedIn(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invokePrivateMethod(
            'assertSafeWebhookUri',
            ['uri' => 'file:///etc/passwd', 'allowPrivate' => true]
        );
    }//end testAssertSafeWebhookUriStillRejectsNonHttpSchemeWhenOptedIn()

    /**
     * A redirect to a private target is permitted when the hook opted in.
     *
     * The per-request on_redirect callback set in sendRequest() delegates to
     * assertSafeWebhookUri(uri, allowPrivate), so this exercises that exact
     * delegation: allowPrivate=true ⇒ a private Location is accepted, while the
     * default client path (allowPrivate=false) still rejects it.
     */
    public function testRedirectToPrivateTargetHonoursPerHookFlag(): void
    {
        // allowPrivate=true: the redirect target is accepted.
        $this->invokePrivateMethod(
            'assertSafeWebhookUri',
            ['uri' => 'http://192.168.1.50:8000/callback', 'allowPrivate' => true]
        );
        $this->addToAssertionCount(1);

        // allowPrivate=false: the same redirect target is rejected.
        $this->expectException(\RuntimeException::class);
        $this->invokePrivateMethod(
            'assertSafeWebhookUri',
            ['uri' => 'http://192.168.1.50:8000/callback', 'allowPrivate' => false]
        );
    }//end testRedirectToPrivateTargetHonoursPerHookFlag()

    /**
     * blockedIpv6Reason must return 'loopback' for the ::1 address.
     */
    public function testBlockedIpv6ReasonLoopback(): void
    {
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => '::1']);
        $this->assertSame('loopback', $result);
    }//end testBlockedIpv6ReasonLoopback()

    /**
     * blockedIpv6Reason must return 'unspecified' for the :: address.
     */
    public function testBlockedIpv6ReasonUnspecified(): void
    {
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => '::']);
        $this->assertSame('unspecified', $result);
    }//end testBlockedIpv6ReasonUnspecified()

    /**
     * blockedIpv6Reason must return 'unique-local' for fc00::/7 addresses.
     */
    public function testBlockedIpv6ReasonUniqueLocal(): void
    {
        // fd00::/8 falls within fc00::/7.
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => 'fd00::1']);
        $this->assertSame('unique-local', $result);

        $result2 = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => 'fc00::1']);
        $this->assertSame('unique-local', $result2);
    }//end testBlockedIpv6ReasonUniqueLocal()

    /**
     * blockedIpv6Reason must return 'link-local' for fe80::/10 addresses.
     */
    public function testBlockedIpv6ReasonLinkLocal(): void
    {
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => 'fe80::1']);
        $this->assertSame('link-local', $result);
    }//end testBlockedIpv6ReasonLinkLocal()

    /**
     * blockedIpv6Reason must return 'documentation' for 2001:db8::/32 addresses.
     */
    public function testBlockedIpv6ReasonDocumentation(): void
    {
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => '2001:db8::1']);
        $this->assertSame('documentation', $result);
    }//end testBlockedIpv6ReasonDocumentation()

    /**
     * blockedIpv6Reason must detect IPv4-mapped loopback (::ffff:127.0.0.1).
     */
    public function testBlockedIpv6ReasonIpv4MappedLoopback(): void
    {
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => '::ffff:127.0.0.1']);
        $this->assertSame('IPv4-mapped loopback', $result);
    }//end testBlockedIpv6ReasonIpv4MappedLoopback()

    /**
     * blockedIpv6Reason must detect IPv4-mapped RFC-1918 (::ffff:10.0.0.1).
     */
    public function testBlockedIpv6ReasonIpv4MappedRfc1918(): void
    {
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => '::ffff:10.0.0.1']);
        $this->assertSame('IPv4-mapped RFC-1918', $result);
    }//end testBlockedIpv6ReasonIpv4MappedRfc1918()

    /**
     * blockedIpv6Reason must detect IPv4-mapped link-local/metadata (::ffff:169.254.169.254).
     */
    public function testBlockedIpv6ReasonIpv4MappedLinkLocal(): void
    {
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => '::ffff:169.254.169.254']);
        $this->assertSame('IPv4-mapped link-local/metadata', $result);
    }//end testBlockedIpv6ReasonIpv4MappedLinkLocal()

    /**
     * blockedIpv6Reason must return null for a genuine public IPv6 address.
     */
    public function testBlockedIpv6ReasonAllowsPublicAddress(): void
    {
        // 2606:4700::1 is Cloudflare's anycast DNS (real public address).
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => '2606:4700::1']);
        $this->assertNull($result);
    }//end testBlockedIpv6ReasonAllowsPublicAddress()

    /**
     * blockedIpv6Reason must return null for a non-IPv6 string.
     */
    public function testBlockedIpv6ReasonNullForInvalidInput(): void
    {
        $result = $this->invokePrivateMethod('blockedIpv6Reason', ['ip' => 'not-an-ip']);
        $this->assertNull($result);
    }//end testBlockedIpv6ReasonNullForInvalidInput()
}//end class
