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

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
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
}//end class
