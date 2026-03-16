<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhooksControllerTest extends TestCase
{
    private WebhooksController $controller;
    private IRequest&MockObject $request;
    private WebhookMapper&MockObject $webhookMapper;
    private WebhookLogMapper&MockObject $webhookLogMapper;
    private WebhookService&MockObject $webhookService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->webhookMapper = $this->createMock(WebhookMapper::class);
        $this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
        $this->webhookService = $this->createMock(WebhookService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new WebhooksController(
            'openregister',
            $this->request,
            $this->webhookMapper,
            $this->webhookLogMapper,
            $this->webhookService,
            $this->logger
        );
    }

    private function createWebhookEntity(): Webhook
    {
        $webhook = new Webhook();
        $ref = new \ReflectionClass($webhook);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($webhook, 1);
        $webhook->setName('Test Webhook');
        $webhook->setUrl('https://example.com/hook');
        return $webhook;
    }

    /**
     * Create a real WebhookLog entity with configurable properties.
     *
     * @param array $config Key-value pairs mapping property names to values
     *
     * @return WebhookLog
     */
    private function createWebhookLogEntity(array $config = []): WebhookLog
    {
        $defaults = [
            'id' => 1,
            'webhook' => 1,
            'success' => false,
            'eventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'requestBody' => null,
            'payload' => null,
            'attempt' => 1,
            'errorMessage' => null,
            'statusCode' => null,
            'responseBody' => null,
            'url' => 'https://example.com/hook',
            'method' => 'POST',
        ];

        $merged = array_merge($defaults, $config);
        $log = new WebhookLog();

        // Set ID via reflection since it's on the parent Entity class.
        $ref = new \ReflectionClass($log);
        $parent = $ref->getParentClass();
        $idProp = $parent->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($log, $merged['id']);
        unset($merged['id']);

        // Set remaining properties via setters (using __call magic).
        foreach ($merged as $property => $value) {
            $setter = 'set' . ucfirst($property);
            $log->$setter($value);
        }

        return $log;
    }

    /**
     * Create a WebhookLog mock for retry() tests.
     *
     * The controller's retry() calls getWebhookId() which doesn't exist as
     * a real property on WebhookLog (the field is 'webhook'). We need a mock
     * with addMethods to stub this non-existent method.
     *
     * @param array $config Method return value map
     *
     * @return WebhookLog&MockObject
     */
    private function createWebhookLogMockForRetry(array $config = []): WebhookLog&MockObject
    {
        $defaults = [
            'getWebhookId' => 1,
            'getSuccess' => false,
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getRequestBody' => null,
            'getPayload' => null,
            'getPayloadArray' => [],
            'getAttempt' => 1,
            'getErrorMessage' => null,
            'getStatusCode' => null,
            'getResponseBody' => null,
        ];

        $merged = array_merge($defaults, $config);

        // All getter methods on WebhookLog are magic (__call) so they must
        // use addMethods, not onlyMethods. Only getPayloadArray is a real method.
        $log = $this->getMockBuilder(WebhookLog::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayloadArray'])
            ->addMethods([
                'getWebhookId', 'getSuccess', 'getEventClass',
                'getRequestBody', 'getPayload', 'getAttempt',
                'getErrorMessage', 'getStatusCode', 'getResponseBody',
            ])
            ->getMock();

        // Set ID via reflection.
        $ref = new \ReflectionClass($log);
        $parent = $ref->getParentClass();
        $idProp = $parent->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($log, $config['id'] ?? 1);

        foreach ($merged as $method => $returnValue) {
            $log->method($method)->willReturn($returnValue);
        }

        return $log;
    }

    // =========================================================================
    // index() tests
    // =========================================================================

    public function testIndexSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $webhook = $this->createWebhookEntity();

        $this->webhookMapper->method('findAll')->willReturn([$webhook]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testIndexException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->webhookMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testIndexWithLimitAndOffset(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_offset' => '5',
        ]);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('findAll')->willReturn([$webhook]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);
    }

    public function testIndexWithPageBasedPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_page' => '2',
        ]);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('findAll')->willReturn([$webhook]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testIndexWithStringExtend(): void
    {
        $this->request->method('getParams')->willReturn([
            '_extend' => 'logs',
        ]);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('findAll')->willReturn([$webhook]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testIndexWithArrayExtend(): void
    {
        $this->request->method('getParams')->willReturn([
            '_extend' => ['logs', 'stats'],
        ]);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('findAll')->willReturn([$webhook]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testIndexWithFilters(): void
    {
        $this->request->method('getParams')->willReturn([
            'filters' => ['name' => 'test'],
        ]);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('findAll')->willReturn([$webhook]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(1, $result->getData()['total']);
    }

    public function testIndexWithPageWithoutLimit(): void
    {
        $this->request->method('getParams')->willReturn([
            '_page' => '3',
        ]);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('findAll')->willReturn([$webhook]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testIndexReturnsEmptyResults(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->webhookMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(0, $data['results']);
        $this->assertEquals(0, $data['total']);
    }

    // =========================================================================
    // show() tests
    // =========================================================================

    public function testShowSuccess(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->with(1)->willReturn($webhook);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testShowReturns500OnException(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->show(1);

        $this->assertEquals(500, $result->getStatus());
    }

    // =========================================================================
    // create() tests
    // =========================================================================

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'New Hook',
            'url' => 'https://example.com/hook',
        ]);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('createFromArray')->willReturn($webhook);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateMissingRequired(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'New Hook',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Name and URL are required', $result->getData()['error']);
    }

    public function testCreateMissingName(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'https://example.com/hook',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Name and URL are required', $result->getData()['error']);
    }

    public function testCreateMissingBothNameAndUrl(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Name and URL are required', $result->getData()['error']);
    }

    public function testCreateRemovesInternalParams(): void
    {
        $webhook = $this->createWebhookEntity();

        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            'id' => 5,
            'name' => 'New Hook',
            'url' => 'https://example.com/hook',
        ]);
        $this->webhookMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['_route']) && !isset($data['id'])
                    && isset($data['name']) && isset($data['url']);
            }))
            ->willReturn($webhook);

        $result = $this->controller->create();
        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateRemovesOrganisationParam(): void
    {
        $webhook = $this->createWebhookEntity();

        $this->request->method('getParams')->willReturn([
            'name' => 'New Hook',
            'url' => 'https://example.com/hook',
            'organisation' => 'some-org-id',
        ]);
        $this->webhookMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['organisation']);
            }))
            ->willReturn($webhook);

        $result = $this->controller->create();
        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test',
            'url' => 'https://example.com',
        ]);
        $this->webhookMapper->method('createFromArray')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->create();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testCreateWithEmptyNameString(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => '',
            'url' => 'https://example.com/hook',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Name and URL are required', $result->getData()['error']);
    }

    public function testCreateWithEmptyUrlString(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test',
            'url' => '',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Name and URL are required', $result->getData()['error']);
    }

    public function testCreateWithMultipleUnderscoreParams(): void
    {
        $webhook = $this->createWebhookEntity();

        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            '_method' => 'PUT',
            '_token' => 'abc',
            'name' => 'New Hook',
            'url' => 'https://example.com/hook',
        ]);
        $this->webhookMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['_route'])
                    && !isset($data['_method'])
                    && !isset($data['_token'])
                    && $data['name'] === 'New Hook'
                    && $data['url'] === 'https://example.com/hook';
            }))
            ->willReturn($webhook);

        $result = $this->controller->create();
        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateWithIdNull(): void
    {
        $webhook = $this->createWebhookEntity();

        $this->request->method('getParams')->willReturn([
            'id' => null,
            'name' => 'New Hook',
            'url' => 'https://example.com/hook',
        ]);
        $this->webhookMapper->method('createFromArray')->willReturn($webhook);

        $result = $this->controller->create();
        $this->assertEquals(201, $result->getStatus());
    }

    // =========================================================================
    // update() tests
    // =========================================================================

    public function testUpdateSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('updateFromArray')->willReturn($webhook);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $this->webhookMapper->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->update(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testUpdateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $this->webhookMapper->method('updateFromArray')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->update(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateRemovesIdFromData(): void
    {
        $webhook = $this->createWebhookEntity();

        $this->request->method('getParams')->willReturn([
            'id' => 999,
            'name' => 'Updated Name',
        ]);
        $this->webhookMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
                $this->callback(function ($data) {
                    return !isset($data['id']) && $data['name'] === 'Updated Name';
                })
            )
            ->willReturn($webhook);

        $result = $this->controller->update(1);
        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // destroy() tests
    // =========================================================================

    public function testDestroySuccess(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $result = $this->controller->destroy(1);

        $this->assertEquals(204, $result->getStatus());
    }

    public function testDestroyNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroyReturns500OnException(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDestroyCallsDeleteOnMapper(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookMapper->expects($this->once())
            ->method('delete')
            ->with($webhook);

        $result = $this->controller->destroy(1);
        $this->assertEquals(204, $result->getStatus());
    }

    // =========================================================================
    // test() tests
    // =========================================================================

    public function testTestWebhookSuccess(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookService->method('deliverWebhook')->willReturn(true);

        $result = $this->controller->test(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testTestWebhookNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->test(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testTestWebhookReturns500OnException(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookService->method('deliverWebhook')
            ->willThrowException(new \Exception('Delivery failed'));

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testTestWebhookDeliveryFailedWithLogDetails(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookService->method('deliverWebhook')->willReturn(false);

        $logEntity = $this->createWebhookLogEntity([
            'errorMessage' => 'Connection refused',
            'statusCode' => 502,
            'responseBody' => 'Bad Gateway',
        ]);

        $this->webhookLogMapper->method('findByWebhook')->willReturn([$logEntity]);

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Connection refused', $data['message']);
        $this->assertArrayHasKey('error_details', $data);
        $this->assertEquals(502, $data['error_details']['status_code']);
        $this->assertEquals('Bad Gateway', $data['error_details']['response_body']);
    }

    public function testTestWebhookDeliveryFailedWithNoLogs(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookService->method('deliverWebhook')->willReturn(false);

        $this->webhookLogMapper->method('findByWebhook')->willReturn([]);

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Test webhook delivery failed', $data['message']);
        $this->assertArrayNotHasKey('error_details', $data);
    }

    public function testTestWebhookDeliveryFailedWithLogNoErrorMessage(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookService->method('deliverWebhook')->willReturn(false);

        $logEntity = $this->createWebhookLogEntity([
            'errorMessage' => null,
            'statusCode' => null,
            'responseBody' => null,
        ]);

        $this->webhookLogMapper->method('findByWebhook')->willReturn([$logEntity]);

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Test webhook delivery failed', $data['message']);
        $this->assertArrayNotHasKey('error_details', $data);
    }

    public function testTestWebhookDeliveryFailedWithLogErrorMessageButNoStatusCode(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookService->method('deliverWebhook')->willReturn(false);

        $logEntity = $this->createWebhookLogEntity([
            'errorMessage' => 'Timeout reached',
            'statusCode' => null,
        ]);

        $this->webhookLogMapper->method('findByWebhook')->willReturn([$logEntity]);

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Timeout reached', $data['message']);
        $this->assertArrayNotHasKey('error_details', $data);
    }

    public function testTestWebhookGuzzleException(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        // GuzzleHttp ConnectException requires a real RequestInterface.
        $guzzleRequest = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $guzzleException = new \GuzzleHttp\Exception\ConnectException(
            'Connection timed out',
            $guzzleRequest
        );

        $this->webhookService->method('deliverWebhook')
            ->willThrowException($guzzleException);

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Connection timed out', $data['message']);
    }

    // =========================================================================
    // events() tests
    // =========================================================================

    public function testEventsReturnsArray(): void
    {
        $result = $this->controller->events();

        $this->assertEquals(200, $result->getStatus());
        $this->assertIsArray($result->getData());
        $this->assertNotEmpty($result->getData());
    }

    public function testEventsContainsExpectedStructure(): void
    {
        $result = $this->controller->events();

        $data = $result->getData();
        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertIsArray($data['events']);
        $this->assertGreaterThan(0, $data['total']);

        // Check first event has expected keys.
        $firstEvent = $data['events'][0];
        $this->assertArrayHasKey('class', $firstEvent);
        $this->assertArrayHasKey('name', $firstEvent);
        $this->assertArrayHasKey('description', $firstEvent);
        $this->assertArrayHasKey('category', $firstEvent);
        $this->assertArrayHasKey('type', $firstEvent);
        $this->assertArrayHasKey('properties', $firstEvent);
    }

    public function testEventsContainsAllCategories(): void
    {
        $result = $this->controller->events();
        $data = $result->getData();

        $categories = array_unique(array_column($data['events'], 'category'));
        $expectedCategories = [
            'Object', 'Register', 'Schema', 'Application',
            'Agent', 'Source', 'Configuration', 'View',
            'Conversation', 'Organisation',
        ];
        foreach ($expectedCategories as $expected) {
            $this->assertContains($expected, $categories, "Missing category: $expected");
        }
    }

    // =========================================================================
    // logs() tests
    // =========================================================================

    public function testLogsReturnsWebhookLogs(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->request->method('getParam')->willReturn(null);
        $this->webhookLogMapper->method('findByWebhook')->willReturn([]);

        $result = $this->controller->logs(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertSame(0, $data['total']);
    }

    public function testLogsReturns404WhenWebhookNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->logs(999);

        $this->assertEquals(404, $result->getStatus());
        $this->assertSame('Webhook not found', $result->getData()['error']);
    }

    public function testLogsReturns500OnGenericException(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->logs(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testLogsWithCustomLimitAndOffset(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $paramMap = [
            ['limit', null, '25'],
            ['offset', null, '10'],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $logEntity = $this->createWebhookLogEntity();
        $this->webhookLogMapper->expects($this->once())
            ->method('findByWebhook')
            ->with(
                $this->equalTo(1),
                $this->equalTo(25),
                $this->equalTo(10)
            )
            ->willReturn([$logEntity]);

        $result = $this->controller->logs(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertSame(1, $result->getData()['total']);
    }

    // =========================================================================
    // logStats() tests
    // =========================================================================

    public function testLogStatsReturnsStatistics(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookLogMapper->method('getStatistics')->willReturn([
            'total' => 50,
            'successful' => 45,
            'failed' => 5,
        ]);
        $this->webhookLogMapper->method('findFailedForRetry')->willReturn([]);

        $result = $this->controller->logStats(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(50, $data['total']);
        $this->assertSame(0, $data['pendingRetries']);
    }

    public function testLogStatsReturns404WhenWebhookNotFound(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->logStats(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testLogStatsReturns500OnGenericException(): void
    {
        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('Stats error'));

        $result = $this->controller->logStats(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testLogStatsWithPendingRetries(): void
    {
        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);
        $this->webhookLogMapper->method('getStatistics')->willReturn([
            'total' => 100,
            'successful' => 80,
            'failed' => 20,
        ]);

        $retryLog1 = $this->createWebhookLogEntity();
        $retryLog2 = $this->createWebhookLogEntity(['id' => 2]);
        $this->webhookLogMapper->method('findFailedForRetry')
            ->willReturn([$retryLog1, $retryLog2]);

        $result = $this->controller->logStats(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(100, $data['total']);
        $this->assertSame(2, $data['pendingRetries']);
    }

    // =========================================================================
    // allLogs() tests
    // =========================================================================

    public function testAllLogsReturnsLogs(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->webhookLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testAllLogsReturns500OnException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->webhookLogMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->allLogs();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testAllLogsWithWebhookIdFilter(): void
    {
        $paramMap = [
            ['webhook_id', null, '5'],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, null],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $logEntity = $this->createWebhookLogEntity();
        $this->webhookLogMapper->method('findAll')->willReturn([]);
        $this->webhookLogMapper->method('findByWebhook')->willReturn([$logEntity]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testAllLogsWithSuccessTrueFilter(): void
    {
        $paramMap = [
            ['webhook_id', null, null],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, 'true'],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $successLog = $this->createWebhookLogEntity(['id' => 1, 'success' => true]);
        $failedLog = $this->createWebhookLogEntity(['id' => 2, 'success' => false]);

        $this->webhookLogMapper->method('findAll')
            ->willReturn([$successLog, $failedLog]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        // Only the success=true log should remain after filtering.
        $this->assertCount(1, $data['results']);
    }

    public function testAllLogsWithSuccessFalseFilter(): void
    {
        $paramMap = [
            ['webhook_id', null, null],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, 'false'],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $successLog = $this->createWebhookLogEntity(['id' => 1, 'success' => true]);
        $failedLog = $this->createWebhookLogEntity(['id' => 2, 'success' => false]);

        $this->webhookLogMapper->method('findAll')
            ->willReturn([$successLog, $failedLog]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['results']);
    }

    public function testAllLogsWithSuccess1Filter(): void
    {
        $paramMap = [
            ['webhook_id', null, null],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, '1'],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $successLog = $this->createWebhookLogEntity(['success' => true]);

        $this->webhookLogMapper->method('findAll')
            ->willReturn([$successLog]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['results']);
    }

    public function testAllLogsWithSuccess0Filter(): void
    {
        $paramMap = [
            ['webhook_id', null, null],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, '0'],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $failedLog = $this->createWebhookLogEntity(['success' => false]);

        $this->webhookLogMapper->method('findAll')
            ->willReturn([$failedLog]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['results']);
    }

    public function testAllLogsWithWebhookIdAndSuccessFilter(): void
    {
        $paramMap = [
            ['webhook_id', null, '3'],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, 'true'],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $successLog = $this->createWebhookLogEntity(['id' => 1, 'success' => true]);
        $failedLog = $this->createWebhookLogEntity(['id' => 2, 'success' => false]);

        $this->webhookLogMapper->method('findAll')
            ->willReturn([$successLog, $failedLog]);
        $this->webhookLogMapper->method('findByWebhook')
            ->willReturn([$successLog, $failedLog]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        // Only success=true should remain.
        $this->assertCount(1, $data['results']);
    }

    public function testAllLogsWithInvalidSuccessFilterIsIgnored(): void
    {
        $paramMap = [
            ['webhook_id', null, null],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, 'invalid'],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $logEntity = $this->createWebhookLogEntity();
        $this->webhookLogMapper->method('findAll')->willReturn([$logEntity]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        // No filtering applied, all logs returned.
        $this->assertCount(1, $result->getData()['results']);
    }

    public function testAllLogsWithCustomLimitAndOffset(): void
    {
        $paramMap = [
            ['webhook_id', null, null],
            ['limit', null, '20'],
            ['offset', null, '5'],
            ['success', null, null],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $this->webhookLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testAllLogsWithWebhookIdEmptyString(): void
    {
        $paramMap = [
            ['webhook_id', null, ''],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, null],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $this->webhookLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testAllLogsWithWebhookIdZero(): void
    {
        $paramMap = [
            ['webhook_id', null, '0'],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, null],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $this->webhookLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testAllLogsWithSuccessEmptyString(): void
    {
        $paramMap = [
            ['webhook_id', null, null],
            ['limit', null, null],
            ['offset', null, null],
            ['success', null, ''],
        ];
        $this->request->method('getParam')->willReturnMap($paramMap);

        $this->webhookLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // retry() tests
    // =========================================================================

    public function testRetryReturns404WhenLogNotFound(): void
    {
        $this->webhookLogMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->retry(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testRetryReturns400WhenLogIsSuccessful(): void
    {
        $logMock = $this->createWebhookLogMockForRetry(['getSuccess' => true]);
        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $result = $this->controller->retry(1);

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Cannot retry a successful webhook delivery', $result->getData()['error']);
    }

    public function testRetrySuccessWithRequestBody(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => '{"data": {"key": "value"}}',
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 2,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->method('deliverWebhook')->willReturn(true);

        $result = $this->controller->retry(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Webhook retry delivered successfully', $data['message']);
    }

    public function testRetrySuccessWithPayloadArray(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => null,
            'getPayload' => '{"key": "value"}',
            'getPayloadArray' => ['key' => 'value'],
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 1,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->method('deliverWebhook')->willReturn(true);

        $result = $this->controller->retry(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testRetryReturns400WhenNoPayloadAvailable(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => null,
            'getPayload' => null,
            'getPayloadArray' => [],
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $result = $this->controller->retry(1);

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('No payload available for retry', $result->getData()['error']);
    }

    public function testRetryFailedDeliveryWithLogDetails(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => '{"data": {"key": "value"}}',
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 1,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->method('deliverWebhook')->willReturn(false);

        $latestLogEntity = $this->createWebhookLogEntity([
            'errorMessage' => 'Server error',
            'statusCode' => 503,
            'responseBody' => 'Service Unavailable',
        ]);
        $this->webhookLogMapper->method('findByWebhook')->willReturn([$latestLogEntity]);

        $result = $this->controller->retry(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Server error', $data['message']);
        $this->assertArrayHasKey('error_details', $data);
        $this->assertEquals(503, $data['error_details']['status_code']);
    }

    public function testRetryFailedDeliveryWithNoLogDetails(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => '{"data": {"key": "value"}}',
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 1,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->method('deliverWebhook')->willReturn(false);
        $this->webhookLogMapper->method('findByWebhook')->willReturn([]);

        $result = $this->controller->retry(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Webhook retry delivery failed', $data['message']);
        $this->assertArrayNotHasKey('error_details', $data);
    }

    public function testRetryFailedDeliveryWithLogNoErrorMessage(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => '{"data": {"key": "value"}}',
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 1,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->method('deliverWebhook')->willReturn(false);

        $latestLogEntity = $this->createWebhookLogEntity([
            'errorMessage' => null,
            'statusCode' => null,
        ]);
        $this->webhookLogMapper->method('findByWebhook')->willReturn([$latestLogEntity]);

        $result = $this->controller->retry(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Webhook retry delivery failed', $data['message']);
        $this->assertArrayNotHasKey('error_details', $data);
    }

    public function testRetryReturns500OnGenericException(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => '{"data": {"key": "value"}}',
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 1,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $this->webhookMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->retry(1);

        $this->assertEquals(500, $result->getStatus());
        $this->assertEquals('Failed to retry webhook', $result->getData()['error']);
    }

    public function testRetryWithRequestBodyContainingInvalidJson(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => 'not valid json{{{',
            'getPayload' => null,
            'getPayloadArray' => [],
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $result = $this->controller->retry(1);

        // Invalid JSON decode returns null, so payload is empty => 400.
        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('No payload available for retry', $result->getData()['error']);
    }

    public function testRetryUsesDataKeyFromPayload(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => '{"data": {"id": 42, "name": "test"}, "event": "created"}',
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 3,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->expects($this->once())
            ->method('deliverWebhook')
            ->with(
                $this->equalTo($webhook),
                $this->equalTo('OCA\\OpenRegister\\Event\\ObjectCreatedEvent'),
                $this->equalTo(['id' => 42, 'name' => 'test']),
                $this->equalTo(4)
            )
            ->willReturn(true);

        $result = $this->controller->retry(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testRetryWithPayloadWithoutDataKey(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => '{"id": 42, "name": "test"}',
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 1,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->expects($this->once())
            ->method('deliverWebhook')
            ->with(
                $this->equalTo($webhook),
                $this->equalTo('OCA\\OpenRegister\\Event\\ObjectCreatedEvent'),
                $this->equalTo(['id' => 42, 'name' => 'test']),
                $this->equalTo(2)
            )
            ->willReturn(true);

        $result = $this->controller->retry(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testRetryFailedWithLogErrorMessageAndStatusCode(): void
    {
        $logMock = $this->createWebhookLogMockForRetry([
            'getSuccess' => false,
            'getWebhookId' => 1,
            'getRequestBody' => '{"key": "value"}',
            'getEventClass' => 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'getAttempt' => 1,
        ]);

        $this->webhookLogMapper->method('find')->willReturn($logMock);

        $webhook = $this->createWebhookEntity();
        $this->webhookMapper->method('find')->willReturn($webhook);

        $this->webhookService->method('deliverWebhook')->willReturn(false);

        $latestLogEntity = $this->createWebhookLogEntity([
            'errorMessage' => 'Bad request',
            'statusCode' => 400,
            'responseBody' => '{"error": "invalid"}',
        ]);
        $this->webhookLogMapper->method('findByWebhook')->willReturn([$latestLogEntity]);

        $result = $this->controller->retry(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Bad request', $data['message']);
        $this->assertEquals(400, $data['error_details']['status_code']);
        $this->assertEquals('{"error": "invalid"}', $data['error_details']['response_body']);
    }
}
