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
use OCP\AppFramework\Db\DoesNotExistException;
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

        $this->service = new WebhookService(
            webhookMapper: $this->webhookMapper,
            logger: $this->logger,
            webhookLogMapper: $this->webhookLogMapper,
            mappingService: $this->mappingService,
            mappingMapper: $this->mappingMapper
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

    /**
     * Test dispatchEvent delivers to matching webhooks.
     *
     * @return void
     */
    public function testDispatchEventDeliversToMatchingWebhooks(): void
    {
        $event = $this->createMock(\OCP\EventDispatcher\Event::class);

        $webhook = $this->createTestWebhook(enabled: true);
        $webhook->setMethod('POST');

        $this->webhookMapper->method('findForEvent')->willReturn([$webhook]);

        // Inject mock client for successful delivery.
        $mockResponse = new GuzzleResponse(200, [], '{"ok":true}');
        $mockClient   = $this->createMock(GuzzleClient::class);
        $mockClient->method('request')->willReturn($mockResponse);
        $this->injectMockClient($mockClient);

        $this->webhookMapper->expects($this->once())
            ->method('updateStatistics');

        $this->webhookLogMapper->expects($this->once())
            ->method('insert');

        $this->service->dispatchEvent(
            $event,
            'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            ['objectType' => 'object']
        );
    }//end testDispatchEventDeliversToMatchingWebhooks()

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
            cloudEventFormatter: $cloudEventFormatter
        );

        $webhook = $this->createTestWebhook(id: 1, enabled: true);
        $webhook->setConfiguration(json_encode(['interceptRequests' => true]));
        $webhook->setMethod('POST');

        $this->webhookMapper->method('findEnabled')->willReturn([$webhook]);

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
}//end class
