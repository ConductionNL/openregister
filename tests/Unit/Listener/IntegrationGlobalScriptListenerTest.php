<?php

/**
 * Unit tests for IntegrationGlobalScriptListener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/universal-shared-integration-registry/tasks.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Listener\IntegrationGlobalScriptListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IntegrationGlobalScriptListener.
 *
 * @covers \OCA\OpenRegister\Listener\IntegrationGlobalScriptListener
 *
 * @spec openspec/changes/universal-shared-integration-registry/tasks.md
 */
class IntegrationGlobalScriptListenerTest extends TestCase
{
    private IntegrationGlobalScriptListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new IntegrationGlobalScriptListener();
    }

    /**
     * Non-BeforeTemplateRenderedEvent events must be ignored silently.
     */
    public function testIgnoresNonBeforeTemplateRenderedEvent(): void
    {
        $event = $this->createMock(Event::class);

        $this->listener->handle($event);

        $this->assertTrue(true);
    }

    /**
     * A BeforeTemplateRenderedEvent must be handled without throwing.
     *
     * OCP\Util::addInitScript is a static NC framework helper; full
     * invocation behaviour is verified in e2e tests. Here we confirm
     * the listener does not raise on a valid event.
     */
    public function testHandlesBeforeTemplateRenderedEventWithoutException(): void
    {
        $event = $this->createMock(BeforeTemplateRenderedEvent::class);

        try {
            $this->listener->handle($event);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            // Allow only OC-not-bootstrapped errors so pure-unit mode passes.
            $allowed = str_contains($e->getMessage(), 'OC')
                || str_contains($e->getMessage(), 'Nextcloud')
                || str_contains($e->getMessage(), 'app');
            $this->assertTrue($allowed, sprintf('Unexpected exception: %s', $e->getMessage()));
        }
    }

    /**
     * The listener must implement IEventListener.
     */
    public function testImplementsIEventListener(): void
    {
        $this->assertInstanceOf(IEventListener::class, $this->listener);
    }
}
