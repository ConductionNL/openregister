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

use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for WebhookService
 *
 * Tests webhook dispatch, delivery, and request interception functionality.
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
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(WebhookService::class);
    }

    /**
     * Test dispatchEvent with no matching webhooks does not throw
     *
     * @return void
     */
    public function testDispatchEventNoWebhooks(): void
    {
        $event = new class extends Event {};

        // Should not throw even if no webhooks match
        $this->service->dispatchEvent(
            $event,
            'phpunit.test.event.' . uniqid(),
            ['test' => 'data']
        );

        // If we get here, the test passes (no exception)
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
                'nested' => [
                    'key' => 'value',
                ],
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
}
